# Installation and operation

Everything needed to run, update and test the system. For what the system does, see the [README](../README.md) and `docs/SYSTEM_FEATURES_EXPLAINED.txt`.

## Requirements

- XAMPP (Apache + MariaDB 10.4+) with PHP 8.2 and the `openssl` and `pdo_mysql` extensions
- `mod_rewrite` and `.htaccess` overrides enabled (the XAMPP default); the access rules depend on them
- Node.js 18+ is optional: only needed to rebuild `assets/css/app.css` (see Styles) and for the browser-based checks

## Setup

1. Put the project in `C:\xampp\htdocs\Websites\test` (or any folder under `htdocs`) and start Apache and MySQL.
2. Create the database, the least-privilege app account and `includes/config.local.php`:

   ```
   C:\xampp\php\php.exe bin\setup.php
   ```

   The script prints a **random first password for the `admin` account, once**. Write it down: it is stored only as a hash, and you must choose a new password at the first sign-in. Add `--demo` for a trial install with fake demo data; that install keeps the well-known demo account `admin` / `admin` (the sign-in page shows a hint while it is unchanged), so never use `--demo` for real data. If the database already exists the script refuses to run; `--reset` rebuilds it (your Kuma connection, API keys and endpoints are kept) and `--reset --wipe-all` removes those too.
3. Open <http://localhost/Websites/test/>, sign in as `admin` with the password the script printed, and choose your own password when asked. (A demo install: `admin` / `admin`, for trying only.)

An existing database is brought up to date, keeping its data, with `C:\xampp\php\php.exe database\migrate.php`. It runs as root, can be repeated safely, and also migrates the old `users.company` text into companies.

## Styles (Tailwind build)

The pages use one compiled stylesheet, `assets/css/app.css`, which is committed, so running the system needs no Node. Rebuild it after you change classes in a page or the palette in `tailwind.config.js`:

```
npm ci
npm run build:css
```

(`npm run build:css` runs `tailwindcss -c tailwind.config.js -i assets/css/tailwind.src.css -o assets/css/app.css --minify`; the build scans every page, the includes and `assets/js` for class names.) The theme colours are CSS variables (`theme_style()` in `includes/core/theme.php`), so the one file serves both the light and the dark theme. Build output for dynamic class names: write the full class name somewhere in the source (for example `'text-' . $x` will not be found, `$x ? 'text-left' : 'text-right'` will). CI runs the build before the tests, and a test fails if the committed `app.css` is out of date.

Content-Security-Policy note: scripts need a nonce, but `style-src` keeps `'unsafe-inline'`, because about fifty elements carry a `style=""` attribute (animation delays, bar heights) that a nonce cannot cover, and Google Translate adds inline styles when a visitor allows it.

## Folder layout

```
index.php                  the Overview (the only page in the project root)
auth/                      sign-in, two-step, password reset, invitations, forced password change
users/  companies/         customer people and organisations
tickets/  calendar/        support tickets, appointments and .ics export
invoices/                  invoices, PDF, payments, recurring invoices
monitoring/                uptime, endpoint checks, connections
system/                    reports, backups, activity logs, CSV export and import, demo data
search/  notifications/    global search, the bell and its list
settings/  privacy/        settings tabs (code in includes/settings), privacy requests and policy
extras/                    the two hidden pages
api/                       JSON endpoints (health API, Kuma data, key reveal)
bin/                       command-line jobs and installer; refused by the web server
includes/core/             bootstrap, security, layout, theme, translation
includes/modules/          feature logic: companies, tickets, invoices, mailer, PDF writer, two-factor, Kuma, ...
includes/settings/         the tabs of the Settings page
assets/css js img          app.css (compiled Tailwind), motion system, phone table cards, logos; fonts/ and avatars/ beside them
database/                  schema, migration, dumps, test-log baseline
docs/                      guides, data-breach procedure, processing register
tests/                     test suite (tests/parts, one file per stage), mock Kuma and mock SMTP server
storage/                   runtime files (cache, locks, error log); not committed
```

Every page links to others with a path from the project folder (`tickets/ticket.php?id=3`): each page head sets a `<base href>`, so the same link works from any folder. Links that stay on the same page use `current_page() . '#anchor'`, and redirects go through `redirect()`, which adds the folder prefix. Old addresses (`ticket.php`, `login.php`, ...) are redirected with a 301 by `.htaccess`, so emailed links and bookmarks keep working.

