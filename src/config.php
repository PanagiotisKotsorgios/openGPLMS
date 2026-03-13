<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'root');         
define('DB_NAME', 'dbname');
define('TABLE_PREFIX', 'prefix_');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $code = $e->getCode();

            if ($code == 1045) {
                die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>DB Login Error</title>
<style>
body{font-family:Arial,sans-serif;background:#1a1a2e;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border-radius:12px;padding:40px;max-width:560px;width:90%}
h2{color:#ef4444;margin:0 0 14px}
code{background:#f3f4f6;padding:2px 8px;border-radius:4px;font-size:13px;font-family:monospace}
.step{background:#f9fafb;border-left:3px solid #e8c547;padding:11px 15px;margin:8px 0;font-size:13px;border-radius:0 6px 6px 0;line-height:1.7}
p{font-size:14px;color:#374151}
a{color:#3b82f6}
</style></head><body><div class="box">
<h2>&#9888; MySQL Access Denied</h2>
<p>Cannot connect as <code>' . DB_USER . '</code>. Fix one of these:</p>
<div class="step"><strong>Option A (recommended):</strong><br>
Open <code>config.php</code> and add your MySQL root password:<br>
<code>define(\'DB_PASS\', \'your_password_here\');</code></div>
<div class="step"><strong>Option B — Reset root to no password:</strong><br>
Open <a href="http://localhost/phpmyadmin" target="_blank">phpMyAdmin</a> &rarr; User Accounts &rarr; root &rarr; Edit &rarr; Change password &rarr; leave blank &rarr; Save.</div>
<div class="step"><strong>Option C — XAMPP Shell:</strong><br>
<code>mysqladmin -u root -p password ""</code></div>
<p style="font-size:12px;color:#9ca3af;margin-top:16px">MySQL said: ' . htmlspecialchars($e->getMessage()) . '</p>
</div></body></html>');

            } elseif ($code == 1049) {
                // Database does not exist — redirect to installer
                if (basename($_SERVER['PHP_SELF']) !== 'install.php') {
                    header('Location: install.php'); exit;
                }
            } else {
                die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>DB Error</title>
<style>body{font-family:Arial,sans-serif;background:#1a1a2e;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fff;border-radius:12px;padding:40px;max-width:500px;width:90%}h2{color:#ef4444}a{color:#3b82f6}</style>
</head><body><div class="box">
<h2>Database Error</h2>
<p>Could not connect. Have you run <a href="install.php">install.php</a>?</p>
<p style="font-size:12px;color:#9ca3af">' . htmlspecialchars($e->getMessage()) . '</p>
</div></body></html>');
            }
        }
    }
    return $pdo;
}

// ── Ρυθμίσεις ασφαλείας session ──────────────────────────────────────────────
// Αποτρέπουμε XSS access στο session cookie και περιορίζουμε σε HTTP μόνο.
// session.use_strict_mode: αρνείται sessions με άγνωστα IDs (session fixation).
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
// Αν το site τρέχει με HTTPS (production), αποκόμμεντάρετε την επόμενη γραμμή:
// ini_set('session.cookie_secure', 1);

session_start();

function isLoggedIn() { return isset($_SESSION['user_id']); }
function isAdmin() { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function isEmployee() { return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','employee']); }

function requireLogin() {
    if (!isLoggedIn()) { header('Location: index.php'); exit; }
}
function requireEmployee() {
    requireLogin();
    if (!isEmployee()) { header('Location: index.php'); exit; }
}
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) { header('Location: dashboard.php'); exit; }
}

function flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
function getFlash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ── CSRF Protection ──────────────────────────────────────────────────────────
/**
 * Παράγει (ή επιστρέφει υπάρχον) CSRF token για τη συνεδρία.
 * Αποθηκεύεται στο session — δεν εκτίθεται στο URL ποτέ.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Επιστρέφει έτοιμο hidden input για χρήση μέσα σε φόρμες:
 *   echo csrfField();
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

/**
 * Επαληθεύει το CSRF token που ήρθε από POST.
 * Αν αποτύχει, σταματά αμέσως με HTTP 403.
 * Καλείται στην αρχή κάθε POST handler.
 */
function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Μη έγκυρο αίτημα (CSRF). Παρακαλώ φορτώστε ξανά τη σελίδα.');
    }
}

// ── Login Rate Limiting ──────────────────────────────────────────────────────
/**
 * Ελέγχει αν η IP έχει ξεπεράσει το όριο αποτυχημένων προσπαθειών σύνδεσης.
 * Χρησιμοποιεί session για αποθήκευση — δεν απαιτεί πρόσθετο πίνακα DB.
 *
 * Όρια: 5 αποτυχίες → 15 λεπτά lockout.
 */
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 15 * 60); // 15 λεπτά

function loginCheckRateLimit(string $ip): bool {
    $key = 'login_attempts_' . md5($ip);
    $now = time();

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_at' => $now, 'locked_until' => 0];
    }

    $data = &$_SESSION[$key];

    // Αν υπάρχει ενεργό lockout, το ελέγχουμε πρώτα
    if ($data['locked_until'] > $now) {
        return false; // Locked
    }

    // Αν έχει περάσει το παράθυρο παρακολούθησης, μηδενίζουμε
    if (($now - $data['first_at']) > LOGIN_LOCKOUT_SECONDS) {
        $data = ['count' => 0, 'first_at' => $now, 'locked_until' => 0];
    }

    return true; // OK, επιτρέπεται η προσπάθεια
}

function loginRecordFailure(string $ip): void {
    $key  = 'login_attempts_' . md5($ip);
    $now  = time();
    $data = &$_SESSION[$key];
    $data['count']++;
    if ($data['count'] >= LOGIN_MAX_ATTEMPTS) {
        $data['locked_until'] = $now + LOGIN_LOCKOUT_SECONDS;
    }
}

function loginClearAttempts(string $ip): void {
    unset($_SESSION['login_attempts_' . md5($ip)]);
}

function loginRemainingSeconds(string $ip): int {
    $key = 'login_attempts_' . md5($ip);
    if (!isset($_SESSION[$key])) return 0;
    return max(0, $_SESSION[$key]['locked_until'] - time());
}

// ── cover_url Validation ─────────────────────────────────────────────────────
/**
 * Επαληθεύει ότι το cover_url είναι:
 *  - κενό (αποδεκτό), ή
 *  - απόλυτο URL με scheme http/https (αποτρέπει javascript:, data:, κ.λπ.)
 *
 * Επιστρέφει το καθαρό URL ή κενό string αν δεν είναι έγκυρο.
 */
function sanitizeCoverUrl(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    // Επιτρέπουμε μόνο http:// και https://
    if (!preg_match('#^https?://#i', $url)) return '';
    // Βασική επαλήθευση URL μορφής
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    return $url;
}
?>