<?php
require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι/admins έχουν πρόσβαση
$db = getDB();

/* ─────────────────────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────────────────────── */

// Ίδια υλοποίηση με audit.php — παράγει παράθυρο σελίδων για pagination
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
        if ($prev !== null && $p > $prev + 1) $out[] = '…';
        $out[] = $p;
        $prev = $p;
    }
    return $out;
}

// Επιστρέφει τα IDs όλων των ενεργών admins — χρησιμοποιείται
// για μαζική αποστολή μηνύματος αιτήματος άδειας
function getAdminIds(PDO $db): array {
    $ids = $db->query("SELECT id FROM " . TABLE_PREFIX . "users WHERE role='admin' AND active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $ids ?: []);
}

/* ─────────────────────────────────────────────────────────────
   POST ACTIONS
   Όλες οι ενέργειες τερματίζουν με redirect για να αποφεύγεται
   η επανυποβολή φόρμας με F5 (Post/Redirect/Get pattern).
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ── Μεμονωμένη διαγραφή ── */
    if ($action === 'delete_book' && isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        $stmt = $db->prepare("SELECT id, title, isbn, created_by FROM " . TABLE_PREFIX . "books WHERE id=?");
        $stmt->execute([$delId]);
        $book = $stmt->fetch();

        if (!$book) { flash('Το αντικείμενο δεν βρέθηκε.', 'error'); header('Location: catalog.php'); exit; }

        // Έλεγχος εξουσιοδότησης: admin ή ιδιοκτήτης εγγραφής
        $isOwner = ((int)$book['created_by'] === (int)($_SESSION['user_id'] ?? 0));
        if (!isAdmin() && !$isOwner) {
            flash('Δεν έχετε δικαίωμα διαγραφής αυτού του αντικειμένου. Μπορείτε να ζητήσετε άδεια από τον διαχειριστή.', 'error');
            header('Location: catalog.php'); exit;
        }

        $db->prepare("DELETE FROM " . TABLE_PREFIX . "books WHERE id=?")->execute([$delId]);
        $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
           ->execute([$_SESSION['user_id'], 'delete', 'book', $delId, 'Book deleted from catalog']);

        flash('Το αντικείμενο διαγράφηκε.');
        header('Location: catalog.php'); exit;
    }

    /* ── Μαζική διαγραφή (Admin only) ── */
    if ($action === 'mass_delete' && isAdmin()) {
        $ids = array_map('intval', array_filter($_POST['mass_ids'] ?? []));
        if (empty($ids)) { flash('Δεν επιλέξατε κανένα αντικείμενο.', 'error'); header('Location: catalog.php'); exit; }
        $deleted = 0;
        foreach ($ids as $did) {
            $res = $db->prepare("DELETE FROM " . TABLE_PREFIX . "books WHERE id=?");
            $res->execute([$did]);
            if ($res->rowCount() > 0) {
                $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
                   ->execute([$_SESSION['user_id'], 'mass_delete', 'book', $did, 'Mass deleted by admin']);
                $deleted++;
            }
        }
        flash("Διαγράφηκαν {$deleted} αντικείμενα μαζικά.");
        header('Location: catalog.php'); exit;
    }

    /* ── Μαζική αλλαγή κατάστασης (Admin only) ── */
    if ($action === 'mass_status' && isAdmin()) {
        $ids       = array_map('intval', array_filter($_POST['mass_ids'] ?? []));
        $newStatus = $_POST['new_status'] ?? '';
        $valid     = ['Διαθέσιμο', 'Μη Διαθέσιμο', 'Σε Επεξεργασία'];
        // Whitelist validation — αποτροπή arbitrary τιμών στο status
        if (empty($ids) || !in_array($newStatus, $valid)) { flash('Σφάλμα παραμέτρων.', 'error'); header('Location: catalog.php'); exit; }
        $updated = 0;
        foreach ($ids as $uid) {
            $res = $db->prepare("UPDATE " . TABLE_PREFIX . "books SET status=? WHERE id=?");
            $res->execute([$newStatus, $uid]);
            if ($res->rowCount() > 0) {
                $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
                   ->execute([$_SESSION['user_id'], 'mass_status', 'book', $uid, "Status → {$newStatus}"]);
                $updated++;
            }
        }
        flash("Ενημερώθηκε η κατάσταση {$updated} αντικειμένων σε «{$newStatus}».");
        header('Location: catalog.php'); exit;
    }

    /* ── Μαζική αλλαγή δημοσιότητας (Admin only) ── */
    if ($action === 'mass_visibility' && isAdmin()) {
        $ids = array_map('intval', array_filter($_POST['mass_ids'] ?? []));
        // Cast σε 0/1 — αποτροπή οποιασδήποτε άλλης τιμής
        $vis = isset($_POST['new_visibility']) ? (int)$_POST['new_visibility'] : 1;
        $vis = ($vis === 0) ? 0 : 1;
        if (empty($ids)) { flash('Δεν επιλέξατε κανένα αντικείμενο.', 'error'); header('Location: catalog.php'); exit; }
        $updated = 0;
        foreach ($ids as $uid) {
            $res = $db->prepare("UPDATE " . TABLE_PREFIX . "books SET is_public=? WHERE id=?");
            $res->execute([$vis, $uid]);
            if ($res->rowCount() > 0) $updated++;
        }
        $label = $vis ? 'Δημόσιο' : 'Ιδιωτικό';
        // Ένα audit log entry για το σύνολο (όχι ανά εγγραφή) — intentional για brevity
        $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
           ->execute([$_SESSION['user_id'], 'mass_visibility', 'book', "Set {$updated} books → {$label}"]);
        flash("Ορίστηκαν {$updated} αντικείμενα ως «{$label}».");
        header('Location: catalog.php'); exit;
    }

    /* ── Αίτημα άδειας (υπάλληλος → admin) ── */
    if ($action === 'request_permission' && isset($_POST['book_id'], $_POST['req_action'])) {
        $bookId    = (int)$_POST['book_id'];
        $reqAction = $_POST['req_action'] === 'edit' ? 'edit' : 'delete';
        $reason    = trim($_POST['reason'] ?? '');
        if ($reason === '') $reason = '—';

        $stmt = $db->prepare("SELECT id, title, author, isbn, created_by FROM " . TABLE_PREFIX . "books WHERE id=?");
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();

        if (!$book) { flash('Το αντικείμενο δεν βρέθηκε.', 'error'); header('Location: catalog.php'); exit; }

        // Αν ο χρήστης έχει ήδη δικαίωμα, δεν χρειάζεται αίτημα
        $isOwner = ((int)$book['created_by'] === (int)($_SESSION['user_id'] ?? 0));
        if ($isOwner || isAdmin()) {
            flash('Έχετε ήδη δικαίωμα για αυτή την ενέργεια.', 'info');
            header('Location: catalog.php'); exit;
        }

        $admins = getAdminIds($db);
        if (empty($admins)) { flash('Δεν βρέθηκε ενεργός διαχειριστής.', 'error'); header('Location: catalog.php'); exit; }

        $who        = $_SESSION['username'] ?? ('user#' . ($_SESSION['user_id'] ?? ''));
        $niceAction = $reqAction === 'edit' ? 'Επεξεργασία' : 'Διαγραφή';
        $subject    = "Αίτημα Άδειας: {$niceAction} βιβλίου (ID {$book['id']})";
        $body       = "Ο υπάλληλος: {$who}\nΖητά άδεια για: {$niceAction}\n\nΑιτιολογία:\n{$reason}\n\nΣτοιχεία βιβλίου:\n- ID: {$book['id']}\n- Τίτλος: {$book['title']}\n- Συγγραφέας: {$book['author']}\n- ISBN: " . ($book['isbn'] ?: '—') . "\n\nΠαρακαλώ εγκρίνετε/αναλάβετε την ενέργεια.";

        // Αποστολή σε ΟΛΟΥΣ τους admins (αντίθετα με το book.php που στέλνει μόνο στον πρώτο)
        $ins = $db->prepare("INSERT INTO " . TABLE_PREFIX . "messages (from_user, to_user, subject, body) VALUES (?,?,?,?)");
        foreach ($admins as $aid) $ins->execute([$_SESSION['user_id'], $aid, $subject, $body]);

        $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
           ->execute([$_SESSION['user_id'], 'request_permission', 'book', $bookId, "Requested {$reqAction} permission"]);

        flash('Το αίτημα στάλθηκε στον διαχειριστή.');
        header('Location: catalog.php'); exit;
    }
}

