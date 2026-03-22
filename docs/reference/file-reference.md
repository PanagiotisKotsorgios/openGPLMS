# File & Module Reference

Every PHP file in `src/`, its access level, and a summary of its key logic.

---

## Core Infrastructure

### `config.php`
**Access:** Included by all pages (no direct URL access needed)

The single configuration and shared-function file. Contains:
- DB connection constants (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `TABLE_PREFIX`)
- `getDB()` — static singleton PDO connection; provides detailed error pages for common MySQL error codes (1045 = wrong credentials, 1049 = missing database → redirects to `install.php`)
- Session security settings (httponly, strict mode, samesite)
- `isLoggedIn()`, `isAdmin()`, `isEmployee()` — role check helpers
- `requireLogin()`, `requireEmployee()`, `requireAdmin()` — page guards with redirects
- `flash($msg, $type)` / `getFlash()` — one-time session flash messages
- `h($s)` — `htmlspecialchars()` wrapper (ENT_QUOTES, UTF-8)
- `csrfToken()`, `csrfField()`, `verifyCsrf()` — CSRF protection
- `loginCheckRateLimit()`, `loginRecordFailure()`, `loginClearAttempts()`, `loginRemainingSeconds()` — IP-based rate limiting
- `sanitizeCoverUrl()` — validates http/https URLs, rejects all other schemes

### `layout_admin.php`
**Access:** Included by all admin/employee pages (not directly accessed)

Outputs the full HTML `<head>`, loads all CSS and Bootstrap JS, renders the sidebar navigation with role-conditional links, shows the flash message if present, and opens `<div id="main">`.

Sidebar link highlighting uses `$activePage` variable set by each page before the include.

Unread message count badge is fetched from DB on every page load (lightweight indexed query).

### `layout_admin_end.php`
**Access:** Included at the end of all admin/employee pages

Closes `<div id="main">`, outputs `$extraJs` if set, closes `</body>` and `</html>`.

---

## Public Pages

### `index.php`
**Access:** Public (redirects logged-in users to dashboard)

Landing page with a full-screen background, brand section, public catalog CTA, and staff login modal. Handles the login POST action (rate-limited, CSRF-protected, session-hardened).

### `catalog_public.php`
**Access:** Public (no authentication required)

Read-only catalog for public visitors. Features:
- Filter sidebar: search, category, language, type, publisher (all using SearchableSelect custom dropdowns)
- Sort: title A-Z/Z-A, year newest/oldest, author A-Z
- Pagination: sliding ±2 window
- Detail modal: populated from `data-*` attributes on each `<tr>` (no AJAX)
- Bilingual UI (Greek / English) via a full client-side i18n system with `localStorage` persistence
- Login modal for staff, including forgot-password flow via `forgot_message.php`

### `forgot_message.php`
**Access:** Public AJAX endpoint (POST only)

Accepts `username` (required) and `email` (optional). Sends a message to the first active admin's inbox. Returns JSON `{"ok": true}` always (uniform response to prevent username enumeration). Logs the request in the audit log.

---

## Authentication

### `logout.php`
**Access:** Any logged-in user

Logs the logout action in the audit log, calls `session_destroy()`, redirects to `index.php`.

---

## Admin / Employee Pages

### `dashboard.php`
**Access:** Employee+

Shows summary stat cards and two Chart.js charts (doughnut for type distribution, bar for monthly additions). Lists the 5 most recently added items.

### `catalog.php`
**Access:** Employee+

The main staff catalog. Key features:
- Filters: search, type, category, language, year range, per-page
- SearchableSelect dropdowns for type, category, language
- Admin checkbox column with mass action bar (mass delete, mass status change, mass visibility toggle)
- Per-row actions: view, edit (or request), delete (or request)
- Post-Redirect-Get pattern for all POST actions
- CSV export (respects current filters)

POST actions handled inline (before layout):
- `delete_book` — single item deletion with ownership check
- `mass_delete` — admin-only bulk delete
- `mass_status` — admin-only bulk status change
- `mass_visibility` — admin-only bulk visibility change
- `request_permission` — sends message to all active admins

### `add_book.php`
**Access:** Employee+

Dual-mode form for creating and editing books. Detects edit mode via `?id=` URL parameter.

Notable implementation:
- Item type ENUM values are read from MySQL column definition at runtime (`SHOW COLUMNS ... LIKE 'type'`) rather than being hardcoded
- Three searchable dropdowns (type, category, publisher) use the `SearchableSelect` JavaScript class
- Live cover image preview updates as the URL is typed
- Writes to `{prefix}audit_log` on create (`action='create'`) and edit (`action='edit'`)

### `edit_book.php`
**Access:** Employee+

A redirect proxy. Forwards `?id=X` to `add_book.php?id=X`. If `?id` is missing, redirects to `catalog.php`. The integer cast `(int)$_GET['id']` prevents injection via the URL.

