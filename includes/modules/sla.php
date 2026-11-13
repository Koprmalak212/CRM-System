<?php
declare(strict_types=1);

/*
 * SLA for tickets: how long a first response and the solution may take.
 *
 * - The targets are set per priority under Settings > Tickets (table sla_policies, in minutes). The clock runs 24 hours a day.
 * - A company with an active contract that carries its own targets gets those whenever they are shorter (only while the Contracts module is on).
 * - The two moments are stored on the ticket when it is opened (respond_due_at, resolve_due_at) and worked out again when the priority changes.
 * - While a ticket is "Waiting" (for the customer) the clock stands still: that time is added to the resolve target when the ticket moves on.
 * - A first response is the first comment typed by staff, or staff changing the status. System notes and customer emails do not count.
 * - Tickets from before this feature have no targets and are left out of every SLA number.
 */

const SLA_DEFAULTS = ['urgent' => [30, 240], 'high' => [120, 480], 'normal' => [480, 1440], 'low' => [1440, 4320]];   // respond, resolve (minutes)
const SLA_WARN_PART = 4;        // "about to breach" starts when less than a quarter of the time is left
const SLA_MAX_MINUTES = 525600; // a target can be at most a year

/** SLA tracking can be switched off under Settings > Tickets; tickets opened while it is off get no targets. */
function sla_enabled(PDO $pdo, bool $fresh = false): bool
{
    static $on = null;
    if ($on === null || $fresh) {
        try { $on = $pdo->query("SELECT v FROM app_meta WHERE k = 'sla_enabled'")->fetchColumn() !== '0'; }
        catch (Throwable $e) { $on = true; }
    }
    return $on;
}

/** priority => ['respond_min' => int, 'resolve_min' => int], for all four priorities. */
function sla_policies(PDO $pdo, bool $fresh = false): array
{
    static $p = null;
    if ($p === null || $fresh) {
        $p = [];
        foreach (SLA_DEFAULTS as $k => [$a, $b]) { $p[$k] = ['respond_min' => $a, 'resolve_min' => $b]; }
        try {
            foreach ($pdo->query('SELECT priority, respond_min, resolve_min FROM sla_policies') as $r) {
                if (isset($p[$r['priority']])) { $p[$r['priority']] = ['respond_min' => (int) $r['respond_min'], 'resolve_min' => (int) $r['resolve_min']]; }
            }
        } catch (Throwable $e) { error_log('SLA policies unavailable: ' . $e->getMessage()); }
    }
    return $p;
}

/**
 * The targets for one ticket: [respond minutes, resolve minutes, 'standard' or 'contract'].
 * An active contract of the company with shorter targets wins, each of the two on its own.
 */
function sla_targets(PDO $pdo, string $priority, ?int $companyId): array
{
    $pol = sla_policies($pdo)[$priority] ?? sla_policies($pdo)['normal'];
    $respond = $pol['respond_min']; $resolve = $pol['resolve_min']; $from = 'standard';
    if ($companyId !== null && $companyId > 0 && module_enabled('contracts')) {
        $s = $pdo->prepare("SELECT MIN(sla_respond_min) r, MIN(sla_resolve_min) s FROM contracts WHERE company_id = ? AND status = 'active'");
        $s->execute([$companyId]);
        $c = $s->fetch();
        if ($c && $c['r'] !== null && (int) $c['r'] < $respond) { $respond = (int) $c['r']; $from = 'contract'; }
        if ($c && $c['s'] !== null && (int) $c['s'] < $resolve) { $resolve = (int) $c['s']; $from = 'contract'; }
    }
    return [$respond, $resolve, $from];
}

/** Stores when the first response and the solution of a ticket are due, counted from the moment it was opened. */
function sla_apply(PDO $pdo, int $ticketId): void
{
    if (!sla_enabled($pdo)) { return; }
    $s = $pdo->prepare('SELECT priority, company_id FROM tickets WHERE id = ?');
    $s->execute([$ticketId]);
    $t = $s->fetch();
    if (!$t) { return; }
    [$respond, $resolve] = sla_targets($pdo, (string) $t['priority'], $t['company_id'] !== null ? (int) $t['company_id'] : null);
    $pdo->prepare('UPDATE tickets SET respond_due_at = created_at + INTERVAL ? MINUTE, resolve_due_at = created_at + INTERVAL (? * 60 + sla_paused_s) SECOND WHERE id = ?')
        ->execute([$respond, $resolve, $ticketId]);
}

/**
 * Where a ticket stands against its two targets. Each of 'respond' and 'resolve' is ['state' => ..., 'left' => seconds (negative = overdue)]:
 *   none (no target), met, missed (done, but too late), ok, warn (less than a quarter left), late (overdue now), paused (waiting for the customer).
 */