/* ─────────────────────────────────────────────────────────────
   ΦΙΛΤΡΑ (GET)
   Δυναμικό WHERE με prepared statement params για ασφάλεια SQL injection
───────────────────────────────────────────────────────────── */
$search   = trim($_GET['search'] ?? '');
$typeF    = $_GET['type'] ?? '';
$langF    = $_GET['lang'] ?? '';
$catF     = $_GET['cat'] ?? '';
$yearFrom = $_GET['year_from'] ?? '';
$yearTo   = $_GET['year_to'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) $perPage = 10;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[] = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($typeF)    { $where[] = "b.type=?";         $params[] = $typeF; }
if ($langF)    { $where[] = "b.language=?";     $params[] = $langF; }
if ($catF)     { $where[] = "b.category_id=?";  $params[] = $catF; }
if ($yearFrom) { $where[] = "b.year >= ?";       $params[] = $yearFrom; }
if ($yearTo)   { $where[] = "b.year <= ?";       $params[] = $yearTo; }

$whereStr = implode(' AND ', $where);

// Πρώτα μετράμε το σύνολο (για pagination), μετά ανακτούμε τη σελίδα
$totalStmt = $db->prepare("SELECT COUNT(*) FROM " . TABLE_PREFIX . "books b WHERE $whereStr");
$totalStmt->execute($params);
$total  = (int)$totalStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("
    SELECT b.*, c.name as cat_name, p.name as pub_name
    FROM " . TABLE_PREFIX . "books b
    LEFT JOIN " . TABLE_PREFIX . "categories c ON b.category_id=c.id
    LEFT JOIN " . TABLE_PREFIX . "publishers p ON b.publisher_id=p.id
    WHERE $whereStr
    ORDER BY b.created_at DESC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
);
$stmt->execute($params);
$books = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM " . TABLE_PREFIX . "categories ORDER BY name")->fetchAll();
// Μόνο γλώσσες που χρησιμοποιούνται πραγματικά (όχι hard-coded λίστα)
$languages  = $db->query("SELECT DISTINCT language FROM " . TABLE_PREFIX . "books WHERE language IS NOT NULL ORDER BY language")->fetchAll(PDO::FETCH_COLUMN);

// Ανάκτηση ENUM τύπων από τη βάση — συντηρείται αυτόματα χωρίς code changes
$typeResult = $db->query("SHOW COLUMNS FROM " . TABLE_PREFIX . "books LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
preg_match("/^enum\((.*)\)$/", $typeResult['Type'], $matches);
$allTypes = array_map(fn($v) => trim($v, "'"), explode(",", $matches[1]));

// Σειριοποίηση δεδομένων για τα JS searchable dropdowns και τη mass bar
$jsCats    = json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $categories));
$jsLangs   = json_encode(array_values($languages));
$jsTypes   = json_encode($allTypes);
$jsIsAdmin = isAdmin() ? 'true' : 'false';

