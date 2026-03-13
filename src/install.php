<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);
// ============================================================
//  Εκτελείται ΜΙΑ ΦΟΡΑ για να δημιουργήσει τη βάση από το μηδέν.
//
//  ΔΙΑΓΡΑΨΤΕ ΤΟ ΑΡΧΕΙΟ μετά την επιτυχή εκτέλεση —
//      αν παραμείνει στον server, οποιοσδήποτε μπορεί να
//      τρέξει ξανά την εγκατάσταση και να σβήσει τη βάση.
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'u494116011_site');
define('DB_PASS', 'Yl2fDoC6f9]');         
define('DB_NAME', 'u494116011_site');
define('TABLE_PREFIX', 'lib_');

/* ── Βοηθητικές συναρτήσεις εμφάνισης αποτελεσμάτων ── */
function ok(string $msg): void {
    echo "<p style='color:#16a34a;font-family:sans-serif;margin:4px 0'>[+] $msg</p>\n";
}
function info(string $msg): void {
    echo "<p style='color:#2563eb;font-family:sans-serif;margin:4px 0'>[i] $msg</p>\n";
}
function fail(string $msg): void {
    echo "<p style='color:#dc2626;font-family:sans-serif;font-weight:bold;margin:4px 0'>[x] $msg</p>\n";
}

