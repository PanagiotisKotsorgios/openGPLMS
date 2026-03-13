<?php
require 'config.php';
$db = getDB();

// ── Login handler (modal POST) ──────────────────────────────
$loginError = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    verifyCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!loginCheckRateLimit($ip)) {
        $remaining = loginRemainingSeconds($ip);
        $loginError = "Πολλές αποτυχημένες προσπάθειες. Δοκιμάστε ξανά σε " . ceil($remaining/60) . " λεπτό(ά).";
    } elseif ($username && $password) {
        $stmt = $db->prepare("SELECT * FROM " . TABLE_PREFIX . "users WHERE username=? AND active=1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            loginClearAttempts($ip);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
               ->execute([$user['id'], 'login', 'user', 'Login from ' . $ip]);
            header('Location: dashboard.php'); exit;
        } else {
            loginRecordFailure($ip);
            $attemptsLeft = max(0, LOGIN_MAX_ATTEMPTS - ($_SESSION['login_attempts_' . md5($ip)]['count'] ?? 0));
            $loginError = 'Λάθος όνομα χρήστη ή κωδικός πρόσβασης.'
                        . ($attemptsLeft > 0 ? " ({$attemptsLeft} προσπάθεια(ες) ακόμα)" : '');
        }
    } else {
        $loginError = 'Παρακαλώ συμπληρώστε όλα τα πεδία.';
    }
}

// ── Query params — φίλτρα και pagination από URL ──
$search  = trim($_GET['search'] ?? '');
$typeF   = $_GET['type']     ?? '';
$langF   = $_GET['lang']     ?? '';
$catF    = $_GET['cat']      ?? '';
$pubF    = $_GET['pub']      ?? '';
$sort    = $_GET['sort']     ?? 'title_asc';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50])) $perPage = 10; // Whitelist — αποτροπή произвольных τιμών

/*
 * Τα labels των sort options δεν βρίσκονται εδώ — μεταφράζονται αποκλειστικά
 * από τη JavaScript μέσω του i18n translations object.
 * Το PHP γνωρίζει μόνο το SQL ORDER BY fragment που αντιστοιχεί σε κάθε key.
 */
$sortOptions = [
    'title_asc'  => 'b.title ASC',
    'title_desc' => 'b.title DESC',
    'year_desc'  => 'b.year DESC',
    'year_asc'   => 'b.year ASC',
    'author_asc' => 'b.author ASC',
];
$orderBy = $sortOptions[$sort] ?? 'b.title ASC'; // Fallback αν έρθει άγνωστο sort key

// ── Dynamic WHERE με prepared statement ──
$where  = ['b.is_public=1']; // Πάντα φιλτράρουμε μόνο δημόσια βιβλία
$params = [];

if ($search) {
    /*
     * Το ίδιο search term επαναλαμβάνεται 4 φορές στα params γιατί το PDO
     * με positional (?) binding δεν υποστηρίζει επαναχρησιμοποίηση ονομαστικών
     * παραμέτρων — κάθε ? πρέπει να έχει ξεχωριστή θέση στο array.
     */
    $where[]  = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.description LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($typeF) { $where[] = "b.type=?";         $params[] = $typeF; }
if ($langF) { $where[] = "b.language=?";     $params[] = $langF; }
if ($catF)  { $where[] = "b.category_id=?";  $params[] = $catF;  }
if ($pubF)  { $where[] = "b.publisher_id=?"; $params[] = $pubF;  }

$whereStr = implode(' AND ', $where);

/*
 * Δύο queries: πρώτα COUNT(*) για το pagination, μετά τα πραγματικά δεδομένα.
 * Χρησιμοποιούμε τα ίδια $params και στα δύο — το LIMIT/OFFSET προστίθεται
 * μόνο στο δεύτερο query.
 */
$countStmt = $db->prepare("SELECT COUNT(*) FROM " . TABLE_PREFIX . "books b WHERE $whereStr");
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$pages  = max(1, ceil($total / $perPage));
// Clamp μετά τον υπολογισμό — π.χ. αν ο χρήστης είχε bookmark στη σελίδα 5 και τώρα υπάρχουν μόνο 2
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT b.*, c.name as cat_name, p.name as pub_name FROM " . TABLE_PREFIX . "books b
    LEFT JOIN " . TABLE_PREFIX . "categories c ON b.category_id=c.id
    LEFT JOIN " . TABLE_PREFIX . "publishers p ON b.publisher_id=p.id
    WHERE $whereStr ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$books = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM " . TABLE_PREFIX . "categories ORDER BY name")->fetchAll();
$publishers = $db->query("SELECT * FROM " . TABLE_PREFIX . "publishers ORDER BY name")->fetchAll();
// Μόνο γλώσσες που εμφανίζονται σε δημόσια βιβλία — αποφεύγουμε orphan options στο filter
$languages  = $db->query("SELECT DISTINCT language FROM " . TABLE_PREFIX . "books WHERE language IS NOT NULL AND is_public=1 ORDER BY language")->fetchAll(PDO::FETCH_COLUMN);
$hasFilters = $search || $typeF || $langF || $catF || $pubF; // Flag για UI (breadcrumb, "clear filters" btn)
$totalAll   = $db->query("SELECT COUNT(*) FROM " . TABLE_PREFIX . "books WHERE is_public=1")->fetchColumn(); // Σύνολο χωρίς φίλτρα

/*
 * Διαβάζουμε τους αποδεκτούς τύπους απευθείας από τον ορισμό του ENUM column
 * αντί να τους hardcode-άρουμε — έτσι αν προστεθεί νέος τύπος στη βάση,
 * εμφανίζεται αυτόματα στο filter χωρίς αλλαγή κώδικα.
 */
$typeResult = $db->query("SHOW COLUMNS FROM " . TABLE_PREFIX . "books LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
preg_match("/^enum\((.*)\)$/", $typeResult['Type'], $matches);
$types = array_map(fn($v) => trim($v, "'"), explode(",", $matches[1]));

/*
 * Serialize για JS — στέλνουμε μόνο id + name (όχι ολόκληρη την εγγραφή).
 * Τα IDs χρειάζονται ως form values, τα names ως display labels.
 * Η μετάφραση labels → English γίνεται client-side από το valueTranslations map.
 */
$jsCategories = json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $categories));
$jsPublishers = json_encode(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $publishers));
$jsTypes      = json_encode($types);
$jsLanguages  = json_encode(array_values($languages));
?>
<!DOCTYPE html>
<html lang="el" data-lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title data-i18n="page_title">Ψηφιακός Κατάλογος — openGPLMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
<link rel="stylesheet" href="assets/styles/index.css">
<link rel="stylesheet" href="assets/styles/index-styles.css">
</head>
<body>

<!-- ═══════════════════════════════════════════
     TOPBAR
════════════════════════════════════════════ -->
<div class="topbar">
    <a href="https://github.com/openGPLMS/openGPLMS" target="_blank" rel="noopener" class="topbar-brand">
        <img src="assets/opengplms-logo.png" alt="openGPLMS">
        <div class="brand-text">
            <div class="brand-name">openGPLMS</div>
            <div class="brand-sub">General Purpose Library Management System</div>
            <div class="brand-sub2">Open Source Library Management System</div>
        </div>
    </a>
    <div class="topbar-divider"></div>
    <div class="topbar-tagline" data-i18n="topbar_tagline">Ψηφιακός Κατάλογος Βιβλιοθήκης</div>
    <div class="topbar-actions">
        <a href="https://github.com/openGPLMS/openGPLMS" target="_blank" rel="noopener"
           style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;letter-spacing:0.5px;color:#555;border:1px solid #ddd;border-radius:20px;padding:4px 10px;text-decoration:none;transition:all .2s;white-space:nowrap"
           onmouseover="this.style.borderColor='#c8a96e';this.style.color='#c8a96e'" onmouseout="this.style.borderColor='#ddd';this.style.color='#555'">
            <i class="bi bi-github"></i> GitHub
        </a>
        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;letter-spacing:0.5px;color:#1a7850;border:1px solid rgba(34,139,87,0.3);border-radius:20px;padding:4px 10px;background:rgba(34,139,87,0.07);white-space:nowrap">
            <i class="bi bi-shield-check"></i> MIT
        </span>
        <a href="index.php" class="btn-outline">
            <i class="bi bi-house"></i>
            <span data-i18n="topbar_website">Αρχική</span>
        </a>
        <?php if (isLoggedIn()): ?>
            <!-- Employees παίρνουν link στο dashboard, απλοί users όχι -->
            <a href="<?= isEmployee() ? 'dashboard.php' : '#' ?>" class="btn-outline">
                <i class="bi bi-grid"></i>
                <span data-i18n="topbar_dashboard">Πίνακας Ελέγχου</span>
            </a>
            <a href="logout.php" class="btn-outline">
                <i class="bi bi-box-arrow-right"></i>
                <span data-i18n="topbar_logout">Αποσύνδεση</span>
            </a>
        <?php else: ?>
            <button type="button" onclick="openLoginModal()" class="btn-outline">
                <i class="bi bi-box-arrow-in-right"></i>
                <span data-i18n="topbar_login">Διαχείριση</span>
            </button>
        <?php endif; ?>

        <!-- Language switcher — αλλάζει μόνο το UI, δεν κάνει reload -->
        <div class="lang-switcher" id="langSwitcher">
            <button class="lang-btn" id="langBtn" onclick="toggleLangDropdown(event)" aria-label="Language">
                <span class="lang-flag" id="langFlag">🇬🇷</span>
                <span id="langLabel">ΕΛ</span>
                <i class="bi bi-chevron-down lang-chevron"></i>
            </button>
            <div class="lang-dropdown" id="langDropdown">
                <button class="lang-option active" id="opt-el" onclick="setLang('el')">
                    <span class="lang-flag">🇬🇷</span> Ελληνικά
                    <i class="bi bi-check2 lang-check"></i>
                </button>
                <button class="lang-option" id="opt-en" onclick="setLang('en')">
                    <span class="lang-flag">🇬🇧</span> English
                    <i class="bi bi-check2 lang-check"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Breadcrumb — εμφανίζει "Αποτελέσματα Αναζήτησης" μόνο αν υπάρχουν ενεργά φίλτρα -->
