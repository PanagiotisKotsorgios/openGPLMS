<?php
require 'config.php';
requireEmployee();
$db = getDB();

// Ανάκτηση δεδομένων για τα datalists/dropdowns του editor.
// Φορτώνονται μόνο τα ονόματα (όχι τα IDs) γιατί το CSV δουλεύει
// με plain text τιμές που αντιστοιχίζονται κατά την εισαγωγή.
$categories = $db->query("SELECT name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$publishers  = $db->query("SELECT name FROM publishers ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Ανάκτηση ENUM τύπων δυναμικά από τη βάση — ίδια λογική με book_add.php
$typeResult = $db->query("SHOW COLUMNS FROM books LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
preg_match("/^enum\((.*)\)$/", $typeResult['Type'], $matches);
$types = array_map(fn($v) => trim($v, "'"), explode(",", $matches[1]));

$pageTitle  = 'Online CSV Editor';
$activePage = 'csv_import';
include 'layout_admin.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <h1>Online CSV Editor</h1>
        <p>Δημιουργήστε ή επεξεργαστείτε βιβλία σε μορφή πίνακα, μετά κατεβάστε το CSV</p>
    </div>
    <div class="d-flex gap-2">
        <a href="csv_import.php" class="btn btn-outline-gold"><i class="bi bi-upload me-1"></i>Εισαγωγή CSV</a>
        <a href="catalog.php" class="btn btn-outline-secondary btn-sm">← Κατάλογος</a>
    </div>
</div>

<!-- Φόρτωση υπάρχοντος CSV αρχείου για επεξεργασία -->
<div class="import-panel">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div style="font-weight:600;font-size:13px;flex-shrink:0"><i class="bi bi-file-earmark-arrow-up me-1" style="color:var(--gold)"></i>Φόρτωση υπάρχοντος CSV:</div>
        <input type="file" id="loadCsvFile" accept=".csv,.txt" class="form-control" style="max-width:300px;font-size:13px">
        <button class="tb-btn" onclick="loadFromFile()"><i class="bi bi-folder2-open"></i> Φόρτωση</button>
        <div class="sep" style="width:1px;height:24px;background:#e5e7eb"></div>
        <div style="font-size:12px;color:#9ca3af">ή ξεκινήστε με κενό πίνακα παρακάτω</div>
    </div>
</div>

<!-- TOOLBAR -->
<div class="csv-toolbar">
    <button class="tb-btn" onclick="addRow()" title="Νέα γραμμή (Enter)"><i class="bi bi-plus-lg"></i> Γραμμή</button>
    <button class="tb-btn danger" onclick="deleteSelectedRows()" title="Διαγραφή επιλεγμένων"><i class="bi bi-trash"></i> Διαγραφή</button>
    <div class="sep"></div>
    <button class="tb-btn" onclick="duplicateRow()"><i class="bi bi-copy"></i> Αντιγραφή γραμμής</button>
    <button class="tb-btn" onclick="clearSelected()"><i class="bi bi-eraser"></i> Καθαρισμός</button>
    <div class="sep"></div>
    <button class="tb-btn" onclick="moveUp()"><i class="bi bi-arrow-up"></i></button>
    <button class="tb-btn" onclick="moveDown()"><i class="bi bi-arrow-down"></i></button>
    <div class="sep"></div>
    <button class="tb-btn" onclick="fillDown()" title="Γεμίστε κάτω (τρέχουσα τιμή σε όλες τις επιλεγμένες)"><i class="bi bi-arrow-bar-down"></i> Fill Down</button>
    <div class="sep"></div>
    <select id="rowCount" class="form-select form-select-sm" style="width:auto;font-size:12px" onchange="addMultipleRows()">
        <option value="">Προσθήκη N γραμμών...</option>
        <option value="5">+5 γραμμές</option>
        <option value="10">+10 γραμμές</option>
        <option value="20">+20 γραμμές</option>
        <option value="50">+50 γραμμές</option>
    </select>
    <div class="ms-auto d-flex gap-2">
        <button class="tb-btn" onclick="validateAll()"><i class="bi bi-check2-circle"></i> Έλεγχος</button>
        <button class="tb-btn primary" onclick="downloadCSV()"><i class="bi bi-download"></i> Κατέβασε CSV</button>
        <!-- Εναλλακτικά: δημιουργεί CSV στη μνήμη και το στέλνει απευθείας στο csv_import.php χωρίς να αποθηκευτεί τοπικά -->
        <button class="tb-btn primary" onclick="sendToImporter()" style="background:#1a1a2e;border-color:#1a1a2e;color:#e8c547"><i class="bi bi-upload"></i> Απευθείας Εισαγωγή</button>
    </div>
</div>

<!-- ΠΙΝΑΚΑΣ ΕΠΕΞΕΡΓΑΣΙΑΣ -->
<div class="tbl-wrap" id="tblWrap">
<table id="csvTable">
    <thead>
        <tr>
            <th><input type="checkbox" id="selectAll" onchange="toggleAll(this)" style="cursor:pointer"></th>
            <th>Τίτλος<span class="req">*</span></th>
            <th>Συγγραφέας<span class="req">*</span></th>
            <th>ISBN</th>
            <th>Τύπος</th>
            <th>Κατηγορία</th>
            <th>Εκδότης</th>
            <th>Έτος</th>
            <th>Γλώσσα</th>
            <th>Σελίδες</th>
            <th>Έκδοση</th>
            <th>Τόμος</th>
            <th>Τοποθεσία</th>
            <th>Περιγραφή</th>
            <th>Κατάσταση</th>
            <th>Δημόσιο</th>
            <th>Cover URL</th>
        </tr>
    </thead>
    <tbody id="tblBody"></tbody>
</table>
</div>
<div class="status-bar" id="statusBar">
    <span><strong id="rowCountLbl">0</strong> γραμμές</span>
    <span><strong id="selCountLbl">0</strong> επιλεγμένες</span>
    <span id="errLbl" style="color:#ef4444;display:none"><i class="bi bi-exclamation-triangle me-1"></i><strong id="errCount">0</strong> σφάλματα</span>
    <span class="ms-auto">Tip: Πατήστε Enter σε κελί για νέα γραμμή · Tab για επόμενο κελί · Ctrl+D για Fill Down</span>
</div>

<!--
    Κρυφή φόρμα για "Απευθείας Εισαγωγή".
    Το sendToImporter() δημιουργεί File object στη μνήμη, το εισάγει
    στο hidden file input και υποβάλλει τη φόρμα — το csv_import.php
    το λαμβάνει σαν κανονικό file upload χωρίς ο χρήστης να αποθηκεύσει τίποτα.
-->
<form method="POST" action="csv_import.php" enctype="multipart/form-data" id="directImportForm" style="display:none">
    <input type="hidden" name="skip_header" value="1">
    <input type="file" name="csv_file" id="directCsvInput">
</form>

<script>
// Δεδομένα από PHP — περνούν ως JS arrays για τα dropdowns/datalists
const CATS     = <?= json_encode($categories) ?>;
const PUBS     = <?= json_encode($publishers) ?>;
const TYPES    = <?= json_encode($types) ?>;
const STATUSES = ['Διαθέσιμο','Μη Διαθέσιμο','Σε Επεξεργασία'];
const LANGS    = ['Ελληνικά','Αγγλικά','Γερμανικά','Γαλλικά','Ιταλικά','Ισπανικά','Άλλη'];

let selectedRows = new Set(); // Σύνολο επιλεγμένων <tr> elements
let focusedCell  = null;      // { tr, idx } — τελευταίο εστιασμένο κελί, χρησιμοποιείται από fillDown()

// Βοηθητική συνάρτηση: επιστρέφει όλα τα inputs/selects μιας γραμμής
// ΕΞΑΙΡΕΙ το checkbox επιλογής γραμμής — αυτό ήταν η ρίζα του προβλήματος
// όπου το "on" του checkbox εμφανιζόταν ως τιμή του πεδίου τίτλου στο CSV.
function getRowInputs(tr) {
    return [...tr.querySelectorAll('input,select')].filter(el => el.type !== 'checkbox');
}

/*
 * makeRow(data) — Δημιουργεί ένα <tr> με όλα τα κελιά για μία εγγραφή.
 *
 * Τρεις τύποι κελιών:
 *   select → <select> με fixed επιλογές (type, status, is_public)
 *   combo  → <input> + <datalist> για free-text με autocomplete (category, publisher, language)
 *            Χρησιμοποιείται αντί <select> για να επιτρέπει τιμές που δεν υπάρχουν ακόμα στη βάση
 *   text/number → απλό <input>
 *
 * Η σειρά των cols πρέπει να ταιριάζει ΑΚΡΙΒΩΣ με τις CSV headers.
 */
function makeRow(data = {}) {
    const tr = document.createElement('tr');

    // Checkbox για επιλογή γραμμής
    const tdCb = document.createElement('td');
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.style.cursor = 'pointer';
    cb.addEventListener('change', () => {
        if (cb.checked) { selectedRows.add(tr); tr.classList.add('selected'); }
        else { selectedRows.delete(tr); tr.classList.remove('selected'); }
        updateStatus();
    });
    tdCb.appendChild(cb);
    tr.appendChild(tdCb);

    const cols = [
        { key:'title',       type:'text',   required:true  },
        { key:'author',      type:'text',   required:true  },
        { key:'isbn',        type:'text'                   },
        { key:'type',        type:'select', options:TYPES, default: TYPES[0] ?? 'Βιβλίο' },
        { key:'category',    type:'combo',  options:CATS   },
        { key:'publisher',   type:'combo',  options:PUBS   },
        { key:'year',        type:'number'                 },
        { key:'language',    type:'combo',  options:LANGS, default:'Ελληνικά' },
        { key:'pages',       type:'number'                 },
        { key:'edition',     type:'text'                   },
        { key:'volume',      type:'text'                   },
        { key:'location',    type:'text'                   },
        { key:'description', type:'text'                   },
        { key:'status',      type:'select', options:STATUSES, default:'Διαθέσιμο' },
        { key:'is_public',   type:'select', options:['1','0'], default:'1' },
        { key:'cover_url',   type:'text'                   },
    ];

    cols.forEach((col, idx) => {
        const td = document.createElement('td');
        let el;

        if (col.type === 'select') {
            el = document.createElement('select');
            col.options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = opt.textContent = o;
                el.appendChild(opt);
            });
            el.value = data[col.key] ?? col.default ?? col.options[0];
        } else if (col.type === 'combo') {
            el = document.createElement('input');
            el.type = 'text';
            el.setAttribute('list', 'dl_' + col.key);
            el.value = data[col.key] ?? col.default ?? '';
            // Δημιουργία datalist μόνο αν δεν υπάρχει ήδη — κοινόχρηστο μεταξύ γραμμών
            let dl = document.getElementById('dl_' + col.key);
            if (!dl) {
                dl = document.createElement('datalist');
                dl.id = 'dl_' + col.key;
                col.options.forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = o;
                    dl.appendChild(opt);
                });
                document.body.appendChild(dl);
            }
        } else {
            el = document.createElement('input');
            el.type = col.type === 'number' ? 'number' : 'text';
            el.value = data[col.key] ?? '';
        }

        // Tracking focused cell για το fillDown()
        el.addEventListener('focus', () => { focusedCell = { tr, idx }; });
        el.addEventListener('keydown', e => handleKeydown(e, tr, idx));

        // Live validation μόνο για required πεδία (title, author)
        if (col.required) {
            el.addEventListener('input', () => {
                td.classList.toggle('err', !el.value.trim());
                updateErrCount();
            });
        }

        td.appendChild(el);
        tr.appendChild(td);
    });

    // Click στο <tr> (εκτός input) toggle-άρει την επιλογή της γραμμής
    tr.addEventListener('click', e => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
        const cb2 = tr.querySelector('input[type=checkbox]');
        cb2.checked = !cb2.checked;
        cb2.dispatchEvent(new Event('change'));
    });

    return tr;
}