function sla_state(array $t, ?int $now = null): array
{
    $now ??= time();
    $created = strtotime((string) $t['created_at']);
    $one = function (?string $due, ?string $done, bool $paused) use ($now, $created, $t): array {
        if ($due === null) { return ['state' => 'none', 'left' => 0]; }
        $dueTs = strtotime($due);
        if ($done !== null) { return ['state' => strtotime($done) <= $dueTs ? 'met' : 'missed', 'left' => $dueTs - strtotime($done)]; }
        if ($paused) { return ['state' => 'paused', 'left' => $dueTs + max(0, $now - strtotime((string) $t['waiting_since'])) - $now]; }
        $left = $dueTs - $now;
        if ($left < 0) { return ['state' => 'late', 'left' => $left]; }
        return ['state' => $left <= max(60, intdiv($dueTs - $created, SLA_WARN_PART)) ? 'warn' : 'ok', 'left' => $left];
    };
    $paused = ($t['status'] ?? '') === 'waiting' && !empty($t['waiting_since']);
    return [
        'respond' => $one($t['respond_due_at'] ?? null, $t['first_response_at'] ?? $t['resolved_at'] ?? null, false),   // resolving without a comment is also an answer
        'resolve' => $one($t['resolve_due_at'] ?? null, $t['resolved_at'] ?? null, $paused),
    ];
}

/** 135 => "2 h 15 min", 45 => "45 min", 4320 => "3 days". For targets and countdowns. */
function sla_fmt_minutes(int $min): string
{
    $min = abs($min);
    if ($min < 60) { return $min . ' min'; }
    if ($min < 2880) { return intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . ($min % 60) . ' min' : ''); }
    return intdiv($min, 1440) . ' days' . (intdiv($min % 1440, 60) ? ' ' . intdiv($min % 1440, 60) . ' h' : '');
}

/** One line of plain text for a target, for example "Due in 2 h 15 min" or "Overdue by 40 min". $what is 'respond' or 'resolve'. */
function sla_text(array $part, string $what): string
{
    $m = (int) ceil(abs($part['left']) / 60);
    $noun = $what === 'respond' ? 'First response' : 'Solution';
    return match ($part['state']) {
        'none' => 'No target',
        'met' => $noun . ' in time',
        'missed' => $noun . ' ' . sla_fmt_minutes($m) . ' too late',
        'paused' => 'Paused while waiting, ' . sla_fmt_minutes($m) . ' left',
        'late' => $noun . ' overdue by ' . sla_fmt_minutes($m),
        default => $noun . ' due in ' . sla_fmt_minutes($m),
    };
}

/** The one SLA pill for a row in the ticket list: the worst thing that is going on. Empty for a ticket without targets. */
function sla_badge(array $t): string
{
    $s = sla_state($t);
    if ($s['resolve']['state'] === 'none' && $s['respond']['state'] === 'none') { return '<span class="text-soft">No target</span>'; }
    $pick = ['resolve', $s['resolve']];
    if (in_array($s['respond']['state'], ['late', 'warn'], true) && $s['resolve']['state'] !== 'late') { $pick = ['respond', $s['respond']]; }
    [$what, $p] = $pick;
    $tone = ['late' => 'bad', 'missed' => 'bad', 'warn' => 'warn', 'paused' => 'info', 'met' => 'ok', 'ok' => 'info'][$p['state']] ?? 'info';
    $cls = ['ok' => 'bg-lime/15 text-lime', 'info' => 'bg-ink/10 text-ink', 'warn' => 'bg-butter-100 text-butter', 'bad' => 'bg-coral/15 text-[#FF9C8C]'][$tone];
    $m = sla_fmt_minutes((int) ceil(abs($p['left']) / 60));
    $short = match ($p['state']) {
        'late' => ($what === 'respond' ? 'No response, ' : 'Overdue ') . $m,
        'missed' => 'Missed by ' . $m,
        'warn', 'ok' => ($what === 'respond' ? 'Respond in ' : '') . $m . ($what === 'respond' ? '' : ' left'),
        'paused' => 'Paused',
        default => 'In time',
    };
    return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $cls . '" title="' . e(sla_text($p, $what)) . '"><span class="h-1.5 w-1.5 rounded-full bg-current"></span>' . e($short) . '</span>';
}

/** SQL conditions on the tickets table (alias t) for the list filters. Constants only. */
function sla_sql(string $which): string
{
    $open = "t.status NOT IN ('resolved','closed','waiting')";
    return match ($which) {
        'breached' => "($open AND ((t.resolve_due_at IS NOT NULL AND t.resolve_due_at < NOW()) OR (t.respond_due_at IS NOT NULL AND t.first_response_at IS NULL AND t.respond_due_at < NOW())))",
        'risk' => "($open AND t.resolve_due_at IS NOT NULL AND t.resolve_due_at >= NOW() AND TIMESTAMPDIFF(SECOND, NOW(), t.resolve_due_at) <= GREATEST(60, TIMESTAMPDIFF(SECOND, t.created_at, t.resolve_due_at) / " . SLA_WARN_PART . '))',
        'escalated' => "(t.escalated_at IS NOT NULL AND t.status NOT IN ('resolved','closed'))",
        default => '1 = 1',
    };
}

/**
 * The numbers for the Overview: of the tickets resolved in the last $days days that had a target, how many were resolved in time,
 * and how many open tickets are past a target right now. 'pct' is null when nothing with a target was resolved.
 */