<div class="breadcrumb-bar">
    <a href="index.php"><i class="bi bi-house"></i> <span data-i18n="breadcrumb_home">Αρχική</span></a>
    <span class="breadcrumb-sep"><i class="bi bi-chevron-right"></i></span>
    <span class="breadcrumb-current" data-i18n="breadcrumb_catalog">Κατάλογος Βιβλιοθήκης</span>
    <?php if ($hasFilters): ?>
    <span class="breadcrumb-sep"><i class="bi bi-chevron-right"></i></span>
    <span class="breadcrumb-current" style="color:var(--gold)" data-i18n="breadcrumb_results">Αποτελέσματα Αναζήτησης</span>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════
     MAIN LAYOUT
════════════════════════════════════════════ -->
<div class="main-layout">

    <!-- SIDEBAR -->
    <div class="sidebar-filters">
        <div class="sidebar-header">
            <div class="sidebar-header-icon"><i class="bi bi-search"></i></div>
            <span class="sidebar-header-title" data-i18n="sidebar_title">Φίλτρα Αναζήτησης</span>
        </div>

        <form method="GET" id="filterForm">

            <!-- Full-text search — ψάχνει σε title, author, isbn, description -->
            <div class="filter-group">
                <label class="filter-label" data-i18n="filter_search">Αναζήτηση</label>
                <div class="search-wrap">
                    <i class="bi bi-search s-icon"></i>
                    <input type="text" name="search" class="form-control"
                           data-i18n-placeholder="filter_search_placeholder"
                           placeholder="Τίτλος, Συγγραφέας, ISBN..."
                           value="<?= h($search) ?>">
                </div>
            </div>

            <!--
                Searchable Select pattern — κάθε filter αποτελείται από 3 στοιχεία:
                1. .ss-wrap       → container με το custom dropdown UI
                2. .ss-display    → visible text input (typing για αναζήτηση στη λίστα)
                3. <select> (hidden) → πραγματικό form field που υποβάλλεται στον server

                Η JS class SearchableSelect συγχρονίζει τα 2 και 3.
                Το hidden select υπάρχει για να δουλεύει και χωρίς JS (graceful degradation).
            -->

            <!-- Category filter -->
            <div class="filter-group">
                <label class="filter-label" data-i18n="filter_category">Κατηγορία</label>
                <div class="ss-wrap" id="ss-cat">
                    <div class="ss-input-wrap">
                        <i class="bi bi-tag ss-search-icon"></i>
                        <input type="text" class="ss-display" autocomplete="off"
                               data-ss-for="cat" data-i18n-placeholder="filter_all_categories"
                               placeholder="Όλες οι Κατηγορίες">
                        <button type="button" class="ss-clear" aria-label="Clear"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="ss-panel" id="ss-panel-cat"></div>
                </div>
                <select name="cat" class="ss-hidden" id="sel-cat">
                    <option value=""></option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $catF == $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Language filter -->
            <div class="filter-group">
                <label class="filter-label" data-i18n="filter_language">Γλώσσα</label>
                <div class="ss-wrap" id="ss-lang">
                    <div class="ss-input-wrap">
                        <i class="bi bi-globe ss-search-icon"></i>
                        <input type="text" class="ss-display" autocomplete="off"
                               data-ss-for="lang" data-i18n-placeholder="filter_all_languages"
                               placeholder="Όλες οι Γλώσσες">
                        <button type="button" class="ss-clear" aria-label="Clear"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="ss-panel" id="ss-panel-lang"></div>
                </div>
                <select name="lang" class="ss-hidden" id="sel-lang">
                    <option value=""></option>
                    <?php foreach ($languages as $l): ?>
                    <option value="<?= h($l) ?>" <?= $langF === $l ? 'selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Type filter -->
            <div class="filter-group">
                <label class="filter-label" data-i18n="filter_type">Τύπος Υλικού</label>
                <div class="ss-wrap" id="ss-type">
                    <div class="ss-input-wrap">
                        <i class="bi bi-layers ss-search-icon"></i>
                        <input type="text" class="ss-display" autocomplete="off"
                               data-ss-for="type" data-i18n-placeholder="filter_all_types"
                               placeholder="Όλοι οι Τύποι">
                        <button type="button" class="ss-clear" aria-label="Clear"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="ss-panel" id="ss-panel-type"></div>
                </div>
                <select name="type" class="ss-hidden" id="sel-type">
                    <option value=""></option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?= h($t) ?>" <?= $typeF === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Publisher filter -->
            <div class="filter-group">
                <label class="filter-label" data-i18n="filter_publisher">Εκδότης</label>
                <div class="ss-wrap" id="ss-pub">
                    <div class="ss-input-wrap">
                        <i class="bi bi-building ss-search-icon"></i>
                        <input type="text" class="ss-display" autocomplete="off"
                               data-ss-for="pub" data-i18n-placeholder="filter_all_publishers"
                               placeholder="Όλοι οι Εκδότες">
                        <button type="button" class="ss-clear" aria-label="Clear"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="ss-panel" id="ss-panel-pub"></div>
                </div>
                <select name="pub" class="ss-hidden" id="sel-pub">
                    <option value=""></option>
                    <?php foreach ($publishers as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $pubF == $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Διατηρούμε sort/per_page ως hidden fields ώστε να μην χαθούν κατά το submit του form -->
            <input type="hidden" name="sort"     value="<?= h($sort) ?>">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">

            <button type="submit" class="btn-search">
                <i class="bi bi-search"></i>
                <span data-i18n="btn_search">Αναζήτηση</span>
            </button>

            <?php if ($hasFilters): ?>
            <a href="catalog_public.php" class="btn-clear-filters">
                <i class="bi bi-x-circle"></i>
                <span data-i18n="btn_clear">Καθαρισμός Φίλτρων</span>
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Overlay για mobile sidebar — εκτός sidebar ώστε να μην μπλοκάρει το περιεχόμενό του -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- CONTENT -->
    <div class="content-area">

        <button class="mobile-filter-toggle" id="mobileFilterToggle">
            <i class="bi bi-sliders2"></i> <span data-i18n="mobile_filters">Φίλτρα</span>
        </button>

        <div class="results-bar">
            <div class="results-count">
                <?php if ($hasFilters): ?>
                    <strong><?= $total ?></strong> <span data-i18n="results_found">αποτελέσματα</span>
                    <?= $search ? " <span data-i18n='results_for'>για</span> «<strong>" . h($search) . "</strong>»" : '' ?>
                <?php else: ?>
                    <span data-i18n="results_total">Σύνολο:</span> <strong><?= $totalAll ?></strong> <span data-i18n="results_items">αντικείμενα στον κατάλογο</span>
                <?php endif; ?>
            </div>
            <div class="results-bar-right">
                <div class="sort-group">
                    <label data-i18n="sort_label">Ταξινόμηση:</label>
                    <!-- Τα labels μεταφράζονται από τη JS — εδώ βάζουμε το key ως placeholder -->
                    <select onchange="updateParam('sort', this.value)" id="sortSelect">
                        <?php foreach (array_keys($sortOptions) as $key): ?>
                        <option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $key ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="per-page-group">
                    <label data-i18n="per_page_label">Ανά σελίδα:</label>
                    <select onchange="updateParam('per_page', this.value)">
                        <?php foreach ([10, 25, 50] as $pp): ?>
                        <option value="<?= $pp ?>" <?= $perPage == $pp ? 'selected' : '' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Catalog table -->
        <div class="catalog-table-wrap">
            <?php if (empty($books)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-journal-x"></i></div>
                <p data-i18n="empty_message">Δεν βρέθηκαν αποτελέσματα για αυτά τα κριτήρια.</p>
                <?php if ($hasFilters): ?>
                <a href="catalog_public.php"><i class="bi bi-arrow-left me-1"></i><span data-i18n="empty_clear">Αφαίρεση φίλτρων</span></a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <table class="catalog-table">
                <thead>
                    <tr>
                        <th data-i18n="col_title">Τίτλος &amp; Στοιχεία</th>
                        <th data-i18n="col_author">Συγγραφέας</th>
                        <th data-i18n="col_type">Τύπος</th>
                        <th data-i18n="col_year">Έτος</th>
                        <th data-i18n="col_status">Κατάσταση</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($books as $b): ?>
                <!--
                    Τα δεδομένα κάθε βιβλίου αποθηκεύονται ως data-* attributes στο <tr>.
                    Όταν ο χρήστης κάνει κλικ, η JS τα διαβάζει και γεμίζει το modal —
                    αποφεύγουμε έτσι AJAX call για κάθε βιβλίο ξεχωριστά.
                    Όλες οι τιμές περνούν από h() για XSS protection μέσα σε HTML attributes.
                -->
                <tr data-bs-toggle="modal" data-bs-target="#bookModal"
                    data-title="<?= h($b['title']) ?>"
                    data-author="<?= h($b['author']) ?>"
                    data-type="<?= h($b['type']) ?>"
                    data-year="<?= h($b['year'] ?? '') ?>"
                    data-lang="<?= h($b['language'] ?? '') ?>"
                    data-cat="<?= h($b['cat_name'] ?? '') ?>"
                    data-pub="<?= h($b['pub_name'] ?? '') ?>"
                    data-isbn="<?= h($b['isbn'] ?? '') ?>"
                    data-pages="<?= h($b['pages'] ?? '') ?>"
                    data-location="<?= h($b['location'] ?? '') ?>"
                    data-status="<?= h($b['status']) ?>"
                    data-desc="<?= h($b['description'] ?? '') ?>"
                    data-cover="<?= h($b['cover_url'] ?? '') ?>">
                    <td>
                        <div class="book-title-cell" style="display:flex;align-items:center;gap:10px">
                            <?php if ($b['cover_url']): ?>
                            <img src="<?= h($b['cover_url']) ?>"
                                 style="width:36px;height:50px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;flex-shrink:0">
                            <?php else: ?>
                            <!-- Placeholder όταν δεν υπάρχει εξώφυλλο -->
                            <div style="width:36px;height:50px;background:#f3f4f6;border-radius:4px;border:1px solid #e8e4da;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;color:#d1d5db">
                                <i class="bi bi-book"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <span class="title-text"><?= h($b['title']) ?></span>
                                <div class="book-meta">
                                    <?php if ($b['cat_name']): ?>
                                    <!-- data-raw-cat χρησιμοποιείται από τη JS για μετάφραση κατά το lang switch -->
                                    <span class="book-meta-item"><i class="bi bi-tag"></i><span data-raw-cat="<?= h($b['cat_name']) ?>"><?= h($b['cat_name']) ?></span></span>
                                    <?php endif; ?>
                                    <?php if ($b['isbn']): ?>
                                    <?php if ($b['cat_name']): ?><div class="book-meta-sep"></div><?php endif; ?>
                                    <span class="book-meta-item">ISBN <?= h($b['isbn']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td><?= h($b['author']) ?></td>
                    <td>
                        <!--
                            data-raw: η αρχική ελληνική τιμή από τη βάση (αυτή που στέλνεται στον server)
                            data-map: το key στο valueTranslations object για client-side μετάφραση
                        -->
                        <span class="badge <?= $b['type'] === 'Βιβλίο' ? 'badge-book' : ($b['type'] === 'Περιοδικό' ? 'badge-magazine' : 'badge-other') ?>"
                              data-raw="<?= h($b['type']) ?>" data-map="types">
                            <?= h($b['type']) ?>
                        </span>
                    </td>
                    <td><?= $b['year'] ?: '—' ?></td>
                    <td>
                        <span class="badge <?= $b['status'] === 'Διαθέσιμο' ? 'badge-available' : ($b['status'] === 'Μη Διαθέσιμο' ? 'badge-unavailable' : 'badge-processing') ?>"
                              data-raw="<?= h($b['status']) ?>" data-map="statuses">
                            <?= h($b['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Pagination — sliding window ±2 γύρω από την τρέχουσα σελίδα -->
        <?php if ($pages > 1): ?>
        <div class="pagination-wrap">
            <ul class="pagination">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php endif; ?>
                <?php
                // Παράδειγμα: σελίδα 7 από 20 → εμφανίζονται [5,6,7,8,9]
                $start = max(1, $page - 2);
                $end   = min($pages, $page + 2);
                for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

    </div><!-- /content-area -->
</div><!-- /main-layout -->


<!-- OPEN SOURCE BANNER -->
<div class="os-banner">
    <div class="os-banner-text">
        <i class="bi bi-git"></i>
        <span>openGPLMS is <strong>free &amp; open source</strong> — MIT License · Self-hosted · Community driven</span>
    </div>
    <div class="os-banner-links">
        <a href="https://github.com/openGPLMS/openGPLMS" target="_blank" rel="noopener" class="os-banner-btn github"><i class="bi bi-github"></i> Star on GitHub</a>
        <a href="https://github.com/openGPLMS/openGPLMS/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener" class="os-banner-btn contrib"><i class="bi bi-code-slash"></i> Contribute</a>
        <a href="https://github.com/openGPLMS/openGPLMS/issues" target="_blank" rel="noopener" class="os-banner-btn issues"><i class="bi bi-bug"></i> Report Issue</a>
        <a href="https://ko-fi.com/opengplms" target="_blank" rel="noopener" class="os-banner-btn donate"><i class="bi bi-heart-fill"></i> Donate</a>
    </div>
</div>

<!-- FOOTER -->
<footer>
    <div class="footer-main">
        <!-- Brand column -->
        <div class="footer-brand">
            <div class="footer-logo-wrap">
                <div class="footer-logo">
                    <img src="assets/opengplms-logo.png" alt="openGPLMS">
                </div>
            </div>
            <p class="footer-desc">A free, self-hosted library management system built for libraries of all sizes. Open source, multilingual, and community driven.</p>
        </div>
        <!-- Links column -->
        <div class="footer-col">
            <div class="footer-col-title">Project</div>
            <ul>
                <li><a href="https://github.com/openGPLMS/openGPLMS" target="_blank"><i class="bi bi-github"></i> GitHub Repository</a></li>
                <li><a href="https://github.com/openGPLMS/openGPLMS/releases" target="_blank"><i class="bi bi-tag"></i> Releases</a></li>
                <li><a href="https://github.com/openGPLMS/openGPLMS/blob/main/README.md" target="_blank"><i class="bi bi-file-text"></i> Documentation</a></li>
                <li><a href="https://github.com/openGPLMS/openGPLMS/blob/main/LICENSE" target="_blank"><i class="bi bi-shield-check"></i> MIT License</a></li>
            </ul>
        </div>
        <!-- Community column -->
        <div class="footer-col">
            <div class="footer-col-title">Community</div>
            <ul>
                <li><a href="https://github.com/openGPLMS/openGPLMS/blob/main/CONTRIBUTING.md" target="_blank"><i class="bi bi-code-slash"></i> Contribute</a></li>
                <li><a href="https://github.com/openGPLMS/openGPLMS/issues" target="_blank"><i class="bi bi-bug"></i> Report a Bug</a></li>
                <li><a href="https://github.com/openGPLMS/openGPLMS/discussions" target="_blank"><i class="bi bi-chat-dots"></i> Discussions</a></li>
                <li><a href="https://ko-fi.com/opengplms" target="_blank"><i class="bi bi-heart"></i> Donate / Support</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <div class="footer-copy">
            © <?= date('Y') ?> openGPLMS Contributors &nbsp;·&nbsp; <span data-i18n="footer_rights">Open Source under MIT License.</span>
        </div>
        <div class="footer-legal">
            <a href="#" onclick="openModal('privacy'); return false;"      data-i18n="footer_privacy">Privacy Policy</a>
            <div class="footer-legal-sep"></div>
            <a href="#" onclick="openModal('terms'); return false;"        data-i18n="footer_terms">Terms of Use</a>
            <div class="footer-legal-sep"></div>
            <a href="#" onclick="openModal('accessibility'); return false;" data-i18n="footer_accessibility">Accessibility</a>
            <div class="footer-legal-sep"></div>
            <a href="#" onclick="openModal('contact'); return false;"      data-i18n="footer_contact">Contact</a>
        </div>
    </div>
    <div class="footer-creator" data-i18n="footer_creator">
        <span class="fc-label">Created & Supported by</span>
        <span class="fc-name">
            <span class="corner tl"></span>
            <span class="corner tr"></span>
            <a href="#">Kotsorgios Panagiotis</a>
            <span class="corner bl"></span>
            <span class="corner br"></span>
        </span>
        <div class="fc-approved">
            Evaluated &amp; approved by
            <a href="https://yhatzis.gr/" target="_blank">Dr. Yiannis Hatzis</a>
        </div>
    </div>
</footer>


<!-- BOOK MODAL — γεμίζει από JS μέσω data-* attributes του κλικαρισμένου <tr> -->
<div class="modal fade" id="bookModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bmTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-3 text-center" id="bmCoverCol"><div id="bmCover"></div></div>
                    <div class="col" id="bmInfoCol">
                        <div class="row g-3" id="bmDetails"></div>
                        <div id="bmDesc" class="modal-desc"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i><span data-i18n="modal_close">Κλείσιμο</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- LEGAL MODALS — custom overlay (όχι Bootstrap) ώστε να μπορούν να ανοίξουν πάνω από το book modal -->
<div class="modal-overlay" id="modal-privacy">
    <div class="legal-modal">
        <div class="legal-modal-header">
            <span class="legal-modal-title" data-i18n="privacy_title">Πολιτική Απορρήτου</span>
            <button class="legal-modal-close" onclick="closeModal('privacy')"><i class="bi bi-x"></i></button>
        </div>
        <div class="legal-modal-body">
            <h4 data-i18n="privacy_h1">1. Συλλογή Δεδομένων</h4>
            <p data-i18n="privacy_p1">Ο δημόσιος κατάλογος δεν συλλέγει προσωπικά δεδομένα επισκεπτών.</p>
            <h4 data-i18n="privacy_h2">2. Cookies</h4>
            <p data-i18n="privacy_p2">Χρησιμοποιούμε αποκλειστικά τεχνικά cookies.</p>
            <h4 data-i18n="privacy_h3">3. Δεδομένα Βιβλιοθήκης</h4>
            <p data-i18n="privacy_p3">Τα βιβλιογραφικά δεδομένα είναι δημόσια διαθέσιμα.</p>
            <h4 data-i18n="privacy_h4">4. Επικοινωνία</h4>
            <p data-i18n="privacy_p4">Επικοινωνήστε μέσω της επίσημης ιστοσελίδας.</p>
            <h4 data-i18n="privacy_h5">5. Ευρωπαϊκή Νομοθεσία</h4>
            <p data-i18n="privacy_p5">Η επεξεργασία δεδομένων διέπεται από τον GDPR.</p>
        </div>
    </div>
</div>
<div class="modal-overlay" id="modal-terms">
    <div class="legal-modal">
        <div class="legal-modal-header">
            <span class="legal-modal-title" data-i18n="terms_title">Όροι Χρήσης</span>
            <button class="legal-modal-close" onclick="closeModal('terms')"><i class="bi bi-x"></i></button>
        </div>
        <div class="legal-modal-body">
            <h4 data-i18n="terms_h1">1. Αποδοχή Όρων</h4><p data-i18n="terms_p1">Η χρήση συνεπάγεται αποδοχή των παρόντων όρων.</p>
            <h4 data-i18n="terms_h2">2. Σκοπός</h4><p data-i18n="terms_p2">Ο κατάλογος παρέχεται για ενημερωτικούς σκοπούς.</p>
            <h4 data-i18n="terms_h3">3. Πνευματική Ιδιοκτησία</h4><p data-i18n="terms_p3">Το περιεχόμενο προστατεύεται από την ισχύουσα νομοθεσία.</p>
            <h4 data-i18n="terms_h4">4. Απαγορεύσεις</h4>
            <ul><li data-i18n="terms_li1">Scraping χωρίς άδεια</li><li data-i18n="terms_li2">Εμπορική χρήση χωρίς άδεια</li><li data-i18n="terms_li3">Παραποίηση βιβλιογραφικών δεδομένων</li></ul>
            <h4 data-i18n="terms_h5">5. Ευθύνη</h4><p data-i18n="terms_p5">Χωρίς εγγύηση πληρότητας.</p>
         
        </div>
    </div>
</div>
<div class="modal-overlay" id="modal-cookies">
    <div class="legal-modal">
        <div class="legal-modal-header">
            <span class="legal-modal-title" data-i18n="cookies_title">Πολιτική Cookies</span>
            <button class="legal-modal-close" onclick="closeModal('cookies')"><i class="bi bi-x"></i></button>
        </div>
        <div class="legal-modal-body">
            <h4 data-i18n="cookies_h1">Τι είναι τα Cookies</h4><p data-i18n="cookies_p1">Μικρά αρχεία κειμένου στον browser.</p>
            <h4 data-i18n="cookies_h2">Cookies που Χρησιμοποιούμε</h4><p data-i18n="cookies_p2">Session cookies για τεχνικούς λόγους.</p>
            <h4 data-i18n="cookies_h3">Cookies που ΔΕΝ Χρησιμοποιούμε</h4>
            <ul><li data-i18n="cookies_li1">Tracking</li><li data-i18n="cookies_li2">Advertising</li><li data-i18n="cookies_li3">Third-party</li><li data-i18n="cookies_li4">Analytics</li></ul>
        </div>
    </div>
</div>
<div class="modal-overlay" id="modal-accessibility">
    <div class="legal-modal">
        <div class="legal-modal-header">
            <span class="legal-modal-title" data-i18n="access_title">Δήλωση Προσβασιμότητας</span>
            <button class="legal-modal-close" onclick="closeModal('accessibility')"><i class="bi bi-x"></i></button>
        </div>
        <div class="legal-modal-body">
            <h4 data-i18n="access_h1">Δέσμευσή μας</h4><p data-i18n="access_p1">Στοχεύουμε σε πλήρη προσβασιμότητα.</p>
            <h4 data-i18n="access_h3">Χαρακτηριστικά</h4>
            <ul><li data-i18n="access_li1">Σημασιολογική HTML</li><li data-i18n="access_li2">Αντίθεση χρωμάτων</li><li data-i18n="access_li3">Πλοήγηση με πληκτρολόγιο</li><li data-i18n="access_li4">Συμβατότητα screen readers</li></ul>
        </div>
    </div>
</div>
<div class="modal-overlay" id="modal-contact">
    <div class="legal-modal">
        <div class="legal-modal-header">
            <span class="legal-modal-title" data-i18n="contact_title">Επικοινωνία</span>
            <button class="legal-modal-close" onclick="closeModal('contact')"><i class="bi bi-x"></i></button>
        </div>
        <div class="legal-modal-body">
            <h4 data-i18n="contact_h1">openGPLMS</h4>
            <p data-i18n="contact_p1">Επικοινωνήστε μαζί μας:</p>
            <ul>
                <li><strong data-i18n="contact_website_label">Ιστοσελίδα:</strong> <a href="https://www.github.com/openGPLMS/openGPLMS/" target="_blank" style="color:var(--gold)">github.com/openGPLMS/openGPLMS</a></li>
                <li><strong data-i18n="contact_address_label">Διεύθυνση:</strong> <span data-i18n="contact_address">GitHub: github.com/openGPLMS</span></li>
            </ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ══════════════════════════════════════════════════════════════
   DATA FROM PHP
   Τα filter data έρχονται από PHP ως JSON και αποθηκεύονται εδώ.
   Η JS χρησιμοποιεί αυτά για να χτίσει τα searchable dropdowns
   και να μεταφράσει τα values client-side.
══════════════════════════════════════════════════════════════ */
const DB = {
    categories: <?= $jsCategories ?>,
    publishers:  <?= $jsPublishers ?>,
    types:       <?= $jsTypes ?>,
    languages:   <?= $jsLanguages ?>,
};

/* ══════════════════════════════════════════════════════════════
   TRANSLATION MAPS
   Αντιστοίχιση ελληνικών τιμών βάσης → αγγλικά display labels.
   Σημαντικό: η τιμή που υποβάλλεται στον server παραμένει πάντα
   η αρχική ελληνική — αλλάζει μόνο αυτό που βλέπει ο χρήστης.
   Οι εκδότες δεν μεταφράζονται (κύρια ονόματα).
══════════════════════════════════════════════════════════════ */
const valueTranslations = {
    categories: {
        'Αρχαία Ελληνική Λογοτεχνία': 'Ancient Greek Literature',
        'Βυζαντινή Λογοτεχνία':       'Byzantine Literature',
        'Νεοελληνική Λογοτεχνία':     'Modern Greek Literature',
        'Ξένη Λογοτεχνία':            'Foreign Literature',
        'Ποίηση':                      'Poetry',
        'Δράμα':                       'Drama',
        'Πεζογραφία':                  'Prose',
        'Ιστορία':                     'History',
        'Φιλοσοφία':                   'Philosophy',
        'Θεολογία':                    'Theology',
        'Επιστήμη':                    'Science',
        'Επιστημονικό':                'Scientific',
        'Τέχνη':                       'Art',
        'Μουσική':                     'Music',
        'Γεωγραφία':                   'Geography',
        'Βιογραφία':                   'Biography',
        'Αυτοβιογραφία':               'Autobiography',
        'Παιδική Λογοτεχνία':         'Children\'s Literature',
        'Εκπαίδευση':                  'Education',
        'Γλωσσολογία':                 'Linguistics',
        'Πολιτική':                    'Politics',
        'Οικονομία':                   'Economics',
        'Κοινωνιολογία':               'Sociology',
        'Ψυχολογία':                   'Psychology',
        'Δίκαιο':                      'Law',
        'Ιατρική':                     'Medicine',
        'Τεχνολογία':                  'Technology',
        'Περιοδικό':                   'Magazine',
        'Λεξικό':                      'Dictionary',
        'Εγκυκλοπαίδεια':             'Encyclopedia',
        'Library Science':                 'Biblionomics',
        'Ρομαντισμός':                 'Romanticism',
        'Φιλελληνισμός':               'Philhellenism',
        'Επανάσταση 1821':             'Greek Revolution 1821',
        'Γενικό':                      'General',
        'Άλλο':                        'Other',
    },
    types: {
        'Βιβλίο':         'Book',
        'Περιοδικό':      'Magazine / Journal',
        'Εφημερίδα':      'Newspaper',
        'Χειρόγραφο':     'Manuscript',
        'Ημερολόγιο':     'Diary / Calendar',
        'Επιστολή':       'Letter',
        'Ψηφιακό Αρχείο': 'Digital Archive',
        'Άλλο':           'Other',
    },
    languages: {
        'Αγγλικά':         'English',
        'Ελληνικά':        'Greek',
        'Γαλλικά':         'French',
        'Γερμανικά':       'German',
        'Ιταλικά':         'Italian',
        'Ισπανικά':        'Spanish',
        'Πορτογαλικά':     'Portuguese',
        'Ρωσικά':          'Russian',
        'Αραβικά':         'Arabic',
        'Τουρκικά':        'Turkish',
        'Λατινικά':        'Latin',
        'Αρχαία Ελληνικά': 'Ancient Greek',
        'Κινεζικά':        'Chinese',
        'Ιαπωνικά':        'Japanese',
        'Ολλανδικά':       'Dutch',
        'Πολωνικά':        'Polish',
        'Σουηδικά':        'Swedish',
        'Δανικά':          'Danish',
        'Νορβηγικά':       'Norwegian',
        'Φινλανδικά':      'Finnish',
        'Τσεχικά':         'Czech',
        'Ουγγρικά':        'Hungarian',
        'Ρουμανικά':       'Romanian',
        'Βουλγαρικά':      'Bulgarian',
        'Σερβικά':         'Serbian',
        'Κροατικά':        'Croatian',
        'Αλβανικά':        'Albanian',
        'Εβραϊκά':         'Hebrew',
        'Περσικά':         'Persian',
    },
    statuses: {
        'Διαθέσιμο':      'Available',
        'Μη Διαθέσιμο':   'Unavailable',
        'Σε Επεξεργασία': 'Processing',
        'Σε Δανεισμό':    'On Loan',
        'Χαμένο':         'Lost',
        'Κατεστραμμένο':  'Damaged',
    },
    publishers: {}, // Κύρια ονόματα — δεν μεταφράζονται
};

/* ══════════════════════════════════════════════════════════════
   i18n STRINGS — el / en
   Κάθε UI string ορίζεται και στις δύο γλώσσες.
   Τα data-i18n attributes στο HTML δείχνουν στο αντίστοιχο key.
══════════════════════════════════════════════════════════════ */
const translations = {
    el: {
        page_title:            'Ψηφιακός Κατάλογος — openGPLMS',
        topbar_tagline:        'Ψηφιακός Κατάλογος Βιβλιοθήκης',
        topbar_website:        'Αρχική',
        topbar_dashboard:      'Πίνακας Ελέγχου',
        topbar_logout:         'Αποσύνδεση',
        topbar_login:          'Διαχείριση',
        breadcrumb_home:       'Αρχική',
        breadcrumb_catalog:    'Κατάλογος Βιβλιοθήκης',
        breadcrumb_results:    'Αποτελέσματα Αναζήτησης',
        sidebar_title:         'Φίλτρα Αναζήτησης',
        filter_search:         'Αναζήτηση',
        filter_search_placeholder: 'Τίτλος, Συγγραφέας, ISBN...',
        filter_category:       'Κατηγορία',
        filter_all_categories: 'Όλες οι Κατηγορίες',
        filter_language:       'Γλώσσα',
        filter_all_languages:  'Όλες οι Γλώσσες',
        filter_type:           'Τύπος Υλικού',
        filter_all_types:      'Όλοι οι Τύποι',
        filter_publisher:      'Εκδότης',
        filter_all_publishers: 'Όλοι οι Εκδότες',
        btn_search:            'Αναζήτηση',
        btn_clear:             'Καθαρισμός Φίλτρων',
        results_found:         'αποτελέσματα',
        results_for:           'για',
        results_total:         'Σύνολο:',
        results_items:         'αντικείμενα στον κατάλογο',
        sort_label:            'Ταξινόμηση:',
        per_page_label:        'Ανά σελίδα:',
        sort_title_asc:        'Αλφαβητικά (Α-Ω)',
        sort_title_desc:       'Αλφαβητικά (Ω-Α)',
        sort_year_desc:        'Νεότερα πρώτα',
        sort_year_asc:         'Παλαιότερα πρώτα',
        sort_author_asc:       'Συγγραφέας (Α-Ω)',
        col_title:             'Τίτλος & Στοιχεία',
        col_author:            'Συγγραφέας',
        col_type:              'Τύπος',
        col_year:              'Έτος',
        col_status:            'Κατάσταση',
        empty_message:         'Δεν βρέθηκαν αποτελέσματα για αυτά τα κριτήρια.',
        empty_clear:           'Αφαίρεση φίλτρων',
        modal_close:           'Κλείσιμο',
        modal_author:          'Συγγραφέας',
        modal_type:            'Τύπος',
        modal_year:            'Έτος',
        modal_language:        'Γλώσσα',
        modal_category:        'Κατηγορία',
        modal_publisher:       'Εκδότης',
        modal_isbn:            'ISBN',
        modal_pages:           'Σελίδες',
        modal_location:        'Τοποθεσία',
        modal_status:          'Κατάσταση',
        footer_rights:         'openGPLMS. Όλα τα δικαιώματα διατηρούνται.',
        footer_privacy:        'Πολιτική Απορρήτου',
        footer_terms:          'Όροι Χρήσης',
        footer_cookies:        'Πολιτική Cookies',
        footer_accessibility:  'Προσβασιμότητα',
        footer_contact:        'Επικοινωνία',
        ss_no_results:         'Δεν βρέθηκαν αποτελέσματα',
        ss_type_to_filter:     'Πληκτρολογήστε για αναζήτηση...',
        mobile_filters:        'Φίλτρα',
        privacy_title:'Πολιτική Απορρήτου', privacy_h1:'1. Συλλογή Δεδομένων', privacy_p1:'Ο δημόσιος κατάλογος δεν συλλέγει προσωπικά δεδομένα επισκεπτών.', privacy_h2:'2. Cookies', privacy_p2:'Χρησιμοποιούμε αποκλειστικά τεχνικά cookies.', privacy_h3:'3. Δεδομένα Βιβλιοθήκης', privacy_p3:'Τα βιβλιογραφικά δεδομένα είναι δημόσια διαθέσιμα.', privacy_h4:'4. Επικοινωνία', privacy_p4:'Επικοινωνήστε μέσω της επίσημης ιστοσελίδας.', privacy_h5:'5. Ευρωπαϊκή Νομοθεσία', privacy_p5:'Η επεξεργασία διέπεται από τον GDPR.',
        terms_title:'Όροι Χρήσης', terms_h1:'1. Αποδοχή Όρων', terms_p1:'Η χρήση συνεπάγεται αποδοχή των παρόντων όρων.', terms_h2:'2. Σκοπός', terms_p2:'Ο κατάλογος παρέχεται για ενημερωτικούς σκοπούς.', terms_h3:'3. Πνευματική Ιδιοκτησία', terms_p3:'Το περιεχόμενο προστατεύεται από την ισχύουσα νομοθεσία.', terms_h4:'4. Απαγορεύσεις', terms_li1:'Scraping χωρίς άδεια', terms_li2:'Εμπορική χρήση χωρίς άδεια', terms_li3:'Παραποίηση δεδομένων', terms_h5:'5. Ευθύνη', terms_p5:'Χωρίς εγγύηση πληρότητας.', 
        cookies_title:'Πολιτική Cookies', cookies_h1:'Τι είναι τα Cookies', cookies_p1:'Μικρά αρχεία κειμένου στον browser.', cookies_h2:'Cookies που Χρησιμοποιούμε', cookies_p2:'Session cookies για τεχνικούς λόγους.', cookies_h3:'Cookies που ΔΕΝ Χρησιμοποιούμε', cookies_li1:'Tracking', cookies_li2:'Advertising', cookies_li3:'Third-party', cookies_li4:'Analytics',
        access_title:'Δήλωση Προσβασιμότητας', access_h1:'Δέσμευσή μας', access_p1:'Στοχεύουμε σε πλήρη προσβασιμότητα.', access_h3:'Χαρακτηριστικά', access_li1:'Σημασιολογική HTML', access_li2:'Αντίθεση χρωμάτων', access_li3:'Πληκτρολόγιο', access_li4:'Screen readers',
        contact_title:'Επικοινωνία', contact_h1:'openGPLMS', contact_p1:'Επικοινωνήστε μαζί μας:', contact_website_label:'Ιστοσελίδα:', contact_address_label:'Διεύθυνση:', contact_address:'GitHub: github.com/openGPLMS',
    },
    en: {
        page_title:            'Digital Catalogue — openGPLMS',
        topbar_tagline:        'Library Digital Catalogue',
        topbar_website:        'Home',
        topbar_dashboard:      'Dashboard',
        topbar_logout:         'Sign Out',
        topbar_login:          'Management',
        breadcrumb_home:       'Home',
        breadcrumb_catalog:    'Library Catalogue',
        breadcrumb_results:    'Search Results',
        sidebar_title:         'Search Filters',
        filter_search:         'Search',
        filter_search_placeholder: 'Title, Author, ISBN...',
        filter_category:       'Category',
        filter_all_categories: 'All Categories',
        filter_language:       'Language',
        filter_all_languages:  'All Languages',
        filter_type:           'Material Type',
        filter_all_types:      'All Types',
        filter_publisher:      'Publisher',
        filter_all_publishers: 'All Publishers',
        btn_search:            'Search',
        btn_clear:             'Clear Filters',
        results_found:         'results',
        results_for:           'for',
        results_total:         'Total:',
        results_items:         'items in catalogue',
        sort_label:            'Sort by:',
        per_page_label:        'Per page:',
        sort_title_asc:        'Alphabetical (A-Z)',
        sort_title_desc:       'Alphabetical (Z-A)',
        sort_year_desc:        'Newest first',
        sort_year_asc:         'Oldest first',
        sort_author_asc:       'Author (A-Z)',
        col_title:             'Title & Details',
        col_author:            'Author',
        col_type:              'Type',
        col_year:              'Year',
        col_status:            'Status',
        empty_message:         'No results found for these criteria.',
        empty_clear:           'Remove filters',
        modal_close:           'Close',
        modal_author:          'Author',
        modal_type:            'Type',
        modal_year:            'Year',
        modal_language:        'Language',
        modal_category:        'Category',
        modal_publisher:       'Publisher',
        modal_isbn:            'ISBN',
        modal_pages:           'Pages',
        modal_location:        'Location',
        modal_status:          'Status',
        footer_rights:         'openGPLMS. Open Source under MIT License.',
        footer_privacy:        'Privacy Policy',
        footer_terms:          'Terms of Use',
        footer_cookies:        'Cookie Policy',
        footer_accessibility:  'Accessibility',
        footer_contact:        'Contact',
        ss_no_results:         'No results found',
        ss_type_to_filter:     'Type to search...',
        mobile_filters:        'Filters',
        privacy_title:'Privacy Policy', privacy_h1:'1. Data Collection', privacy_p1:'The public catalogue does not collect personal data from visitors.', privacy_h2:'2. Cookies', privacy_p2:'We use only technically necessary cookies.', privacy_h3:'3. Library Data', privacy_p3:'Bibliographic data is publicly available.', privacy_h4:'4. Contact', privacy_p4:'Contact us via the official website.', privacy_h5:'5. European Legislation', privacy_p5:'Data processing is governed by the GDPR.',
        terms_title:'Terms of Use', terms_h1:'1. Acceptance', terms_p1:'Use of the catalogue implies acceptance of these terms.', terms_h2:'2. Purpose', terms_p2:'The catalogue is provided for informational purposes only.', terms_h3:'3. Intellectual Property', terms_p3:'Content is protected by applicable law.', terms_h4:'4. Prohibited Uses', terms_li1:'Scraping without permission', terms_li2:'Commercial use without authorisation', terms_li3:'Falsification of bibliographic data', terms_h5:'5. Liability', terms_p5:'No guarantee of data completeness.', terms_h6:'6. Applicable Law', terms_p6:'Courts of Mesolonghi have jurisdiction.',
        cookies_title:'Cookie Policy', cookies_h1:'What Are Cookies', cookies_p1:'Small text files stored in your browser.', cookies_h2:'Cookies We Use', cookies_p2:'Session cookies for technical purposes only.', cookies_h3:'Cookies We Do NOT Use', cookies_li1:'Tracking', cookies_li2:'Advertising', cookies_li3:'Third-party', cookies_li4:'Analytics',
        access_title:'Accessibility Statement', access_h1:'Our Commitment', access_p1:'We aim for full accessibility for all users.', access_h3:'Features', access_li1:'Semantic HTML', access_li2:'Colour contrast', access_li3:'Keyboard navigation', access_li4:'Screen reader support',
        contact_title:'Contact', contact_h1:'openGPLMS', contact_p1:'Get in touch with us:', contact_website_label:'GitHub:', contact_address_label:'Contribute:', contact_address:'github.com/openGPLMS/openGPLMS/blob/main/CONTRIBUTING.md',
    }
};

/* ══════════════════════════════════════════════════════════════
   SearchableSelect — custom dropdown component
   ──────────────────────────────────────────────────────────────
   Αρχιτεκτονική:
   - visible input  → ο χρήστης πληκτρολογεί για να φιλτράρει τη λίστα
   - dropdown panel → εμφανίζει φιλτραρισμένα αποτελέσματα (max TOP)
   - hidden <select>→ η πραγματική τιμή που υποβάλλεται μέσω form GET

   Αυτή η υλοποίηση αντικαθιστά τις έτοιμες βιβλιοθήκες (select2/choices.js)
   ώστε να υποστηρίζεται client-side μετάφραση labels χωρίς reload.
══════════════════════════════════════════════════════════════ */
class SearchableSelect {
    /**
     * @param {string} id        — id του ss-wrap div
     * @param {Array}  items     — [{value, label}] πλήρης λίστα επιλογών
     * @param {string} selId     — id του hidden <select>
     * @param {string} filterKey — key στο valueTranslations για μετάφραση
     */
    constructor(id, items, selId, filterKey) {
        this.wrap      = document.getElementById(id);
        this.input     = this.wrap.querySelector('.ss-display');
        this.panel     = this.wrap.querySelector('.ss-panel');
        this.clearBtn  = this.wrap.querySelector('.ss-clear');
        this.hidden    = document.getElementById(selId);
        this.items     = items;
        this.filterKey = filterKey;
        this.selected  = { value: '', label: '' };
        this.TOP       = 10; // Max εμφανιζόμενα αποτελέσματα — πάνω από 10 γίνεται "X ακόμα..."

        this._bind();
        this._initFromHidden(); // Διαβάζουμε την ήδη επιλεγμένη τιμή (π.χ. από URL param)
    }

    /* Αρχικοποίηση από το hidden select — χρειάζεται για server-side pre-selected filters */
    _initFromHidden() {
        const opt = this.hidden.querySelector('option[selected]');
        if (opt && opt.value) {
            const item = this.items.find(i => String(i.value) === String(opt.value));
            if (item) this._select(item, false);
        }
    }

    _bind() {
        this.input.addEventListener('focus', () => this._open());
        this.input.addEventListener('input', () => this._render(this.input.value));
        this.clearBtn.addEventListener('click', (e) => { e.stopPropagation(); this._clear(); });
        document.addEventListener('click', (e) => {
            if (!this.wrap.contains(e.target)) this._close();
        });
        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { this._close(); this.input.blur(); }
            if (e.key === 'Enter')  { e.preventDefault(); this._selectFirst(); }
        });
    }

    /* Μεταφράζει ένα label στην τρέχουσα γλώσσα — fallback στο πρωτότυπο */
    _translate(label) {
        if (currentLang === 'el') return label;
        const map = valueTranslations[this.filterKey] || {};
        return map[label] || label;
    }

    /* Χτίζει τη λίστα items με translated display labels */
    _buildItems() {
        return this.items.map(i => ({
            value:   i.value,
            label:   i.label || i.name || i.value,
            display: this._translate(i.label || i.name || i.value),
        }));
    }

    _open() {
        // Κλείνουμε πρώτα όλα τα άλλα ανοιχτά dropdowns πριν ανοίξουμε αυτό
        Object.values(ssInstances).forEach(inst => {
            if (inst !== this) inst._close();
        });
        this.wrap.classList.add('open');
        this._render(this.input.value);
    }

    /* Κλείνοντας, επαναφέρουμε το input στο επιλεγμένο label (discard partial typing) */
    _close() {
        this.wrap.classList.remove('open');
        if (this.selected.value) {
            this.input.value = this._translate(this.selected.label);
        } else {
            this.input.value = '';
        }
    }

    _render(query = '') {
        const q   = query.trim().toLowerCase();
        const all = this._buildItems();
        const t   = translations[currentLang];

        // Φιλτράρισμα: ελέγχουμε και το display (μεταφρασμένο) και το label (αρχικό)
        // ώστε να λειτουργεί η αναζήτηση και στις δύο γλώσσες
        const matched = q
            ? all.filter(i => i.display.toLowerCase().includes(q) || i.label.toLowerCase().includes(q))
            : all;
        const shown = matched.slice(0, this.TOP);

        let html = '';

        // "Όλα" option πάντα στην κορυφή
        const allLabel = t[`filter_all_${this.filterKey}`] || '—';
        const allSel   = !this.selected.value ? 'selected ss-option-all' : 'ss-option-all';
        html += `<button type="button" class="ss-option ${allSel}" data-value="" data-label="">${allLabel}</button>`;

        if (shown.length === 0) {
            html += `<div class="ss-empty">${t.ss_no_results || 'No results'}</div>`;
        } else {
            shown.forEach(i => {
                const isSel = String(i.value) === String(this.selected.value);
                html += `<button type="button" class="ss-option${isSel ? ' selected' : ''}"
                    data-value="${i.value}" data-label="${i.label}">${i.display}</button>`;
            });
            // Truncation notice — δεν κόβουμε σιωπηλά
            if (matched.length > this.TOP) {
                html += `<div class="ss-empty" style="font-style:normal;color:var(--muted);font-size:11px">
                    +${matched.length - this.TOP} ${currentLang === 'el' ? 'ακόμα — εξειδικεύστε την αναζήτηση' : 'more — refine your search'}
                </div>`;
            }
        }

        this.panel.innerHTML = html;

        this.panel.querySelectorAll('.ss-option[data-value]').forEach(btn => {
            // mousedown αντί click — αποτρέπει το blur του input πριν γίνει η επιλογή
            btn.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this._select({ value: btn.dataset.value, label: btn.dataset.label });
            });
        });
    }

    _select(item, close = true) {
        this.selected    = item;
        this.hidden.value = item.value; // Ενημέρωση hidden select — αυτό υποβάλλεται στον server
        if (item.value) {
            this.input.value = this._translate(item.label);
            this.input.classList.add('is-selected');
            this.input.parentElement.classList.add('has-value');
        } else {
            this.input.value = '';
            this.input.classList.remove('is-selected');
            this.input.parentElement.classList.remove('has-value');
        }
        if (close) this._close();
    }

    _clear() {
        this._select({ value: '', label: '' });
        this.input.focus();
        this._open();
    }

    _selectFirst() {
        const first = this.panel.querySelector('.ss-option:not(.ss-option-all)');
        if (first) first.dispatchEvent(new MouseEvent('mousedown'));
    }

    /* Καλείται κατά το lang switch — ανανεώνει το display label χωρίς να χάσει την επιλογή */
    refresh() {
        if (this.selected.value) {
            this.input.value = this._translate(this.selected.label);
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   INIT SearchableSelect instances
   Κάθε filter έχει ξεχωριστό instance — το filterKey καθορίζει
   από ποιο valueTranslations map θα μεταφράζονται τα labels.
══════════════════════════════════════════════════════════════ */
let ssInstances = {};

function initSearchableSelects() {
    ssInstances.cat = new SearchableSelect(
        'ss-cat',
        DB.categories.map(c => ({ value: c.id, label: c.name })),
        'sel-cat', 'categories'
    );
    ssInstances.pub = new SearchableSelect(
        'ss-pub',
        DB.publishers.map(p => ({ value: p.id, label: p.name })),
        'sel-pub', 'publishers' // publishers: κενό map → labels αμετάβλητα
    );
    ssInstances.type = new SearchableSelect(
        'ss-type',
        DB.types.map(t => ({ value: t, label: t })), // value === label (enum strings)
        'sel-type', 'types'
    );
    ssInstances.lang = new SearchableSelect(
        'ss-lang',
        DB.languages.map(l => ({ value: l, label: l })),
        'sel-lang', 'languages'
    );
}

/* ══════════════════════════════════════════════════════════════
   i18n — εφαρμογή μεταφράσεων σε όλα τα data-i18n elements
══════════════════════════════════════════════════════════════ */
let currentLang = localStorage.getItem('lang') || 'el'; // Persistence μέσω localStorage

const sortKeyMap = {
    'title_asc':  'sort_title_asc',
    'title_desc': 'sort_title_desc',
    'year_desc':  'sort_year_desc',
    'year_asc':   'sort_year_asc',
    'author_asc': 'sort_author_asc',
};

function applyTranslations(lang) {
    const t = translations[lang];
    if (!t) return;

    // Ενημέρωση όλων των text nodes με data-i18n
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (t[key] !== undefined) el.innerHTML = t[key];
    });
    // Ενημέρωση placeholders
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.dataset.i18nPlaceholder;
        if (t[key] !== undefined) el.placeholder = t[key];
    });

    // Μετάφραση sort options — τα values παραμένουν τα ίδια, αλλάζει μόνο το textContent
    const sortSel = document.getElementById('sortSelect');
    if (sortSel) {
        sortSel.querySelectorAll('option').forEach(opt => {
            const tKey = sortKeyMap[opt.value];
            if (tKey && t[tKey]) opt.textContent = t[tKey];
        });
    }

    document.title = t['page_title'] || document.title;
    document.documentElement.lang = lang;

    // Ενημέρωση lang switcher UI
    const isEl = lang === 'el';
    document.getElementById('langFlag').textContent  = isEl ? '🇬🇷' : '🇬🇧';
    document.getElementById('langLabel').textContent = isEl ? 'ΕΛ' : 'EN';
    document.getElementById('opt-el').classList.toggle('active', isEl);
    document.getElementById('opt-en').classList.toggle('active', !isEl);

    // Ανανέωση searchable selects και table badges
    Object.values(ssInstances).forEach(ss => ss.refresh());
    retranslateBadges();
}

