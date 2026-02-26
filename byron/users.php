<?php
require 'config.php';
requireAdmin(); // Μόνο admins — η διαχείριση χρηστών δεν είναι διαθέσιμη σε employees
$db = getDB();

/* ── POST ACTIONS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $uname = trim($_POST['username'] ?? '');
        $pass  = $_POST['password'] ?? '';
        // Whitelist ρόλων — αποτροπή έγχυσης произвольного role string στη βάση
        $role  = in_array($_POST['role']??'', ['admin','employee','user']) ? $_POST['role'] : 'user';

        if (!$uname || !$pass) {
            flash('Συμπληρώστε username και password.', 'error');
        } elseif (strlen($pass) < 6) {
            flash('Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.', 'error');
        } else {
            // Έλεγχος μοναδικότητας username πριν το INSERT — αποτροπή duplicate key error
            $chk = $db->prepare("SELECT id FROM users WHERE username=?");
            $chk->execute([$uname]);
            if ($chk->fetch()) {
                flash('Το username υπάρχει ήδη.', 'error');
            } else {
                $db->prepare("INSERT INTO users (username,password,role,active) VALUES (?,?,?,1)")
                   ->execute([$uname, password_hash($pass, PASSWORD_DEFAULT), $role]);
                $db->prepare("INSERT INTO audit_log (user_id,action,target_type,details) VALUES (?,?,?,?)")
                   ->execute([$_SESSION['user_id'], 'create_user', 'user', "{$uname} ({$role})"]);
                flash("Ο χρήστης «{$uname}» δημιουργήθηκε.");
            }
        }

    } elseif ($action === 'toggle') {
        $uid = (int)$_POST['uid'];
        // Self-protection — admin δεν μπορεί να απενεργοποιήσει τον εαυτό του
        if ($uid === (int)$_SESSION['user_id']) {
            flash('Δεν μπορείτε να απενεργοποιήσετε τον εαυτό σας.', 'error');
        } else {
            // 1-active: bitwise flip (0→1, 1→0) χωρίς να χρειαστεί να διαβάσουμε πρώτα την τιμή
            $db->prepare("UPDATE users SET active=1-active WHERE id=?")->execute([$uid]);
            flash('Η κατάσταση χρήστη ενημερώθηκε.');
        }

    } elseif ($action === 'change_role') {
        $uid  = (int)$_POST['uid'];
        $role = $_POST['role'] ?? '';
        if ($uid === (int)$_SESSION['user_id']) {
            // Ο admin δεν μπορεί να υποβαθμίσει τον εαυτό του — αποτροπή accidental lockout
            flash('Δεν μπορείτε να αλλάξετε τον ρόλο σας.', 'error');
        } elseif (!in_array($role, ['admin','employee','user'])) {
            flash('Μη έγκυρος ρόλος.', 'error');
        } else {
            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $uid]);
            $db->prepare("INSERT INTO audit_log (user_id,action,target_type,target_id,details) VALUES (?,?,?,?,?)")
               ->execute([$_SESSION['user_id'], 'change_role', 'user', $uid, "Role → {$role}"]);
            flash('Ο ρόλος ενημερώθηκε.');
        }

    } elseif ($action === 'reset_pass') {
        $uid  = (int)$_POST['uid'];
        $pass = $_POST['new_password'] ?? '';
        if (strlen($pass) < 6) {
            flash('Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.', 'error');
        } else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")
               ->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
            // Audit χωρίς τον κωδικό — καταγράφουμε μόνο ότι έγινε reset
            $db->prepare("INSERT INTO audit_log (user_id,action,target_type,target_id) VALUES (?,?,?,?)")
               ->execute([$_SESSION['user_id'], 'reset_password', 'user', $uid]);
            flash('Ο κωδικός ενημερώθηκε.');
        }

    } elseif ($action === 'delete') {
        $uid = (int)$_POST['uid'];
        if ($uid === (int)$_SESSION['user_id']) {
            flash('Δεν μπορείτε να διαγράψετε τον εαυτό σας.', 'error');
        } else {
            // Διαβάζουμε το username ΠΡΙΝ τη διαγραφή για το audit log —
            // μετά το DELETE δεν υπάρχει πια η εγγραφή
            $uRow = $db->prepare("SELECT username FROM users WHERE id=?");
            $uRow->execute([$uid]);
            $uName = $uRow->fetchColumn();
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            $db->prepare("INSERT INTO audit_log (user_id,action,target_type,details) VALUES (?,?,?,?)")
               ->execute([$_SESSION['user_id'], 'delete_user', 'user', $uName]);
            flash("Ο χρήστης «{$uName}» διαγράφηκε.");
        }

    } elseif ($action === 'send_message') {
        $uid  = (int)$_POST['uid'];
        $subj = trim($_POST['msg_subject'] ?? '');
        $body = trim($_POST['msg_body']    ?? '');
        if ($body && $uid) {
            $db->prepare("INSERT INTO messages (from_user,to_user,subject,body) VALUES (?,?,?,?)")
               ->execute([$_SESSION['user_id'], $uid, $subj ?: '(μήνυμα από admin)', $body]);
            flash('Το μήνυμα εστάλη.');
        } else {
            flash('Συμπληρώστε κείμενο μηνύματος.', 'error');
        }
    }

    // PRG pattern — αποτροπή resubmission σε F5
    header('Location: users.php'); exit;
}

/* ── READ — λίστα χρηστών με φίλτρα ── */
$search     = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';

