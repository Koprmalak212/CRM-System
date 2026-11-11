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
calls/  links/            calls and messages (3CX, Yeastar, Twilio) and short links (Short.io)
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
| Contracts (invoices, renewals, endings) | daily | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\run_contracts.php` |
| Email to ticket (reads the support mailbox) | every 3 minutes | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\poll_inbox.php` |
| Endpoint checks | every minute | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\check_endpoints.php` |
| Kuma history | every minute | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\kuma_poll.php` |
| Connectors (calls, messages, short links) | every 5 minutes | `C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\sync_connectors.php` |

Recurring invoices and contract invoices are also created when the site is opened, and the SLA of open tickets is checked then too (at most every three minutes). The support mailbox is only read by its job or by the "Read the mailbox now" button, never inside an ordinary page view.

On Linux the same jobs as cron lines (`crontab -e` for the web server's user, or the owner of the files):

```
# retention: every night at 03:15 (hourly keeps the rate-limit table smaller on a busy site: 15 * * * *)
15 3 * * *  php /var/www/crm/bin/purge.php >> /var/www/crm/storage/purge.log 2>&1
# recurring invoices: every day at 06:00
0 6 * * *   php /var/www/crm/bin/run_recurring.php >> /var/www/crm/storage/recurring.log 2>&1
# contracts: invoices that are due, renewals and endings, every day at 06:05
5 6 * * *   php /var/www/crm/bin/run_contracts.php >> /var/www/crm/storage/contracts.log 2>&1
# email to ticket: every 3 minutes
*/3 * * * * php /var/www/crm/bin/poll_inbox.php >> /var/www/crm/storage/inbox.log 2>&1
# endpoint checks and Kuma history: every minute
* * * * *   php /var/www/crm/bin/check_endpoints.php
* * * * *   php /var/www/crm/bin/kuma_poll.php
# connectors (3CX, Yeastar, Twilio, Short.io): every 5 minutes
*/5 * * * * php /var/www/crm/bin/sync_connectors.php
```

## Connectors: 3CX, Yeastar, Twilio and Short.io

Open Settings, Connectors (admins only). Every service has its own box with a **Test connection** button (nothing is saved by a test), a **Save** button and a **Sync now** button. Secrets are stored encrypted (AES-256-GCM, with the `APP_KEY` from `includes/config.local.php`) and are never shown again: leave the secret field empty to keep the saved one. A saved secret is only used for the address and ID it was saved with: change the address and the secret is dropped.

| Service | You need | Gives you |
| --- | --- | --- |
| 3CX | Server address, and either the Client ID and Client secret of an API client application (3CX version 20, Integrations, API, with access to the call history) **or** the user name and password of a system owner or admin, typed in the same two fields. The API client is tried first, then the login. A login with two-step sign-in turned on can not be used: make an API client for it | Calls |
| Yeastar | Server address (with port), Client ID and Client secret (P-Series, Integrations, API) | Calls |
| Twilio | Account SID (starts with `AC`) and Auth token | Calls and text messages |
| Short.io | A secret API key | Short links |

- A phone system on your own network usually has a self-signed certificate: tick "Accept a self-signed certificate" for that service only. Addresses on private networks are allowed (like the Uptime Kuma address); link-local addresses such as the cloud metadata address are refused.
- Calls and messages are matched to people and companies by **phone number** (the last nine digits, so `+31 6 1234 5678` and `06 1234 5678` are the same number). Keep the phone numbers on people and companies up to date; anything unclear stays "Unmatched". A number shared by colleagues of one company is matched to the company.
- **Message text is not stored** unless you tick "Keep the text of messages" for Twilio. Calls and messages are deleted after 12 months by `bin/purge.php`, are part of the privacy export and are blanked by the privacy erasure.
- Short links are tagged with the company when they are made here (`company-<id>`), so the company shows on the Short links page and on the company page. Links made elsewhere are listed without a company.
- Data is fetched by `bin/sync_connectors.php` (see Scheduled jobs) or with Sync now: never inside an ordinary page view.
- **3CX call history.** The calls are read through the call-log function of the 3CX reports (`ReportCallLogData`, `Pbx.GetCallLogData`) for a period, and only if that is refused through the `CallHistoryView` list with a start date. The program never asks a PBX for its whole history, because a PBX with years of calls cannot answer that. A sync looks back 35 days, newest first, up to 2000 records in pages of 200, and waits up to 30 seconds per request. Each segment of a call (for example when it is passed on) is one row. The count in the message is the number of rows stored; if records replace each other because they share an ID, the log says so with a WARNING. If it still fails, the log below the connector shows what 3CX said and which call-history views and fields the PBX offers.
- **Log.** Under each connector a "Log" lists the last tests and syncs: the result, the message, and one line per request the program made (for example `POST pbx.example.com:5001/connect/token -> HTTP 400, invalid_client`). It shows the address, the HTTP code, the error code the service gave and, when there was no answer, the connection error (for example a certificate problem or a name that could not be resolved). It never holds a secret. It opens by itself while the last result is a failure. A scheduled sync is only written down when its result changes. The newest 40 entries per service are kept, and `bin/purge.php` removes entries older than 90 days.
- The field names of each service follow its public API documentation as far as known, and the code accepts several spellings. It could not be tried against real accounts while it was built: if a real service answers differently, the sync reports "Invalid response" and stores nothing. Tell the developer which service and what it said.

## SLA and email to ticket

Both belong to the Tickets module and are set under Settings > Tickets (admins only).

- **SLA targets.** Per priority: the hours allowed for a first response and for the solution. The clock runs 24 hours a day and stands still while a ticket is "Waiting". A contract can carry shorter targets for one company. Tickets that existed before this feature have no targets and are left out of the SLA numbers.
- **Email to ticket.** Enter the IMAP server, user name and password of the support mailbox (the password is stored encrypted), the folder (normally `INBOX`) and the support address. Use "Test the connection", then tick "Read this mailbox" and schedule `bin/poll_inbox.php`. The reader is written in-house: the PHP imap extension is not needed.
- A message becomes a comment on an existing ticket when its subject holds the reference (`[TCK-0042]`) or it answers a mail that is already known, **and** it comes from the requester, the contact person or someone of the same company. Everything else becomes a new ticket. Automatic mail (out-of-office, bounces, mailing lists) and mail from the support address itself are ignored, and one sender can open at most 10 tickets an hour.
- Only the first 512 KB of a message is read and attachments are not stored. Messages are marked as read in the mailbox, never deleted.
- For replies to reach the customer, outgoing mail must work (Settings > Email). Set the sender address or the support address so that the customer's reply comes back to the mailbox that is read.
- It could only be tried against a stand-in IMAP server while it was built. Run "Test the connection" against your real mailbox before relying on it.

## Choosing modules

Under Settings > Modules (admins only) switch off what this install does not use: calendar, tickets, quotes, contracts, invoices, calls, short links, connections, uptime, health checks and API, reports, backups and activity logs. A module that is off has no menu item, and its pages answer 404. Its data is kept, so it can be switched on again later. The core (companies, users, notes, search, notifications and settings) is always on.

The scheduled scripts of a module that is off (`bin/kuma_poll.php`, `bin/run_recurring.php`, `bin/run_contracts.php`, `bin/poll_inbox.php`, `bin/check_endpoints.php`) print one line and exit, so they can stay in the scheduler.

## Tests

```
C:\xampp\php\php.exe tests\run.php
```

About 1,500 checks (one file per stage in `tests/parts/`): authentication, permissions, rate limiting, the SSRF guards, encrypted key storage, the Kuma parser and uptime maths (against a mock Kuma on port 3199, which must be free), the settings endpoints, companies, notes, tickets, search, CSV round trips, notifications, the two-factor algorithm against the RFC test values, email (against a mock SMTP server on port 3225, which must be free), password reset and invitations, trend numbers (in a scratch database), PDF structure and totals, recurring invoices, theme contrast, the phone layout, the calendar, the folder structure (a crawler follows every link and form in the program), modules switched off one by one and all at once, contracts and their invoices, quotes, the SLA clock, and email to ticket (against a stand-in IMAP server on port 3243, which must be free). Results and fixes are also documented in Settings, Tests.

Apache and MariaDB must be running; the run takes about two minutes. An optional pre-commit hook runs the suite before each commit: `git config core.hooksPath .githooks` (it uses `php` from the PATH, or `PHP_BIN`).

GitHub Actions (`.github/workflows/tests.yml`) runs the suite on every push and pull request: PHP 8.2 with `pdo_mysql` and `openssl`, a MariaDB service, Apache with `mod_rewrite` (the suite checks the `.htaccess` rules), `php bin/setup.php --demo`, then `php tests/run.php`. The job fails unless the output contains ` 0 failed`.

## Linux, Apache and MariaDB (production)

The examples are for Debian or Ubuntu with the project in `/var/www/crm`; any other path works, because every link is relative to the folder the project is served from.

**1. Packages**

```
sudo apt install apache2 libapache2-mod-php8.2 php8.2-cli php8.2-mysql php8.2-curl php8.2-mbstring mariadb-server
sudo a2enmod rewrite headers
```

PHP needs `pdo_mysql`, `openssl`, `curl` and `mbstring` (`openssl`, `ctype` and `filter` are part of the standard Debian PHP package). `php -m` lists what is loaded. Argon2id password hashing is used when PHP has it (the default build does) and bcrypt otherwise.

**2. Apache.** `.htaccess` is part of the security (closed folders, the HTTP to HTTPS redirect, the old-address redirects), so the site folder must allow overrides. A virtual host:

```
<VirtualHost *:80>
    ServerName crm.example.com
    DocumentRoot /var/www/crm
    <Directory /var/www/crm>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Check it with `curl -I http://crm.example.com/includes/core/bootstrap.php`: it must answer 403.

**3. Database and first install.** On Debian the MariaDB `root` user signs in through the socket and has no password for TCP, which is what XAMPP's defaults assume. Create an administrator for the install, run setup with it, and drop it afterwards:

```
sudo mariadb -e "CREATE USER 'installer'@'127.0.0.1' IDENTIFIED BY 'a-long-temporary-password'; GRANT ALL ON *.* TO 'installer'@'127.0.0.1' WITH GRANT OPTION;"
sudo -u www-data SETUP_DB_USER=installer SETUP_DB_PASS='a-long-temporary-password' php bin/setup.php
sudo mariadb -e "DROP USER 'installer'@'127.0.0.1';"
```

`bin/setup.php` prints the first admin password once. It also creates the limited `ops_console_app` account (select, insert, update and delete only) and writes its password to `includes/config.local.php`. Later schema updates run `database/migrate.php` the same way, with `MIGRATE_DB_USER` and `MIGRATE_DB_PASS`. The database must run on the same machine as the web server.

**4. Permissions.** The web server's user (`www-data`) must be able to write the runtime folder and read the config, and nobody else should:

```
sudo chown -R www-data:www-data /var/www/crm/storage
sudo chmod 750 /var/www/crm/storage
sudo chown www-data:www-data /var/www/crm/includes/config.local.php
sudo chmod 640 /var/www/crm/includes/config.local.php
```

**5. Scheduled jobs.** Add the cron lines from "Scheduled jobs" above to the crontab of `www-data` (`sudo crontab -u www-data -e`): the retention purge is the only thing that deletes expired data.

**6. HTTPS.** Get a certificate, for example with Let's Encrypt:

```
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d crm.example.com
```

Once the site is served over HTTPS the program does the rest by itself: `.htaccess` sends plain HTTP to HTTPS (only `localhost` and private network addresses such as 10.x and 192.168.x stay on HTTP), the session cookie becomes `Secure` with the `__Host-` prefix, and `Strict-Transport-Security` is sent. Open the firewall for 80 and 443 only (`sudo ufw allow 'Apache Full'`), never for 3306.

### Behind a reverse proxy or load balancer

The program deliberately reads the visitor's address from `REMOTE_ADDR` only and ignores the `X-Forwarded-For` header, because that header is typed by the client unless a proxy you control sets it. Behind a proxy `REMOTE_ADDR` is the proxy's address, so **every user would share one rate-limit bucket (240 requests a minute) and one sign-in lockout counter**. Fix it with `mod_remoteip`, naming only the proxies you control:

```
sudo a2enmod remoteip
# /etc/apache2/conf-available/remoteip.conf  (then: sudo a2enconf remoteip && sudo systemctl reload apache2)
RemoteIPHeader X-Forwarded-For
RemoteIPInternalProxy 10.0.0.5
# a whole network: RemoteIPInternalProxy 10.0.0.0/24
```

Never trust a wide range such as `0.0.0.0/0`: anyone could then pick the address the limiter sees. If the proxy ends HTTPS and talks plain HTTP to Apache, also tell PHP that the visitor used HTTPS (this is what turns on the secure cookie and the HSTS header), again only for requests that come from the proxy:

```
SetEnvIf X-Forwarded-Proto "https" HTTPS=on
```

After this, `curl -I https://crm.example.com/auth/login.php` should show a `Set-Cookie: __Host-ops_console` with `Secure`, and the activity log should show the visitors' own addresses.

### Lines-of-code badge

The badge at the top of the README is a typed-in number (a status badge can not read a private repository). Update it after larger changes with `php bin/count_lines.php`: it counts the non-blank lines of PHP, JavaScript, CSS, SQL and workflow files (leaving out third-party and generated files such as `node_modules`, the compiled `assets/css/app.css` and the data dumps) and rewrites the number in `README.md`.

## Putting it on a network

See `docs/SYSTEM_GUIDE_SECURITY_AND_COMPLIANCE.txt`, section 9, for the go-live checklist. For a private network: give the PC a fixed address, allow ports 80 and 443 only from the local subnet in Windows Firewall, keep MariaDB bound to `127.0.0.1`, and never forward a port on the router.