The folders `bin`, `includes`, `database`, `tests`, `storage` and `docs` each carry an `.htaccess` that denies all web access.

## Database files

| File | What it is |
| --- | --- |
| `database/schema.sql` | The schema that `bin/setup.php` applies. This is the source of truth. |
| `database/migrate.php` | Upgrades an existing database to the current schema without losing data. |
| `database/ops_console_structure.sql` | A mysqldump of the table structure (no data). |
| `database/test_log_baseline.php` | The documented test history that feeds the Tests tab. |

## What is not in this repository

Secrets and data are deliberately left out. A data dump (`database/ops_console_data.sql`) is no longer tracked: it held the admin account hash and a notification address, and is ignored by `.gitignore`. To move data between machines make a backup with `mysqldump` and keep it private. A fresh install needs no dump: `bin/setup.php` builds everything from `database/schema.sql` (add `--demo` for fake data).

Left out:

- `includes/config.local.php` (database password and `APP_KEY`, generated by `bin/setup.php`)
- the saved Kuma connection and any API keys, so enter them again under Settings
- the SMTP settings and the mail outbox, two-step secrets and recovery codes, reset and invitation tokens
- login attempts, rate-limit counters, activity logs and Kuma heartbeat history
- runtime files in `storage/`

## Scheduled jobs

Set these up in the Windows Task Scheduler (or cron). **The retention job is the only thing that deletes expired data** (activity logs after 12 months, login attempts, rate-limit counters, monitoring history, old invoices): web requests no longer do any housekeeping, so if `bin/purge.php` is not scheduled, nothing is ever removed.

| Job | When | Command |
| --- | --- | --- |
| Retention | daily | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\purge.php` |
| Recurring invoices | daily | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\run_recurring.php` |
| Endpoint checks | every minute | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\check_endpoints.php` |
| Kuma history | every minute | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\kuma_poll.php` |

Recurring invoices are also created when the site is opened.

On Linux the same jobs as cron lines (`crontab -e` for the web server's user, or the owner of the files):

```
# retention: every night at 03:15 (hourly keeps the rate-limit table smaller on a busy site: 15 * * * *)
15 3 * * *  php /var/www/crm/bin/purge.php >> /var/www/crm/storage/purge.log 2>&1
# recurring invoices: every day at 06:00
0 6 * * *   php /var/www/crm/bin/run_recurring.php >> /var/www/crm/storage/recurring.log 2>&1
# endpoint checks and Kuma history: every minute
* * * * *   php /var/www/crm/bin/check_endpoints.php
* * * * *   php /var/www/crm/bin/kuma_poll.php
```

## Tests

```
C:\xampp\php\php.exe tests\run.php
```

About 1,100 checks (one file per stage in `tests/parts/`): authentication, permissions, rate limiting, the SSRF guards, encrypted key storage, the Kuma parser and uptime maths (against a mock Kuma on port 3199, which must be free), the settings endpoints, companies, notes, tickets, search, CSV round trips, notifications, the two-factor algorithm against the RFC test values, email (against a mock SMTP server on port 3225, which must be free), password reset and invitations, trend numbers (in a scratch database), PDF structure and totals, recurring invoices, theme contrast, the phone layout, the calendar, and the folder structure (a crawler follows every link and form in the program). Results and fixes are also documented in Settings, Tests.

Apache and MariaDB must be running; the run takes about two minutes. An optional pre-commit hook runs the suite before each commit: `git config core.hooksPath .githooks` (it uses `php` from the PATH, or `PHP_BIN`).

GitHub Actions (`.github/workflows/tests.yml`) runs the suite on every push and pull request: PHP 8.2 with `pdo_mysql` and `openssl`, a MariaDB service, Apache with `mod_rewrite` (the suite checks the `.htaccess` rules), `php bin/setup.php --demo`, then `php tests/run.php`. The job fails unless the output contains ` 0 failed`.

## Putting it on a network

See `docs/SYSTEM_GUIDE_SECURITY_AND_COMPLIANCE.txt`, section 9, for the go-live checklist. For a private network: give the PC a fixed address, allow ports 80 and 443 only from the local subnet in Windows Firewall, keep MariaDB bound to `127.0.0.1`, and never forward a port on the router.