/*
 * Subqueries για στατιστικά ανά χρήστη (book_count, msg_count, last_activity).
 * Αποφεύγουμε επιπλέον queries στο loop — υπολογίζονται εδώ για όλους ταυτόχρονα.
 * FIELD() στο ORDER BY: εξασφαλίζει σταθερή σειρά admin → employee → user
 * ανεξάρτητα από αλφαβητική ταξινόμηση.
 */
$q = "SELECT u.*,
      (SELECT COUNT(*) FROM books       WHERE created_by=u.id)                  AS book_count,
      (SELECT COUNT(*) FROM messages    WHERE from_user=u.id OR to_user=u.id)   AS msg_count,
      (SELECT MAX(created_at) FROM audit_log WHERE user_id=u.id)                AS last_activity
      FROM users u WHERE 1=1";
$p = [];
if ($search)     { $q .= " AND u.username LIKE ?"; $p[] = "%$search%"; }
if ($roleFilter) { $q .= " AND u.role=?";          $p[] = $roleFilter; }
$q .= " ORDER BY FIELD(u.role,'admin','employee','user'), u.username";
$stmt = $db->prepare($q);
$stmt->execute($p);
$users = $stmt->fetchAll();

// Summary stats για τα stat cards — ξεχωριστά queries αντί subqueries για ευκολία ανάγνωσης
$totalUsers    = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers   = $db->query("SELECT COUNT(*) FROM users WHERE active=1")->fetchColumn();
$adminCount    = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$employeeCount = $db->query("SELECT COUNT(*) FROM users WHERE role='employee'")->fetchColumn();

$pageTitle  = 'Διαχείριση Χρηστών';
$activePage = 'users';
include 'layout_admin.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <h1>Διαχείριση Χρηστών</h1>
        <p>Διαχειριστείτε την πρόσβαση, τους ρόλους και τα δικαιώματα</p>
    </div>
    <button type="button" class="btn btn-gold" onclick="openModal('newUserModal')">
        <i class="bi bi-person-plus me-1"></i> Νέος Χρήστης
    </button>
</div>

<!-- STATS — data-driven loop αντί για επαναλαμβανόμενο HTML -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Σύνολο Χρηστών', $totalUsers,    'bi-people',      '#3b82f6'],
        ['Ενεργοί',         $activeUsers,   'bi-person-check','#22c55e'],
        ['Admins',          $adminCount,    'bi-shield-fill', '#e8c547'],
        ['Υπάλληλοι',       $employeeCount, 'bi-person-badge','#8b5cf6'],
    ] as [$label, $val, $icon, $color]): ?>
    <div class="col-6 col-lg-3">
        <div class="stat-card d-flex align-items-center gap-3">
            <div style="font-size:24px;color:<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div>
                <div class="stat-value" style="font-size:26px"><?= $val ?></div>
                <div class="stat-label"><?= $label ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- FILTER -->