/*
 * handleKeydown — πλοήγηση με πληκτρολόγιο.
 * Enter: μετάβαση στην ίδια στήλη της επόμενης γραμμής (ή δημιουργία νέας αν είμαστε στην τελευταία)
 * Ctrl+D: Fill Down (ίδια λογική με Excel)
 */
function handleKeydown(e, tr, colIdx) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        const rows = [...document.getElementById('tblBody').children];
        const rIdx = rows.indexOf(tr);
        if (rIdx === rows.length - 1) addRow();
        const nextTr = rows[rIdx + 1] || rows[rIdx];
        // FIX: χρησιμοποιούμε getRowInputs() για να εξαιρεθεί το checkbox
        const inputs = getRowInputs(nextTr);
        if (inputs[colIdx]) inputs[colIdx].focus();
    }
    if (e.key === 'd' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        fillDown();
    }
}

function addRow(data = {}) {
    const tbody = document.getElementById('tblBody');
    tbody.appendChild(makeRow(data));
    updateStatus();
    // Auto-scroll και focus στην πρώτη στήλη της νέας γραμμής
    const last = tbody.lastElementChild;
    // FIX: χρησιμοποιούμε getRowInputs() — focus στον τίτλο, όχι στο checkbox
    const inp  = getRowInputs(last)[0];
    if (inp) { inp.focus(); inp.scrollIntoView({ block:'nearest' }); }
}

