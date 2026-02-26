<?php
/**
 * login.php — Είσοδος προσωπικού
 *
 * Χειρίζεται authentication, αρχικοποίηση session και audit logging.
 * Προσβάσιμο μόνο από μη-συνδεδεμένους χρήστες.
 */
require 'config.php';

// Αν ο χρήστης είναι ήδη authenticated, δεν έχει λόγο να δει τη φόρμα
if (isLoggedIn()) {
    header('Location: ' . (isEmployee() ? 'dashboard.php' : 'index.php')); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $db = getDB();

        // Ελέγχουμε και active=1 — απενεργοποιημένοι λογαριασμοί αποκλείονται
        // εδώ κι όχι μετά το password check, για να μην διαρρεύσουμε πληροφορία ύπαρξης user
        $stmt = $db->prepare("SELECT * FROM users WHERE username=? AND active=1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // password_verify() είναι timing-safe — δεν επιτρέπει timing attacks
        if ($user && password_verify($password, $user['password'])) {

            // Αποθηκεύουμε μόνο ό,τι χρειάζεται το session — όχι ολόκληρο το $user row
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            // Audit log — καταγραφή κάθε login με IP για forensics/security review
            $db->prepare("INSERT INTO audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
               ->execute([$user['id'], 'login', 'user', 'Login from ' . ($_SERVER['REMOTE_ADDR'] ?? '')]);

            header('Location: ' . (in_array($user['role'], ['admin','employee']) ? 'dashboard.php' : 'index.php'));
            exit;

        } else {
            // Σκόπιμα αόριστο μήνυμα — δεν αποκαλύπτουμε αν υπάρχει το username
            $error = 'Λάθος όνομα χρήστη ή κωδικός πρόσβασης.';
        }

    } else {
        $error = 'Παρακαλώ συμπληρώστε όλα τα πεδία.';
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Σύνδεση — Σύστημα Διαχείρισης Βιβλιοθήκης — Βυρωνικής Εταιρείας v1.0</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="login.css">
<link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
<style>
/*
 * Legal modals (Privacy, Terms) — ξεχωριστό σύστημα από τα .custom-modal-overlay.
 * Λόγος: θέλουμε πανομοιότυπο στυλ με το index.php χωρίς να εξαρτώμαστε
 * από Bootstrap Modal που θα δημιουργούσε z-index conflicts με το login form.
 * Λειτουργούν με opacity/pointer-events toggle αντί για display:none/block
 * ώστε να δουλεύουν τα CSS transitions.
 */
.legal-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0,0,0,0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s;
    backdrop-filter: blur(4px);
}
.legal-modal-overlay.open {
    opacity: 1;
    pointer-events: all;
}
.legal-modal-box {
    background: #fff;
    border-radius: 14px;
    max-width: 520px;
    width: 90%;
    max-height: 82vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 48px rgba(0,0,0,0.18);
    transform: translateY(16px) scale(0.98);
    transition: transform 0.25s;
}
.legal-modal-overlay.open .legal-modal-box {
    transform: translateY(0) scale(1);
}
.legal-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 22px 28px;
    border-bottom: 1px solid #eee;
    flex-shrink: 0; /* header παραμένει σταθερός όταν το body κάνει scroll */
}
.legal-modal-head-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 24px;
    font-weight: 600;
    color: #212529;
}
.legal-modal-head-close {
    background: none;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: #999;
    line-height: 1;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background 0.15s, color 0.15s;
}
.legal-modal-head-close:hover { background: #f3f3f3; color: #333; }

.legal-modal-body {
    padding: 24px 28px;
    overflow-y: auto; /* μόνο το body scrollάρει, header fixed */
    line-height: 1.7;
}
.legal-modal-body::-webkit-scrollbar { width: 4px; }
.legal-modal-body::-webkit-scrollbar-thumb { background: #ddd; border-radius: 2px; }
.legal-modal-body h4 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 18px;
    color: #c9a84c;
    margin: 18px 0 7px;
}
.legal-modal-body h4:first-child { margin-top: 0; }
.legal-modal-body p, .legal-modal-body li { font-size: 14px; color: #555; margin-bottom: 10px; }
.legal-modal-body ul { padding-left: 18px; }
</style>
</head>
<body>

<!-- LEFT PANEL — διακοσμητικό, branding, quote -->
<div class="left-panel">
    <div class="left-brand">
        <div class="brand-emblem">
            <img src="assets/byron.png" alt="">
            <div class="emblem-text">
                <div class="company">Βυρωνική Εταιρεία</div>
                <div class="tagline">The Messolonghi Byron Society</div>
            </div>
        </div>
    </div>

    <div class="left-headline">
        <div class="eyebrow">Ψηφιακό Σύστημα Διαχείρισης Βιβλιοθήκης</div>
        <h1>
            Ο καλύτερος προφήτης <br>
            του μέλλοντος  <em>είναι </em><br>
            το παρελθόν.
        </h1>
        <p style="color: goldenrod;">Λόρδος Βύρων &nbsp;•&nbsp; Lord Byron</p>
        <p>Μέσω αυτού του συστήματος, δίνεται πρόσβαση στον πλήρη κατάλογο της Βυρωνικής Εταιρείας. 
            Διαχειριστείτε, αναζητήστε και εξερευνήστε χιλιάδες τίτλους.</p>
    </div>

    <div class="left-bottom"></div>
</div>


<!-- RIGHT PANEL — φόρμα σύνδεσης -->
<div class="right-panel">
    <div class="right-top">
        <a href="index.php">
            <i class="fa-solid fa-globe"></i>
            Δημόσιος Κατάλογος Βιβλιοθήκης
        </a>
    </div>

    <div class="form-area">
        <div class="form-heading">
            <div class="step-tag"><div class="step-dot"></div> Περιορισμένη Πρόσβαση</div>
            <h2>Καλωσορίσατε</h2>
            <p>Συνδεθείτε στο σύστημα διαχείρισης της βιβλιοθήκης παρακάτω.</p>
        </div>

        <?php if ($error): ?>
        <div class="error-banner">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <div class="field-group">
                <label class="field-label" for="username">
                    <i class="fa-regular fa-user" style="margin-right:6px;color:var(--gold)"></i>
                    Όνομα Χρήστη
                </label>
                <div class="input-wrap">
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Εισάγετε το όνομα χρήστη σας"
                        value="<?= h($_POST['username'] ?? '') ?>"
                        required
                        autocomplete="username"
                        spellcheck="false"
                    >
                    <i class="fa-regular fa-user input-icon"></i>
                </div>
            </div>

            <div class="field-group">
                <label class="field-label" for="password">
                    <i class="fa-solid fa-key" style="margin-right:6px;color:var(--gold)"></i>
                    Κωδικός Πρόσβασης
                </label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Εισάγετε τον κωδικό πρόσβασής σας"
                        required
                        autocomplete="current-password"
                    >
                    <i class="fa-solid fa-lock input-icon"></i>
                    <button type="button" class="toggle-pass" onclick="togglePassword()" id="toggleBtn" title="Εμφάνιση/Απόκρυψη">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <div class="forgot-row">
                <a href="#" class="forgot-link" onclick="openModal('forgotModal'); return false;">
                    <i class="fa-regular fa-circle-question"></i>
                    Ξεχάσατε τον κωδικό σας;
                </a>
            </div>

            <button type="submit" class="btn-signin" id="submitBtn">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>Σύνδεση</span>
                <i class="fa-solid fa-arrow-right btn-arrow"></i>
            </button>
        </form>

        <div class="divider"><span>ή</span></div>

        <a href="index.php" class="btn-public">
            <i class="fa-solid fa-earth-europe"></i>
            Είσοδος ως επισκέπτης — Δημόσιος Κατάλογος
        </a>

        <div style="margin-top:20px;display:flex;justify-content:center;gap:16px">
            &nbsp; &nbsp;
            <a href="#" onclick="openModal('accessModal'); return false;"
               style="font-size:13px;color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:6px;transition:color 0.2s"
               onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--muted)'">
                <i class="fa-solid fa-user-plus"></i> Πρόσβαση Νέου Χρήστη
            </a>
        </div>
    </div>

    <div class="right-footer">
        <div class="footer-links">
            <a href="#" onclick="openLegalModal('privacy'); return false;">Πολιτική Απορρήτου</a>
            <a href="#" onclick="openLegalModal('terms'); return false;">Όροι Χρήσης</a>
            <a href="#" onclick="openModal('accessibilityModal'); return false;">Προσβασιμότητα</a>
            <a href="#" onclick="openModal('helpModal'); return false;">FAQ</a>
            <a href="https://www.messolonghibyronsociety.gr/contact-us/">Επικοινωνία</a>
        </div>
        <div class="footer-copy">
            &copy; <?= date('Y') ?> Βυρωνική Εταιρεία &nbsp;·&nbsp; Σύστημα Διαχείρισης Βιβλιοθήκης Βυρωνικής Εταιρείας v1.0
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     CUSTOM MODALS
     Χρησιμοποιούν .custom-modal-overlay + class toggle "open".
     ΔΕΝ εξαρτώνται από Bootstrap Modal για να αποφύγουμε
     conflicts με το login form και τα legal modals.
════════════════════════════════════════════════════════════ -->

<!-- FORGOT PASSWORD — αποστέλλει AJAX στο forgot_message.php -->
<div class="custom-modal-overlay" id="forgotModal">
    <div class="custom-modal">
        <button class="modal-close" onclick="closeModal('forgotModal')"><i class="fa-solid fa-xmark"></i></button>
       
        <div class="modal-title">Ανάκτηση Κωδικού</div>
        <div class="modal-sub">Επικοινωνήστε με τον διαχειριστή του συστήματος για επαναφορά κωδικού πρόσβασης. Εναλλακτικά, συμπληρώστε το username σας παρακάτω και ο admin θα λάβει σχετικό αίτημα.</div>
        <input type="text"  class="modal-input" placeholder="Username ή ονοματεπώνυμο..." id="forgotUser">
        <input type="email" class="modal-input" placeholder="Email επικοινωνίας (προαιρετικό)..." id="forgotEmail">
        <button class="btn-modal-submit" onclick="submitForgot()">
            <i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος
        </button>
        <div class="modal-note">
            Για άμεση βοήθεια επικοινωνήστε στο
            <a href="mailto:byronlib@gmail.com">byronlib@gmail.com</a>
        </div>
    </div>
</div>

<!-- FAQ / HELP -->
<div class="custom-modal-overlay" id="helpModal">
    <div class="custom-modal">
        <button class="modal-close" onclick="closeModal('helpModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title">Βοήθεια &amp; FAQ</div>
        <div class="modal-sub">Συχνές ερωτήσεις για τη σύνδεση και τη χρήση του συστήματος.</div>
        <div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">
                    Δεν θυμάμαι τον κωδικό μου. Τι κάνω;
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="help-a">Χρησιμοποιήστε την επιλογή "Ξεχάσατε τον κωδικό;" κάτω από το πεδίο κωδικού, ή επικοινωνήστε με τον διαχειριστή στο byronlib@gmail.com.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">
                    Ποιοι μπορούν να συνδεθούν στο σύστημα;
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="help-a">Μόνο εξουσιοδοτημένοι υπάλληλοι και διαχειριστές. Οι επισκέπτες μπορούν να χρησιμοποιήσουν τον δημόσιο κατάλογο χωρίς σύνδεση.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">
                    Πώς μπορώ να αποκτήσω πρόσβαση ως νέος υπάλληλος;
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="help-a">Ο λογαριασμός σας δημιουργείται από τον διαχειριστή. Επικοινωνήστε με το IT τμήμα ή τον υπεύθυνο βιβλιοθήκης.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">
                    Το username/password είναι case-sensitive;
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="help-a">Ναι, το πεδίο κωδικού είναι πλήρως case-sensitive. Βεβαιωθείτε ότι το CapsLock είναι απενεργοποιημένο.</div>
            </div>
            <div class="help-item">
                <div class="help-q" onclick="toggleHelp(this)">
                    Πώς μπορώ να δω τον κατάλογο χωρίς σύνδεση;
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div class="help-a">Κάντε κλικ στο "Είσοδος ως επισκέπτης" ή επισκεφθείτε απευθείας τη σελίδα index.php για τον δημόσιο κατάλογο.</div>
            </div>
        </div>
    </div>
</div>

<!-- REQUEST ACCESS — η υποβολή εμφανίζει showNotAvailablePopup(), δεν αποστέλλει δεδομένα -->
<div class="custom-modal-overlay" id="accessModal">
    <div class="custom-modal">
        <button class="modal-close" onclick="closeModal('accessModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title">Αίτημα Πρόσβασης</div>
        <div class="modal-sub">Εάν είστε νέος υπάλληλος και χρειάζεστε πρόσβαση στο σύστημα, συμπληρώστε τα παρακάτω στοιχεία.</div>
        <input type="text"  class="modal-input" placeholder="Ονοματεπώνυμο...">
        <input type="text"  class="modal-input" placeholder="Τμήμα / Ρόλος...">
        <input type="email" class="modal-input" placeholder="Email εταιρείας...">
        <button class="btn-modal-submit" onclick="submitAccess()">
            <i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Υποβολή Αιτήματος
        </button>
        <div class="modal-note">Θα λάβετε απάντηση εντός 1-2 εργάσιμων ημερών.</div>
    </div>
</div>

<!-- ACCESSIBILITY -->
<div class="custom-modal-overlay" id="accessibilityModal">
    <div class="custom-modal">
        <button class="modal-close" onclick="closeModal('accessibilityModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title">Προσβασιμότητα</div>
        <div class="modal-sub">Το σύστημα έχει σχεδιαστεί για να είναι προσβάσιμο σε όλους τους χρήστες.</div>
        <div class="legal-content" style="max-height:200px">
            <h4>Πλοήγηση με Πληκτρολόγιο</h4>
            <p>Χρησιμοποιήστε Tab για μετακίνηση μεταξύ πεδίων και Enter για υποβολή φόρμας. Όλες οι λειτουργίες είναι προσβάσιμες χωρίς ποντίκι.</p>
            <h4>Αντίθεση &amp; Εμφάνιση</h4>
            <p>Το σύστημα χρησιμοποιεί υψηλή αντίθεση χρωμάτων. Μπορείτε να αυξήσετε το μέγεθος γραμματοσειράς μέσω των ρυθμίσεων του browser σας (Ctrl/Cmd +).</p>
            <h4>Αναφορά Προβλήματος</h4>
            <p>Εάν αντιμετωπίζετε πρόβλημα προσβασιμότητας, επικοινωνήστε στο byronlib@gmail.com</p>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     LEGAL MODALS — ξεχωριστό σύστημα (openLegalModal/closeLegalModal)
     Ίδιο στυλ/συμπεριφορά με τα legal modals του index.php.
════════════════════════════════════════════════════════════ -->

<!-- PRIVACY POLICY -->
<div class="legal-modal-overlay" id="legal-modal-privacy">
    <div class="legal-modal-box">
        <div class="legal-modal-head">
            <span class="legal-modal-head-title">Πολιτική Απορρήτου</span>
            <button class="legal-modal-head-close" onclick="closeLegalModal('privacy')">&times;</button>
        </div>
        <div class="legal-modal-body">
            <h4>1. Συλλογή Δεδομένων</h4>
            <p>Το σύστημα καταγράφει τα στοιχεία σύνδεσης (username, ημερομηνία/ώρα, διεύθυνση IP) για λόγους ασφαλείας και ελέγχου. Δεν συλλέγουμε προσωπικά δεδομένα πέραν αυτών που είναι απαραίτητα για τη λειτουργία του συστήματος.</p>
            <h4>2. Χρήση Δεδομένων</h4>
            <p>Τα δεδομένα χρησιμοποιούνται αποκλειστικά για τη διαχείριση της βιβλιοθήκης και δεν κοινοποιούνται σε τρίτους. Η πρόσβαση περιορίζεται στους εξουσιοδοτημένους διαχειριστές.</p>
            <h4>3. Ασφάλεια</h4>
            <p>Οι κωδικοί πρόσβασης αποθηκεύονται με κρυπτογράφηση bcrypt. Η επικοινωνία με τον server γίνεται μέσω HTTPS. Διατηρούμε αρχείο καταγραφής (audit log) για όλες τις ενέργειες.</p>
            <h4>4. Δικαιώματα</h4>
            <p>Μπορείτε να ζητήσετε διόρθωση ή διαγραφή των δεδομένων σας επικοινωνώντας με τον διαχειριστή. Για ερωτήματα GDPR: <a href="mailto:byronlib@gmail.com" style="color:var(--gold)">byronlib@gmail.com</a></p>
            <h4>5. Ευρωπαϊκή Νομοθεσία</h4>
            <p>Η επεξεργασία δεδομένων διέπεται από τον GDPR (Κανονισμός ΕΕ 2016/679) και την ελληνική νομοθεσία.</p>
        </div>
    </div>
</div>

<!-- TERMS OF USE -->
<div class="legal-modal-overlay" id="legal-modal-terms">
    <div class="legal-modal-box">
        <div class="legal-modal-head">
            <span class="legal-modal-head-title">Όροι Χρήσης</span>
            <button class="legal-modal-head-close" onclick="closeLegalModal('terms')">&times;</button>
        </div>
        <div class="legal-modal-body">
            <h4>1. Αποδοχή Όρων</h4>
            <p>Η χρήση του συστήματος προϋποθέτει αποδοχή των παρόντων όρων. Το σύστημα προορίζεται αποκλειστικά για εξουσιοδοτημένους χρήστες της Βυρωνικής Εταιρείας.</p>
            <h4>2. Χρήση Λογαριασμού</h4>
            <p>Ο λογαριασμός σας είναι προσωπικός και μη μεταβιβάσιμος. Απαγορεύεται η κοινοποίηση των κωδικών πρόσβασης. Είστε υπεύθυνοι για κάθε ενέργεια που πραγματοποιείται μέσω του λογαριασμού σας.</p>
            <h4>3. Απαγορευμένες Ενέργειες</h4>
            <ul>
                <li>Μη εξουσιοδοτημένη πρόσβαση στο σύστημα</li>
                <li>Τροποποίηση δεδομένων χωρίς δικαίωμα</li>
                <li>Εξαγωγή δεδομένων για εμπορικούς σκοπούς</li>
                <li>Κάθε ενέργεια που υπονομεύει την ασφάλεια του συστήματος</li>
            </ul>
            <h4>4. Ευθύνη</h4>
            <p>Η Βυρωνική Εταιρεία δεν ευθύνεται για τυχαία απώλεια δεδομένων ή διακοπή λειτουργίας. Συνιστάται η τακτική αποθήκευση σημαντικών πληροφοριών.</p>

        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>

// ─── Εναλλαγή ορατότητας κωδικού ────────────────────────────────────────────
function togglePassword() {
    const inp = document.getElementById('password');
    const ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.className = 'fa-regular fa-eye-slash';
    } else {
        inp.type = 'password';
        ico.className = 'fa-regular fa-eye';
    }
}


