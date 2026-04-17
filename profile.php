<?php
/**
 * openLogistic v47 - Profilo Utente
 * Cambio password e gestione PIN (per tutti gli utenti)
 */
session_start();
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/db.php';

// Carica modulo Trusted Devices se esiste
$trustedDevicesEnabled = file_exists(__DIR__ . '/api/trusted_devices.php');
if ($trustedDevicesEnabled) {
    require_once __DIR__ . '/api/trusted_devices.php';
    $trustedDevicesEnabled = isTrustedDevicesEnabled();
}

// Verifica login
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if ($trustedDevicesEnabled) {
    touchTrustedDeviceLastUsed((int)$_SESSION['user_id']);
}

$userId = $_SESSION['user_id'];
$user = [
    'id' => $userId,
    'username' => $_SESSION['username'] ?? '',
    'nome' => $_SESSION['nome'] ?? '',
    'cognome' => $_SESSION['cognome'] ?? '',
    'role' => $_SESSION['role'] ?? 'operator'
];

$message = '';
$messageType = '';
$activeTab = $_GET['tab'] ?? 'password';

// Carica dati utente dal DB
$stmt = $pdo->prepare('SELECT username, nome, cognome, role, last_login, recovery_pin_hash IS NOT NULL as has_pin FROM users WHERE id = ?');
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

// Conta dispositivi fidati
$deviceCount = 0;
$devices = [];
if ($trustedDevicesEnabled) {
    $devices = getUserDevices($userId);
    $deviceCount = count($devices);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_or_die();
}

// Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'change_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Verifica password attuale
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            
            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                $message = 'La password attuale non è corretta.';
                $messageType = 'error';
                break;
            }
            
            if (strlen($newPassword) < 6) {
                $message = 'La nuova password deve essere almeno 6 caratteri.';
                $messageType = 'error';
                break;
            }
            
            if ($newPassword !== $confirmPassword) {
                $message = 'Le password non coincidono.';
                $messageType = 'error';
                break;
            }
            
            // Aggiorna password
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$newHash, $userId]);
            
            $message = 'Password aggiornata con successo!';
            $messageType = 'success';
            break;
            
        case 'change_pin':
            if (!$trustedDevicesEnabled) break;
            
            $currentPin = $_POST['current_pin'] ?? '';
            $newPin = $_POST['new_pin'] ?? '';
            $confirmPin = $_POST['confirm_pin'] ?? '';
            
            // Se ha già un PIN, verifica quello attuale
            if ($userData['has_pin']) {
                $result = verifyRecoveryPin($userId, $currentPin);
                if (!$result['success']) {
                    $message = $result['error'];
                    $messageType = 'error';
                    $activeTab = 'pin';
                    break;
                }
            }
            
            if (!preg_match('/^\d{4,6}$/', $newPin)) {
                $message = 'Il PIN deve essere di 4-6 cifre.';
                $messageType = 'error';
                $activeTab = 'pin';
                break;
            }
            
            if ($newPin !== $confirmPin) {
                $message = 'I PIN non coincidono.';
                $messageType = 'error';
                $activeTab = 'pin';
                break;
            }
            
            if (setRecoveryPin($userId, $newPin)) {
                $message = 'PIN aggiornato con successo!';
                $messageType = 'success';
                $userData['has_pin'] = true;
            } else {
                $message = 'Errore nel salvataggio del PIN.';
                $messageType = 'error';
            }
            $activeTab = 'pin';
            break;
            
        case 'revoke_device':
            if (!$trustedDevicesEnabled) break;
            
            $deviceId = (int)($_POST['device_id'] ?? 0);
            if ($deviceId > 0 && revokeDevice($deviceId, $userId)) {
                $message = 'Dispositivo rimosso.';
                $messageType = 'success';
                $devices = getUserDevices($userId);
                $deviceCount = count($devices);
            }
            $activeTab = 'devices';
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
    <title>Profilo - openLogistic</title>
    <link rel="icon" type="image/png" href="assets/img/openLogistic.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/admin.css') ?>">
    <style>
        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .profile-avatar {
            width: 64px;
            height: 64px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 600;
        }
        
        .profile-info h2 {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 4px;
        }
        
        .profile-info p {
            color: var(--gray-500);
            font-size: 14px;
            margin: 0;
        }
        
        .profile-stats {
            display: flex;
            gap: 24px;
            margin-left: auto;
        }
        
        .profile-stat {
            text-align: center;
        }
        
        .profile-stat-value {
            font-size: 24px;
            font-weight: 600;
            color: var(--accent);
        }
        
        .profile-stat-label {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .password-strength {
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s;
        }
        
        .password-strength-bar.weak { width: 33%; background: var(--error); }
        .password-strength-bar.medium { width: 66%; background: var(--warning); }
        .password-strength-bar.strong { width: 100%; background: var(--success); }
    </style>
</head>
<body>
    <div class="page">
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fa-solid fa-user-circle"></i>
                    Il mio profilo
                </h1>
            </div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i>
                Torna a openLogistic
            </a>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= h($messageType) ?>">
                <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                <span><?= h($message) ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Profilo Header -->
        <div class="card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?= strtoupper(substr($userData['nome'] ?: $userData['username'], 0, 1)) ?>
                </div>
                <div class="profile-info">
                    <h2><?= h($userData['nome'] && $userData['cognome'] ? $userData['nome'] . ' ' . $userData['cognome'] : $userData['username']) ?></h2>
                    <p>
                        <i class="fa-solid fa-user"></i> <?= h($userData['username']) ?> · 
                        <span class="badge badge-<?= $userData['role'] ?>"><?= $userData['role'] === 'admin' ? 'Amministratore' : 'Operatore' ?></span>
                    </p>
                </div>
                <?php if ($trustedDevicesEnabled): ?>
                <div class="profile-stats">
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?= $deviceCount ?></div>
                        <div class="profile-stat-label">Dispositivi</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?= $userData['has_pin'] ? '✓' : '✗' ?></div>
                        <div class="profile-stat-label">PIN</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="?tab=password" class="tab <?= $activeTab === 'password' ? 'active' : '' ?>">
                <i class="fa-solid fa-lock"></i> Cambia Password
            </a>
            <?php if ($trustedDevicesEnabled): ?>
            <a href="?tab=pin" class="tab <?= $activeTab === 'pin' ? 'active' : '' ?>">
                <i class="fa-solid fa-key"></i> PIN di Sicurezza
            </a>
            <a href="?tab=devices" class="tab <?= $activeTab === 'devices' ? 'active' : '' ?>">
                <i class="fa-solid fa-laptop"></i> Dispositivi Fidati
                <?php if ($deviceCount > 0): ?>
                    <span class="badge" style="background:var(--accent);color:white;margin-left:6px;"><?= $deviceCount ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </div>
        
        <?php if ($activeTab === 'password'): ?>
        <!-- CAMBIO PASSWORD -->
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-lock"></i> Cambia Password</h3>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                
                <div class="form-grid" style="max-width:400px;">
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label">Password attuale</label>
                        <input type="password" name="current_password" class="form-input" required autocomplete="current-password">
                    </div>
                    
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label">Nuova password</label>
                        <input type="password" name="new_password" id="new_password" class="form-input" required minlength="6" autocomplete="new-password">
                        <div class="password-strength">
                            <div class="password-strength-bar" id="strength-bar"></div>
                        </div>
                    </div>
                    
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label">Conferma nuova password</label>
                        <input type="password" name="confirm_password" class="form-input" required minlength="6" autocomplete="new-password">
                    </div>
                </div>
                
                <div class="form-actions" style="justify-content:flex-start;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-check"></i> Aggiorna Password
                    </button>
                </div>
            </form>
        </div>
        
        <?php elseif ($activeTab === 'pin' && $trustedDevicesEnabled): ?>
        <!-- GESTIONE PIN -->
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-key"></i> PIN di Sicurezza</h3>
            <p class="text-muted" style="margin-bottom:20px;">
                Il PIN ti permette di resettare la password autonomamente da un dispositivo fidato, senza contattare l'amministratore.
            </p>
            
            <form method="post">
                <input type="hidden" name="action" value="change_pin">
                
                <div class="form-grid" style="max-width:300px;">
                    <?php if ($userData['has_pin']): ?>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label">PIN attuale</label>
                        <input type="password" name="current_pin" class="form-input" style="text-align:center;letter-spacing:8px;font-size:20px;" 
                               maxlength="6" pattern="\d{4,6}" inputmode="numeric" required>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label"><?= $userData['has_pin'] ? 'Nuovo PIN' : 'PIN' ?></label>
                        <input type="password" name="new_pin" class="form-input" style="text-align:center;letter-spacing:8px;font-size:20px;" 
                               maxlength="6" pattern="\d{4,6}" inputmode="numeric" required>
                        <div style="font-size:12px;color:var(--gray-400);margin-top:4px;text-align:center;">4-6 cifre</div>
                    </div>
                    
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label">Conferma PIN</label>
                        <input type="password" name="confirm_pin" class="form-input" style="text-align:center;letter-spacing:8px;font-size:20px;" 
                               maxlength="6" pattern="\d{4,6}" inputmode="numeric" required>
                    </div>
                </div>
                
                <div class="form-actions" style="justify-content:flex-start;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-check"></i> <?= $userData['has_pin'] ? 'Aggiorna PIN' : 'Imposta PIN' ?>
                    </button>
                </div>
            </form>
        </div>
        
        <?php elseif ($activeTab === 'devices' && $trustedDevicesEnabled): ?>
        <!-- DISPOSITIVI FIDATI -->
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-laptop"></i> Dispositivi Fidati</h3>
            <p class="text-muted" style="margin-bottom:20px;">
                Questi dispositivi possono essere usati per resettare la password con il PIN.
            </p>
            
            <?php if (count($devices) > 0): ?>
            <table>
                <thead>
                    <tr>
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
                            <strong><?= h($d['device_name']) ?></strong>
                            <div class="text-muted"><?= h(substr($d['user_agent'] ?? '', 0, 50)) ?>...</div>
                        </td>
                        <td><code><?= h($d['ip_address']) ?></code></td>
                        <td class="text-muted"><?= $d['last_used_at'] ? date('d/m/Y H:i', strtotime($d['last_used_at'])) : ('Registrato il ' . date('d/m/Y H:i', strtotime($d['created_at']))) ?></td>
                        <td class="text-muted"><?= date('d/m/Y', strtotime($d['expires_at'])) ?></td>
                        <td>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Rimuovere questo dispositivo?');">
                                <input type="hidden" name="action" value="revoke_device">
                                <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
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
                <p class="text-muted">Seleziona "Ricordami" al prossimo login per registrare questo dispositivo.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Password strength indicator
        document.getElementById('new_password')?.addEventListener('input', function() {
            const bar = document.getElementById('strength-bar');
            const len = this.value.length;
            const hasNumber = /\d/.test(this.value);
            const hasLower = /[a-z]/.test(this.value);
            const hasUpper = /[A-Z]/.test(this.value);
            const hasSpecial = /[^a-zA-Z0-9]/.test(this.value);
            
            let strength = 0;
            if (len >= 6) strength++;
            if (len >= 8 && (hasNumber || hasSpecial)) strength++;
            if (len >= 10 && hasNumber && hasSpecial && hasUpper && hasLower) strength++;
            
            bar.className = 'password-strength-bar';
            if (strength === 1) bar.classList.add('weak');
            else if (strength === 2) bar.classList.add('medium');
            else if (strength >= 3) bar.classList.add('strong');
        });
        
        // PIN input formatting
        document.querySelectorAll('input[inputmode="numeric"]').forEach(function(input) {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
        });
    </script>
</body>
</html>