function addMultipleRows() {
    const n = parseInt(document.getElementById('rowCount').value);
    if (!n) return;
    for (let i = 0; i < n; i++) addRow();
    document.getElementById('rowCount').value = ''; // Reset dropdown μετά την προσθήκη
}

function deleteSelectedRows() {
    if (selectedRows.size === 0) { alert('Επιλέξτε γραμμές για διαγραφή.'); return; }
    selectedRows.forEach(tr => tr.remove());
    selectedRows.clear();
    updateStatus();
}

function duplicateRow() {
    if (selectedRows.size === 0) { alert('Επιλέξτε μια γραμμή.'); return; }
    // Αντιγράφει την τελευταία επιλεγμένη γραμμή
    const tr   = [...selectedRows][selectedRows.size - 1];
    const data = getRowData(tr);
    addRow(data);
}

function clearSelected() {
    selectedRows.forEach(tr => {
        // FIX: εξαιρούμε το checkbox από το reset
        getRowInputs(tr).forEach(el => { el.value = ''; });
    });
}

function moveUp() {
    if (selectedRows.size !== 1) return;
    const tr   = [...selectedRows][0];
    const prev = tr.previousElementSibling;
    if (prev) tr.parentNode.insertBefore(tr, prev);
}
function moveDown() {
    if (selectedRows.size !== 1) return;
    const tr   = [...selectedRows][0];
    const next = tr.nextElementSibling;
    if (next) tr.parentNode.insertBefore(next, tr);
}

