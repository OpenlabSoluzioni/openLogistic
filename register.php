<?php
/**
 * openLogistic - Pagina Registrazione
 * Registrazione nuovi utenti con approvazione admin
 */

session_start();
require_once __DIR__ . '/api/csrf.php';

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

$error = '';
$success = false;

// Se già loggato, redirect
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Gestione submit registrazione
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_or_die();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validazioni
    if (empty($username) || empty($password) || empty($nome) || empty($cognome)) {
        $error = 'Tutti i campi obbligatori devono essere compilati.';
    } elseif (strlen($username) < 3) {
        $error = 'Username deve essere almeno 3 caratteri.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $error = 'Username può contenere solo lettere, numeri, punti, trattini e underscore.';
    } elseif (strlen($password) < 6) {
        $error = 'La password deve essere almeno 6 caratteri.';
    } elseif ($password !== $password_confirm) {
        $error = 'Le password non coincidono.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email non valida.';
    } else {
        try {
            $pdo = getPDO();
            
            // Verifica username non già in uso (users o requests)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username già in uso.';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM registration_requests WHERE username = ? AND status = 'pending'");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $error = 'Una richiesta con questo username è già in attesa di approvazione.';
                } else {
                    // Inserisci richiesta
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO registration_requests (username, password_hash, nome, cognome, email, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$username, $passwordHash, $nome, $cognome, $email ?: null]);
                    $success = true;
                }
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = 'Errore durante la registrazione. Riprova più tardi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <?= csrf_meta_tag() ?>
    <?= csrf_bootstrap_script() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione - openLogistic</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/auth.css') ?>">
    <style>
        .register-container {
            max-width: 450px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .success-container {
            text-align: center;
            padding: 40px 20px;
        }
        .success-icon {
            font-size: 64px;
            color: #10b981;
            margin-bottom: 20px;
        }
        .success-title {
            font-size: 24px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 12px;
        }
        .success-text {
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .info-box {
            background: rgba(79, 70, 229, 0.1);
            border: 1px solid rgba(79, 70, 229, 0.3);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .info-box p {
            color: #a5b4fc;
            font-size: 13px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-box i {
            color: #818cf8;
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-container register-container">
            <div class="auth-header">
                <img src="assets/img/openLogistic_banner.png" alt="openLogistic" class="auth-logo">
                <h1>openLogistic</h1>
                <p>Registrazione Nuovo Utente</p>
            </div>
            
            <?php if ($success): ?>
            <div class="success-container">
                <div class="success-icon">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <h2 class="success-title">Richiesta Inviata!</h2>
                <p class="success-text">
                    La tua richiesta di registrazione è stata inviata con successo.<br>
                    Un amministratore la esaminerà a breve.<br><br>
                    Riceverai l'accesso una volta approvata.
                </p>
                <a href="index.php" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
                    <i class="fa-solid fa-arrow-left"></i>
                    Torna al Login
                </a>
            </div>
            <?php else: ?>
            
            <div class="info-box">
                <p>
                    <i class="fa-solid fa-info-circle"></i>
                    La registrazione richiede l'approvazione di un amministratore.
                </p>
            </div>
            
            <?php if ($error): ?>
            <div class="auth-error">
                <i class="fa-solid fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nome">Nome <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-user"></i>
                            <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="cognome">Cognome <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-user"></i>
                            <input type="text" id="cognome" name="cognome" value="<?= htmlspecialchars($_POST['cognome'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="username">Username <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-at"></i>
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username" placeholder="es: mario.rossi">
                    </div>
                    <small style="color: #64748b; font-size: 11px;">Lettere, numeri, punti, trattini</small>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="opzionale">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" id="password" name="password" required minlength="6">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Conferma <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" id="password_confirm" name="password_confirm" required>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-user-plus"></i>
                    Richiedi Registrazione
                </button>
            </form>
            
            <div class="auth-footer">
                <p>Hai già un account? <a href="index.php">Accedi</a></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
