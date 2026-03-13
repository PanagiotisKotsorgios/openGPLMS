<?php
require 'config.php';
requireAdmin(); // Μόνο admins έχουν πρόσβαση στο αρχείο καταγραφής
$db = getDB();


/*
 * Δημιουργεί το "παράθυρο" σελίδων για το pagination.
 * Πάντα συμπεριλαμβάνει την πρώτη/τελευταία σελίδα και $radius σελίδες
 * γύρω από την τρέχουσα. Εκεί που υπάρχει κενό, βάζει '…'.
 * Παράδειγμα με current=5, total=10, radius=2: [1, …, 3, 4, 5, 6, 7, …, 10]
 */
function pageWindow(int $current, int $total, int $radius = 2): array {
    if ($total <= 1) return [1];
    $pages = [1, $total];
    for ($i = $current - $radius; $i <= $current + $radius; $i++) {
        if ($i >= 1 && $i <= $total) $pages[] = $i;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $out = [];
    $prev = null;
    foreach ($pages as $p) {
        if ($prev !== null && $p > $prev + 1) $out[] = '…'; // κενό στη σειρά
        $out[] = $p;
        $prev = $p;
    }
    return $out;
}

/*
 * Χτίζει το query string της URL διατηρώντας όλες τις υπάρχουσες παραμέτρους
 * εκτός από αυτές που ορίζονται στο $override.
 * Null τιμή σε $override = αφαίρεση της παραμέτρου εντελώς.
 * Χρησιμοποιείται σε links pagination/φίλτρων για να μην χάνονται τα ενεργά φίλτρα.
 */
function qsKeep(array $override = []): string {
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}

/* Μετάφραση action slug → ελληνική ετικέτα για εμφάνιση στο UI */
function actionLabel(string $a): string {
    $map = [
        'login'          => 'Σύνδεση',
        'logout'         => 'Αποσύνδεση',
        'create'         => 'Δημιουργία',
        'create_user'    => 'Δημιουργία Χρήστη',
        'edit'           => 'Επεξεργασία',
        'delete'         => 'Διαγραφή',
        'delete_user'    => 'Διαγραφή Χρήστη',
        'change_role'    => 'Αλλαγή Ρόλου',
        'reset_password' => 'Επαναφορά Κωδικού',
        'send_message'   => 'Αποστολή Μηνύματος',
        'csv_import'     => 'Εισαγωγή CSV',
        'export'         => 'Εξαγωγή',
        'import'         => 'Εισαγωγή',
    ];
    return $map[$a] ?? $a; // αν δεν υπάρχει στον χάρτη, επιστρέφει ως έχει
}

/* Μετάφραση target_type → ελληνική ετικέτα για εμφάνιση στο UI */
function targetLabel(string $t): string {
    $map = [
        'book'      => 'Βιβλίο',
        'user'      => 'Χρήστης',
        'category'  => 'Κατηγορία',
        'publisher' => 'Εκδότης',
        'audit'     => 'Καταγραφή',
        'system'    => 'Σύστημα',
    ];
    return $map[$t] ?? $t;
}

/* ─────────────────────────────────────────────────────────────
   BACKUP (ZIP: αρχεία project + dump βάσης δεδομένων)
   Εκτελείται ΠΡΙΝ οποιοδήποτε HTML output για να μπορεί
   να στείλει σωστά τα headers του ZIP download.
   Ενεργοποίηση: POST με action=backup + valid CSRF token.
   Χρήση GET αποφεύγεται — ένα απλό link/email θα μπορούσε
   να εκκινήσει backup χωρίς πρόθεση (CSRF via GET).

   Σημειώσεις:
   - Απαιτεί ενεργοποιημένη την επέκταση ZipArchive.
   - Το DB dump χρησιμοποιεί mysqldump μέσω exec().
   - Αν ο hosting αποκλείει το exec(), το ZIP θα περιέχει
     μόνο τα αρχεία χωρίς dump βάσης.
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    verifyCsrf();
    $ts = date('Ymd_His');
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

    $rootPath = realpath(__DIR__); // ριζικός φάκελος του project
    $zipPath  = $backupDir . "/backup_{$ts}.zip";
    $sqlPath  = $backupDir . "/db_{$ts}.sql";

    // Ανάκτηση credentials βάσης από constants ή environment variables
    $dbHost = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $dbName = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
    $dbUser = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
    $dbPass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');

    // 1) Δημιουργία DB dump μέσω mysqldump (best-effort — δεν σπάει αν αποτύχει)
    $dumpOk = false;
    if ($dbName && $dbUser) {
        if (function_exists('exec')) {
            $cmd = 'mysqldump --no-tablespaces --single-transaction -h ' . escapeshellarg($dbHost)
                 . ' -u ' . escapeshellarg($dbUser)
                 . ' ' . ($dbPass !== '' ? ('-p' . escapeshellarg($dbPass)) : '')
                 . ' ' . escapeshellarg($dbName)
                 . ' > ' . escapeshellarg($sqlPath) . ' 2>&1';
            @exec($cmd, $out, $ret);
            // Επιτυχία μόνο αν το αρχείο δημιουργήθηκε και δεν είναι κενό
            if ($ret === 0 && file_exists($sqlPath) && filesize($sqlPath) > 0) $dumpOk = true;
        }
    }

    // 2) Δημιουργία ZIP με όλα τα αρχεία του project
    if (!class_exists('ZipArchive')) {
        flash('Δεν είναι διαθέσιμη η επέκταση ZipArchive στον server.','error');
        header('Location: audit.php'); exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        flash('Αποτυχία δημιουργίας ZIP αντιγράφου ασφαλείας.','error');
        header('Location: audit.php'); exit;
    }

    // Αναδρομική προσθήκη αρχείων, εξαιρώντας τον φάκελο backups
    // για να μην εμφωλεύονται backups μέσα σε backups
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        $filePath = $file->getRealPath();
        if (!$filePath) continue;

        if (strpos($filePath, $backupDir . DIRECTORY_SEPARATOR) === 0) continue;

        // Σχετικό path μέσα στο ZIP (χωρίς τον απόλυτο δρόμο του server)
        $relPath = ltrim(str_replace($rootPath, '', $filePath), DIRECTORY_SEPARATOR);
        if ($file->isDir()) continue;

        $zip->addFile($filePath, $relPath);
    }

    // Προσθήκη SQL dump ή ενημερωτικού README αν απέτυχε το dump
    if ($dumpOk) {
        $zip->addFile($sqlPath, 'database/db_dump.sql');
    } else {
        $zip->addFromString('database/README.txt',
            "DB dump was not created.\n".
            "Reason: missing DB credentials in constants/env OR mysqldump/exec not available.\n".
            "Solution: define DB_HOST/DB_NAME/DB_USER/DB_PASS in config.php or export via phpMyAdmin.\n"
        );
    }

    $zip->close();

    // Καταγραφή της ενέργειας backup στο audit log
    try {
        $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
           ->execute([$_SESSION['user_id'] ?? null, 'backup', 'system', null, 'System backup created']);
    } catch (Exception $e) {}

    // Αποστολή ZIP στον browser ως download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="backup_' . $ts . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   CSV EXPORT — εξαγωγή ολόκληρου του audit log χωρίς φίλτρα.
   Πρέπει να εκτελεστεί ΠΡΙΝ οποιοδήποτε HTML output
   για να μπορούν να σταλούν τα σωστά headers.
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    verifyCsrf();
    $all = $db->query("SELECT a.created_at, u.username, a.action, a.target_type, a.target_id, a.details
                       FROM " . TABLE_PREFIX . "audit_log a LEFT JOIN " . TABLE_PREFIX . "users u ON a.user_id=u.id
                       ORDER BY a.created_at DESC");
    $rows = $all->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM για σωστή εμφάνιση στο Excel
    fputcsv($out, ['Ημ/νία','Χρήστης','Ενέργεια','Τύπος','ID','Λεπτομέρειες']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   ΦΙΛΤΡΑ + PAGINATION
   Παράμετροι GET:
     filter   → φιλτράρισμα βάσει ακριβούς τιμής action
     search   → ελεύθερη αναζήτηση σε username/details/target
     per_page → εγγραφές ανά σελίδα (10, 25, 50)
     page     → τρέχουσα σελίδα
───────────────────────────────────────────────────────────── */
$filter = trim($_GET['filter'] ?? '');
$search = trim($_GET['search'] ?? '');

$perOptions = [10,25,50];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $perOptions, true)) $perPage = 10;

