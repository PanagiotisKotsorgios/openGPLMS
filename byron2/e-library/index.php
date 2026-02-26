<?php
/**
 * index.php — Κεντρική σελίδα (Landing Page)
 */
require 'config.php';

if (isLoggedIn()) {
    header('Location: dashboard.php'); exit;
}

$loginError = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    verifyCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!loginCheckRateLimit($ip)) {
        $remaining = loginRemainingSeconds($ip);
        $mins = ceil($remaining / 60);
        $loginError = "Πολλές αποτυχημένες προσπάθειες. Δοκιμάστε ξανά σε {$mins} λεπτό(ά).";
    } elseif ($username && $password) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM " . TABLE_PREFIX . "users WHERE username=? AND active=1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            loginClearAttempts($ip);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
               ->execute([$user['id'], 'login', 'user', 'Login from ' . $ip]);
            header('Location: dashboard.php'); exit;
        } else {
            loginRecordFailure($ip);
            $attemptsLeft = max(0, LOGIN_MAX_ATTEMPTS - ($_SESSION['login_attempts_' . md5($ip)]['count'] ?? 0));
            $loginError = 'Λάθος όνομα χρήστη ή κωδικός πρόσβασης.'
                        . ($attemptsLeft > 0 ? " ({$attemptsLeft} προσπάθεια(ες) ακόμα)" : '');
        }
    } else {
        $loginError = 'Παρακαλώ συμπληρώστε όλα τα πεδία.';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Βυρωνική Εταιρεία — Σύστημα Διαχείρισης Βιβλιοθήκης</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
<style>
/* ─── VARIABLES ─────────────────────────────────────────────── */
:root {
    --gold:       #c9a84c;
    --gold-light: #e8c547;
    --gold-dim:   rgba(201,168,76,0.18);
    --border:     rgba(201,168,76,0.25);
    --muted:      #6c757d;
    --text:       #212529;
    --panel:      #ffffff;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html {
    height: 100%;
    /* Prevent iOS rubber-band scroll from revealing white bg */
    background: #0d0b07;
}

body {
    font-family: 'Jost', sans-serif;
    min-height: 100%;
    min-height: 100dvh; /* dynamic viewport height — modern browsers */
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
    background: #0d0b07;
    /* iOS overscroll colour */
    overscroll-behavior: none;
}

/* ─── FULL-SCREEN BACKGROUND ────────────────────────────────── */
/*
 * FIX: background-attachment:fixed is broken on iOS Safari and most
 * Android browsers — it causes the image to scroll or jump.
 * Solution: use a position:fixed pseudo-element on <html> instead.
 * This is universally supported and behaves correctly on all mobile
 * browsers (iOS Safari, Chrome Android, Samsung Internet, etc.).
 */
html::before {
    content: '';
    position: fixed;          /* ← key: fixed to viewport, never moves */
    inset: 0;
    z-index: 0;
    /* The actual background image */
    background-image: url('https://www.lagoonroutes.gr/eedrefti/2023/11/20231204_102750.jpg');
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
    /* Dark overlay baked in via the pseudo-element below */
    /* We'll do the overlay in html::after */
}

html::after {
    content: '';
    position: fixed;
    inset: 0;
    z-index: 1;   /* sits above the image, below everything else */
    background:
        linear-gradient(160deg,
            rgba(8, 6, 2, 0.82) 0%,
            rgba(18, 13, 4, 0.75) 45%,
            rgba(28, 20, 6, 0.85) 100%
        ),
        radial-gradient(ellipse 80% 60% at 30% 50%, rgba(201,168,76,0.06) 0%, transparent 70%);
    pointer-events: none;
}

/* Remove the old .bg-layer — kept for backwards compat but hidden */
.bg-layer { display: none; }

/* ─── MAIN WRAPPER ──────────────────────────────────────────── */
.landing {
    position: relative;
    z-index: 2;   /* above html::after overlay */
    min-height: 100vh;
    min-height: 100dvh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: space-between;
    padding: 48px 32px 36px;
    /* Ensure content fills height even if short */
    gap: 32px;
}

/* ─── BRAND ─────────────────────────────────────────────────── */
.brand-logo-link {
    text-decoration: none;
    text-align: center;
    display: block;
    animation: fadeDown 0.7s ease both;
    transition: opacity 0.2s;
    flex-shrink: 0;
}
.brand-logo-link:hover { opacity: 0.8; }

.blt-top {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(14px, 2.2vw, 22px);
    font-weight: 700;
    letter-spacing: 3px;
    text-transform: uppercase;
    line-height: 1.2;
    -webkit-text-stroke: 0.3px rgba(200,220,255,0.3);
    color: #d8e4ff;
}
.blt-mid {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(10px, 1.5vw, 16px);
    font-weight: 600;
    letter-spacing: 2px;
    color: #c0ccee;
    text-transform: uppercase;
    margin-top: 4px;
    line-height: 1.3;
}
.blt-sub {
    font-family: 'Jost', sans-serif;
    font-size: clamp(10px, 1.2vw, 13px);
    font-weight: 300;
    letter-spacing: 1px;
    color: rgba(200,215,255,0.65);
    margin-top: 5px;
    font-style: italic;
}

/* ─── CENTER CONTENT ────────────────────────────────────────── */
.center-content {
    text-align: center;
    max-width: 640px;
    width: 100%;
    animation: fadeUp 0.8s ease 0.15s both;
    flex-shrink: 0;
}

.eyebrow {
    font-size: 11px; letter-spacing: 4px; text-transform: uppercase;
    color: var(--gold); font-weight: 500; margin-bottom: 20px;
    display: flex; align-items: center; justify-content: center; gap: 12px;
}
.eyebrow::before, .eyebrow::after {
    content: ''; display: block; width: 36px; height: 1px;
    background: var(--gold); opacity: 0.6;
}

.main-quote {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(34px, 6vw, 68px);
    font-weight: 300; line-height: 1.1;
    color: #f5f0e8; margin-bottom: 16px; letter-spacing: -0.5px;
}
.main-quote em { font-style: italic; color: var(--gold); }

.quote-attr {
    font-size: 13px; letter-spacing: 2px;
    color: rgba(201,168,76,0.8); text-transform: uppercase; margin-bottom: 22px;
}

.intro-text {
    font-size: 15px; line-height: 1.8;
    color: rgba(240,230,200,0.65); font-weight: 300;
    max-width: 480px; margin: 0 auto 40px;
}

/* ─── CATALOG ENTRY BUTTON ──────────────────────────────────── */
.btn-catalog-entry {
    display: inline-block;
    padding: 16px 56px;
    border: 2px solid var(--gold);
    border-radius: 4px;
    color: var(--gold);
    font-family: 'Jost', sans-serif;
    font-size: 14px;
    font-weight: 500;
    letter-spacing: 3px;
    text-transform: uppercase;
    text-decoration: none;
    transition: all 0.25s ease;
    animation: fadeUp 0.8s ease 0.3s both;
    -webkit-tap-highlight-color: transparent; /* remove grey flash on iOS tap */
}
.btn-catalog-entry:hover,
.btn-catalog-entry:active {
    background: var(--gold);
    color: #fff;
    box-shadow: 0 8px 32px rgba(201,168,76,0.3);
}

/* ─── FOOTER ────────────────────────────────────────────────── */
.site-footer {
    width: 100%;
    text-align: center;
    animation: fadeUp 0.8s ease 0.45s both;
    flex-shrink: 0;
    /* Extra bottom padding on iPhone notch/home bar */
    padding-bottom: env(safe-area-inset-bottom, 0px);
}
.footer-links {
    display: flex; flex-wrap: wrap; gap: 6px 22px;
    justify-content: center; margin-bottom: 14px;
}
.footer-links a, .footer-links button {
    font-size: 13px; color: rgba(240,230,200,0.45);
    text-decoration: none; letter-spacing: 0.3px; transition: color 0.2s;
    background: none; border: none; cursor: pointer;
    font-family: 'Jost', sans-serif; padding: 4px 2px; /* bigger tap target */
    -webkit-tap-highlight-color: transparent;
}
.footer-links a:hover, .footer-links button:hover,
.footer-links a:active, .footer-links button:active { color: var(--gold); }
.footer-copy { font-size: 12px; color: rgba(240,230,200,0.25); letter-spacing: 0.5px; }

/* ─── ANIMATIONS ────────────────────────────────────────────── */
@keyframes fadeDown { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }
@keyframes fadeUp   { from{opacity:0;transform:translateY(20px)}  to{opacity:1;transform:translateY(0)} }

/* ─── LOGIN MODAL ───────────────────────────────────────────── */
.modal-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(8,6,2,0.65); backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.25s;
    padding: 16px;
    /* Allow scroll inside if modal is taller than viewport (small phones) */
    overflow-y: auto;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal-box {
    background: #ffffff; border: 1px solid rgba(201,168,76,0.2);
    border-radius: 16px; padding: 44px 48px;
    max-width: 440px; width: 100%;
    position: relative;
    transform: translateY(24px) scale(0.97);
    transition: transform 0.28s cubic-bezier(.34,1.56,.64,1);
    box-shadow: 0 32px 64px rgba(0,0,0,0.35);
    /* Don't let it be cut off on very small screens */
    margin: auto;
}
.modal-overlay.open .modal-box { transform: translateY(0) scale(1); }

.modal-close-btn {
    position: absolute; top: 16px; right: 18px;
    background: none; border: none; color: var(--muted); font-size: 20px; cursor: pointer;
    width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
    border-radius: 50%; transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.modal-close-btn:hover { color: var(--text); background: rgba(0,0,0,0.06); }

.modal-heading { margin-bottom: 32px; }
.modal-step-tag {
    font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase;
    color: var(--gold); display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
}
.modal-step-dot {
    width: 6px; height: 6px; border-radius: 50%; background: var(--gold);
    animation: blink 1.8s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }
.modal-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 34px; font-weight: 400; color: var(--text); margin-bottom: 6px;
}
.modal-subtitle { font-size: 15px; color: var(--muted); line-height: 1.6; }

.error-banner {
    display: flex; align-items: center; gap: 10px;
    background: rgba(220,53,69,0.08); border: 1px solid rgba(220,53,69,0.2);
    border-radius: 8px; padding: 12px 16px; margin-bottom: 24px;
    font-size: 14px; color: #b02a37;
}

.field-group { margin-bottom: 22px; }
.field-label {
    font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase;
    color: var(--muted); font-weight: 500; display: block; margin-bottom: 8px;
}
.input-wrap { position: relative; }
.input-wrap .input-icon {
    position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
    color: rgba(0,0,0,0.35); font-size: 14px; pointer-events: none; transition: color 0.2s;
}
.input-wrap input {
    width: 100%; background: #eef0f3; border: 1px solid #d0d5dc;
    border-radius: 8px; padding: 13px 15px 13px 42px;
    font-family: 'Jost', sans-serif; font-size: 16px; /* ≥16px prevents iOS auto-zoom */
    color: #111;
    outline: none; transition: all 0.25s;
    -webkit-appearance: none; /* removes iOS inner shadow */
}
.input-wrap input::placeholder { color: rgba(0,0,0,0.35); }
.input-wrap input:focus { background: #fff; border-color: var(--gold); box-shadow: 0 0 0 3px rgba(201,168,76,0.15); }
.input-wrap:focus-within .input-icon { color: var(--gold); }
.toggle-pass {
    position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--muted); cursor: pointer; font-size: 14px; transition: color 0.2s;
    padding: 8px; /* bigger tap target */
    -webkit-tap-highlight-color: transparent;
}
.toggle-pass:hover { color: var(--gold); }

.forgot-row { display: flex; justify-content: flex-end; margin-top: -8px; margin-bottom: 26px; }
.forgot-link {
    font-size: 13px; color: var(--muted); text-decoration: none;
    display: flex; align-items: center; gap: 5px; transition: color 0.2s;
    padding: 4px 0; /* bigger tap target */
}
.forgot-link:hover { color: var(--gold); }

.btn-signin {
    width: 100%;
    background: linear-gradient(135deg, var(--gold) 0%, #b8922a 100%);
    color: #fff; border: none; border-radius: 8px; padding: 15px;
    font-family: 'Jost', sans-serif; font-size: 15px; font-weight: 600;
    letter-spacing: 1.5px; text-transform: uppercase; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    transition: all 0.25s;
    -webkit-tap-highlight-color: transparent;
}
.btn-signin:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(201,168,76,0.3); }
.btn-signin:active { transform: translateY(0); }

/* ─── LEGAL / EXTRA MODALS ──────────────────────────────────── */
.legal-modal-overlay {
    position: fixed; inset: 0; z-index: 10000;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.25s;
    padding: 16px;
    overflow-y: auto;
}
.legal-modal-overlay.open { opacity: 1; pointer-events: all; }
.legal-modal-box {
    background: #fff; border-radius: 14px;
    max-width: 520px; width: 100%; max-height: 82vh;
    overflow: hidden; display: flex; flex-direction: column;
    box-shadow: 0 24px 48px rgba(0,0,0,0.2);
    transform: translateY(16px) scale(0.98); transition: transform 0.25s;
    margin: auto;
}
.legal-modal-overlay.open .legal-modal-box { transform: translateY(0) scale(1); }
.legal-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 28px; border-bottom: 1px solid #eee; flex-shrink: 0;
}
.legal-modal-head-title { font-family: 'Cormorant Garamond', serif; font-size: 24px; font-weight: 600; color: #212529; }
.legal-modal-head-close {
    background: none; border: none; font-size: 22px; cursor: pointer; color: #999;
    width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
    border-radius: 50%; transition: all 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.legal-modal-head-close:hover { background: #f3f3f3; color: #333; }
.legal-modal-body { padding: 24px 28px; overflow-y: auto; line-height: 1.7; }
.legal-modal-body::-webkit-scrollbar { width: 4px; }
.legal-modal-body::-webkit-scrollbar-thumb { background: #ddd; border-radius: 2px; }
.legal-modal-body h4 { font-family: 'Cormorant Garamond', serif; font-size: 18px; color: var(--gold); margin: 18px 0 7px; }
.legal-modal-body h4:first-child { margin-top: 0; }
.legal-modal-body p, .legal-modal-body li { font-size: 14px; color: #555; margin-bottom: 10px; }
.legal-modal-body ul { padding-left: 18px; }

/* custom modal (forgot, help, accessibility) */
.custom-modal-overlay {
    position: fixed; inset: 0; z-index: 10000;
    background: rgba(255,255,255,0.7);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.25s;
    padding: 16px;
    overflow-y: auto;
}
.custom-modal-overlay.open { opacity: 1; pointer-events: all; }
.custom-modal {
    background: #fff; border: 1px solid rgba(201,168,76,0.2);
    border-radius: 14px; padding: 36px 40px;
    max-width: 480px; width: 100%; position: relative;
    transform: translateY(20px) scale(0.97); transition: transform 0.25s;
    box-shadow: 0 20px 40px rgba(0,0,0,0.12);
    margin: auto;
}
.custom-modal-overlay.open .custom-modal { transform: translateY(0) scale(1); }
.custom-modal .modal-close {
    position: absolute; top: 16px; right: 18px;
    background: none; border: none; color: var(--muted); font-size: 20px; cursor: pointer;
    width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
    border-radius: 50%; transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.custom-modal .modal-close:hover { color: var(--text); background: rgba(0,0,0,0.05); }
.custom-modal .cm-title { font-family: 'Cormorant Garamond', serif; font-size: 28px; font-weight: 400; color: var(--text); margin-bottom: 8px; }
.custom-modal .cm-sub { font-size: 15px; color: var(--muted); line-height: 1.6; margin-bottom: 24px; }
.modal-input {
    width: 100%; background: rgba(0,0,0,0.02); border: 1px solid rgba(201,168,76,0.2);
    border-radius: 8px; padding: 14px 16px; font-family: 'Jost', sans-serif;
    font-size: 16px; /* ≥16px prevents iOS auto-zoom */
    color: var(--text); outline: none; transition: all 0.25s; margin-bottom: 16px;
    -webkit-appearance: none;
}
.modal-input:focus { border-color: rgba(201,168,76,0.5); box-shadow: 0 0 0 3px rgba(201,168,76,0.1); }
.modal-input::placeholder { color: rgba(0,0,0,0.25); }
.btn-modal-submit {
    width: 100%; background: linear-gradient(135deg, var(--gold) 0%, #b8922a 100%);
    color: #fff; border: none; border-radius: 8px; padding: 14px;
    font-family: 'Jost', sans-serif; font-size: 14px; font-weight: 600;
    letter-spacing: 1px; text-transform: uppercase; cursor: pointer; transition: all 0.25s;
    -webkit-tap-highlight-color: transparent;
}
.btn-modal-submit:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(201,168,76,0.25); }
.modal-note { font-size: 13px; color: var(--muted); text-align: center; margin-top: 14px; line-height: 1.6; }
.modal-note a { color: var(--gold); text-decoration: none; }

/* help accordion */
.help-item { border-bottom: 1px solid rgba(0,0,0,0.08); padding: 14px 0; }
.help-item:last-child { border-bottom: none; padding-bottom: 0; }
.help-q { font-size: 15px; font-weight: 500; color: var(--text); cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 10px; user-select: none; padding: 4px 0; /* bigger tap area */ }
.help-q i { font-size: 13px; color: var(--gold); transition: transform 0.2s; flex-shrink: 0; }
.help-q.active i { transform: rotate(45deg); }
.help-a { font-size: 14px; color: var(--muted); line-height: 1.7; max-height: 0; overflow: hidden; transition: max-height 0.3s ease, margin-top 0.2s; }
.help-a.open { max-height: 150px; margin-top: 10px; }

/* ─── RESPONSIVE — small phones ────────────────────────────── */
@media (max-width: 480px) {
    .landing {
        padding: 28px 20px 24px;
        gap: 24px;
    }

    .blt-top { letter-spacing: 2px; }
    .blt-mid { letter-spacing: 1.5px; }

    .eyebrow { margin-bottom: 14px; letter-spacing: 3px; }
    .eyebrow::before, .eyebrow::after { width: 24px; }

    .main-quote { font-size: clamp(30px, 9vw, 46px); margin-bottom: 12px; }
    .quote-attr  { margin-bottom: 14px; }
    .intro-text  { font-size: 14px; margin-bottom: 30px; }

    .btn-catalog-entry {
        padding: 15px 32px;
        font-size: 13px;
        letter-spacing: 2px;
        width: 100%;
        text-align: center;
    }

    .modal-box {
        padding: 28px 20px 24px;
        border-radius: 12px;
    }
    .modal-title { font-size: 28px; }
    .modal-subtitle { font-size: 14px; }

    .custom-modal { padding: 28px 20px 24px; }
    .custom-modal .cm-title { font-size: 24px; }
}

/* Landscape phones — tighten vertical spacing so nothing clips */
@media (max-height: 600px) and (max-width: 900px) {
    .landing {
        justify-content: flex-start;
        padding-top: 20px;
        gap: 18px;
    }
    .main-quote { font-size: clamp(26px, 5vh, 40px); margin-bottom: 8px; }
    .intro-text  { display: none; } /* hide description in landscape to save space */
    .eyebrow     { margin-bottom: 10px; }
    .quote-attr  { margin-bottom: 16px; }
}
</style>
</head>
<body>

<!-- bg-layer kept in DOM for backward compat; hidden via CSS -->
<div class="bg-layer"></div>

<div class="landing">

    <!-- BRAND -->
    <a href="https://www.messolonghibyronsociety.gr/" target="_blank" rel="noopener" class="brand-logo-link">
        <div class="blt-top">THE MESSOLONGHI BYRON SOCIETY</div>
        <div class="blt-mid">ΒΥΡΩΝΙΚΗ ΕΤΑΙΡΕΙΑ ΙΕΡΑΣ ΠΟΛΕΩΣ ΜΕΣΟΛΟΓΓΙΟΥ</div>
        <div class="blt-sub">International Research Center for Lord Byron &amp; Philhellenism</div>
    </a>

    <!-- CENTER -->
    <div class="center-content">
        <div class="eyebrow">Ψηφιακό Σύστημα Διαχείρισης Βιβλιοθήκης</div>

        <h1 class="main-quote">
            Ο καλύτερος προφήτης<br>
            του μέλλοντος <em>είναι</em><br>
            το παρελθόν.
        </h1>

        <div class="quote-attr">Λόρδος Βύρων &nbsp;·&nbsp; Lord Byron</div>

        <p class="intro-text">
            Αναζητήστε, εξερευνήστε και ανακαλύψτε χιλιάδες τίτλους του πλούσιου αρχείου
            της Βυρωνικής Εταιρείας. Πρόσβαση στον πλήρη κατάλογο ανοιχτά για όλους.
        </p>

        <a href="catalog_public.php" class="btn-catalog-entry">
            Είσοδος στον κατάλογο
        </a>
    </div>

    <!-- FOOTER -->
    <footer class="site-footer">
        <div class="footer-links">
            <button onclick="openLoginModal()">Διαχείριση</button>
            <button onclick="openCustomModal('accessibilityModal')">Προσβασιμότητα</button>
            <button onclick="openCustomModal('helpModal')">FAQ</button>
            <a href="https://www.messolonghibyronsociety.gr/contact-us/" target="_blank" rel="noopener">Επικοινωνία</a>
        </div>
        <div class="footer-copy">
            &copy; <?= date('Y') ?> Βυρωνική Εταιρεία &nbsp;·&nbsp; Σύστημα Διαχείρισης Βιβλιοθήκης Βυρωνικής Εταιρείας v1.0
        </div>
    </footer>

</div><!-- /.landing -->


<!-- ══════════════════════════════════════════════════════
     LOGIN MODAL
══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="loginModal">
    <div class="modal-box">
        <button class="modal-close-btn" onclick="closeLoginModal()" title="Κλείσιμο">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="modal-heading">
            <div class="modal-step-tag"><div class="modal-step-dot"></div> Περιορισμένη Πρόσβαση</div>
            <div class="modal-title">Καλωσορίσατε</div>
            <div class="modal-subtitle">Συνδεθείτε στο σύστημα διαχείρισης της βιβλιοθήκης.</div>
        </div>

        <?php if ($loginError): ?>
        <div class="error-banner">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= h($loginError) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="login">

            <div class="field-group">
                <label class="field-label" for="username">
                    <i class="fa-regular fa-user" style="margin-right:5px;color:var(--gold)"></i>Όνομα Χρήστη
                </label>
                <div class="input-wrap">
                    <input type="text" id="username" name="username"
                           placeholder="Εισάγετε το όνομα χρήστη σας"
                           value="<?= h($_POST['username'] ?? '') ?>"
                           required autocomplete="username" spellcheck="false">
                    <i class="fa-regular fa-user input-icon"></i>
                </div>
            </div>

            <div class="field-group">
                <label class="field-label" for="password">
                    <i class="fa-solid fa-key" style="margin-right:5px;color:var(--gold)"></i>Κωδικός Πρόσβασης
                </label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="Εισάγετε τον κωδικό πρόσβασής σας"
                           required autocomplete="current-password">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <button type="button" class="toggle-pass" onclick="togglePassword()" title="Εμφάνιση/Απόκρυψη">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <div class="forgot-row">
                <a href="#" class="forgot-link" onclick="openCustomModal('forgotModal'); return false;">
                    <i class="fa-regular fa-circle-question"></i>
                    Ξεχάσατε τον κωδικό σας;
                </a>
            </div>

            <button type="submit" class="btn-signin" id="submitBtn">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>Σύνδεση</span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     FORGOT PASSWORD MODAL
══════════════════════════════════════════════════════ -->
<div class="custom-modal-overlay" id="forgotModal">
    <div class="custom-modal">
        <button class="modal-close" onclick="closeCustomModal('forgotModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="cm-title">Ανάκτηση Κωδικού</div>
        <div class="cm-sub">Συμπληρώστε το username σας παρακάτω και ο admin θα λάβει σχετικό αίτημα.</div>
        <input type="text"  class="modal-input" placeholder="Username ή ονοματεπώνυμο..." id="forgotUser">
        <input type="email" class="modal-input" placeholder="Email επικοινωνίας (προαιρετικό)..." id="forgotEmail">
        <button class="btn-modal-submit" onclick="submitForgot()">
            <i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος
        </button>
        <div class="modal-note">
            Ή επικοινωνήστε στο <a href="mailto:byronlib@gmail.com">byronlib@gmail.com</a>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     FAQ / HELP MODAL
══════════════════════════════════════════════════════ -->
<div class="custom-modal-overlay" id="helpModal">
    <div class="custom-modal">
        <button class="modal-close" onclick="closeCustomModal('helpModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="cm-title">Βοήθεια &amp; FAQ</div>
        <div class="cm-sub">Συχνές ερωτήσεις για τη χρήση του συστήματος.</div>
        <div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">Δεν θυμάμαι τον κωδικό μου. Τι κάνω;<i class="fa-solid fa-plus"></i></div>
                <div class="help-a">Χρησιμοποιήστε την επιλογή «Ξεχάσατε τον κωδικό;» στο modal σύνδεσης, ή επικοινωνήστε με τον διαχειριστή στο byronlib@gmail.com.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">Ποιοι μπορούν να συνδεθούν στο σύστημα;<i class="fa-solid fa-plus"></i></div>
                <div class="help-a">Μόνο εξουσιοδοτημένοι υπάλληλοι και διαχειριστές. Οι επισκέπτες μπορούν να χρησιμοποιήσουν τον δημόσιο κατάλογο χωρίς σύνδεση.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">Πώς μπορώ να αποκτήσω πρόσβαση ως νέος υπάλληλος;<i class="fa-solid fa-plus"></i></div>
                <div class="help-a">Ο λογαριασμός σας δημιουργείται από τον διαχειριστή. Επικοινωνήστε με τον υπεύθυνο βιβλιοθήκης.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">Το username/password είναι case-sensitive;<i class="fa-solid fa-plus"></i></div>
                <div class="help-a">Ναι, ο κωδικός είναι πλήρως case-sensitive. Βεβαιωθείτε ότι το CapsLock είναι απενεργοποιημένο.</div>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     ACCESSIBILITY MODAL
══════════════════════════════════════════════════════ -->
<div class="custom-modal-overlay" id="accessibilityModal">
    <div class="custom-modal">
        <button class="modal-close" onclick="closeCustomModal('accessibilityModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="cm-title">Προσβασιμότητα</div>
        <div class="cm-sub">Το σύστημα έχει σχεδιαστεί για να είναι προσβάσιμο σε όλους τους χρήστες.</div>
        <div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">Πλοήγηση με Πληκτρολόγιο<i class="fa-solid fa-plus"></i></div>
                <div class="help-a">Χρησιμοποιήστε Tab για μετακίνηση μεταξύ πεδίων και Enter για υποβολή φόρμας.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">Αντίθεση &amp; Εμφάνιση<i class="fa-solid fa-plus"></i></div>
                <div class="help-a">Μπορείτε να αυξήσετε το μέγεθος γραμματοσειράς μέσω των ρυθμίσεων του browser σας (Ctrl/Cmd +).</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">Αναφορά Προβλήματος<i class="fa-solid fa-plus"></i></div>
                <div class="help-a">Εάν αντιμετωπίζετε πρόβλημα προσβασιμότητας, επικοινωνήστε στο byronlib@gmail.com</div>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     LEGAL MODALS
══════════════════════════════════════════════════════ -->
<div class="legal-modal-overlay" id="legal-modal-privacy">
    <div class="legal-modal-box">
        <div class="legal-modal-head">
            <span class="legal-modal-head-title">Πολιτική Απορρήτου</span>
            <button class="legal-modal-head-close" onclick="closeLegalModal('privacy')">&times;</button>
        </div>
        <div class="legal-modal-body">
            <h4>1. Συλλογή Δεδομένων</h4>
            <p>Το σύστημα καταγράφει τα στοιχεία σύνδεσης (username, ημερομηνία/ώρα, διεύθυνση IP) για λόγους ασφαλείας και ελέγχου. Δεν συλλέγουμε προσωπικά δεδομένα πέραν αυτών που είναι απαραίτητα για τη λειτουργία του συστήματος.</p>
            <h4>2. Χρήση Δεδομένων</h4>
            <p>Τα δεδομένα χρησιμοποιούνται αποκλειστικά για τη διαχείριση της βιβλιοθήκης και δεν κοινοποιούνται σε τρίτους.</p>
            <h4>3. Ασφάλεια</h4>
            <p>Οι κωδικοί πρόσβασης αποθηκεύονται με κρυπτογράφηση bcrypt. Διατηρούμε αρχείο καταγραφής (audit log) για όλες τις ενέργειες.</p>
            <h4>4. Δικαιώματα</h4>
            <p>Μπορείτε να ζητήσετε διόρθωση ή διαγραφή των δεδομένων σας επικοινωνώντας στο <a href="mailto:byronlib@gmail.com" style="color:var(--gold)">byronlib@gmail.com</a></p>
            <h4>5. Ευρωπαϊκή Νομοθεσία</h4>
            <p>Η επεξεργασία δεδομένων διέπεται από τον GDPR (Κανονισμός ΕΕ 2016/679) και την ελληνική νομοθεσία.</p>
        </div>
    </div>
</div>

<div class="legal-modal-overlay" id="legal-modal-terms">
    <div class="legal-modal-box">
        <div class="legal-modal-head">
            <span class="legal-modal-head-title">Όροι Χρήσης</span>
            <button class="legal-modal-head-close" onclick="closeLegalModal('terms')">&times;</button>
        </div>
        <div class="legal-modal-body">
            <h4>1. Αποδοχή Όρων</h4>
            <p>Η χρήση του συστήματος προϋποθέτει αποδοχή των παρόντων όρων. Το σύστημα προορίζεται αποκλειστικά για εξουσιοδοτημένους χρήστες της Βυρωνικής Εταιρείας.</p>
            <h4>2. Χρήση Λογαριασμού</h4>
            <p>Ο λογαριασμός σας είναι προσωπικός και μη μεταβιβάσιμος. Απαγορεύεται η κοινοποίηση των κωδικών πρόσβασης.</p>
            <h4>3. Απαγορευμένες Ενέργειες</h4>
            <ul>
                <li>Μη εξουσιοδοτημένη πρόσβαση στο σύστημα</li>
                <li>Τροποποίηση δεδομένων χωρίς δικαίωμα</li>
                <li>Εξαγωγή δεδομένων για εμπορικούς σκοπούς</li>
                <li>Κάθε ενέργεια που υπονομεύει την ασφάλεια του συστήματος</li>
            </ul>
            <h4>4. Ευθύνη</h4>
            <p>Η Βυρωνική Εταιρεία δεν ευθύνεται για τυχαία απώλεια δεδομένων ή διακοπή λειτουργίας.</p>
        </div>
    </div>
</div>


<script>
// ─── Login Modal ─────────────────────────────────────────────
function openLoginModal() {
    document.getElementById('loginModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('username')?.focus(), 300);
}
function closeLoginModal() {
    document.getElementById('loginModal').classList.remove('open');
    document.body.style.overflow = '';
}

<?php if ($loginError): ?>
window.addEventListener('DOMContentLoaded', () => openLoginModal());
<?php endif; ?>

document.getElementById('loginModal').addEventListener('click', function(e) {
    if (e.target === this) closeLoginModal();
});

document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Σύνδεση...</span>';
    btn.disabled = true;
});

function togglePassword() {
    const inp = document.getElementById('password');
    const ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fa-regular fa-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'fa-regular fa-eye'; }
}

// ─── Custom Modals ───────────────────────────────────────────
function openCustomModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeCustomModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
document.querySelectorAll('.custom-modal-overlay').forEach(o => o.addEventListener('click', function(e){ if(e.target===this) closeCustomModal(this.id); }));

// ─── Legal Modals ────────────────────────────────────────────
function openLegalModal(id) { document.getElementById('legal-modal-'+id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeLegalModal(id) { document.getElementById('legal-modal-'+id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.legal-modal-overlay').forEach(o => o.addEventListener('click', function(e){ if(e.target===this){ this.classList.remove('open'); document.body.style.overflow=''; } }));

// Escape closes all
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    closeLoginModal();
    document.querySelectorAll('.custom-modal-overlay.open').forEach(m => closeCustomModal(m.id));
    document.querySelectorAll('.legal-modal-overlay.open').forEach(m => { m.classList.remove('open'); document.body.style.overflow=''; });
});

// ─── FAQ Accordion ───────────────────────────────────────────
function toggleHelp(el) {
    const a = el.nextElementSibling, isOpen = a.classList.contains('open');
    document.querySelectorAll('.help-a.open').forEach(x => x.classList.remove('open'));
    document.querySelectorAll('.help-q.active').forEach(x => x.classList.remove('active'));
    if (!isOpen) { a.classList.add('open'); el.classList.add('active'); }
}

// ─── Forgot Password ─────────────────────────────────────────
function submitForgot() {
    const user = document.getElementById('forgotUser').value.trim();
    const email = document.getElementById('forgotEmail').value.trim();
    if (!user) { document.getElementById('forgotUser').focus(); return; }
    const btn = event.target;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="margin-right:8px"></i>Αποστολή...';
    btn.disabled = true;
    fetch('forgot_message.php', {
        method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'username=' + encodeURIComponent(user) + '&email=' + encodeURIComponent(email)
    }).then(r=>r.json()).then(data => {
        if (data.ok) {
            btn.innerHTML = '<i class="fa-solid fa-check" style="margin-right:8px"></i>Αίτημα Εστάλη!';
            btn.style.background = 'linear-gradient(135deg,#22c55e,#16a34a)';
            setTimeout(() => closeCustomModal('forgotModal'), 2200);
            setTimeout(() => { btn.innerHTML='<i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος'; btn.style.background=''; btn.disabled=false; document.getElementById('forgotUser').value=''; document.getElementById('forgotEmail').value=''; }, 2800);
        } else {
            btn.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:8px"></i>Σφάλμα';
            btn.style.background = 'linear-gradient(135deg,#ef4444,#dc2626)'; btn.disabled=false;
            setTimeout(() => { btn.innerHTML='<i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος'; btn.style.background=''; }, 3000);
        }
    }).catch(() => {
        btn.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:8px"></i>Σφάλμα σύνδεσης';
        btn.style.background = 'linear-gradient(135deg,#ef4444,#dc2626)'; btn.disabled=false;
        setTimeout(() => { btn.innerHTML='<i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος'; btn.style.background=''; }, 3000);
    });
}
</script>
</body>
</html>