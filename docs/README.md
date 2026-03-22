
# openGPLMS Documentation

> **General Purpose Library Management System** — A self-hosted, web-based library management system built with PHP and MySQL.

## Documentation Index

| Section | Description |
|---|---|
| [Installation Guide](guides/installation.md) | Full setup walkthrough, requirements, and configuration |
| [User Guide](guides/user-guide.md) | How to use the system by role (Admin, Employee, User) |
| [Security Guide](guides/security.md) | Security architecture, hardening, and best practices |
| [CSV Import & Editor](guides/csv-import.md) | Bulk import workflow, template format, and online editor |
| [Database Schema](reference/database-schema.md) | Full schema reference, tables, foreign keys |
| [File & Module Reference](reference/file-reference.md) | Every PHP file: purpose, access level, key logic |
| [Configuration Reference](reference/configuration.md) | All `config.php` constants and session settings |
| [Role & Permission Model](reference/permissions.md) | Role matrix and authorization logic |
| [API / AJAX Endpoints](api/endpoints.md) | AJAX-style endpoints and form POST actions |
| [Contributing](development/contributing.md) | Code style, PR process, project conventions |
| [Architecture Overview](development/architecture.md) | Tech stack, design patterns, directory layout |
| [Changelog & Roadmap](development/roadmap.md) | Known issues and planned features |

---

## Quick Start

```
1. Copy repo into your web root (e.g. htdocs/e-library/)
2. Edit src/config.php with DB credentials
3. Visit install.php in the browser
4. Delete install.php immediately after
5. Log in at index.php  (admin / gplmsadm123)
6. Change default passwords immediately
```

See the full [Installation Guide](guides/installation.md) for details.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.0+ (PDO, prepared statements) |
| Database | MySQL 5.7+ / MariaDB 10.3+ (utf8mb4) |
| Frontend | HTML, CSS, Bootstrap 5, Bootstrap Icons |
| Charts | Chart.js (CDN) |
| Dev server | XAMPP or any Apache + PHP + MySQL stack |

---

## License

MIT License — see [LICENSE](../LICENSE) for details.