### `book.php`
**Access:** Employee+

Single-item detail view. Shows all metadata, cover image, and action buttons.

Authorization logic:
- Admin: can edit and delete directly
- Employee who created the item: can edit directly; can request deletion (sends message to admin)
- Employee who did not create the item: can request both edit and delete permission

Handles the `request_permission` POST action (sends message to the first active admin).

### `categories.php`
**Access:** Admin only

Two-panel layout: categories (left) and publishers (right). Both panels have independent search, per-page, and pagination controls with prefixed GET parameters (`c` for categories, `p` for publishers) to avoid conflicts.

Each panel preserves the other panel's filter state via hidden inputs on form submit.

Deletion uses browser `confirm()` dialog; the underlying FK behavior (ON DELETE SET NULL) means books are never accidentally deleted.

### `users.php`
**Access:** Admin only

Full user management. The main query uses subqueries to fetch `book_count`, `msg_count`, and `last_activity` for each user in a single roundtrip.

Row-level action buttons open modals via JavaScript, passing `uid` and `username` as arguments. The modal's hidden input fields are populated by JS before each open.

Admin self-protection: the current admin cannot deactivate, change the role of, or delete themselves.

Password generator: 12-character random string from a curated alphabet that excludes visually ambiguous characters (`l`, `1`, `O`, `0`).

### `messages.php`
**Access:** Employee+

Three-pane layout: tabs + search (left column), message list (left column, scrollable), read pane (right column).

The list query dynamically switches its JOIN direction (`from_user` / `to_user`) based on the active tab (`inbox` / `sent`). Both queries return `other_name` as the alias, which the template uses uniformly.

The read pane shows sender/recipient metadata and a quick-reply form (only visible if the current user is the recipient).

A custom overlay modal (not Bootstrap) handles composition to avoid conflicts with Bootstrap modals used elsewhere.

### `reports.php`
**Access:** Employee+

Four Chart.js charts and a paginated, searchable audit log table. The audit log uses the `pageWindow()` helper, `auditQs()` for URL building, and separate `auditActionLabel()` / `auditTargetLabel()` translation functions.

Admins can clear the entire audit log (with a JS `confirm()` guard). The clear action is itself logged immediately after.

CSV export of the full catalog (not filtered) runs before the layout include and outputs directly to `php://output`.

### `audit.php`
**Access:** Admin only

Full audit log with filters (action type, free-text search), per-page control, and paginated results. CSV export of the complete unfiltered log.

The backup feature (POST `action=backup`) is present in the code but its UI button shows a warning modal stating it is non-functional in the current version.

### `csv_import.php`
**Access:** Employee+

Handles file upload, parsing, validation, and insertion of CSV data. See the [CSV Import Guide](../guides/csv-import.md) for full details.

### `csv_editor.php`
**Access:** Employee+

Browser-based spreadsheet editor. Pure JavaScript with no external dependencies. Data flows from PHP (via JSON) into JavaScript for autocomplete lists. See the [CSV Import Guide](../guides/csv-import.md) for full details.

### `profile.php`
**Access:** Employee+

Change own password (requires current password verification). Shows last 10 audit log entries for the current user.

### `help.php`
**Access:** Employee+

Static FAQ page. Questions and answers are defined as a PHP array in the file; no database involvement.

---

## Installer Files

### `install.php`
**Access:** Public (DELETE AFTER USE)

Creates the database, all tables, and seed data. Shows friendly error output. Uses `SET FOREIGN_KEY_CHECKS = 0/1` to allow DROP/CREATE in any order.

### `removit/install.php`
**Access:** Public (DELETE AFTER USE)

Equivalent to `install.php` — drops everything and recreates. Intended for development resets only.

---

## JavaScript Utilities (Inline in PHP Files)

### `SearchableSelect` class

Implemented inline in `add_book.php`, `catalog.php`, and `catalog_public.php`. A custom searchable dropdown that:
- Hides the native `<select>` and renders a visible text input with a floating panel
- Filters the list in real-time as the user types (max 10 results shown)
- Keeps the hidden `<select>` in sync for form submission
- Supports keyboard navigation (Enter to select first result, Escape to close)
- `catalog_public.php` version adds bilingual translation support via `valueTranslations` maps

### `pageWindow(int $current, int $total, int $radius = 2): array`

A PHP helper used in `audit.php`, `catalog.php`, `categories.php`, and `reports.php`. Returns a sorted array of page numbers with `'…'` string placeholders for gaps. Example output for page 5 of 20: `[1, '…', 3, 4, 5, 6, 7, '…', 20]`.

### `qsKeep(array $override = []): string`

Used in `audit.php` and `categories.php`. Rebuilds the current GET query string while overriding specific keys. Passing `null` as a value removes the key entirely. Used for pagination and filter links that preserve other active filters.
