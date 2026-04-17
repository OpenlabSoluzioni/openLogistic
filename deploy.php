<?php
/**
 * openLogistic - Deploy & Backup Manager v3
 * Con progress bar, gestione comparti, cron automatico
 */
define('PROJECT_DIR', __DIR__);
define('BACKUP_DIR', __DIR__ . '/backups');
define('MAX_BACKUPS', 20);
define('DOWNLOAD_LOG', BACKUP_DIR . '/.last_download');

$configPath = __DIR__ . '/api/config.php';
$dbConfig = file_exists($configPath) ? require $configPath : null;
if (!is_array($dbConfig)) {
    throw new RuntimeException('Configurazione non valida: api/config.php');
}

define('DEPLOY_PASSWORD', (string)($dbConfig['deploy_password'] ?? ''));
define('CRON_SECRET', (string)($dbConfig['cron_secret'] ?? ''));  // Token per backup automatico via cron

// ═══════════════════════════════════════════════════════════════
// CRON: backup automatico via URL (no session needed)
// wget -q "http://server/openddt/deploy.php?cron=CRON_SECRET" -O /dev/null
// ═══════════════════════════════════════════════════════════════
if (CRON_SECRET !== '' && isset($_GET['cron']) && hash_equals(CRON_SECRET, (string)$_GET['cron'])) {
    header('Content-Type: text/plain');
    set_time_limit(0); ini_set('memory_limit', '512M');
    try {
        if (!is_dir(BACKUP_DIR)) { @mkdir(BACKUP_DIR, 0777, true); @chmod(BACKUP_DIR, 0777); }
        if (!file_exists(BACKUP_DIR . '/.htaccess')) file_put_contents(BACKUP_DIR . '/.htaccess', "Deny from all\n");
        
        $pdo = new PDO("mysql:host={$dbConfig['db_host']};dbname={$dbConfig['db_name']};charset=utf8mb4",
            $dbConfig['db_user'], $dbConfig['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        $ts = date('Ymd_His');
        $sqlFile = BACKUP_DIR . "/tmp_cron_{$ts}.sql";
        $fp = fopen($sqlFile, 'w');
        fwrite($fp, "-- openLogistic Cron Backup — " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n");
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                foreach (array_chunk($rows, 500) as $chunk) {
                    $vals = [];
                    foreach ($chunk as $row) { $v=[]; foreach($row as $val) $v[]=$val===null?'NULL':$pdo->quote($val); $vals[]='('.implode(',',$v).')'; }
                    fwrite($fp, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $vals) . ";\n\n");
                }
            }
        }
        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fp);
        
        // ZIP completo (codice + DB) - addFromString per evitare file handle exhaustion
        $zipPath = BACKUP_DIR . "/openLogistic_cron_{$ts}.zip";
        $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('database/' . basename($sqlFile), file_get_contents($sqlFile));
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_DIR, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            $rel = substr($f->getPathname(), strlen(PROJECT_DIR)+1);
            if (strpos($rel,'backups')===0||strpos($rel,'.git')===0||$rel==='deploy.php') continue;
            if ($f->isFile()) { $c = @file_get_contents($f->getPathname()); if ($c !== false) $zip->addFromString('code/'.$rel, $c); }
        }
        $zip->close();
        @unlink($sqlFile);
        
        // Pulizia vecchi backup cron (tiene ultimi 7)
        $cronFiles = glob(BACKUP_DIR . '/openLogistic_cron_*.zip');
        usort($cronFiles, fn($a,$b) => filemtime($b) - filemtime($a));
        while (count($cronFiles) > 7) @unlink(array_pop($cronFiles));
        
        // Log
        file_put_contents(BACKUP_DIR . '/.last_cron', date('Y-m-d H:i:s') . ' | ' . basename($zipPath) . ' | ' . round(filesize($zipPath)/1024/1024, 2) . ' MB');
        
        $size = round(filesize($zipPath)/1024/1024, 2);
        echo "OK | {$ts} | {$size} MB | " . count($tables) . " tabelle\n";
    } catch (Exception $e) {
        echo "ERROR | " . $e->getMessage() . "\n";
    }
    exit;
}

session_start();
require_once __DIR__ . '/api/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_or_die();
}
if (isset($_POST['deploy_login'])) {
    if (DEPLOY_PASSWORD !== '' && hash_equals(DEPLOY_PASSWORD, (string)($_POST['deploy_password'] ?? ''))) {
        $_SESSION['deploy_auth'] = true;
    } else {
        $loginError = true;
    }
}
if (isset($_GET['logout'])) { unset($_SESSION['deploy_auth']); header('Location: deploy.php'); exit; }
$isAuth = !empty($_SESSION['deploy_auth']);

