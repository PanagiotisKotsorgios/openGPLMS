<?php
require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι έχουν πρόσβαση στο προφίλ τους
$db = getDB();

/* ── Αλλαγή κωδικού ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $curpass = $_POST['current_password'] ?? '';
    $newpass = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    /*
     * Φορτώνουμε τον χρήστη από τη βάση για να επαληθεύσουμε τον τρέχοντα κωδικό.
     * Δεν εμπιστευόμαστε session data για τον έλεγχο — ο hash πρέπει να έρθει fresh.
     * Χρησιμοποιούμε την ίδια μεταβλητή $user για query και αποτέλεσμα (overwrite).
     */
    $user = $db->prepare("SELECT * FROM " . TABLE_PREFIX . "users WHERE id=?");
    $user->execute([$_SESSION['user_id']]);
    $user = $user->fetch();

    if (!password_verify($curpass, $user['password'])) {
        // Σκόπιμα γενικό μήνυμα — δεν αποκαλύπτουμε αν ο λογαριασμός υπάρχει
        flash('Ο τρέχων κωδικός δεν είναι σωστός.', 'error');
    } elseif (strlen($newpass) < 6) {
        flash('Ο νέος κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.', 'error');
    } elseif ($newpass !== $confirm) {
        flash('Οι κωδικοί δεν ταιριάζουν.', 'error');
    } else {
        // password_hash() με PASSWORD_DEFAULT — αυτόματα bcrypt, future-proof αν αλλάξει ο αλγόριθμος
        $db->prepare("UPDATE " . TABLE_PREFIX . "users SET password=? WHERE id=?")
           ->execute([password_hash($newpass, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        // Audit log — καταγράφουμε την αλλαγή χωρίς να αποθηκεύουμε τον κωδικό
        $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type) VALUES (?,?,?)")
           ->execute([$_SESSION['user_id'], 'change_password', 'user']);
        flash('Ο κωδικός ενημερώθηκε επιτυχώς.');
    }
    // PRG pattern — αποτροπή resubmission σε F5
    header('Location: profile.php'); exit;
}

// Στοιχεία συνδεδεμένου χρήστη για εμφάνιση στο profile card
$myUser = $db->prepare("SELECT * FROM " . TABLE_PREFIX . "users WHERE id=?");
$myUser->execute([$_SESSION['user_id']]);
$myUser = $myUser->fetch();

// Τελευταίες 10 ενέργειες του χρήστη — περιορισμός σε LIMIT 10 για απόδοση
$myActions = $db->prepare("SELECT * FROM " . TABLE_PREFIX . "audit_log WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$myActions->execute([$_SESSION['user_id']]);
$myActions = $myActions->fetchAll();

$pageTitle  = 'Ρυθμίσεις Προφίλ';
$activePage = 'profile';
include 'layout_admin.php';
?>

<div class="page-header">
    <h1>Ρυθμίσεις Προφίλ</h1>
    <p>Διαχειριστείτε τα στοιχεία του λογαριασμού σας</p>
</div>

<div class="row g-3">
    <div class="col-lg-5">

        <!-- Profile card -->
        <div class="card-panel mb-3">
            <div class="text-center mb-4">
                <!-- Avatar: πρώτο γράμμα username (uppercase) — ίδιο pattern με layout_admin και messages -->
                <div style="width:72px;height:72px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:#1a1a2e;margin:0 auto 12px">
                    <?= strtoupper(substr($myUser['username'],0,1)) ?>
                </div>
                <div style="font-family:'Playfair Display',serif;font-size:20px;font-weight:700"><?= h($myUser['username']) ?></div>
                <div style="font-size:12px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px"><?= h($myUser['role']) ?></div>
            </div>
            <div style="background:#f9fafb;border-radius:8px;padding:16px">
                <div class="row g-2">
                    <div class="col-6">
                        <div style="font-size:11px;color:#9ca3af;text-transform:uppercase">Εγγραφή</div>
                        <div style="font-size:13px;font-weight:500"><?= date('d/m/Y', strtotime($myUser['created_at'])) ?></div>
                    </div>
                    <div class="col-6">
                        <div style="font-size:11px;color:#9ca3af;text-transform:uppercase">Κατάσταση</div>
                        <!-- Ο χρήστης βλέπει πάντα "Ενεργός" αφού μόνο active=1 μπορεί να συνδεθεί -->
                        <div style="font-size:13px;font-weight:500;color:#22c55e"><?= $myUser['active']?'Ενεργός':'Ανενεργός' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Change password form -->
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Αλλαγή Κωδικού</h6>
            <form method="POST">
<?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Τρέχων Κωδικός</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Νέος Κωδικός</label>
                    <!-- minlength="6" — client-side validation, ίδιο όριο με τον server-side έλεγχο -->
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label">Επιβεβαίωση Νέου Κωδικού</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-gold w-100">Ενημέρωση Κωδικού</button>
            </form>
        </div>
    </div>

    <!-- Recent activity — τελευταίες 10 ενέργειες από το audit_log -->
    <div class="col-lg-7">
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">Πρόσφατη Δραστηριότητα</h6>
            <?php if (empty($myActions)): ?>
            <p class="text-muted text-center py-4" style="font-size:13px">Καμία δραστηριότητα.</p>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px">
            <?php foreach ($myActions as $a):
                /*
                 * Αντιστοίχιση action string → Bootstrap icon.
                 * Fallback σε 'bi-dot' για άγνωστες ενέργειες —
                 * αποφεύγουμε να σπάσει το UI αν προστεθούν νέα actions στο audit_log.
                 */
                $icons = [
                    'login'           => 'bi-box-arrow-in-right',
                    'logout'          => 'bi-box-arrow-right',
                    'create'          => 'bi-plus-circle',
                    'edit'            => 'bi-pencil',
                    'delete'          => 'bi-trash',
                    'change_password' => 'bi-key',
                ];
                $icon = $icons[$a['action']] ?? 'bi-dot';
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f9fafb;border-radius:8px">
                <i class="bi <?= $icon ?>" style="font-size:16px;color:var(--gold)"></i>
                <div style="flex:1">
                    <!-- Μορφή: "action — target_type #target_id" (αν υπάρχουν) -->
                    <div style="font-size:13px;font-weight:500">
                        <?= h($a['action']) ?>
                        <?= $a['target_type'] ? ' — ' . h($a['target_type']) . ($a['target_id'] ? ' #'.$a['target_id'] : '') : '' ?>
                    </div>
                    <?php if ($a['details']): ?>
                    <div style="font-size:11px;color:#9ca3af"><?= h($a['details']) ?></div>
                    <?php endif; ?>
                </div>
                <div style="font-size:11px;color:#9ca3af;white-space:nowrap"><?= date('d/m H:i', strtotime($a['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'layout_admin_end.php'; ?>