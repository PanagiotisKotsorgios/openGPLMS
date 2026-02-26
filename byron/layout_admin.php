<?php
/**
 * layout_admin.php — Κοινό layout για όλες τις σελίδες του admin panel.
 *
 * Συμπεριλαμβάνεται στην ΑΡΧΗ κάθε admin σελίδας (πριν το περιεχόμενο).
 * Η αντίστοιχη layout_admin_end.php κλείνει το <div id="main"> και
 * φορτώνει τυχόν page-specific JS μέσω της μεταβλητής $extraJs.
 *
 * Απαιτήσεις πριν το include:
 *   $pageTitle  — τίτλος καρτέλας (string)
 *   $activePage — key για highlight του sidebar link (string)
 */

// Fallbacks σε περίπτωση που η σελίδα ξεχάσει να ορίσει τις μεταβλητές
if (!isset($pageTitle))  $pageTitle  = 'Βυρωνική Εταιρεία';
if (!isset($activePage)) $activePage = '';
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> — Σύστημα Διαχείρισης Βιβλιοθήκης — Βυρωνικής Εταιρείας v1.0</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
<!-- Page-specific CSS — όλα φορτώνονται πάντα, ανεξαρτήτως σελίδας.
     Το overhead είναι μηδαμινό (cached) και αποφεύγεται η ανάγκη να
     γνωρίζει το layout ποια σελίδα το φορτώνει. -->
<link rel="stylesheet" href="assets/styles/add_book.css">
<link rel="stylesheet" href="assets/styles/layout_admin.css">
<link rel="stylesheet" href="assets/styles/catalog.css">
<link rel="stylesheet" href="assets/styles/categories.css">
<link rel="stylesheet" href="assets/styles/csv_editor.css">
<link rel="stylesheet" href="assets/styles/messages.css">
<link rel="stylesheet" href="assets/styles/users.css">
<!--
    Bootstrap JS φορτώνεται στο <head> (όχι πριν το </body>) γιατί ορισμένες
    σελίδες χρησιμοποιούν inline onclick handlers που καλούν Bootstrap methods
    (π.χ. modal, alert dismiss) πριν φτάσει το DOM στο τέλος της σελίδας.
-->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>