function getDeployPDO() {
    global $dbConfig;
    return new PDO("mysql:host={$dbConfig['db_host']};dbname={$dbConfig['db_name']};charset=utf8mb4",
        $dbConfig['db_user'], $dbConfig['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function ensureBackupDir() {
    if (!is_dir(BACKUP_DIR)) { @mkdir(BACKUP_DIR, 0777, true); @chmod(BACKUP_DIR, 0777); }
    if (!file_exists(BACKUP_DIR . '/.htaccess')) file_put_contents(BACKUP_DIR . '/.htaccess', "Deny from all\n");
    if (!is_writable(BACKUP_DIR)) { @chmod(BACKUP_DIR, 0777);
        if (!is_writable(BACKUP_DIR)) throw new Exception("Cartella non scrivibile: " . BACKUP_DIR . " — Esegui: sudo chown -R www-data:www-data " . BACKUP_DIR);
    }
}

function getComparti() {
    return [
        'ddt' => ['label'=>'DDT','icon'=>'file-lines','color'=>'#3b82f6','tables'=>['ddt','ddt_righe','ddt_counters']],
        'anagrafiche' => ['label'=>'Anagrafiche','icon'=>'address-book','color'=>'#8b5cf6','tables'=>['anagrafiche']],
        'magazzino' => ['label'=>'Magazzino & Servizi','icon'=>'boxes-stacked','color'=>'#f59e0b','tables'=>['magazzino','servizi']],
        'attivita' => ['label'=>'Registro Attività','icon'=>'clipboard-list','color'=>'#10b981','tables'=>['registro_attivita','registro_attivita_righe']],
        'utenti' => ['label'=>'Utenti','icon'=>'users','color'=>'#ef4444','tables'=>['users','user_trusted_devices','registration_requests','password_reset_requests','password_reset_attempts','reset_log']],
        'impostazioni' => ['label'=>'Impostazioni','icon'=>'gear','color'=>'#64748b','tables'=>['causali_trasporto','aspetto_beni','porto','tipo_mezzo','ddt_automatismi']],
    ];
}

function dumpTables($pdo, $tables, $outputFile) {
    $dir = dirname($outputFile);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    if (!is_writable($dir)) throw new Exception("Cartella non scrivibile: {$dir}");
    $fp = fopen($outputFile, 'w');
    if (!$fp) throw new Exception("Impossibile creare: {$outputFile}");
    fwrite($fp, "-- openLogistic Dump — " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    foreach ($tables as $table) {
        try { $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1"); } catch (Exception $e) { continue; }
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n");
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) continue;
        $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
        foreach (array_chunk($rows, 500) as $chunk) {
            $vals = [];
            foreach ($chunk as $row) { $v = []; foreach ($row as $val) $v[] = $val === null ? 'NULL' : $pdo->quote($val); $vals[] = '('.implode(',',$v).')'; }
            fwrite($fp, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $vals) . ";\n\n");
        }
    }
    fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fp);
}

// ═══════════════════════════════════════════════════════════════
// BACKUP con progress file + polling (no SSE)
// ═══════════════════════════════════════════════════════════════
$progressFile = BACKUP_DIR . '/.progress';

// Polling: JS chiede lo stato ogni 600ms
if ($isAuth && isset($_GET['check_progress'])) {
    session_write_close();
    header('Content-Type: application/json');
    ensureBackupDir();
    echo file_exists($progressFile) ? file_get_contents($progressFile) : json_encode(['pct'=>0,'msg'=>'']);
    exit;
}

// Avvia backup: chiude la connessione subito, lavora in background
if ($isAuth && isset($_GET['run_backup'])) {
    session_write_close();
    ensureBackupDir();
    set_time_limit(0); ini_set('memory_limit', '512M'); ignore_user_abort(true);
    
    // Rispondi subito e chiudi connessione
    $resp = json_encode(['ok'=>true]);
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: '.strlen($resp));
    echo $resp;
    if (ob_get_level()) ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    
    // Da qui lavora in background
    @file_put_contents($progressFile, json_encode(['pct'=>2,'msg'=>'Avvio...','t'=>time()]));
    
    $isFull = $_GET['run_backup'] === 'full';
    try {
        $pdo = getDeployPDO();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $n = count($tables);
        $ts = date('Ymd_His');
        
        $sqlFile = BACKUP_DIR . "/tmp_{$ts}.sql";
        $fp = fopen($sqlFile, 'w');
        fwrite($fp, "-- openLogistic Dump — ".date('Y-m-d H:i:s')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        
        foreach ($tables as $i => $table) {
            $pct = 5 + intval(($i/$n)*($isFull?40:80));
            @file_put_contents($progressFile, json_encode(['pct'=>$pct,'msg'=>"Dump: {$table} (".($i+1)."/{$n})",'t'=>time()]));
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n".$create['Create Table'].";\n\n");
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = '`'.implode('`, `', array_keys($rows[0])).'`';
                foreach (array_chunk($rows, 500) as $chunk) {
                    $vals = [];
                    foreach ($chunk as $row) { $v=[]; foreach($row as $val) $v[]=$val===null?'NULL':$pdo->quote($val); $vals[]='('.implode(',',$v).')'; }
                    fwrite($fp, "INSERT INTO `{$table}` ({$cols}) VALUES\n".implode(",\n",$vals).";\n\n");
                }
            }
        }
        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n"); fclose($fp);
        
        if ($isFull) {
            $allFiles = [];
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_DIR, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iter as $f) {
                $rel = substr($f->getPathname(), strlen(PROJECT_DIR)+1);
                if (strpos($rel,'backups')===0||strpos($rel,'.git')===0||$rel==='deploy.php') continue;
                if ($f->isFile()) $allFiles[] = $f->getPathname();
            }
            $tf = count($allFiles);
            @file_put_contents($progressFile, json_encode(['pct'=>48,'msg'=>"ZIP: 0/{$tf} file",'t'=>time()]));
            $zipPath = BACKUP_DIR."/openLogistic_full_{$ts}.zip";
            $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('database/dump.sql', file_get_contents($sqlFile));
            foreach ($allFiles as $fi => $fp2) {
                $rel = substr($fp2, strlen(PROJECT_DIR)+1);
                $c = @file_get_contents($fp2);
                if ($c !== false) $zip->addFromString('code/'.$rel, $c);
                if ($fi % 3 === 0) @file_put_contents($progressFile, json_encode(['pct'=>48+intval((($fi+1)/$tf)*45),'msg'=>"ZIP: ".($fi+1)."/{$tf} file",'t'=>time()]));
            }
            $zip->close();
        } else {
            @file_put_contents($progressFile, json_encode(['pct'=>85,'msg'=>'Compressione...','t'=>time()]));
            $zipPath = BACKUP_DIR."/openLogistic_db_{$ts}.sql.zip";
            $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('dump.sql', file_get_contents($sqlFile)); $zip->close();
        }
        @unlink($sqlFile);
        $files = glob(BACKUP_DIR.'/*.zip'); usort($files, fn($a,$b)=>filemtime($b)-filemtime($a));
        while(count($files)>MAX_BACKUPS) @unlink(array_pop($files));
        $size = round(filesize($zipPath)/1024/1024, 2);
        @file_put_contents($progressFile, json_encode(['pct'=>100,'msg'=>($isFull?'Completo':'DB').": {$size} MB",'t'=>time()]));
    } catch (Exception $e) {
        @file_put_contents($progressFile, json_encode(['pct'=>-1,'msg'=>'Errore: '.$e->getMessage(),'t'=>time()]));
    }
    exit;
}