/*
 * fillDown — αντιγράφει την τιμή του focusedCell:
 *   α) Αν υπάρχουν επιλεγμένες γραμμές (>1): σε όλες εκτός της πηγής
 *   β) Αν δεν υπάρχουν: σε όλες τις γραμμές κάτω από την τρέχουσα
 * Αντίστοιχη λογική με το Ctrl+D του Excel.
 */
function fillDown() {
    if (focusedCell === null) return;
    const { tr, idx } = focusedCell;
    // FIX: χρησιμοποιούμε getRowInputs() για να εξαιρεθεί το checkbox
    const srcEl = getRowInputs(tr)[idx];
    if (!srcEl) return;
    const val  = srcEl.value;
    const rows = [...document.getElementById('tblBody').children];
    const startIdx = rows.indexOf(tr);
    const targets  = selectedRows.size > 1 ? [...selectedRows] : rows.slice(startIdx + 1);
    targets.forEach(row => {
        if (row === tr) return;
        // FIX: χρησιμοποιούμε getRowInputs() για να εξαιρεθεί το checkbox
        const el = getRowInputs(row)[idx];
        if (el) el.value = val;
    });
}

function toggleAll(cb) {
    document.querySelectorAll('#tblBody tr').forEach(tr => {
        const trCb = tr.querySelector('input[type=checkbox]');
        trCb.checked = cb.checked;
        if (cb.checked) { selectedRows.add(tr); tr.classList.add('selected'); }
        else { selectedRows.delete(tr); tr.classList.remove('selected'); }
    });
    updateStatus();
}