function setLang(lang) {
    currentLang = lang;
    localStorage.setItem('lang', lang);
    applyTranslations(lang);
    closeLangDropdown();
}

/* ── Lang dropdown ── */
function toggleLangDropdown(e) {
    e.stopPropagation();
    document.getElementById('langSwitcher').classList.toggle('open');
}
function closeLangDropdown() {
    document.getElementById('langSwitcher').classList.remove('open');
}
document.addEventListener('click', closeLangDropdown);

/* ── Sort / per-page — reload της σελίδας με νέο param, reset στη σελίδα 1 ── */
function updateParam(key, val) {
    const url = new URL(window.location);
    url.searchParams.set(key, val);
    url.searchParams.set('page', 1);
    window.location = url.toString();
}

/* ── Universal DB-value translator ──
   Μεταφράζει raw ελληνικές τιμές βάσης στην τρέχουσα γλώσσα.
   Fallback στο πρωτότυπο αν δεν υπάρχει mapping. */
function tv(value, mapKey) {
    if (!value || currentLang === 'el') return value;
    const map = valueTranslations[mapKey] || {};
    return map[value] || value;
}

/* ── Badge re-translation ──
   Τα badges στον πίνακα κρατούν την αρχική ελληνική τιμή στο data-raw.
   Κατά το lang switch επανατυπώνουμε όλα χωρίς DOM re-render. */