// JSON API
if ($isAuth && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try { ensureBackupDir(); } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
    try {
        $a = $_POST['action'];
        if ($a==='list_backups') {
            $files = glob(BACKUP_DIR.'/*.{zip,sql}', GLOB_BRACE)?:[];
            usort($files, fn($a,$b)=>filemtime($b)-filemtime($a));
            $bk = [];
            foreach ($files as $f) {
                $nm = basename($f); $type='code';
                if(strpos($nm,'_full_')!==false)$type='full'; elseif(strpos($nm,'_db_')!==false)$type='db'; elseif(strpos($nm,'_comparto_')!==false||strpos($nm,'pre_clear_')!==false)$type='section'; elseif(strpos($nm,'pre_deploy_')!==false)$type='pre';
                $bk[] = ['name'=>$nm,'size'=>round(filesize($f)/1024,1),'date'=>date('d/m/Y H:i',filemtime($f)),'type'=>$type];
            }
            echo json_encode(['ok'=>true,'backups'=>$bk]);
        } elseif ($a==='delete_backup') {
            $p = BACKUP_DIR.'/'.basename($_POST['file']);
            if (file_exists($p)) { unlink($p); echo json_encode(['ok'=>true]); } else throw new Exception('Non trovato');
        } elseif ($a==='deploy') {
            if (empty($_FILES['zipfile'])||$_FILES['zipfile']['error']!==UPLOAD_ERR_OK) throw new Exception('File non caricato');
            $zip = new ZipArchive(); if($zip->open($_FILES['zipfile']['tmp_name'])!==true) throw new Exception('ZIP non valido');
            $ts=date('Ymd_His'); $pre=BACKUP_DIR."/pre_deploy_{$ts}.sql";
            try{$pdo=getDeployPDO();dumpTables($pdo,$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN),$pre);}catch(Exception$e){}
            $ext=0; $skip=['deploy.php','api/config.php'];
            for($i=0;$i<$zip->numFiles;$i++){
                $entry=$zip->getNameIndex($i); $sk=false;
                foreach($skip as $s){if($entry===$s||strpos($entry,'backups/')===0){$sk=true;break;}}
                if($sk)continue;
                if(substr($entry,-1)==='/'){@mkdir(PROJECT_DIR.'/'.$entry,0755,true);continue;}
                $dest=PROJECT_DIR.'/'.$entry; @mkdir(dirname($dest),0755,true);
                $c=$zip->getFromIndex($i); if($c!==false){file_put_contents($dest,$c);$ext++;}
            }
            $zip->close();
            echo json_encode(['ok'=>true,'extracted'=>$ext]);
        } elseif ($a==='run_sql') {
            $f=basename($_POST['file']); $p=PROJECT_DIR.'/sql/'.$f;
            if(!file_exists($p))throw new Exception('Non trovato');
            getDeployPDO()->exec(file_get_contents($p));
            echo json_encode(['ok'=>true,'msg'=>"{$f} eseguita"]);
        } elseif ($a==='list_sql') {
            $fs=glob(PROJECT_DIR.'/sql/*.sql')?:[]; sort($fs);
            echo json_encode(['ok'=>true,'files'=>array_map('basename',$fs)]);
        } elseif ($a==='info') {
            $pdo=getDeployPDO(); $info=['php'=>phpversion(),'db'=>$dbConfig['db_name'],'disk'=>round(disk_free_space('/')/1024/1024/1024,2).' GB','bk_count'=>count(glob(BACKUP_DIR.'/*.zip')?:[])];
            foreach(getComparti() as $k=>$c){
                $t=0; $detail=[];
                foreach($c['tables'] as $tb){
                    try{
                        $n=(int)$pdo->query("SELECT COUNT(*) FROM `{$tb}`")->fetchColumn();
                        $detail[]=['table'=>$tb,'count'=>$n];
                        $t+=$n;
                    }catch(Exception$e){$detail[]=['table'=>$tb,'count'=>0,'error'=>true];}
                }
                $info["count_{$k}"]=$t;
                $info["detail_{$k}"]=$detail;
            }
            // DDT specifici: contatore, magazzino esterno
            try {
                $info['ddt_counter'] = $pdo->query("SELECT anno, last_num FROM ddt_counters ORDER BY anno DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
                $info['ddt_magazzino_esterno'] = (int)$pdo->query("
                    SELECT COUNT(DISTINCT d.id) FROM ddt d
                    INNER JOIN causali_trasporto c ON LOWER(d.causale_trasporto) = LOWER(c.descrizione) AND c.is_conto_deposito = 1
                    WHERE d.stato = 'definitivo'
                    AND EXISTS (SELECT 1 FROM ddt_righe r WHERE r.ddt_id = d.id AND r.servizio != 'S'
                        AND (r.data_rientro IS NULL OR r.data_rientro = '' OR r.rif_fornitore IS NULL OR r.rif_fornitore = ''))
                ")->fetchColumn();
            } catch (Exception $e) {}
            // Last download info
            $info['last_download'] = null; $info['days_since_download'] = null;
            if (file_exists(DOWNLOAD_LOG)) {
                $dl = @json_decode(file_get_contents(DOWNLOAD_LOG), true);
                if ($dl && !empty($dl['date'])) {
                    $info['last_download'] = $dl['date'];
                    $info['last_download_file'] = $dl['file'] ?? '';
                    $info['days_since_download'] = (int)((time() - strtotime($dl['date'])) / 86400);
                }
            }
            // Last cron info
            $info['last_cron'] = null;
            if (file_exists(BACKUP_DIR . '/.last_cron')) {
                $info['last_cron'] = trim(file_get_contents(BACKUP_DIR . '/.last_cron'));
            }
            $info['cron_url'] = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/deploy.php?cron='.CRON_SECRET;
            echo json_encode(['ok'=>true,'info'=>$info]);
        } elseif ($a==='backup_section') {
            $s=$_POST['section']??''; $comp=getComparti(); if(!isset($comp[$s]))throw new Exception('Sconosciuto');
            $pdo=getDeployPDO(); $ts=date('Ymd_His');
            $sf=BACKUP_DIR."/openLogistic_comparto_{$s}_{$ts}.sql";
            dumpTables($pdo,$comp[$s]['tables'],$sf);
            $zf=$sf.'.zip'; $z=new ZipArchive();$z->open($zf,ZipArchive::CREATE);$z->addFromString(basename($sf),file_get_contents($sf));$z->close();@unlink($sf);
            echo json_encode(['ok'=>true,'file'=>basename($zf),'size'=>round(filesize($zf)/1024,1).' KB']);
        } elseif ($a==='clear_section') {
            $s=$_POST['section']??''; $comp=getComparti(); if(!isset($comp[$s]))throw new Exception('Sconosciuto');
            $pdo=getDeployPDO(); $ts=date('Ymd_His');
            // Backup preventivo
            dumpTables($pdo,$comp[$s]['tables'],BACKUP_DIR."/pre_clear_{$s}_{$ts}.sql");
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0"); $del=0; $errors=[];
            foreach($comp[$s]['tables'] as $tb){
                try{
                    // Conta prima
                    $n=(int)$pdo->query("SELECT COUNT(*) FROM `{$tb}`")->fetchColumn();
                    // TRUNCATE resetta auto_increment e svuota completamente
                    $pdo->exec("TRUNCATE TABLE `{$tb}`");
                    $del+=$n;
                }catch(Exception$e){
                    // Fallback a DELETE se TRUNCATE fallisce
                    try{$del+=$pdo->exec("DELETE FROM `{$tb}`");}catch(Exception$e2){$errors[]=$tb.': '.$e2->getMessage();}
                }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            $msg = $del.' record eliminati';
            if (!empty($errors)) $msg .= ' (errori: '.implode(', ',$errors).')';
            echo json_encode(['ok'=>true,'deleted'=>$del,'msg'=>$msg]);
        } elseif ($a==='restore_section') {
            if(empty($_FILES['sqlfile'])||$_FILES['sqlfile']['error']!==UPLOAD_ERR_OK)throw new Exception('File non caricato');
            $nm=$_FILES['sqlfile']['name']; $tmp=$_FILES['sqlfile']['tmp_name']; $sql='';
            if(preg_match('/\.zip$/i',$nm)){$z=new ZipArchive();if($z->open($tmp)!==true)throw new Exception('ZIP non valido');for($i=0;$i<$z->numFiles;$i++){$e=$z->getNameIndex($i);if(preg_match('/\.sql$/i',$e)){$sql=$z->getFromIndex($i);break;}}$z->close();if(!$sql)throw new Exception('Nessun .sql nel ZIP');}
            else $sql=file_get_contents($tmp);
            if(!$sql)throw new Exception('File vuoto');
            getDeployPDO()->exec($sql);
            echo json_encode(['ok'=>true,'msg'=>"Ripristinato da {$nm}"]);
        } else echo json_encode(['ok'=>false,'error'=>'Azione sconosciuta']);
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
if ($isAuth && isset($_GET['download'])) {
    $f=basename($_GET['download']); $p=BACKUP_DIR.'/'.$f;
    if(file_exists($p)){
        // Track download
        @file_put_contents(DOWNLOAD_LOG, json_encode(['date'=>date('Y-m-d H:i:s'),'file'=>$f,'ip'=>$_SERVER['REMOTE_ADDR']??'']));
        header('Content-Type:application/octet-stream');header('Content-Disposition:attachment;filename="'.$f.'"');header('Content-Length:'.filesize($p));readfile($p);exit;
    }
}
$cj=json_encode(getComparti());
?><!DOCTYPE html><html lang="it"><head>
    <?= csrf_meta_tag() ?>
    <?= csrf_bootstrap_script() ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>openLogistic — Deploy Manager</title><link rel="icon" type="image/png" href="assets/img/openLogistic.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--bg:#0c0f1a;--s1:#141828;--s2:#1c2137;--brd:#2a3050;--t:#e2e8f0;--td:#64748b;--acc:#3b82f6;--ok:#10b981;--w:#f59e0b;--err:#ef4444;--r:10px;--f:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;--m:'SF Mono','Fira Code',monospace}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:var(--f);background:var(--bg);color:var(--t);min-height:100vh}
.lp{display:flex;align-items:center;justify-content:center;min-height:100vh;background:radial-gradient(ellipse at 50% 0%,rgba(59,130,246,.08),transparent 60%)}
.lb{background:var(--s1);border:1px solid var(--brd);border-radius:var(--r);padding:40px;width:360px;text-align:center}
.lb h1{font-size:20px;margin-bottom:8px}.lb p{color:var(--td);font-size:13px;margin-bottom:24px}
.lb input{width:100%;padding:10px 14px;background:var(--bg);border:1px solid var(--brd);border-radius:8px;color:var(--t);font-size:14px;margin-bottom:12px;font-family:var(--m);text-align:center;letter-spacing:2px}
.lb input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.le{color:var(--err);font-size:12px;margin-bottom:12px}
.app{max-width:1200px;margin:0 auto;padding:24px}
.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid var(--brd)}
.top h1{font-size:18px;font-weight:600;display:flex;align-items:center;gap:10px}.top h1 i{color:var(--acc)}
.b{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border:1px solid var(--brd);border-radius:8px;background:var(--s1);color:var(--t);font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;font-family:var(--f);text-decoration:none}
.b:hover{background:var(--s2);border-color:var(--acc)}.b:disabled{opacity:.5;cursor:not-allowed}
.bp{background:var(--acc);border-color:var(--acc);color:#fff}.bp:hover{background:#2563eb}
.bd{border-color:var(--err);color:var(--err)}.bd:hover{background:rgba(239,68,68,.1)}
.bg{border-color:var(--ok);color:var(--ok)}.bg:hover{background:rgba(16,185,129,.1)}
.bw{border-color:var(--w);color:var(--w)}.bw:hover{background:rgba(245,158,11,.1)}
.bs{padding:6px 10px;font-size:12px}
.pw{margin:16px 0;display:none}.pb{height:6px;background:var(--s2);border-radius:3px;overflow:hidden}
.pf{height:100%;background:linear-gradient(90deg,var(--acc),#818cf8);border-radius:3px;width:0;transition:width .3s}
.pm{font-size:12px;color:var(--td);margin-top:6px;font-family:var(--m)}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.c{background:var(--s1);border:1px solid var(--brd);border-radius:var(--r);overflow:hidden}
.ch{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--brd);background:var(--s2);font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--td)}
.ch i{margin-right:8px}.cb{padding:18px}
.co{background:var(--s1);border:1px solid var(--brd);border-radius:var(--r);overflow:hidden;transition:border-color .2s}
.co:hover{border-color:rgba(255,255,255,.15)}
.coh{display:flex;align-items:center;gap:12px;padding:16px;border-bottom:1px solid var(--brd)}
.coi{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.con{font-size:14px;font-weight:600}.coc{font-size:24px;font-weight:700;font-family:var(--m)}
.cot{font-size:10px;color:var(--td);margin-top:2px;font-family:var(--m)}
.co-dt{display:flex;flex-wrap:wrap;gap:4px 10px;margin-top:4px}
.co-td{font-size:10px;color:var(--td);font-family:var(--m);background:rgba(255,255,255,.04);padding:1px 6px;border-radius:4px}
.co-td strong{color:var(--t)}
.co-te{color:var(--err)!important}
.co-tc{background:rgba(59,130,246,.1);color:#60a5fa!important}
.co-tc strong{color:#93bbfc}
.co-tw{background:rgba(245,158,11,.1);color:#f59e0b!important}
.co-tw strong{color:#fbbf24}
.coa{display:flex;gap:6px;padding:12px 16px;background:var(--bg);flex-wrap:wrap}
.bl{max-height:300px;overflow-y:auto}
.bi{display:flex;align-items:center;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);gap:12px;transition:background .15s}
.bi:hover{background:rgba(59,130,246,.04)}.bi:last-child{border-bottom:none}
.bii{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px}
.bin{font-size:12px;font-weight:500;font-family:var(--m)}.bim{font-size:11px;color:var(--td);margin-top:2px}
.uz{border:2px dashed var(--brd);border-radius:var(--r);padding:28px;text-align:center;cursor:pointer;transition:all .2s}
.uz:hover,.uz.dg{border-color:var(--acc);background:rgba(59,130,246,.06)}
.uz i{font-size:28px;color:var(--td);margin-bottom:8px}.uz p{color:var(--td);font-size:13px}.uz input{display:none}
.sl{display:flex;flex-wrap:wrap;gap:6px}
.si{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;background:var(--bg);border:1px solid var(--brd);border-radius:6px;font-size:11px;font-family:var(--m);cursor:pointer;transition:all .15s}
.si:hover{border-color:var(--acc);color:var(--acc)}
.tt{position:fixed;bottom:20px;right:20px;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:500;color:#fff;z-index:9999;animation:sI .3s,fO .3s 3.5s forwards;max-width:400px}
.tok{background:var(--ok)}.ter{background:var(--err)}.tin{background:var(--acc)}
@keyframes sI{from{transform:translateX(100px);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes fO{to{opacity:0;transform:translateY(10px)}}
.sp{animation:sp 1s linear infinite}@keyframes sp{to{transform:rotate(360deg)}}
.al{display:flex;align-items:center;gap:16px;padding:14px 20px;border-radius:var(--r);margin-bottom:20px;animation:sI .3s}
.al-w{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3)}
.al-d{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3)}
.al-i{font-size:20px;flex-shrink:0}.al-w .al-i{color:var(--w)}.al-d .al-i{color:var(--err)}
.al-t{font-size:14px;font-weight:600}.al-m{font-size:12px;color:var(--td);margin-top:2px}.al-b{flex:1}
@media(max-width:768px){.g2,.g3{grid-template-columns:1fr}}
</style></head><body>
<?php if(!$isAuth):?>
<div class="lp"><div class="lb"><h1><i class="fa-solid fa-rocket"></i> Deploy Manager</h1><p>openLogistic</p>
<?php if(!empty($loginError)):?><div class="le"><i class="fa-solid fa-circle-exclamation"></i> Password errata</div><?php endif;?>
<form method="post"><input type="hidden" name="deploy_login" value="1"><input type="password" name="deploy_password" placeholder="••••••••" autofocus>
<button type="submit" class="b bp" style="width:100%;justify-content:center"><i class="fa-solid fa-right-to-bracket"></i> Accedi</button></form></div></div>
<?php else:?>
<div class="app">
<div class="top"><h1><i class="fa-solid fa-rocket"></i> openLogistic — Deploy Manager</h1><a href="?logout" class="b bs"><i class="fa-solid fa-right-from-bracket"></i> Esci</a></div>

<!-- Alert banner -->
<div class="al" id="alert-dl" style="display:none">
  <div class="al-i"><i class="fa-solid fa-triangle-exclamation"></i></div>
  <div class="al-b">
    <div class="al-t" id="alert-title">Nessun backup scaricato</div>
    <div class="al-m" id="alert-msg">Scarica un backup sul tuo PC per proteggerti da guasti del server.</div>
  </div>
  <a class="b bs bg" id="alert-btn" style="display:none"><i class="fa-solid fa-download"></i> Scarica ultimo</a>
</div>

<h3 style="margin-bottom:12px;font-size:13px;color:var(--td);text-transform:uppercase;letter-spacing:.08em"><i class="fa-solid fa-cubes"></i> Gestione Comparti</h3>
<div class="g3" id="comp"></div>
<div class="g2">
<div class="c"><div class="ch"><span><i class="fa-solid fa-shield-halved"></i> Backup Globale</span></div><div class="cb">
<div style="display:flex;gap:8px;margin-bottom:12px"><button class="b bp" id="bf"><i class="fa-solid fa-box-archive"></i> Completo</button><button class="b bg" id="bd2"><i class="fa-solid fa-database"></i> Solo DB</button></div>
<div class="pw" id="pw"><div class="pb"><div class="pf" id="pf"></div></div><div class="pm" id="pm"></div></div>
<div class="bl" id="blist"><div style="text-align:center;padding:20px;color:var(--td)"><i class="fa-solid fa-spinner sp"></i></div></div></div></div>
<div class="c"><div class="ch"><span><i class="fa-solid fa-cloud-arrow-up"></i> Deploy & Migrazioni</span></div><div class="cb">
<div class="uz" id="uz" onclick="document.getElementById('zf').click()"><i class="fa-solid fa-cloud-arrow-up"></i><p><strong>Trascina ZIP</strong> oppure clicca</p><input type="file" id="zf" accept=".zip"></div>
<p style="margin-top:8px;font-size:11px;color:var(--td)"><i class="fa-solid fa-shield-halved"></i> Backup auto pre-deploy. <code>deploy.php</code> e <code>config.php</code> protetti.</p>
<div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--brd)"><div style="font-size:11px;color:var(--td);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px"><i class="fa-solid fa-code"></i> Migrazioni SQL</div><div class="sl" id="sqls"></div></div>
</div></div></div></div>

<!-- CRON SETUP -->
<div class="c" style="margin-bottom:24px">
  <div class="ch"><span><i class="fa-solid fa-clock"></i> Backup Automatico (Cron)</span></div>
  <div class="cb">
    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
      <div style="flex:1;min-width:280px">
        <p style="font-size:13px;margin-bottom:12px">Il backup automatico crea un archivio completo (codice + DB) ogni giorno. Per attivarlo, esegui sul server:</p>
        <code id="cron-cmd" style="display:block;background:var(--bg);border:1px solid var(--brd);border-radius:8px;padding:12px 16px;font-family:var(--m);font-size:12px;color:var(--acc);word-break:break-all;cursor:pointer" onclick="navigator.clipboard.writeText(this.textContent);T('Comando copiato','ok')" title="Clicca per copiare">
          Caricamento...
        </code>
        <p style="font-size:11px;color:var(--td);margin-top:8px"><i class="fa-solid fa-info-circle"></i> Aggiungilo al crontab con <code>crontab -e</code>. Tiene gli ultimi 7 backup automatici.</p>
      </div>
      <div style="min-width:200px">
        <div style="font-size:11px;color:var(--td);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Stato</div>
        <div id="cron-status" style="font-size:13px;color:var(--td)">Caricamento...</div>
        <div style="margin-top:12px;font-size:11px;color:var(--td);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Ultimo Download</div>
        <div id="dl-status" style="font-size:13px;color:var(--td)">Caricamento...</div>
      </div>
    </div>
  </div>
</div>
</div>

<script>
const C=<?=$cj?>;
function T(m,t='tin'){const e=document.createElement('div');e.className='tt t'+t;e.innerHTML=m;document.body.appendChild(e);setTimeout(()=>e.remove(),4000)}
async function A(a,x={}){const b=new FormData;b.append('action',a);Object.entries(x).forEach(([k,v])=>{v instanceof File?b.append(k,v):b.append(k,v)});const r=await fetch('deploy.php',{method:'POST',body:b});const j=await r.json();if(!j.ok)throw new Error(j.error||'Errore');return j}
function startBackup(type){
const pw=document.getElementById('pw'),pf=document.getElementById('pf'),pm=document.getElementById('pm');
const btn=document.getElementById(type==='full'?'bf':'bd2');
pw.style.display='block';pf.style.width='0%';pm.textContent='Avvio...';btn.disabled=true;
fetch('deploy.php?run_backup='+type).catch(()=>{});
const poll=setInterval(async()=>{
try{const r=await fetch('deploy.php?check_progress');const d=await r.json();
if(!d.pct&&!d.msg)return;
if(d.pct===-1){clearInterval(poll);btn.disabled=false;pw.style.display='none';T('<i class="fa-solid fa-circle-exclamation"></i> '+d.msg,'er');return}
pf.style.width=Math.max(0,d.pct)+'%';pm.textContent=d.msg;
if(d.pct>=100){clearInterval(poll);btn.disabled=false;T('<i class="fa-solid fa-check"></i> '+d.msg,'ok');setTimeout(()=>{pw.style.display='none'},2000);LB();LI()}
}catch(e){}},600);
setTimeout(()=>{clearInterval(poll);if(btn.disabled){btn.disabled=false;pw.style.display='none';T('Timeout — controlla manualmente','er');LB();LI()}},300000);
}
document.getElementById('bf').onclick=()=>startBackup('full');
document.getElementById('bd2').onclick=()=>startBackup('db');
async function LB(){const j=await A('list_backups');const el=document.getElementById('blist');if(!j.backups.length){el.innerHTML='<div style="text-align:center;padding:16px;color:var(--td)">Nessun backup</div>';return}
const ic={full:'box-archive',db:'database',section:'cube',pre:'clock-rotate-left',code:'file-zipper'};
const co={full:'var(--acc)',db:'var(--ok)',section:'var(--w)',pre:'var(--td)',code:'var(--td)'};
const lb={full:'Completo',db:'Solo DB',section:'Comparto',pre:'Pre-deploy',code:'Codice'};
el.innerHTML=j.backups.map(b=>`<div class="bi"><div class="bii" style="background:${co[b.type]}22;color:${co[b.type]}"><i class="fa-solid fa-${ic[b.type]}"></i></div><div style="flex:1"><div class="bin">${b.name}</div><div class="bim">${b.date} • ${b.size>1024?(b.size/1024).toFixed(1)+' MB':b.size+' KB'} • ${lb[b.type]}</div></div><div style="display:flex;gap:4px"><a href="deploy.php?download=${encodeURIComponent(b.name)}" class="b bs"><i class="fa-solid fa-download"></i></a><button class="b bs bd" onclick="DB('${b.name}')"><i class="fa-solid fa-trash"></i></button></div></div>`).join('')}
async function DB(f){if(!confirm('Eliminare?'))return;await A('delete_backup',{file:f});T('Eliminato','ok');LB();LI()}
function RC(info){document.getElementById('comp').innerHTML=Object.entries(C).map(([k,c])=>{
const n=info['count_'+k]||0;
const detail=info['detail_'+k]||[];
const detailHtml=detail.map(d=>`<span class="co-td${d.error?' co-te':''}">${d.table}: <strong>${d.count}</strong></span>`).join('');
let extra='';
if(k==='ddt'){
  if(info.ddt_counter)extra+=`<span class="co-td co-tc">Contatore: <strong>${info.ddt_counter.last_num}/${info.ddt_counter.anno}</strong></span>`;
  if(info.ddt_magazzino_esterno>0)extra+=`<span class="co-td co-tw">Mag. Esterno: <strong>${info.ddt_magazzino_esterno}</strong></span>`;
}
return`<div class="co"><div class="coh"><div class="coi" style="background:${c.color}18;color:${c.color}"><i class="fa-solid fa-${c.icon}"></i></div><div style="flex:1"><div class="con">${c.label}</div><div class="co-dt">${detailHtml}${extra}</div></div><div class="coc" style="color:${c.color}">${n}</div></div><div class="coa"><button class="b bs bg" onclick="BS('${k}')"><i class="fa-solid fa-download"></i> Backup</button><label class="b bs bw" style="cursor:pointer"><i class="fa-solid fa-upload"></i> Ripristina<input type="file" accept=".zip,.sql" style="display:none" onchange="RS(this.files[0])"></label><button class="b bs bd" onclick="CS('${k}','${c.label}')"><i class="fa-solid fa-trash"></i> Svuota</button></div></div>`}).join('')}
async function BS(s){T('<i class="fa-solid fa-spinner sp"></i> Backup comparto...','in');const j=await A('backup_section',{section:s});T('<i class="fa-solid fa-check"></i> '+j.file+' ('+j.size+')','ok');LB()}
async function CS(s,l){if(!confirm('⚠️ Svuotare TUTTI i dati di "'+l+'"?\nBackup automatico prima della cancellazione.'))return;if(!confirm('CONFERMA DEFINITIVA: eliminare tutti i record di "'+l+'"?\nIl backup viene creato automaticamente.'))return;T('<i class="fa-solid fa-spinner sp"></i> Svuotamento...','in');const j=await A('clear_section',{section:s});T('<i class="fa-solid fa-check"></i> '+(j.msg||j.deleted+' eliminati'),'ok');LI()}
async function RS(f){if(!f)return;if(!confirm('Ripristinare da '+f.name+'?'))return;T('<i class="fa-solid fa-spinner sp"></i> Ripristino...','in');const b=new FormData;b.append('action','restore_section');b.append('sqlfile',f);const r=await fetch('deploy.php',{method:'POST',body:b});const j=await r.json();if(!j.ok){T('<i class="fa-solid fa-circle-exclamation"></i> '+j.error,'er');return}T('<i class="fa-solid fa-check"></i> '+j.msg,'ok');LI()}
async function LS(){const j=await A('list_sql');document.getElementById('sqls').innerHTML=j.files.map(f=>`<div class="si" onclick="XS('${f}')"><i class="fa-solid fa-play" style="font-size:9px"></i> ${f}</div>`).join('')}
async function XS(f){if(!confirm('Eseguire '+f+'?'))return;T('<i class="fa-solid fa-spinner sp"></i> '+f+'...','in');await A('run_sql',{file:f});T('<i class="fa-solid fa-check"></i> '+f+' OK','ok');LI()}
async function LI(){
  const j=await A('info');const i=j.info;RC(i);
  // Alert banner
  const al=document.getElementById('alert-dl'),at=document.getElementById('alert-title'),am=document.getElementById('alert-msg'),ab=document.getElementById('alert-btn');
  const d=i.days_since_download;
  if(d===null){al.style.display='flex';al.className='al al-d';at.textContent='⚠ Nessun backup mai scaricato sul PC!';am.textContent='I backup sul server non ti proteggono se il server muore. Scarica un backup sul tuo PC.';ab.style.display='none'}
  else if(d>=7){al.style.display='flex';al.className='al al-w';at.textContent='Ultimo download: '+i.last_download+' ('+d+' giorni fa)';am.textContent='Consigliamo di scaricare un backup aggiornato almeno ogni settimana.';ab.style.display='none'}
  else{al.style.display='none'}
  // Find latest backup for quick download
  try{const bl=await A('list_backups');if(bl.backups.length>0){const latest=bl.backups[0];ab.href='deploy.php?download='+encodeURIComponent(latest.name);ab.style.display='inline-flex'}}catch(e){}
  // Cron section
  const cc=document.getElementById('cron-cmd');
  if(cc&&i.cron_url)cc.textContent='0 2 * * * wget -q "'+i.cron_url+'" -O /dev/null';
  const cs=document.getElementById('cron-status');
  if(cs)cs.innerHTML=i.last_cron?'<span style="color:var(--ok)"><i class="fa-solid fa-check-circle"></i> '+i.last_cron+'</span>':'<span style="color:var(--w)"><i class="fa-solid fa-circle-exclamation"></i> Non ancora eseguito</span>';
  const ds=document.getElementById('dl-status');
  if(ds)ds.innerHTML=i.last_download?'<span style="color:var(--ok)">'+i.last_download+'</span><br><span style="font-size:11px;color:var(--td)">'+i.last_download_file+'</span>':'<span style="color:var(--err)">Mai scaricato</span>';
}
const uz=document.getElementById('uz'),fi=document.getElementById('zf');
['dragenter','dragover'].forEach(e=>uz.addEventListener(e,ev=>{ev.preventDefault();uz.classList.add('dg')}));
['dragleave','drop'].forEach(e=>uz.addEventListener(e,ev=>{ev.preventDefault();uz.classList.remove('dg')}));
uz.addEventListener('drop',e=>{const f=e.dataTransfer.files[0];if(f?.name.endsWith('.zip'))DF(f);else T('Solo .zip','er')});
fi.addEventListener('change',()=>{if(fi.files[0])DF(fi.files[0])});
async function DF(f){if(!confirm('Deploy '+f.name+'?'))return;T('<i class="fa-solid fa-spinner sp"></i> Deploy...','in');const b=new FormData;b.append('action','deploy');b.append('zipfile',f);const r=await fetch('deploy.php',{method:'POST',body:b});const j=await r.json();if(!j.ok){T('<i class="fa-solid fa-circle-exclamation"></i> '+j.error,'er');return}T('<i class="fa-solid fa-check"></i> Deploy: '+j.extracted+' file','ok');LB();LI();LS()}
LI();LB();LS();
</script>
<?php endif;?>
</body></html>
