<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Calendar: appointments linked to companies and their people. Included by tests/run.php inside its try block.
require_once TESTS_DIR . '/../includes/appointments.php';

$clean14 = function () use ($pdo) {
    $pdo->exec("DELETE FROM appointments WHERE title LIKE 'ZZC%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'zz.c14%@example.test'");
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZC%'");
    $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND action IN ('Appointment added','Appointment updated','Appointment deleted')");
    $pdo->exec('DELETE FROM rate_limits');
};
$clean14();

section('42. Calendar: dates, validation, iCalendar');
same([appt_parse_time('2026-11-10T14:30'), appt_parse_time('2026-11-10 14:30:15'), appt_parse_time('2026-02-30T10:00'), appt_parse_time('2026-11-10T25:00'), appt_parse_time('nope'), appt_parse_time('1999-01-01T10:00'), appt_parse_time('2101-01-01T10:00')], ['2026-11-10 14:30:00', '2026-11-10 14:30:15', null, null, null, null, null], 'date-time fields are read strictly: impossible dates, hours and years outside 2000 to 2100 are refused');
$w = calendar_weeks('2026-11');
same([count($w), $w[0][0], $w[0][6], $w[4][6], $w[5][0], count($w[0])], [6, '2026-10-26', '2026-11-01', '2026-11-29', '2026-11-30', 7], 'November 2026 (starts on a Sunday, ends on a Monday) is six Monday-first weeks from 26 October to 6 December');
same([calendar_weeks('2026-06')[0][0], count(calendar_weeks('2027-02')), calendar_weeks('2027-02')[0][0]], ['2026-06-01', 4, '2027-02-01'], 'a month that starts on a Monday starts its grid there, and February 2027 (28 days from a Monday) needs only four weeks');
check(count(calendar_weeks('2026-08')) === 6, 'a month that needs six weeks gets six');
$pdo->exec("INSERT INTO companies (name) VALUES ('ZZC Alpha'), ('ZZC Beta')");
$ca = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZC Alpha'")->fetchColumn();
$cb = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZC Beta'")->fetchColumn();
$pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZC Alice', 'zz.c14a@example.test', 'NL', NOW(), NOW(), ?), ('ZZC Bob', 'zz.c14b@example.test', 'NL', NOW(), NOW(), ?), ('ZZC Carol', 'zz.c14c@example.test', 'NL', NOW(), NOW(), ?)")->execute([$ca, $ca, $cb]);
$ua = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.c14a@example.test'")->fetchColumn();
$ub = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.c14b@example.test'")->fetchColumn();
$uc = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.c14c@example.test'")->fetchColumn();
$ok = ['company_id' => $ca, 'user_id' => $ua, 'title' => 'ZZC Visit', 'location' => 'On site', 'description' => 'Bring the router', 'status' => 'scheduled', 'starts_at' => '2031-03-04T09:00', 'ends_at' => '2031-03-04T10:30'];
[$d, $e] = appointment_clean($pdo, $ok);
same([$e, $d['starts_at'], $d['ends_at']], [[], '2031-03-04 09:00:00', '2031-03-04 10:30:00'], 'a valid appointment passes');
foreach ([['Choose a company', ['company_id' => 0]], ['Enter a title', ['title' => '  ']], ['up to 160', ['title' => str_repeat('t', 161)]], ['Choose a status', ['status' => 'maybe']], ['valid start', ['starts_at' => '31-03-2031']], ['valid end', ['ends_at' => '']], ['end can not be before', ['ends_at' => '2031-03-04T08:00']], ['at most 14 days', ['ends_at' => '2031-04-04T10:00']], ['does not exist', ['user_id' => 999999]], ['does not belong to that company', ['user_id' => $uc]], ['at most 1000', ['description' => str_repeat('d', 1001)]]] as [$msg, $bad]) {
    [, $er] = appointment_clean($pdo, $bad + $ok);
    check((bool) array_filter($er, fn($x) => str_contains($x, $msg)), 'validation: ' . $msg);
}
$ics = appointments_ics([['id' => 7, 'company_name' => 'Acme, Inc; Co', 'title' => 'Plan: a;b,c', 'location' => 'Room 1', 'description' => "Line1\nLine2", 'user_name' => 'Jan', 'status' => 'cancelled', 'starts_at' => '2031-03-04 09:00:00', 'ends_at' => '2031-03-04 10:30:00']], 'Test');
check(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_ends_with($ics, "END:VCALENDAR\r\n") && str_contains($ics, "DTSTART:20310304T090000\r\n") && str_contains($ics, "DTEND:20310304T103000\r\n") && str_contains($ics, 'STATUS:CANCELLED') && str_contains($ics, 'UID:appointment-7@'), 'the .ics has the calendar and event wrappers, local start and end times, status and a stable id');
check(str_contains($ics, 'SUMMARY:Acme\\, Inc\\; Co: Plan: a\\;b\\,c') && str_contains($ics, 'DESCRIPTION:Contact: Jan\\nLine1\\nLine2'), 'commas, semicolons and line breaks are escaped');
$long = appointments_ics([['id' => 1, 'company_name' => 'C', 'title' => str_repeat('é', 120), 'location' => '', 'description' => '', 'user_name' => null, 'status' => 'scheduled', 'starts_at' => '2031-03-04 09:00:00', 'ends_at' => '2031-03-04 10:00:00']], 'T');
$maxLine = max(array_map('strlen', explode("\r\n", $long)));
check($maxLine <= 75 && str_contains($long, "\r\n "), 'long lines are folded at 75 bytes without cutting a character in half');