function updateStatus() {
    const rows = document.getElementById('tblBody').children.length;
    document.getElementById('rowCountLbl').textContent = rows;
    document.getElementById('selCountLbl').textContent = selectedRows.size;
}

function updateErrCount() {
    const errs = document.querySelectorAll('#tblBody td.err').length;
    const lbl  = document.getElementById('errLbl');
    document.getElementById('errCount').textContent = errs;
    lbl.style.display = errs > 0 ? '' : 'none';
}

// Manual validation — ελέγχει μόνο τα υποχρεωτικά πεδία (title, author)
function validateAll() {
    let errs = 0;
    document.querySelectorAll('#tblBody tr').forEach((tr, i) => {
        // FIX: εξαιρούμε checkbox — inputs[0]=title, inputs[1]=author
        const inputs = getRowInputs(tr);
        const title  = inputs[0]?.value?.trim();
        const author = inputs[1]?.value?.trim();
        tr.cells[1]?.classList.toggle('err', !title);
        tr.cells[2]?.classList.toggle('err', !author);
        if (!title || !author) errs++;
    });
    updateErrCount();
    if (errs === 0) {
        alert('Όλες οι γραμμές είναι έγκυρες!');
    } else {
        alert(`⚠ Βρέθηκαν ${errs} γραμμές με σφάλματα (τίτλος ή συγγραφέας λείπει). Ελέγξτε τα κόκκινα κελιά.`);
    }
}

// Επιστρέφει τα δεδομένα μιας γραμμής ως {key: value} object
function getRowData(tr) {
    const keys = ['title','author','isbn','type','category','publisher','year','language','pages','edition','volume','location','description','status','is_public','cover_url'];
    // FIX: εξαιρούμε checkbox — διαφορετικά keys[0] έπαιρνε την τιμή "on" του checkbox
    const inputs = getRowInputs(tr);
    const obj    = {};
    keys.forEach((k, i) => { obj[k] = inputs[i]?.value ?? ''; });
    return obj;
}

/*
 * getCSVContent — Δημιουργεί έγκυρο CSV string από τον πίνακα (RFC 4180).
 * Χειρίζεται: τιμές με κόμματα, escaped quotes ("") και newlines.
 * Προσθέτει UTF-8 BOM (\uFEFF) για σωστή εμφάνιση στο Excel.
 */