function sla_stats(PDO $pdo, int $days = 30): array
{
    $r = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(resolved_at <= resolve_due_at), 0) met FROM tickets WHERE resolved_at IS NOT NULL AND resolve_due_at IS NOT NULL AND resolved_at >= NOW() - INTERVAL ' . (int) $days . ' DAY')->fetch();
    $late = (int) $pdo->query('SELECT COUNT(*) FROM tickets t WHERE ' . sla_sql('breached'))->fetchColumn();
    $total = (int) $r['total']; $met = (int) $r['met'];
    return ['total' => $total, 'met' => $met, 'pct' => $total > 0 ? round($met / $total * 100, 1) : null, 'open_breached' => $late];
}

/**
 * Raises the SLA notifications and escalates tickets that missed their resolve target. Called from the notification refresh.
 *   about to breach: less than a quarter of the time is left (response or solution)
 *   breached: the moment has passed. A missed resolve target also escalates the ticket: it is marked, and a note is added to its thread.
 * Each event is announced once (the key holds the due moment, so a ticket whose clock was paused is announced again for its new moment).
 * Returns the number of new notifications.
 */
function sla_check(PDO $pdo): int
{
    if (!sla_enabled($pdo)) { return 0; }
    $new = 0;
    $rows = $pdo->query("SELECT id, reference, subject, status, priority, is_demo, created_at, respond_due_at, resolve_due_at, first_response_at, resolved_at, waiting_since, escalated_at
        FROM tickets WHERE status NOT IN ('resolved','closed','waiting') AND resolve_due_at IS NOT NULL ORDER BY resolve_due_at LIMIT 500")->fetchAll();
    foreach ($rows as $t) {
        $s = sla_state($t);
        $link = 'tickets/ticket.php?id=' . (int) $t['id'];
        $demo = (bool) $t['is_demo'];
        foreach (['respond' => 'first response', 'resolve' => 'solution'] as $what => $noun) {
            $p = $s[$what];
            $due = (string) $t[$what . '_due_at'];
            if ($p['state'] === 'warn') {
                $new += notify_add($pdo, 'ticket_sla', "sla-warn:$what:" . $t['id'] . ':' . $due, 'About to breach: ' . $t['reference'], 'The ' . $noun . ' is due in ' . sla_fmt_minutes((int) ceil($p['left'] / 60)) . '. ' . $t['subject'], $link, $demo) ? 1 : 0;
            } elseif ($p['state'] === 'late') {
                $new += notify_add($pdo, 'ticket_sla', "sla-breach:$what:" . $t['id'] . ':' . $due, 'SLA breached: ' . $t['reference'], 'The ' . $noun . ' was due at ' . date('j M H:i', strtotime($due)) . '. ' . $t['subject'], $link, $demo) ? 1 : 0;
            }
        }
        if ($s['resolve']['state'] === 'late' && $t['escalated_at'] === null) {
            $mark = $pdo->prepare('UPDATE tickets SET escalated_at = NOW() WHERE id = ? AND escalated_at IS NULL');
            $mark->execute([$t['id']]);
            if ($mark->rowCount() === 1) {   // only the run that set the mark writes the note
                $pdo->prepare("INSERT INTO ticket_comments (ticket_id, author, body, is_demo, created_at) VALUES (?, 'system', ?, ?, NOW())")
                    ->execute([$t['id'], 'Escalated: the solution was due at ' . date('j M Y, H:i', strtotime((string) $t['resolve_due_at'])) . ' and the ticket is still open.', $demo ? 1 : 0]);
                if (!$demo) { log_activity('warning', 'Ticket escalated', $t['reference'] . ' · resolve target missed', 'scheduler'); }
            }
        }
    }
    return $new;
}

/** The SLA box on a ticket page: both targets with their state. Empty string for a ticket without targets. */
function sla_panel(array $t): string
{
    $s = sla_state($t);
    if ($s['respond']['state'] === 'none' && $s['resolve']['state'] === 'none') { return ''; }
    $tone = fn(string $st) => ['late' => 'text-[#FF9C8C]', 'missed' => 'text-[#FF9C8C]', 'warn' => 'text-butter', 'met' => 'text-lime'][$st] ?? 'text-ink';
    $h = '<section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="sla-h"><div class="flex items-center justify-between gap-2"><h2 id="sla-h" class="text-base font-bold">SLA</h2>'
        . ($t['escalated_at'] !== null ? '<span class="inline-flex items-center rounded-full bg-coral/15 px-2.5 py-0.5 text-xs font-semibold text-[#FF9C8C]">Escalated</span>' : '') . '</div><dl class="mt-4 space-y-3 text-sm">';
    foreach (['respond' => 'First response', 'resolve' => 'Solution'] as $what => $label) {
        $due = $t[$what . '_due_at'];
        if ($due === null) { continue; }
        $h .= '<div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">' . $label . '</dt><dd class="mt-1 font-semibold ' . $tone($s[$what]['state']) . '">' . e(sla_text($s[$what], $what)) . '</dd>'
            . '<dd class="text-xs text-soft">Target ' . e(date('j M Y, H:i', strtotime((string) $due))) . '</dd></div>';
    }
    return $h . '</dl></section>';
}
