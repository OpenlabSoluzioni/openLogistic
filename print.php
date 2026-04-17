<?php
/**
 * DDT Print View - Professional A4 format
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/api/db.php';

$id = (int)($_GET['id'] ?? 0);
$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

// Load DDT
$stmt = $pdo->prepare("SELECT * FROM ddt WHERE id = ?");
$stmt->execute([$id]);
$ddt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ddt) {
    die('DDT non trovato');
}

// Load rows
$stmt = $pdo->prepare("SELECT * FROM ddt_righe WHERE ddt_id = ? ORDER BY riga_n, id");
$stmt->execute([$id]);
$righe = $stmt->fetchAll(PDO::FETCH_ASSOC);

$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

// Carica anagrafica completa del vettore (se presente)
$vettoreAnag = null;
if (!empty($ddt['trasportatore'])) {
    // Cerca tra le anagrafiche tipo vettore
    $stmtV = $pdo->prepare("SELECT * FROM anagrafiche WHERE tipo = 'vettore' AND LOWER(nome) = LOWER(?) LIMIT 1");
    $stmtV->execute([$ddt['trasportatore']]);
    $vettoreAnag = $stmtV->fetch(PDO::FETCH_ASSOC);
    
    // Fallback: cerca per nome tra tutti i tipi (vettori salvati con tipo troncato dall'ENUM)
    if (!$vettoreAnag) {
        $stmtV = $pdo->prepare("SELECT * FROM anagrafiche WHERE LOWER(nome) = LOWER(?) LIMIT 1");
        $stmtV->execute([$ddt['trasportatore']]);
        $vettoreAnag = $stmtV->fetch(PDO::FETCH_ASSOC);
    }
}

// Stato documento
$isBozza = ($ddt['stato'] !== 'definitivo');

// Nome creatore DDT
$creatoreNome = '';
if (!empty($ddt['created_by'])) {
    try {
        $stmtU = $pdo->prepare("SELECT nome, cognome, username FROM users WHERE id = ?");
        $stmtU->execute([$ddt['created_by']]);
        $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($uRow) {
            $parts = array_filter([trim($uRow['nome'] ?? ''), trim($uRow['cognome'] ?? '')]);
            $creatoreNome = $parts ? implode(' ', $parts) : $uRow['username'];
        }
    } catch (Exception $e) {}
} elseif (!empty($ddt['creato_da'])) {
    $creatoreNome = $ddt['creato_da'];
}
$isAnnullato = !empty($ddt['annullato']);

// Format date
$dataDoc = $ddt['data'] ? date('d/m/Y', strtotime($ddt['data'])) : date('d/m/Y');
$oraDoc = $ddt['ora_emissione'] ? substr($ddt['ora_emissione'], 0, 5) : '';

// Luogo destinazione - se uguale a destinatario
$luogoNome = $ddt['luogo_destinazione'] ?: $ddt['destinatario'];
$luogoAddr = $ddt['luogo_addr'] ?: $ddt['destinatario_addr'];
$luogoCap = $ddt['luogo_cap'] ?: $ddt['destinatario_cap'];
$luogoCitta = $ddt['luogo_citta'] ?: $ddt['destinatario_citta'];
$luogoProv = $ddt['luogo_provincia'] ?: $ddt['destinatario_provincia'];
$luogoNaz = $ddt['luogo_nazione'] ?: $ddt['destinatario_nazione'] ?: 'IT';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>DDT <?= $h($ddt['numero']) ?>/<?= $h($ddt['anno']) ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        @page {
            size: A4;
            margin: 10mm;
        }
        
        body {
            font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #1a1a1a;
            background: <?= $isEmbed ? 'white' : '#f0f0f0' ?>;
        }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: <?= $isEmbed ? '0 auto' : '10mm auto' ?>;
            padding: 8mm;
            background: white;
            box-shadow: <?= $isEmbed ? 'none' : '0 2px 10px rgba(0,0,0,0.1)' ?>;
            display: flex;
            flex-direction: column;
        }
        
        .document-body {
            flex: 1;
        }
        
        .document-footer {
            margin-top: auto;
        }
        
        /* ══════════════════════════════════════════════════════════════
           HEADER
           ══════════════════════════════════════════════════════════════ */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 5mm;
            border-bottom: 0.5mm solid #374151;
            margin-bottom: 4mm;
        }
        
        .header-left {
            flex: 1;
        }
        
        .company-logo {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: -0.02em;
            margin-bottom: 2mm;
        }
        
        .company-logo img {
            max-height: 15mm;
            max-width: 50mm;
            object-fit: contain;
        }
        
        .company-name {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 1mm;
        }
        
        .company-info {
            font-size: 11px;
            color: #666;
            line-height: 1.5;
        }
        
        .sede-legale-box {
            margin-top: 1mm;
            margin-bottom: 3mm;
            padding: 1.5mm 2mm;
            border: 0.3mm solid #ddd;
            border-radius: 1mm;
            background: #fafafa;
            width: 100%;
            box-sizing: border-box;
        }
        
        .sede-legale-content {
            font-size: 9.5px;
            color: #444;
            line-height: 1.5;
        }
        
        .sede-legale-row {
            display: block;
        }
        
        .header-right {
            text-align: right;
        }
        
        .doc-title {
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #1a1a1a;
            margin-bottom: 1mm;
        }
        
        .doc-subtitle {
            font-size: 10px;
            color: #888;
            margin-bottom: 3mm;
        }
        
        .doc-number {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a1a;
        }
        
        .doc-number-small {
            font-size: 10px;
            font-weight: 500;
            color: #666;
            margin-top: 1mm;
        }
        
        .doc-date {
            font-size: 13px;
            color: #444;
            margin-top: 1mm;
        }
        
        /* ══════════════════════════════════════════════════════════════
           ADDRESSES
           ══════════════════════════════════════════════════════════════ */
        .addresses {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 3mm;
            margin-bottom: 4mm;
        }
        
        .address-box {
            border: 0.3mm solid #ddd;
            border-radius: 2mm;
            padding: 3mm;
            background: #fafafa;
        }
        
        .address-box h3 {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #1a1a1a;
            margin-bottom: 2mm;
            padding-bottom: 1mm;
            border-bottom: 0.2mm solid #e0e0e0;
        }
        
        .address-box .name {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 1mm;
        }
        
        .address-box .line {
            font-size: 11px;
            color: #444;
            line-height: 1.4;
        }
        
        /* ══════════════════════════════════════════════════════════════
           INFO GRID - Causale su riga intera, poi dettagli
           ══════════════════════════════════════════════════════════════ */
        .causale-box {
            margin-bottom: 3mm;
            background: #f8fafc;
            border: 0.3mm solid #e2e8f0;
            border-radius: 2mm;
            padding: 2mm 3mm;
        }
        
        .causale-box label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }
        
        .causale-box span {
            font-size: 12px;
            font-weight: 600;
            color: #1a1a1a;
            margin-left: 2mm;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 2mm;
            margin-bottom: 4mm;
            background: #f8fafc;
            border: 0.3mm solid #e2e8f0;
            border-radius: 2mm;
            padding: 3mm;
        }
        
        .info-item {
            padding: 2mm;
        }
        
        .info-item label {
            display: block;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 1mm;
        }
        
        .info-item span {
            display: block;
            font-size: 10px;
            font-weight: 500;
            color: #1a1a1a;
        }
        
        /* ══════════════════════════════════════════════════════════════
           MATERIALS TABLE
           ══════════════════════════════════════════════════════════════ */
        .materials {
            margin-bottom: 4mm;
        }
        
        .materials h2 {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #333;
            margin-bottom: 0;
            padding: 2mm 2.5mm;
            border: 1px solid #999;
            border-bottom: none;
            background: #f5f5f5;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            border: 1px solid #999;
        }
        
        thead {
            background: #fff;
        }
        
        th {
            text-align: left;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #333;
            padding: 2mm 2mm;
            border-bottom: 1.5px solid #666;
            border-right: 0.5px solid #ddd;
        }
        
        th:last-child {
            border-right: none;
        }
        
        th:first-child {
            border-radius: 0;
        }
        
        th:last-child {
            border-radius: 0;
        }
        
        td {
            padding: 1.8mm 2mm;
            border-bottom: 0.3px solid #ddd;
            border-right: 0.3px solid #eee;
            vertical-align: middle;
        }
        
        td:last-child {
            border-right: none;
        }
        
        tr:nth-child(even) {
            background: #fafafa;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        .col-num {
            text-align: center;
            width: 4%;
            color: #888;
            font-size: 9px;
        }
        
        .col-codice {
            width: 12%;
            font-family: 'SF Mono', 'Consolas', monospace;
            font-size: 9.5px;
            font-weight: 600;
        }
        
        .col-desc {
            width: 24%;
        }
        
        .col-um {
            text-align: center;
            width: 6%;
            font-size: 9.5px;
        }
        
        .col-numero {
            text-align: center;
            width: 8%;
        }
        
        .col-qta {
            text-align: right;
            width: 7%;
            font-family: 'SF Mono', 'Consolas', monospace;
            font-weight: 600;
        }
        
        .col-parziale {
            text-align: right;
            width: 7%;
            font-family: 'SF Mono', 'Consolas', monospace;
            font-weight: 600;
        }
        
        .col-qta-lettere {
            text-align: center;
            width: 7%;
            font-family: 'SF Mono', 'Consolas', monospace;
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        .col-contr {
            text-align: center;
            width: 7%;
        }
        
        .col-rientro {
            text-align: center;
            width: 6%;
        }
        
        .col-data-rientro {
            text-align: center;
            width: 10%;
            font-size: 10px;
        }
        
        .col-note {
            width: 9%;
            font-size: 10px;
            color: #666;
        }
        
        .table-wrapper {
            overflow: hidden;
        }
        
        .empty-row {
            text-align: center;
            color: #999;
            padding: 8mm !important;
            font-style: italic;
            font-size: 9px;
        }
        
        /* ══════════════════════════════════════════════════════════════
           NOTES
           ══════════════════════════════════════════════════════════════ */
        .notes {
            margin-bottom: 4mm;
            padding: 3mm;
            background: #fffbeb;
            border: 0.3mm solid #fcd34d;
            border-radius: 2mm;
        }
        
        .notes h3 {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #b45309;
            margin-bottom: 2mm;
        }
        
        .notes p {
            font-size: 11px;
            color: #78350f;
            white-space: pre-wrap;
        }
        
        /* ══════════════════════════════════════════════════════════════
           FOOTER - Signatures
           ══════════════════════════════════════════════════════════════ */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 5mm;
            margin-top: 4mm;
            margin-bottom: 3mm;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-box .sig-line {
            border-bottom: 0.3mm solid #1a1a1a;
            height: 10mm;
            margin-bottom: 2mm;
        }
        
        .signature-box label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #666;
        }
        
        /* ══════════════════════════════════════════════════════════════
           LEGAL FOOTER - Disclaimer
           ══════════════════════════════════════════════════════════════ */
        .legal-footer {
            padding: 2mm 0;
            border-top: 0.3mm solid #e5e7eb;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
            line-height: 1.5;
        }
        
        .legal-footer strong {
            color: #6b7280;
        }
        
        /* ══════════════════════════════════════════════════════════════
           CONVERSION TABLE
           ══════════════════════════════════════════════════════════════ */
        .conversion-table {
            margin-top: 4mm;
            margin-bottom: 4mm;
            padding: 2mm 4mm;
            border: 0.3mm solid #e5e7eb;
            border-radius: 1mm;
            font-size: 8px;
            text-align: center;
            background: #fafafa;
            color: #666;
        }
        
        .conversion-table strong {
            color: #444;
        }
        
        /* ══════════════════════════════════════════════════════════════
           BOZZA WATERMARK
           ══════════════════════════════════════════════════════════════ */
        .bozza-watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 120px;
            font-weight: 900;
            color: rgba(100, 100, 100, 0.15);
            text-transform: uppercase;
            letter-spacing: 20px;
            pointer-events: none;
            z-index: 100;
            white-space: nowrap;
        }
        
        .annullato-watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            font-weight: 900;
            color: rgba(220, 38, 38, 0.18);
            text-transform: uppercase;
            letter-spacing: 15px;
            pointer-events: none;
            z-index: 100;
            white-space: nowrap;
        }
        
        .bozza-banner {
            background: linear-gradient(135deg, #f9fafb 0%, #e5e7eb 100%);
            border: 2px solid #374151;
            border-radius: 2mm;
            padding: 3mm;
            margin-bottom: 4mm;
            text-align: center;
        }
        
        .bozza-banner strong {
            color: #1f2937;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 3px;
        }
        
        .bozza-banner p {
            color: #4b5563;
            font-size: 9px;
            margin-top: 1mm;
        }
        
        /* ══════════════════════════════════════════════════════════════
           PRINT STYLES
           ══════════════════════════════════════════════════════════════ */
        @media print {
            body { 
                background: white; 
            }
            .page { 
                margin: 0;
                padding: 8mm;
                box-shadow: none;
                width: 100%;
                min-height: 277mm; /* Altezza A4 meno margini */
                display: flex;
                flex-direction: column;
            }
            .document-body {
                flex: 1;
            }
            .document-footer {
                margin-top: auto;
                page-break-inside: avoid;
            }
            .signatures {
                page-break-inside: avoid;
            }
            .legal-footer {
                page-break-inside: avoid;
            }
            .no-print { 
                display: none !important; 
            }
        }
        
        /* ══════════════════════════════════════════════════════════════
           PRINT BUTTON
           ══════════════════════════════════════════════════════════════ */
        .toolbar {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 1000;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
        }
        
        .btn-success:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-1px);
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="page">
        <?php if ($isAnnullato): ?>
        <!-- ANNULLATO WATERMARK -->
        <div class="annullato-watermark">ANNULLATO</div>
        <?php elseif ($isBozza): ?>
        <!-- BOZZA WATERMARK -->
        <div class="bozza-watermark">BOZZA</div>
        <div class="bozza-banner">
            <strong>&#9888; DOCUMENTO NON VALIDO - BOZZA &#9888;</strong>
            <p>Questo documento è in stato di bozza e non ha valore fiscale. Non può essere utilizzato per il trasporto.</p>
        </div>
        <?php endif; ?>
        
        <!-- HEADER -->
        <header class="header">
            <div class="header-left">
                <div class="company-logo">
                    <?php 
                    // Cerca logo in assets/img/logo/ (supporta png, jpg, jpeg)
                    $logoPath = '';
                    $logoDir = __DIR__ . '/assets/img/logo/';
                    $logoBaseUrl = 'assets/img/logo/';
                    
                    // Debug: verifica se la directory esiste
                    if (is_dir($logoDir)) {
                        foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'Logo.png', 'Logo.jpg', 'Logo.jpeg'] as $logoFile) {
                            if (file_exists($logoDir . $logoFile)) {
                                $logoPath = $logoBaseUrl . $logoFile;
                                break;
                            }
                        }
                    }
                    
                    if ($logoPath): ?>
                        <img src="<?= $logoPath ?>" alt="Logo" onerror="this.style.display='none'">
                    <?php endif; ?>
                </div>
                <div class="company-name"><?= $h($ddt['mittente_nome']) ?: '' ?></div>
                <div class="company-info">
                    <?php if ($ddt['mittente_addr']): ?>
                    <?= $h($ddt['mittente_addr']) ?><br>
                    <?php endif; ?>
                    <?= $h($ddt['mittente_cap']) ?> <?= $h($ddt['mittente_citta']) ?>
                    <?php if ($ddt['mittente_provincia']): ?>(<?= $h($ddt['mittente_provincia']) ?>)<?php endif; ?>
                    <?php if ($ddt['mittente_nazione'] && $ddt['mittente_nazione'] !== 'IT'): ?> - <?= $h($ddt['mittente_nazione']) ?><?php endif; ?>
                </div>
                <?php 
                // Sede legale extended info - rendered after header
                $hasSedeLegale = !empty($ddt['mittente_sede_legale_addr']) || !empty($ddt['mittente_sede_legale_cap']) ||
                                 !empty($ddt['mittente_telefono']) || !empty($ddt['mittente_fax']) || 
                                 !empty($ddt['mittente_capitale_sociale']) || !empty($ddt['mittente_registro_imprese']) || 
                                 !empty($ddt['mittente_piva']) || !empty($ddt['mittente_cf']);
                ?>
            </div>
            <div class="header-right">
                <div class="doc-title">Documento di Trasporto</div>
                <div class="doc-subtitle">Art. 1 D.P.R. 472/96</div>
                <?php 
                $isVenditaHeader = stripos($ddt['causale_trasporto'] ?? '', 'vendita') !== false;
                if ($isVenditaHeader && !empty($ddt['rif_dn'])): 
                ?>
                <div class="doc-number"><?= $h($ddt['rif_dn']) ?></div>
                <div class="doc-number-small">DDT N. <?= $h($ddt['numero']) ?>/<?= $h($ddt['anno']) ?></div>
                <?php else: ?>
                <div class="doc-number">N. <?= $h($ddt['numero']) ?>/<?= $h($ddt['anno']) ?></div>
                <?php endif; ?>
                <div class="doc-date">
                    Data: <?= $dataDoc ?>
                    <?php if ($oraDoc): ?> - Ora: <?= $oraDoc ?><?php endif; ?>
                </div>
            </div>
        </header>
        <?php if ($hasSedeLegale): ?>
        <div class="sede-legale-box">
            <div class="sede-legale-content">
            <?php
            // Riga 1: Sede Legale indirizzo + CAP + Città (Prov) + Tel/Fax
            $riga1Parts = [];
            $sedeAddr = [];
            if (!empty($ddt['mittente_sede_legale_addr'])) $sedeAddr[] = $h($ddt['mittente_sede_legale_addr']);
            $sedeLoc = array_filter([
                $ddt['mittente_sede_legale_cap'] ?? '',
                $ddt['mittente_sede_legale_citta'] ?? '',
                !empty($ddt['mittente_sede_legale_provincia']) ? '(' . $h($ddt['mittente_sede_legale_provincia']) . ')' : ''
            ]);
            if (!empty($sedeLoc)) $sedeAddr[] = implode(' ', $sedeLoc);
            if (!empty($sedeAddr)) $riga1Parts[] = '<strong>Sede Legale:</strong> ' . implode(' - ', $sedeAddr);
            if (!empty($ddt['mittente_telefono'])) $riga1Parts[] = 'Tel: ' . $h($ddt['mittente_telefono']);
            if (!empty($ddt['mittente_fax'])) $riga1Parts[] = 'Fax: ' . $h($ddt['mittente_fax']);
            if (!empty($riga1Parts)): ?>
            <span class="sede-legale-row"><?= implode(' | ', $riga1Parts) ?></span>
            <?php endif; ?>
            <?php
            // Riga 2: P.IVA + C.F. + Capitale Sociale + Registro Imprese
            $riga2 = [];
            if (!empty($ddt['mittente_piva'])) $riga2[] = 'P.IVA: ' . $h($ddt['mittente_piva']);
            if (!empty($ddt['mittente_cf'])) $riga2[] = 'C.F.: ' . $h($ddt['mittente_cf']);
            if (!empty($ddt['mittente_capitale_sociale'])) $riga2[] = $h($ddt['mittente_capitale_sociale']);
            if (!empty($ddt['mittente_registro_imprese'])) $riga2[] = $h($ddt['mittente_registro_imprese']);
            if (!empty($riga2)): ?>
            <span class="sede-legale-row"><?= implode(' | ', $riga2) ?></span>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="document-body">
        
        <!-- ADDRESSES -->
        <div class="addresses">
            <div class="address-box">
                <h3>Destinatario</h3>
                <div class="name"><?= $h($ddt['destinatario']) ?></div>
                <div class="line"><?= $h($ddt['destinatario_addr']) ?></div>
                <div class="line">
                    <?= $h($ddt['destinatario_cap']) ?> <?= $h($ddt['destinatario_citta']) ?>
                    <?php if ($ddt['destinatario_provincia']): ?>(<?= $h($ddt['destinatario_provincia']) ?>)<?php endif; ?>
                </div>
                <?php if ($ddt['destinatario_nazione'] && $ddt['destinatario_nazione'] !== 'IT'): ?>
                <div class="line"><?= $h($ddt['destinatario_nazione']) ?></div>
                <?php endif; ?>
            </div>
            
            <div class="address-box">
                <h3>Luogo di Destinazione</h3>
                <div class="name"><?= $h($luogoNome) ?></div>
                <div class="line"><?= $h($luogoAddr) ?></div>
                <div class="line">
                    <?= $h($luogoCap) ?> <?= $h($luogoCitta) ?>
                    <?php if ($luogoProv): ?>(<?= $h($luogoProv) ?>)<?php endif; ?>
                </div>
                <?php if ($luogoNaz && $luogoNaz !== 'IT'): ?>
                <div class="line"><?= $h($luogoNaz) ?></div>
                <?php endif; ?>
            </div>
            
            <div class="address-box">
                <?php $targaStampa = ''; ?>
                <?php if ($ddt['trasportatore']): ?>
                <?php 
                // Dati indirizzo: preferisci dal DDT, fallback dall'anagrafica
                $vAddr = !empty($ddt['vettore_addr']) ? $ddt['vettore_addr'] : ($vettoreAnag['indirizzo'] ?? '');
                $vCap = !empty($ddt['vettore_cap']) ? $ddt['vettore_cap'] : ($vettoreAnag['cap'] ?? '');
                $vCitta = !empty($ddt['vettore_citta']) ? $ddt['vettore_citta'] : ($vettoreAnag['citta'] ?? '');
                $vProv = !empty($ddt['vettore_provincia']) ? $ddt['vettore_provincia'] : ($vettoreAnag['provincia'] ?? '');
                $vNaz = $ddt['vettore_nazione'] ?? ($vettoreAnag['nazione'] ?? 'IT');
                $targaStampa = !empty($ddt['targa'] ?? '') ? $ddt['targa'] : ($vettoreAnag ? ($vettoreAnag['targa'] ?? '') : '');
                ?>
                <h3>Vettore<?php if (!empty($targaStampa)): ?>: Targa <?= $h($targaStampa) ?><?php endif; ?></h3>
                <div class="name"><?= $h($ddt['trasportatore']) ?></div>
                <?php if (!empty($vAddr)): ?>
                <div class="line"><?= $h($vAddr) ?></div>
                <?php endif; ?>
                <?php 
                $vettLoc = array_filter([$vNaz, $vCap, $vCitta, !empty($vProv) ? '(' . $h($vProv) . ')' : '']);
                if (!empty($vettLoc)): ?>
                <div class="line"><?= implode(' ', $vettLoc) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <h3>Vettore</h3>
                <div class="line" style="color: #888; font-style: italic;">Mittente</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- INFO: CAUSALE su riga separata -->
        <?php 
        $isVendita = stripos($ddt['causale_trasporto'] ?? '', 'vendita') !== false;
        ?>
        <div class="causale-box">
            <label>Causale Trasporto:</label>
            <span><?= $h($ddt['causale_trasporto']) ?: '-' ?></span>
        </div>
        
        <!-- INFO GRID: Dettagli trasporto -->
        <div class="info-grid">
            <?php if ($isVendita && !empty($ddt['sales_order'])): ?>
            <div class="info-item">
                <label>Sales Order</label>
                <span><?= $h($ddt['sales_order']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($isVendita && !empty($ddt['rif_dn'])): ?>
            <div class="info-item">
                <label>Rif. interno DN</label>
                <span><?= $h($ddt['rif_dn']) ?></span>
            </div>
            <?php endif; ?>
            <div class="info-item">
                <label>Aspetto Beni</label>
                <span><?= $h($ddt['aspetto']) ?: '-' ?></span>
            </div>
            <div class="info-item">
                <label>N. Colli</label>
                <span><?= $h($ddt['ncolli']) ?: '-' ?></span>
            </div>
            <div class="info-item">
                <label>Peso Lordo (KG)</label>
                <span><?= !empty($ddt['kglordo']) && is_numeric($ddt['kglordo']) ? number_format((float)$ddt['kglordo'], 0, ',', '.') : ($h($ddt['kglordo']) ?: '-') ?></span>
            </div>
            <div class="info-item">
                <label>Peso Netto (KG)</label>
                <span><?= !empty($ddt['kgnetto']) && is_numeric($ddt['kgnetto']) ? number_format((float)$ddt['kgnetto'], 0, ',', '.') : ($h($ddt['kgnetto']) ?: '-') ?></span>
            </div>
            <div class="info-item">
                <label>Porto</label>
                <span><?= $h($ddt['porto']) ?: '-' ?></span>
            </div>
        </div>
        
        <!-- MATERIALS TABLE -->
        <div class="materials">
            <h2>Beni Trasportati</h2>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th class="col-num">N.</th>
                            <th class="col-codice">Codice</th>
                            <th class="col-desc">Descrizione</th>
                            <th class="col-um">U.M.</th>
                            <th class="col-numero">Numero</th>
                            <th class="col-qta">Qtà</th>
                            <th class="col-parziale">Parz.</th>
                            <th class="col-qta-lettere">Q/tà Lett.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($righe)): ?>
                        <tr>
                            <td colspan="8" class="empty-row">Nessun materiale inserito</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($righe as $i => $r): 
                            // Format data rientro as dd-mm-yyyy
                            $dataRientroFormatted = '';
                            if (!empty($r['data_rientro'])) {
                                $dt = new DateTime($r['data_rientro']);
                                $dataRientroFormatted = $dt->format('d-m-Y');
                            }
                            // Format numeri in italiano (senza decimali, migliaia con punto)
                            $qtaFormatted = is_numeric($r['qta']) ? number_format((float)$r['qta'], 0, ',', '.') : $r['qta'];
                            $parzialeFormatted = !empty($r['parziale']) && is_numeric($r['parziale']) ? number_format((float)$r['parziale'], 0, ',', '.') : ($r['parziale'] ?? '');
                        ?>
                        <tr>
                            <td class="col-num"><?= $i + 1 ?></td>
                            <td class="col-codice"><?= $h($r['codice']) ?></td>
                            <td class="col-desc" style="font-weight:700;"><?= $h($r['descrizione']) ?></td>
                            <td class="col-um"><?= $h($r['um']) ?></td>
                            <td class="col-numero"><?= $h($r['numero'] ?? '') ?></td>
                            <td class="col-qta"><?= $qtaFormatted ?></td>
                            <td class="col-parziale"><?= $parzialeFormatted ?></td>
                            <td class="col-qta-lettere" style="text-align:center;font-weight:600;letter-spacing:1px;"><?= $h($r['qta_lettere'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- NOTES -->
        <?php if ($ddt['note']): ?>
        <div class="notes">
            <h3><i class="fa-solid fa-note-sticky"></i> Note</h3>
            <p><?= $h($ddt['note']) ?></p>
        </div>
        <?php endif; ?>
        
        <!-- CONVERSION TABLE (dopo beni trasportati) -->
        <div class="conversion-table">
            <strong>Tabella Conversione Q/tà in Lettere:</strong>
            1=A &nbsp; 2=E &nbsp; 3=G &nbsp; 4=H &nbsp; 5=M &nbsp; 6=P &nbsp; 7=S &nbsp; 8=T &nbsp; 9=K &nbsp; 0=0
        </div>
        
        </div><!-- fine document-body -->
        
        <!-- DOCUMENT FOOTER: Firme + Disclaimer -->
        <div class="document-footer">
            <!-- SIGNATURES (solo per documenti definitivi) -->
            <?php if (!$isBozza): ?>
            <div class="signatures">
                <div class="signature-box">
                    <div class="sig-line"></div>
                    <label>Firma Mittente</label>
                    <?php if ($creatoreNome): ?>
                    <span style="font-size:10px;color:#444;display:block;margin-top:1mm">(<?= $h($creatoreNome) ?>)</span>
                    <?php endif; ?>
                </div>
                <div class="signature-box">
                    <div class="sig-line"></div>
                    <label>Firma Vettore</label>
                </div>
                <div class="signature-box">
                    <div class="sig-line"></div>
                    <label>Firma Destinatario</label>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- LEGAL FOOTER -->
            <div class="legal-footer">
                <strong>Documento di Trasporto</strong> emesso ai sensi dell'Art. 1 del D.P.R. 14 agosto 1996, n. 472 - I beni viaggiano a rischio e pericolo del destinatario.<br>
                Il presente documento non costituisce fattura. La merce si intende accettata se non contestata per iscritto entro 8 giorni dal ricevimento.
            </div>
        </div>
    </div>
    
    <!-- TOOLBAR -->
    <?php if (!$isEmbed): ?>
    <div class="toolbar no-print">
        <button class="btn btn-secondary" onclick="window.history.back()">
            <i class="fa-solid fa-arrow-left"></i> Indietro
        </button>
        <div class="font-size-control" style="display:inline-flex;align-items:center;gap:8px;background:#f9fafb;padding:6px 14px;border-radius:6px;border:1px solid #d1d5db;">
            <label style="font-size:13px;font-weight:600;color:#666;"><i class="fa-solid fa-text-height"></i></label>
            <button class="btn-font" onclick="changeFontSize(-1)" title="Riduci" style="width:28px;height:28px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;">
                <i class="fa-solid fa-minus"></i>
            </button>
            <span id="font-size-label" style="font-size:13px;font-weight:700;min-width:34px;text-align:center;">12px</span>
            <button class="btn-font" onclick="changeFontSize(1)" title="Aumenta" style="width:28px;height:28px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;">
                <i class="fa-solid fa-plus"></i>
            </button>
            <select id="font-size-preset" onchange="setFontSize(this.value)" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;cursor:pointer;">
                <option value="9">9px</option><option value="10">10px</option><option value="11">11px</option>
                <option value="12" selected>12px</option><option value="13">13px</option><option value="14">14px</option><option value="16">16px</option>
            </select>
        </div>
        <button class="btn btn-danger" onclick="savePDF()" id="btn-save-pdf">
            <i class="fa-solid fa-file-pdf"></i> Salva PDF
        </button>
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Stampa
        </button>
    </div>
    <?php endif; ?>
    
    <script>
    let currentFontSize = 12;
    try { const saved = localStorage.getItem('openLogistic_printFontSize'); if (saved) currentFontSize = parseInt(saved); } catch(e) {}
    
    function setFontSize(size) {
        currentFontSize = parseInt(size);
        try { localStorage.setItem('openLogistic_printFontSize', currentFontSize); } catch(e) {}
        applyFontSize();
    }
    function changeFontSize(delta) {
        currentFontSize = Math.max(7, Math.min(20, currentFontSize + delta));
        const p = document.getElementById('font-size-preset'); if (p) p.value = currentFontSize;
        try { localStorage.setItem('openLogistic_printFontSize', currentFontSize); } catch(e) {}
        applyFontSize();
    }
    function applyFontSize() {
        const page = document.querySelector('.page'); if (!page) return;
        const base = currentFontSize;
        const l = document.getElementById('font-size-label'); if (l) l.textContent = base + 'px';
        const p = document.getElementById('font-size-preset'); if (p) p.value = base;
        page.style.fontSize = base + 'px';
        page.querySelectorAll('.company-name').forEach(el => el.style.fontSize = (base + 4) + 'px');
        page.querySelectorAll('.company-info').forEach(el => el.style.fontSize = (base - 1) + 'px');
        page.querySelectorAll('.sede-legale-info').forEach(el => el.style.fontSize = (base - 3) + 'px');
        page.querySelectorAll('.doc-title').forEach(el => el.style.fontSize = (base + 4) + 'px');
        page.querySelectorAll('.doc-number').forEach(el => el.style.fontSize = (base + 6) + 'px');
        page.querySelectorAll('.doc-number-small').forEach(el => el.style.fontSize = (base - 1) + 'px');
        page.querySelectorAll('.doc-date').forEach(el => el.style.fontSize = (base + 1) + 'px');
        page.querySelectorAll('.address-box .name').forEach(el => el.style.fontSize = (base + 1) + 'px');
        page.querySelectorAll('.address-box .line').forEach(el => el.style.fontSize = (base - 1) + 'px');
        page.querySelectorAll('.address-box h3').forEach(el => el.style.fontSize = (base - 2) + 'px');
        // Info grid (dettaglio) - sempre più piccolo
        page.querySelectorAll('.info-item label').forEach(el => el.style.fontSize = Math.max(7, base - 4) + 'px');
        page.querySelectorAll('.info-item span').forEach(el => el.style.fontSize = Math.max(9, base - 2) + 'px');
        page.querySelectorAll('table').forEach(el => el.style.fontSize = (base - 1) + 'px');
        page.querySelectorAll('th').forEach(el => el.style.fontSize = (base - 2) + 'px');
        page.querySelectorAll('.col-codice').forEach(el => el.style.fontSize = (base - 2) + 'px');
        page.querySelectorAll('.col-qta').forEach(el => el.style.fontSize = (base - 1) + 'px');
        page.querySelectorAll('.col-parziale').forEach(el => el.style.fontSize = (base - 1) + 'px');
        page.querySelectorAll('.col-qta-lettere').forEach(el => el.style.fontSize = (base - 2) + 'px');
        page.querySelectorAll('.notes p').forEach(el => el.style.fontSize = (base - 1) + 'px');
        page.querySelectorAll('.conversion-table').forEach(el => el.style.fontSize = (base - 2) + 'px');
        page.querySelectorAll('.legal-footer').forEach(el => el.style.fontSize = (base - 3) + 'px');
        page.querySelectorAll('.signature-box label').forEach(el => el.style.fontSize = (base - 2) + 'px');
    }
    
    // Salva come PDF - usa la stampa nativa del browser (più affidabile)
    function savePDF() {
        // Mostra istruzioni e apre dialogo stampa
        alert('Per salvare come PDF:\n\n1. Nel dialogo di stampa, seleziona "Salva come PDF" come stampante\n2. Clicca "Salva"\n\nQuesto metodo mantiene il layout perfetto del documento.');
        window.print();
    }
    
    document.addEventListener('DOMContentLoaded', () => { if (currentFontSize !== 12) applyFontSize(); });
    </script>
</body>
</html>
