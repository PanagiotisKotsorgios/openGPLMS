<?php
require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι έχουν πρόσβαση
$db = getDB();

// ── Στατιστικά για τα summary cards ──
$totalBooks   = $db->query("SELECT COUNT(*) FROM books")->fetchColumn();
$newThisMonth = $db->query("SELECT COUNT(*) FROM books WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$typeCount    = $db->query("SELECT COUNT(DISTINCT type) FROM books")->fetchColumn();
$totalUsers   = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Τελευταίες 5 καταχωρήσεις για τον πίνακα "Πρόσφατες Καταχωρήσεις"
$recentBooks  = $db->query("SELECT b.*, c.name as cat_name FROM books b LEFT JOIN categories c ON b.category_id=c.id ORDER BY b.created_at DESC LIMIT 5")->fetchAll();

// Κατανομή βιβλίων ανά τύπο — τροφοδοτεί το doughnut chart
$typeStats    = $db->query("SELECT type, COUNT(*) as cnt FROM books GROUP BY type")->fetchAll();

// Top 6 κατηγορίες ανά πλήθος βιβλίων — έτοιμο αν χρειαστεί για μελλοντικό chart/widget
$catStats     = $db->query("SELECT c.name, COUNT(b.id) as cnt FROM categories c LEFT JOIN books b ON b.category_id=c.id GROUP BY c.id, c.name ORDER BY cnt DESC LIMIT 6")->fetchAll();

// Μηνιαίες καταχωρήσεις των τελευταίων 6 μηνών — τροφοδοτεί το bar chart
$monthlyStats = $db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo, COUNT(*) as cnt FROM books WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY mo ORDER BY mo")->fetchAll();

$pageTitle  = 'Πίνακας Ελέγχου';
$activePage = 'dashboard';
include 'layout_admin.php';
?>

<style>
    .btn .bi { font-size: 1.05em; }
</style>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <h1>Πίνακας Ελέγχου</h1>
        <p>Καλωσορίσατε πίσω, <?= h($_SESSION['username']) ?>. Εδώ είναι η επισκόπηση της βιβλιοθήκης.</p>
    </div>
    <a href="assets/documents/user-manual-mbs.pdf" class="btn btn-outline-gold btn-sm d-inline-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-word"></i>
        Οδηγίες χρήσης συστήματος (PDF)
    </a>
</div>

<!-- STATS ROW -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card d-flex align-items-start gap-3">
            <div class="stat-icon"><i class="bi bi-book"></i></div>
            <div>
                <div class="stat-label">Σύνολο Αντικειμένων</div>
                <div class="stat-value"><?= $totalBooks ?></div>
                <div class="stat-sub">Καταχωρημένα στη βάση</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card d-flex align-items-start gap-3">
            <div class="stat-icon"><i class="bi bi-calendar-plus"></i></div>
            <div>
                <div class="stat-label">Νέες Καταχωρήσεις</div>
                <div class="stat-value"><?= $newThisMonth ?></div>
                <div class="stat-sub">Τελευταίοι 30 ημέρες</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card d-flex align-items-start gap-3">
            <div class="stat-icon"><i class="bi bi-layers"></i></div>
            <div>
                <div class="stat-label">Ποικιλία Τύπων</div>
                <div class="stat-value"><?= $typeCount ?></div>
                <div class="stat-sub">Διαφορετικές κατηγορίες</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card d-flex align-items-start gap-3">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div>
                <div class="stat-label">Χρήστες Συστήματος</div>
                <div class="stat-value"><?= $totalUsers ?></div>
                <div class="stat-sub">Ενεργοί λογαριασμοί</div>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS + RECENT -->
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card-panel h-100">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:20px">Κατανομή Αποθέματος</h6>
            <canvas id="typeChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card-panel h-100">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:20px">Ιστορικό Καταχωρήσεων</h6>
            <canvas id="monthChart" height="130"></canvas>
        </div>
    </div>
</div>

<!-- RECENT BOOKS -->
<div class="card-panel">
    <div class="d-flex align-items-center justify-content-between mb-16" style="margin-bottom:16px">
        <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin:0">Πρόσφατες Καταχωρήσεις</h6>
        <a href="catalog.php" class="btn btn-outline-gold btn-sm">Όλος ο Κατάλογος</a>
    </div>
    <?php if (empty($recentBooks)): ?>
    <div class="text-center text-muted py-4" style="font-size:13px">Δεν υπάρχουν καταχωρήσεις ακόμα.</div>
    <?php else: ?>
    <table class="table table-library mb-0">
        <thead><tr><th>Τίτλος</th><th>Συγγραφέας</th><th>Τύπος</th><th>Κατηγορία</th><th>Έτος</th><th>Κατάσταση</th></tr></thead>
        <tbody>
        <?php foreach ($recentBooks as $b): ?>
        <tr>
            <td><a href="book.php?id=<?= $b['id'] ?>" class="text-decoration-none fw-semibold" style="color:var(--text-primary)"><?= h($b['title']) ?></a></td>
            <td><?= h($b['author']) ?></td>
            <!-- Αντιστοίχιση τύπου βιβλίου σε CSS class για χρωματική διαφοροποίηση -->
            <td><span class="badge-type <?= $b['type']==='Βιβλίο'?'badge-book':($b['type']==='Περιοδικό'?'badge-magazine':'badge-other') ?>"><?= h($b['type']) ?></span></td>
            <td><?= h($b['cat_name'] ?? '—') ?></td>
            <td><?= $b['year'] ?: '—' ?></td>
            <!-- Αντιστοίχιση κατάστασης σε CSS class για χρωματική διαφοροποίηση -->
            <td><span class="badge-type <?= $b['status']==='Διαθέσιμο'?'badge-available':($b['status']==='Μη Διαθέσιμο'?'badge-unavailable':'badge-processing') ?>"><?= h($b['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
// Προετοιμασία δεδομένων για τα Chart.js γραφήματα
// Τα arrays μετατρέπονται σε JSON για απευθείας χρήση στη JavaScript
$typeLabels  = array_column($typeStats,    'type');
$typeVals    = array_column($typeStats,    'cnt');
$monthLabels = array_column($monthlyStats, 'mo');
$monthVals   = array_column($monthlyStats, 'cnt');

// Το $extraJs εισάγεται από το layout_admin_end.php πριν το </body>
// ώστε τα scripts να φορτώνουν μετά το DOM
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const gold = "#e8c547", dark = "#1a1a2e";

// Doughnut chart — κατανομή βιβλίων ανά τύπο
new Chart(document.getElementById("typeChart"), {
    type: "doughnut",
    data: {
        labels: ' . json_encode($typeLabels) . ',
        datasets: [{
            data: ' . json_encode($typeVals) . ',
            backgroundColor: ["#e8c547","#3b82f6","#22c55e","#ef4444"],
            borderWidth: 0, hoverOffset: 6
        }]
    },
    options: { plugins: { legend: { position: "bottom", labels: { font:{size:12}, padding:12 } } }, cutout:"65%" }
});

// Bar chart — μηνιαίες καταχωρήσεις τελευταίων 6 μηνών
new Chart(document.getElementById("monthChart"), {
    type: "bar",
    data: {
        labels: ' . json_encode($monthLabels) . ',
        datasets: [{ label:"Καταχωρήσεις", data: ' . json_encode($monthVals) . ',
            backgroundColor:"#e8c547", borderRadius:4 }]
    },
    options: { plugins: { legend:{ display:false } }, scales: { y:{ beginAtZero:true, ticks:{stepSize:1} } } }
});
</script>';

include 'layout_admin_end.php';
?>