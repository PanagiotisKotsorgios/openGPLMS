# Configuration Reference

All configuration lives in `src/config.php`. This file is included by every page and must be present.

---

## Database Constants

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');
define('TABLE_PREFIX', 'lib_');
```

| Constant | Description |
|---|---|
| `DB_HOST` | MySQL host. Typically `localhost` for local installs; use an IP or hostname for remote DB |
| `DB_USER` | MySQL username |
| `DB_PASS` | MySQL password |
| `DB_NAME` | Database name. Created by `install.php` if it does not exist |
| `TABLE_PREFIX` | Prefix for all table names. Allows sharing one MySQL database with other applications. Set to `''` (empty string) for no prefix |

---

## Session Security Settings

These `ini_set()` calls are made before `session_start()`:

```php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
// ini_set('session.cookie_secure', 1); // Uncomment for HTTPS
```

| Setting | Value | Purpose |
|---|---|---|
| `cookie_httponly` | `1` | Prevents JavaScript from reading the session cookie (mitigates XSS cookie theft) |
| `use_strict_mode` | `1` | Refuses session IDs that weren't issued by the server (prevents session fixation) |
| `cookie_samesite` | `'Lax'` | Limits cross-site cookie sending; blocks most CSRF via navigation |
| `cookie_secure` | `1` | Sends the cookie over HTTPS only; **uncomment this on production HTTPS servers** |

---

## Rate Limiting Constants

```php
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 15 * 60); // 900 seconds = 15 minutes
```

| Constant | Default | Description |
|---|---|---|
| `LOGIN_MAX_ATTEMPTS` | `5` | Number of failed login attempts before lockout triggers |
| `LOGIN_LOCKOUT_SECONDS` | `900` | Lockout duration in seconds. Reset to `0` by `loginClearAttempts()` on success |

These are defined as constants for readability. To change the policy, edit these two values in `config.php`.

---

## Shared Functions Reference

All functions are defined in `config.php` and available globally to every included page.

### Database

```php
getDB(): PDO
```
Returns a static singleton PDO connection. Connects lazily on first call. Error mode is `ERRMODE_EXCEPTION`. Default fetch mode is `FETCH_ASSOC`.

On connection failure:
- Error code `1045` (access denied): shows a styled HTML page with three fix options
- Error code `1049` (unknown database): redirects to `install.php`
- Any other error: shows a generic error page

---

### Role Checks

```php
isLoggedIn(): bool     // $_SESSION['user_id'] is set
isAdmin(): bool        // role === 'admin'
isEmployee(): bool     // role is 'admin' or 'employee'
```

---

### Page Guards

```php
requireLogin(): void     // Redirects to index.php if not logged in
requireEmployee(): void  // Redirects to index.php if not employee or admin
requireAdmin(): void     // Redirects to dashboard.php if not admin
```

All three call `exit` after the redirect header.

---

### Flash Messages

```php
flash(string $msg, string $type = 'success'): void
getFlash(): ?array  // Returns ['msg' => ..., 'type' => ...] or null
```

`$type` is either `'success'` (renders as Bootstrap `alert-success`) or `'error'` (renders as `alert-danger`). `getFlash()` consumes the flash, clearing it from the session.

---

### Output Escaping

```php
h(string $s): string
```
Calls `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`. Use on every user-supplied value output to HTML.

---

### CSRF Protection

```php
csrfToken(): string     // Returns (or generates) the session CSRF token
csrfField(): string     // Returns a complete <input type="hidden"> element
verifyCsrf(): void      // Validates $_POST['csrf_token']; dies with 403 on failure
```

Token is generated with `bin2hex(random_bytes(32))` (64 hex characters). Comparison uses `hash_equals()` for constant-time evaluation.

---

### Login Rate Limiting

```php
loginCheckRateLimit(string $ip): bool       // false = currently locked out
loginRecordFailure(string $ip): void        // Increments counter; triggers lockout at threshold
loginClearAttempts(string $ip): void        // Clears counter on success
loginRemainingSeconds(string $ip): int      // Seconds until lockout expires
```

State is stored in `$_SESSION['login_attempts_' . md5($ip)]` as an array with `count`, `first_at`, and `locked_until` keys.

---

### URL Sanitization

```php
sanitizeCoverUrl(string $url): string
```

Returns the URL unchanged if it passes both tests:
1. Matches `#^https?://#i` (must be absolute http/https)
2. Passes `filter_var($url, FILTER_VALIDATE_URL)`

Returns empty string for anything else (including `javascript:`, `data:`, relative paths, blank input).
