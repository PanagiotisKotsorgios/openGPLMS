<?php
require 'config.php';
requireEmployee(); // Μόνο υπάλληλοι/admins βλέπουν αναφορές
$db = getDB();

/* ─────────────────────────────────────────────────────────────
   POST ACTION — Άδειασμα Ιστορικού (μόνο admin)
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_audit') {
    requireAdmin();
    verifyCsrf();
    $db->exec("DELETE FROM " . TABLE_PREFIX . "audit_log");
    // Καταγράφουμε την ίδια την ενέργεια αμέσως μετά το truncate
    $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
       ->execute([$_SESSION['user_id'], 'clear', 'audit_log', 'Άδειασμα ιστορικού ενεργειών από admin']);
    flash('Το ιστορικό ενεργειών αδειάστηκε.');
    header('Location: reports.php'); exit;
}

/* ─────────────────────────────────────────────────────────────
   ΣΤΑΤΙΣΤΙΚΑ — summary cards + chart data
───────────────────────────────────────────────────────────── */
$totalBooks   = $db->query("SELECT COUNT(*) FROM " . TABLE_PREFIX . "books")->fetchColumn();
$newMonth     = $db->query("SELECT COUNT(*) FROM " . TABLE_PREFIX . "books WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$typeCount    = $db->query("SELECT COUNT(DISTINCT type) FROM " . TABLE_PREFIX . "books")->fetchColumn();

// Queries για charts — κάθε αποτέλεσμα χρησιμοποιείται σε ξεχωριστό Chart.js graph
$typeStats    = $db->query("SELECT type, COUNT(*) as cnt FROM " . TABLE_PREFIX . "books GROUP BY type ORDER BY cnt DESC")->fetchAll();
$catStats     = $db->query("SELECT c.name, COUNT(b.id) as cnt FROM " . TABLE_PREFIX . "categories c LEFT JOIN " . TABLE_PREFIX . "books b ON b.category_id=c.id GROUP BY c.id, c.name ORDER BY cnt DESC")->fetchAll();
$langStats    = $db->query("SELECT language, COUNT(*) as cnt FROM " . TABLE_PREFIX . "books GROUP BY language ORDER BY cnt DESC LIMIT 8")->fetchAll(); // Top 8 για ευανάγνωστο chart
$yearStats    = $db->query("SELECT year, COUNT(*) as cnt FROM " . TABLE_PREFIX . "books WHERE year IS NOT NULL GROUP BY year ORDER BY year DESC LIMIT 10")->fetchAll();
$monthlyStats = $db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo, COUNT(*) as cnt FROM " . TABLE_PREFIX . "books GROUP BY mo ORDER BY mo DESC LIMIT 12")->fetchAll(); // 12 μήνες πίσω

/* ─────────────────────────────────────────────────────────────
   AUDIT LOG — φίλτρα + pagination
   GET params:
     - asearch : αναζήτηση σε username, action, target_type, details
     - alimit  : εγγραφές ανά σελίδα (10/25/50)
     - apage   : τρέχουσα σελίδα (1..n)
   Χρησιμοποιεί prefix 'a' για να μην συγκρούεται με φίλτρα άλλων σελίδων
───────────────────────────────────────────────────────────── */
$limitOptions = [10, 25, 50];

$aLimit = (int)($_GET['alimit'] ?? 10);
if (!in_array($aLimit, $limitOptions, true)) $aLimit = 10; // Whitelist — αποτροπή произвольных τιμών

$aSearch     = trim($_GET['asearch'] ?? '');
$aSearchLike = '%' . $aSearch . '%';

$aPage = (int)($_GET['apage'] ?? 1);
if ($aPage < 1) $aPage = 1;

// Δυναμικό WHERE — προστίθεται μόνο αν υπάρχει search term
$whereSql = '';
$params   = [];
if ($aSearch !== '') {
    $whereSql = " WHERE (u.username LIKE ? OR a.action LIKE ? OR a.target_type LIKE ? OR a.details LIKE ?)";
    $params   = [$aSearchLike, $aSearchLike, $aSearchLike, $aSearchLike];
}

// Πρώτα COUNT για pagination, μετά τα πραγματικά δεδομένα — ίδιο pattern με index.php
$sqlCount = "
SELECT COUNT(*)
FROM " . TABLE_PREFIX . "audit_log a
LEFT JOIN " . TABLE_PREFIX . "users u ON a.user_id=u.id
" . $whereSql;

$stmtCount = $db->prepare($sqlCount);
$stmtCount->execute($params);
$aTotalRows = (int)$stmtCount->fetchColumn();

$aTotalPages = max(1, (int)ceil($aTotalRows / $aLimit));
if ($aPage > $aTotalPages) $aPage = $aTotalPages; // Clamp μετά τον υπολογισμό

$aOffset = ($aPage - 1) * $aLimit;

// LIMIT/OFFSET cast σε int inline — αδύνατο SQL injection μέσω prepared statement,
// αλλά ο cast είναι ρητή άμυνα επειδή τα LIMIT/OFFSET δεν μπορούν να περαστούν ως params
$sqlAudit = "
SELECT a.*, u.username
FROM " . TABLE_PREFIX . "audit_log a
LEFT JOIN " . TABLE_PREFIX . "users u ON a.user_id=u.id
" . $whereSql . "
ORDER BY a.created_at DESC
LIMIT " . (int)$aLimit . " OFFSET " . (int)$aOffset;

$stmt = $db->prepare($sqlAudit);
$stmt->execute($params);
$recentAudit = $stmt->fetchAll();

/* ─────────────────────────────────────────────────────────────
   ΜΕΤΑΦΡΑΣΕΙΣ audit values — μόνο για εμφάνιση, δεν αλλάζει τη βάση.
   Τα raw English strings αποθηκεύονται στη βάση για consistency.
   Η μετάφραση γίνεται frontend-only με αυτές τις helper functions.
───────────────────────────────────────────────────────────── */

/**
 * Μεταφράζει το action string σε ελληνικό label για εμφάνιση.
 * Fallback: title-case του αρχικού string αν δεν βρεθεί στο map.
 */
function auditActionLabel(?string $action): string {
    $a = strtolower(trim((string)$action));
    $map = [
        'create'  => 'Δημιούργησε',
        'add'     => 'Πρόσθεσε',
        'insert'  => 'Πρόσθεσε',
        'update'  => 'Ενημέρωσε',
        'edit'    => 'Επεξεργάστηκε',
        'delete'  => 'Διέγραψε',
        'remove'  => 'Αφαίρεσε',
        'restore' => 'Επανέφερε',
        'login'   => 'Σύνδεση',
        'logout'  => 'Αποσύνδεση',
        'export'  => 'Εξαγωγή',
        'import'  => 'Εισαγωγή',
        'approve' => 'Ενέκρινε',
        'reject'  => 'Απέρριψε',
        'view'    => 'Προβολή',
        'search'  => 'Αναζήτηση',
        'borrow'  => 'Δανεισμός',
        'return'  => 'Επιστροφή',
    ];
    return $map[$a] ?? ($action ? mb_convert_case($action, MB_CASE_TITLE, "UTF-8") : '—');
}

/**
 * Μεταφράζει το target_type string σε ελληνικό label για εμφάνιση.
 * Χειρίζεται και singular και plural μορφές (π.χ. 'book' / 'books').
 */
function auditTargetLabel(?string $type): string {
    $t = strtolower(trim((string)$type));
    $map = [
        'book'       => 'Βιβλίο',
        'books'      => 'Βιβλίο',
        'category'   => 'Κατηγορία',
        'categories' => 'Κατηγορία',
        'publisher'  => 'Εκδότης',
        'publishers' => 'Εκδότης',
        'user'       => 'Χρήστης',
        'users'      => 'Χρήστης',
        'loan'       => 'Δανεισμός',
        'loans'      => 'Δανεισμός',
        'copy'       => 'Αντίτυπο',
        'copies'     => 'Αντίτυπο',
        'system'     => 'Σύστημα',
    ];
    return $map[$t] ?? ($type ? mb_convert_case($type, MB_CASE_TITLE, "UTF-8") : '—');
}

/**
 * Χτίζει query string για links pagination/filter διατηρώντας τα τρέχοντα φίλτρα.
 * Το $overrides array επιτρέπει να αλλάξει μόνο συγκεκριμένα params (π.χ. apage).
 * Αδειανό asearch αφαιρείται από το URL για καθαρότερα links.
 */
function auditQs(array $overrides = []): string {
    $base = [
        'alimit'  => $_GET['alimit']  ?? 10,
        'asearch' => $_GET['asearch'] ?? '',
        'apage'   => $_GET['apage']   ?? 1,
    ];
    $q = array_merge($base, $overrides);
    if (trim((string)($q['asearch'] ?? '')) === '') unset($q['asearch']);
    return '?' . http_build_query($q);
}

/**
 * Δημιουργεί sliding window σελίδων με ellipsis για μεγάλο αριθμό σελίδων.
 * Εμφανίζει πάντα την πρώτη, την τελευταία και ±$radius γύρω από την τρέχουσα.
 * Παράδειγμα (current=7, total=20, radius=2): [1, …, 5, 6, 7, 8, 9, …, 20]
 */
function pageWindow(int $current, int $total, int $radius = 2): array {
    if ($total <= 1) return [1];

    $pages = [1, $total];
    for ($i = $current - $radius; $i <= $current + $radius; $i++) {
        if ($i >= 1 && $i <= $total) $pages[] = $i;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    // Εισαγωγή '…' στα κενά — string '…' αντί για int για διάκριση στο template
    $out  = [];
    $prev = null;
    foreach ($pages as $p) {
        if ($prev !== null && $p > $prev + 1) $out[] = '…';
        $out[] = $p;
        $prev  = $p;
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────
   EXPORT — πλήρης κατάλογος σε CSV
   Εκτελείται πριν το layout include ώστε να μπορεί να στείλει
   Content-Type: text/csv header χωρίς να έχει εκτυπωθεί HTML.
───────────────────────────────────────────────────────────── */
if (isset($_GET['export'])) {
    $all = $db->query("
        SELECT b.title, b.author, b.isbn, b.type, c.name as category, p.name as publisher,
               b.year, b.language, b.pages, b.location, b.status, b.created_at
        FROM " . TABLE_PREFIX . "books b
        LEFT JOIN " . TABLE_PREFIX . "categories c ON b.category_id=c.id
        LEFT JOIN " . TABLE_PREFIX . "publishers p ON b.publisher_id=p.id
        ORDER BY b.title
    ");
    $rows = $all->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="full_catalog_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM για σωστή εμφάνιση ελληνικών στο Excel
    fputcsv($out, ['Τίτλος','Συγγραφέας','ISBN','Τύπος','Κατηγορία','Εκδότης','Έτος','Γλώσσα','Σελίδες','Τοποθεσία','Κατάσταση','Καταχωρήθηκε']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit; // Σταματάμε εδώ — δεν θέλουμε να εκτυπωθεί HTML μετά το CSV
}

$pageTitle  = 'Αναφορές & Στατιστικά';
$activePage = 'reports';
include 'layout_admin.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <h1>Αναφορές &amp; Στατιστικά</h1>
        <p>Πραγματικά δεδομένα και μετρικά από τη βάση δεδομένων.</p>
    </div>
    <a href="?export=1" class="btn btn-outline-gold">
        <i class="bi bi-download me-1"></i> Εξαγωγή Καταλόγου (CSV)
    </a>
</div>

<!-- STATS -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card text-center">
            <div class="stat-icon mb-2"><i class="bi bi-collection"></i></div>
            <div class="stat-value"><?= $totalBooks ?></div>
            <div class="stat-label">Σύνολο Αντικειμένων</div>
            <div class="stat-sub">Καταχωρημένα στη βάση</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card text-center">
            <div class="stat-icon mb-2"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-value"><?= $newMonth ?></div>
            <div class="stat-label">Νέες Καταχωρήσεις</div>
            <div class="stat-sub">Τελευταίοι 30 ημέρες</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card text-center">
            <div class="stat-icon mb-2"><i class="bi bi-grid"></i></div>
            <div class="stat-value"><?= $typeCount ?></div>
            <div class="stat-label">Ποικιλία Τύπων</div>
            <div class="stat-sub">Διαφορετικές κατηγορίες</div>
        </div>
    </div>
</div>

<!-- CHARTS ROW 1 -->
<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card-panel h-100">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Κατανομή Αποθέματος</h6>
            <canvas id="typeDonut" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-panel h-100">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Ιστορικό Καταχωρήσεων</h6>
            <canvas id="monthBar" height="150"></canvas>
        </div>
    </div>
</div>

<!-- CHARTS ROW 2 -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Ανά Κατηγορία</h6>
            <canvas id="catBar" height="220"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Ανά Γλώσσα</h6>
            <canvas id="langBar" height="220"></canvas>
        </div>
    </div>
</div>

<!-- AUDIT LOG -->
<div class="card-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin:0">Ιστορικό Ενεργειών</h6>
        <?php if (isAdmin()): ?>
        <form method="POST" id="clearAuditForm" style="margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear_audit">
            <button type="button"
                    onclick="confirmClearAudit()"
                    class="btn btn-sm"
                    style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;font-size:12px;display:flex;align-items:center;gap:5px">
                <i class="bi bi-trash"></i> Άδειασμα Ιστορικού
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Φίλτρα — GET form ώστε το search να φαίνεται στο URL -->
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
            <div class="input-group input-group-sm" style="width: 290px;">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input
                    type="text"
                    name="asearch"
                    value="<?= h($aSearch) ?>"
                    class="form-control"
                    placeholder="Αναζήτηση (χρήστης, ενέργεια, αντικείμενο...)">
            </div>

            <div class="input-group input-group-sm" style="width: 190px;">
                <span class="input-group-text bg-light">Εμφάνιση</span>
                <!-- onchange submit — άμεση εφαρμογή χωρίς να χρειαστεί κλικ στο "Φίλτρο" -->
                <select name="alimit" class="form-select" onchange="this.form.submit()">
                    <?php foreach ([10,25,50] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $aLimit===$opt?'selected':'' ?>><?= $opt ?> / σελίδα</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Reset apage=1 κατά το φιλτράρισμα — αποτροπή "σελίδα 5 από 2" -->
            <input type="hidden" name="apage" value="1">
            <button class="btn btn-sm btn-outline-secondary" type="submit">Φίλτρο</button>

            <?php if ($aSearch !== ''): ?>
                <a class="btn btn-sm btn-link text-decoration-none" href="<?= auditQs(['apage'=>1, 'asearch'=>'']) ?>">Καθαρισμός</a>
            <?php endif; ?>
        </form>

        <div class="small text-muted">
            Σύνολο: <strong><?= $aTotalRows ?></strong> • Σελίδα <strong><?= $aPage ?></strong> / <strong><?= $aTotalPages ?></strong>
        </div>
    </div>

    <table class="table table-library mb-0">
        <thead>
            <tr>
                <th>Χρήστης</th>
                <th>Ενέργεια</th>
                <th>Αντικείμενο</th>
                <th>Ημ/νία</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentAudit as $a):
            // Μετάφραση raw DB values → ελληνικά labels για εμφάνιση
            $actionGr    = auditActionLabel($a['action']      ?? '');
            $targetGr    = auditTargetLabel($a['target_type'] ?? '');
            $targetShown = $targetGr . ($a['target_id'] ? ' #'.$a['target_id'] : '');
        ?>
            <tr>
                <td style="font-weight:600"><?= h($a['username'] ?? '—') ?></td>
                <td>
                    <span style="font-size:12px;background:#f3f4f6;padding:2px 8px;border-radius:10px">
                        <?= h($actionGr) ?>
                    </span>
                </td>
                <td><?= h($targetShown) ?></td>
                <td style="font-size:12px;color:#9ca3af"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>

        <?php if (empty($recentAudit)): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Κανένα ιστορικό ακόμα.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination — χρησιμοποιεί pageWindow() για sliding window με ellipsis -->
    <?php if ($aTotalPages > 1): ?>
        <nav class="mt-3" aria-label="Audit pagination">
            <ul class="pagination pagination-sm mb-0 flex-wrap">
                <li class="page-item <?= $aPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $aPage <= 1 ? '#' : auditQs(['apage'=>$aPage-1]) ?>" aria-label="Previous">
                        &laquo;
                    </a>
                </li>

                <?php foreach (pageWindow($aPage, $aTotalPages, 2) as $p): ?>
                    <?php if ($p === '…'): ?>
                        <!-- '…' είναι string — ο έλεγχος $p === '…' δουλεύει με strict comparison -->
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php else: ?>
                        <li class="page-item <?= $p === $aPage ? 'active' : '' ?>">
                            <a class="page-link" href="<?= auditQs(['apage'=>$p]) ?>"><?= $p ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>

                <li class="page-item <?= $aPage >= $aTotalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $aPage >= $aTotalPages ? '#' : auditQs(['apage'=>$aPage+1]) ?>" aria-label="Next">
                        &raquo;
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php
/*
 * Chart.js data — τα PHP arrays μετατρέπονται σε JSON για απευθείας χρήση.
 * Τα monthlyStats έρχονται DESC από τη βάση — χρειάζεται array_reverse()
 * για χρονολογική σειρά (παλαιότερα → νεότερα) στο bar chart.
 * Το $extraJs εισάγεται από το layout_admin_end.php πριν το </body>.
 */
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function confirmClearAudit() {
    if (confirm("Προσοχή!\n\nΘα διαγραφούν ΟΛΕΣ οι εγγραφές του ιστορικού ενεργειών.\nΗ ενέργεια δεν αναιρείται.\n\nΣυνέχεια;")) {
        document.getElementById("clearAuditForm").submit();
    }
}
const gold    = "#e8c547";
const palette = ["#e8c547","#3b82f6","#22c55e","#ef4444","#8b5cf6","#f97316","#06b6d4","#84cc16"];

// Doughnut — κατανομή ανά τύπο υλικού
new Chart(document.getElementById("typeDonut"), {
    type:"doughnut",
    data: { labels:' . json_encode(array_column($typeStats,'type')) . ',
        datasets:[{data:' . json_encode(array_column($typeStats,'cnt')) . ',backgroundColor:palette,borderWidth:0,hoverOffset:6}]},
    options:{plugins:{legend:{position:"bottom"}},cutout:"65%"}
});

// Bar — μηνιαίες καταχωρήσεις (array_reverse: DESC → ASC για σωστό timeline)
new Chart(document.getElementById("monthBar"), {
    type:"bar",
    data:{ labels:' . json_encode(array_reverse(array_column($monthlyStats,'mo'))) . ',
        datasets:[{label:"Καταχωρήσεις",data:' . json_encode(array_reverse(array_column($monthlyStats,'cnt'))) . ',backgroundColor:gold,borderRadius:4}]},
    options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
});

// Horizontal bar (indexAxis:"y") — κατηγορίες ανά πλήθος βιβλίων
new Chart(document.getElementById("catBar"), {
    type:"bar",
    data:{ labels:' . json_encode(array_column($catStats,'name')) . ',
        datasets:[{label:"Αντικείμενα",data:' . json_encode(array_column($catStats,'cnt')) . ',backgroundColor:"#3b82f6",borderRadius:4}]},
    options:{indexAxis:"y",plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}
});

// Bar — top 8 γλώσσες ανά πλήθος βιβλίων
new Chart(document.getElementById("langBar"), {
    type:"bar",
    data:{ labels:' . json_encode(array_column($langStats,'language')) . ',
        datasets:[{label:"Αντικείμενα",data:' . json_encode(array_column($langStats,'cnt')) . ',backgroundColor:"#22c55e",borderRadius:4}]},
    options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
});
</script>';

include 'layout_admin_end.php';
?>