$page = max(1, (int)($_GET['page'] ?? 1));

// Δυναμική κατασκευή WHERE clause βάσει ενεργών φίλτρων
$where = ['1=1'];
$params = [];

if ($filter !== '') {
    $where[] = "a.action = ?";
    $params[] = $filter;
}
if ($search !== '') {
    // Αναζήτηση σε πολλαπλά πεδία ταυτόχρονα
    $where[] = "(u.username LIKE ? OR a.details LIKE ? OR a.target_type LIKE ? OR CAST(a.target_id AS CHAR) LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereStr = implode(' AND ', $where);

// Συνολικός αριθμός εγγραφών με τα ενεργά φίλτρα (για το pagination)
$totalStmt = $db->prepare("SELECT COUNT(*) FROM " . TABLE_PREFIX . "audit_log a LEFT JOIN " . TABLE_PREFIX . "users u ON a.user_id=u.id WHERE $whereStr");
$totalStmt->execute($params);
$totalFiltered = (int)$totalStmt->fetchColumn();

// Υπολογισμός pagination — αν η σελίδα ξεπερνά το max, επαναφορά στην τελευταία
$pages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

// Ανάκτηση εγγραφών της τρέχουσας σελίδας
$q = "
    SELECT a.*, u.username
    FROM " . TABLE_PREFIX . "audit_log a
    LEFT JOIN " . TABLE_PREFIX . "users u ON a.user_id=u.id
    WHERE $whereStr
    ORDER BY a.created_at DESC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $db->prepare($q);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Στατιστικά για το dropdown φίλτρου και τις κάρτες συνόλων (χωρίς φίλτρα)
$stats = $db->query("SELECT action, COUNT(*) as cnt FROM " . TABLE_PREFIX . "audit_log GROUP BY action ORDER BY cnt DESC")->fetchAll();
$totalEvents = (int)$db->query("SELECT COUNT(*) FROM " . TABLE_PREFIX . "audit_log")->fetchColumn();

$pageTitle  = 'Αρχείο Καταγραφής';
$activePage = 'audit';
include 'layout_admin.php';
?>

<!-- Admin topbar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding:10px 16px;background:var(--panel);border:1px solid var(--border);border-radius:9px;box-shadow:var(--shadow-sm)">
    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted)">
        <i class="bi bi-grid-1x2" style="color:var(--gold)"></i>
        <a href="dashboard.php" style="color:var(--muted);text-decoration:none">Dashboard</a>
        <i class="bi bi-chevron-right" style="font-size:9px;opacity:0.4"></i>
        <span style="color:var(--ink);font-weight:600"><?= h($pageTitle ?? '') ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
        <a href="catalog_public.php" target="_blank"
           style="font-size:11px;color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid var(--border);border-radius:14px"
           onmouseover="this.style.borderColor='var(--gold)';this.style.color='var(--gold)'"
           onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
            <i class="bi bi-globe"></i> Public View
        </a>
    </div>
