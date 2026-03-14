# The openGPLMS Software

> A self-hosted, web-based library management system built with PHP and MySQL. Manage your collection, control access by role, and expose a public catalog — all from a single lightweight install.

<img src="https://github.com/PanagiotisKotsorgios/openGPLMS/blob/main/assets/opengplms_logo.png">

---

## Features

- **Role-based access control** — three roles: `admin`, `employee`, and `user`
- **Book catalog** — add, edit, delete, and search items with full metadata (title, author, ISBN, type, category, publisher, year, language, pages, edition, volume, location, cover URL, status)
- **Item types** — Book, Magazine, Newspaper, Manuscript, Journal, Letter, Other
- **Public catalog** — a read-only, unauthenticated view (`catalog_public.php`) with filtering, sorting, and pagination
- **CSV import** — bulk-import books from CSV with auto-creation of missing categories and publishers; downloadable template included
- **Online CSV editor** — edit CSV data directly in the browser before importing
- **Categories & publishers management** — admin-only CRUD for categories and publishers
- **User management** — admin-only: create, activate/deactivate, change roles, reset passwords, delete users
- **Internal messaging** — employees can send messages to each other and to admins; used also for permission requests
- **Permission requests** — employees can request edit/delete access on books they did not create
- **Audit log** — every significant action (login, logout, create, edit, delete, CSV import, role change, etc.) is recorded with user, action, target, and timestamp
- **Reports & statistics** — charts for item type distribution, categories, languages, yearly breakdown, and monthly additions (Chart.js)
- **Dashboard** — summary cards and charts giving an at-a-glance overview of the collection
- **Profile page** — any logged-in user can change their own password
- **Help & FAQ** — built-in help page for staff
- **One-file installer** — `install.php` creates the full database schema and seeds default data; `removit/install.php` drops and recreates everything for a clean reinstall

---

## Tech Stack

| Layer      | Technology                        |
|------------|-----------------------------------|
| Backend    | PHP (PDO, prepared statements)    |
| Database   | MySQL / MariaDB (utf8mb4)         |
| Frontend   | HTML, CSS, Bootstrap Icons        |
| Charts     | Chart.js                          |
| Dev server | XAMPP (or any Apache + PHP + MySQL stack) |

---

## Security

- CSRF protection on every POST form (`csrfToken()` / `verifyCsrf()`)
- Passwords hashed with `password_hash()` (bcrypt)
- Login rate limiting — 5 failed attempts trigger a 15-minute lockout (session-based, no extra table needed)
- Session hardening — `httponly`, `samesite=Lax`, `use_strict_mode` (uncomment `cookie_secure` for HTTPS)
- `cover_url` sanitisation — only `http://` and `https://` URLs accepted; `javascript:` and `data:` schemes are rejected
- Role checks on every page — `requireAdmin()` / `requireEmployee()` / `requireLogin()`
- Authorization check on edit/delete — employees can only modify records they created
- All user input goes through prepared statements — no raw query interpolation

---

## Installation

### Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache (mod_rewrite not required)

### Steps

1. **Clone or download** this repository into your web root (e.g. `htdocs/e-library/`).

2. **Edit `config.php`** with your database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_db_password');
   define('DB_NAME', 'your_db_name');
   define('TABLE_PREFIX', 'lib_');
   ```

3. **Run the installer** by visiting `install.php` in your browser:
   ```
   http://localhost/e-library/install.php
   ```
   This creates all tables and inserts seed data (3 categories, 3 publishers, 5 sample books).

4. **Delete `install.php`** immediately after a successful install. Leaving it on the server allows anyone to wipe and recreate the database.

5. **Log in** at `index.php` with the default credentials:

   | Username   | Password       | Role     |
   |------------|----------------|----------|
   | `admin`    | `gplmsadm123`  | Admin    |
   | `employee` | `gplmslib123`  | Employee |

   **Change these passwords immediately** after first login.

### Clean reinstall

If you need to drop everything and start fresh, run `removit/install.php` instead. Delete it afterwards as well.

---

## Database Schema

| Table             | Description                                      |
|-------------------|--------------------------------------------------|
| `{prefix}users`      | User accounts with role and active flag          |
| `{prefix}books`      | The main collection; all item types stored here  |
| `{prefix}categories` | Book categories (FK from books)                  |
| `{prefix}publishers` | Publishers (FK from books)                       |
| `{prefix}messages`   | Internal messaging between users                 |
| `{prefix}audit_log`  | Immutable action log                             |

Foreign key behaviour:
- Deleting a category or publisher sets `category_id` / `publisher_id` to `NULL` on linked books (no data loss).
- Deleting a user cascades to their received messages but sets `from_user` to `NULL` on sent messages.

---

## License

This project is released under the **MIT License**. See [LICENSE](LICENSE) for details.

---

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Security

If you discover a security vulnerability, please follow the responsible disclosure process described in [SECURITY.md](SECURITY.md).
