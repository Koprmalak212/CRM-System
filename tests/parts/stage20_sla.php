<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// SLA: targets per priority, the clock (first response, waiting, priority changes), notifications, escalation, the Overview tile.
// Included by tests/run.php inside its try block (uses $base, $admin, $user, $pdo, $post, http(), check(), same(), section()).
require_once TESTS_DIR . '/../includes/modules/tickets.php';
$polSaved20 = $pdo->query('SELECT * FROM sla_policies')->fetchAll();
$onSaved20 = $pdo->query("SELECT v FROM app_meta WHERE k = 'sla_enabled'")->fetchColumn();
$restore20 = function () use ($pdo, $polSaved20, $onSaved20) {
    $pdo->exec('DELETE FROM sla_policies');
    foreach ($polSaved20 as $r) { $pdo->prepare('INSERT INTO sla_policies (priority, respond_min, resolve_min) VALUES (?,?,?)')->execute([$r['priority'], $r['respond_min'], $r['resolve_min']]); }
    $pdo->exec("DELETE FROM app_meta WHERE k = 'sla_enabled'");
    if ($onSaved20 !== false) { $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('sla_enabled', ?)")->execute([$onSaved20]); }
    sla_policies($pdo, true); sla_enabled($pdo, true);
};
$clean20 = function () use ($pdo) {
    $pdo->exec("DELETE FROM notifications WHERE kind = 'ticket_sla' AND body LIKE '%ZZS %'");
    $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZS %'");
    $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND (target LIKE '%ZZS %' OR action IN ('SLA targets saved', 'Ticket escalated') OR (action = 'Ticket updated' AND target LIKE 'TCK-%'))");
    $pdo->exec('DELETE FROM rate_limits');
};
$clean20();
try {
    // known targets for the whole stage, whatever this install has saved
    $pdo->exec("REPLACE INTO sla_policies (priority, respond_min, resolve_min) VALUES ('urgent', 30, 240), ('high', 120, 480), ('normal', 480, 1440), ('low', 1440, 4320)");
    $pdo->exec("REPLACE INTO app_meta (k, v) VALUES ('sla_enabled', '1')");
    sla_policies($pdo, true); sla_enabled($pdo, true);
    $tk20 = function (int $id) use ($pdo): array { return ticket_find($pdo, $id); };
    $mins20 = fn(string $a, string $b): int => (int) round((strtotime($b) - strtotime($a)) / 60);

    section('66. SLA: targets on a ticket');
    same([sla_fmt_minutes(45), sla_fmt_minutes(135), sla_fmt_minutes(240), sla_fmt_minutes(4320), sla_fmt_minutes(1500)], ['45 min', '2 h 15 min', '4 h', '3 days', '25 h'], 'minutes are written as people read them');
    [$t1] = ticket_create($pdo, ['subject' => 'ZZS urgent one', 'description' => 'x', 'priority' => 'urgent'], 'tester');
    $t = $tk20($t1);
    same([$mins20($t['created_at'], $t['respond_due_at']), $mins20($t['created_at'], $t['resolve_due_at'])], [30, 240], 'an urgent ticket gets 30 minutes to respond and 4 hours to resolve');
    [$t2] = ticket_create($pdo, ['subject' => 'ZZS low one', 'description' => 'x', 'priority' => 'low'], 'tester');
    $t = $tk20($t2);
    same([$mins20($t['created_at'], $t['respond_due_at']), $mins20($t['created_at'], $t['resolve_due_at'])], [1440, 4320], 'a low ticket gets a day and three days');
    $s = sla_state($tk20($t1));
    same([$s['respond']['state'], $s['resolve']['state']], ['ok', 'ok'], 'a fresh ticket is inside both targets');
    check(str_contains(sla_badge($tk20($t1)), 'left'), 'its badge shows the time that is left');

    section('67. SLA: first response, waiting and priority');
    ticket_comment($pdo, $t1, 'Changed something.', 'system');
    same($tk20($t1)['first_response_at'], null, 'a system note is not a first response');
    ticket_comment($pdo, $t1, 'I wrote to you.', 'zz@example.test', 'email');
    same($tk20($t1)['first_response_at'], null, 'neither is an email from the customer');
    ticket_comment($pdo, $t1, 'We are on it.', 'tester');
    check($tk20($t1)['first_response_at'] !== null, 'the first comment by staff is the first response');
    same(sla_state($tk20($t1))['respond']['state'], 'met', 'and it met the response target');
    ticket_update($pdo, $tk20($t2), ['status' => 'in_progress']);
    check($tk20($t2)['first_response_at'] !== null, 'picking a ticket up (a status change) also counts as the first response');
    // waiting: the clock stands still
    ticket_update($pdo, $tk20($t1), ['status' => 'waiting']);
    $pdo->exec("UPDATE tickets SET waiting_since = NOW() - INTERVAL 90 MINUTE WHERE id = $t1");
    $w = $tk20($t1);
    same(sla_state($w)['resolve']['state'], 'paused', 'a waiting ticket has its clock paused');
    $dueBefore = $w['resolve_due_at'];
    ticket_update($pdo, $tk20($t1), ['status' => 'in_progress']);
    $a = $tk20($t1);
    check($a['waiting_since'] === null && abs((int) $a['sla_paused_s'] - 5400) <= 5, 'leaving "waiting" records the 90 minutes that were spent there', (string) $a['sla_paused_s']);
    check(abs($mins20($dueBefore, $a['resolve_due_at']) - 90) <= 1, 'and the resolve target moves 90 minutes later');
    ticket_update($pdo, $tk20($t1), ['priority' => 'high']);
    $a = $tk20($t1);
    check(abs($mins20($a['created_at'], $a['resolve_due_at']) - (480 + 90)) <= 1 && $mins20($a['created_at'], $a['respond_due_at']) === 120, 'a new priority gets its own targets, and the waiting time is kept');
    // tickets from before the SLA
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, created_by, created_at, updated_at) VALUES ('ZZS-OLD', 'ZZS old ticket', 'x', 'normal', 'tester', NOW() - INTERVAL 30 DAY, NOW())");
    $old = (int) $pdo->query("SELECT id FROM tickets WHERE reference = 'ZZS-OLD'")->fetchColumn();
    ticket_update($pdo, $tk20($old), ['priority' => 'urgent']);
    same($tk20($old)['resolve_due_at'], null, 'a ticket from before the SLA stays without targets, also when its priority changes');
    check(str_contains(sla_badge($tk20($old)), 'No target'), 'and its badge says "No target"');
    // switched off
    $pdo->exec("REPLACE INTO app_meta (k, v) VALUES ('sla_enabled', '0')"); sla_enabled($pdo, true);
    [$t3] = ticket_create($pdo, ['subject' => 'ZZS while off', 'description' => 'x', 'priority' => 'urgent'], 'tester');
    same($tk20($t3)['resolve_due_at'], null, 'with SLA tracking off a new ticket gets no targets');
    $pdo->exec("REPLACE INTO app_meta (k, v) VALUES ('sla_enabled', '1')"); sla_enabled($pdo, true);

    section('68. SLA: about to breach, breached, escalation');
    $shift20 = fn(int $id, int $createdAgoMin, int $respondInMin, int $resolveInMin) => $pdo->exec("UPDATE tickets SET created_at = NOW() - INTERVAL $createdAgoMin MINUTE, respond_due_at = NOW() + INTERVAL $respondInMin MINUTE, resolve_due_at = NOW() + INTERVAL $resolveInMin MINUTE, sla_paused_s = 0, escalated_at = NULL WHERE id = $id");
    $notes20 = fn(int $id) => $pdo->query("SELECT dedupe_key FROM notifications WHERE kind = 'ticket_sla' AND dedupe_key LIKE '%:$id:%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $shift20($t2, 200, -100, 40);   // 240 minutes in total, 40 left: less than a quarter
    same(sla_state($tk20($t2))['resolve']['state'], 'warn', 'with less than a quarter of the time left a ticket is "about to breach"');
    sla_check($pdo);
    $k = $notes20($t2);
    check(count($k) === 1 && str_starts_with($k[0], "sla-warn:resolve:$t2:"), 'that raises one "about to breach" notification', json_encode($k));
    same((string) $pdo->query("SELECT title FROM notifications WHERE dedupe_key = " . $pdo->quote($k[0]))->fetchColumn(), 'About to breach: ' . $tk20($t2)['reference'], 'with the ticket reference in its title');
    sla_check($pdo);
    same(count($notes20($t2)), 1, 'checking again does not announce it twice');
    $shift20($t2, 300, -200, -20);
    same(sla_state($tk20($t2))['resolve']['state'], 'late', 'past the moment the ticket is breached');
    sla_check($pdo);
    $k = $notes20($t2);
    check(count($k) === 2 && str_starts_with($k[1], "sla-breach:resolve:$t2:"), 'that raises the "SLA breached" notification', json_encode($k));
    $esc = $tk20($t2);
    check($esc['escalated_at'] !== null, 'and the ticket is escalated');
    $sys = (int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $t2 AND author = 'system' AND body LIKE 'Escalated:%'")->fetchColumn();
    sla_check($pdo); sla_check($pdo);
    same([$sys, (int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $t2 AND author = 'system' AND body LIKE 'Escalated:%'")->fetchColumn()], [1, 1], 'the escalation is noted in the thread exactly once');
    same(notification_module('ticket_sla', $k[1]), 'tickets', 'SLA notifications belong to the tickets module');
    // no response yet
    $shift20($t3, 60, -10, 600);
    $pdo->exec("UPDATE tickets SET first_response_at = NULL, status = 'open' WHERE id = $t3");
    same(sla_state($tk20($t3))['respond']['state'], 'late', 'a ticket nobody answered is late on its response target');
    sla_check($pdo);
    check(in_array("sla-breach:respond:$t3:" . $tk20($t3)['respond_due_at'], $notes20($t3), true), 'that is announced too');
    same($tk20($t3)['escalated_at'], null, 'but only a missed solution escalates a ticket');
    check(str_contains(sla_badge($tk20($t3)), 'No response'), 'the badge names the missing response');
    // a waiting ticket is left alone
    $pdo->exec("UPDATE tickets SET status = 'waiting', waiting_since = NOW() - INTERVAL 5 MINUTE, resolve_due_at = NOW() - INTERVAL 1 MINUTE WHERE id = $t1");
    $n0 = count($notes20($t1)); sla_check($pdo);
    same(count($notes20($t1)), $n0, 'a ticket that waits for the customer raises nothing');
    // resolved in time and too late
    $pdo->exec("UPDATE tickets SET status = 'resolved', resolved_at = NOW(), waiting_since = NULL, resolve_due_at = NOW() + INTERVAL 10 MINUTE WHERE id = $t1");
    $pdo->exec("UPDATE tickets SET status = 'resolved', resolved_at = NOW() WHERE id = $t2");
    same([sla_state($tk20($t1))['resolve']['state'], sla_state($tk20($t2))['resolve']['state']], ['met', 'missed'], 'a resolved ticket is "met" or "missed"');
    $root20 = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $root20->exec('DROP DATABASE IF EXISTS ops_console_slatest'); $root20->exec('CREATE DATABASE ops_console_slatest'); $root20->exec('USE ops_console_slatest');
    $root20->exec('CREATE TABLE tickets LIKE ops_console.tickets');
    $root20->exec("INSERT INTO tickets SELECT * FROM ops_console.tickets WHERE subject LIKE 'ZZS %'");
    $st = sla_stats($root20);
    same([$st['total'], $st['met'], $st['pct'], $st['open_breached']], [2, 1, 50.0, 1], 'the numbers: 1 of 2 resolved in time (50%), 1 open ticket past a target');
    $root20->exec("DELETE FROM tickets");
    same(sla_stats($root20)['pct'], null, 'with nothing resolved there is no percentage (not 0%)');
    $root20->exec('DROP DATABASE ops_console_slatest');

    section('69. SLA: pages and settings');
    $pdo->exec('DELETE FROM rate_limits');
    $home = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(str_contains($home, 'SLA met, 30 days') && str_contains($home, 'tickets/tickets.php?sla=breached'), 'the Overview has the SLA tile, linked to the tickets past a target');
    check(str_contains($home, 'past an SLA target'), 'and "Needs attention" names the tickets past a target');
    $list = http('GET', "$base/tickets/tickets.php?sla=breached", ['jar' => $admin]);
    check($list['code'] === 200 && str_contains($list['body'], 'ZZS while off') && !str_contains($list['body'], 'ZZS urgent one'), 'the ticket list can show only the tickets past a target');
    check(str_contains(http('GET', "$base/tickets/tickets.php?sort=sla&dir=asc", ['jar' => $admin])['body'], '>SLA<'), 'the list has an SLA column that can be sorted');
    check(str_contains(http('GET', "$base/tickets/ticket.php?id=$t2", ['jar' => $admin])['body'], 'Escalated'), 'the ticket page shows the SLA box with "Escalated"');
    same(http('GET', "$base/settings/settings.php?tab=tickets")['code'], 302, 'signed out: the Tickets settings tab redirects');
    same(http('GET', "$base/settings/settings.php?tab=tickets", ['jar' => $user])['code'], 403, 'a normal account gets 403 on the Tickets settings tab');
    $slaForm = ['tab' => 'tickets', 'action' => 'sla_save', 'sla_enabled' => '1', 'respond_urgent' => '0,25', 'respond_high' => '1', 'respond_normal' => '4', 'respond_low' => '8', 'resolve_urgent' => '2', 'resolve_high' => '6', 'resolve_normal' => '24', 'resolve_low' => '48'];
    same($post($user, 'settings/settings.php?tab=tickets', $slaForm, 'settings/settings.php')['code'], 403, 'a forged save by a normal account is 403');
    $post($admin, 'settings/settings.php?tab=tickets', ['respond_urgent' => '5'] + $slaForm, 'settings/settings.php?tab=tickets');
    same(sla_policies($pdo, true)['urgent'], ['respond_min' => 30, 'resolve_min' => 240], 'a response target longer than the resolve target is refused');
    $post($admin, 'settings/settings.php?tab=tickets', ['resolve_urgent' => 'abc'] + $slaForm, 'settings/settings.php?tab=tickets');
    same(sla_policies($pdo, true)['high'], ['respond_min' => 120, 'resolve_min' => 480], 'a target that is not a number saves nothing at all');
    $post($admin, 'settings/settings.php?tab=tickets', $slaForm, 'settings/settings.php?tab=tickets');
    same([sla_policies($pdo, true)['urgent'], sla_policies($pdo)['low']], [['respond_min' => 15, 'resolve_min' => 120], ['respond_min' => 480, 'resolve_min' => 2880]], 'valid targets are saved (hours become minutes, 0,25 h = 15 min)');
    $tabHtml = http('GET', "$base/settings/settings.php?tab=tickets", ['jar' => $admin])['body'];
    check(str_contains($tabHtml, 'name="respond_urgent"') && str_contains($tabHtml, 'value="0,25"'), 'the tab shows the saved targets');
    $unticked = $slaForm; unset($unticked['sla_enabled']);
    $post($admin, 'settings/settings.php?tab=tickets', $unticked, 'settings/settings.php?tab=tickets');
    same(sla_enabled($pdo, true), false, 'unticking "Track the SLA" turns it off');
    $pdo->exec('DELETE FROM rate_limits');
    check(!str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'SLA met, 30 days'), 'and the Overview tile is gone');
    same(sla_check($pdo), 0, 'and nothing is checked or escalated');
} finally {
    try { (new PDO('mysql:host=127.0.0.1', 'root', ''))->exec('DROP DATABASE IF EXISTS ops_console_slatest'); } catch (Throwable $e) {}
    $restore20();
    $clean20();
}
