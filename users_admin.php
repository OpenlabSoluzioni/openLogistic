<?php
/**
 * openLogistic v47 - Gestione Utenti (Admin)
 * Con supporto Trusted Devices e Reset Requests
 */
session_start();
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/trusted_devices.php';

// Verifica accesso admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

if (isTrustedDevicesEnabled()) {
    touchTrustedDeviceLastUsed((int)$_SESSION['user_id']);
}

$currentUser = [
    'id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'] ?? '',
    'nome' => $_SESSION['nome'] ?? '',
    'cognome' => $_SESSION['cognome'] ?? ''
];

$activeTab = $_GET['tab'] ?? 'users';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_or_die();
}

// Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    switch ($action) {
        // Utenti
        case 'save_user':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $nome = trim($_POST['nome'] ?? '');
            $cognome = trim($_POST['cognome'] ?? '');
            $role = $_POST['role'] ?? 'operator';
            
            if ($username) {
                try {
                    if ($id > 0) {
                        if ($password) {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare('UPDATE users SET username=?, password_hash=?, nome=?, cognome=?, role=? WHERE id=?');
                            $stmt->execute([$username, $hash, $nome, $cognome, $role, $id]);
                        } else {
                            $stmt = $pdo->prepare('UPDATE users SET username=?, nome=?, cognome=?, role=? WHERE id=?');
                            $stmt->execute([$username, $nome, $cognome, $role, $id]);
                        }
                        $message = 'Utente aggiornato.';
                    } else {
                        if (!$password) throw new Exception('Password obbligatoria');
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, nome, cognome, role) VALUES (?, ?, ?, ?, ?)');
                        $stmt->execute([$username, $hash, $nome, $cognome, $role]);
                        $message = 'Utente creato.';
                    }
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Errore: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
            break;
            
        case 'delete_user':
            if ($id > 0 && $id !== $currentUser['id']) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                $message = 'Utente eliminato.';
                $messageType = 'success';
            }
            break;
            
        case 'reset_pin':
            if ($id > 0) {
                resetUserPin($id);
                $message = 'PIN resettato.';
                $messageType = 'success';
            }
            break;
            
        // Dispositivi
        case 'revoke_device':
            $deviceId = (int)($_POST['device_id'] ?? 0);
            if ($deviceId > 0) {
                revokeDevice($deviceId);
                $message = 'Dispositivo revocato.';
                $messageType = 'success';
            }
            $activeTab = 'devices';
            break;
            
        case 'revoke_all_devices':
            if ($id > 0) {
                $count = revokeAllDevices($id);
                $message = "Revocati {$count} dispositivi.";
                $messageType = 'success';
            }
            $activeTab = 'devices';
            break;
            
        // Richieste reset
        case 'approve_reset':
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId > 0) {
                $result = approveResetRequest($requestId, $currentUser['id']);
                if ($result['success']) {
                    $message = "Reset approvato. Password temporanea: <strong>{$result['password']}</strong>";
                    $messageType = 'success';
                } else {
                    $message = 'Errore: ' . ($result['error'] ?? 'Sconosciuto');
                    $messageType = 'error';
                }
            }
            $activeTab = 'requests';
            break;
            
        case 'reject_reset':
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId > 0) {
                rejectResetRequest($requestId, $currentUser['id']);
                $message = 'Richiesta rifiutata.';
                $messageType = 'success';
            }
            $activeTab = 'requests';
            break;
            
        // Richieste registrazione
        case 'approve_registration':
            $requestId = (int)($_POST['request_id'] ?? 0);
            if ($requestId > 0) {
                try {
                    // Recupera richiesta
                    $stmt = $pdo->prepare('SELECT * FROM registration_requests WHERE id = ? AND status = "pending"');
                    $stmt->execute([$requestId]);
                    $req = $stmt->fetch();
                    
                    if ($req) {
                        // Crea utente
                        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, nome, cognome, email, role) VALUES (?, ?, ?, ?, ?, "operator")');
                        $stmt->execute([$req['username'], $req['password_hash'], $req['nome'], $req['cognome'], $req['email']]);
                        
                        // Aggiorna richiesta
                        $stmt = $pdo->prepare('UPDATE registration_requests SET status = "approved", reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
                        $stmt->execute([$currentUser['id'], $requestId]);
                        
                        $message = "Registrazione approvata. L'utente <strong>{$req['username']}</strong> può ora accedere.";
                        $messageType = 'success';
                    } else {
                        $message = 'Richiesta non trovata o già processata.';
                        $messageType = 'error';
                    }
                } catch (PDOException $e) {
                    $message = 'Errore: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
            $activeTab = 'registrations';
            break;
            
        case 'reject_registration':
            $requestId = (int)($_POST['request_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if ($requestId > 0) {
                $stmt = $pdo->prepare('UPDATE registration_requests SET status = "rejected", rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = "pending"');
                $stmt->execute([$reason ?: null, $currentUser['id'], $requestId]);
                $message = 'Richiesta di registrazione rifiutata.';
                $messageType = 'success';
            }
            $activeTab = 'registrations';
            break;
    }
}

// Lettura dati
$users = $pdo->query('
    SELECT u.id, u.username, u.nome, u.cognome, u.role, u.last_login,
           u.recovery_pin_hash IS NOT NULL as has_pin
    FROM users u
    ORDER BY u.cognome, u.nome, u.username
')->fetchAll(PDO::FETCH_ASSOC);

// Conta dispositivi per utente
$deviceCounts = [];
try {
    $stmt = $pdo->query('SELECT user_id, COUNT(*) as cnt FROM user_trusted_devices WHERE is_active = 1 AND expires_at > NOW() GROUP BY user_id');
    while ($row = $stmt->fetch()) {
        $deviceCounts[$row['user_id']] = $row['cnt'];
    }
} catch (PDOException $e) {}

// Dispositivi (tutti)
$devices = [];
try {
    $devices = $pdo->query('
        SELECT d.*, u.username, u.nome, u.cognome
        FROM user_trusted_devices d
        JOIN users u ON d.user_id = u.id
        WHERE d.is_active = 1 AND d.expires_at > NOW()
        ORDER BY COALESCE(d.last_used_at, d.created_at) DESC, d.created_at DESC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Richieste reset pendenti
$pendingRequests = getPendingResetRequests();

// Richieste registrazione pendenti
$pendingRegistrations = [];
try {
    $pendingRegistrations = $pdo->query('
        SELECT * FROM registration_requests 
        WHERE status = "pending" 
        ORDER BY created_at ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <?= csrf_meta_tag() ?>
    <?= csrf_bootstrap_script() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Utenti - openLogistic</title>
    <link rel="icon" type="image/png" href="assets/img/openLogistic.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/admin.css') ?>">
</head>
<body>
    <div class="page">
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fa-solid fa-users-gear"></i>
                    Gestione Utenti
                </h1>
                <div class="user-info-small">
                    Connesso come: <?= h($currentUser['nome'] ?: $currentUser['username']) ?> (admin)
                </div>
            </div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i>
                Torna a openLogistic
            </a>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= h($messageType) ?>">
                <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                <span><?= $message ?></span>
            </div>
        <?php endif; ?>
        
        <div class="tabs">
            <a href="?tab=users" class="tab <?= $activeTab === 'users' ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> Utenti
            </a>
            <a href="?tab=devices" class="tab <?= $activeTab === 'devices' ? 'active' : '' ?>">
                <i class="fa-solid fa-laptop"></i> Dispositivi Fidati
            </a>
            <a href="?tab=registrations" class="tab <?= $activeTab === 'registrations' ? 'active' : '' ?>">
                <i class="fa-solid fa-user-plus"></i> Registrazioni
                <?php if (count($pendingRegistrations) > 0): ?>
                    <span class="badge"><?= count($pendingRegistrations) ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=requests" class="tab <?= $activeTab === 'requests' ? 'active' : '' ?>">
                <i class="fa-solid fa-key"></i> Richieste Reset
                <?php if (count($pendingRequests) > 0): ?>
                    <span class="badge"><?= count($pendingRequests) ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <?php if ($activeTab === 'users'): ?>
            <!-- FORM UTENTE -->
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-user-plus"></i> Nuovo / Modifica Utente</h3>
                <form method="post" id="user-form">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="id" id="form-id" value="">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" id="form-username" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" id="form-password" class="form-input" placeholder="Obbligatoria per nuovo">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" id="form-nome" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cognome</label>
                            <input type="text" name="cognome" id="form-cognome" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ruolo</label>
                            <select name="role" id="form-role" class="form-select">
                                <option value="operator">Operatore</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">Annulla</button>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Salva</button>
                    </div>
                </form>
            </div>
            
            <!-- LISTA UTENTI -->
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-list"></i> Elenco Utenti</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Utente</th>
                            <th>Ruolo</th>
                            <th>PIN</th>
                            <th>Dispositivi</th>
                            <th>Ultimo accesso</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <strong><?= h($u['nome'] && $u['cognome'] ? $u['nome'] . ' ' . $u['cognome'] : $u['username']) ?></strong>
                                <div class="text-muted"><?= h($u['username']) ?></div>
                            </td>
                            <td>
                                <span class="badge badge-<?= $u['role'] ?>"><?= h($u['role']) ?></span>
                            </td>
                            <td>
                                <?php if ($u['has_pin']): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check"></i> OK</span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><i class="fa-solid fa-minus"></i> No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $dc = $deviceCounts[$u['id']] ?? 0; ?>
                                <?php if ($dc > 0): ?>
                                    <span class="badge badge-info"><i class="fa-solid fa-laptop"></i> <?= $dc ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted">
                                <?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : '-' ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <button class="btn btn-secondary btn-sm" onclick="editUser(<?= $u['id'] ?>, '<?= h($u['username']) ?>', '<?= h($u['nome']) ?>', '<?= h($u['cognome']) ?>', '<?= h($u['role']) ?>')">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <?php if ($u['has_pin']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Resettare il PIN?');">
                                        <input type="hidden" name="action" value="reset_pin">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button class="btn btn-secondary btn-sm" title="Reset PIN"><i class="fa-solid fa-key"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($u['id'] !== $currentUser['id']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questo utente?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($activeTab === 'devices'): ?>
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-laptop"></i> Dispositivi Fidati Attivi</h3>
                <?php if (count($devices) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Utente</th>
                            <th>Dispositivo</th>
                            <th>IP</th>
                            <th>Ultimo utilizzo</th>
                            <th>Scadenza</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $d): ?>
                        <tr>
                            <td>
                                <strong><?= h($d['nome'] && $d['cognome'] ? $d['nome'] . ' ' . $d['cognome'] : $d['username']) ?></strong>
                            </td>
                            <td>
                                <?= h($d['device_name']) ?>
                                <div class="text-muted"><?= h(substr($d['user_agent'] ?? '', 0, 40)) ?>...</div>
                            </td>
                            <td><code><?= h($d['ip_address']) ?></code></td>
                            <td class="text-muted"><?= $d['last_used_at'] ? date('d/m/Y H:i', strtotime($d['last_used_at'])) : ('Registrato il ' . date('d/m/Y H:i', strtotime($d['created_at']))) ?></td>
                            <td class="text-muted"><?= date('d/m/Y', strtotime($d['expires_at'])) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Revocare questo dispositivo?');">
                                    <input type="hidden" name="action" value="revoke_device">
                                    <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                    <button class="btn btn-danger btn-sm"><i class="fa-solid fa-ban"></i> Revoca</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-laptop"></i>
                    <p>Nessun dispositivo fidato registrato.</p>
                </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($activeTab === 'registrations'): ?>
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-user-plus"></i> Richieste di Registrazione</h3>
                <?php if (count($pendingRegistrations) > 0): ?>
                    <?php foreach ($pendingRegistrations as $req): ?>
                    <div class="request-card">
                        <div class="request-header">
                            <span class="request-user">
                                <i class="fa-solid fa-user-plus"></i>
                                <strong><?= h($req['nome']) ?> <?= h($req['cognome']) ?></strong>
                                <span class="text-muted">(<?= h($req['username']) ?>)</span>
                            </span>
                            <span class="request-time"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></span>
                        </div>
                        <?php if ($req['email']): ?>
                        <div class="request-reason">
                            <i class="fa-solid fa-envelope"></i> <?= h($req['email']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="request-actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="approve_registration">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <button class="btn btn-success btn-sm"><i class="fa-solid fa-check"></i> Approva</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirmReject(this);">
                                <input type="hidden" name="action" value="reject_registration">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <input type="hidden" name="reason" class="reject-reason" value="">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-xmark"></i> Rifiuta</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    <p>Nessuna richiesta di registrazione in attesa.</p>
                </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($activeTab === 'requests'): ?>
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-key"></i> Richieste di Reset Password</h3>
                <?php if (count($pendingRequests) > 0): ?>
                    <?php foreach ($pendingRequests as $req): ?>
                    <div class="request-card">
                        <div class="request-header">
                            <span class="request-user">
                                <i class="fa-solid fa-user"></i>
                                <?= h($req['nome'] && $req['cognome'] ? $req['nome'] . ' ' . $req['cognome'] : $req['username']) ?>
                                <span class="text-muted">(<?= h($req['username']) ?>)</span>
                            </span>
                            <span class="request-time"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></span>
                        </div>
                        <?php if ($req['request_reason']): ?>
                        <div class="request-reason">
                            <i class="fa-solid fa-comment"></i> <?= h($req['request_reason']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="request-actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="approve_reset">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <button class="btn btn-success btn-sm"><i class="fa-solid fa-check"></i> Approva</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="reject_reset">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <button class="btn btn-danger btn-sm"><i class="fa-solid fa-xmark"></i> Rifiuta</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    <p>Nessuna richiesta di reset in attesa.</p>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function editUser(id, username, nome, cognome, role) {
            document.getElementById('form-id').value = id;
            document.getElementById('form-username').value = username;
            document.getElementById('form-nome').value = nome;
            document.getElementById('form-cognome').value = cognome;
            document.getElementById('form-role').value = role;
            document.getElementById('form-password').value = '';
            document.getElementById('form-password').placeholder = 'Lascia vuoto per non modificare';
            document.getElementById('user-form').scrollIntoView({ behavior: 'smooth' });
        }
        
        function resetForm() {
            document.getElementById('form-id').value = '';
            document.getElementById('form-username').value = '';
            document.getElementById('form-nome').value = '';
            document.getElementById('form-cognome').value = '';
            document.getElementById('form-role').value = 'operator';
            document.getElementById('form-password').value = '';
            document.getElementById('form-password').placeholder = 'Obbligatoria per nuovo';
        }
        
        function confirmReject(form) {
            const reason = prompt('Motivo del rifiuto (opzionale):');
            if (reason === null) return false; // Annullato
            form.querySelector('.reject-reason').value = reason;
            return true;
        }
    </script>
</body>
</html>