// ─── Custom Modals (forgotModal, helpModal, accessModal, accessibilityModal) ─
// Class toggle αντί display:none/block — απαραίτητο για να δουλεύουν τα CSS transitions
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden'; // αποτρέπει scroll του background
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

// Κλείσιμο με κλικ στο σκοτεινό overlay, εκτός του modal box
document.querySelectorAll('.custom-modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});


// ─── Legal Modals (privacy, terms) ──────────────────────────────────────────
// Ίδιο pattern με index.php — ξεχωριστές συναρτήσεις για να μην συγχέονται
// τα δύο modal συστήματα στο Escape handler παρακάτω
function openLegalModal(id) {
    document.getElementById('legal-modal-' + id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLegalModal(id) {
    document.getElementById('legal-modal-' + id).classList.remove('open');
    document.body.style.overflow = '';
}

document.querySelectorAll('.legal-modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});

// Escape κλείνει και τα δύο είδη modal ταυτόχρονα
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.custom-modal-overlay.open').forEach(m => closeModal(m.id));
        document.querySelectorAll('.legal-modal-overlay.open').forEach(m => {
            m.classList.remove('open');
            document.body.style.overflow = '';
        });
    }
});


// ─── FAQ Accordion ───────────────────────────────────────────────────────────
// Κλείνουμε πρώτα όλα, μετά ανοίγουμε μόνο το επιλεγμένο (αν ήταν κλειστό)
// — αποτρέπουμε πολλαπλά ανοιχτά ταυτόχρονα
function toggleHelp(el) {
    const answer = el.nextElementSibling;
    const isOpen = answer.classList.contains('open');
    document.querySelectorAll('.help-a.open').forEach(a => a.classList.remove('open'));
    document.querySelectorAll('.help-q.active').forEach(q => q.classList.remove('active'));
    if (!isOpen) {
        answer.classList.add('open');
        el.classList.add('active');
    }
}


// ─── Forgot Password ─────────────────────────────────────────────────────────
// AJAX POST στο forgot_message.php — εισάγει μήνυμα στο inbox του admin
// μέσω του messaging συστήματος χωρίς να απαιτείται session (ο χρήστης δεν είναι logged in)
function submitForgot() {
    const user  = document.getElementById('forgotUser').value.trim();
    const email = document.getElementById('forgotEmail').value.trim();

    if (!user) { document.getElementById('forgotUser').focus(); return; }

    const btn = event.target;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="margin-right:8px"></i>Αποστολή...';
    btn.disabled  = true;

    fetch('forgot_message.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'username=' + encodeURIComponent(user) + '&email=' + encodeURIComponent(email)
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            btn.innerHTML    = '<i class="fa-solid fa-check" style="margin-right:8px"></i>Αίτημα Εστάλη!';
            btn.style.background = 'linear-gradient(135deg,#22c55e,#16a34a)';
            setTimeout(() => closeModal('forgotModal'), 2500);
            // Επαναφορά κουμπιού + καθαρισμός πεδίων αφού κλείσει το modal
            setTimeout(() => {
                btn.innerHTML    = '<i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος';
                btn.style.background = '';
                btn.disabled     = false;
                document.getElementById('forgotUser').value  = '';
                document.getElementById('forgotEmail').value = '';
            }, 3000);
        } else {
            btn.innerHTML    = '<i class="fa-solid fa-circle-exclamation" style="margin-right:8px"></i>Σφάλμα — Δοκιμάστε ξανά';
            btn.style.background = 'linear-gradient(135deg,#ef4444,#dc2626)';
            btn.disabled     = false;
            setTimeout(() => {
                btn.innerHTML    = '<i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος';
                btn.style.background = '';
            }, 3000);
        }
    })
    .catch(() => {
        // Network error ή το forgot_message.php δεν είναι προσβάσιμο
        btn.innerHTML    = '<i class="fa-solid fa-circle-exclamation" style="margin-right:8px"></i>Σφάλμα σύνδεσης';
        btn.style.background = 'linear-gradient(135deg,#ef4444,#dc2626)';
        btn.disabled     = false;
        setTimeout(() => {
            btn.innerHTML    = '<i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Αποστολή Αιτήματος';
            btn.style.background = '';
        }, 3000);
    });
}


