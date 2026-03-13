<?php
require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι/admins έχουν πρόσβαση
$db = getDB();

// Αν υπάρχει παράμετρος 'id' στο URL, είμαστε σε λειτουργία επεξεργασίας
$isEdit = isset($_GET['id']);
$book = null;
if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM " . TABLE_PREFIX . "books WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $book = $stmt->fetch();
    // Αν το βιβλίο δεν βρεθεί, επιστροφή στον κατάλογο με μήνυμα σφάλματος
    if (!$book) { flash('Το αντικείμενο δεν βρέθηκε.','error'); header('Location: catalog.php'); exit; }
}

$categories = $db->query("SELECT * FROM " . TABLE_PREFIX . "categories ORDER BY name")->fetchAll();
$publishers = $db->query("SELECT * FROM " . TABLE_PREFIX . "publishers ORDER BY name")->fetchAll();

// Επεξεργασία της φόρμας όταν υποβληθεί (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); // CSRF έλεγχος — σταματά αμέσως αν το token δεν ταιριάζει
    // Καθαρισμός και ανάκτηση τιμών από τη φόρμα
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $type = $_POST['type'] ?? 'Βιβλίο';
    $cat = $_POST['category_id'] ?: null;   // null αν δεν επιλέχτηκε κατηγορία
    $pub = $_POST['publisher_id'] ?: null;   // null αν δεν επιλέχτηκε εκδότης
    $year = $_POST['year'] ?: null;
    $lang = trim($_POST['language'] ?? 'Ελληνικά');
    $pages = $_POST['pages'] ?: null;
    $edition = trim($_POST['edition'] ?? '');
    $volume = trim($_POST['volume'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'Διαθέσιμο';
    $is_public = isset($_POST['is_public']) ? 1 : 0; // checkbox → 1 ή 0
    // sanitizeCoverUrl: επιτρέπει μόνο http/https URLs — αποτρέπει javascript:, data: κ.λπ.
    $cover_url = sanitizeCoverUrl($_POST['cover_url'] ?? '');
    $created_by = $_SESSION['user_id'];

    // Βασικός έλεγχος: τίτλος και συγγραφέας είναι υποχρεωτικά
    if (!$title || !$author) {
        $error = 'Τίτλος και Συγγραφέας είναι υποχρεωτικά.';
    } else {
        if ($isEdit) {
            // Ενημέρωση υπάρχοντος βιβλίου
            $db->prepare("UPDATE " . TABLE_PREFIX . "books SET title=?,author=?,isbn=?,type=?,category_id=?,publisher_id=?,year=?,language=?,pages=?,edition=?,volume=?,location=?,description=?,status=?,is_public=?,cover_url=? WHERE id=?")
               ->execute([$title,$author,$isbn,$type,$cat,$pub,$year,$lang,$pages,$edition,$volume,$location,$desc,$status,$is_public,$cover_url,$book['id']]);
            // Καταγραφή της ενέργειας στο audit log
            $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id) VALUES (?,?,?,?)")
               ->execute([$_SESSION['user_id'], 'edit', 'book', $book['id']]);
            flash('Η καταχώρηση ενημερώθηκε επιτυχώς.');
            header('Location: book.php?id=' . $book['id']); exit;
        } else {
            // Δημιουργία νέου βιβλίου
            $stmt = $db->prepare("INSERT INTO " . TABLE_PREFIX . "books (title,author,isbn,type,category_id,publisher_id,year,language,pages,edition,volume,location,description,status,is_public,cover_url,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$title,$author,$isbn,$type,$cat,$pub,$year,$lang,$pages,$edition,$volume,$location,$desc,$status,$is_public,$cover_url,$_SESSION['user_id']]);
            $newId = $db->lastInsertId();
            // Καταγραφή της δημιουργίας στο audit log
            $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id) VALUES (?,?,?,?)")
               ->execute([$_SESSION['user_id'], 'create', 'book', $newId]);
            flash('Η καταχώρηση προστέθηκε επιτυχώς.');
            header('Location: book.php?id=' . $newId); exit;
        }
    }
}

// Αν είμαστε σε επεξεργασία, χρησιμοποιούμε τα δεδομένα του βιβλίου ως default τιμές
$v = $book ?: [];
$pageTitle = $isEdit ? 'Επεξεργασία: ' . ($book['title'] ?? '') : 'Προσθήκη Αντικειμένου';
$activePage = $isEdit ? 'catalog' : 'add';
include 'layout_admin.php';

