<?php
declare(strict_types=1);

/*
 * Demo data: load and remove fake sample data without touching real data.
 *
 * How real data stays safe: every demo row is written with is_demo = 1 and everything you enter yourself
 * keeps the default is_demo = 0. Removal only ever runs "DELETE ... WHERE is_demo = 1". Two extra guards:
 *   - a demo invoice that has a REAL payment recorded on it is kept, never deleted
 *   - loading refuses to run twice, so demo rows are never duplicated
 * Admin accounts are never created or removed here.
 */

/** Tables that carry the is_demo tag, in the order they are shown to the admin. */
require_once __DIR__ . '/../core/demo_state.php';   // demo_loaded()

const DEMO_TABLES = [
    'companies' => 'Companies', 'users' => 'Users', 'notes' => 'Notes', 'tickets' => 'Tickets', 'appointments' => 'Appointments', 'ticket_comments' => 'Ticket comments', 'connections' => 'Connections', 'services' => 'Services', 'invoices' => 'Invoices',
    'payments' => 'Payments', 'recurring_invoices' => 'Recurring invoices', 'reports' => 'Reports', 'backups' => 'Backups',
    'monitored_endpoints' => 'Endpoints', 'activity_logs' => 'Log entries',
];

function demo_set_flag(PDO $pdo, bool $on): void
{
    $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('demo_loaded', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$on ? '1' : '0']);
}

/** @return array<string,int> row counts per table for demo (1) or real (0) rows */
function demo_counts(PDO $pdo, int $isDemo): array
{
    $out = [];
    foreach (DEMO_TABLES as $t => $label) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE is_demo = ?");
        $s->execute([$isDemo]);
        $out[$t] = (int) $s->fetchColumn();
    }
    return $out;
}

function demo_ts(int $secondsAgo): string { return date('Y-m-d H:i:s', time() - $secondsAgo); }
function demo_pick(array $a): mixed { return $a[mt_rand(0, count($a) - 1)]; }
function demo_weighted(array $w): string { $t = array_sum($w); $r = mt_rand(1, $t); foreach ($w as $k => $v) { if (($r -= $v) <= 0) { return (string) $k; } } return (string) array_key_first($w); }