</div>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <h1>Αντίγραφο Ασφαλείας &amp; Αρχείο Καταγραφής</h1>
        <p>Παρακολούθηση όλων των ενεργειών στο σύστημα</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <!-- qsKeep διατηρεί τα ενεργά φίλτρα στο URL και προσθέτει την παράμετρο backup=1 -->
<button type="button" class="btn btn-outline-gold" onclick="openBackupModal()">
    <i class="bi bi-shield-check me-1"></i> Δημιουργία Αντιγράφου Ασφαλείας
</button>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="export">
            <button type="submit" class="btn btn-gold">
                <i class="bi bi-download me-1"></i> Εξαγωγή CSV
            </button>
        </form>
    </div>
</div>

<!-- Κάρτες στατιστικών: σύνολο + top 3 πιο συχνές ενέργειες -->
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="stat-card text-center">
            <div class="stat-value"><?= $totalEvents ?></div>
            <div class="stat-label">Σύνολο Ενεργειών</div>
        </div>
    </div>
    <?php foreach (array_slice($stats, 0, 3) as $s): ?>
    <div class="col-sm-3">
        <div class="stat-card text-center">
            <div class="stat-value" style="font-size:24px"><?= (int)$s['cnt'] ?></div>
            <div class="stat-label"><?= h(actionLabel($s['action'])) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Φόρμα φίλτρων — GET για να μπορούν να γίνουν bookmark τα αποτελέσματα -->
