<?php
require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι/admins έχουν πρόσβαση
$db = getDB();

$id = (int)($_GET['id'] ?? 0);

// Φόρτωση βιβλίου μαζί με τα ονόματα κατηγορίας και εκδότη μέσω JOIN
$stmt = $db->prepare("
    SELECT b.*, c.name as cat_name, p.name as pub_name
    FROM books b
    LEFT JOIN categories c ON b.category_id=c.id
    LEFT JOIN publishers p ON b.publisher_id=p.id
    WHERE b.id=?
");
$stmt->execute([$id]);
$book = $stmt->fetch();

if (!$book) {
    flash('Δεν βρέθηκε.', 'error');
    header('Location: catalog.php'); exit;
}

/* ─────────────────────────────────────────────────────────────
   ΛΟΓΙΚΗ ΕΞΟΥΣΙΟΔΟΤΗΣΗΣ
   - Admin: πλήρης πρόσβαση σε όλα τα βιβλία
   - Υπάλληλος: μπορεί να επεξεργαστεί/διαγράψει ΜΟΝΟ αν
     είναι ο ίδιος που το πρόσθεσε (created_by == session user_id)
───────────────────────────────────────────────────────────── */
$ownerId = (int)($book['created_by'] ?? 0);
$meId    = (int)($_SESSION['user_id'] ?? 0);

$canManage = isAdmin() || ($ownerId > 0 && $ownerId === $meId);

/* ─────────────────────────────────────────────────────────────
   POST: αίτημα άδειας από υπάλληλο προς διαχειριστή.
   Στέλνει εσωτερικό μήνυμα και καταγράφεται στο audit log.
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_permission') {

    $reqAction = ($_POST['req_action'] ?? '') === 'delete' ? 'delete' : 'edit';
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') $reason = '—';

    // Εύρεση πρώτου ενεργού admin για αποστολή μηνύματος
    $adminId = (int)$db->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();

    if ($adminId <= 0) {
        flash('Δεν βρέθηκε διαθέσιμος διαχειριστής για αποστολή αιτήματος.', 'error');
        header('Location: book.php?id=' . $id); exit;
    }

    $niceAction = $reqAction === 'delete' ? 'Διαγραφή' : 'Επεξεργασία';
    $who = $_SESSION['username'] ?? ('User#' . $meId);

    $subject = "Αίτημα άδειας: {$niceAction} βιβλίου #{$book['id']}";
    $body =
        "Ο υπάλληλος: {$who}\n" .
        "Ζητά άδεια για: {$niceAction}\n\n" .
        "Αιτιολογία:\n" .
        $reason . "\n\n" .
        "Στοιχεία βιβλίου:\n" .
        "- ID: {$book['id']}\n" .
        "- Τίτλος: {$book['title']}\n" .
        "- Συγγραφέας: {$book['author']}\n" .
        "- ISBN: " . ($book['isbn'] ?: '—') . "\n\n" .
        "Παρακαλώ εγκρίνετε/αναλάβετε την ενέργεια από διαχειριστή.";

    $db->prepare("INSERT INTO messages (from_user, to_user, subject, body) VALUES (?,?,?,?)")
       ->execute([$meId, $adminId, $subject, $body]);

    // Καταγραφή αιτήματος — mb_substr για να μην κοπεί UTF-8 χαρακτήρας στη μέση
    try {
        $db->prepare("INSERT INTO audit_log (user_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
           ->execute([$meId, 'request_permission', 'book', $book['id'], "Requested {$reqAction} | Reason: " . mb_substr($reason, 0, 160)]);
    } catch (Exception $e) {}

    flash('Το αίτημα στάλθηκε στον διαχειριστή.');
    header('Location: book.php?id=' . $id); exit;
}

$pageTitle  = $book['title'];
$activePage = 'catalog';
include 'layout_admin.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="catalog.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Κατάλογος</a>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-panel">
            <div class="d-flex align-items-start gap-4">
                <?php if ($book['cover_url']): ?>
                <img src="<?= h($book['cover_url']) ?>" style="width:100px;border-radius:8px;border:1px solid #e5e7eb;object-fit:cover">
                <?php else: ?>
                <div style="width:100px;height:140px;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:32px;color:#d1d5db;flex-shrink:0">
                    <i class="bi bi-book"></i>
                </div>
                <?php endif; ?>
                <div style="flex:1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge-type <?= $book['type']==='Βιβλίο'?'badge-book':($book['type']==='Περιοδικό'?'badge-magazine':'badge-other') ?>"><?= h($book['type']) ?></span>
                        <span class="badge-type <?= $book['status']==='Διαθέσιμο'?'badge-available':($book['status']==='Μη Διαθέσιμο'?'badge-unavailable':'badge-processing') ?>"><?= h($book['status']) ?></span>
                        <?php if (!$book['is_public']): ?>
                        <span class="badge-type" style="background:#fef3c7;color:#92400e"><i class="bi bi-lock-fill me-1"></i>Ιδιωτικό</span>
                        <?php endif; ?>
                    </div>
                    <h1 style="font-family:'Playfair Display',serif;font-size:24px;margin:8px 0 4px"><?= h($book['title']) ?></h1>
                    <p style="font-size:16px;color:#374151;margin:0 0 16px"><?= h($book['author']) ?></p>
                    <?php if ($book['description']): ?>
                    <p style="font-size:14px;color:var(--text-muted);line-height:1.7"><?= nl2br(h($book['description'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Λεπτομέρειες</h6>
            <div class="row g-3">
                <?php
                // Πίνακας [label, τιμή] — τα κενά πεδία παραλείπονται αυτόματα
                $details = [
                    ['ISBN / ISSN', $book['isbn']],
                    ['Κατηγορία', $book['cat_name']],
                    ['Εκδότης', $book['pub_name']],
                    ['Έτος', $book['year']],
                    ['Γλώσσα', $book['language']],
                    ['Σελίδες', $book['pages']],
                    ['Έκδοση', $book['edition']],
                    ['Τόμος', $book['volume']],
                    ['Τοποθεσία', $book['location']],
                    ['Καταχωρήθηκε', date('d/m/Y', strtotime($book['created_at']))],
                ];
                foreach ($details as [$label, $val]):
                    if (!$val) continue;
                ?>
                <div class="col-sm-4 col-lg-3">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#9ca3af;font-weight:600"><?= $label ?></div>
                    <div style="font-size:14px;color:#1a1a2e;margin-top:2px;font-weight:500"><?= h($val) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Ενέργειες</h6>
            <div class="d-grid gap-2">

                <?php if ($canManage): ?>
                    <!-- Ο χρήστης έχει δικαίωμα διαχείρισης (admin ή ιδιοκτήτης εγγραφής) -->
                    <a href="add_book.php?id=<?= $book['id'] ?>" class="btn btn-gold">
                        <i class="bi bi-pencil me-2"></i>Επεξεργασία
                    </a>

                    <?php if (isAdmin()): ?>
                        <!-- Μόνο ο admin μπορεί να διαγράψει απευθείας -->
                        <button class="btn btn-outline-secondary" style="font-size:13px" onclick="confirmDelete()">
                            <i class="bi bi-trash me-2"></i>Διαγραφή
                        </button>
                    <?php else: ?>
                        <!-- Υπάλληλος-ιδιοκτήτης: αποστέλλει αίτημα διαγραφής στον admin -->
                        <button class="btn btn-outline-secondary" style="font-size:13px" onclick="openRequestModal('delete')">
                            <i class="bi bi-trash me-2"></i>Διαγραφή (Αίτημα)
                        </button>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- Ο χρήστης δεν έχει δικαίωμα — μπορεί μόνο να στείλει αίτημα -->
                    <button class="btn btn-gold" type="button" onclick="openRequestModal('edit')">
                        <i class="bi bi-pencil me-2"></i>Επεξεργασία (Αίτημα)
                    </button>
                    <button class="btn btn-outline-secondary" style="font-size:13px" onclick="openRequestModal('delete')">
                        <i class="bi bi-trash me-2"></i>Διαγραφή (Αίτημα)
                    </button>

                    <div style="font-size:12px;color:#9ca3af;line-height:1.5;margin-top:6px">
                        Δεν έχετε δικαίωμα αλλαγής αυτού του αντικειμένου. Μπορείτε να στείλετε αίτημα στον διαχειριστή.
                    </div>
                <?php endif; ?>

                <a href="catalog.php" class="btn btn-outline-secondary" style="font-size:13px">
                    <i class="bi bi-list me-2"></i>Πίσω στον Κατάλογο
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal διαγραφής — εμφανίζεται μόνο σε admins -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Διαγραφή;</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" style="font-size:13px">Να διαγραφεί το «<?= h($book['title']) ?>»; Η ενέργεια δεν αναιρείται.</div>
            <div class="modal-footer">
                <!-- Υποβολή στο catalog.php που χειρίζεται τη διαγραφή -->
                <form method="POST" action="catalog.php">
                    <input type="hidden" name="delete_id" value="<?= $book['id'] ?>">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-sm btn-danger">Διαγραφή</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal αιτήματος άδειας — για υπαλλήλους και κλειδωμένες εγγραφές -->
<div class="modal fade" id="requestModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Αίτημα Άδειας από Διαχειριστή</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" style="font-size:13px">
        <!-- Συμπληρώνεται δυναμικά από JS με τα στοιχεία του βιβλίου -->
        <div id="requestModalBody" class="mb-3"></div>

        <label class="form-label mb-1" for="reqReason" style="font-weight:600">Αιτιολογία / Τι θέλετε να κάνετε;</label>
        <textarea
          class="form-control"
          id="reqReason"
          rows="4"
          placeholder="Π.χ. Λάθος στοιχεία / διπλοεγγραφή / χρειάζεται διόρθωση ISBN / αλλαγή κατάστασης..."
          style="font-size:13px"
          required
        ></textarea>

        <div style="font-size:12px;color:#6b7280;margin-top:8px">
          Θα σταλεί μήνυμα στον διαχειριστή με ID/ISBN/Τίτλο και την αιτιολογία σας.
        </div>
      </div>

      <div class="modal-footer">
        <form method="POST" id="reqForm">
          <input type="hidden" name="action" value="request_permission">
          <input type="hidden" name="req_action" id="reqAction">
          <!-- Η αιτιολογία αντιγράφεται εδώ από το textarea πριν το submit (βλ. JS) -->
          <input type="hidden" name="reason" id="reqReasonHidden">

          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
          <button type="submit" class="btn btn-sm btn-gold">Αποστολή Αιτήματος</button>
        </form>
      </div>

    </div>
  </div>
</div>

<?php
$extraJs = '
<script>
function confirmDelete(){
    new bootstrap.Modal(document.getElementById("deleteModal")).show();
}

/*
 * Ανοίγει το modal αιτήματος και το προ-συμπληρώνει δυναμικά
 * με τα στοιχεία του βιβλίου και τον τύπο ενέργειας (edit/delete).
 * Τα δεδομένα βιβλίου εγχύονται από PHP ως JS literals.
 */
function openRequestModal(act){
    document.getElementById("reqAction").value = (act === "delete") ? "delete" : "edit";

    const nice  = (act === "delete") ? "Διαγραφή" : "Επεξεργασία";
    const id    = ' . (int)$book['id'] . ';
    const title = ' . json_encode($book['title']) . ';
    const isbn  = ' . json_encode($book['isbn'] ?: '') . ';

    const isbnTxt = isbn ? ("ISBN: " + isbn) : "ISBN: —";
    document.getElementById("requestModalBody").innerHTML =
        "<div style=\\"font-weight:700\\">" + nice + " αντικειμένου</div>" +
        "<div style=\\"margin-top:6px\\">ID: <b>" + id + "</b></div>" +
        "<div>Τίτλος: <b>" + title + "</b></div>" +
        "<div>" + isbnTxt + "</div>";

    const reason = document.getElementById("reqReason");
    if (reason) { reason.value = ""; reason.style.borderColor = ""; }

    new bootstrap.Modal(document.getElementById("requestModal")).show();
}

document.addEventListener("DOMContentLoaded", function(){
    const form = document.getElementById("reqForm");
    if (!form) return;

    form.addEventListener("submit", function(e){
        const reasonUi = document.getElementById("reqReason");
        const hidden   = document.getElementById("reqReasonHidden");
        const val      = (reasonUi ? reasonUi.value.trim() : "");

        // Validation: αποτροπή submit χωρίς αιτιολογία
        if (!val) {
            e.preventDefault();
            if (reasonUi) { reasonUi.focus(); reasonUi.style.borderColor = "#ef4444"; }
            return;
        }
        // Αντιγραφή τιμής από το ορατό textarea στο hidden input πριν το POST
        if (hidden) hidden.value = val;
    });
});
</script>
';
include 'layout_admin_end.php';
?>