// ─── Access Request ──────────────────────────────────────────────────────────
// Η λειτουργία δεν είναι υλοποιημένη ακόμα.
// Δείχνουμε fake success για 1.5s (καλύτερο UX από άμεσο error),
// κλείνουμε το modal και εμφανίζουμε informational popup.
function submitAccess() {
    const btn = event.target;
    btn.innerHTML    = '<i class="fa-solid fa-check" style="margin-right:8px"></i>Υποβλήθηκε!';
    btn.style.background = 'linear-gradient(135deg,#22c55e,#16a34a)';
    btn.disabled     = true;

    setTimeout(() => {
        closeModal('accessModal');
        setTimeout(() => {
            btn.innerHTML    = '<i class="fa-solid fa-paper-plane" style="margin-right:8px"></i>Υποβολή Αιτήματος';
            btn.style.background = '';
            btn.disabled     = false;
        }, 400);
        showNotAvailablePopup();
    }, 1500);
}


// ─── "Υπό ανάπτυξη" Popup ───────────────────────────────────────────────────
// Δημιουργείται δυναμικά στο DOM (δεν υπάρχει στο HTML) ώστε να αποφύγουμε
// z-index conflicts με τα υπάρχοντα modal overlays.
// Αυτοκαταστρέφεται μετά από 8s — η progress bar δίνει οπτική ένδειξη του countdown.
function showNotAvailablePopup() {
    const existing = document.getElementById('notAvailablePopup');
    if (existing) existing.remove();

    const popup = document.createElement('div');
    popup.id = 'notAvailablePopup';
    popup.innerHTML = `
        <div id="notAvailableInner" style="
            position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -48%) scale(0.96);
            background: #ffffff;
            border: 1px solid rgba(201,168,76,0.3);
            border-top: 4px solid #c9a84c;
            border-radius: 14px;
            padding: 36px 40px;
            display: flex; flex-direction: column; align-items: center; text-align: center;
            gap: 0;
            box-shadow: 0 24px 64px rgba(0,0,0,0.14), 0 4px 16px rgba(0,0,0,0.07);
            max-width: 460px; width: 90%;
            z-index: 99999;
            opacity: 0;
            transition: opacity 0.35s ease, transform 0.35s ease;
            font-family: 'Jost', sans-serif;
        ">
            <button onclick="
                const inner = document.getElementById('notAvailableInner');
                inner.style.opacity = '0';
                inner.style.transform = 'translate(-50%, -48%) scale(0.96)';
                setTimeout(() => { const p = document.getElementById('notAvailablePopup'); if(p) p.remove(); }, 350);
            " style="
                position: absolute; top: 14px; right: 16px;
                background: none; border: none; color: #adb5bd; cursor: pointer;
                font-size: 18px; line-height: 1; padding: 4px;
                display: flex; align-items: center; border-radius: 50%;
                transition: color 0.15s, background 0.15s;
            " onmouseover="this.style.color='#495057';this.style.background='#f1f3f5'"
               onmouseout="this.style.color='#adb5bd';this.style.background='none'">✕</button>


            <div style="
                font-family: 'Cormorant Garamond', serif; font-size: 24px; font-weight: 600;
                color: #212529; margin-bottom: 12px; letter-spacing: 0.3px;
            ">Λειτουργία υπό ανάπτυξη</div>

            <div style="
                width: 40px; height: 2px;
                background: linear-gradient(90deg, #c9a84c, #e8c547);
                border-radius: 2px; margin-bottom: 18px;
            "></div>

            <div style="font-size: 15px; color: #6c757d; line-height: 1.75; max-width: 340px;">
                Η αίτηση πρόσβασης νέου χρήστη δεν έχει ενεργοποιηθεί ακόμα στο σύστημα.
                <br><br>
                Η λειτουργία αυτή θα
                <strong style="color:#212529;font-weight:500;">προστεθεί σε μελλοντική έκδοση</strong>
                του συστήματος διαχείρισης.
            </div>

            <!-- Progress bar — countdown 8s, αδειάζει με CSS transition -->
            <div style="
                width: 100%; height: 3px; background: rgba(0,0,0,0.06);
                border-radius: 2px; margin-top: 28px; overflow: hidden;
            ">
                <div id="notAvailableProgress" style="
                    height: 100%; width: 100%;
                    background: linear-gradient(90deg, #c9a84c, #e8c547);
                    border-radius: 2px; transition: width 8s linear;
                "></div>
            </div>
        </div>

        <!-- Backdrop — κλείνει το popup με κλικ εκτός, z-index κάτω από το inner -->
        <div id="notAvailableBackdrop" style="
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.25); backdrop-filter: blur(3px);
            z-index: 99998; opacity: 0; transition: opacity 0.35s ease;
        " onclick="
            const inner = document.getElementById('notAvailableInner');
            inner.style.opacity = '0';
            inner.style.transform = 'translate(-50%, -48%) scale(0.96)';
            document.getElementById('notAvailableBackdrop').style.opacity = '0';
            setTimeout(() => { const p = document.getElementById('notAvailablePopup'); if(p) p.remove(); }, 350);
        "></div>
    `;
    document.body.appendChild(popup);

    // Double rAF — εξασφαλίζει ότι το element έχει γίνει paint
    // πριν αλλάξουμε styles, ώστε να ενεργοποιηθεί το CSS transition
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            const inner    = document.getElementById('notAvailableInner');
            const backdrop = document.getElementById('notAvailableBackdrop');
            inner.style.opacity   = '1';
            inner.style.transform = 'translate(-50%, -50%) scale(1)';
            backdrop.style.opacity = '1';

            // Μικρή καθυστέρηση ώστε να φανεί πρώτα η γεμάτη μπάρα (100%)
            // πριν ξεκινήσει το drain
            setTimeout(() => {
                const bar = document.getElementById('notAvailableProgress');
                if (bar) bar.style.width = '0%';
            }, 100);
        });
    });

    // Αυτόματο κλείσιμο μετά από 8s
    setTimeout(() => {
        const inner    = document.getElementById('notAvailableInner');
        const backdrop = document.getElementById('notAvailableBackdrop');
        if (inner)    { inner.style.opacity = '0'; inner.style.transform = 'translate(-50%, -48%) scale(0.96)'; }
        if (backdrop)   backdrop.style.opacity = '0';
        setTimeout(() => { const p = document.getElementById('notAvailablePopup'); if (p) p.remove(); }, 350);
    }, 8000);
}


// ─── Login form submit ───────────────────────────────────────────────────────
// Disabled button αποτρέπει double-submit σε αργές συνδέσεις
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn    = document.getElementById('submitBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Σύνδεση...</span>';
    btn.disabled  = true;
});

</script>


<!-- Create by Panagiotis Kotsorgios | Agrotech Innovations (2026) -->
</body>
</html>