// Ανάκτηση των επιτρεπόμενων τιμών του ENUM 'type' απευθείας από τη βάση,
// ώστε να μην χρειάζεται να τις συντηρούμε χειροκίνητα στον κώδικα
$typeResult = $db->query("SHOW COLUMNS FROM " . TABLE_PREFIX . "books LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
preg_match("/^enum\((.*)\)$/", $typeResult['Type'], $matches);
$allTypes = array_map(fn($v) => trim($v, "'"), explode(",", $matches[1]));

// Προετοιμασία δεδομένων για τα searchable dropdowns του JavaScript
// Κατηγορίες και εκδότες περνάνε ως [{value, label}] για να δουλεύουν με τη SearchableSelect class
$jsTypes = json_encode(array_map(fn($t) => ['value' => $t, 'label' => $t], $allTypes));
$jsCategories = json_encode(array_map(fn($c) => ['value' => (string)$c['id'], 'label' => $c['name']], $categories));
$jsPublishers = json_encode(array_map(fn($p) => ['value' => (string)$p['id'], 'label' => $p['name']], $publishers));
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

<div class="page-header">
    <h1><?= $isEdit ? 'Επεξεργασία Αντικειμένου' : 'Προσθήκη Αντικειμένου' ?></h1>
    <p><?= $isEdit ? 'Ενημερώστε τα στοιχεία της καταχώρησης' : 'Καταχωρήστε ένα νέο βιβλίο, περιοδικό ή άλλο πόρο' ?></p>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger flash-alert"><?= h($error) ?></div>
<?php endif; ?>

<form method="POST">
<?= csrfField() ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid #f3f4f6">Βασικά Στοιχεία</h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Τίτλος <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required value="<?= h($v['title']??'') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Συγγραφέας / Δημιουργός <span class="text-danger">*</span></label>
                    <input type="text" name="author" class="form-control" required value="<?= h($v['author']??'') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Τύπος</label>

                    <!--
                        Κρυφό <select> που φέρει την πραγματική τιμή για το POST.
                        Το ορατό searchable dropdown (παρακάτω) ελέγχει αυτό μέσω JS.
                    -->
                    <select name="type" id="sel-type" style="display:none">
                        <option value=""></option>
                        <?php foreach ($allTypes as $t): ?>
                            <option value="<?= h($t) ?>" <?= ($v['type'] ?? 'Βιβλίο') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Ορατό searchable dropdown — η SearchableSelect class το συνδέει με το κρυφό select -->
                    <div class="ss-wrap" id="ss-type">
                        <div class="ss-input-wrap" id="ss-type-wrap">
                            <i class="bi bi-layers ss-icon"></i>
                            <input type="text" class="ss-display" autocomplete="off" placeholder="Τύπος" data-ss="type">
                            <button type="button" class="ss-clear" tabindex="-1">×</button>
                        </div>
                        <div class="ss-panel" id="ss-panel-type"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ISBN / ISSN</label>
                    <input type="text" name="isbn" class="form-control" value="<?= h($v['isbn']??'') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Γλώσσα</label>
                    <input type="text" name="language" class="form-control" value="<?= h($v['language']??'Ελληνικά') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Έτος Έκδοσης</label>
                    <input type="number" name="year" class="form-control" min="1" max="<?= date('Y') ?>" value="<?= h($v['year']??'') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Αριθμός Σελίδων</label>
                    <input type="number" name="pages" class="form-control" value="<?= h($v['pages']??'') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Τοποθεσία Ράφι</label>
                    <input type="text" name="location" class="form-control" placeholder="π.χ. Α1-Σ2" value="<?= h($v['location']??'') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Έκδοση</label>
                    <input type="text" name="edition" class="form-control" value="<?= h($v['edition']??'') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Τόμος</label>
                    <input type="text" name="volume" class="form-control" value="<?= h($v['volume']??'') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Περιγραφή / Σημειώσεις</label>
                    <textarea name="description" class="form-control" rows="4"><?= h($v['description']??'') ?></textarea>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-panel mb-3">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f3f4f6">Ταξινόμηση</h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Κατηγορία</label>

                    <!-- Κρυφό select για το POST — ενημερώνεται από τη SearchableSelect -->
                    <select name="category_id" id="sel-cat" style="display:none">
                        <option value="">— Χωρίς κατηγορία —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($v['category_id']??'')==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Ορατό searchable dropdown κατηγορίας -->
                    <div class="ss-wrap" id="ss-cat">
                        <div class="ss-input-wrap" id="ss-cat-wrap">
                            <i class="bi bi-tag ss-icon"></i>
                            <input type="text" class="ss-display" autocomplete="off" placeholder="Κατηγορία" data-ss="cat">
                            <button type="button" class="ss-clear" tabindex="-1">×</button>
                        </div>
                        <div class="ss-panel" id="ss-panel-cat"></div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Εκδότης</label>

                    <!-- Κρυφό select για το POST — ενημερώνεται από τη SearchableSelect -->
                    <select name="publisher_id" id="sel-pub" style="display:none">
                        <option value="">— Χωρίς εκδότη —</option>
                        <?php foreach ($publishers as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($v['publisher_id']??'')==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Ορατό searchable dropdown εκδότη -->
                    <div class="ss-wrap" id="ss-pub">
                        <div class="ss-input-wrap" id="ss-pub-wrap">
                            <i class="bi bi-building ss-icon"></i>
                            <input type="text" class="ss-display" autocomplete="off" placeholder="Εκδότης" data-ss="pub">
                            <button type="button" class="ss-clear" tabindex="-1">×</button>
                        </div>
                        <div class="ss-panel" id="ss-panel-pub"></div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Κατάσταση</label>
                    <select name="status" class="form-select">
                        <?php foreach (['Διαθέσιμο','Μη Διαθέσιμο','Σε Επεξεργασία'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($v['status']??'Διαθέσιμο')===$s?'selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_public" id="is_public" <?= ($v['is_public']??1)?'checked':'' ?>>
                        <label class="form-check-label" for="is_public" style="font-size:13px">Δημόσια ορατό</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f3f4f6">Εξώφυλλο</h6>
            <label class="form-label">URL Εικόνας</label>
            <input type="url" name="cover_url" class="form-control" placeholder="https://..." value="<?= h($v['cover_url']??'') ?>" id="coverUrl">
            <div class="mt-2 text-center" id="coverPreview" style="<?= empty($v['cover_url'])?'display:none':'' ?>">
                <img src="<?= h($v['cover_url']??'') ?>" style="max-height:150px;max-width:100%;border-radius:6px;border:1px solid #e5e7eb">
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-gold px-4"><?= $isEdit ? 'Αποθήκευση Αλλαγών' : 'Δημιουργία Καταχώρησης' ?></button>
            <a href="<?= $isEdit ? 'book.php?id='.$book['id'] : 'catalog.php' ?>" class="btn btn-outline-secondary">Ακύρωση</a>
        </div>
    </div>
</div>
</form>

<?php $extraJs = '
<script>
/*
 * Δεδομένα από PHP → JavaScript για τα τρία searchable dropdowns.
 * Μορφή: [{value: "...", label: "..."}]
 */
const SS_DATA = {
    type: ' . $jsTypes . ',
    cat:  ' . $jsCategories . ',
    pub:  ' . $jsPublishers . '
};

/*
 * SearchableSelect — επαναχρησιμοποιήσιμη class για searchable dropdowns.
 *
 * Πώς δουλεύει:
 *   - Κρύβει το αρχικό <select> και δείχνει ένα text input με floating panel.
 *   - Το panel φιλτράρεται σε real-time καθώς ο χρήστης πληκτρολογεί.
 *   - Όταν επιλεγεί τιμή, ενημερώνεται το κρυφό <select> ώστε
 *     να σταλεί σωστά στο POST της φόρμας.
 *   - Υποστηρίζει πλοήγηση με πληκτρολόγιο (Enter, Escape).
 */
class SearchableSelect {
    constructor(wrapId, items, selId, placeholder, allLabel) {
        this.wrap     = document.getElementById(wrapId);
        this.input    = this.wrap.querySelector(".ss-display");
        this.panel    = this.wrap.querySelector(".ss-panel");
        this.clearBtn = this.wrap.querySelector(".ss-clear");
        this.inputWrap= this.wrap.querySelector(".ss-input-wrap");
        this.hidden   = document.getElementById(selId); // το κρυφό <select>
        this.items    = items;
        this.allLabel = allLabel || "Όλα";
        this.selected = { value: "", label: "" };
        this.MAX      = 10; // μέγιστος αριθμός ορατών αποτελεσμάτων

        this._initFromHidden();
        this._bind();
    }

    // Αν το κρυφό select έχει ήδη επιλεγμένη τιμή (π.χ. σε edit mode),
    // προ-συμπληρώνουμε το text input ανάλογα
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

        // Κλείσιμο panel όταν κάνουμε click αλλού στη σελίδα
        document.addEventListener("click", e => {
            if (!this.wrap.contains(e.target)) this._close();
        });

        this.input.addEventListener("keydown", e => {
            if (e.key === "Escape") { this._close(); this.input.blur(); }
            if (e.key === "Enter")  { e.preventDefault(); this._selectFirst(); }
        });
    }

    _open() {
        this.wrap.classList.add("open");
        this._render(this.input.value);
    }

    // Κλείσιμο: επαναφορά του text input στην επιλεγμένη τιμή
    _close() {
        this.wrap.classList.remove("open");
        this.input.value = this.selected.value ? this.selected.label : "";
    }

    // Δημιουργία λίστας αποτελεσμάτων βάσει του query
    _render(query = "") {
        const q       = query.trim().toLowerCase();
        const matched = q
            ? this.items.filter(i => i.label.toLowerCase().includes(q))
            : this.items;
        const shown  = matched.slice(0, this.MAX);
        const more   = matched.length - shown.length; // πόσα δεν χωράνε

        let html = "";

        // "Όλα" option — επιλογή χωρίς φίλτρο / καθαρισμός επιλογής
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
            // Ειδοποίηση όταν υπάρχουν κρυμμένα αποτελέσματα
            if (more > 0) {
                html += `<div class="ss-more">+${more} ακόμα — πληκτρολογήστε για περισσότερα</div>`;
            }
        }

        this.panel.innerHTML = html;

        // mousedown αντί για click για να μη χαθεί το focus πριν ενημερωθεί η τιμή
        this.panel.querySelectorAll(".ss-opt").forEach(btn => {
            btn.addEventListener("mousedown", e => {
                e.preventDefault();
                this._select({ value: btn.dataset.value, label: btn.dataset.label });
            });
        });
    }

    // Ενημέρωση επιλογής: αποθήκευση τιμής + ενημέρωση κρυφού select
    _select(item, close = true) {
        this.selected     = item;
        this.hidden.value = item.value;

        if (item.value) {
            this.input.value = item.label;
            this.inputWrap.classList.add("has-value");
        } else {
            this.input.value = "";
            this.inputWrap.classList.remove("has-value");
        }
        if (close) this._close();
    }

    _clear() {
        this._select({ value: "", label: "" });
        this.input.focus();
        this._open();
    }

    // Επιλογή πρώτου αποτελέσματος με Enter
    _selectFirst() {
        const first = this.panel.querySelector(".ss-opt:not(.ss-opt-all)");
        if (first) first.dispatchEvent(new MouseEvent("mousedown"));
    }
}

// Αρχικοποίηση των τριών searchable dropdowns μόλις φορτώσει η σελίδα
document.addEventListener("DOMContentLoaded", function () {
    new SearchableSelect("ss-type", SS_DATA.type, "sel-type", "Τύπος", "Όλοι οι Τύποι");
    new SearchableSelect("ss-cat",  SS_DATA.cat,  "sel-cat",  "Κατηγορία", "Όλες οι Κατηγορίες");
    new SearchableSelect("ss-pub",  SS_DATA.pub,  "sel-pub",  "Εκδότης", "Όλοι οι Εκδότες");

    // Live preview εξωφύλλου: ενημερώνει την εικόνα καθώς πληκτρολογεί ο χρήστης το URL
    const coverInput = document.getElementById("coverUrl");
    if (coverInput) {
        coverInput.addEventListener("input", function() {
            const preview = document.getElementById("coverPreview");
            if (this.value) {
                preview.style.display = "block";
                preview.querySelector("img").src = this.value;
            } else {
                preview.style.display = "none";
            }
        });
    }
});
</script>';
include 'layout_admin_end.php';
?>