/* ── CSV Export — πριν το HTML output για σωστά headers ── */
if (isset($_GET['export'])) {
    $all = $db->prepare("
        SELECT b.title, b.author, b.isbn, b.type, c.name as category, p.name as publisher,
               b.year, b.language, b.pages, b.location, b.status
        FROM " . TABLE_PREFIX . "books b
        LEFT JOIN " . TABLE_PREFIX . "categories c ON b.category_id=c.id
        LEFT JOIN " . TABLE_PREFIX . "publishers p ON b.publisher_id=p.id
        WHERE $whereStr ORDER BY b.title
    ");
    $all->execute($params); // Εξάγει μόνο τα φιλτραρισμένα αποτελέσματα
    $rows = $all->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="catalog_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM για Excel
    fputcsv($out, ['Τίτλος','Συγγραφέας','ISBN','Τύπος','Κατηγορία','Εκδότης','Έτος','Γλώσσα','Σελίδες','Τοποθεσία','Κατάσταση']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out); exit;
}

$pageTitle  = 'Κατάλογος Βιβλιοθήκης';
$activePage = 'catalog';
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
        <h1>Κατάλογος Βιβλιοθήκης</h1>
        <p>Διαχειριστείτε βιβλία, περιοδικά και άλλους πόρους</p>
    </div>
    <div class="d-flex gap-2">
        <!-- Το export κληρονομεί τα ενεργά φίλτρα μέσω array_merge($_GET) -->
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>1])) ?>" class="btn btn-outline-gold">
            <i class="bi bi-download me-1"></i> Εξαγωγή CSV
        </a>
        <a href="add_book.php" class="btn btn-gold">
            <i class="bi bi-plus-lg me-1"></i> Προσθήκη Αντικειμένου
        </a>
    </div>
</div>