function retranslateBadges() {
    document.querySelectorAll('.catalog-table [data-raw]').forEach(el => {
        el.textContent = tv(el.dataset.raw, el.dataset.map);
    });
    document.querySelectorAll('.catalog-table [data-raw-cat]').forEach(el => {
        el.textContent = tv(el.dataset.rawCat, 'categories');
    });
}

/* ── Book modal ──
   Γεμίζει το modal από τα data-* attributes του <tr>.
   Χρησιμοποιούμε closest('tr') γιατί το relatedTarget μπορεί να είναι
   child element (π.χ. το <span> του τίτλου) και όχι ο ίδιος ο <tr>. */
document.getElementById('bookModal').addEventListener('show.bs.modal', function(e) {
    const r = e.relatedTarget;
    const d = r ? r.closest('tr').dataset : r.dataset;
    document.getElementById('bmTitle').textContent = d.title;

    const t = translations[currentLang];
    const fields = [
        [t.modal_author,    d.author],
        [t.modal_type,      tv(d.type,   'types')],
        [t.modal_year,      d.year],
        [t.modal_language,  tv(d.lang,   'languages')],
        [t.modal_category,  tv(d.cat,    'categories')],
        [t.modal_publisher, d.pub],
        [t.modal_isbn,      d.isbn],
        [t.modal_pages,     d.pages],
        [t.modal_location,  d.location],
        [t.modal_status,    tv(d.status, 'statuses')],
    ];

    let html = '';
    // Παραλείπουμε πεδία χωρίς τιμή — αποφεύγουμε κενά κελιά στο 2-column grid
    fields.forEach(([label, val]) => {
        if (val) html += `<div class="col-6"><div class="modal-detail-label">${label}</div><div class="modal-detail-value">${val}</div></div>`;
    });
    document.getElementById('bmDetails').innerHTML = html;

    const desc = document.getElementById('bmDesc');
    if (d.desc) { desc.style.display = 'block'; desc.textContent = d.desc; }
    else        { desc.style.display = 'none'; }

    const coverCol = document.getElementById('bmCoverCol');
    const cover    = document.getElementById('bmCover');
    if (d.cover) {
        cover.innerHTML = `<img src="${d.cover}" style="max-width:100%;max-height:220px;border-radius:8px;border:1px solid #e5e7eb;box-shadow:0 4px 12px rgba(0,0,0,0.08)">`;
        coverCol.style.display = 'block';
        // Όταν υπάρχει εξώφυλλο, ο info column στενεύει σε col-md-9 για να χωρέσει η εικόνα
        document.getElementById('bmInfoCol').className = 'col-md-9';
    } else {
        cover.innerHTML = `<div style="width:90px;height:120px;background:#f5f3ef;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:32px;color:#d1d5db;margin:auto;border:1px solid #e8e4da"><i class="bi bi-book"></i></div>`;
        coverCol.style.display = 'block';
    }
});