try {
    /* ── 1. Σύνδεση χωρίς επιλογή βάσης ─────────────────────────────────────
       Δεν δίνουμε dbname στο DSN γιατί η βάση μπορεί να μην υπάρχει ακόμα.
       Τη δημιουργούμε στο βήμα 2 και μετά κάνουμε USE.
    ──────────────────────────────────────────────────────────────────────── */
	$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
	);
	echo "<p>✅ Σύνδεση OK</p>";

    echo "<h2 style='font-family:sans-serif;border-bottom:2px solid #e5e7eb;padding-bottom:8px'>
            Full System Database Installer Script</h2>\n";

    /* ── 2. Δημιουργία βάσης ──────────────────────────────────────────────── */
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    ok("Database <strong>" . DB_NAME . "</strong> ready");

    /* ── 3. Απενεργοποίηση FK checks κατά τη δημιουργία ──────────────────────
       Χρειάζεται γιατί κάνουμε DROP/CREATE πινάκων που έχουν FKs μεταξύ τους.
       Χωρίς αυτό, το DROP θα αποτύγχανε αν υπάρχουν ήδη FKs που αναφέρονται
       στον πίνακα που προσπαθούμε να διαγράψουμε. Επανενεργοποιείται στο τέλος.
    ──────────────────────────────────────────────────────────────────────── */
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    /* ════════════════════════════════════════════════════════
       TABLE: users
       AUTO_INCREMENT=5 — ταιριάζει με το live dump
    ════════════════════════════════════════════════════════ */
    $pdo->exec("DROP TABLE IF EXISTS `" . TABLE_PREFIX . "users`");
    $pdo->exec("
    CREATE TABLE `" . TABLE_PREFIX . "users` (
        `id`         INT            NOT NULL AUTO_INCREMENT,
        `username`   VARCHAR(100)   COLLATE utf8mb4_unicode_ci NOT NULL,
        `password`   VARCHAR(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
        `role`       ENUM('admin','employee','user')
                     COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
        `active`     TINYINT(1)     NOT NULL DEFAULT '1',
        `created_at` DATETIME       DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`)
    ) ENGINE=InnoDB AUTO_INCREMENT=5
      DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    ok("Table <code>users</code> created");

    /*
     * Seed default users — οι κωδικοί hashed με bcrypt κατά την εγκατάσταση.
     * Δεν αποθηκεύουμε plaintext κωδικούς στη βάση ποτέ.
     *
     * ΣΗΜΑΝΤΙΚΟ: Αλλάξτε τους κωδικούς αμέσως μετά την πρώτη σύνδεση
     * μέσω του UI διαχείρισης χρηστών — τα defaults είναι γνωστά.
     */
    $adminHash    = password_hash('gplmsadm123',    PASSWORD_DEFAULT);
    $employeeHash = password_hash('gplmslib123', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
    INSERT INTO `" . TABLE_PREFIX . "users` 
        (`id`, `username`, `password`, `role`, `active`, `created_at`) 
    VALUES
        (1, 'admin',    ?, 'admin',    1, '2026-02-18 11:15:29'),
        (2, 'employee', ?, 'employee', 1, '2026-02-18 11:15:29')
	");
	$stmt->execute([$adminHash, $employeeHash]);
    ok("Default users inserted &nbsp;<code>gplmsadm / gplmsadm123</code> &nbsp;&amp;&nbsp; <code>gplmslib / gplmslib123</code>");

    /* ════════════════════════════════════════════════════════
       TABLE: categories
       AUTO_INCREMENT=34 — ταιριάζει με το live dump
       Seed: 3 πραγματικές κατηγορίες βιβλιοθήκης
    ════════════════════════════════════════════════════════ */
    $pdo->exec("DROP TABLE IF EXISTS `" . TABLE_PREFIX . "categories`");
    $pdo->exec("
    CREATE TABLE `" . TABLE_PREFIX . "categories` (
        `id`          INT          NOT NULL AUTO_INCREMENT,
        `name`        VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
        `description` TEXT         COLLATE utf8mb4_unicode_ci,
        `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB AUTO_INCREMENT=34
      DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 3 αληθινές κατηγορίες βιβλιοθήκης ως αφετηρία.
     * Τα IDs ξεκινούν από 34 λόγω AUTO_INCREMENT=34 (συνέχεια του live dump).
     * Προσθέστε περισσότερες κατηγορίες από τη διεπαφή διαχείρισης.
     */
    $pdo->exec("
    INSERT INTO `" . TABLE_PREFIX . "categories` (`name`, `description`, `created_at`) VALUES
    ('Ιστορία',    'Ιστορικά βιβλία, μελέτες και αναλύσεις',                 NOW()),
    ('Λογοτεχνία', 'Μυθιστορήματα, ποίηση, διηγήματα και λογοτεχνικά έργα', NOW()),
    ('Επιστήμη',   'Φυσικές επιστήμες, τεχνολογία και επιστημονικά κείμενα', NOW())
    ");
    ok("Table <code>categories</code> created &amp; seeded (3 κατηγορίες: Ιστορία, Λογοτεχνία, Επιστήμη)");

    /* ════════════════════════════════════════════════════════
       TABLE: publishers
       AUTO_INCREMENT=44 — ταιριάζει με το live dump
       Seed: 3 πραγματικοί εκδότες
    ════════════════════════════════════════════════════════ */
    $pdo->exec("DROP TABLE IF EXISTS `" . TABLE_PREFIX . "publishers`");
    $pdo->exec("
    CREATE TABLE `" . TABLE_PREFIX . "publishers` (
        `id`         INT          NOT NULL AUTO_INCREMENT,
        `name`       VARCHAR(200) COLLATE utf8mb4_unicode_ci NOT NULL,
        `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB AUTO_INCREMENT=44
      DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 3 γνωστοί Έλληνες εκδότες ως αφετηρία.
     * Τα IDs ξεκινούν από 44 λόγω AUTO_INCREMENT=44 (συνέχεια του live dump).
     * Προσθέστε περισσότερους εκδότες από τη διεπαφή διαχείρισης.
     */
    $pdo->exec("
    INSERT INTO `" . TABLE_PREFIX . "publishers` (`name`, `created_at`) VALUES
    ('Εκδόσεις Καστανιώτη', NOW()),
    ('Εκδόσεις Πατάκη',     NOW()),
    ('Εκδόσεις Μεταίχμιο',  NOW())
    ");
    ok("Table <code>publishers</code> created &amp; seeded (3 εκδότες: Καστανιώτης, Πατάκης, Μεταίχμιο)");

    /* ════════════════════════════════════════════════════════
       TABLE: books
       AUTO_INCREMENT=190 — ταιριάζει με το live dump
       Seed: 3 βιβλία, 1 περιοδικό, 1 χειρόγραφο
    ════════════════════════════════════════════════════════ */
    $pdo->exec("DROP TABLE IF EXISTS `" . TABLE_PREFIX . "books`");
    $pdo->exec("
    CREATE TABLE `" . TABLE_PREFIX . "books` (
        `id`           INT          NOT NULL AUTO_INCREMENT,
        `title`        VARCHAR(300) COLLATE utf8mb4_unicode_ci NOT NULL,
        `author`       VARCHAR(200) COLLATE utf8mb4_unicode_ci NOT NULL,
        `isbn`         VARCHAR(30)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `type`         ENUM(
                         'Βιβλίο',
                         'Περιοδικό',
                         'Εφημερίδα',
                         'Χειρόγραφο',
                         'Ημερολόγιο',
                         'Επιστολή',
                         'Άλλο'
                       ) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Βιβλίο',
        `category_id`  INT          DEFAULT NULL,
        `publisher_id` INT          DEFAULT NULL,
        `year`         INT          DEFAULT NULL,
        `language`     VARCHAR(50)  COLLATE utf8mb4_unicode_ci DEFAULT 'Ελληνικά',
        `pages`        INT          DEFAULT NULL,
        `edition`      VARCHAR(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `volume`       VARCHAR(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `location`     VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `description`  TEXT         COLLATE utf8mb4_unicode_ci,
        `cover_url`    VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `status`       ENUM('Διαθέσιμο','Μη Διαθέσιμο','Σε Επεξεργασία')
                       COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Διαθέσιμο',
        `is_public`    TINYINT(1)   DEFAULT '1',
        `created_by`   INT          DEFAULT NULL,
        `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `category_id`          (`category_id`),
        KEY `publisher_id`         (`publisher_id`),
        KEY `idx_books_created_by` (`created_by`),
        -- ON DELETE SET NULL: αν διαγραφεί κατηγορία/εκδότης, το βιβλίο παραμένει
        CONSTRAINT `books_ibfk_1` FOREIGN KEY (`category_id`)
            REFERENCES `" . TABLE_PREFIX . "categories` (`id`) ON DELETE SET NULL,
        CONSTRAINT `books_ibfk_2` FOREIGN KEY (`publisher_id`)
            REFERENCES `" . TABLE_PREFIX . "publishers` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB AUTO_INCREMENT=190
      DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    ok("Table <code>books</code> created");

    /*
     * Seed books: 3 βιβλία, 1 περιοδικό, 1 χειρόγραφο.
     * category_id / publisher_id αντιστοιχούν στα seed IDs παραπάνω:
     *   34=Ιστορία, 35=Λογοτεχνία, 36=Επιστήμη
     *   44=Καστανιώτη, 45=Πατάκη, 46=Μεταίχμιο
     * Το χειρόγραφο δεν έχει εκδότη (publisher_id=NULL) — λογικό για χφ.
     * created_by=1 (admin)
     */
    $cols = '(`title`,`author`,`isbn`,`type`,`category_id`,`publisher_id`,`year`,`language`,'
          . '`pages`,`edition`,`volume`,`location`,`description`,`cover_url`,'
          . '`status`,`is_public`,`created_by`,`created_at`,`updated_at`)';
    $ph   = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())';

    $stmt = $pdo->prepare("INSERT INTO `" . TABLE_PREFIX . "books` $cols VALUES $ph,$ph,$ph,$ph,$ph");
    $stmt->execute([
        // 1. Βιβλίο — Ιστορία
        'Ιστορία του Ελληνικού Έθνους', 'Κωνσταντίνος Παπαρρηγόπουλος',
        '9789600301234', 'Βιβλίο', 34, 44, 1860, 'Ελληνικά', 512, 'Α΄', 'Τόμος Α΄', 'Ράφι Α1',
        'Θεμελιώδες ιστορικό έργο που καλύπτει την ιστορία του ελληνικού έθνους από την αρχαιότητα έως τη σύγχρονη εποχή.',
        '', 'Διαθέσιμο', 1, 1,
        // 2. Βιβλίο — Λογοτεχνία
        'Ζορμπάς ο Έλληνας', 'Νίκος Καζαντζάκης',
        '9789600412251', 'Βιβλίο', 35, 45, 1946, 'Ελληνικά', 368, 'Β΄', '', 'Ράφι Β2',
        'Ένα από τα πιο γνωστά ελληνικά μυθιστορήματα, με ήρωα τον ανεξάρτητο και ζωντανό Αλέξη Ζορμπά.',
        '', 'Διαθέσιμο', 1, 1,
        // 3. Βιβλίο — Επιστήμη
        'Εισαγωγή στη Φυσική', 'Δημήτριος Νανόπουλος',
        '9789600523478', 'Βιβλίο', 36, 46, 2005, 'Ελληνικά', 624, 'Γ΄', '', 'Ράφι Γ3',
        'Ολοκληρωμένο εισαγωγικό εγχειρίδιο φυσικής για φοιτητές και ερευνητές.',
        '', 'Διαθέσιμο', 1, 1,
        // 4. Περιοδικό — Ιστορία
        'Αρχαιολογία & Ιστορία', 'Σύνταξη Περιοδικού',
        null, 'Περιοδικό', 34, 44, 2023, 'Ελληνικά', 96, '', 'Τεύχος 47', 'Ράφι Δ1',
        'Διμηνιαίο περιοδικό αφιερωμένο στην ελληνική αρχαιολογία και ιστορία με ανασκαφές, ευρήματα και επιστημονικά άρθρα.',
        '', 'Διαθέσιμο', 1, 1,
        // 5. Χειρόγραφο — χωρίς εκδότη, Μη Διαθέσιμο (εκθεσιακό)
        'Χειρόγραφες Σημειώσεις Βυζαντινής Μουσικής', 'Άγνωστος Μοναχός',
        null, 'Χειρόγραφο', 35, null, 1780, 'Ελληνικά', 48, '', '', 'Βιτρίνα Α',
        'Σπάνιο χειρόγραφο με βυζαντινές μουσικές σημειώσεις και ψαλτικές παρτιτούρες του 18ου αιώνα.',
        '', 'Μη Διαθέσιμο', 1, 1,
    ]);
    ok("Table <code>books</code> seeded (3 βιβλία, 1 περιοδικό, 1 χειρόγραφο)");

    /* ════════════════════════════════════════════════════════
       TABLE: messages
       AUTO_INCREMENT=7 — ταιριάζει με το live dump
       Δύο FKs με διαφορετική συμπεριφορά ON DELETE:
       - from_user → SET NULL: αν διαγραφεί ο αποστολέας, το μήνυμα παραμένει
       - to_user   → CASCADE:  αν διαγραφεί ο παραλήπτης, τα μηνύματά του σβήνουν
    ════════════════════════════════════════════════════════ */
    $pdo->exec("DROP TABLE IF EXISTS `" . TABLE_PREFIX . "messages`");
    $pdo->exec("
    CREATE TABLE `" . TABLE_PREFIX . "messages` (
        `id`         INT          NOT NULL AUTO_INCREMENT,
        `from_user`  INT          DEFAULT NULL,
        `to_user`    INT          DEFAULT NULL,
        `subject`    VARCHAR(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `body`       TEXT         COLLATE utf8mb4_unicode_ci,
        `is_read`    TINYINT(1)   DEFAULT '0',
        `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `from_user` (`from_user`),
        KEY `to_user`   (`to_user`),
        CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`from_user`)
            REFERENCES `" . TABLE_PREFIX . "users` (`id`) ON DELETE SET NULL,
        CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`to_user`)
            REFERENCES `" . TABLE_PREFIX . "users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB AUTO_INCREMENT=7
      DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    ok("Table <code>messages</code> created");

    /* ════════════════════════════════════════════════════════
       TABLE: audit_log
       AUTO_INCREMENT=301 — ταιριάζει με το live dump
       Καθαρή εκκίνηση χωρίς ιστορικά logs
    ════════════════════════════════════════════════════════ */
    $pdo->exec("DROP TABLE IF EXISTS `" . TABLE_PREFIX . "audit_log`");
    $pdo->exec("
    CREATE TABLE `" . TABLE_PREFIX . "audit_log` (
        `id`          INT          NOT NULL AUTO_INCREMENT,
        `user_id`     INT          DEFAULT NULL,
        `action`      VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `target_type` VARCHAR(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `target_id`   INT          DEFAULT NULL,
        `details`     TEXT         COLLATE utf8mb4_unicode_ci,
        `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB AUTO_INCREMENT=301
      DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    ok("Table <code>audit_log</code> created (καθαρή εκκίνηση — χωρίς ιστορικά)");

    /* ── Επανενεργοποίηση FK checks ── */
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    ok("Foreign key checks re-enabled");

    /* ── Ολοκλήρωση εγκατάστασης ── */
    echo "
    <hr style='margin:20px 0'>
    <h3 style='font-family:sans-serif;color:#16a34a'>Εγκατάσταση ολοκληρώθηκε!</h3>

    <table style='font-family:sans-serif;font-size:14px;border-collapse:collapse;margin-bottom:16px'>
        <thead>
            <tr>
                <th style='text-align:left;padding:6px 20px 6px 0;border-bottom:1px solid #e5e7eb;color:#6b7280'>Χρήστης</th>
                <th style='text-align:left;padding:6px 20px 6px 0;border-bottom:1px solid #e5e7eb;color:#6b7280'>Κωδικός</th>
                <th style='text-align:left;padding:6px 0;border-bottom:1px solid #e5e7eb;color:#6b7280'>Ρόλος</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style='padding:6px 20px 6px 0'><strong>admin</strong></td>
                <td style='padding:6px 20px 6px 0'>gplmsadm123</td>
                <td style='padding:6px 0'><span style='background:#1a1a2e;color:#e8c547;padding:2px 8px;border-radius:10px;font-size:12px'>Admin</span></td>
            </tr>
            <tr>
                <td style='padding:6px 20px 6px 0'><strong>employee</strong></td>
                <td style='padding:6px 20px 6px 0'>gplmslib123</td>
                <td style='padding:6px 0'><span style='background:#3b82f6;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px'>Employee</span></td>
            </tr>
        </tbody>
    </table>

    <table style='font-family:sans-serif;font-size:14px;border-collapse:collapse;margin-bottom:16px'>
        <thead>
            <tr>
                <th style='text-align:left;padding:6px 20px 6px 0;border-bottom:1px solid #e5e7eb;color:#6b7280'>Κατηγορίες (seed)</th>
                <th style='text-align:left;padding:6px 20px 6px 0;border-bottom:1px solid #e5e7eb;color:#6b7280'>Εκδότες (seed)</th>
                <th style='text-align:left;padding:6px 0;border-bottom:1px solid #e5e7eb;color:#6b7280'>Βιβλία (seed)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style='padding:6px 20px 6px 0;vertical-align:top'>Ιστορία<br>Λογοτεχνία<br>Επιστήμη</td>
                <td style='padding:6px 20px 6px 0;vertical-align:top'>Εκδόσεις Καστανιώτη<br>Εκδόσεις Πατάκη<br>Εκδόσεις Μεταίχμιο</td>
                <td style='padding:6px 0;vertical-align:top'>
                    Ιστορία του Ελληνικού Έθνους <em style='color:#9ca3af'>(Βιβλίο)</em><br>
                    Ζορμπάς ο Έλληνας <em style='color:#9ca3af'>(Βιβλίο)</em><br>
                    Εισαγωγή στη Φυσική <em style='color:#9ca3af'>(Βιβλίο)</em><br>
                    Αρχαιολογία &amp; Ιστορία <em style='color:#9ca3af'>(Περιοδικό)</em><br>
                    Χφ. Βυζαντινής Μουσικής <em style='color:#9ca3af'>(Χειρόγραφο)</em>
                </td>
            </tr>
        </tbody>
    </table>

    <div style='font-family:sans-serif;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400e;margin-bottom:16px'>
        <strong>Αλλάξτε τους κωδικούς αμέσως</strong> μετά την πρώτη σύνδεση,
        έπειτα <strong>διαγράψτε αυτό το αρχείο</strong> από τον server.
    </div>

    <p style='font-family:sans-serif'>
        <a href='index.php' style='margin-right:16px'>→ Μετάβαση στη Βιβλιοθήκη</a>
        <a href='login.php'>→ Σύνδεση</a>
    </p>
    ";

} catch (PDOException $e) {
    try { if(isset($pdo)) $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $ignored) {}
    fail("Database error: " . htmlspecialchars($e->getMessage()));
	} catch (Exception $e) {
    fail("General error: " . htmlspecialchars($e->getMessage()));
	}
?>