<!-- ══ ΦΙΛΤΡΑ ══ -->
<div class="card-panel mb-3">
    <form method="GET" id="filterForm" class="row g-2 align-items-end">

        <div class="col-sm-4 col-lg-3">
            <div class="search-bar">
                <i class="bi bi-search search-icon"></i>
                <input type="text" name="search" class="form-control"
                       placeholder="Τίτλος, Συγγραφέας, ISBN..."
                       value="<?= h($search) ?>">
            </div>
        </div>

        <!--
            Searchable dropdowns: κρυφό <select> για POST + ορατό input για UX.
            Η SearchableSelect class συγχρονίζει τις δύο τιμές.
        -->
        <div class="col-sm-2 col-lg-2">
            <div class="ss-wrap" id="ss-type">
                <div class="ss-input-wrap" id="ss-type-wrap">
                    <i class="bi bi-layers ss-icon"></i>
                    <input type="text" class="ss-display" autocomplete="off"
                           placeholder="Τύπος" data-ss="type">
                    <button type="button" class="ss-clear" tabindex="-1">×</button>
                </div>
                <div class="ss-panel" id="ss-panel-type"></div>
            </div>
            <select name="type" id="sel-type" style="display:none">
                <option value=""></option>
                <?php foreach ($allTypes as $t): ?>
                    <option value="<?= h($t) ?>" <?= $typeF===$t?'selected':'' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-sm-2 col-lg-2">
            <div class="ss-wrap" id="ss-cat">
                <div class="ss-input-wrap" id="ss-cat-wrap">
                    <i class="bi bi-tag ss-icon"></i>
                    <input type="text" class="ss-display" autocomplete="off"
                           placeholder="Κατηγορία" data-ss="cat">
                    <button type="button" class="ss-clear" tabindex="-1">×</button>
                </div>
                <div class="ss-panel" id="ss-panel-cat"></div>
            </div>
            <select name="cat" id="sel-cat" style="display:none">
                <option value=""></option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $catF==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-sm-2 col-lg-2">
            <div class="ss-wrap" id="ss-lang">
                <div class="ss-input-wrap" id="ss-lang-wrap">
                    <i class="bi bi-globe ss-icon"></i>
                    <input type="text" class="ss-display" autocomplete="off"
                           placeholder="Γλώσσα" data-ss="lang">
                    <button type="button" class="ss-clear" tabindex="-1">×</button>
                </div>
                <div class="ss-panel" id="ss-panel-lang"></div>
            </div>
            <select name="lang" id="sel-lang" style="display:none">
                <option value=""></option>
                <?php foreach ($languages as $l): ?>
                    <option value="<?= h($l) ?>" <?= $langF===$l?'selected':'' ?>><?= h($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <input type="number" name="year_from" class="form-control"
                   placeholder="Από" style="width:80px" value="<?= h($yearFrom) ?>">
        </div>
        <div class="col-auto">
            <input type="number" name="year_to" class="form-control"
                   placeholder="Έως" style="width:80px" value="<?= h($yearTo) ?>">
        </div>

        <div class="col-auto">
            <select name="per_page" class="form-select" style="width:130px"
                    onchange="this.form.submit()">
                <?php foreach ([10,25,50] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?> / σελίδα</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <button type="submit" class="btn btn-gold">Φίλτρο</button>
        </div>

        <?php if ($search || $typeF || $langF || $catF || $yearFrom || $yearTo || isset($_GET['per_page'])): ?>
        <div class="col-auto">
            <a href="catalog.php" class="btn btn-outline-secondary btn-sm" style="font-size:12px">Καθαρισμός</a>
        </div>
        <?php endif; ?>

        <!-- Reset σελίδας στο 1 όταν αλλάζουν τα φίλτρα -->
        <input type="hidden" name="page" value="1">
    </form>
</div>

<!-- ══ MASS ACTIONS BAR — ορατή μόνο σε admins και μόνο όταν υπάρχουν επιλεγμένες γραμμές ══ -->
<?php if (isAdmin()): ?>
<div class="mass-bar" id="massBar">
    <span class="mass-count" id="massCount">0 επιλεγμένα</span>
    <div class="mass-sep"></div>
    <button type="button" class="mass-btn" onclick="openMassStatus()">
        <i class="bi bi-arrow-repeat"></i> Αλλαγή Κατάστασης
    </button>
    <button type="button" class="mass-btn" onclick="openMassVisibility()">
        <i class="bi bi-eye"></i> Ορισμός Δημοσιότητας
    </button>
    <button type="button" class="mass-btn danger" onclick="openMassDelete()">
        <i class="bi bi-trash3"></i> Μαζική Διαγραφή
    </button>
    <div class="mass-sep"></div>
    <button type="button" class="mass-btn desel" onclick="clearAll()">
        <i class="bi bi-x-lg"></i> Αποεπιλογή
    </button>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-2">
    <small class="text-muted">
        <?= $total ?> αποτελέσματα<?= $search ? " για «" . h($search) . "»" : '' ?> • Σελίδα <?= $page ?> / <?= $pages ?>
    </small>
    <?php if (isAdmin()): ?>
    <small style="color:#9ca3af;font-size:11px">
        <i class="bi bi-shield-check me-1" style="color:var(--gold)"></i>
        Τσεκάρετε γραμμές για μαζικές ενέργειες (Admin)
    </small>
    <?php endif; ?>
</div>

<!-- ΠΙΝΑΚΑΣ -->
<div class="card-panel p-0" style="overflow:hidden">
    <table class="table table-library mb-0" id="catalogTable">
        <thead>
            <tr>
                <?php if (isAdmin()): ?>
                <th class="th-chk">
                    <input type="checkbox" class="row-chk" id="chkAll" title="Επιλογή όλων στη σελίδα">
                </th>
                <?php endif; ?>
                <th>Τίτλος</th>
                <th>Συγγραφέας</th>
                <th>Τύπος</th>
                <th>Από</th>
                <th>Γλώσσα</th>
                <th>Κατάσταση</th>
                <th>Ενέργειες</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($books)): ?>
            <tr><td colspan="<?= isAdmin() ? 8 : 7 ?>" class="text-center text-muted py-5" style="font-size:13px">
                <i class="bi bi-search me-2"></i>Δεν βρέθηκαν αποτελέσματα
            </td></tr>
        <?php else: ?>
            <?php foreach ($books as $b): ?>
            <?php
                // Ανά γραμμή: υπολογισμός δικαιωμάτων ώστε να εμφανιστούν
                // τα σωστά κουμπιά (επεξεργασία/διαγραφή vs αίτημα)
                $isOwner       = ((int)($b['created_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0));
                $canEditDelete = isAdmin() || $isOwner;
            ?>
            <tr data-id="<?= (int)$b['id'] ?>">
                <?php if (isAdmin()): ?>
                <td class="td-chk">
                    <input type="checkbox" class="row-chk book-chk"
                           value="<?= (int)$b['id'] ?>"
                           data-title="<?= addslashes(h($b['title'])) ?>">
                </td>
                <?php endif; ?>
                <td>
                    <a href="book.php?id=<?= $b['id'] ?>" class="fw-semibold text-decoration-none" style="color:var(--text-primary)">
                        <?= h($b['title']) ?>
                    </a>
                    <?php if ($b['isbn']): ?>
                        <div style="font-size:11px;color:#9ca3af">ISBN: <?= h($b['isbn']) ?></div>
                    <?php endif; ?>
                    <?php if (!$b['is_public']): ?>
                        <span style="font-size:10px;background:#f3f4f6;color:#9ca3af;padding:1px 5px;border-radius:8px;margin-left:2px">Ιδιωτικό</span>
                    <?php endif; ?>
                </td>
                <td><?= h($b['author']) ?></td>
                <td>
                    <span class="badge-type <?= $b['type']==='Βιβλίο'?'badge-book':($b['type']==='Περιοδικό'?'badge-magazine':'badge-other') ?>">
                        <?= h($b['type']) ?>
                    </span>
                </td>
                <td><?= $b['year'] ?: '—' ?></td>
                <td><?= h($b['language'] ?: '—') ?></td>
                <td>
                    <span class="badge-type <?= $b['status']==='Διαθέσιμο'?'badge-available':($b['status']==='Μη Διαθέσιμο'?'badge-unavailable':'badge-processing') ?>">
                        <?= h($b['status']) ?>
                    </span>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="book.php?id=<?= $b['id'] ?>" class="btn btn-sm"
                           style="padding:3px 8px;font-size:12px;background:#f3f4f6;border:none" title="Προβολή">
                            <i class="bi bi-eye"></i>
                        </a>

                        <?php if ($canEditDelete): ?>
                            <!-- Άμεσες ενέργειες για admin/ιδιοκτήτη -->
                            <a href="edit_book.php?id=<?= $b['id'] ?>" class="btn btn-sm"
                               style="padding:3px 8px;font-size:12px;background:#f3f4f6;border:none" title="Επεξεργασία">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button class="btn btn-sm"
                                style="padding:3px 8px;font-size:12px;background:#fee2e2;border:none;color:#991b1b"
                                title="Διαγραφή"
                                onclick="confirmDelete(<?= (int)$b['id'] ?>, '<?= addslashes(h($b['title'])) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php else: ?>
                            <!-- Κουμπιά αιτήματος για χρήστες χωρίς δικαίωμα -->
                            <button class="btn btn-sm"
                                style="padding:3px 8px;font-size:12px;background:#f3f4f6;border:none"
                                title="Αίτημα Επεξεργασίας"
                                onclick="openRequestModal('edit', <?= (int)$b['id'] ?>, '<?= addslashes(h($b['title'])) ?>', '<?= addslashes(h($b['isbn'] ?? '')) ?>')">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm"
                                style="padding:3px 8px;font-size:12px;background:#f3f4f6;border:none;color:#991b1b"
                                title="Αίτημα Διαγραφής"
                                onclick="openRequestModal('delete', <?= (int)$b['id'] ?>, '<?= addslashes(h($b['title'])) ?>', '<?= addslashes(h($b['isbn'] ?? '')) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if (!$canEditDelete): ?>
                        <div style="font-size:10px;color:#9ca3af;margin-top:4px">Μόνο ο διαχειριστής/δημιουργός</div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- PAGINATION -->
<?php if ($pages > 1): ?>
<div class="d-flex justify-content-center mt-3">
    <nav>
        <ul class="pagination pagination-sm flex-wrap">
            <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= $page<=1?'#':('?'.http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>">&laquo;</a>
            </li>
            <?php foreach (pageWindow($page, $pages, 2) as $p): ?>
                <?php if ($p==='…'): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php else: ?>
                    <li class="page-item <?= $p==$page?'active':'' ?>">
                        <a class="page-link"
                           href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"
                           style="<?= $p==$page?'background:var(--gold);border-color:var(--gold);color:#1a1a2e':'' ?>">
                            <?= $p ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= $page>=$pages?'#':('?'.http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
</div>
<?php endif; ?>


<!-- ══ MODALS ══ -->

<!-- 1. Επιβεβαίωση μεμονωμένης διαγραφής -->
<div class="mo" id="moDelete">
    <div class="mo-box sm">
        <div class="mo-hdr">
            <h5><i class="bi bi-trash me-2 text-danger"></i>Διαγραφή Αντικειμένου</h5>
            <button class="mo-x" onclick="closeMo('moDelete')">&times;</button>
        </div>
        <div class="mo-body">
            <p>Θέλετε σίγουρα να διαγράψετε:</p>
            <p style="font-weight:700;color:#1a1a2e" id="moDeleteTitle"></p>
            <div class="mo-warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>Η ενέργεια είναι <strong>μόνιμη</strong> και δεν αναιρείται.</span>
            </div>
        </div>
        <div class="mo-foot">
            <form method="POST" id="fmDelete">
<?= csrfField() ?>
                <input type="hidden" name="action" value="delete_book">
                <input type="hidden" name="delete_id" id="moDeleteId">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeMo('moDelete')">Ακύρωση</button>
                <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Διαγραφή</button>
            </form>
        </div>
    </div>
</div>

<!-- 2. Επιβεβαίωση μαζικής διαγραφής -->
<div class="mo" id="moMassDel">
    <div class="mo-box">
        <div class="mo-hdr">
            <h5><i class="bi bi-trash3 me-2 text-danger"></i>Μαζική Διαγραφή</h5>
            <button class="mo-x" onclick="closeMo('moMassDel')">&times;</button>
        </div>
        <div class="mo-body">
            <p>Πρόκειται να διαγράψετε <strong id="moMassDelCount" style="color:#ef4444"></strong> αντικείμενα:</p>
            <div class="mo-list" id="moMassDelList"></div>
            <div class="mo-danger">
                <i class="bi bi-exclamation-octagon-fill"></i>
                <div>
                    <strong>ΠΡΟΣΟΧΗ:</strong> Η ενέργεια είναι <strong>μόνιμη και μη αναστρέψιμη</strong>.
                    Όλα τα επιλεγμένα αντικείμενα θα διαγραφούν οριστικά.
                    Εξαγάγετε CSV αντίγραφο πριν προχωρήσετε αν χρειάζεστε ιστορικό.
                </div>
            </div>
        </div>
        <div class="mo-foot">
            <form method="POST" id="fmMassDel">
<?= csrfField() ?>
                <input type="hidden" name="action" value="mass_delete">
                <!-- Τα IDs εγχύονται δυναμικά από JS πριν το άνοιγμα του modal -->
                <div id="moMassDelInputs"></div>
                <button type="button" class="btn btn-secondary" onclick="closeMo('moMassDel')">Ακύρωση</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Διαγραφή Όλων</button>
            </form>
        </div>
    </div>
</div>

<!-- 3. Μαζική αλλαγή κατάστασης -->
<div class="mo" id="moMassStatus">
    <div class="mo-box sm">
        <div class="mo-hdr">
            <h5><i class="bi bi-arrow-repeat me-2" style="color:var(--gold)"></i>Αλλαγή Κατάστασης</h5>
            <button class="mo-x" onclick="closeMo('moMassStatus')">&times;</button>
        </div>
        <div class="mo-body">
            <p>Αλλαγή κατάστασης για <strong id="moStatusCount" style="color:var(--gold)"></strong> αντικείμενα:</p>
            <label class="form-label mt-2">Νέα Κατάσταση</label>
            <select class="form-select" id="moStatusSel">
                <option value="Διαθέσιμο">Διαθέσιμο</option>
                <option value="Μη Διαθέσιμο">Μη Διαθέσιμο</option>
                <option value="Σε Επεξεργασία">Σε Επεξεργασία</option>
            </select>
            <div class="mo-warn" style="margin-top:12px">
                <i class="bi bi-info-circle-fill"></i>
                <span>Η κατάσταση θα αλλάξει σε <strong>όλα</strong> τα επιλεγμένα αντικείμενα ταυτόχρονα.</span>
            </div>
        </div>
        <div class="mo-foot">
            <form method="POST" id="fmMassStatus">
<?= csrfField() ?>
                <input type="hidden" name="action" value="mass_status">
                <!-- Η τιμή του select αντιγράφεται στο hidden input ακριβώς πριν το submit -->
                <input type="hidden" name="new_status" id="moStatusHidden">
                <div id="moStatusInputs"></div>
                <button type="button" class="btn btn-secondary" onclick="closeMo('moMassStatus')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold"
                        onclick="document.getElementById('moStatusHidden').value=document.getElementById('moStatusSel').value">
                    <i class="bi bi-check-lg me-1"></i>Εφαρμογή
                </button>
            </form>
        </div>
    </div>
</div>

<!-- 4. Μαζική αλλαγή δημοσιότητας -->
<div class="mo" id="moMassVis">
    <div class="mo-box sm">
        <div class="mo-hdr">
            <h5><i class="bi bi-eye me-2" style="color:var(--gold)"></i>Ορισμός Δημοσιότητας</h5>
            <button class="mo-x" onclick="closeMo('moMassVis')">&times;</button>
        </div>
        <div class="mo-body">
            <p>Ορισμός δημοσιότητας για <strong id="moVisCount" style="color:var(--gold)"></strong> αντικείμενα:</p>
            <div class="d-flex gap-2 mt-3">
                <label style="flex:1;display:flex;align-items:center;gap:10px;padding:12px;border:2px solid #e5e7eb;border-radius:8px;cursor:pointer">
                    <input type="radio" name="visChoice" value="1" checked style="accent-color:var(--gold)">
                    <div>
                        <div style="font-weight:700;font-size:13px">Δημόσιο</div>
                        <div style="font-size:11px;color:#9ca3af">Ορατό χωρίς σύνδεση</div>
                    </div>
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:10px;padding:12px;border:2px solid #e5e7eb;border-radius:8px;cursor:pointer">
                    <input type="radio" name="visChoice" value="0" style="accent-color:var(--gold)">
                    <div>
                        <div style="font-weight:700;font-size:13px">Ιδιωτικό</div>
                        <div style="font-size:11px;color:#9ca3af">Μόνο για συνδεδεμένους</div>
                    </div>
                </label>
            </div>
        </div>
        <div class="mo-foot">
            <form method="POST" id="fmMassVis">
<?= csrfField() ?>
                <input type="hidden" name="action" value="mass_visibility">
                <!-- Η τιμή του radio (:checked) αντιγράφεται στο hidden input ακριβώς πριν το submit -->
                <input type="hidden" name="new_visibility" id="moVisHidden" value="1">
                <div id="moVisInputs"></div>
                <button type="button" class="btn btn-secondary" onclick="closeMo('moMassVis')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold"
                        onclick="document.getElementById('moVisHidden').value=document.querySelector('input[name=visChoice]:checked').value">
                    <i class="bi bi-check-lg me-1"></i>Εφαρμογή
                </button>
            </form>
        </div>
    </div>
</div>

<!-- 5. Αίτημα άδειας (υπάλληλος) -->
<div class="mo" id="moRequest">
    <div class="mo-box">
        <div class="mo-hdr">
            <h5><i class="bi bi-send me-2" style="color:var(--gold)"></i>Αίτημα Άδειας από Διαχειριστή</h5>
            <button class="mo-x" onclick="closeMo('moRequest')">&times;</button>
        </div>
        <div class="mo-body">
            <!-- Συμπληρώνεται δυναμικά από JS με τα στοιχεία της επιλεγμένης εγγραφής -->
            <div id="moReqInfo" class="mb-3"></div>
            <label class="form-label" style="font-weight:600">Αιτιολογία <span class="text-danger">*</span></label>
            <textarea class="form-control" id="moReqReason" rows="4"
                placeholder="Π.χ. Λάθος συγγραφέας / διπλοεγγραφή / λάθος ISBN..."
                style="font-size:13px"></textarea>
            <div style="font-size:12px;color:#6b7280;margin-top:8px">
                <i class="bi bi-info-circle me-1"></i>
                Θα σταλεί μήνυμα στον διαχειριστή με ID/ISBN/Τίτλο και την αιτιολογία σας.
            </div>
        </div>
        <div class="mo-foot">
            <form method="POST" id="fmReq">
<?= csrfField() ?>
                <input type="hidden" name="action" value="request_permission">
                <input type="hidden" name="book_id"    id="moReqBookId">
                <input type="hidden" name="req_action" id="moReqAction">
                <!-- Αντιγράφεται από το textarea πριν το submit (βλ. JS) -->
                <input type="hidden" name="reason"     id="moReqReasonHidden">
                <button type="button" class="btn btn-secondary" onclick="closeMo('moRequest')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold"><i class="bi bi-send me-1"></i>Αποστολή Αιτήματος</button>
            </form>
        </div>
    </div>
</div>


<?php $extraJs = '
<script>

/*
 * Δεδομένα από PHP → JavaScript για τα searchable dropdowns.
 * IS_ADMIN χρησιμοποιείται για να ενεργοποιείται/απενεργοποιείται
 * η λογική mass selection μόνο για admins.
 */
const SS_DATA = {
    type: ' . $jsTypes . '.map(v => ({ value: v, label: v })),
    cat:  ' . $jsCats  . '.map(c => ({ value: String(c.id), label: c.name })),
    lang: ' . $jsLangs . '.map(v => ({ value: v, label: v })),
};
const IS_ADMIN = ' . $jsIsAdmin . ';

/* ════════════════════════════════════════════════════════════
   Pure JS modal system — χωρίς Bootstrap dependency.
   Υποστηρίζει κλείσιμο με backdrop click και Escape.
════════════════════════════════════════════════════════════ */
function openMo(id) {
    document.getElementById(id).classList.add("open");
    document.body.style.overflow = "hidden"; // αποτροπή scroll του background
}
function closeMo(id) {
    document.getElementById(id).classList.remove("open");
    document.body.style.overflow = "";
}
document.querySelectorAll(".mo").forEach(function(el) {
    el.addEventListener("click", function(e) { if (e.target === el) closeMo(el.id); });
});
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape")
        document.querySelectorAll(".mo.open").forEach(function(m) { closeMo(m.id); });
});

/* ════════════════════════════════════════════════════════════
   Mass selection (Admin only)
════════════════════════════════════════════════════════════ */
function getChecked() {
    return Array.from(document.querySelectorAll(".book-chk:checked"));
}

/*
 * Ενημερώνει τη mass bar, τον counter, το "select all" checkbox
 * και το highlight των επιλεγμένων γραμμών σε ένα πέρασμα.
 */
function syncMassBar() {
    if (!IS_ADMIN) return;
    var checked = getChecked();
    var bar = document.getElementById("massBar");
    if (!bar) return;
    bar.classList.toggle("visible", checked.length > 0);
    document.getElementById("massCount").textContent =
        checked.length + " επιλεγμέν" + (checked.length === 1 ? "ο" : "α");

    // Indeterminate state όταν επιλεγμένα είναι ούτε 0 ούτε όλα
    var all = document.querySelectorAll(".book-chk");
    var ca  = document.getElementById("chkAll");
    if (ca) {
        ca.indeterminate = checked.length > 0 && checked.length < all.length;
        ca.checked = all.length > 0 && checked.length === all.length;
    }

    document.querySelectorAll("tr[data-id]").forEach(function(tr) {
        var cb = tr.querySelector(".book-chk");
        if (cb) tr.classList.toggle("row-sel", cb.checked);
    });
}

function clearAll() {
    document.querySelectorAll(".book-chk").forEach(function(cb) { cb.checked = false; });
    var ca = document.getElementById("chkAll");
    if (ca) { ca.checked = false; ca.indeterminate = false; }
    syncMassBar();
}

/*
 * Εγχύει hidden inputs με τα επιλεγμένα IDs σε ένα form container,
 * ώστε να σταλούν ως mass_ids[] στο POST.
 */
function injectIds(containerId) {
    var c = document.getElementById(containerId);
    c.innerHTML = "";
    getChecked().forEach(function(cb) {
        var inp = document.createElement("input");
        inp.type = "hidden"; inp.name = "mass_ids[]"; inp.value = cb.value;
        c.appendChild(inp);
    });
}

function openMassDelete() {
    var checked = getChecked();
    if (!checked.length) return;
    document.getElementById("moMassDelCount").textContent = checked.length;
    document.getElementById("moMassDelList").textContent =
        checked.map(function(cb) { return "• " + cb.dataset.title; }).join("\n");
    injectIds("moMassDelInputs");
    openMo("moMassDel");
}

function openMassStatus() {
    var checked = getChecked();
    if (!checked.length) return;
    document.getElementById("moStatusCount").textContent = checked.length;
    injectIds("moStatusInputs");
    openMo("moMassStatus");
}

function openMassVisibility() {
    var checked = getChecked();
    if (!checked.length) return;
    document.getElementById("moVisCount").textContent = checked.length;
    injectIds("moVisInputs");
    openMo("moMassVis");
}

/* ════════════════════════════════════════════════════════════
   Single delete
════════════════════════════════════════════════════════════ */
function confirmDelete(id, title) {
    document.getElementById("moDeleteId").value = id;
    document.getElementById("moDeleteTitle").textContent = title;
    openMo("moDelete");
}

/* ════════════════════════════════════════════════════════════
   Request permission modal
   Τα στοιχεία βιβλίου περνούν ως παράμετροι από τα PHP-generated
   onclick attributes σε κάθε γραμμή του πίνακα.
════════════════════════════════════════════════════════════ */
function openRequestModal(act, id, title, isbn) {
    document.getElementById("moReqBookId").value = id;
    document.getElementById("moReqAction").value = act;
    document.getElementById("moReqReason").value = "";
    var nice    = act === "edit" ? "Επεξεργασία" : "Διαγραφή";
    var isbnTxt = isbn ? "ISBN: " + isbn : "ISBN: —";
    document.getElementById("moReqInfo").innerHTML =
        "<div style=\"font-weight:700;font-size:14px;margin-bottom:8px\">" + nice + " αντικειμένου:</div>" +
        "<div style=\"background:#f9fafb;border-radius:6px;padding:10px 14px;font-size:13px\">" +
        "<div>ID: <b>" + id + "</b></div>" +
        "<div>Τίτλος: <b>" + title + "</b></div>" +
        "<div>" + isbnTxt + "</div></div>";
    openMo("moRequest");
}
document.getElementById("fmReq").addEventListener("submit", function(e) {
    var val = document.getElementById("moReqReason").value.trim();
    if (!val) {
        e.preventDefault();
        var ta = document.getElementById("moReqReason");
        ta.style.borderColor = "#ef4444"; ta.focus(); return;
    }
    document.getElementById("moReqReason").style.borderColor = "";
    // Αντιγραφή τιμής από το ορατό textarea στο hidden input πριν το POST
    document.getElementById("moReqReasonHidden").value = val;
});

/* ════════════════════════════════════════════════════════════
   SearchableSelect — ίδια υλοποίηση με book_add.php / audit.php
════════════════════════════════════════════════════════════ */
class SearchableSelect {
    constructor(wrapId, items, selId, placeholder, allLabel) {
        this.wrap     = document.getElementById(wrapId);
        this.input    = this.wrap.querySelector(".ss-display");
        this.panel    = this.wrap.querySelector(".ss-panel");
        this.clearBtn = this.wrap.querySelector(".ss-clear");
        this.inputWrap= this.wrap.querySelector(".ss-input-wrap");
        this.hidden   = document.getElementById(selId);
        this.items    = items;
        this.allLabel = allLabel || "Όλα";
        this.selected = { value: "", label: "" };
        this.MAX      = 10;
        this._initFromHidden();
        this._bind();
    }
    _initFromHidden() {
        const opt = this.hidden.querySelector("option[selected]");
        if (opt && opt.value) {
            const item = this.items.find(i => i.value === opt.value);
            if (item) this._select(item, false);
        }
    }
    _bind() {
        this.input.addEventListener("focus",  () => this._open());
        this.input.addEventListener("input",  () => this._render(this.input.value));
        this.clearBtn.addEventListener("click", e => { e.stopPropagation(); this._clear(); });
        document.addEventListener("click", e => { if (!this.wrap.contains(e.target)) this._close(); });
        this.input.addEventListener("keydown", e => {
            if (e.key === "Escape") { this._close(); this.input.blur(); }
            if (e.key === "Enter")  { e.preventDefault(); this._selectFirst(); }
        });
    }
    _open()  { this.wrap.classList.add("open"); this._render(this.input.value); }
    _close() { this.wrap.classList.remove("open"); this.input.value = this.selected.value ? this.selected.label : ""; }
    _render(query = "") {
        const q       = query.trim().toLowerCase();
        const matched = q ? this.items.filter(i => i.label.toLowerCase().includes(q)) : this.items;
        const shown   = matched.slice(0, this.MAX);
        const more    = matched.length - shown.length;
        let html = "";
        const allSel = !this.selected.value ? "selected ss-opt-all" : "ss-opt-all";
        html += `<button type="button" class="ss-opt ${allSel}" data-value="" data-label="">${this.allLabel}</button>`;
        if (shown.length === 0) {
            html += `<div class="ss-empty">Δεν βρέθηκαν αποτελέσματα</div>`;
        } else {
            shown.forEach(i => {
                const sel = i.value === this.selected.value ? "selected" : "";
                html += `<button type="button" class="ss-opt ${sel}"
                    data-value="${i.value}" data-label="${i.label.replace(/"/g,"&quot;")}">${i.label}</button>`;
            });
            if (more > 0) html += `<div class="ss-more">+${more} ακόμα — πληκτρολογήστε για περισσότερα</div>`;
        }
        this.panel.innerHTML = html;
        this.panel.querySelectorAll(".ss-opt").forEach(btn => {
            btn.addEventListener("mousedown", e => {
                e.preventDefault(); // mousedown αντί click για να μη χαθεί focus πριν ενημερωθεί η τιμή
                this._select({ value: btn.dataset.value, label: btn.dataset.label });
            });
        });
    }
    _select(item, close = true) {
        this.selected = item;
        this.hidden.value = item.value;
        if (item.value) { this.input.value = item.label; this.inputWrap.classList.add("has-value"); }
        else            { this.input.value = "";          this.inputWrap.classList.remove("has-value"); }
        if (close) this._close();
    }
    _clear()       { this._select({ value: "", label: "" }); this.input.focus(); this._open(); }
    _selectFirst() { const f = this.panel.querySelector(".ss-opt:not(.ss-opt-all)"); if (f) f.dispatchEvent(new MouseEvent("mousedown")); }
}

/* ════════════════════════════════════════════════════════════
   INIT
════════════════════════════════════════════════════════════ */
document.addEventListener("DOMContentLoaded", function () {

    new SearchableSelect("ss-type", SS_DATA.type, "sel-type", "Τύπος",     "Όλοι οι Τύποι");
    new SearchableSelect("ss-cat",  SS_DATA.cat,  "sel-cat",  "Κατηγορία", "Όλες οι Κατηγορίες");
    new SearchableSelect("ss-lang", SS_DATA.lang, "sel-lang", "Γλώσσα",    "Όλες οι Γλώσσες");

    if (IS_ADMIN) {
        var chkAll = document.getElementById("chkAll");
        if (chkAll) {
            chkAll.addEventListener("change", function() {
                document.querySelectorAll(".book-chk").forEach(function(cb) { cb.checked = chkAll.checked; });
                syncMassBar();
            });
        }
        document.querySelectorAll(".book-chk").forEach(function(cb) {
            cb.addEventListener("change", syncMassBar);
        });
    }

    /*
     * Έκθεση των functions στο window scope για τα inline onclick
     * attributes των κουμπιών στον πίνακα (δεν έχουν πρόσβαση σε
     * module scope ή DOMContentLoaded closure χωρίς αυτό).
     */
    window.confirmDelete      = confirmDelete;
    window.openRequestModal   = openRequestModal;
    window.openMassDelete     = openMassDelete;
    window.openMassStatus     = openMassStatus;
    window.openMassVisibility = openMassVisibility;
    window.clearAll           = clearAll;
});

</script>';
include 'layout_admin_end.php';
?>