/* ── Legal modals (custom, όχι Bootstrap) ── */
function openModal(id)  { document.getElementById('modal-' + id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById('modal-' + id).classList.remove('open'); document.body.style.overflow = ''; }

// Κλείσιμο με κλικ εκτός modal content
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) { this.classList.remove('open'); document.body.style.overflow = ''; }
    });
});
// Κλείσιμο με Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
        document.body.style.overflow = '';
    }
});

/* Αποτροπή Bootstrap modal trigger από click μέσα στα searchable select panels —
   χωρίς αυτό, η επιλογή option ανοίγει κατά λάθος το book modal */
document.querySelectorAll('.ss-panel, .ss-wrap').forEach(el => {
    el.addEventListener('click', e => e.stopPropagation());
});

/* ── Mobile sidebar toggle ── */
const filterToggle = document.getElementById('mobileFilterToggle');
const sidebar      = document.querySelector('.sidebar-filters');
const overlay      = document.getElementById('sidebarOverlay');

if (filterToggle && sidebar && overlay) {
    function openSidebar()  { sidebar.classList.add('active'); overlay.classList.add('active'); document.body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar.classList.remove('active'); overlay.classList.remove('active'); document.body.style.overflow = ''; }

    filterToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
    });
    overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) closeSidebar();
    });
    // Κλείσιμο sidebar μετά το submit σε mobile (πριν το reload φύγει η UX αίσθηση)
    document.getElementById('filterForm').addEventListener('submit', function() {
        if (window.innerWidth <= 768) setTimeout(closeSidebar, 100);
    });
}

