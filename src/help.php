<?php
require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι βλέπουν τη σελίδα βοήθειας
$pageTitle  = 'Βοήθεια & FAQ';
$activePage = 'help';
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

<div class="page-header">
    <h1>Βοήθεια &amp; FAQ</h1>
    <p>Συχνές ερωτήσεις και οδηγίες χρήσης του συστήματος</p>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php
        /*
         * Τα FAQ ορίζονται στατικά ως array αντί να αποθηκεύονται στη βάση,
         * καθώς το περιεχόμενό τους αλλάζει σπάνια και δεν χρειάζεται
         * διαχείριση μέσω UI. Κάθε στοιχείο είναι [ερώτηση, απάντηση].
         * Οι απαντήσεις επιτρέπουν HTML (bold, br) για μορφοποίηση.
         */
        $faqs = [
            ['Πώς προσθέτω ένα νέο βιβλίο;', 'Κάντε κλικ στο "Προσθήκη Αντικειμένου" στο πλευρικό μενού ή στον κατάλογο. Συμπληρώστε τον τίτλο και τον συγγραφέα (υποχρεωτικά) και οποιαδήποτε άλλα στοιχεία γνωρίζετε.'],
            ['Τι σημαίνει "Δημόσια ορατό";', 'Αν ενεργοποιήσετε αυτή την επιλογή, το αντικείμενο θα εμφανίζεται στον δημόσιο κατάλογο που μπορούν να δουν οι επισκέπτες χωρίς σύνδεση.'],
            ['Πώς αλλάζω τον κωδικό μου;', 'Πηγαίνετε στις Ρυθμίσεις Προφίλ από το πλαϊνό μενού. Εκεί μπορείτε να αλλάξετε τον κωδικό πρόσβασης.'],
            ['Τι μπορούν να κάνουν οι διαφορετικοί ρόλοι;', '<strong>Admin:</strong> Πλήρης πρόσβαση — διαχείριση χρηστών, κατηγοριών, διαγραφές.<br><strong>Employee:</strong> Προσθήκη/επεξεργασία βιβλίων, αναφορές, μηνύματα.<br><strong>User:</strong> Μόνο προβολή δημόσιου καταλόγου.'],
            ['Πώς εξάγω τον κατάλογο σε CSV;', 'Στον κατάλογο βιβλιοθήκης ή στις αναφορές, κάντε κλικ στο κουμπί "Εξαγωγή CSV". Μπορείτε να εξαγάγετε και φιλτραρισμένα αποτελέσματα.'],
            ['Πού βλέπω ποιος έκανε τι;', 'Το αρχείο καταγραφής (Αντίγραφο Ασφαλείας) στο μενού Διαχείρισης καταγράφει όλες τις ενέργειες: συνδέσεις, δημιουργίες, επεξεργασίες και διαγραφές.'],
            ['Πώς προσθέτω εξώφυλλο σε βιβλίο;', 'Κατά την προσθήκη/επεξεργασία βιβλίου, εισάγετε τη διεύθυνση URL μιας εικόνας στο πεδίο "URL Εικόνας". Μπορείτε να βρείτε εικόνες στο Google Images ή στο Open Library (covers.openlibrary.org).'],
        ];

        // Το $i χρησιμοποιείται για την αρίθμηση (1-based) του κάθε FAQ card
        foreach ($faqs as $i => [$q, $a]):
        ?>
        <div class="card-panel mb-2">
            <div class="d-flex gap-3 align-items-start">
                <div style="width:28px;height:28px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#1a1a2e;flex-shrink:0">
                    <?= $i+1 ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:14px;margin-bottom:6px"><?= $q ?></div>
                    <!-- Χωρίς h() εδώ — οι απαντήσεις περιέχουν ηθελημένο HTML (bold, br) -->
                    <div style="font-size:13px;color:#4b5563;line-height:1.7"><?= $a ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="col-lg-4">
        <!-- Πληροφορίες συστήματος — PHP_VERSION εκτυπώνεται live από τον server -->
        <div class="card-panel" style="background:#1a1a2e;color:#fff">
            <h6 style="font-family:'Playfair Display',serif;font-size:16px;margin-bottom:16px;color:#e8c547">Πληροφορίες Συστήματος</h6>
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.5px">Εφαρμογή</div>
                    <div style="font-size:13px;margin-top:2px">Σύστημα Διαχείρισης Βιβλιοθήκης openGPLMS</div>
                </div>
                <div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.5px">Έκδοση</div>
                    <div style="font-size:13px;margin-top:2px">0.1</div>
                </div>
                <div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.5px">Τεχνολογία</div>
                    <div style="font-size:13px;margin-top:2px">PHP <?= PHP_VERSION ?> · MySQL · Bootstrap 5</div>
                </div>
                <div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.5px">Ρόλος</div>
                    <!-- Εμφάνιση του ρόλου του συνδεδεμένου χρήστη — h() για XSS protection -->
                    <div style="font-size:13px;margin-top:2px;color:#e8c547;text-transform:capitalize"><?= h($_SESSION['role']) ?></div>
                </div>
            </div>
        </div>

        <div class="card-panel mt-3">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:12px">Γρήγοροι Σύνδεσμοι</h6>
            <div class="d-grid gap-2">
                <a href="catalog.php" class="btn btn-outline-secondary btn-sm text-start"><i class="bi bi-journal-bookmark me-2"></i>Κατάλογος</a>
                <a href="add_book.php" class="btn btn-outline-secondary btn-sm text-start"><i class="bi bi-plus-circle me-2"></i>Νέο Αντικείμενο</a>
                <a href="reports.php" class="btn btn-outline-secondary btn-sm text-start"><i class="bi bi-bar-chart me-2"></i>Αναφορές</a>
                <a href="profile.php" class="btn btn-outline-secondary btn-sm text-start"><i class="bi bi-person me-2"></i>Προφίλ</a>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_admin_end.php'; ?>