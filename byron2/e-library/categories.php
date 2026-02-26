<?php
require 'config.php';
requireAdmin(); // Μόνο admins — οι υπάλληλοι δεν διαχειρίζονται κατηγορίες/εκδότες
$db = getDB();

/* ─────────────────────────────────────────────────────────────
   POST ACTIONS
   Και οι τέσσερις actions τελειώνουν με redirect (PRG pattern)
   για να αποφεύγεται η επανυποβολή φόρμας με F5.
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_cat') {
        $name = trim($_POST['cat_name'] ?? '');
        $desc = trim($_POST['cat_desc'] ?? '');
        if ($name) {
            try {
                $db->prepare("INSERT INTO " . TABLE_PREFIX . "categories (name, description) VALUES (?,?)")->execute([$name,$desc]);
                flash('Κατηγορία προστέθηκε.');
            } catch (Exception $e) {
                // PDO exception λόγω UNIQUE constraint — το όνομα υπάρχει ήδη
                flash('Η κατηγορία υπάρχει ήδη.','error');
            }
        } else {
            flash('Συμπληρώστε όνομα κατηγορίας.','error');
        }

    } elseif ($action === 'del_cat') {
        $id = (int)$_POST['id'];
        // Τα βιβλία που ανήκουν στην κατηγορία παραμένουν — category_id → NULL (FK ON DELETE SET NULL)
        $db->prepare("DELETE FROM " . TABLE_PREFIX . "categories WHERE id=?")->execute([$id]);
        flash('Κατηγορία διαγράφηκε.');

    } elseif ($action === 'add_pub') {
        $name = trim($_POST['pub_name'] ?? '');
        if ($name) {
            try {
                $db->prepare("INSERT INTO " . TABLE_PREFIX . "publishers (name) VALUES (?)")->execute([$name]);
                flash('Εκδότης προστέθηκε.');
            } catch (Exception $e) {
                // PDO exception λόγω UNIQUE constraint
                flash('Ο εκδότης υπάρχει ήδη.','error');
            }
        } else {
            flash('Συμπληρώστε όνομα εκδότη.','error');
        }

    } elseif ($action === 'del_pub') {
        // Τα βιβλία του εκδότη παραμένουν — publisher_id → NULL (FK ON DELETE SET NULL)
        $db->prepare("DELETE FROM " . TABLE_PREFIX . "publishers WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Εκδότης διαγράφηκε.');
    }

    header('Location: categories.php'); exit;
}

/* ─────────────────────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────────────────────── */

// Ίδια υλοποίηση με audit.php / catalog.php
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

// Ίδια υλοποίηση με audit.php — διατηρεί τα GET params και των δύο panels
// ώστε η αλλαγή σελίδας στις κατηγορίες να μην μηδενίζει τα φίλτρα εκδοτών και αντίστροφα
function qsKeep(array $override = []): string {
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}

/* ─────────────────────────────────────────────────────────────
   ΦΙΛΤΡΑ + PAGINATION ΚΑΤΗΓΟΡΙΩΝ
   Παράμετροι: csearch, climit, cpage (prefix "c" για να μην
   συγκρούονται με τις αντίστοιχες των εκδοτών)
───────────────────────────────────────────────────────────── */
$cSearch = trim($_GET['csearch'] ?? '');
$cLimitOptions = [10,25,50];
$cLimit = (int)($_GET['climit'] ?? 10);
if (!in_array($cLimit, $cLimitOptions, true)) $cLimit = 10;
$cPage = max(1, (int)($_GET['cpage'] ?? 1));