/* ── BOOT — σειρά σημαντική: πρώτα τα selects, μετά τα translations ── */
initSearchableSelects();
applyTranslations(currentLang);
</script>


<!-- ══════════════════════════════════════════════════════
     LOGIN MODAL
══════════════════════════════════════════════════════ -->
<style>
.login-modal-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(8,6,2,0.65); backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.25s;
}
.login-modal-overlay.open { opacity: 1; pointer-events: all; }
.login-modal-box {
    background: #fff; border: 1px solid rgba(201,168,76,0.2);
    border-radius: 16px; padding: 44px 48px;
    max-width: 440px; width: calc(100% - 32px);
    position: relative;
    transform: translateY(24px) scale(0.97);
    transition: transform 0.28s cubic-bezier(.34,1.56,.64,1);
    box-shadow: 0 32px 64px rgba(0,0,0,0.35);
    font-family: 'Jost', sans-serif;
}
.login-modal-overlay.open .login-modal-box { transform: translateY(0) scale(1); }
.login-modal-close {
    position: absolute; top: 16px; right: 18px;
    background: none; border: none; color: #6c757d; font-size: 20px; cursor: pointer;
    width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
    border-radius: 50%; transition: all 0.2s;
}
.login-modal-close:hover { color: #212529; background: rgba(0,0,0,0.06); }
.lm-step-tag {
    font-size: 11px; letter-spacing: 2.5px; text-transform: uppercase;
    color: #c9a84c; display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
}
.lm-step-dot { width: 6px; height: 6px; border-radius: 50%; background: #c9a84c; animation: lmblink 1.8s ease-in-out infinite; }
@keyframes lmblink { 0%,100%{opacity:1} 50%{opacity:0.3} }
.lm-title { font-family: 'Cormorant Garamond', serif; font-size: 34px; font-weight: 400; color: #212529; margin-bottom: 6px; }
.lm-subtitle { font-size: 15px; color: #6c757d; line-height: 1.6; margin-bottom: 28px; }
.lm-error {
    display: flex; align-items: center; gap: 10px;
    background: rgba(220,53,69,0.08); border: 1px solid rgba(220,53,69,0.2);
    border-radius: 8px; padding: 12px 16px; margin-bottom: 20px;
    font-size: 14px; color: #b02a37;
}
.lm-field { margin-bottom: 20px; }
.lm-label { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: #6c757d; font-weight: 500; display: block; margin-bottom: 8px; }
.lm-input-wrap { position: relative; }
.lm-input-wrap .lm-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: rgba(0,0,0,0.35); font-size: 14px; pointer-events: none; transition: color 0.2s; }
.lm-input-wrap input { width: 100%; background: #eef0f3; border: 1px solid #d0d5dc; border-radius: 8px; padding: 13px 14px 13px 40px; font-family: 'Jost', sans-serif; font-size: 15px; color: #111; outline: none; transition: all 0.25s; }
.lm-input-wrap input::placeholder { color: rgba(0,0,0,0.35); }
.lm-input-wrap input:focus { background: #fff; border-color: #c9a84c; box-shadow: 0 0 0 3px rgba(201,168,76,0.15); }
.lm-input-wrap:focus-within .lm-icon { color: #c9a84c; }
.lm-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6c757d; cursor: pointer; font-size: 14px; transition: color 0.2s; }
.lm-toggle:hover { color: #c9a84c; }
.lm-forgot { display: flex; justify-content: flex-end; margin-top: -6px; margin-bottom: 22px; }
.lm-forgot a { font-size: 13px; color: #6c757d; text-decoration: none; transition: color 0.2s; }
.lm-forgot a:hover { color: #c9a84c; }
.lm-submit { width: 100%; background: linear-gradient(135deg, #c9a84c 0%, #b8922a 100%); color: #fff; border: none; border-radius: 8px; padding: 15px; font-family: 'Jost', sans-serif; font-size: 15px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.25s; }
.lm-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(201,168,76,0.3); }
</style>

<div class="login-modal-overlay" id="loginModal">
    <div class="login-modal-box">
        <button class="login-modal-close" onclick="closeLoginModal()"><i class="bi bi-x-lg"></i></button>

        <div class="lm-step-tag"><div class="lm-step-dot"></div> Περιορισμένη Πρόσβαση</div>
        <div class="lm-title">Καλωσορίσατε</div>
        <div class="lm-subtitle">Συνδεθείτε στο σύστημα διαχείρισης της βιβλιοθήκης.</div>

        <?php if ($loginError): ?>
        <div class="lm-error">
            <i class="bi bi-exclamation-circle"></i>
            <?= h($loginError) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="lmForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="login">

            <div class="lm-field">
                <label class="lm-label"><i class="bi bi-person" style="margin-right:5px;color:#c9a84c"></i>Όνομα Χρήστη</label>
                <div class="lm-input-wrap">
                    <input type="text" name="username" placeholder="Εισάγετε το όνομα χρήστη σας"
                           value="<?= h($_POST['username'] ?? '') ?>"
                           required autocomplete="username" spellcheck="false">
                    <i class="bi bi-person lm-icon"></i>
                </div>
            </div>

            <div class="lm-field">
                <label class="lm-label"><i class="bi bi-key" style="margin-right:5px;color:#c9a84c"></i>Κωδικός Πρόσβασης</label>
                <div class="lm-input-wrap">
                    <input type="password" id="lmPassword" name="password" placeholder="Εισάγετε τον κωδικό πρόσβασής σας"
                           required autocomplete="current-password">
                    <i class="bi bi-lock lm-icon"></i>
                    <button type="button" class="lm-toggle" onclick="lmTogglePass()" title="Εμφάνιση/Απόκρυψη">
                        <i class="bi bi-eye" id="lmEyeIcon"></i>
                    </button>
                </div>
            </div>

            <div class="lm-forgot">
                <a href="#" onclick="closeLoginModal(); openCustomModal('forgotModal'); return false;">Ξεχάσατε τον κωδικό σας;</a>
            </div>

            <button type="submit" class="lm-submit" id="lmSubmitBtn">
                <i class="bi bi-box-arrow-in-right"></i>
                <span>Σύνδεση</span>
            </button>
        </form>
    </div>
</div>

<script>
function openLoginModal() {
    document.getElementById('loginModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.querySelector('#loginModal input[name="username"]')?.focus(), 300);
}
function closeLoginModal() {
    document.getElementById('loginModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('loginModal').addEventListener('click', function(e) {
    if (e.target === this) closeLoginModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLoginModal();
});
document.getElementById('lmForm').addEventListener('submit', function() {
    const btn = document.getElementById('lmSubmitBtn');
    btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite"></i><span>Σύνδεση...</span>';
    btn.disabled = true;
});
function lmTogglePass() {
    const inp = document.getElementById('lmPassword');
    const ico = document.getElementById('lmEyeIcon');
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'bi bi-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'bi bi-eye'; }
}
<?php if ($loginError): ?>
window.addEventListener('DOMContentLoaded', () => openLoginModal());
<?php endif; ?>
</script>
<!-- FORGOT PASSWORD MODAL -->
<div class="custom-modal-overlay" id="forgotModal" style="position:fixed;inset:0;z-index:10000;background:rgba(255,255,255,0.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity 0.25s;">
    <div class="custom-modal" style="background:#fff;border:1px solid rgba(201,168,76,0.2);border-radius:14px;padding:36px 40px;max-width:480px;width:90%;position:relative;transform:translateY(20px) scale(0.97);transition:transform 0.25s;box-shadow:0 20px 40px rgba(0,0,0,0.12);">
        <button onclick="closeCustomModal('forgotModal')" style="position:absolute;top:16px;right:18px;background:none;border:none;color:#6c757d;font-size:20px;cursor:pointer;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%"><i class="bi bi-x-lg"></i></button>
        <div style="font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:400;color:#212529;margin-bottom:8px">Ανάκτηση Κωδικού</div>
        <div style="font-size:14px;color:#6c757d;line-height:1.6;margin-bottom:20px">Συμπληρώστε το username σας και ο admin θα λάβει σχετικό αίτημα.</div>
        <input type="text"  id="forgotUser"  placeholder="Username ή ονοματεπώνυμο..." style="width:100%;background:rgba(0,0,0,0.02);border:1px solid rgba(201,168,76,0.2);border-radius:8px;padding:13px 15px;font-family:'Jost',sans-serif;font-size:14px;color:#212529;outline:none;margin-bottom:14px;transition:all 0.25s">
        <input type="email" id="forgotEmail" placeholder="Email επικοινωνίας (προαιρετικό)..." style="width:100%;background:rgba(0,0,0,0.02);border:1px solid rgba(201,168,76,0.2);border-radius:8px;padding:13px 15px;font-family:'Jost',sans-serif;font-size:14px;color:#212529;outline:none;margin-bottom:16px;transition:all 0.25s">
        <button onclick="submitForgot()" id="forgotSubmitBtn" style="width:100%;background:linear-gradient(135deg,#c9a84c,#b8922a);color:#fff;border:none;border-radius:8px;padding:13px;font-family:'Jost',sans-serif;font-size:13px;font-weight:600;letter-spacing:1px;text-transform:uppercase;cursor:pointer">
            <i class="bi bi-send me-2"></i>Αποστολή Αιτήματος
        </button>
        <div style="font-size:12px;color:#6c757d;text-align:center;margin-top:12px">Ή επικοινωνήστε στο <a href="mailto:contribute@opengplms.org" style="color:#c9a84c">contribute@opengplms.org</a></div>
    </div>
</div>

<script>
function openCustomModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.opacity = '0'; el.style.pointerEvents = 'all';
    el.querySelector('.custom-modal') && (el.querySelector('.custom-modal').style.transform = 'translateY(0) scale(1)');
    requestAnimationFrame(() => { el.style.opacity = '1'; });
    document.body.style.overflow = 'hidden';
}
function closeCustomModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.opacity = '0'; el.style.pointerEvents = 'none';
    document.body.style.overflow = '';
}
document.getElementById('forgotModal').addEventListener('click', function(e) {
    if (e.target === this) closeCustomModal('forgotModal');
});
function submitForgot() {
    const user = document.getElementById('forgotUser').value.trim();
    const email = document.getElementById('forgotEmail').value.trim();
    if (!user) { document.getElementById('forgotUser').focus(); return; }
    const btn = document.getElementById('forgotSubmitBtn');
    btn.innerHTML = '<i class="bi bi-arrow-repeat me-2" style="animation:spin 1s linear infinite"></i>Αποστολή...';
    btn.disabled = true;
    fetch('forgot_message.php', {
        method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'username=' + encodeURIComponent(user) + '&email=' + encodeURIComponent(email)
    }).then(r=>r.json()).then(data => {
        if (data.ok) {
            btn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Αίτημα Εστάλη!';
            btn.style.background = 'linear-gradient(135deg,#22c55e,#16a34a)';
            setTimeout(() => { closeCustomModal('forgotModal'); btn.innerHTML='<i class="bi bi-send me-2"></i>Αποστολή Αιτήματος'; btn.style.background=''; btn.disabled=false; document.getElementById('forgotUser').value=''; document.getElementById('forgotEmail').value=''; }, 2200);
        } else {
            btn.innerHTML = '<i class="bi bi-exclamation-circle me-2"></i>Σφάλμα';
            btn.style.background = 'linear-gradient(135deg,#ef4444,#dc2626)'; btn.disabled=false;
            setTimeout(() => { btn.innerHTML='<i class="bi bi-send me-2"></i>Αποστολή Αιτήματος'; btn.style.background=''; }, 3000);
        }
    });
}
</script>
</body>
</html>