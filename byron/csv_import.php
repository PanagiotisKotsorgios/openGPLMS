<?php
require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι έχουν πρόσβαση σε αυτή τη σελίδα

$db = getDB();

$results   = [];
$errors    = [];
$imported  = 0;
$skipped   = 0;
$processed = false; // Flag για να ξέρουμε αν έχει γίνει ήδη επεξεργασία αρχείου στο τρέχον request

/**
 * Βρίσκει εγγραφή σε πίνακα (categories ή publishers) βάσει ονόματος.
 * Αν δεν υπάρχει, τη δημιουργεί και επιστρέφει το νέο ID.
 * Επιστρέφει null αν το όνομα είναι κενό.
 */
function findOrCreate(PDO $db, string $table, string $name): ?int {
    if (trim($name) === '') return null;
    $name = trim($name);

    $s = $db->prepare("SELECT id FROM {$table} WHERE name=?");
    $s->execute([$name]);
    $row = $s->fetch();

    if ($row) return (int)$row['id']; // Υπάρχει ήδη — επιστρέφουμε το id της

    // Δεν υπάρχει — την εισάγουμε και επιστρέφουμε το νέο id
    $db->prepare("INSERT INTO {$table} (name) VALUES (?)")->execute([$name]);
    return (int)$db->lastInsertId();
}

/* ── Κατέβασμα template CSV ── */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="library_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM για σωστή εμφάνιση ελληνικών στο Excel
    fputcsv($out, ['title','author','isbn','type','category','publisher','year','language','pages','edition','volume','location','description','status','is_public','cover_url']);
    // Παραδείγματα εγγραφών
    fputcsv($out, ['Ερωτόκριτος','Βιτσέντζος Κορνάρος','978-960-01-0001-1','Βιβλίο','Ποίηση','Εστία','1713','Ελληνικά','250','1η','','Α1-Σ1','Κλασικό έπος','Διαθέσιμο','1','']);
    fputcsv($out, ['Nature Magazine Vol.12','Various Authors','','Περιοδικό','Επιστήμη','','2022','Αγγλικά','180','','Vol.12','Β2-Σ3','','Διαθέσιμο','1','']);
    fclose($out);
    exit;
}