<div class="card-panel mb-3">
    <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
        <div class="search-bar" style="flex:1;min-width:180px">
            <i class="bi bi-search search-icon"></i>
            <input type="text" name="search" class="form-control" placeholder="Αναζήτηση username..." value="<?= h($search) ?>">
        </div>
        <select name="role" class="form-select" style="width:160px">
            <option value="">Όλοι οι ρόλοι</option>
            <option value="admin"    <?= $roleFilter==='admin'   ?'selected':'' ?>>Admin</option>
            <option value="employee" <?= $roleFilter==='employee'?'selected':'' ?>>Employee</option>
        </select>
        <button type="submit" class="btn btn-gold">Φίλτρο</button>
        <?php if ($search||$roleFilter): ?>
        <a href="users.php" class="btn btn-outline-secondary btn-sm">× Καθαρισμός</a>
        <?php endif; ?>
    </form>
</div>

<!-- TABLE -->
<div class="card-panel p-0" style="overflow:hidden">
    <table class="table table-library mb-0">
        <thead>
            <tr>
                <th>Χρήστης</th>
                <th>Ρόλος</th>
                <th>Κατάσταση</th>
                <th>Τελευταία Δραστηριότητα</th>
                <th>Στατιστικά</th>
                <th>Ενέργειες</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($users)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Δεν βρέθηκαν χρήστες.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u):
            // Avatar χρώμα ανά ρόλο — οπτική ιεραρχία admin > employee > user
            $avatarBg    = $u['role']==='admin' ? '#1a1a2e' : ($u['role']==='employee' ? '#3b82f6' : '#9ca3af');
            $avatarColor = $u['role']==='admin' ? '#e8c547' : '#fff';
            // $isMe: flag για απόκρυψη επικίνδυνων ενεργειών στον ίδιο τον admin
            $isMe        = ($u['id'] == $_SESSION['user_id']);
        ?>
        <tr>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="user-avatar-lg" style="background:<?= $avatarBg ?>;color:<?= $avatarColor ?>">
                        <?= strtoupper(substr($u['username'],0,1)) ?>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:13px">
                            <?= h($u['username']) ?>
                            <?php if ($isMe): ?>
                            <!-- "εσείς" badge — ο admin αναγνωρίζει εύκολα τον εαυτό του -->
                            <span style="font-size:10px;background:#f3f4f6;color:#9ca3af;padding:1px 6px;border-radius:10px;margin-left:4px">εσείς</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:#9ca3af">Εγγραφή: <?= date('d/m/Y', strtotime($u['created_at'])) ?></div>
                    </div>
                </div>
            </td>
            <td>
                <?php if (!$isMe): ?>
                <!--
                    Role select: onchange submit — άμεση αλλαγή ρόλου χωρίς επιπλέον κλικ.
                    Ο ίδιος ο admin βλέπει static badge (δεν μπορεί να αλλάξει τον ρόλο του).
                -->
                <form method="POST">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                    <select name="role" class="form-select form-select-sm" style="width:130px;font-size:12px" onchange="this.form.submit()">
                        <option value="admin"    <?= $u['role']==='admin'   ?'selected':'' ?>>Admin</option>
                        <option value="employee" <?= $u['role']==='employee'?'selected':'' ?>>Employee</option>
                    </select>
                </form>
                <?php else: ?>
                <span class="role-badge rb-<?= $u['role'] ?>"><?= $u['role'] ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$isMe): ?>
                <!--
                    Toggle switch: onchange submit — άμεση ενεργοποίηση/απενεργοποίηση.
                    Κάθε toggle είναι ξεχωριστό form με uid hidden field.
                    Ο ίδιος ο admin βλέπει static "Ενεργός" (πάντα true, αφού είναι συνδεδεμένος).
                -->
                <form method="POST">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" <?= $u['active']?'checked':'' ?> onchange="this.form.submit()">
                        <label class="form-check-label" style="font-size:11px;color:<?= $u['active']?'#22c55e':'#ef4444' ?>">
                            <?= $u['active']?'Ενεργός':'Ανενεργός' ?>
                        </label>
                    </div>
                </form>
                <?php else: ?>
                <span style="font-size:11px;color:#22c55e;font-weight:600">
                    <i class="bi bi-circle-fill me-1" style="font-size:8px"></i>Ενεργός
                </span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#374151">
                <?= $u['last_activity'] ? date('d/m/Y H:i', strtotime($u['last_activity'])) : '—' ?>
            </td>
            <td>
                <!-- Subquery αποτελέσματα από το κυρίως query -->
                <div class="d-flex gap-2" style="font-size:12px">
                    <span><strong><?= $u['book_count'] ?></strong> <span style="color:#9ca3af">βιβλία</span></span>
                    <span><strong><?= $u['msg_count'] ?></strong>  <span style="color:#9ca3af">μηνύματα</span></span>
                </div>
            </td>
            <td>
                <!--
                    Τα action buttons ανοίγουν modals μέσω JS και περνούν uid + username.
                    Το addslashes() προστατεύει από αποστρόφους σε usernames μέσα στο JS string.
                    Το reset_pass εμφανίζεται ΚΑΙ για τον ίδιο τον admin (μπορεί να reset-άρει
                    τον κωδικό άλλου — εκτός από τον εαυτό του, για τον οποίο υπάρχει το profile.php).
                -->
                <div class="d-flex gap-1">
                    <button type="button" class="action-icon-btn key" title="Αλλαγή κωδικού"
                            onclick="openResetPass(<?= $u['id'] ?>, '<?= addslashes(h($u['username'])) ?>')">
                        <i class="bi bi-key"></i>
                    </button>
                    <?php if (!$isMe): ?>
                    <button type="button" class="action-icon-btn msg" title="Αποστολή μηνύματος"
                            onclick="openSendMsg(<?= $u['id'] ?>, '<?= addslashes(h($u['username'])) ?>')">
                        <i class="bi bi-chat-left-text"></i>
                    </button>
                    <button type="button" class="action-icon-btn del" title="Διαγραφή"
                            onclick="openDeleteUser(<?= $u['id'] ?>, '<?= addslashes(h($u['username'])) ?>')">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ══ NEW USER MODAL ══ -->