<div class="card-panel mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label mb-1">Αναζήτηση</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Χρήστης, λεπτομέρειες, τύπος, ID..." value="<?= h($search) ?>">
            </div>
        </div>

        <div class="col-md-3">
            <label class="form-label mb-1">Φίλτρο ενέργειας</label>
            <select name="filter" class="form-select">
                <option value="">Όλες οι ενέργειες</option>
                <?php foreach ($stats as $s): ?>
                    <option value="<?= h($s['action']) ?>" <?= $filter===$s['action']?'selected':'' ?>>
                        <?= h(actionLabel($s['action'])) ?> (<?= (int)$s['cnt'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label mb-1">Εμφάνιση</label>
            <!-- Auto-submit με onchange για άμεση αλλαγή εγγραφών/σελίδα -->
            <select name="per_page" class="form-select" onchange="this.form.submit()">
                <?php foreach ([10,25,50] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?> / σελίδα</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3 d-flex gap-2 align-items-end">
            <button type="submit" class="btn btn-gold">Εφαρμογή</button>
            <?php if ($filter || $search): ?>
                <!-- Εμφανίζεται μόνο όταν υπάρχουν ενεργά φίλτρα -->
                <a href="audit.php" class="btn btn-outline-secondary">Καθαρισμός</a>
            <?php endif; ?>
            <!-- Reset σελίδας στο 1 όταν αλλάζουν τα φίλτρα -->
            <input type="hidden" name="page" value="1">
        </div>

        <div class="col-12">
            <div style="font-size:12px;color:#9ca3af">
                Εμφανίζονται <?= count($logs) ?> εγγραφές (Σελίδα <?= $page ?> / <?= $pages ?>) — Σύνολο αποτελεσμάτων: <?= $totalFiltered ?><?php if (!$filter && !$search) echo " / $totalEvents"; ?>
            </div>
        </div>
    </form>
</div>

<div class="card-panel p-0" style="overflow:hidden">
    <table class="table table-library mb-0">
        <thead>
            <tr>
                <th>Ημ/νία &amp; Ώρα</th>
                <th>Χρήστης</th>
                <th>Ενέργεια</th>
                <th>Αντικείμενο</th>
                <th>Λεπτομέρειες</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
            <tr><td colspan="5" class="text-center text-muted py-5">Κανένα αρχείο καταγραφής.</td></tr>
        <?php endif; ?>

        <?php foreach ($logs as $log):
            // Χρωματική κωδικοποίηση ετικέτας ανά τύπο ενέργειας για γρήγορη οπτική αναγνώριση
            $actionColors = [
                'login'         => 'background:#dcfce7;color:#166534',
                'logout'        => 'background:#f3f4f6;color:#374151',
                'create'        => 'background:#dbeafe;color:#1e40af',
                'create_user'   => 'background:#e0e7ff;color:#3730a3',
                'edit'          => 'background:#fef3c7;color:#92400e',
                'delete'        => 'background:#fee2e2;color:#991b1b',
                'delete_user'   => 'background:#fee2e2;color:#991b1b',
                'change_role'   => 'background:#fce7f3;color:#9d174d',
                'reset_password'=> 'background:#fef3c7;color:#92400e',
                'send_message'  => 'background:#d1fae5;color:#065f46',
                'csv_import'    => 'background:#dbeafe;color:#1e40af',
                'backup'        => 'background:#e0f2fe;color:#075985',
            ];
            $ac = $actionColors[$log['action']] ?? 'background:#f3f4f6;color:#374151';

            $tType = $log['target_type'] ?? '';
            $tId   = $log['target_id'] ? (' #'.$log['target_id']) : '';
        ?>
        <tr>
            <td style="font-size:12px;color:#9ca3af;white-space:nowrap">
                <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
            </td>
            <td>
                <span style="font-weight:600;font-size:13px"><?= h($log['username'] ?? '—') ?></span>
            </td>
            <td>
                <span style="font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;<?= $ac ?>">
                    <?= h(actionLabel($log['action'])) ?>
                </span>
            </td>
            <td style="font-size:12px;color:#374151">
                <?= h(targetLabel($tType) . $tId) ?>
            </td>
            <td style="font-size:12px;color:#6b7280;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= h($log['details'] ?? '') ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination — εμφανίζεται μόνο αν υπάρχουν πάνω από 1 σελίδα -->
<?php if ($pages > 1): ?>
<div class="d-flex justify-content-center mt-3">
    <nav aria-label="Audit pagination">
        <ul class="pagination pagination-sm flex-wrap mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page <= 1 ? '#' : ('?' . qsKeep(['page'=>$page-1])) ?>">&laquo;</a>
            </li>

            <?php foreach (pageWindow($page, $pages, 2) as $p): ?>
                <?php if ($p === '…'): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php else: ?>
                    <li class="page-item <?= $p===$page?'active':'' ?>">
                        <a class="page-link" href="?<?= qsKeep(['page'=>$p]) ?>"
                           style="<?= $p===$page?'background:var(--gold);border-color:var(--gold);color:#1a1a2e':'' ?>">
                           <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page >= $pages ? '#' : ('?' . qsKeep(['page'=>$page+1])) ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
</div>
<?php endif; ?>

<!-- ══ BACKUP WARNING BANNER/MODAL ══ -->
<div id="backupWarningModal" style="
    display:none;
    position:fixed;
    top:0; left:0; right:0; bottom:0;
    z-index:9999;
    background:rgba(0,0,0,0.55);
    align-items:flex-start;
    justify-content:center;
    padding-top:60px;
">
    <div style="
        background:#fff;
        border-radius:12px;
        max-width:520px;
        width:calc(100% - 32px);
        box-shadow:0 20px 60px rgba(0,0,0,0.3);
        overflow:hidden;
        animation: slideDown .25s ease;
    ">
        <!-- Header -->
        <div style="background:#1a1a2e;padding:16px 20px;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:10px;color:#e8c547;font-weight:700;font-size:15px">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:18px"></i>
                Προσοχή — Λειτουργία μη διαθέσιμη
            </div>
            <button onclick="closeBackupModal()" style="background:none;border:none;color:#9ca3af;font-size:22px;cursor:pointer;line-height:1">&times;</button>
        </div>
        <!-- Body -->
        <div style="padding:22px 24px">
            <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#991b1b;display:flex;align-items:center;gap:10px">
                <i class="bi bi-x-octagon-fill" style="font-size:18px;flex-shrink:0"></i>
                <strong>Η λειτουργία δεν είναι διαθέσιμη αυτή τη στιγμή.</strong>
            </div>
            <p style="font-size:13px;color:#374151;margin-bottom:14px;line-height:1.6">
                Η δημιουργία αντιγράφου ασφαλείας <strong>δεν λειτουργεί ορθά</strong> στην τρέχουσα έκδοση του συστήματος και θα ολοκληρωθεί σε μελλοντική αναβάθμιση.
            </p>
            <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;font-size:12px;color:#92400e;line-height:1.6">
                <i class="bi bi-shield-exclamation me-1"></i>
                <strong>Συνιστάται ανεπιφύλακτα να μην προχωρήσετε.</strong><br>
                Η εκτέλεση της λειτουργίας ενδέχεται να προκαλέσει απρόβλεπτη συμπεριφορά του συστήματος.
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:14px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end">
            <button onclick="closeBackupModal()" class="btn btn-gold">
                <i class="bi bi-check-lg me-1"></i>Κατανοητό, Επιστροφή
            </button>
        </div>
    </div>
</div>

<style>
@keyframes slideDown {
    from { opacity:0; transform:translateY(-20px); }
    to   { opacity:1; transform:translateY(0); }
}
</style>

<script>
function openBackupModal() {
    var m = document.getElementById('backupWarningModal');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeBackupModal() {
    var m = document.getElementById('backupWarningModal');
    m.style.display = 'none';
    document.body.style.overflow = '';
}
// Κλείσιμο με κλικ έξω
document.getElementById('backupWarningModal').addEventListener('click', function(e) {
    if (e.target === this) closeBackupModal();
});
// Κλείσιμο με Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeBackupModal();
});
</script>

<?php include 'layout_admin_end.php'; ?>