/* ── Επεξεργασία του uploaded CSV ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $processed = true;
    $file = $_FILES['csv_file'];

    // Έλεγχος για σφάλματα κατά το upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Σφάλμα κατά το upload του αρχείου.';
    } elseif (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv','txt'])) {
        $errors[] = 'Επιτρέπονται μόνο αρχεία .csv';
    } else {
        $delimiter      = $_POST['delimiter'] ?? ',';
        $skipFirst      = isset($_POST['skip_header']) ? 1 : 0; // Παράλειψη header row
        $updateExisting = isset($_POST['update_existing']);      // Αν true, ενημερώνει υπάρχοντα ISBN αντί να τα παρακάμπτει

        $handle = fopen($file['tmp_name'], 'r');

        // Αφαίρεση BOM (Byte Order Mark) αν υπάρχει — συνήθης στα UTF-8 αρχεία από Excel
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) rewind($handle);

        $lineNum      = 0;
        $validTypes   = ['Βιβλίο','Περιοδικό','Άλλο'];
        $validStatus  = ['Διαθέσιμο','Μη Διαθέσιμο','Σε Επεξεργασία'];

        while (($row = fgetcsv($handle, 2000, $delimiter)) !== false) {
            $lineNum++;
            if ($skipFirst && $lineNum === 1) continue; // Παράλειψη γραμμής επικεφαλίδων
            if (count(array_filter($row)) === 0) continue; // Παράλειψη κενών γραμμών

            // Αντιστοίχιση στηλών CSV στις μεταβλητές
            $title       = trim($row[0]  ?? '');
            $author      = trim($row[1]  ?? '');
            $isbn        = trim($row[2]  ?? '');
            $type        = trim($row[3]  ?? 'Βιβλίο');
            $catName     = trim($row[4]  ?? '');
            $pubName     = trim($row[5]  ?? '');
            $year        = (int)($row[6]  ?? 0) ?: null; // 0 → null για να μην αποθηκευτεί άκυρη χρονολογία
            $language    = trim($row[7]  ?? 'Ελληνικά');
            $pages       = (int)($row[8]  ?? 0) ?: null;
            $edition     = trim($row[9]  ?? '');
            $volume      = trim($row[10] ?? '');
            $location    = trim($row[11] ?? '');
            $description = trim($row[12] ?? '');
            $status      = trim($row[13] ?? 'Διαθέσιμο');
            $isPublic    = trim($row[14] ?? '1') === '0' ? 0 : 1; // Default δημόσιο αν δεν οριστεί ρητά '0'
            $coverUrl    = trim($row[15] ?? '');

            // Επικύρωση υποχρεωτικών πεδίων
            if (!$title) {
                $errors[] = "Γραμμή {$lineNum}: Ο τίτλος είναι υποχρεωτικός — παραλείφθηκε.";
                $skipped++;
                continue;
            }
            if (!$author) {
                $errors[] = "Γραμμή {$lineNum}: Ο συγγραφέας είναι υποχρεωτικός — παραλείφθηκε.";
                $skipped++;
                continue;
            }

            // Fallback σε default τιμές αν οι τιμές του CSV δεν είναι αποδεκτές
            if (!in_array($type, $validTypes)) $type = 'Βιβλίο';
            if (!in_array($status, $validStatus)) $status = 'Διαθέσιμο';

            // Εύρεση ή δημιουργία κατηγορίας και εκδότη (upsert)
            $catId = $catName ? findOrCreate($db, 'categories', $catName) : null;
            $pubId = $pubName ? findOrCreate($db, 'publishers', $pubName) : null;

            // Έλεγχος αν το ISBN υπάρχει ήδη στη βάση
            $existingId = null;
            if ($isbn) {
                $chk = $db->prepare("SELECT id FROM books WHERE isbn=?");
                $chk->execute([$isbn]);
                $ex = $chk->fetch();
                if ($ex) $existingId = $ex['id'];
            }

            // Αν υπάρχει και δεν επιτρέπεται ενημέρωση → παράλειψη
            if ($existingId && !$updateExisting) {
                $skipped++;
                $results[] = ['line'=>$lineNum,'title'=>$title,'status'=>'skip','msg'=>'ISBN υπάρχει ήδη'];
                continue;
            }

            if ($existingId && $updateExisting) {
                // Ενημέρωση υπάρχουσας εγγραφής (δεν αλλάζουμε ISBN ούτε created_by)
                $db->prepare("UPDATE books SET title=?,author=?,type=?,category_id=?,publisher_id=?,year=?,language=?,pages=?,edition=?,volume=?,location=?,description=?,status=?,is_public=?,cover_url=? WHERE id=?")
                   ->execute([$title,$author,$type,$catId,$pubId,$year,$language,$pages,$edition,$volume,$location,$description,$status,$isPublic,$coverUrl,$existingId]);
                $results[] = ['line'=>$lineNum,'title'=>$title,'status'=>'update','msg'=>'Ενημερώθηκε'];
            } else {
                // Νέα εγγραφή — καταγράφουμε και τον χρήστη που έκανε την εισαγωγή
                $db->prepare("INSERT INTO books (title,author,isbn,type,category_id,publisher_id,year,language,pages,edition,volume,location,description,status,is_public,cover_url,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$title,$author,$isbn,$type,$catId,$pubId,$year,$language,$pages,$edition,$volume,$location,$description,$status,$isPublic,$coverUrl,$_SESSION['user_id']]);
                $results[] = ['line'=>$lineNum,'title'=>$title,'status'=>'ok','msg'=>'Εισήχθη'];
            }
            $imported++;
        }
        fclose($handle);

        // Καταγραφή της ενέργειας στο audit log για ιχνηλασιμότητα
        $db->prepare("INSERT INTO audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
           ->execute([$_SESSION['user_id'], 'csv_import', 'books', "Imported: {$imported}, Skipped: {$skipped}"]);

        if ($imported > 0) flash("Εισήχθησαν {$imported} εγγραφές επιτυχώς" . ($skipped > 0 ? ", {$skipped} παραλείφθηκαν." : "."));
    }
}

$pageTitle  = 'Εισαγωγή CSV';
$activePage = 'csv_import';
include 'layout_admin.php';


?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <h1>Μαζική Εισαγωγή CSV</h1>
        <p>Εισάγετε πολλαπλά βιβλία ταυτόχρονα μέσω αρχείου CSV</p>
    </div>
    <div class="d-flex gap-2">
        <a href="csv_editor.php" class="btn btn-outline-gold">
            <i class="bi bi-table me-1"></i> Online CSV Editor
        </a>
        <a href="?template=1" class="btn btn-gold">
            <i class="bi bi-download me-1"></i> Κατέβασε Template
        </a>
    </div>
</div>

<div class="row g-3">

    <!-- UPLOAD FORM -->
    <div class="col-lg-5">
        <div class="card-panel">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid #f3f4f6">
                <i class="bi bi-upload me-2" style="color:var(--gold)"></i>Ανέβασμα Αρχείου
            </h6>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <!-- Drop zone -->
                <div id="dropZone" onclick="document.getElementById('csv_file').click()"
                     style="border:2px dashed #d1d5db;border-radius:10px;padding:36px 20px;text-align:center;cursor:pointer;transition:all 0.2s;margin-bottom:20px;background:#fafafa">
                    <i class="bi bi-file-earmark-spreadsheet" style="font-size:40px;color:#d1d5db;display:block;margin-bottom:10px"></i>
                    <div style="font-size:14px;font-weight:600;color:#374151" id="dropText">Σύρετε το CSV εδώ ή κάντε κλικ</div>
                    <div style="font-size:12px;color:#9ca3af;margin-top:4px">Προτεινόμενο Μέγιστο 50MB · .csv ή .txt</div>
                </div>
                <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" style="display:none" onchange="fileSelected(this)">

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">Διαχωριστικό</label>
                        <select name="delimiter" class="form-select">
                            <option value=",">, (κόμμα)</option>
                            <option value=";">; (άνω τελεία)</option>
                            <option value="&#9;">Tab</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex flex-column gap-1 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="skip_header" id="skip_header" checked>
                                <label class="form-check-label" for="skip_header" style="font-size:13px">Παράλειψη 1ης γραμμής</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="update_existing" id="update_existing">
                                <label class="form-check-label" for="update_existing" style="font-size:13px">Ενημέρωση αν υπάρχει ISBN</label>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-gold w-100" id="submitBtn" disabled>
                    <i class="bi bi-cloud-upload me-1"></i> Εισαγωγή Δεδομένων
                </button>
            </form>
        </div>

        <!-- INSTRUCTIONS -->
        <div class="card-panel mt-3">
            <h6 style="font-family:'Playfair Display',serif;font-size:15px;margin-bottom:16px">
                <i class="bi bi-info-circle me-2" style="color:var(--gold)"></i>Οδηγίες
            </h6>
            <div style="font-size:13px;color:#374151;line-height:1.8">
                <p class="mb-2"><strong>Κατεβάστε το template</strong> από το κουμπί πάνω δεξιά για να δείτε την αναμενόμενη μορφή.</p>
                <p class="mb-2"><strong>Στήλες CSV (με σειρά):</strong></p>
                <div style="background:#f9fafb;border-radius:8px;padding:12px;font-size:11px;font-family:monospace;line-height:1.9;overflow-x:auto;white-space:nowrap">
                    title*, author*, isbn, type, category,<br>
                    publisher, year, language, pages, edition,<br>
                    volume, location, description, status, is_public, cover_url
                </div>
                <p class="mt-2 mb-0" style="font-size:12px;color:#9ca3af">* Υποχρεωτικά πεδία. Οι κατηγορίες και εκδότες δημιουργούνται αυτόματα αν δεν υπάρχουν.</p>
            </div>
        </div>
    </div>

    <!-- RESULTS -->
    <div class="col-lg-7">
        <?php if ($processed): ?>
        <!-- Summary -->
        <div class="row g-2 mb-3">
            <div class="col-4">
                <div style="background:#dcfce7;border-radius:10px;padding:16px;text-align:center">
                    <div style="font-size:28px;font-weight:700;color:#166534;font-family:'Playfair Display',serif"><?= $imported ?></div>
                    <div style="font-size:11px;color:#166534;font-weight:600;text-transform:uppercase;letter-spacing:0.5px">Εισήχθησαν</div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#fef3c7;border-radius:10px;padding:16px;text-align:center">
                    <div style="font-size:28px;font-weight:700;color:#92400e;font-family:'Playfair Display',serif"><?= $skipped ?></div>
                    <div style="font-size:11px;color:#92400e;font-weight:600;text-transform:uppercase;letter-spacing:0.5px">Παραλείφθηκαν</div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#fee2e2;border-radius:10px;padding:16px;text-align:center">
                    <div style="font-size:28px;font-weight:700;color:#991b1b;font-family:'Playfair Display',serif"><?= count($errors) ?></div>
                    <div style="font-size:11px;color:#991b1b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px">Σφάλματα</div>
                </div>
            </div>
        </div>

        <?php if ($errors): ?>
        <div class="card-panel mb-3" style="border-left:3px solid #ef4444">
            <h6 style="font-size:13px;font-weight:700;color:#991b1b;margin-bottom:10px"><i class="bi bi-exclamation-triangle me-1"></i>Σφάλματα / Προειδοποιήσεις</h6>
            <?php foreach ($errors as $e): ?>
            <div style="font-size:12px;color:#374151;padding:4px 0;border-bottom:1px solid #fee2e2"><?= h($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($results): ?>
        <div class="card-panel p-0" style="overflow:hidden">
            <div style="padding:14px 18px;border-bottom:1px solid #f3f4f6;background:#f9fafb;display:flex;align-items:center;justify-content:between;gap:8px">
                <span style="font-size:13px;font-weight:600">Αποτελέσματα Επεξεργασίας</span>
                <span style="font-size:11px;color:#9ca3af;margin-left:auto"><?= count($results) ?> γραμμές</span>
            </div>
            <div style="max-height:400px;overflow-y:auto">
            <table class="table table-library mb-0">
                <thead><tr><th>#</th><th>Τίτλος</th><th>Αποτέλεσμα</th><th>Σημείωση</th></tr></thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td style="color:#9ca3af"><?= $r['line'] ?></td>
                    <td style="font-weight:500"><?= 
                    (mb_strimwidth($r['title'],0,40,'…')) ?></td>
                    <td>
                        <?php
                        // Αντιστοίχιση status → CSS class + label για εμφάνιση στον πίνακα
                        $stMap = ['ok'=>['badge-available','Εισήχθη'],'update'=>['badge-processing','Ενημερώθηκε'],'skip'=>['badge-unavailable','Παραλείφθηκε']];
                        [$cls,$lbl] = $stMap[$r['status']] ?? ['badge-other','—'];
                        ?>
                        <span class="badge-type <?= $cls ?>"><?= $lbl ?></span>
                    </td>
                    <td style="font-size:11px;color:#9ca3af"><?= h($r['msg']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($imported > 0): ?>
        <div class="mt-3 d-flex gap-2">
            <a href="catalog.php" class="btn btn-gold"><i class="bi bi-journal-bookmark me-1"></i>Προβολή Καταλόγου</a>
            <a href="csv_import.php" class="btn btn-outline-gold">Νέα Εισαγωγή</a>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Placeholder όταν δεν έχει γίνει ακόμα upload -->
        <div class="card-panel text-center" style="padding:60px 20px">
            <i class="bi bi-file-earmark-arrow-up" style="font-size:56px;color:#e5e7eb;display:block;margin-bottom:16px"></i>
            <div style="font-size:16px;font-weight:600;color:#374151;margin-bottom:8px">Έτοιμο για Εισαγωγή</div>
            <p style="font-size:13px;color:#9ca3af;margin-bottom:20px">Επιλέξτε ένα αρχείο CSV και πατήστε «Εισαγωγή Δεδομένων».<br>Μπορείτε επίσης να χρησιμοποιήσετε τον <strong>Online CSV Editor</strong> για να φτιάξετε το αρχείο σας.</p>
            <a href="csv_editor.php" class="btn btn-outline-gold">
                <i class="bi bi-table me-1"></i> Ανοίξτε τον CSV Editor
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Drag & drop — ενημέρωση του input file και του UI όταν ο χρήστης σύρει αρχείο
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.style.borderColor='var(--gold)'; dz.style.background='#fffbeb'; });
dz.addEventListener('dragleave', () => { dz.style.borderColor='#d1d5db'; dz.style.background='#fafafa'; });
dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.style.borderColor='#d1d5db'; dz.style.background='#fafafa';
    const f = e.dataTransfer.files[0];
    if (f) applyFile(f);
});

function fileSelected(inp) { if (inp.files[0]) applyFile(inp.files[0]); }

// Εφαρμόζει το επιλεγμένο αρχείο στο input και ξεκλειδώνει το κουμπί υποβολής
function applyFile(f) {
    const dt = new DataTransfer();
    dt.items.add(f);
    document.getElementById('csv_file').files = dt.files;
    document.getElementById('dropText').textContent = f.name;
    dz.style.borderColor = 'var(--gold)';
    dz.style.background = '#fffbeb';
    document.getElementById('submitBtn').disabled = false;
}
</script>

<?php include 'layout_admin_end.php'; ?>