<div class="modal-overlay" id="newUserModal">
    <div class="modal-box">
        <div class="modal-box-header">
            <h5><i class="bi bi-person-plus me-2" style="color:var(--gold)"></i>Νέος Χρήστης</h5>
            <button type="button" class="modal-close-btn" onclick="closeModal('newUserModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-box-body">
                <div class="mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required placeholder="π.χ. giannis.p" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label">Κωδικός <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <!-- type="text" αντί για "password" — ο admin βλέπει τον κωδικό που δημιουργεί -->
                        <input type="text" name="password" id="newPassInput" class="form-control" required
                               placeholder="Τουλάχιστον 6 χαρακτήρες" autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary" onclick="genNewPass()" title="Τυχαίος κωδικός">
                            <i class="bi bi-shuffle"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label">Ρόλος</label>
                    <select name="role" class="form-select">
                        <!-- Σειρά: user πρώτο ως ασφαλές default — δεν δημιουργούμε admins κατά λάθος -->
                        <option value="employee">Employee — Διαχείριση βιβλίων</option>
                        <option value="admin">Admin — Πλήρης πρόσβαση</option>
                    </select>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('newUserModal')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold"><i class="bi bi-person-check me-1"></i>Δημιουργία</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ RESET PASSWORD MODAL ══ -->
<div class="modal-overlay" id="resetPassModal">
    <div class="modal-box sm">
        <div class="modal-box-header">
            <h5><i class="bi bi-key me-2" style="color:var(--gold)"></i>Αλλαγή Κωδικού</h5>
            <button type="button" class="modal-close-btn" onclick="closeModal('resetPassModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_pass">
            <!-- uid + username γεμίζουν από JS κατά το άνοιγμα του modal -->
            <input type="hidden" name="uid" id="resetUid">
            <div class="modal-box-body">
                <p style="font-size:13px;margin-bottom:14px;color:#374151">
                    Χρήστης: <strong id="resetUname" style="color:#1a1a2e"></strong>
                </p>
                <label class="form-label">Νέος Κωδικός <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="text" name="new_password" id="resetPassInput" class="form-control" required
                           placeholder="Τουλάχιστον 6 χαρακτήρες" autocomplete="new-password">
                    <button type="button" class="btn btn-outline-secondary" onclick="genResetPass()" title="Τυχαίος κωδικός">
                        <i class="bi bi-shuffle"></i>
                    </button>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('resetPassModal')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold btn-sm"><i class="bi bi-check-lg me-1"></i>Αποθήκευση</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ SEND MESSAGE MODAL ══ -->
