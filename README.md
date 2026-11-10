# Telecomlijn CRM

**One calm place to run a telecom business: customers, support, planning, invoices and uptime, together.**

Telecomlijn CRM is a private CRM and operations dashboard for a business that sells and supports connectivity. It brings the people you serve, the problems they report, the visits you plan, the money you are owed and the health of your services into one fast, clear workspace. It runs on your own server, and your data stays there.

> Private software. Copyright (c) 2026 Denis Mehmed. All rights reserved. See [LICENSE](LICENSE).

---

## What it does

### Know your customers
Every customer is a **company** with its address, chamber-of-commerce and VAT numbers, and the **people** who work there. A company page shows everything in one view: its people, open tickets, invoices, upcoming appointments and the full history. Notes (call, meeting, email) and system events sit in one **timeline**, so anyone on the team can pick up a conversation where it was left.

### Handle support without losing track
**Tickets** have a status, a priority, an owner and a comment thread. Filter by what is open, urgent or yours; urgent tickets raise an alert and an email at once. Time-to-resolve is measured for you.

### Plan the work
A **calendar** shows appointments per company and contact person in a month grid and an agenda. Click a day to plan a visit. Each person's page lists what is planned with their company, and any appointment can be added to your own calendar app with one download.

### Get paid
Create **invoices** from a company in seconds, with lines, VAT, payment terms and exact amounts. Record payments (iDEAL, transfer, card, direct debit) and see what is open, due soon or overdue. Download a clean **PDF**, and set up **recurring invoices** that bill monthly, quarterly or yearly, once per period and never twice.

### Watch your services
An **uptime** page in the style of Uptime Kuma shows every monitored service, its history and response time, and your own **endpoint checks** keep an eye on addresses you choose. A service that goes down tells you before a customer does.

### See how the business is doing
The **Overview** shows health, key numbers and trend charts for revenue, new users, tickets per week and time to resolve, each with a data table. **Reports** and **activity logs** can be searched, filtered and exported.

### Find anything
One search box finds people, companies, invoices and tickets. **CSV export and import** move data in and out safely, with bad rows explained instead of guessed.

### Stay informed
A **notification bell** warns about overdue invoices, failed backups, services that are down and urgent tickets, and mails each one once, to the address you choose and to colleagues who opt in.

### Run a team
Invite colleagues by email, give them a role and permission level, switch on **two-step sign-in** (and require it for admins), reset a lost device, and see who did what in an **audit trail**.

---

## Built to be trusted

- **Security by default.** Passwords are stored only as strong hashes. Sign-in has lockout and rate limits, sessions end when idle, forms are protected against forged requests, and stored secrets are encrypted. A strict content policy blocks injected scripts. See `docs/SYSTEM_GUIDE_SECURITY_AND_COMPLIANCE.txt`.
- **Privacy built in.** Retention periods are enforced automatically (activity logs after 12 months, invoices after the legal 7 years). Staff can look up, export and anonymise what is held about a person. Translation through Google only happens after explicit consent. A data-breach procedure and a processing register are included as drafts for legal review.
- **Accessible.** Contrast was measured in light and dark mode, charts come with tables, everything works with a keyboard, and motion stops for people who ask for less of it.
- **Works on a phone.** On small screens tables become readable cards, and the calendar switches to its agenda.
- **Proven.** An automated suite of about 1,100 checks, including a crawler that follows every link and form in the program, runs against a real server. Every test and fix is documented inside the product, under Settings, Tests.

## A product that looks the part

A navy, lime and butter-yellow design with a light mode and a dark mode that follow the system setting by default, a pop-out sidebar, and a quiet, spring-based motion system. The interface has a Dutch-friendly layout, a language switch, and a few hidden surprises for the curious.

## Under the hood

PHP 8.2 and MariaDB, Tailwind CSS, no framework and no build step. It runs on a plain XAMPP stack. Pages are grouped by feature (`auth`, `users`, `companies`, `tickets`, `calendar`, `invoices`, `monitoring`, `system`, ...), shared logic lives in `includes/`, and scheduled jobs live in `bin/`. The PDF writer, the two-factor code (RFC 6238) and the SMTP client are written in-house, so customer data does not leave the server through third-party services.

## Documentation

| Read this | For |
| --- | --- |
| [`docs/SYSTEM_FEATURES_EXPLAINED.txt`](docs/SYSTEM_FEATURES_EXPLAINED.txt) | Every page and feature, how to use it, with worked examples |
| [`docs/SYSTEM_GUIDE_SECURITY_AND_COMPLIANCE.txt`](docs/SYSTEM_GUIDE_SECURITY_AND_COMPLIANCE.txt) | How each part is secured, how the system supports the GDPR and Dutch rules, known gaps, go-live checklist |
| [`docs/INSTALL.md`](docs/INSTALL.md) | Setup, folder layout, scheduled jobs, tests, running on a network |
| [`docs/PRODUCT.md`](docs/PRODUCT.md) | Product and design notes |
| [`docs/datalekprocedure.md`](docs/datalekprocedure.md), [`docs/verwerkingsregister.md`](docs/verwerkingsregister.md) | Data-breach procedure and processing register (Dutch, drafts) |

Getting a copy running takes one command, `php bin/setup.php`; the details are in the install guide.

## Credits and licence

Design and development by Denis Mehmed for Telecomlijn. Third-party components (Tailwind CSS, Hanken Grotesk, icon shapes) keep their own licences, listed in [`assets/LICENSES.txt`](assets/LICENSES.txt).

Copyright (c) 2026 Denis Mehmed. All rights reserved. No one may use, copy, modify or distribute this software without written permission. See [LICENSE](LICENSE).
