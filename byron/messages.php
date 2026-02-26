<?php
require 'config.php';
requireEmployee(); // Μόνο υπάλληλοι/admins έχουν πρόσβαση στο σύστημα μηνυμάτων
$db = getDB();

/* ────────────────── POST ACTIONS ────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $to   = (int)($_POST['to_user'] ?? 0);
        $subj = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');

        if ($to && $body) {
            // Επαλήθευση ότι ο παραλήπτης υπάρχει και είναι ενεργός —
            // αποτρέπει αποστολή σε διαγραμμένους/ανενεργούς λογαριασμούς
            $chk = $db->prepare("SELECT id FROM users WHERE id=? AND active=1");
            $chk->execute([$to]);
            if ($chk->fetch()) {
                $db->prepare("INSERT INTO messages (from_user, to_user, subject, body) VALUES (?,?,?,?)")
                   ->execute([$_SESSION['user_id'], $to, $subj ?: '(χωρίς θέμα)', $body]);
                // Audit log — καταγράφουμε μόνο τον παραλήπτη, όχι το περιεχόμενο
                $db->prepare("INSERT INTO audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
                   ->execute([$_SESSION['user_id'], 'send_message', 'user', "To: {$to}"]);
                flash('Το μήνυμα εστάλη επιτυχώς.');
            } else {
                flash('Ο παραλήπτης δεν βρέθηκε.', 'error');
            }
        } else {
            flash('Παρακαλώ συμπληρώστε παραλήπτη και κείμενο.', 'error');
        }

    } elseif ($action === 'delete') {
        $mid = (int)($_POST['msg_id'] ?? 0);
        // Επιτρέπουμε διαγραφή τόσο στον αποστολέα όσο και στον παραλήπτη —
        // ο χρήστης βλέπει το μήνυμα και στα "Εισερχόμενα" και στα "Απεσταλμένα"
        $db->prepare("DELETE FROM messages WHERE id=? AND (to_user=? OR from_user=?)")
           ->execute([$mid, $_SESSION['user_id'], $_SESSION['user_id']]);
        flash('Το μήνυμα διαγράφηκε.');

    } elseif ($action === 'mark_all_read') {
        $db->prepare("UPDATE messages SET is_read=1 WHERE to_user=? AND is_read=0")
           ->execute([$_SESSION['user_id']]);
        flash('Όλα τα μηνύματα σημειώθηκαν ως αναγνωσμένα.');

    } elseif ($action === 'reply') {
        $to   = (int)($_POST['to_user'] ?? 0);
        $subj = trim($_POST['subject'] ?? ''); // Έρχεται pre-filled ως "Re: [original subject]"
        $body = trim($_POST['body'] ?? '');
        if ($to && $body) {
            $db->prepare("INSERT INTO messages (from_user, to_user, subject, body) VALUES (?,?,?,?)")
               ->execute([$_SESSION['user_id'], $to, $subj, $body]);
            flash('Η απάντηση εστάλη.');
        } else {
            flash('Το κείμενο απάντησης δεν μπορεί να είναι κενό.', 'error');
        }
    }

    /*
     * Μετά από κάθε POST, redirect στο ίδιο tab και μήνυμα (αν ήταν ανοιχτό).
     * Αυτό αποτρέπει το "form resubmission" warning στο F5 (PRG pattern).
     */
    $tab = $_POST['tab'] ?? 'inbox';
    $redirect = 'messages.php?tab=' . urlencode($tab);
    if (!empty($_POST['view'])) $redirect .= '&view=' . (int)$_POST['view'];
    header("Location: {$redirect}"); exit;
}

/* ────────────────── READ STATE ────────────────── */
$tab    = $_GET['tab']  ?? 'inbox';
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;
$search = trim($_GET['search'] ?? '');