function getCSVContent() {
    const rows    = document.getElementById('tblBody').children;
    const headers = ['title','author','isbn','type','category','publisher','year','language','pages','edition','volume','location','description','status','is_public','cover_url'];
    let csv = '\uFEFF' + headers.join(',') + '\r\n';
    [...rows].forEach(tr => {
        // FIX: εξαιρούμε checkbox — αυτό ήταν το κυρίως πρόβλημα
        // το checkbox επέστρεφε "on" ως πρώτη τιμή, με αποτέλεσμα
        // title="on", author=<πραγματικός τίτλος>, isbn=<πραγματικός συγγραφέας> κ.ο.κ.
        const inputs = getRowInputs(tr);
        const vals = inputs.map(el => {
            let v = el.value.replace(/"/g, '""'); // Escape εσωτερικά quotes
            if (v.includes(',') || v.includes('"') || v.includes('\n')) v = '"' + v + '"';
            return v;
        });
        csv += vals.join(',') + '\r\n';
    });
    return csv;
}

function downloadCSV() {
    const rows = document.getElementById('tblBody').children.length;
    if (rows === 0) { alert('Δεν υπάρχουν δεδομένα για εξαγωγή.'); return; }
    const csv  = getCSVContent();
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    // Δημιουργία προσωρινού link για download — αποφεύγει popup blockers
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = 'byronic_library_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

/*
 * sendToImporter — Απευθείας εισαγωγή χωρίς αποθήκευση αρχείου.
 * Χρησιμοποιεί DataTransfer API για να εισάγει programmatically
 * ένα File object στο hidden file input και υποβάλλει τη φόρμα.
 * Υποστηρίζεται σε όλους τους modern browsers.
 */
function sendToImporter() {
    const rows = document.getElementById('tblBody').children.length;
    if (rows === 0) { alert('Δεν υπάρχουν δεδομένα.'); return; }
    const csv  = getCSVContent();
    const blob = new Blob([csv], { type: 'text/csv' });
    const file = new File([blob], 'editor_export.csv', { type: 'text/csv' });
    const dt   = new DataTransfer();
    dt.items.add(file);
    document.getElementById('directCsvInput').files = dt.files;
    document.getElementById('directImportForm').submit();
}

/*
 * loadFromFile — Φορτώνει υπάρχον CSV στον editor.
 * Auto-detect διαχωριστή (,  ;  tab) από την πρώτη γραμμή.
 * Αφαιρεί BOM αν υπάρχει.
 * Παραλείπει την πρώτη γραμμή αν αναγνωρίζεται ως header
 * (περιέχει 'title' ή 'τίτλ').
 */
function loadFromFile() {
    const f = document.getElementById('loadCsvFile').files[0];
    if (!f) { alert('Επιλέξτε αρχείο CSV.'); return; }
    const reader = new FileReader();
    reader.onload = e => {
        let text = e.target.result;
        if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1); // Αφαίρεση BOM
        const firstLine = text.split('\n')[0];
        // Auto-detect: ; πριν tab, tab πριν κόμμα
        const delim = firstLine.includes(';') ? ';' : (firstLine.includes('\t') ? '\t' : ',');
        const lines = text.trim().split('\n');
        document.getElementById('tblBody').innerHTML = '';
        selectedRows.clear();
        const start = (lines[0].toLowerCase().includes('title') || lines[0].toLowerCase().includes('τίτλ')) ? 1 : 0;
        lines.slice(start).forEach(line => {
            if (!line.trim()) return;
            const cols = parseCSVLine(line, delim);
            const keys = ['title','author','isbn','type','category','publisher','year','language','pages','edition','volume','location','description','status','is_public','cover_url'];
            const data = {};
            keys.forEach((k, i) => { data[k] = cols[i] ?? ''; });
            addRow(data);
        });
    };
    reader.readAsText(f, 'UTF-8');
}

/*
 * parseCSVLine — RFC 4180 compliant CSV parser για μία γραμμή.
 * Χειρίζεται: εισαγωγικά, escaped quotes (""), οποιονδήποτε διαχωριστή.
 */
function parseCSVLine(line, delim) {
    const result = [];
    let cur = '', inQ = false;
    for (let i = 0; i < line.length; i++) {
        const c = line[i];
        if (c === '"') {
            if (inQ && line[i+1] === '"') { cur += '"'; i++; } // Escaped quote
            else inQ = !inQ;
        } else if (c === delim && !inQ) {
            result.push(cur.trim()); cur = '';
        } else {
            cur += c;
        }
    }
    result.push(cur.trim());
    return result;
}

// Αρχικοποίηση με 10 κενές γραμμές ώστε ο editor να φαίνεται έτοιμος αμέσως
window.addEventListener('DOMContentLoaded', () => {
    for (let i = 0; i < 10; i++) addRow();
    updateStatus();
});
</script>

<?php include 'layout_admin_end.php'; ?>