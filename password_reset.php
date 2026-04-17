<?php
/**
 * openLogistic v47 - Reset Password
 * Tramite Trusted Device + PIN
 */
session_start();
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/trusted_devices.php';

$message = '';
$messageType = '';
$pageState = 'check_device';
$trustedUser = null;

// Verifica dispositivo fidato
$deviceData = verifyTrustedDevice();
if ($deviceData) {
    $trustedUser = $deviceData;
    $pageState = $deviceData['has_pin'] ? 'enter_pin' : 'no_pin';
} else {
    $pageState = 'no_device';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_or_die();
}

// Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'verify_pin':
            if (!$trustedUser) {
                $message = 'Dispositivo non riconosciuto.';
                $messageType = 'error';
                break;
            }
            
            $pin = $_POST['pin'] ?? '';
            $result = verifyRecoveryPin($trustedUser['user_id'], $pin);
            
            if ($result['success']) {
                $pageState = 'new_password';
                $_SESSION['reset_authorized'] = true;
                $_SESSION['reset_user_id'] = $trustedUser['user_id'];
            } else {
                $message = $result['error'];
                $messageType = 'error';
                $pageState = 'enter_pin';
                logResetAttempt($trustedUser['user_id'], $trustedUser['username'], 'pin', false, $result['error']);
            }
            break;
            
        case 'set_password':
            if (empty($_SESSION['reset_authorized']) || empty($_SESSION['reset_user_id'])) {
                $message = 'Sessione scaduta. Riprova.';
                $messageType = 'error';
                $pageState = 'check_device';
                break;
            }
            
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (strlen($newPassword) < 6) {
                $message = 'La password deve essere almeno 6 caratteri.';
                $messageType = 'error';
                $pageState = 'new_password';
                break;
            }
            
            if ($newPassword !== $confirmPassword) {
                $message = 'Le password non coincidono.';
                $messageType = 'error';
                $pageState = 'new_password';
                break;
            }
            
            if (resetPasswordWithPin($_SESSION['reset_user_id'], $newPassword)) {
                unset($_SESSION['reset_authorized'], $_SESSION['reset_user_id']);
                $pageState = 'success';
            } else {
                $message = 'Errore durante il reset. Riprova.';
                $messageType = 'error';
                $pageState = 'new_password';
            }
            break;
            
        case 'request_admin':
            $username = trim($_POST['username'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            
            if ($username === '') {
                $message = 'Inserisci il tuo username.';
                $messageType = 'error';
                break;
            }
            
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $message = 'Username non trovato.';
                $messageType = 'error';
                logResetAttempt(null, $username, 'admin', false, 'Username non trovato');
                break;
            }
            
            $requestId = createResetRequest($user['id'], $reason);
            
            if ($requestId) {
                $pageState = 'request_sent';
                $message = 'Richiesta inviata. Contatta l\'amministratore.';
                $messageType = 'success';
            } else {
                $message = 'Hai già una richiesta in attesa.';
                $messageType = 'warning';
            }
            break;
    }
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <?= csrf_meta_tag() ?>
    <?= csrf_bootstrap_script() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - openLogistic</title>
    <link rel="icon" type="image/png" href="assets/img/openLogistic.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/auth.css') ?>">
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <img src="assets/img/openLogistic_banner.png" alt="openLogistic" class="auth-logo">
            <h1 class="auth-title">Reset Password</h1>
            <p class="auth-subtitle">
                <?php
                switch ($pageState) {
                    case 'enter_pin': echo 'Inserisci il tuo PIN di sicurezza'; break;
                    case 'new_password': echo 'Scegli una nuova password'; break;
                    case 'success': echo 'Password aggiornata!'; break;
                    case 'no_device': echo 'Dispositivo non riconosciuto'; break;
                    case 'no_pin': echo 'PIN non configurato'; break;
                    case 'request_sent': echo 'Richiesta inviata'; break;
                    default: echo 'Recupero credenziali';
                }
                ?>
            </p>
        </div>
        <?php if ($message): ?>
            <div class="message <?= h($messageType) ?>">
                <i class="fa-solid <?= $messageType === 'error' ? 'fa-circle-exclamation' : ($messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation') ?>"></i>
                <span><?= h($message) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($pageState === 'enter_pin' && $trustedUser): ?>
            <div class="device-info">
                <div class="device-info-label">Dispositivo riconosciuto</div>
                <div class="device-info-value">
                    <i class="fa-solid fa-desktop"></i>
                    <?= h($trustedUser['device_name']) ?>
                </div>
                <div style="margin-top:10px;">
                    <div class="device-info-label">Utente</div>
                    <div class="device-info-value">
                        <?= h($trustedUser['nome'] && $trustedUser['cognome'] ? $trustedUser['nome'] . ' ' . $trustedUser['cognome'] : $trustedUser['username']) ?>
                    </div>
                </div>
            </div>
            
            <form method="post">
                <input type="hidden" name="action" value="verify_pin">
                
                <div class="form-group">
                    <label class="form-label">PIN di sicurezza</label>
                    <input type="password" name="pin" class="form-input pin-input" 
                           maxlength="6" pattern="\d{4,6}" inputmode="numeric"
                           autocomplete="off" required autofocus>
                    <div class="form-hint">4-6 cifre</div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-key"></i>
                    Verifica PIN
                </button>
            </form>
            
            <div class="divider">oppure</div>
            
            <form method="post">
                <input type="hidden" name="action" value="request_admin">
                <input type="hidden" name="username" value="<?= h($trustedUser['username']) ?>">
                <button type="submit" class="btn btn-secondary">
                    <i class="fa-solid fa-user-shield"></i>
                    Richiedi reset all'amministratore
                </button>
            </form>
            
        <?php elseif ($pageState === 'new_password'): ?>
            <form method="post">
                <input type="hidden" name="action" value="set_password">
                
                <div class="form-group">
                    <label class="form-label">Nuova password</label>
                    <input type="password" name="new_password" class="form-input" 
                           minlength="6" required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Conferma password</label>
                    <input type="password" name="confirm_password" class="form-input" 
                           minlength="6" required>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    Imposta nuova password
                </button>
            </form>
            
        <?php elseif ($pageState === 'success'): ?>
            <div class="status-icon success">
                <i class="fa-solid fa-check"></i>
            </div>
            <p style="text-align:center;color:#475569;margin-bottom:24px;">
                La tua password è stata aggiornata.<br>
                Ora puoi accedere con le nuove credenziali.
            </p>
            <a href="index.php" class="btn btn-primary">
                <i class="fa-solid fa-right-to-bracket"></i>
                Vai al login
            </a>
            
        <?php elseif ($pageState === 'request_sent'): ?>
            <div class="status-icon info">
                <i class="fa-solid fa-paper-plane"></i>
            </div>
            <p style="text-align:center;color:#475569;margin-bottom:24px;">
                La tua richiesta è stata inviata.<br>
                Contatta l'amministratore per completare il reset.
            </p>
            
        <?php elseif ($pageState === 'no_device' || $pageState === 'no_pin'): ?>
            <div class="status-icon warning">
                <i class="fa-solid fa-<?= $pageState === 'no_device' ? 'laptop' : 'key' ?>"></i>
            </div>
            <p style="text-align:center;color:#475569;margin-bottom:20px;">
                <?php if ($pageState === 'no_device'): ?>
                    Questo dispositivo non è registrato come fidato.<br>
                    Puoi richiedere il reset all'amministratore.
                <?php else: ?>
                    Non hai configurato un PIN di sicurezza.<br>
                    Contatta l'amministratore per il reset.
                <?php endif; ?>
            </p>
            
            <div class="divider">Richiedi assistenza</div>
            
            <form method="post">
                <input type="hidden" name="action" value="request_admin">
                
                <div class="form-group">
                    <label class="form-label">Il tuo username</label>
                    <input type="text" name="username" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Motivo (opzionale)</label>
                    <input type="text" name="reason" class="form-input" placeholder="es. Password dimenticata">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-paper-plane"></i>
                    Invia richiesta
                </button>
            </form>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="index.php">
                <i class="fa-solid fa-arrow-left"></i>
                Torna al login
            </a>
        </div>
    </div>
    
    <script src="<?= asset_url('assets/js/pin-utils.js') ?>"></script>
</body>
</html>