/** Inserts the sample data. Everything is written inside one transaction: all of it, or nothing. */
function demo_seed(PDO $pdo): array
{
    if (demo_loaded($pdo) || array_sum(demo_counts($pdo, 1)) > 0) {
        throw new RuntimeException('Demo data is already loaded.');
    }
    mt_srand(2026);
    $pdo->beginTransaction();
    try {
        // ----- Companies (fake details; the KvK and VAT numbers are made up)
        $companyData = [
            ['Nova Accountants', 'Coolsingel 40', '3011 AD', 'Rotterdam', '71230411', 'novaaccountants'],
            ['Lijnbaan Logistics', 'Lijnbaan 25', '3012 EP', 'Rotterdam', '71230422', 'lijnbaanlogistics'],
            ['Brouwer & Zonen', 'Westblaak 12', '3012 KC', 'Rotterdam', '71230433', 'brouwerzonen'],
            ['Rhine Valley Software', 'Kruiskade 31', '3012 EH', 'Rotterdam', '71230444', 'rhinevalley'],
            ['Atlas Verzekeringen', 'Hofplein 20', '3032 AC', 'Rotterdam', '71230455', 'atlasverzekeringen'],
            ['Polder Energie', 'Maasboulevard 8', '3063 NS', 'Rotterdam', '71230466', 'polderenergie'],
            ['Zuiderzee Foods', 'Delftsestraat 15', '3013 AE', 'Rotterdam', '71230477', 'zuiderzeefoods'],
            ['Delta Medical Group', 'Mathenesserlaan 90', '3023 GN', 'Rotterdam', '71230488', 'deltamedical'],
            ['Tulp Design Studio', 'Oudedijk 114', '3061 AG', 'Rotterdam', '71230499', 'tulpdesign'],
            ['Kade Bouw BV', 'Westzeedijk 345', '3015 AA', 'Rotterdam', '71230510', 'kadebouw'],
            ['Meridian Telecom Partners', 'Blaak 34', '3011 TA', 'Rotterdam', '71230521', 'meridiantelecom'],
            ['Eurobridge Trading', 'Wilhelminakade 91', '3072 AP', 'Rotterdam', '71230532', 'eurobridge'],
            ['Vonk Installatietechniek', 'Schiedamseweg 60', '3025 AE', 'Rotterdam', '71230543', 'vonkinstallatie'],
            ['Noordlicht Media', 'Noordplein 3', '3034 KC', 'Rotterdam', '71230554', 'noordlichtmedia'],
        ];
        $coIns = $pdo->prepare("INSERT INTO companies (name, address, postcode, city, country, kvk, vat_number, email, phone, website, notes, is_demo) VALUES (?,?,?,?,'Netherlands',?,?,?,?,?,?,1)");
        $companies = [];   // name => company row
        foreach ($companyData as $n => [$cname, $street, $pc, $city, $kvk, $slug]) {
            $mail = 'info@' . $slug . '.test';
            $coIns->execute([$cname, $street, $pc, $city, $kvk, 'NL' . substr($kvk, 0, 8) . '1B01', $mail, '010 ' . mt_rand(200, 499) . ' ' . mt_rand(1000, 9999), 'https://www.' . $slug . '.test', $n % 4 === 0 ? 'Prefers invoices by email. Contact the office manager for changes.' : '']);
            $companies[$cname] = ['id' => (int) $pdo->lastInsertId(), 'name' => $cname, 'email' => $mail, 'address' => $street . ', ' . $pc . ' ' . $city];
        }

        // ----- Users
        $first = ['Amara','Liam','Noor','Mateo','Priya','Jonas','Ines','Kofi','Elena','Tariq','Sofia','Hugo','Mei','Dario','Lena','Omar','Zoe','Felix','Aisha','Ravi','Clara','Nico','Yara','Ethan','Maja','Idris','Freya','Luca','Hana','Theo'];
        $last = ['Okafor','Visser','Haddad','Alvarez','Nair','Berg','Costa','Mensah','Petrov','Rahman','Rossi','Martin','Chen','Ferri','Weber','Khalil','Brooks','Hartman','Siddiqui','Patel','Dubois','Janssen','Farouk','Novak','Larsen','Bakker','Moreau','Silva','Sato','Dijkstra'];
        $countries = ['Netherlands','Germany','Belgium','France','Spain','Italy','Poland','Sweden','Portugal','Ireland'];
        $ins = $pdo->prepare('INSERT IGNORE INTO users (name, email, role, status, country, logins, last_seen, created_at, company_id, job_title, phone, is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)');
        $companyIds = array_column($companies, 'id');
        $titles = ['Office manager','IT manager','Finance lead','Operations director','Account manager','Network engineer','Customer success','Owner','Project coordinator','System administrator'];
        $used = [];
        for ($i = 0; $i < 72; $i++) {
            $f = demo_pick($first); $l = demo_pick($last);
            $email = strtolower($f . '.' . $l) . '@example.test';
            if (isset($used[$email])) { $email = strtolower($f . '.' . $l . $i) . '@example.test'; }
            $used[$email] = true;
            $status = demo_weighted(['active' => 76, 'pending' => 14, 'suspended' => 10]);
            $created = mt_rand(3, 420) * 86400 + mt_rand(0, 86399);
            $seen = $status === 'pending' ? $created : mt_rand(30, 20 * 86400);
            if ($status === 'active' && mt_rand(0, 100) < 55) { $seen = mt_rand(20, 6 * 3600); }
            $seen = min($seen, $created);
            $ins->execute([$f . ' ' . $l, $email, demo_weighted(['viewer' => 40, 'editor' => 28, 'support' => 20, 'admin' => 12]), $status, demo_pick($countries), $status === 'pending' ? 0 : mt_rand(3, 900), demo_ts($seen), demo_ts($created), demo_pick($companyIds), demo_pick($titles), '+31 6 ' . mt_rand(1000, 9999) . ' ' . mt_rand(1000, 9999)]);
        }
        $userIds = $pdo->query('SELECT id FROM users WHERE is_demo = 1')->fetchAll(PDO::FETCH_COLUMN);

        // ----- Notes on demo companies and users
        $noteTexts = [
            'note' => ['Asked for a quote for two extra 3CX seats.', 'Contact person changed; new office manager starts next month.', 'Prefers contact by email, not phone.', 'Renewal of the fiber contract is coming up this quarter.'],
            'call' => ['Called about a slow connection; engineer scheduled for tomorrow.', 'Called to confirm the invoice details. All fine.', 'Short call: asked about the DDoS protection add-on.'],
            'email' => ['Sent the updated price list.', 'Replied to the question about the 085 number.', 'Sent the installation planning.'],
            'meeting' => ['On-site meeting about moving the office network.', 'Video call: yearly review of services.'],
        ];
        $ni = $pdo->prepare('INSERT INTO notes (about_type, about_id, kind, body, author, created_at, is_demo) VALUES (?,?,?,?,?,?,1)');
        foreach ($companies as $co) {
            for ($k = mt_rand(2, 4); $k > 0; $k--) {
                $kind = demo_pick(array_keys($noteTexts));
                $ni->execute(['company', $co['id'], $kind, demo_pick($noteTexts[$kind]), demo_pick(['admin', 'support']), demo_ts(mt_rand(3600, 60 * 86400))]);
            }
        }
        foreach (array_slice($userIds, 0, 20) as $uid) {
            $kind = demo_pick(array_keys($noteTexts));
            $ni->execute(['user', $uid, $kind, demo_pick($noteTexts[$kind]), demo_pick(['admin', 'support']), demo_ts(mt_rand(3600, 40 * 86400))]);
        }

        // ----- Tickets with comments
        $subjects = ['Internet connection keeps dropping', 'Cannot make outgoing calls', 'Request: extra 3CX seats', 'Invoice question: wrong amount', 'Yealink phone does not register', 'Slow upload speed on fiber line', 'Move office: relocate the connection', 'Voicemail to email not working', 'Firewall rule change request', 'WhatsApp Business messages delayed', 'New 085 number for sales team', 'Static IP address request', 'Router replaced, VPN no longer works', 'Request: DDoS protection report', 'Phone number porting status'];
        $descs = ['Started this morning and happens several times an hour. We have already restarted the router.', 'Colleagues hear a busy tone when dialling external numbers. Internal calls work fine.', 'We are hiring three people next month and need phones for them.', 'The amount on the latest invoice does not match our quote.', 'The new desk phone shows an error after plugging it in.'];
        $replies = ['Looking into it now.', 'We checked the line and see packet loss on our side. An engineer is scheduled.', 'Thanks, we will call you back this afternoon.', 'Can you confirm the issue is still happening?', 'Fixed on our side. Please confirm that it works for you.', 'Waiting for the customer to reply.'];
        $adminIds = $pdo->query('SELECT id FROM admins WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        $tIns = $pdo->prepare('INSERT INTO tickets (reference, company_id, user_id, subject, description, status, priority, assignee_id, created_by, created_at, updated_at, resolved_at, is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)');
        $tcIns = $pdo->prepare('INSERT INTO ticket_comments (ticket_id, author, body, created_at, is_demo) VALUES (?,?,?,?,1)');
        $uByCo = [];
        foreach ($pdo->query('SELECT id, company_id FROM users WHERE is_demo = 1')->fetchAll() as $ur) { $uByCo[(int) $ur['company_id']][] = (int) $ur['id']; }
        for ($i = 0; $i < 40; $i++) {
            $co = demo_pick($companyIds);
            $uid = !empty($uByCo[$co]) && mt_rand(0, 100) < 80 ? demo_pick($uByCo[$co]) : null;
            $st = demo_weighted(['open' => 22, 'in_progress' => 22, 'waiting' => 14, 'resolved' => 24, 'closed' => 18]);
            $pr = demo_weighted(['low' => 18, 'normal' => 50, 'high' => 22, 'urgent' => 10]);
            $age = mt_rand(2 * 3600, 83 * 86400);   // inside the 12 weeks the ticket charts show
            $upd = mt_rand(0, max(0, $age - 600));
            $done = in_array($st, ['resolved', 'closed'], true) ? demo_ts($upd) : null;
            $tIns->execute(['TMP-D' . $i, $co, $uid, demo_pick($subjects), demo_pick($descs), $st, $pr, $adminIds && mt_rand(0, 100) < 75 ? demo_pick($adminIds) : null, 'admin', demo_ts($age), demo_ts($upd), $done]);
            $tid = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE tickets SET reference = ? WHERE id = ?')->execute(['TCK-' . str_pad((string) $tid, 4, '0', STR_PAD_LEFT), $tid]);
            for ($k = mt_rand(1, 4); $k > 0; $k--) {
                $tcIns->execute([$tid, demo_pick(['admin', 'support']), demo_pick($replies), demo_ts(mt_rand($upd, max($upd, $age - 60)))]);
            }
        }

        // ----- Appointments: a few per company, from a month back to two months ahead, usually with one of its people
        $apIns = $pdo->prepare('INSERT INTO appointments (company_id, user_id, title, location, description, status, starts_at, ends_at, created_by, is_demo) VALUES (?,?,?,?,?,?,?,?,?,1)');
        $apTitles = ['Fiber installation', 'Quarterly review', 'Phone system training', 'Network health check', 'Contract renewal talk', 'Onboarding call', 'Site survey', 'Router replacement'];
        $apPlaces = ['On site', 'Our office, Rotterdam', 'Video call', 'Customer office'];
        foreach ($companies as $co) {
            for ($k = mt_rand(2, 4); $k > 0; $k--) {
                $dayOffset = mt_rand(-30, 60);
                $startTs = strtotime(date('Y-m-d', strtotime(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days')) . ' ' . mt_rand(8, 16) . ':' . demo_pick(['00', '30']));
                $endTs = $startTs + demo_pick([1800, 3600, 5400, 7200]);
                $past = $endTs < time();
                $status = $past ? demo_weighted(['done' => 85, 'cancelled' => 15]) : demo_weighted(['scheduled' => 92, 'cancelled' => 8]);
                $contact = !empty($uByCo[$co['id']]) && mt_rand(0, 100) < 80 ? demo_pick($uByCo[$co['id']]) : null;
                $apIns->execute([$co['id'], $contact, demo_pick($apTitles), demo_pick($apPlaces), '', $status, date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs), 'admin']);
            }
        }

        // ----- Connections (sessions)
        $devices = ['Chrome 126 · Windows 11','Safari 17 · macOS 14','Firefox 127 · Ubuntu','Edge 126 · Windows 10','Safari · iPhone 15','Chrome · Android 14','curl/8.4 · API client'];
        $places = ['Amsterdam, NL','Rotterdam, NL','Berlin, DE','Antwerp, BE','Lyon, FR','Madrid, ES','Milan, IT','Warsaw, PL','Lisbon, PT','Dublin, IE','Stockholm, SE'];
        $ci = $pdo->prepare('INSERT INTO connections (user_id, ip, device, location, protocol, status, started_at, last_activity, bytes_in, bytes_out, is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,1)');
        for ($i = 0; $i < 34; $i++) {
            $status = $i < 5 ? 'flagged' : ($i < 13 ? 'idle' : 'active');
            $start = mt_rand(120, 14 * 3600);
            $lastAct = $status === 'idle' ? mt_rand(900, min($start, 5400)) : mt_rand(5, min($start, 600));
            $ip = $status === 'flagged' ? demo_pick(['185.220.101.', '45.155.205.', '91.240.118.']) . mt_rand(2, 250) : demo_pick(['84.26.', '145.90.', '92.110.', '178.84.']) . mt_rand(1, 250) . '.' . mt_rand(1, 250);
            $ci->execute([demo_pick($userIds), $ip, $status === 'flagged' ? 'curl/8.4 · API client' : demo_pick($devices), $status === 'flagged' ? demo_pick(['Unknown','Frankfurt, DE','Moscow, RU']) : demo_pick($places), demo_pick(['HTTPS','HTTPS','HTTPS','WebSocket','SSH']), $status, demo_ts($start), demo_ts($lastAct), mt_rand(40_000, 90_000_000), mt_rand(20_000, 40_000_000)]);
        }

        // ----- Services with 24 h health (36 slots of 40 minutes, slot 35 = now)
        $services = ['Customer portal','SSO and authentication','Provisioning database','Fiber backbone','SIP trunk','Call recording storage','WhatsApp Business gateway','3CX phone system'];
        $base = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM services')->fetchColumn();
        $si = $pdo->prepare('INSERT INTO services (name, sort_order, is_demo) VALUES (?, ?, 1)');
        $ki = $pdo->prepare('INSERT INTO service_checks (service_id, slot, status) VALUES (?,?,?)');
        foreach ($services as $n => $name) {
            $si->execute([$name, min(250, $base + $n + 1)]);
            $id = (int) $pdo->lastInsertId();
            for ($slot = 0; $slot < 36; $slot++) {
                $st = 'ok';
                if ($name === 'WhatsApp Business gateway' && $slot >= 30) { $st = 'degraded'; }
                if ($name === '3CX phone system' && in_array($slot, [14, 15], true)) { $st = 'outage'; }
                if ($name === 'SIP trunk' && $slot === 22) { $st = 'degraded'; }
                if ($name === 'Fiber backbone' && in_array($slot, [8, 9, 27], true)) { $st = 'degraded'; }
                $ki->execute([$id, $slot, $st]);
            }
        }

        // ----- Reports
        $rtypes = ['Fiber uptime SLA','Call volume','Security audit','Access review','Incident digest','Billing export'];
        $titles = ['Weekly fiber SLA report','Monthly call volume','Quarterly access review','Firewall security audit','Incident digest','Billing export','Failed login analysis','DDoS mitigation summary','3CX usage report','Backup compliance'];
        $ri = $pdo->prepare('INSERT INTO reports (title, type, status, size_kb, author, generated_at, is_demo) VALUES (?,?,?,?,?,?,1)');
        for ($i = 0; $i < 20; $i++) {
            $status = $i === 0 ? 'running' : ($i === 3 ? 'failed' : ($i < 6 ? 'scheduled' : 'ready'));
            $ri->execute([$titles[$i % count($titles)] . ' · ' . date('M Y', time() - $i * 9 * 86400), demo_pick($rtypes), $status, mt_rand(80, 9400), demo_pick(['Sam Rivera','Noor Haddad','Jonas Berg','Automation']), demo_ts($i * 9 * 3600 + mt_rand(0, 3000))]);
        }

        // ----- Backups
        $bi = $pdo->prepare('INSERT INTO backups (name, type, status, size_mb, started_at, duration_s, location, is_demo) VALUES (?,?,?,?,?,?,?,1)');
        for ($i = 0; $i < 28; $i++) {
            $type = $i % 7 === 0 ? 'full' : ($i % 2 === 0 ? 'database' : 'incremental');
            $status = $i === 0 ? 'running' : ($i === 2 ? 'failed' : 'completed');
            $size = $type === 'full' ? mt_rand(18000, 22000) : ($type === 'database' ? mt_rand(2400, 3100) : mt_rand(180, 900));
            $when = $i * 6 * 3600 + mt_rand(0, 1200);
            $bi->execute(['demo_' . date('Y-m-d', time() - $when) . '_' . $type . '_' . str_pad((string) (28 - $i), 3, '0', STR_PAD_LEFT), $type, $status, $size, demo_ts($when), $status === 'failed' ? mt_rand(40, 120) : ($status === 'running' ? 0 : (int) ($size / mt_rand(6, 11))), $i % 3 === 0 ? 'Offsite · eu-west' : 'Local · /var/backups']);
        }

        // ----- Activity logs
        $actions = [
            ['info', 'Signed in', 'dashboard'], ['info', 'Updated profile', 'user'], ['info', 'Exported report', 'report'], ['info', 'Backup completed', 'backup'],
            ['info', 'Changed role', 'user'], ['warning', 'Failed sign-in attempt', 'auth'], ['warning', 'Slow provisioning query', 'provisioning-db'],
            ['warning', 'Call recordings storage above 80%', 'recordings'], ['error', 'Backup failed', 'backup'], ['error', 'WhatsApp message delivery timeout', 'whatsapp-gateway'],
            ['critical', 'Repeated failed sign-ins from one IP', 'auth'], ['info', 'Session terminated', 'connection'], ['info', 'Suspended account', 'user'],
        ];
        $actors = ['sam@telecomlijn.test','noor.haddad@example.test','jonas.berg@example.test','system','scheduler','priya.nair@example.test'];
        $li = $pdo->prepare('INSERT INTO activity_logs (level, actor, action, target, ip, created_at, is_demo) VALUES (?,?,?,?,?,?,1)');
        $rows = [];
        for ($i = 0; $i < 520; $i++) {
            $age = $i < 300 ? mt_rand(30, 86400 - 60) : mt_rand(86400, 6 * 86400);
            $a = $actions[mt_rand(0, count($actions) - 1)];
            if ($a[0] === 'critical' && mt_rand(0, 100) > 14) { $a = $actions[0]; }
            if ($a[0] === 'error' && mt_rand(0, 100) > 40) { $a = $actions[1]; }
            $rows[] = [$age, $a, demo_pick($actors)];
        }
        usort($rows, fn($x, $y) => $y[0] <=> $x[0]);
        foreach ($rows as [$age, $a, $actor]) {
            $li->execute([$a[0], $actor, $a[1], $a[2], demo_pick(['84.26.','145.90.','92.110.','185.220.']) . mt_rand(1, 250) . '.' . mt_rand(1, 250), demo_ts($age)]);
        }

        // ----- Invoices and payments (numbers use their own DEMO- prefix so they can never collide with real ones)
        $customers = array_values($companies);   // every demo invoice is billed to one of the demo companies
        $catalog = [
            ['Business fiber 1 Gbps (monthly)', 14900], ['Business fiber 10 Gbps (monthly)', 59900], ['VoIP seat, 3CX (per user per month)', 950],
            ['085 business number (monthly)', 500], ['Managed firewall (monthly)', 8900], ['DDoS protection (monthly)', 3900],
            ['WhatsApp Business integration (monthly)', 2500], ['Installation and configuration', 24500], ['Yealink desk phone', 7900],
        ];
        $invIns = $pdo->prepare('INSERT INTO invoices (number, company_id, customer, email, customer_address, supply_date, issue_date, due_date, vat_rate, subtotal_cents, vat_cents, total_cents, notes, created_at, is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
        $itemIns = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents) VALUES (?,?,?,?)');
        $payIns = $pdo->prepare('INSERT INTO payments (invoice_id, amount_cents, method, paid_at, reference, created_at, is_demo) VALUES (?,?,?,?,?,?,1)');
        $day = static fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
        $plan = [];
        for ($i = 0; $i < 48; $i++) {
            $terms = demo_pick([14, 14, 30]);
            if ($i < 8) { $dueIn = -mt_rand(3, 38); $paid = 'none'; }
            elseif ($i < 13) { $dueIn = mt_rand(0, 7); $paid = 'none'; }
            elseif ($i < 22) { $dueIn = mt_rand(8, 26); $paid = 'none'; }
            else { $dueIn = -mt_rand(1, 120); $paid = 'full'; }
            if ($i === 3 || $i === 10) { $paid = 'partial'; }
            $plan[] = [max(0, $terms - $dueIn), $terms, $paid];
        }
        usort($plan, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($plan as $n => [$issueAgo, $terms, $paid]) {
            $co = demo_pick($customers);
            $lines = [];
            foreach ((array) array_rand($catalog, mt_rand(1, 3)) as $k) {
                $qty = str_contains($catalog[$k][0], 'per user') ? mt_rand(3, 24) : (str_contains($catalog[$k][0], 'phone') ? mt_rand(2, 10) : 1);
                $lines[] = [$catalog[$k][0], $qty, $catalog[$k][1]];
            }
            $sub = 0;
            foreach ($lines as $l) { $sub += (int) round($l[1] * $l[2]); }
            $vat = (int) round($sub * 0.21);
            $total = $sub + $vat;
            $issue = $day(-$issueAgo);
            $due = date('Y-m-d', strtotime($issue . ' +' . $terms . ' days'));
            $invIns->execute(['DEMO-' . date('Y') . '-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT), $co['id'], $co['name'], $co['email'], $co['address'], $issue, $issue, $due, 21, $sub, $vat, $total, '', $issue . ' 09:00:00']);
            $iid = (int) $pdo->lastInsertId();
            foreach ($lines as $l) { $itemIns->execute([$iid, $l[0], $l[1], $l[2]]); }
            $method = demo_pick(['iDEAL', 'Bank transfer', 'Bank transfer', 'Direct debit', 'Credit card']);
            if ($paid === 'full') {
                $paidAgo = max(0, $issueAgo - mt_rand(2, $terms + 3));
                $payIns->execute([$iid, $total, $method, $day(-$paidAgo), 'PAY-' . mt_rand(100000, 999999), $day(-$paidAgo) . ' 14:00:00']);
            } elseif ($paid === 'partial') {
                $payIns->execute([$iid, (int) (round($total * 0.5 / 100) * 100), $method, $day(-max(0, $issueAgo - 4)), 'PAY-' . mt_rand(100000, 999999), $day(-max(0, $issueAgo - 4)) . ' 14:00:00']);
            }
        }

        // ----- A year of history for the trend charts: older invoices, all paid, spread over the last 12 months
        for ($i = 0; $i < 70; $i++) {
            $co = demo_pick($customers);
            $issueAgo = mt_rand(125, 360);
            $lines = [];
            foreach ((array) array_rand($catalog, mt_rand(1, 3)) as $k) {
                $qty = str_contains($catalog[$k][0], 'per user') ? mt_rand(3, 24) : (str_contains($catalog[$k][0], 'phone') ? mt_rand(2, 10) : 1);
                $lines[] = [$catalog[$k][0], $qty, $catalog[$k][1]];
            }
            $sub = 0;
            foreach ($lines as $l) { $sub += (int) round($l[1] * $l[2]); }
            $vat = (int) round($sub * 0.21);
            $issue = $day(-$issueAgo);
            $invIns->execute(['DEMO-' . date('Y', strtotime($issue)) . '-H' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), $co['id'], $co['name'], $co['email'], $co['address'], $issue, $issue, date('Y-m-d', strtotime($issue . ' +14 days')), 21, $sub, $vat, $sub + $vat, '', $issue . ' 09:00:00']);
            $iid = (int) $pdo->lastInsertId();
            foreach ($lines as $l) { $itemIns->execute([$iid, $l[0], $l[1], $l[2]]); }
            $paidAgo = max(0, $issueAgo - mt_rand(3, 25));
            $payIns->execute([$iid, $sub + $vat, demo_pick(['iDEAL', 'Bank transfer', 'Bank transfer', 'Direct debit', 'Credit card']), $day(-$paidAgo), 'PAY-' . mt_rand(100000, 999999), $day(-$paidAgo) . ' 14:00:00']);
        }

        // ----- Recurring invoices: next dates are all in the future, so loading demo data never creates invoices by itself
        $recIns = $pdo->prepare('INSERT INTO recurring_invoices (company_id, description, amount_cents, vat_rate, frequency, next_date, anchor_day, terms_days, active, created_by, is_demo) VALUES (?,?,?,?,?,?,?,14,?,?,1)');
        foreach ([['Business fiber 1 Gbps (monthly)', 14900, 'month', 1], ['Managed firewall (monthly)', 8900, 'month', 1], ['VoIP seats, 3CX (quarterly)', 22800, 'quarter', 0], ['Yearly support contract', 120000, 'year', 1]] as $n => [$d, $amt, $freq, $act]) {
            $co = $customers[$n % count($customers)];
            $next = date('Y-m-d', strtotime('+' . mt_rand(6, 25) . ' days'));
            $recIns->execute([$co['id'], $d, $amt, 21, $freq, $next, (int) date('j', strtotime($next)), $act, 'admin']);
        }

        // ----- Example endpoints (real checks, but tagged so they can be removed with the rest)
        $pdo->exec("INSERT INTO monitored_endpoints (name, url, method, expected_status, interval_min, is_demo) VALUES ('Telecomlijn website (demo)', 'https://telecomlijn.nl/', 'GET', 200, 5, 1), ('Example.com (demo)', 'https://example.com/', 'HEAD', 200, 5, 1)");

        demo_set_flag($pdo, true);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return demo_counts($pdo, 1);
}

/**
 * Deletes demo rows only. Returns ['deleted' => counts per table, 'kept_invoices' => int].
 * A demo invoice that has a real (non-demo) payment recorded on it is kept.
 */
function demo_remove(PDO $pdo): array
{
    $before = demo_counts($pdo, 1);
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM appointments WHERE is_demo = 1');
        $pdo->exec('DELETE FROM notifications WHERE is_demo = 1');
        $pdo->exec('DELETE FROM tickets WHERE is_demo = 1');   // its comments go with it
        $pdo->exec('DELETE FROM notes WHERE is_demo = 1');
        $pdo->exec('DELETE FROM connections WHERE is_demo = 1');
        $pdo->exec('DELETE FROM users WHERE is_demo = 1');
        $pdo->exec("DELETE FROM notes WHERE about_type = 'user' AND about_id NOT IN (SELECT id FROM users)");   // notes typed by hand on a removed demo user go with that user
        $pdo->exec('DELETE FROM invoices WHERE is_demo = 1 AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = invoices.id AND p.is_demo = 0)');
        // a demo company stays while a real user or a kept invoice still points at it
        $pdo->exec('DELETE FROM companies WHERE is_demo = 1 AND NOT EXISTS (SELECT 1 FROM users u WHERE u.company_id = companies.id) AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.company_id = companies.id) AND NOT EXISTS (SELECT 1 FROM recurring_invoices r WHERE r.company_id = companies.id AND r.is_demo = 0) AND NOT EXISTS (SELECT 1 FROM appointments ap WHERE ap.company_id = companies.id AND ap.is_demo = 0)');
        $pdo->exec("DELETE FROM notes WHERE about_type = 'company' AND about_id NOT IN (SELECT id FROM companies)");
        $pdo->exec('DELETE FROM recurring_invoices WHERE is_demo = 1');   // after the invoices: the ones it made that were kept lose the link
        $pdo->exec('DELETE FROM monitored_endpoints WHERE is_demo = 1');
        $pdo->exec('DELETE FROM services WHERE is_demo = 1');
        $pdo->exec('DELETE FROM reports WHERE is_demo = 1');
        $pdo->exec('DELETE FROM backups WHERE is_demo = 1');
        $pdo->exec('DELETE FROM activity_logs WHERE is_demo = 1');
        $keptInvoices = (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE is_demo = 1')->fetchColumn();
        $keptCompanies = (int) $pdo->query('SELECT COUNT(*) FROM companies WHERE is_demo = 1')->fetchColumn();
        demo_set_flag($pdo, $keptInvoices > 0 || $keptCompanies > 0);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $after = demo_counts($pdo, 1);
    $deleted = [];
    foreach ($before as $t => $n) { $deleted[$t] = $n - $after[$t]; }
    return ['deleted' => $deleted, 'kept_invoices' => $keptInvoices, 'kept_companies' => $keptCompanies];
}
