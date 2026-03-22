# Contributing Guide

Thank you for your interest in contributing to openGPLMS. This document covers the development workflow, code conventions, and how to submit changes.

Also see the project's root-level [CONTRIBUTING.md](../../CONTRIBUTING.md) for the official contribution policy.

---

## Getting Started

1. Fork the repository on GitHub
2. Clone your fork locally
3. Set up a local XAMPP (or equivalent) environment
4. Follow the [Installation Guide](../guides/installation.md) to get the app running
5. Make your changes on a new branch
6. Test manually and submit a pull request

---

## Branch Naming

| Type | Format | Example |
|---|---|---|
| Bug fix | `fix/short-description` | `fix/csv-checkbox-export` |
| Feature | `feat/short-description` | `feat/isbn-barcode-scan` |
| Documentation | `docs/short-description` | `docs/api-endpoints` |
| Refactor | `refactor/short-description` | `refactor/extract-pagination` |

---

## Code Style

### PHP

- Use 4-space indentation
- Use `snake_case` for variable names and function names
- Use `camelCase` for local variables only where widely adopted in the surrounding code
- Prepared statements for all DB queries — **no raw interpolation of user input into SQL**
- Always call `verifyCsrf()` at the top of POST handlers
- Always call `requireEmployee()` or `requireAdmin()` before business logic
- Always use `h()` when outputting user-controlled values to HTML
- Use `(int)` cast for URL-parameter IDs before use
- End every POST handler with `header('Location: ...')` + `exit` (PRG pattern)
- Set `$pageTitle` and `$activePage` before the `include 'layout_admin.php'` call

### JavaScript

- All JS is written in vanilla ES6+ (no framework, no transpiler)
- Use `const`/`let`, arrow functions, template literals
- Expose functions to `window` scope where necessary for inline `onclick` handlers
- Never rely on `==` for comparison — use `===`
- Comment non-obvious logic, especially the `mousedown` vs `click` choices in SearchableSelect

### HTML / CSS

- Keep page-specific CSS in the appropriate file under `assets/styles/`
- Don't add new global CSS files unless creating a new page that warrants one
- Avoid inline `style=` attributes for anything that could be a reusable class
- Use CSS variables defined in `layout_admin.css` for colors (`--gold`, `--border`, `--panel`, etc.)

---

## Adding a New Page

1. Create `src/new_page.php`
2. Start with:
   ```php
   <?php
   require 'config.php';
   requireEmployee(); // or requireAdmin()
   $db = getDB();
   // ... POST handlers if needed ...
   $pageTitle  = 'Page Title';
   $activePage = 'nav_key';
   include 'layout_admin.php';
   ?>
   ```
3. Add a sidebar link in `layout_admin.php` (inside the appropriate role block)
4. Add a CSS file to `assets/styles/` if needed and include it in `layout_admin.php`
5. Close with `<?php include 'layout_admin_end.php'; ?>`

---

## Adding a New Item Type

Item types are stored as a MySQL ENUM on `{prefix}books.type`. To add a new type:

1. Run an `ALTER TABLE` on your development database:
   ```sql
   ALTER TABLE lib_books MODIFY type ENUM(
     'Βιβλίο','Περιοδικό','Εφημερίδα','Χειρόγραφο',
     'Ημερολόγιο','Επιστολή','Άλλο','Νέος Τύπος'
   );
   ```
2. Update `install.php` to include the new value in the `CREATE TABLE` statement
3. The UI will pick up the new type automatically — `add_book.php`, `catalog.php`, and the public catalog all read the ENUM at runtime

---

## Adding a New Audit Action

1. Write the INSERT in the relevant PHP file:
   ```php
   $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
      ->execute([$_SESSION['user_id'], 'new_action', 'book', $id, 'Some detail']);
   ```
2. Add the slug to the `$map` arrays in `audit.php` (`actionLabel()`) and `reports.php` (`auditActionLabel()`):
   ```php
   'new_action' => 'Νέα Ενέργεια',
   ```

---

## Testing

There is no automated test suite currently. Testing is manual:

- Test each affected page with all three roles (admin, employee, logged-out)
- Test the POST action you changed with both valid and invalid input
- Test CSRF protection by omitting or altering the token in the request
- Test pagination and filtering with edge cases (0 results, page > total pages)
- Test the public catalog in both languages

---

## Submitting a Pull Request

- Keep PRs focused: one feature or bug fix per PR
- Write a clear description of what the change does and why
- Reference any relevant issue numbers
- Do not include changes to `src/config.php` credentials (use placeholder values)
- Do not commit `install.php` with real credentials