section('43. Calendar: pages and who sees what');
foreach (['calendar.php', 'appointment_edit.php', "calendar_ics.php?company=$ca"] as $pg) { same(http('GET', "$base/$pg")['code'], 302, "signed out: $pg redirects to the login page"); }
same(http('POST', "$base/appointment_edit.php", ['jar' => $admin, 'body' => 'title=x'])['code'], 403, 'saving an appointment without a CSRF token is 403');
check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'href="calendar.php"'), 'the sidebar has a Calendar item');
$form = ['company_id' => (string) $ca, 'user_id' => (string) $ua, 'title' => 'ZZC Visit', 'location' => 'On site', 'description' => 'Bring the router', 'status' => 'scheduled', 'starts_at' => date('Y-m-d', strtotime('+10 days')) . 'T09:00', 'ends_at' => date('Y-m-d', strtotime('+10 days')) . 'T10:30'];
$r = $post($admin, 'appointment_edit.php', ['title' => ''] + $form);
check($r['code'] === 200 && str_contains($r['body'], 'Enter a title') && str_contains($r['body'], 'value="ZZC Beta"') === false, 'a form with a problem is shown again with the message');
same((int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE title LIKE 'ZZC%'")->fetchColumn(), 0, 'and nothing is saved');
$r = $post($admin, 'appointment_edit.php', ['user_id' => (string) $uc] + $form);
check($r['code'] === 200 && str_contains($r['body'], 'does not belong to that company'), 'a contact from another company is refused');
$r = $post($admin, 'appointment_edit.php', $form);
$ap = $pdo->query("SELECT * FROM appointments WHERE title = 'ZZC Visit'")->fetch();
check($r['code'] === 302 && $ap && (int) $ap['company_id'] === $ca && (int) $ap['user_id'] === $ua && $ap['status'] === 'scheduled' && $ap['created_by'] === 'admin' && (int) $ap['is_demo'] === 0, 'a valid appointment is saved with its company, contact and creator, as real data');
$aid = (int) $ap['id'];
check(str_contains($r['headers'], 'calendar.php?month=' . date('Y-m', strtotime('+10 days'))), 'and the browser goes to that month of the calendar');
$ym = date('Y-m', strtotime('+10 days')); $day = date('Y-m-d', strtotime('+10 days'));
$cal = http('GET', "$base/calendar.php?month=$ym", ['jar' => $admin])['body'];
check(str_contains($cal, 'ZZC Visit') && str_contains($cal, "appointment_edit.php?id=$aid") && str_contains($cal, 'Agenda for'), 'the calendar shows it in the grid and in the agenda');
check(!str_contains(http('GET', "$base/calendar.php?month=" . date('Y-m', strtotime('+3 months')), ['jar' => $admin])['body'], 'ZZC Visit'), 'a different month does not show it');
check(str_contains(http('GET', "$base/calendar.php?month=$ym&company=$ca", ['jar' => $admin])['body'], 'ZZC Visit') && !str_contains(http('GET', "$base/calendar.php?month=$ym&company=$cb", ['jar' => $admin])['body'], 'ZZC Visit'), 'the company filter includes and excludes');
check(str_contains(http('GET', "$base/calendar.php?month=$ym&company=$ca", ['jar' => $admin])['body'], "calendar_ics.php?company=$ca"), 'with a company chosen the calendar offers its .ics feed');
foreach (['calendar.php?month=garbage', 'calendar.php?month=2026-13', 'calendar.php?month=1850-01', 'calendar.php?month[]=x&company=abc'] as $bad) { same(http('GET', "$base/$bad", ['jar' => $admin])['code'], 200, "an invalid month or company is handled: $bad"); }
check(str_contains(http('GET', "$base/calendar.php?month=garbage", ['jar' => $admin])['body'], date('F Y')), 'a bad month falls back to the current one');
foreach (['prev' => date('Y-m', strtotime(date('Y-m-01') . ' -1 month')), 'next' => date('Y-m', strtotime(date('Y-m-01') . ' +1 month'))] as $k => $m) { check(str_contains(http('GET', "$base/calendar.php", ['jar' => $admin])['body'], "month=$m"), "the $k month link goes to $m"); }
// who sees what
$cp = http('GET', "$base/company.php?id=$ca", ['jar' => $admin])['body'];
check(str_contains($cp, 'Upcoming appointments') && str_contains($cp, 'ZZC Visit') && str_contains($cp, 'with ZZC Alice'), 'the company page lists its upcoming appointments with the contact');
$upA = http('GET', "$base/user.php?id=$ua", ['jar' => $admin])['body'];
check(str_contains($upA, 'Appointments of ZZC Alpha') && str_contains($upA, 'ZZC Visit') && str_contains($upA, 'You are the contact'), 'the contact person sees their company\'s appointments, marked as theirs');
$upB = http('GET', "$base/user.php?id=$ub", ['jar' => $admin])['body'];
check(str_contains($upB, 'ZZC Visit') && !str_contains($upB, 'You are the contact') && str_contains($upB, 'with ZZC Alice'), 'a colleague at the same company sees it too, without the marker');
$upC = http('GET', "$base/user.php?id=$uc", ['jar' => $admin])['body'];
check(!str_contains($upC, 'ZZC Visit'), 'a person from another company does not see it');
$pdo->exec("INSERT INTO users (name, email, country, last_seen, created_at) VALUES ('ZZC Loner', 'zz.c14d@example.test', 'NL', NOW(), NOW())");
$ud = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.c14d@example.test'")->fetchColumn();
check(!str_contains(http('GET', "$base/user.php?id=$ud", ['jar' => $admin])['body'], 'Appointments of'), 'a person without a company has no appointments panel');
check(str_contains($upA, "appointment_edit.php?company=$ca&amp;user=$ua"), 'the panel links to a new appointment with that company and person filled in');
check(str_contains(http('GET', "$base/appointment_edit.php?company=$ca&user=$ua", ['jar' => $admin])['body'], "value=\"$ua\" selected"), 'and that link preselects them');
// past, cancelled and far-future ones
$pdo->prepare("INSERT INTO appointments (company_id, user_id, title, starts_at, ends_at, status) VALUES (?, ?, 'ZZC Past', NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY + INTERVAL 1 HOUR, 'done'), (?, ?, 'ZZC Cancelled', NOW() + INTERVAL 3 DAY, NOW() + INTERVAL 3 DAY + INTERVAL 1 HOUR, 'cancelled'), (?, NULL, 'ZZC Later', NOW() + INTERVAL 40 DAY, NOW() + INTERVAL 40 DAY + INTERVAL 1 HOUR, 'scheduled')")->execute([$ca, $ua, $ca, $ua, $ca]);
$cp = http('GET', "$base/company.php?id=$ca", ['jar' => $admin])['body'];
check(str_contains($cp, 'ZZC Later') && !str_contains($cp, 'ZZC Past') && !str_contains($cp, 'ZZC Cancelled'), 'the panels list only upcoming appointments that are not cancelled');
check(strpos($cp, 'ZZC Visit') < strpos($cp, 'ZZC Later'), 'in time order');
$cc = http('GET', "$base/calendar.php?month=" . date('Y-m', strtotime('-5 days')), ['jar' => $admin])['body'];
check(str_contains($cc, 'ZZC Past') && str_contains($cc, '>Done<'), 'the calendar does show past appointments, with their status');
$cm = http('GET', "$base/calendar.php?month=" . date('Y-m', strtotime('+3 days')), ['jar' => $admin])['body'];
check(str_contains($cm, 'line-through') && str_contains($cm, 'Cancelled'), 'cancelled ones are struck through');
// spanning several days
$pdo->prepare("INSERT INTO appointments (company_id, title, starts_at, ends_at, status) VALUES (?, 'ZZC Span', '2031-05-06 09:00:00', '2031-05-08 17:00:00', 'scheduled')")->execute([$ca]);
$sp = http('GET', "$base/calendar.php?month=2031-05", ['jar' => $admin])['body'];
check(substr_count($sp, 'ZZC Span') >= 4, 'an appointment over three days appears on each day of the grid (and once in the agenda)');
$pdo->prepare("INSERT INTO appointments (company_id, title, starts_at, ends_at, status) VALUES (?, 'ZZC Crossing', '2031-05-31 22:00:00', '2031-06-01 01:00:00', 'scheduled')")->execute([$ca]);
check(str_contains(http('GET', "$base/calendar.php?month=2031-06", ['jar' => $admin])['body'], 'ZZC Crossing') && str_contains(http('GET', "$base/calendar.php?month=2031-05", ['jar' => $admin])['body'], 'ZZC Crossing'), 'one that crosses midnight at month end shows in both months');
for ($i = 0; $i < 5; $i++) { $pdo->prepare("INSERT INTO appointments (company_id, title, starts_at, ends_at) VALUES (?, ?, '2031-07-10 10:00:00', '2031-07-10 11:00:00')")->execute([$ca, "ZZC Busy $i"]); }
check(str_contains(http('GET', "$base/calendar.php?month=2031-07", ['jar' => $admin])['body'], '+2 more'), 'a busy day shows three and a "+2 more" link');
// editing, status, escaping, deleting
$ed = $post($admin, 'appointment_edit.php', ['id' => (string) $aid, 'title' => 'ZZC <script>alert(1)</script>', 'status' => 'done'] + $form, "appointment_edit.php?id=$aid");
$sh = http('GET', "$base/calendar.php?month=$ym", ['jar' => $admin])['body'] . http('GET', "$base/company.php?id=$ca", ['jar' => $admin])['body'] . http('GET', "$base/appointment_edit.php?id=$aid", ['jar' => $admin])['body'];
check(!str_contains($sh, '<script>alert(1)') && str_contains($sh, '&lt;script&gt;'), 'markup in a title is escaped everywhere');
same($pdo->query("SELECT status FROM appointments WHERE id = $aid")->fetchColumn(), 'done', 'editing changes the same appointment, including its status');
same(http('GET', "$base/appointment_edit.php?id=999999", ['jar' => $admin])['code'], 404, 'an unknown appointment is 404');
check(str_contains(http('GET', "$base/appointment_edit.php?id=$aid&delete=1", ['jar' => $admin])['body'], 'Yes, delete it') && (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE id = $aid")->fetchColumn() === 1, 'delete asks for confirmation first; opening that page deletes nothing');
$post($admin, 'appointment_edit.php', ['id' => (string) $aid, 'action' => 'delete'], "appointment_edit.php?id=$aid");
same((int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE id = $aid")->fetchColumn(), 0, 'after confirming it is deleted');
check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('Appointment added','Appointment updated','Appointment deleted')")->fetchColumn() >= 3, 'adding, changing and deleting are in the activity log');
// .ics downloads
$one = (int) $pdo->query("SELECT id FROM appointments WHERE title = 'ZZC Later'")->fetchColumn();
$d1 = http('GET', "$base/calendar_ics.php?id=$one", ['jar' => $admin]);
check($d1['code'] === 200 && str_contains($d1['headers'], 'text/calendar') && str_contains($d1['headers'], "appointment-$one.ics") && str_contains($d1['body'], 'SUMMARY:ZZC Alpha: ZZC Later') && str_contains($d1['body'], 'BEGIN:VEVENT'), 'one appointment downloads as an .ics file');
$pdo->prepare("INSERT INTO appointments (company_id, title, starts_at, ends_at, status) VALUES (?, 'ZZC Ancient', NOW() - INTERVAL 45 DAY, NOW() - INTERVAL 45 DAY + INTERVAL 1 HOUR, 'done')")->execute([$ca]);
$d2 = http('GET', "$base/calendar_ics.php?company=$ca", ['jar' => $admin]);
check($d2['code'] === 200 && substr_count($d2['body'], 'BEGIN:VEVENT') >= 4 && str_contains($d2['body'], 'ZZC Later') && str_contains($d2['body'], 'ZZC Past') && !str_contains($d2['body'], 'ZZC Ancient'), 'a company feed has its appointments from 30 days ago on (a 45-day-old one is left out)');
same([http('GET', "$base/calendar_ics.php?id=999999", ['jar' => $admin])['code'], http('GET', "$base/calendar_ics.php?company=999999", ['jar' => $admin])['code']], [404, 404], 'an unknown appointment or company is 404');
// links to people and companies
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$ua]);
check($pdo->query("SELECT COUNT(*) FROM appointments WHERE title = 'ZZC Later' AND user_id IS NULL")->fetchColumn() == 1 && (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE title = 'ZZC Cancelled' AND user_id IS NULL AND company_id = $ca")->fetchColumn() === 1, 'deleting a person keeps their appointments and only clears the contact');
$pdo->exec("DELETE FROM users WHERE company_id = $ca");
$post($admin, 'company_edit.php', ['id' => (string) $ca, 'action' => 'delete'], "company_edit.php?id=$ca");
same((int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE company_id = $ca")->fetchColumn(), 0, 'deleting a company deletes its appointments with it');
if (demo_loaded($pdo)) {
    same(demo_counts($pdo, 1)['appointments'] >= 28, true, 'demo data adds appointments for the demo companies');
    same((int) $pdo->query('SELECT COUNT(*) FROM appointments WHERE is_demo = 1 AND user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users WHERE company_id = appointments.company_id)')->fetchColumn(), 0, 'every demo contact belongs to the appointment\'s company');
    check((int) $pdo->query('SELECT COUNT(*) FROM appointments WHERE is_demo = 1 AND starts_at > NOW()')->fetchColumn() > 5 && (int) $pdo->query('SELECT COUNT(*) FROM appointments WHERE is_demo = 1 AND ends_at < NOW()')->fetchColumn() > 3, 'demo appointments cover the past and the future');
}
$clean14();