// Σημειώνουμε το μήνυμα ως αναγνωσμένο μόλις ανοιχτεί —
// μόνο αν ο χρήστης είναι ο παραλήπτης (inbox), όχι αν βλέπει ό,τι έστειλε (sent)
if ($viewId && $tab === 'inbox') {
    $db->prepare("UPDATE messages SET is_read=1 WHERE id=? AND to_user=?")
       ->execute([$viewId, $_SESSION['user_id']]);
}

/*
 * Δυναμικό query ανάλογα με tab:
 * - inbox: αναζητούμε μηνύματα ΠΡΟς τον χρήστη → JOIN με from_user για το όνομα αποστολέα
 * - sent:  αναζητούμε μηνύματα ΑΠΟ τον χρήστη → JOIN με to_user για το όνομα παραλήπτη
 * Και στις δύο περιπτώσεις το αποτέλεσμα επιστρέφεται ως 'other_name' για ενιαία χρήση στο template.
 */
if ($tab === 'sent') {
    $listQ = "SELECT m.*, u.username AS other_name, u.role AS other_role
              FROM messages m LEFT JOIN users u ON u.id = m.to_user
              WHERE m.from_user = ?";
    $listP = [$_SESSION['user_id']];
} else {
    $listQ = "SELECT m.*, u.username AS other_name, u.role AS other_role
              FROM messages m LEFT JOIN users u ON u.id = m.from_user
              WHERE m.to_user = ?";
    $listP = [$_SESSION['user_id']];
}
if ($search) {
    // Full-text search σε subject, body και username — ίδια λογική με το positional binding του csv_import
    $listQ .= " AND (m.subject LIKE ? OR m.body LIKE ? OR u.username LIKE ?)";
    $listP[] = "%$search%"; $listP[] = "%$search%"; $listP[] = "%$search%";
}
$listQ .= " ORDER BY m.created_at DESC";
$listStmt = $db->prepare($listQ);
$listStmt->execute($listP);
$messages = $listStmt->fetchAll();

// Αδιάβαστα — χρησιμοποιείται τόσο στο badge του header όσο και στο sidebar
$unreadStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE to_user=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/*
 * Φόρτωση ανοιχτού μηνύματος — JOIN και με τους δύο users (from + to) ώστε να
 * εμφανίζονται και τα στοιχεία αποστολέα ΚΑΙ παραλήπτη στο read pane.
 * Ο έλεγχος (to_user=? OR from_user=?) εξασφαλίζει ότι ο χρήστης μπορεί να δει
 * μόνο μηνύματα που του ανήκουν — αποτρέπει ανάγνωση μηνυμάτων άλλων μέσω ?view=ID.
 */