<div class="modal-overlay" id="sendMsgModal">
    <div class="modal-box">
        <div class="modal-box-header">
            <h5><i class="bi bi-chat-left-text me-2" style="color:var(--gold)"></i>Αποστολή Μηνύματος</h5>
            <button type="button" class="modal-close-btn" onclick="closeModal('sendMsgModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="send_message">
            <input type="hidden" name="uid" id="msgUid">
            <div class="modal-box-body">
                <p style="font-size:13px;margin-bottom:14px;color:#374151">
                    Αποστολή προς: <strong id="msgUname" style="color:#1a1a2e"></strong>
                </p>
                <div class="mb-3">
                    <label class="form-label">Θέμα</label>
                    <input type="text" name="msg_subject" class="form-control" placeholder="Θέμα μηνύματος...">
                </div>
                <div class="mb-2">
                    <label class="form-label">Μήνυμα <span class="text-danger">*</span></label>
                    <textarea name="msg_body" class="form-control" rows="5" required placeholder="Γράψτε το μήνυμά σας..."></textarea>
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('sendMsgModal')">Ακύρωση</button>
                <button type="submit" class="btn btn-gold"><i class="bi bi-send me-1"></i>Αποστολή</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ DELETE CONFIRM MODAL ══ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box sm">
        <div class="modal-box-header">
            <h5><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Διαγραφή Χρήστη</h5>
            <button type="button" class="modal-close-btn" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="uid" id="deleteUid">
            <div class="modal-box-body">
                <p style="font-size:13px;margin-bottom:12px">
                    Θέλετε σίγουρα να διαγράψετε τον χρήστη <strong id="deleteUname"></strong>;
                </p>
                <!-- Προειδοποίηση για τα μηνύματα: ON DELETE CASCADE στο to_user,
                     SET NULL στο from_user — τα μηνύματα παραμένουν αλλά ο αποστολέας γίνεται NULL -->
                <div class="alert alert-warning mb-0" style="font-size:12px;padding:8px 12px">
                    <i class="bi bi-info-circle me-1"></i>Η ενέργεια δεν αναιρείται. Τα μηνύματα του χρήστη θα παραμείνουν.
                </div>
            </div>
            <div class="modal-box-footer">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('deleteModal')">Ακύρωση</button>
                <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Διαγραφή</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════════════════
   Modal system — pure JS, χωρίς Bootstrap dependency
   Ίδιο pattern με messages.php και compose modal
══════════════════════════════════════════════════════════════ */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
// Κλείσιμο με κλικ έξω από το modal box
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
// Κλείσιμο με Escape — κλείνει όλα τα ανοιχτά modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(function(m) { closeModal(m.id); });
    }
});

/* ── Modal openers — γεμίζουν τα hidden fields πριν το άνοιγμα ── */
function openResetPass(id, name) {
    document.getElementById('resetUid').value       = id;
    document.getElementById('resetUname').textContent = name;
    document.getElementById('resetPassInput').value  = ''; // Καθαρό field κάθε φορά
    openModal('resetPassModal');
    // Μικρό delay για focus — αποφεύγει race condition με το CSS transition
    setTimeout(function(){ document.getElementById('resetPassInput').focus(); }, 50);
}
function openSendMsg(id, name) {
    document.getElementById('msgUid').value        = id;
    document.getElementById('msgUname').textContent = name;
    openModal('sendMsgModal');
}
function openDeleteUser(id, name) {
    document.getElementById('deleteUid').value        = id;
    document.getElementById('deleteUname').textContent = name;
    openModal('deleteModal');
}

/* ── Password generator ──────────────────────────────────────────
   Χαρακτήρες: lowercase + uppercase + digits + σύμβολα.
   Εξαιρούνται ομοιόμορφα γράμματα (l, 1, O, 0) για αποφυγή σύγχυσης
   κατά τη χειροκίνητη μεταφορά κωδικού σε χρήστη.
─────────────────────────────────────────────────────────────── */
function randPass() {
    var c = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
    var p = '';
    for (var i = 0; i < 12; i++) p += c[Math.floor(Math.random()*c.length)];
    return p;
}
function genNewPass()   { document.getElementById('newPassInput').value   = randPass(); }
function genResetPass() { document.getElementById('resetPassInput').value = randPass(); }
</script>

<?php include 'layout_admin_end.php'; ?>