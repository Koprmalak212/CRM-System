# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

PHP + MariaDB (XAMPP, Apache on localhost), Tailwind CSS via CDN (no Node build), PHP session login, PDO to MariaDB with seeded fake data.

## Users

Support and ops staff who use the dashboard heavily every day: scanning tables, filtering, and acting on records. Admin-level access (with a PHP login).

## Product Purpose
Internal admin panel for Telecomlijn (telecomlijn.nl), a Rotterdam business telecom provider: fiber internet, VoIP/3CX phone systems and managed security.

An internal admin panel for system administration: view users, connections, reports, backups and activity logs. Success is that staff find a record or an anomaly fast and act on it with few clicks. All data is fake seed data for display purposes.

## Capabilities and Constraints

- Sections confirmed by the user: users, connections, reports, backups, activity logs ("etc" is open).
- Runs locally at http://localhost via XAMPP Apache; database is MariaDB (MySQL on XAMPP).
- Data is fake and seeded; no real customers or claims.
- Login via PHP sessions; follow php-security practices (PDO prepared statements, password_hash, CSRF tokens, output escaping).

## Brand Commitments

Telecomlijn logos downloaded from telecomlijn.nl with the owner's permission, in assets/ (white version for the dark UI). User-pinned UI palette: navy #111D36, lime #C7F36B, butter #FFF1A8. Dark mode requested. Telecomlijn's own brand green is #0BF002.

## Evidence on Hand

Telecomlijn logos only. All data is fake seed data; do not fabricate customers, prices or claims.

## Product Principles

- Speed of scanning beats decoration: dense, legible tables and filters first.
- Every record is actionable from where it is seen.
- Status (healthy, failed, pending) is always visible without opening anything.
- Destructive actions are deliberate and reversible where possible.