<!-- SIDEBAR -->
<div id="sidebar">
    <div class="brand">
        <div class="brand-title">Βυρωνική<br>Εταιρεία</div>
        <div class="brand-sub">The Messolonghi Byron Society</div>
    </div>

    <nav>
        <!-- Τα links εμφανίζονται μόνο σε employees και admins —
             απλοί users δεν έχουν πρόσβαση στο admin panel καθόλου,
             αλλά ο έλεγχος εδώ προσθέτει ένα επιπλέον επίπεδο άμυνας -->
        <?php if (isEmployee()): ?>
        <a href="dashboard.php" class="<?= $activePage==='dashboard'?'active':'' ?>">
            <i class="bi bi-grid-1x2"></i> Πίνακας Ελέγχου
        </a>
        <a href="catalog.php" class="<?= $activePage==='catalog'?'active':'' ?>">
            <i class="bi bi-journal-bookmark"></i> Κατάλογος Βιβλιοθήκης
        </a>
        <a href="add_book.php" class="<?= $activePage==='add'?'active':'' ?>">
            <i class="bi bi-plus-circle"></i> Προσθήκη Αντικειμένου
        </a>
        <a href="reports.php" class="<?= $activePage==='reports'?'active':'' ?>">
            <i class="bi bi-bar-chart"></i> Αναφορές &amp; Στατιστικά
        </a>

        <div class="sidebar-section">Εισαγωγή Δεδομένων</div>
        <a href="csv_import.php" class="<?= $activePage==='csv_import'?'active':'' ?>">
            <i class="bi bi-upload"></i> Εισαγωγή CSV
        </a>
        <!--
            Το csv_editor link έχει διπλό condition: αν το activePage είναι
            'csv_import' δεν βάζει κλάση (κενό string), αν είναι 'csv_editor'
            βάζει 'active'. Αυτό εξασφαλίζει ότι μόνο ένα link είναι active
            τη φορά, παρόλο που τα δύο είναι στην ίδια κατηγορία.
        -->
        <a href="csv_editor.php" class="<?= $activePage==='csv_import'?'':''; ?><?= $activePage==='csv_editor'?'active':'' ?>">
            <i class="bi bi-table"></i> Online CSV Editor
        </a>

        <?php if (isAdmin()): ?>
        <!-- Η ενότητα Διαχείριση εμφανίζεται μόνο σε admins — employees δεν έχουν πρόσβαση -->
        <div class="sidebar-section">Διαχείριση</div>
        <a href="users.php" class="<?= $activePage==='users'?'active':'' ?>">
            <i class="bi bi-people"></i> Διαχείριση Χρηστών
        </a>
        <a href="categories.php" class="<?= $activePage==='categories'?'active':'' ?>">
            <i class="bi bi-tags"></i> Κατηγορίες &amp; Εκδότες
        </a>
        <a href="audit.php" class="<?= $activePage==='audit'?'active':'' ?>">
            <i class="bi bi-shield-check"></i> Αντίγραφο Ασφαλείας
        </a>
        <?php endif; ?>

        <div class="sidebar-section">Άλλα</div>

        <!-- Μηνύματα: εκτελεί query για αδιάβαστα μηνύματα σε κάθε page load
             ώστε το badge να είναι πάντα ενημερωμένο. Το query είναι ελαφρύ
             (indexed στο to_user + is_read) οπότε δεν επιβαρύνει αισθητά. -->
        <a href="messages.php" class="<?= $activePage==='messages'?'active':'' ?>" style="position:relative">
            <i class="bi bi-chat"></i> Μηνύματα &amp; Chat
            <?php
            $db = getDB();
            $unreadNav = $db->prepare("SELECT COUNT(*) FROM messages WHERE to_user=? AND is_read=0");
            $unreadNav->execute([$_SESSION['user_id']]);
            $cntNav = (int)$unreadNav->fetchColumn();
            if ($cntNav > 0): ?>
            <span class="ms-auto badge" style="background:var(--gold);color:#1a1a2e;font-size:9px;font-weight:800;min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;border-radius:9px"><?= $cntNav ?></span>
            <?php endif; ?>
        </a>

        <a href="help.php" class="<?= $activePage==='help'?'active':'' ?>">
            <i class="bi bi-question-circle"></i> Βοήθεια &amp; FAQ
        </a>
        <a href="profile.php" class="<?= $activePage==='profile'?'active':'' ?>">
            <i class="bi bi-person-gear"></i> Ρυθμίσεις Προφίλ
        </a>
        <?php endif; ?>

        <!-- Ο δημόσιος κατάλογος είναι προσβάσιμος σε όλους — εκτός isEmployee() block -->
        <a href="index.php">
            <i class="bi bi-globe"></i> Δημόσια Αρχεία
        </a>
    </nav>

    <!-- User panel — το avatar είναι το πρώτο γράμμα του username (uppercase) -->
    <div class="user-panel">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?></div>
        <div>
            <div class="user-name"><?= h($_SESSION['username'] ?? '') ?></div>
            <div class="user-role"><?= h($_SESSION['role'] ?? '') ?></div>
        </div>
    </div>

    <a href="logout.php" class="logout-link"><i class="bi bi-box-arrow-left"></i> Αποσύνδεση</a>
</div>

<!-- MAIN CONTENT — ανοίγει εδώ, κλείνει στο layout_admin_end.php -->
<div id="main">
<?php
// Flash message — εμφανίζεται μία φορά και διαγράφεται από το session.
// Το type μπορεί να είναι 'error' (→ Bootstrap danger) ή οτιδήποτε άλλο (→ success).
$flash = getFlash();
if ($flash): ?>
<div class="flash-alert alert alert-<?= $flash['type']==='error'?'danger':'success' ?> alert-dismissible fade show">
    <?= h($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>