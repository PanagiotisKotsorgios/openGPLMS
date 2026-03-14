# Contributing to openGPLMS

Thank you for your interest in contributing. The following guidelines help keep the process straightforward for everyone.

---

## Getting Started

1. **Fork** the repository and clone your fork locally.
2. Set up a local environment (XAMPP or equivalent: Apache + PHP 8.0+ + MySQL/MariaDB).
3. Copy the project into your web root, configure `config.php`, and run `install.php` to initialise the database.
4. Create a new branch for your work:
   ```
   git checkout -b feature/your-feature-name
   ```

---

## What to Work On

- Bug fixes
- Security improvements
- Performance improvements to existing queries or logic
- UI/UX improvements to existing pages
- Documentation corrections

If you want to add a significant new feature, please **open an issue first** to discuss it before writing code. This avoids duplicated effort and ensures the change aligns with the project's direction.

---

## Code Style

- PHP files use **PDO with prepared statements** for all database access — no raw string interpolation with user input.
- Every POST handler must call `verifyCsrf()` before processing any data.
- Role checks (`requireAdmin()`, `requireEmployee()`) must be present at the top of every page that requires authentication.
- Keep logic in PHP and presentation in the HTML/CSS sections of each file, consistent with the existing structure.
- Greek-language strings in the UI are intentional — the system targets Greek-language deployments.
- Follow the existing naming conventions for variables, functions, and database columns.

---

## Pull Request Process

1. Make sure your branch is up to date with `main` before opening a PR.
2. Describe clearly **what** the PR changes and **why**.
3. If the change affects database structure, update `install.php` (and `removit/install.php`) accordingly and document the change in the PR description.
4. Do not commit credentials, passwords, or local configuration values. `config.php` should always contain only placeholder values.
5. Test your changes locally with both the `admin` and `employee` roles before submitting.

---

## Reporting Bugs

Open a GitHub Issue and include:

- A clear description of the problem
- Steps to reproduce it
- PHP version and database version
- Any relevant error messages or browser console output

For security vulnerabilities, please follow the process in [SECURITY.md](SECURITY.md) instead of opening a public issue.

---

## Code of Conduct

Be respectful and constructive in all interactions. Contributions of all experience levels are welcome.