$cWhere = '1=1';
$cParams = [];
if ($cSearch !== '') {
    $cWhere .= " AND (c.name LIKE ? OR c.description LIKE ?)";
    $like = '%' . $cSearch . '%';
    $cParams[] = $like; $cParams[] = $like;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM " . TABLE_PREFIX . "categories c WHERE $cWhere");
$stmt->execute($cParams);
$cTotal = (int)$stmt->fetchColumn();
$cPages = max(1, (int)ceil($cTotal / $cLimit));
if ($cPage > $cPages) $cPage = $cPages;
$cOffset = ($cPage - 1) * $cLimit;

// COUNT βιβλίων ανά κατηγορία μέσω LEFT JOIN — εμφανίζεται στον πίνακα
$stmt = $db->prepare("
    SELECT c.*, COUNT(b.id) as book_count
    FROM " . TABLE_PREFIX . "categories c
    LEFT JOIN " . TABLE_PREFIX . "books b ON b.category_id=c.id
    WHERE $cWhere
    GROUP BY c.id
    ORDER BY c.name
    LIMIT " . (int)$cLimit . " OFFSET " . (int)$cOffset
);
$stmt->execute($cParams);
$categories = $stmt->fetchAll();

/* ─────────────────────────────────────────────────────────────
   ΦΙΛΤΡΑ + PAGINATION ΕΚΔΟΤΩΝ
   Παράμετροι: psearch, plimit, ppage (prefix "p")
───────────────────────────────────────────────────────────── */
$pSearch = trim($_GET['psearch'] ?? '');
$pLimitOptions = [10,25,50];
$pLimit = (int)($_GET['plimit'] ?? 10);
if (!in_array($pLimit, $pLimitOptions, true)) $pLimit = 10;
$pPage = max(1, (int)($_GET['ppage'] ?? 1));

$pWhere = '1=1';
$pParams = [];
if ($pSearch !== '') {
    $pWhere .= " AND (p.name LIKE ?)";
    $pParams[] = '%' . $pSearch . '%';
}

$stmt = $db->prepare("SELECT COUNT(*) FROM " . TABLE_PREFIX . "publishers p WHERE $pWhere");
$stmt->execute($pParams);
$pTotal = (int)$stmt->fetchColumn();
$pPages = max(1, (int)ceil($pTotal / $pLimit));
if ($pPage > $pPages) $pPage = $pPages;
$pOffset = ($pPage - 1) * $pLimit;

// COUNT βιβλίων ανά εκδότη — χρήσιμο για να βλέπει ο admin αν ο εκδότης έχει βιβλία πριν τον διαγράψει
$stmt = $db->prepare("
    SELECT p.*, COUNT(b.id) as book_count
    FROM " . TABLE_PREFIX . "publishers p
    LEFT JOIN " . TABLE_PREFIX . "books b ON b.publisher_id=p.id
    WHERE $pWhere
    GROUP BY p.id
    ORDER BY p.name
    LIMIT " . (int)$pLimit . " OFFSET " . (int)$pOffset
);
$stmt->execute($pParams);
$publishers = $stmt->fetchAll();

$pageTitle  = 'Κατηγορίες & Εκδότες';
$activePage = 'categories';
include 'layout_admin.php';
?>


<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <h1>Κατηγορίες &amp; Εκδότες</h1>
        <p>Διαχειριστείτε τους τρόπους ταξινόμησης του καταλόγου</p>
    </div>
</div>

<div class="row g-3">
    <!-- ΚΑΤΗΓΟΡΙΕΣ -->
    <div class="col-lg-6">
        <div class="card-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin:0">
                    <i class="bi bi-tags me-2" style="color:var(--gold)"></i>Κατηγορίες
                    <span style="font-size:12px;font-weight:400;color:#9ca3af;margin-left:6px">(<?= $cTotal ?>)</span>
                </h6>
                <button type="button" class="btn btn-gold btn-sm" onclick="openModal('addCatModal')">
                    <i class="bi bi-plus-lg me-1"></i> Νέα
                </button>
            </div>

            <!--
                Κάθε φόρμα φίλτρων κρατά τις παραμέτρους του ΑΛΛΟΥ panel
                ως hidden inputs, ώστε η αναζήτηση στις κατηγορίες να μην
                επαναφέρει τους εκδότες στην 1η σελίδα και αντίστροφα.
            -->
            <form method="GET" class="mini-filters d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="width:240px;">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" name="csearch" class="form-control" placeholder="Αναζήτηση κατηγορίας..." value="<?= h($cSearch) ?>">
                    </div>

                    <div class="input-group input-group-sm" style="width:160px;">
                        <span class="input-group-text bg-light">Εμφάνιση</span>
                        <select name="climit" class="form-select" onchange="this.form.submit()">
                            <?php foreach ([10,25,50] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $cLimit===$opt?'selected':'' ?>><?= $opt ?> / σελίδα</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="cpage" value="1">
                    <!-- Διατήρηση κατάστασης panel εκδοτών -->
                    <input type="hidden" name="psearch" value="<?= h($pSearch) ?>">
                    <input type="hidden" name="plimit" value="<?= (int)$pLimit ?>">
                    <input type="hidden" name="ppage" value="<?= (int)$pPage ?>">

                    <button class="btn btn-sm btn-outline-secondary" type="submit">Φίλτρο</button>

                    <?php if ($cSearch !== ''): ?>
                        <a class="btn btn-sm btn-link text-decoration-none" href="?<?= qsKeep(['csearch'=>null,'cpage'=>1]) ?>">Καθαρισμός</a>
                    <?php endif; ?>
                </div>

                <div class="small text-muted">
                    Σελίδα <strong><?= $cPage ?></strong> / <strong><?= $cPages ?></strong>
                </div>
            </form>

            <table class="table table-library mb-0">
                <thead><tr><th>Κατηγορία</th><th style="width:70px;text-align:center">Βιβλία</th><th style="width:50px"></th></tr></thead>
                <tbody>
                <?php foreach ($categories as $c): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;font-size:13px"><?= h($c['name']) ?></div>
                        <?php if ($c['description']): ?>
                            <div style="font-size:11px;color:#9ca3af"><?= h(mb_substr($c['description'],0,60)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <span style="font-size:13px;font-weight:600;color:#374151"><?= $c['book_count'] ?></span>
                    </td>
                    <td>
                        <!-- Επιβεβαίωση μέσω browser confirm — αρκετό για διαχειριστική λειτουργία -->
                        <form method="POST" onsubmit="return confirm('Διαγραφή κατηγορίας «<?= addslashes(h($c['name'])) ?>»;')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="del_cat">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="padding:3px 7px;background:#fee2e2;border:none;color:#991b1b" title="Διαγραφή">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">Καμία κατηγορία.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($cPages > 1): ?>
            <nav class="mt-3" aria-label="Categories pagination">
                <ul class="pagination pagination-sm mb-0 flex-wrap">
                    <li class="page-item <?= $cPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= qsKeep(['cpage'=>$cPage-1]) ?>">&laquo;</a>
                    </li>
                    <?php foreach (pageWindow($cPage, $cPages, 2) as $p): ?>
                        <?php if ($p === '…'): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php else: ?>
                            <li class="page-item <?= $p===$cPage?'active':'' ?>">
                                <a class="page-link" href="?<?= qsKeep(['cpage'=>$p]) ?>"><?= $p ?></a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <li class="page-item <?= $cPage >= $cPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= qsKeep(['cpage'=>$cPage+1]) ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>

        </div>
    </div>

    <!-- ΕΚΔΟΤΕΣ -->
    <div class="col-lg-6">
        <div class="card-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin:0">
                    <i class="bi bi-building me-2" style="color:var(--gold)"></i>Εκδότες
                    <span style="font-size:12px;font-weight:400;color:#9ca3af;margin-left:6px">(<?= $pTotal ?>)</span>
                </h6>
                <button type="button" class="btn btn-gold btn-sm" onclick="openModal('addPubModal')">
                    <i class="bi bi-plus-lg me-1"></i> Νέος
                </button>
            </div>

            <!-- Διατήρηση κατάστασης panel κατηγοριών στα hidden inputs -->
            <form method="GET" class="mini-filters d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="width:240px;">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" name="psearch" class="form-control" placeholder="Αναζήτηση εκδότη..." value="<?= h($pSearch) ?>">
                    </div>

                    <div class="input-group input-group-sm" style="width:160px;">
                        <span class="input-group-text bg-light">Εμφάνιση</span>
                        <select name="plimit" class="form-select" onchange="this.form.submit()">
                            <?php foreach ([10,25,50] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $pLimit===$opt?'selected':'' ?>><?= $opt ?> / σελίδα</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="ppage" value="1">
                    <!-- Διατήρηση κατάστασης panel κατηγοριών -->
                    <input type="hidden" name="csearch" value="<?= h($cSearch) ?>">
                    <input type="hidden" name="climit" value="<?= (int)$cLimit ?>">
                    <input type="hidden" name="cpage" value="<?= (int)$cPage ?>">

                    <button class="btn btn-sm btn-outline-secondary" type="submit">Φίλτρο</button>

                    <?php if ($pSearch !== ''): ?>
                        <a class="btn btn-sm btn-link text-decoration-none" href="?<?= qsKeep(['psearch'=>null,'ppage'=>1]) ?>">Καθαρισμός</a>
                    <?php endif; ?>
                </div>

                <div class="small text-muted">
                    Σελίδα <strong><?= $pPage ?></strong> / <strong><?= $pPages ?></strong>
                </div>
            </form>

            <table class="table table-library mb-0">
                <thead><tr><th>Εκδότης</th><th style="width:70px;text-align:center">Βιβλία</th><th style="width:50px"></th></tr></thead>
                <tbody>
                <?php foreach ($publishers as $p): ?>
                <tr>
                    <td style="font-weight:600;font-size:13px"><?= h($p['name']) ?></td>
                    <td style="text-align:center">
                        <span style="font-size:13px;font-weight:600;color:#374151"><?= $p['book_count'] ?></span>
                    </td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Διαγραφή εκδότη «<?= addslashes(h($p['name'])) ?>»;')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="del_pub">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="padding:3px 7px;background:#fee2e2;border:none;color:#991b1b" title="Διαγραφή">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($publishers)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">Κανένας εκδότης.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($pPages > 1): ?>
            <nav class="mt-3" aria-label="Publishers pagination">
                <ul class="pagination pagination-sm mb-0 flex-wrap">
                    <li class="page-item <?= $pPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= qsKeep(['ppage'=>$pPage-1]) ?>">&raquo;</a>
                    </li>
                    <?php foreach (pageWindow($pPage, $pPages, 2) as $pp): ?>
                        <?php if ($pp === '…'): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php else: ?>
                            <li class="page-item <?= $pp===$pPage?'active':'' ?>">
                                <a class="page-link" href="?<?= qsKeep(['ppage'=>$pp]) ?>"><?= $pp ?></a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <li class="page-item <?= $pPage >= $pPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= qsKeep(['ppage'=>$pPage+1]) ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- MODAL: Νέα Κατηγορία -->
<div class="modal-overlay" id="addCatModal">
    <div class="modal-box">
        <div class="modal-box-header">
            <h5><i class="bi bi-tag me-2" style="color:var(--gold)"></i>Νέα Κατηγορία</h5>
            <button type="button" class="modal-close-btn" onclick="closeModal('addCatModal')">&times;</button>
        </div>
        <form method="POST">
<?= csrfField() ?>
            <input type="hidden" name="action" value="add_cat">
            <div class="modal-box-body">
                <div class="mb-3">
                    <label class="form-label">Όνομα Κατηγορίας <span class="text-danger">*</span></label>
                    <input type="text" name="cat_name" class="form-control" required placeholder="π.χ. Φιλοσοφία" autofocus>
                </div>
                <div class="mb-2">
                    <label class="form-label">Περιγραφή (προαιρετικό)</label>
                    <textarea name="cat_desc" class="form-control" rows="2" placeholder="Σύντομη περιγραφή…"></textarea>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addCatModal')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold"><i class="bi bi-plus-lg me-1"></i>Προσθήκη</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Νέος Εκδότης -->
<div class="modal-overlay" id="addPubModal">
    <div class="modal-box">
        <div class="modal-box-header">
            <h5><i class="bi bi-building me-2" style="color:var(--gold)"></i>Νέος Εκδότης</h5>
            <button type="button" class="modal-close-btn" onclick="closeModal('addPubModal')">&times;</button>
        </div>
        <form method="POST">
<?= csrfField() ?>
            <input type="hidden" name="action" value="add_pub">
            <div class="modal-box-body">
                <div class="mb-2">
                    <label class="form-label">Όνομα Εκδότη <span class="text-danger">*</span></label>
                    <input type="text" name="pub_name" class="form-control" required placeholder="π.χ. Εκδόσεις Καστανιώτη">
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addPubModal')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold"><i class="bi bi-plus-lg me-1"></i>Προσθήκη</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    var el = document.getElementById(id);
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
    // setTimeout για να έχει γίνει render το modal πριν γίνει focus
    var inp = el.querySelector('input[type=text], textarea');
    if (inp) setTimeout(function(){ inp.focus(); }, 50);
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
// Κλείσιμο με click στο overlay (εκτός του modal box)
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
// Κλείσιμο με Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(function(m){ closeModal(m.id); });
    }
});
</script>

<?php include 'layout_admin_end.php'; ?>