$viewMsg = null;
if ($viewId) {
    $stmt = $db->prepare("
        SELECT m.*,
               fu.username AS from_name, fu.role AS from_role,
               tu.username AS to_name,   tu.role AS to_role
        FROM messages m
        LEFT JOIN users fu ON fu.id = m.from_user
        LEFT JOIN users tu ON tu.id = m.to_user
        WHERE m.id = ? AND (m.to_user = ? OR m.from_user = ?)");
    $stmt->execute([$viewId, $_SESSION['user_id'], $_SESSION['user_id']]);
    $viewMsg = $stmt->fetch();
}

/*
 * Λίστα παραληπτών για το compose modal — ο ίδιος ο χρήστης εξαιρείται
 * (δεν έχει νόημα να στείλει μήνυμα στον εαυτό του).
 * Ταξινόμηση κατά role → username για πιο χρηστική ομαδοποίηση στο dropdown.
 */
$usersStmt = $db->query("SELECT id, username, role FROM users
                          WHERE id != " . (int)$_SESSION['user_id'] . " AND active=1
                          ORDER BY role, username");
$allUsers = $usersStmt->fetchAll();

$pageTitle  = 'Μηνύματα';
$activePage = 'messages';
include 'layout_admin.php';
?>

<div class="page-header d-flex align-items-start justify-content-between mb-3">
    <div>
        <h1>Μηνύματα &amp; Chat</h1>
        <p>Εσωτερική αλληλογραφία μεταξύ υπαλλήλων
            <?php if ($unreadCount > 0): ?>
            — <span style="color:var(--gold);font-weight:700"><?= $unreadCount ?> αδιάβαστα</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($unreadCount > 0): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="mark_all_read">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <button type="submit" class="act-btn"><i class="bi bi-check2-all"></i> Όλα αναγνωσμένα</button>
        </form>
        <?php endif; ?>
        <button type="button" class="act-btn gold" id="btnOpenCompose">
            <i class="bi bi-pencil-square"></i> Νέο Μήνυμα
        </button>
    </div>
</div>

<!-- MESSAGES LAYOUT — δύο στήλες: λίστα + read pane -->
<div class="msg-layout">

    <!-- LIST COLUMN -->
    <div class="msg-list-col">

        <!-- Tabs — διατηρούν το search param στο URL κατά την εναλλαγή -->
        <div class="msg-tabs">
            <a href="?tab=inbox<?= $search ? '&search='.urlencode($search) : '' ?>" class="msg-tab <?= $tab==='inbox'?'active':'' ?>">
                <i class="bi bi-inbox me-1"></i>Εισερχόμενα
                <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger ms-1" style="font-size:9px;padding:2px 5px"><?= $unreadCount ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=sent<?= $search ? '&search='.urlencode($search) : '' ?>" class="msg-tab <?= $tab==='sent'?'active':'' ?>">
                <i class="bi bi-send me-1"></i>Απεσταλμένα
            </a>
        </div>

        <!-- Search — GET form ώστε το search να φαίνεται στο URL και να διατηρείται σε refresh -->
        <div class="msg-search">
            <form method="GET">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <div class="msg-search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Αναζήτηση..." value="<?= h($search) ?>">
                </div>
            </form>
        </div>

        <div class="msg-list-body">
            <?php if (empty($messages)): ?>
            <div style="text-align:center;padding:40px 16px;color:#9ca3af;font-size:13px">
                <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:10px;opacity:0.25"></i>
                <?= $search ? 'Δεν βρέθηκαν αποτελέσματα' : 'Κανένα μήνυμα' ?>
            </div>
            <?php else: ?>
            <?php foreach ($messages as $msg):
                $isActive = ($viewId == $msg['id']);  // Τρέχον ανοιχτό μήνυμα
                $isUnread = ($tab === 'inbox' && !$msg['is_read']); // Μόνο στα εισερχόμενα
            ?>
            <a href="?tab=<?= $tab ?>&view=<?= $msg['id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>"
               class="msg-item<?= $isActive?' active-msg':'' ?><?= $isUnread?' unread':'' ?>">
                <div class="msg-sender">
                    <span style="display:flex;align-items:center;gap:7px">
                        <?php if ($isUnread): ?><span class="unread-dot"></span><?php endif; ?>
                        <?= h($msg['other_name'] ?? '—') ?>
                        <span class="role-chip <?= h($msg['other_role'] ?? '') ?>"><?= h($msg['other_role'] ?? '') ?></span>
                    </span>
                    <span class="msg-time"><?= date('d/m H:i', strtotime($msg['created_at'])) ?></span>
                </div>
                <div class="msg-subject"><?= h($msg['subject'] ?: '(χωρίς θέμα)') ?></div>
                <!-- Preview: πρώτοι 70 χαρακτήρες του body για preview στη λίστα -->
                <div class="msg-preview"><?= h(mb_substr($msg['body'], 0, 70)) ?></div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div><!-- /msg-list-col -->

    <!-- READ PANE — εμφανίζει το επιλεγμένο μήνυμα ή placeholder -->
    <div class="msg-read-col">
        <?php if ($viewMsg): ?>
        <div class="msg-read-toolbar">
            <a href="?tab=<?= h($tab) ?>" class="act-btn"><i class="bi bi-arrow-left"></i></a>
            <!-- Διαγραφή — προστατευμένη από τον server-side έλεγχο (to_user OR from_user) -->
            <form method="POST" class="d-inline ms-auto">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="msg_id" value="<?= $viewMsg['id'] ?>">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <button type="submit" class="act-btn danger" onclick="return confirm('Διαγραφή μηνύματος;')">
                    <i class="bi bi-trash"></i> Διαγραφή
                </button>
            </form>
        </div>
        <div class="msg-read-body">
            <div class="msg-read-subject"><?= h($viewMsg['subject'] ?: '(χωρίς θέμα)') ?></div>
            <div class="msg-read-meta">
                <span style="display:flex;align-items:center;gap:8px">
                    <!-- Avatar: πρώτο γράμμα username (uppercase) -->
                    <span class="msg-avatar"><?= strtoupper(substr($viewMsg['from_name'],0,1)) ?></span>
                    Από: <strong><?= h($viewMsg['from_name']) ?></strong>
                    <span class="role-chip <?= h($viewMsg['from_role']) ?>"><?= h($viewMsg['from_role']) ?></span>
                </span>
                <span>Προς: <strong><?= h($viewMsg['to_name']) ?></strong></span>
                <span><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($viewMsg['created_at'])) ?></span>
            </div>
            <div class="msg-body-text"><?= h($viewMsg['body']) ?></div>
        </div>

        <?php if ($viewMsg['from_user'] != $_SESSION['user_id']): ?>
        <!--
            Το reply form εμφανίζεται μόνο αν ο χρήστης δεν είναι ο αποστολέας —
            δεν έχει νόημα να απαντήσεις σε δικό σου μήνυμα.
            Το subject pre-filled ως "Re: [original]" μέσω hidden input.
            Μετά την αποστολή, γίνεται redirect στο "sent" tab για να δει ο χρήστης
            την απάντησή του στα απεσταλμένα.
        -->
        <div class="reply-area">
            <h6><i class="bi bi-reply me-1"></i>Γρήγορη Απάντηση</h6>
            <form method="POST">
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="to_user" value="<?= $viewMsg['from_user'] ?>">
                <input type="hidden" name="subject" value="Re: <?= h($viewMsg['subject']) ?>">
                <input type="hidden" name="tab" value="sent">
                <textarea name="body" class="reply-textarea" placeholder="Γράψτε την απάντησή σας…" required></textarea>
                <div class="d-flex justify-content-end mt-2">
                    <button type="submit" class="act-btn gold">
                        <i class="bi bi-send"></i> Αποστολή Απάντησης
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Placeholder όταν δεν είναι ανοιχτό κανένα μήνυμα -->
        <div class="msg-empty">
            <i class="bi bi-chat-square-dots"></i>
            <div style="font-size:15px;font-weight:600;color:#374151;margin-bottom:6px">Επιλέξτε μήνυμα</div>
            <p style="font-size:13px;max-width:280px">Κάντε κλικ σε ένα μήνυμα από τη λίστα αριστερά για να το δείτε.</p>
            <button type="button" class="act-btn gold mt-3" id="btnOpenCompose2">
                <i class="bi bi-pencil-square"></i> Νέο Μήνυμα
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!--
    COMPOSE MODAL — υλοποιημένο με pure HTML/CSS overlay, χωρίς εξάρτηση από Bootstrap modal.
    Λόγος: το Bootstrap modal απαιτεί data-bs-target attributes και μπορεί να έχει
    conflicts με άλλα modals στη σελίδα. Ο custom overlay είναι πιο προβλέψιμος.
-->
<div id="composeOverlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:0;max-width:560px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden">
        <div style="padding:18px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between">
            <h5 style="margin:0;font-family:'Playfair Display',serif;font-size:18px">
                <i class="bi bi-pencil-square me-2" style="color:var(--gold)"></i>Νέο Μήνυμα
            </h5>
            <button type="button" onclick="closeCompose()" style="background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;line-height:1">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateCompose(this)">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <div style="padding:20px 24px">
                <div class="mb-3">
                    <label class="form-label">Προς <span class="text-danger">*</span></label>
                    <!--
                        Ομαδοποίηση παραληπτών ανά role με <optgroup> —
                        πιο εύχρηστο σε μεγάλες ομάδες χρηστών.
                        Οι ρόλοι/labels αντιστοιχούνται μέσω του $roleLabels array.
                    -->
                    <select name="to_user" class="form-select" id="composeTo" required>
                        <option value="">— Επιλέξτε παραλήπτη —</option>
                        <?php
                        $grouped    = ['admin'=>[],'employee'=>[],'user'=>[]];
                        foreach ($allUsers as $u) $grouped[$u['role']][] = $u;
                        $roleLabels = ['admin'=>'Διαχειριστές','employee'=>'Υπάλληλοι','user'=>'Χρήστες'];
                        foreach ($grouped as $role => $grp):
                            if (empty($grp)) continue; // Παράλειψη κενών ρόλων
                        ?>
                        <optgroup label="<?= $roleLabels[$role] ?>">
                            <?php foreach ($grp as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['username']) ?> (<?= h($u['role']) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div id="toError" style="color:#ef4444;font-size:12px;margin-top:4px;display:none">Παρακαλώ επιλέξτε παραλήπτη.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Θέμα</label>
                    <input type="text" name="subject" class="form-control" placeholder="Θέμα μηνύματος…" maxlength="200">
                </div>
                <div class="mb-2">
                    <label class="form-label">Μήνυμα <span class="text-danger">*</span></label>
                    <textarea name="body" id="composeBody" class="form-control" rows="6" required placeholder="Γράψτε το μήνυμά σας…"></textarea>
                    <div id="bodyError" style="color:#ef4444;font-size:12px;margin-top:4px;display:none">Παρακαλώ γράψτε το μήνυμά σας.</div>
                </div>
            </div>
            <div style="padding:14px 24px;border-top:1px solid #f3f4f6;background:#fafafa;display:flex;justify-content:flex-end;gap:8px">
                <button type="button" onclick="closeCompose()" class="btn btn-secondary">Ακύρωση</button>
                <button type="submit" class="btn btn-gold">
                    <i class="bi bi-send me-1"></i>Αποστολή
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════════════════
   Compose modal — pure JS, χωρίς Bootstrap dependency
   Λόγος για custom υλοποίηση: βλ. σχόλιο στο HTML παραπάνω
══════════════════════════════════════════════════════════════ */
function openCompose() {
    var overlay = document.getElementById('composeOverlay');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Αποτροπή scroll πίσω από το modal
    // Reset error messages από προηγούμενη αποτυχημένη προσπάθεια
    document.getElementById('toError').style.display   = 'none';
    document.getElementById('bodyError').style.display = 'none';
    // Μικρό delay πριν το focus — αποφεύγει race condition με το display transition
    setTimeout(function() { document.getElementById('composeTo').focus(); }, 100);
}

function closeCompose() {
    document.getElementById('composeOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

/* Client-side validation — επιπλέον επίπεδο πέρα από το required του HTML
   Εμφανίζει inline errors αντί για browser default alert bubbles */
function validateCompose(form) {
    var ok   = true;
    var to   = document.getElementById('composeTo').value;
    var body = document.getElementById('composeBody').value.trim();

    document.getElementById('toError').style.display   = to   ? 'none' : 'block';
    document.getElementById('bodyError').style.display = body ? 'none' : 'block';

    if (!to || !body) ok = false;
    return ok;
}

// Wiring — δύο buttons ανοίγουν το ίδιο modal (header + empty state)
document.getElementById('btnOpenCompose').addEventListener('click', openCompose);
var btn2 = document.getElementById('btnOpenCompose2');
if (btn2) btn2.addEventListener('click', openCompose); // Μπορεί να μην υπάρχει αν είναι ανοιχτό μήνυμα

// Κλείσιμο με κλικ έξω από το modal dialog
document.getElementById('composeOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeCompose();
});
// Κλείσιμο με Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeCompose();
});
</script>

<?php include 'layout_admin_end.php'; ?>