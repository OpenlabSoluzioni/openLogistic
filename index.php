<?php
/**
 * openLogistic v47 - Single Page App
 * Login integrato nella toolbar + Trusted Devices + PIN
 */

session_start();
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/db.php';

// Carica modulo Trusted Devices se esiste
$trustedDevicesEnabled = file_exists(__DIR__ . '/api/trusted_devices.php');
if ($trustedDevicesEnabled) {
    require_once __DIR__ . '/api/trusted_devices.php';
    $trustedDevicesEnabled = isTrustedDevicesEnabled(); // Verifica anche che le tabelle esistano
}

$isLogged = isset($_SESSION['user_id']);
$user = null;
$lastDdt = null;
$showPinSetup = false;
$loginError = false;

// Aggiorna ultimo utilizzo del device fidato corrente, se il cookie è valido
if (!empty($_SESSION['user_id']) && $trustedDevicesEnabled) {
    touchTrustedDeviceLastUsed((int)$_SESSION['user_id']);
}

// ════════════════════════════════════════════════════════════════════════════
// GESTIONE PIN SETUP (dopo primo login)
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_or_die();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup_pin') {
    if (!empty($_SESSION['user_id']) && $trustedDevicesEnabled) {
        $pin = $_POST['pin'] ?? '';
        $pinConfirm = $_POST['pin_confirm'] ?? '';

        if ($pin !== $pinConfirm) {
            $_SESSION['pin_error'] = 'I PIN non coincidono.';
        } elseif (!preg_match('/^\d{4,6}$/', $pin)) {
            $_SESSION['pin_error'] = 'Il PIN deve essere di 4-6 cifre.';
        } else {
            if (setRecoveryPin($_SESSION['user_id'], $pin)) {
                // Registra dispositivo se richiesto
                if (!empty($_POST['trust_device'])) {
                    registerTrustedDevice($_SESSION['user_id']);
                }
                unset($_SESSION['pending_pin_setup'], $_SESSION['pending_trust_device'], $_SESSION['pin_error']);
            } else {
                $_SESSION['pin_error'] = 'Errore nel salvataggio del PIN.';
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Skip PIN setup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'skip_pin') {
    // Registra dispositivo anche senza PIN se era selezionato
    if (!empty($_SESSION['pending_trust_device']) && !empty($_SESSION['user_id']) && $trustedDevicesEnabled) {
        registerTrustedDevice($_SESSION['user_id']);
    }
    unset($_SESSION['pending_pin_setup'], $_SESSION['pending_trust_device'], $_SESSION['pin_error']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// LOGIN
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $trustDevice = !empty($_POST['trust_device']);

    if ($username && $password) {
        try {
            $sql = 'SELECT id, username, password_hash, nome, cognome, role, last_login';
            if ($trustedDevicesEnabled) {
                $sql .= ', recovery_pin_hash';
            }
            $sql .= ' FROM users WHERE username = ?';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if ($row && password_verify($password, $row['password_hash'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['nome'] = $row['nome'] ?? '';
                $_SESSION['cognome'] = $row['cognome'] ?? '';
                $_SESSION['role'] = $row['role'];
                $_SESSION['last_login'] = $row['last_login'];

                // Aggiorna last_login nel database
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);

                // Verifica se deve impostare il PIN (solo se trusted devices è abilitato)
                if ($trustedDevicesEnabled && empty($row['recovery_pin_hash'])) {
                    $_SESSION['pending_pin_setup'] = true;
                    $_SESSION['pending_trust_device'] = $trustDevice;
                } else {
                    // PIN già configurato o trusted devices non abilitato
                    if ($trustDevice && $trustedDevicesEnabled) {
                        registerTrustedDevice($row['id']);
                    }
                }

                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        } catch (Exception $e) {
        }
    }
    // Redirect anche in caso di errore per evitare form resubmission
    $_SESSION['login_error'] = true;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Gestione errore login dalla sessione
$loginError = false;
if (!empty($_SESSION['login_error'])) {
    $loginError = true;
    unset($_SESSION['login_error']);
}

// ════════════════════════════════════════════════════════════════════════════
// LOGOUT
// ════════════════════════════════════════════════════════════════════════════
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// VERIFICA PIN SETUP PENDENTE
// ════════════════════════════════════════════════════════════════════════════
if (!empty($_SESSION['pending_pin_setup']) && !empty($_SESSION['user_id'])) {
    $showPinSetup = true;
}

// ════════════════════════════════════════════════════════════════════════════
// DATI UTENTE E CONTEGGI
// ════════════════════════════════════════════════════════════════════════════
$isLogged = isset($_SESSION['user_id']) && !$showPinSetup;

if (isset($_SESSION['user_id'])) {
    $user = [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'nome' => $_SESSION['nome'] ?? '',
        'cognome' => $_SESSION['cognome'] ?? '',
        'role' => $_SESSION['role'] ?? 'operator',
        'last_login' => $_SESSION['last_login'] ?? null
    ];

    // Recupera ultimo DDT creato dall'utente
    if ($isLogged) {
        try {
            $stmt = $pdo->prepare("SELECT numero, anno, created_at FROM ddt WHERE created_by = ? AND numero IS NOT NULL ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$user['id']]);
            $lastDdt = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
        }
    }
}

// Conteggi DDT per header
// Nuova logica: Magazzino Esterno = DDT conto deposito senza data_rientro_deposito
$ddtCounts = ['bozze' => 0, 'emessi' => 0, 'magazzino_esterno' => 0];
if ($isLogged) {
    try {
        // Recupera causali conto deposito
        $stmtCausali = $pdo->query("SELECT descrizione FROM causali_trasporto WHERE is_conto_deposito = 1");
        $causaliDeposito = $stmtCausali->fetchAll(PDO::FETCH_COLUMN);

        // Conta bozze
        $stmt = $pdo->query("SELECT COUNT(*) FROM ddt WHERE stato = 'bozza'");
        $ddtCounts['bozze'] = (int) $stmt->fetchColumn();

        // Conta magazzino esterno (DDT definitivi conto deposito senza data rientro)
        if (!empty($causaliDeposito)) {
            $placeholders = str_repeat('?,', count($causaliDeposito) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM ddt 
                WHERE stato = 'definitivo' 
                AND causale_trasporto IN ($placeholders)
                AND (data_rientro_deposito IS NULL OR data_rientro_deposito = '')
            ");
            $stmt->execute($causaliDeposito);
            $ddtCounts['magazzino_esterno'] = (int) $stmt->fetchColumn();
        }

        // Conta emessi (DDT definitivi che NON sono in magazzino esterno)
        $stmt = $pdo->query("SELECT COUNT(*) FROM ddt WHERE stato = 'definitivo'");
        $totaleDefinitivi = (int) $stmt->fetchColumn();
        $ddtCounts['emessi'] = $totaleDefinitivi - $ddtCounts['magazzino_esterno'];

    } catch (Exception $e) {
    }
}

// Conta richieste reset pendenti per admin
$pendingResets = 0;
$pendingRegistrations = 0;
if ($isLogged && ($user['role'] ?? '') === 'admin') {
    if ($trustedDevicesEnabled) {
        $pendingResets = countPendingResetRequests();
    }
    // Conta registrazioni pendenti
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM registration_requests WHERE status = 'pending'");
        $pendingRegistrations = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
    }
}
$totalPending = $pendingResets + $pendingRegistrations;

$pinError = $_SESSION['pin_error'] ?? '';
unset($_SESSION['pin_error']);
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <?= csrf_meta_tag() ?>
    <?= csrf_bootstrap_script() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>openLogistic</title>
    <link rel="icon" type="image/png" href="assets/img/openLogistic.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>

<body>

    <div class="app <?= (!$isLogged && !$showPinSetup) ? 'app-login' : '' ?>" id="app">

        <!-- SIDEBAR -->
        <aside class="sidebar <?= (!$isLogged && !$showPinSetup) ? 'sidebar-hidden-login' : '' ?>" id="sidebar">
            <div class="sidebar-header">
                <img src="assets/img/openLogistic_banner.png" alt="openLogistic" class="sidebar-brand">
            </div>

            <?php if ($isLogged): ?>
                <!-- DDT in compilazione (minimizzato) -->
                <div id="minimized-ddt" class="minimized-ddt-sidebar" style="display: none;"
                    title="Clicca per ripristinare il DDT in compilazione">
                    <i class="fa-solid fa-file-invoice"></i>
                    <span class="minimized-label">DDT in corso</span>
                    <i class="fa-solid fa-chevron-right minimized-arrow"></i>
                </div>

                <!-- Pulsante Nuovo DDT sempre visibile -->
                <div class="sidebar-new-ddt">
                    <button class="btn btn-primary btn-new-ddt-full" id="btn-new-sidebar">
                        <i class="fa-solid fa-plus"></i>
                        <span data-i18n="nav.nuovo_ddt">Nuovo DDT</span>
                    </button>
                </div>

                <nav class="sidebar-nav">

                    <!-- Gruppo DDT - BIANCO -->
                    <div class="nav-section nav-section-ddt">
                        <button class="nav-section-toggle" data-section="ddt">
                            <i class="fa-solid fa-file-lines"></i>
                            <span class="nav-section-label" data-i18n="nav.ddt">DDT</span>
                            <i class="fa-solid fa-chevron-down nav-section-arrow"></i>
                        </button>
                        <div class="nav-section-items" data-section-content="ddt">
                            <button class="nav-item nav-item-emessi" data-view="list" data-filter="emessi" title="Emessi">
                                <span class="nav-item-label" data-i18n="nav.emessi">EMESSI</span>
                                <span class="nav-badge" id="count-emessi">0</span>
                            </button>
                            <button class="nav-item" data-view="list" data-filter="bozze" title="Bozze">
                                <span class="nav-item-label" data-i18n="nav.bozze">Bozze</span>
                                <span class="nav-badge" id="count-bozze">0</span>
                            </button>
                        </div>
                    </div>

                    <!-- Magazzino Esterno - ARANCIONE -->
                    <div class="nav-section nav-section-magext">
                        <button class="nav-section-toggle nav-item nav-item-magext" data-view="list"
                            data-filter="magazzino_esterno" title="Magazzino Esterno">
                            <i class="fa-solid fa-truck-ramp-box"></i>
                            <span class="nav-section-label" data-i18n="nav.magazzino_esterno">Magazzino Esterno</span>
                            <span class="nav-badge badge-warning" id="count-magazzino-esterno">0</span>
                        </button>
                    </div>

                    <!-- Registro Attività (click diretto, no sotto-menu) - ARANCIONE -->
                    <div class="nav-section nav-section-attivita">
                        <button class="nav-section-toggle nav-attivita-direct" data-view="attivita"
                            data-attivita-action="list">
                            <i class="fa-solid fa-clipboard-list"></i>
                            <span class="nav-section-label" data-i18n="nav.registro_attivita">Registro Attivit&agrave;</span>
                            <span class="nav-badge" id="count-attivita">0</span>
                        </button>
                    </div><!-- Gruppo Impostazione (solo admin) -->
                    <?php if (($user['role'] ?? '') === 'admin'): ?>
                        <div class="nav-section">
                            <button class="nav-section-toggle" data-section="settings">
                                <i class="fa-solid fa-gear"></i>
                                <span class="nav-section-label" data-i18n="nav.impostazioni">Impostazione</span>
                                <i class="fa-solid fa-chevron-down nav-section-arrow"></i>
                            </button>
                            <div class="nav-section-items collapsed" data-section-content="settings">
                                <button class="nav-item" data-view="admin" data-tab="automatismi" title="Automatismi DDT">
                                    <span class="nav-item-label" data-i18n="nav.automatismi">Automatismi</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="causali" title="Causale">
                                    <span class="nav-item-label" data-i18n="nav.causale">Causale</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="aspetto_beni" title="Aspetto Beni">
                                    <span class="nav-item-label" data-i18n="nav.aspetto_beni">Aspetto Beni</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="porto" title="Porto">
                                    <span class="nav-item-label" data-i18n="nav.porto">Porto</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="tipo_mezzo" title="Tipo Mezzo">
                                    <span class="nav-item-label" data-i18n="nav.tipo_mezzo">Tipo Mezzo</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="utenti" title="Utenti">
                                    <span class="nav-item-label" data-i18n="nav.utente">Utente</span>
                                    <span class="notification-badge <?= $totalPending > 0 ? '' : 'hidden' ?>"
                                        id="badge-total-pending"><?= $totalPending ?></span>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>


                    <!-- Anagrafica (solo admin) -->
                    <?php if (($user['role'] ?? '') === 'admin'): ?>
                        <div class="nav-section">
                            <button class="nav-section-toggle" data-section="anagrafiche">
                                <i class="fa-solid fa-address-book"></i>
                                <span class="nav-section-label" data-i18n="nav.anagrafica">Anagrafica</span>
                                <i class="fa-solid fa-chevron-down nav-section-arrow"></i>
                            </button>
                            <div class="nav-section-items collapsed" data-section-content="anagrafiche">
                                <button class="nav-item" data-view="admin" data-tab="mittenti" title="Mittente">
                                    <span class="nav-item-label" data-i18n="nav.mittente">Mittente</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="anagrafiche" title="Destinatario">
                                    <span class="nav-item-label" data-i18n="nav.destinatario">Destinatario</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="luoghi" title="Luogo">
                                    <span class="nav-item-label" data-i18n="nav.luogo">Luogo</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="vettori_anagrafica" title="Vettore">
                                    <span class="nav-item-label" data-i18n="nav.vettore">Vettore</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="magazzino" title="Materiale">
                                    <span class="nav-item-label" data-i18n="nav.materiale">Materiale</span>
                                </button>
                                <button class="nav-item" data-view="admin" data-tab="servizi" title="Servizio">
                                    <span class="nav-item-label" data-i18n="nav.servizio">Servizio</span>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                </nav>

                <!-- Footer sidebar -->
                <!-- Language Selector -->
                <div class="sidebar-lang">
                    <button class="lang-btn" data-lang="it" title="Italiano">IT</button>
                    <button class="lang-btn" data-lang="en" title="English">EN</button>
                </div>
                
                <div class="sidebar-footer">
                    <div class="sidebar-user">
                        <div class="sidebar-user-avatar">
                            <?= strtoupper(substr($user['nome'] ?: $user['username'], 0, 1)) ?>
                        </div>
                        <div class="sidebar-user-info">
                            <span
                                class="sidebar-user-name"><?= htmlspecialchars($user['nome'] ?: $user['username']) ?></span>
                            <span class="sidebar-user-role" data-i18n="<?= $user['role'] === 'admin' ? 'gen.admin' : 'gen.operatore' ?>"><?= $user['role'] === 'admin' ? 'Admin' : 'Operatore' ?></span>
                        </div>
                    </div>
                    <div class="sidebar-user-actions">
                        <button class="sidebar-user-action" id="btn-help" title="Guida">
                            <i class="fa-solid fa-circle-question"></i>
                        </button>
                        <button class="sidebar-user-action" id="btn-change-password" title="Cambia password" data-i18n="auth.cambia_password" data-i18n-attr="title">
                            <i class="fa-solid fa-key"></i>
                        </button>
                        <a href="?action=logout" class="sidebar-user-action" title="Esci" data-i18n="btn.esci" data-i18n-attr="title">
                            <i class="fa-solid fa-right-from-bracket"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </aside>

        <!-- MAIN -->
        <main class="main">
            <header class="toolbar" id="toolbar">
                <?php if (!$isLogged && !$showPinSetup): ?>
                    <!-- LOGIN nella toolbar -->
                    <div class="toolbar-left"></div>
                    <div class="toolbar-center">
                        <form class="login-inline" method="post" autocomplete="off">
                            <input type="hidden" name="action" value="login">
                            <input type="text" name="username" placeholder="Username" data-i18n="auth.username" data-i18n-attr="placeholder" required
                                class="login-input <?= $loginError ? 'error' : '' ?>" autocomplete="off" data-lpignore="true" data-form-type="other">
                            <input type="password" name="password" placeholder="Password" data-i18n="auth.password" data-i18n-attr="placeholder" required
                                class="login-input <?= $loginError ? 'error' : '' ?>" autocomplete="off" data-lpignore="true" data-form-type="other">
                            <?php if ($trustedDevicesEnabled): ?>
                                <label class="login-checkbox">
                                    <input type="checkbox" name="trust_device" value="1">
                                    <span data-i18n="auth.ricordami">Ricordami</span>
                                </label>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-right-to-bracket"></i>
                                <span data-i18n="btn.accedi">Accedi</span>
                            </button>
                            <div class="login-links">
                                <a href="register.php" class="login-link" title="Registrati">
                                    <i class="fa-solid fa-user-plus"></i>
                                </a>
                                <?php if ($trustedDevicesEnabled): ?>
                                    <a href="password_reset.php" class="login-link" title="Password dimenticata?">
                                        <i class="fa-solid fa-key"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <div class="toolbar-right">
                        <?php if ($loginError): ?>
                            <span class="login-error-msg">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span data-i18n="auth.credenziali_errate">Credenziali errate</span>
                            </span>
                        <?php endif; ?>
                    </div>

                <?php elseif ($isLogged): ?>
                    <!-- TOOLBAR normale -->
                    <div class="toolbar-left">
                        <nav class="breadcrumb" id="breadcrumb">
                            <a href="#" class="breadcrumb-item breadcrumb-home" data-view="list" data-filter="bozze">
                                <i class="fa-solid fa-house"></i>
                            </a>
                            <span class="breadcrumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
                            <span class="breadcrumb-item breadcrumb-current" id="breadcrumb-current">DDT</span>
                        </nav>
                    </div>
                    <div class="toolbar-center">
                        <div class="toolbar-search" id="toolbar-search">
                            <i class="fa-solid fa-search"></i>
                            <!-- Honeypot: assorbe l'autocomplete del browser -->
                            <input type="text" name="fake_user" style="display:none!important" tabindex="-1" aria-hidden="true" autocomplete="username">
                            <input type="search" placeholder="Cerca: numero, destinatario, codice, descrizione..."
                                id="search-input" autocomplete="nope" autocorrect="off" autocapitalize="off"
                                spellcheck="false" data-lpignore="true" data-form-type="other" data-1p-ignore="true"
                                name="q_<?= time() ?>_<?= rand(1000,9999) ?>"
                                data-i18n="toolbar.search" data-i18n-attr="placeholder">
                            <div class="search-scope-toggle">
                                <button class="scope-btn active" data-scope="all" title="Tutti">
                                    <i class="fa-solid fa-globe"></i>
                                </button>
                                <button class="scope-btn" data-scope="mine" title="Solo miei">
                                    <i class="fa-solid fa-user"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <?php if (($user['role'] ?? '') === 'admin'): ?>
                            <!-- Notifica registrazioni pendenti (solo admin) -->
                            <button class="toolbar-notification <?= $pendingRegistrations > 0 ? '' : 'hidden' ?>"
                                id="toolbar-pending-regs" data-view="admin" data-tab="utenti" data-utenti-tab="registrations"
                                title="Richieste di registrazione in attesa">
                                <i class="fa-solid fa-user-plus"></i>
                                <span class="toolbar-notification-badge"
                                    id="badge-pending-regs"><?= $pendingRegistrations ?></span>
                            </button>

                            <!-- Notifica richieste reset pendenti (solo admin) -->
                            <button class="toolbar-notification <?= $pendingResets > 0 ? '' : 'hidden' ?>"
                                id="toolbar-pending-resets" data-view="admin" data-tab="utenti" data-utenti-tab="resets"
                                title="Richieste di reset password in attesa">
                                <i class="fa-solid fa-key"></i>
                                <span class="toolbar-notification-badge" id="badge-pending-resets"><?= $pendingResets ?></span>
                            </button>
                        <?php endif; ?>

                        <button class="btn btn-ghost btn-close-ddt hidden" id="btn-toolbar-close" title="Chiudi DDT">
                            <i class="fa-solid fa-xmark"></i>
                            <span>Chiudi</span>
                        </button>

                        <!-- Area Utente -->
                        <div class="user-info">
                            <div class="user-info-main">
                                <span class="user-welcome">Benvenuto,
                                    <strong><?= htmlspecialchars($user['nome'] && $user['cognome'] ? $user['nome'] . ' ' . $user['cognome'] : $user['username']) ?></strong></span>
                                <span class="user-role <?= $user['role'] === 'admin' ? 'role-admin' : 'role-operator' ?>">
                                    <?= $user['role'] === 'admin' ? 'Amministratore' : 'Operatore' ?>
                                </span>
                            </div>
                            <div class="user-info-details">
                                <?php if ($user['last_login']): ?>
                                    <span class="user-detail" title="Ultimo accesso">
                                        <i class="fa-solid fa-clock"></i>
                                        <?= date('d/m/Y H:i', strtotime($user['last_login'])) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($lastDdt): ?>
                                    <span class="user-detail" title="Ultimo DDT creato">
                                        <i class="fa-solid fa-file-lines"></i>
                                        <?= $lastDdt['numero'] ?>/<?= $lastDdt['anno'] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <a href="?action=logout" class="btn btn-ghost btn-logout" title="Esci">
                            <i class="fa-solid fa-right-from-bracket"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </header>

            <!-- CONTENT -->
            <div class="content" id="content">
                <?php if (!$isLogged && !$showPinSetup): ?>
                    <!-- Welcome screen -->
                    <div class="welcome-screen">
                        <div class="welcome-content">
                            <img src="assets/img/openLogistic_banner.png" alt="openLogistic" class="welcome-logo">
                            <h2 data-i18n="auth.gestione_ddt">Gestione Documenti di Trasporto</h2>
                            <p data-i18n="auth.inserisci_credenziali">Inserisci le credenziali nella barra superiore per accedere</p>
                        </div>
                    </div>

                <?php elseif ($isLogged): ?>
                    <!-- PANEL: Lista DDT -->
                    <div class="panel panel-list" id="panel-list">
                        <table class="data-table" id="ddt-table">
                            <thead>
                                <tr>
                                    <th class="col-check"><input type="checkbox" id="select-all"></th>
                                    <th class="col-sito" data-i18n="list.sito">Sito</th>
                                    <th class="col-rif" data-i18n="list.riferimento">Riferimento</th>
                                    <th class="col-dest" data-i18n="list.destinatario">Destinatario</th>
                                    <th class="col-vettore" data-i18n="list.vettore">Vettore</th>
                                    <th class="col-date" data-i18n="list.data">Data</th>
                                    <th class="col-creator" data-i18n="list.creato_da">Creato da</th>
                                    <th class="col-status" data-i18n="list.stato">Stato</th>
                                    <th class="col-actions"></th>
                                </tr>
                            </thead>
                            <tbody id="ddt-tbody"></tbody>
                        </table>

                        <div class="list-empty hidden" id="list-empty">
                            <i class="fa-solid fa-inbox"></i>
                            <p>Nessun documento trovato</p>
                        </div>

                        <div class="bulk-bar hidden" id="bulk-bar">
                            <span><strong id="bulk-count">0</strong> selezionati</span>
                            <button class="btn btn-sm btn-ghost" id="bulk-deselect">Deseleziona</button>
                            <button class="btn btn-sm btn-danger" id="bulk-delete">Elimina</button>
                        </div>
                    </div>

                    <!-- PANEL: Dettaglio DDT -->
                    <div class="panel panel-detail hidden" id="panel-detail"></div>

                    <!-- PANEL: Admin -->
                    <div class="panel panel-admin hidden" id="panel-admin"></div>

                    <!-- PANEL: Registro Attività -->
                    <div class="panel panel-attivita hidden" id="panel-attivita"></div>

                    <!-- PANEL: Stampa (overlay) -->
                    <div class="panel panel-print hidden" id="panel-print"></div>
                <?php endif; ?>
            </div>

        </main>

    </div>

    <?php if ($showPinSetup): ?>
        <!-- ══════════════════════════════════════════════════════════════════════════
     PIN SETUP OVERLAY
     ══════════════════════════════════════════════════════════════════════════ -->
        <div class="pin-overlay">
            <div class="pin-card">
                <div class="pin-header">
                    <div class="pin-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h2 class="pin-title">Configura PIN di sicurezza</h2>
                    <p class="pin-subtitle">
                        Imposta un PIN di 4-6 cifre per recuperare la password in futuro senza contattare l'amministratore.
                    </p>
                </div>

                <?php if ($pinError): ?>
                    <div class="pin-error">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?= htmlspecialchars($pinError) ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="setup_pin">

                    <div class="pin-form-group">
                        <label class="pin-label">PIN</label>
                        <input type="password" name="pin" class="pin-input" maxlength="6" pattern="\d{4,6}"
                            inputmode="numeric" autocomplete="off" required autofocus>
                        <div class="pin-hint">4-6 cifre numeriche</div>
                    </div>

                    <div class="pin-form-group">
                        <label class="pin-label">Conferma PIN</label>
                        <input type="password" name="pin_confirm" class="pin-input" maxlength="6" pattern="\d{4,6}"
                            inputmode="numeric" autocomplete="off" required>
                    </div>

                    <label class="pin-checkbox">
                        <input type="checkbox" name="trust_device" value="1" <?= !empty($_SESSION['pending_trust_device']) ? 'checked' : '' ?>>
                        <span>Ricorda questo dispositivo</span>
                    </label>

                    <div class="pin-buttons">
                        <button type="submit" name="action" value="skip_pin" class="btn btn-skip" formnovalidate>
                            Salta
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-check"></i>
                            Salva PIN
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="toast-container" id="toast-container"></div>

    <?php if ($isLogged): ?>
        <script>
            window.APP_USER = <?= json_encode($user) ?>;
        </script>
        <!-- PDF libs loaded on demand by app.js -->
        <script>
            window._loadPdfLibs = async function () {
                if (window._pdfLibsLoaded) return;
                await Promise.all([
                    new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = '<?= asset_url('assets/js/lib/html2canvas.min.js') ?>';
                        s.onload = resolve; s.onerror = reject;
                        document.head.appendChild(s);
                    }),
                    new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = '<?= asset_url('assets/js/lib/jspdf.umd.min.js') ?>';
                        s.onload = resolve; s.onerror = reject;
                        document.head.appendChild(s);
                    })
                ]);
                window._pdfLibsLoaded = true;
            };
        </script>
        <script src="<?= asset_url('assets/js/i18n.js') ?>"></script>
        <script src="<?= asset_url('assets/js/app.js') ?>" type="module"></script>
    <?php endif; ?>

    <?php if ($showPinSetup): ?>
        <script src="<?= asset_url('assets/js/pin-utils.js') ?>"></script>
    <?php endif; ?>

    <!-- HELP OVERLAY -->
    <div id="help-overlay" class="help-overlay hidden">
        <div class="help-panel">
            <div class="help-header">
                <h2 id="help-title"></h2>
                <button class="help-close" id="help-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="help-nav" id="help-nav"></div>
            <div class="help-body" id="help-body"></div>
            <div class="help-footer">
                <span class="help-version">openLogistic v60</span>
            </div>
        </div>
    </div>

</body>

</html>