# CRM-System

A CRM and admin dashboard for **Telecomlijn**: companies, users, tickets, notes, connections, uptime monitoring, invoices (with PDF and recurring invoices), reports, backups and activity logs, in a navy, lime and butter-yellow design with a light mode. Built with PHP 8.2, MariaDB and Tailwind CSS, and made to run on XAMPP.

> Private project. Everything shown by the demo data is fake.

## Features

- **Overview** with system health, stats, an open-tickets tile, trend charts (revenue, new users, tickets per week, time to resolve, each with a data table) and a demo-data panel (load or remove fake data without touching real records)
- **Companies** as their own section (address, KvK, VAT, users, invoices, open tickets), linked from users and invoices; **Notes** and an activity timeline on every user and company
- **Tickets** with status, priority, assignee, comment thread, filters and sorting
- **Global search** across users, companies, invoices and tickets (wildcard characters are searched literally)
- **CSV export and import** (admin only): UTF-8 with BOM, formula injection neutralised, duplicates skipped, bad rows listed with the reason
- **Notifications**: a bell with unread count for overdue invoices, failed backups, services that are down and urgent tickets, also emailed once
- **Two-factor sign-in** (authenticator app, RFC 6238, written without libraries) with recovery codes and an admin reset
- **Email, password reset and invitations**: own SMTP client, an outbox for localhost, one-hour single-use reset links, invite-by-email
- **Users, Connections, Reports, Backups, Activity logs** with search, sorting, filters and pagination
- **Invoices**: create invoices, see them by date and as open, due or overdue, record payments, **download a PDF** (own PDF writer) and set up **recurring invoices** (month, quarter, year; never twice for one period)
- **Uptime** page in the style of Uptime Kuma, fed by the Kuma API (Settings, Kuma API Key tab)
- **Settings**: account (two-step, notification address), integrations, team (invitations, reset two-step), company details (BTW number and IBAN), email / SMTP and outbox, Kuma, and a **Tests** tab that documents every test and fix
- **Light / dark mode** toggle that follows the system setting by default; **phone layout** where tables become stacked cards below 640 px
- **Language toggle** using Google Translate, only after the user consents
- **Privacy**: privacy policy page, privacy requests, retention purge, and a data-breach procedure and processing register in `docs/`
- **Pop-out sidebar** that can be pinned, plus a spring-based motion system that respects `prefers-reduced-motion`
- Two hidden easter eggs in the search bar, which are left for you to find

## Fill these in yourself

Left blank on purpose, nothing fake is shown in their place:

- **Settings, Email**: an SMTP account (`SMTP_SERVER`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`), the sender address (`EMAIL_FROM`), where notifications go (`EMAIL_TARGET`) and the site address used in email links. Until then reset and invitation emails only appear in the outbox on that tab. The same six names also work as environment variables or keys in `includes/config.local.php`; what is saved on the page wins.
- **Settings, Company details**: your BTW number and IBAN. While empty they are left off the PDF invoices.

## Security

CSRF tokens on every form, nonce-based Content-Security-Policy, hardened sessions (idle and absolute timeouts), database-backed login lockout, per-IP rate limiting, prepared statements everywhere, SSRF guards on outbound checks, AES-256-GCM encryption for stored secrets, roles (`admin`, `user`) with an extra integrations permission (`none`, `view`, `manage`), and an audit log. Apache blocks direct access to `.md`, `.sql`, `.log`, `.json`, config files and `setup.php`.

## Requirements

- XAMPP (Apache + MariaDB 10.4+) with PHP 8.2 and the `openssl` and `pdo_mysql` extensions
- Node.js is optional and only needed for the browser-based checks

## Setup

1. Put the project in `C:\xampp\htdocs\Websites\test` (or any folder under `htdocs`) and start Apache and MySQL.
2. Create the database, the least-privilege app account and `includes/config.local.php`:

   ```
   C:\xampp\php\php.exe setup.php
   ```

   Add `--demo` to also load fake demo data. An **existing** database is brought up to date, keeping its data, with `C:\xampp\php\php.exe database\migrate.php` (it runs as root, can be repeated safely, and also migrates the old `users.company` text into companies). If the database already exists the script refuses to run; `--reset` rebuilds it (your Kuma connection, API keys and endpoints are kept) and `--reset --wipe-all` removes those too.
3. Open <http://localhost/Websites/test/> and sign in with `admin` / `admin`. **Change this password before using the system for anything real.**

### Database files

| File | What it is |
| --- | --- |
| `database/schema.sql` | The schema that `setup.php` applies. This is the source of truth. |
| `database/migrate.php` | Upgrades an existing database to the current schema without losing data. |
| `database/ops_console_structure.sql` | A mysqldump of the table structure. |
| `database/ops_console_data.sql` | A dump of the data (admin account, settings, test log). Logs, keys and mail settings are left out. |
| `database/test_log_baseline.php` | The documented test history that feeds the Tests tab. |

Using `setup.php` is the easiest way to get a working copy, because it also creates the app account and the encryption key.

## What is not in this repository

Secrets are deliberately left out:

- `includes/config.local.php` (database password and `APP_KEY`, generated by `setup.php`)
- the saved Kuma connection and any API keys, so enter them again under Settings
- the SMTP settings and the mail outbox, two-step secrets and recovery codes, reset and invitation tokens
- login attempts, rate-limit counters, activity logs and Kuma heartbeat history
- runtime files in `storage/`

## Tests

```
C:\xampp\php\php.exe tests/run.php
```

900+ checks covering authentication, permissions, rate limiting, the SSRF guards, the encrypted key storage, the Kuma parser and uptime maths (against a mock Kuma on port 3199, which must be free), the settings endpoints, companies, notes, tickets, search, CSV round trips, notifications, the two-factor algorithm against the RFC test values, email (against a mock SMTP server on port 3225, which must be free), password reset and invitations, the trend numbers (in a scratch database), PDF structure and totals, recurring invoices, theme contrast and the phone layout. Results and fixes are also documented in Settings, Tests. Phone and light-mode layout were additionally checked in a real browser at 360, 768 and 1440 px.

Recurring invoices are created when the site is opened and by `php run_recurring.php` (for a scheduled task on a real server).

## Project layout

```
index.php, users.php, ...   pages
api/                        inbound health API
includes/                   bootstrap, security, layout, theme, mailer, PDF writer, two-factor, search, CSV, monitoring, Kuma, settings tabs
assets/                     motion system, phone table cards, self-hosted Tailwind and fonts
database/                   schema, dumps, test-log baseline
docs/                       data-breach procedure, processing register
tests/                      CLI test suite, mock Kuma and mock SMTP server
setup.php                   CLI installer (blocked from the web)
```

## Credits

Design and development by Denis Mehmed for Telecomlijn.
