<?php
declare(strict_types=1);

/*
 * Calls and text messages: matching a phone number to a person and a company, the list query with its filters, and the small panels on the
 * company and person pages. The data itself is put in the table by includes/modules/connectors.php.
 */

const COMM_RETENTION_MONTHS = 12;   // calls and messages hold phone numbers (and sometimes text): removed by bin/purge.php after this long

const COMM_SERVICES = ['threecx' => '3CX', 'yeastar' => 'Yeastar', 'twilio' => 'Twilio'];

/**
 * A service as a small badge: its logo when there is a file for it (assets/img/services/<service>.svg, .png or .webp), otherwise a plain rounded
 * mark with the name, so every service looks the same. $name overrides the label (Short.io is not in COMM_SERVICES). The logo files are the
 * logos of the services and belong to their owners: put your own copy there. A file name is never taken from the request.
 */
function service_badge(string $provider, ?string $name = null): string
{
    $name ??= COMM_SERVICES[$provider] ?? ucfirst($provider);
    $logo = '';
    if (preg_match('/^[a-z0-9]{2,20}$/', $provider)) {
        foreach (['svg', 'png', 'webp'] as $ext) {
            if (is_file(dirname(__DIR__, 2) . '/assets/img/services/' . $provider . '.' . $ext)) { $logo = 'assets/img/services/' . $provider . '.' . $ext; break; }
        }
    }
    $mark = $logo !== ''
        ? '<span class="inline-grid h-7 shrink-0 place-items-center rounded-md bg-navy px-1.5"><img src="' . e($logo) . '" alt="" width="64" height="20" class="h-5 w-auto max-w-[6rem] object-contain"></span>'   // on a dark chip: many logos have white parts that would vanish on the light theme
        : '<span class="grid h-5 min-w-5 shrink-0 place-items-center rounded-md bg-ink/10 px-1 text-[10px] font-bold leading-none" aria-hidden="true">' . e(mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: $name, 0, 3))) . '</span>';
    return '<span class="inline-flex items-center gap-2 whitespace-nowrap">' . $mark . '<span' . ($logo !== '' ? ' class="sr-only"' : '') . '>' . e($name) . '</span></span>';   // next to a logo the name is for screen readers only: the logo says it
}
const COMM_KINDS = ['call' => 'Calls', 'sms' => 'Messages'];
const COMM_DIRECTIONS = ['in' => 'Incoming', 'out' => 'Outgoing', 'internal' => 'Internal'];
const COMM_STATUSES = ['answered' => 'Answered', 'missed' => 'Missed', 'busy' => 'Busy', 'failed' => 'Failed', 'delivered' => 'Delivered', 'sent' => 'Sent', 'received' => 'Received', 'queued' => 'Queued', 'other' => 'Other'];

/** The last 9 digits of a phone number, so +31 6 1234 5678, 0031612345678 and 06 1234 5678 are the same. Short numbers (extensions) give "". */
function phone_key(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';
    return strlen($digits) >= 7 ? substr($digits, -9) : '';
}

/**
 * Matches calls and messages that have no person or company yet (from the last 90 days) with the phone numbers of people and companies.
 * A number that belongs to exactly one person gives that person and their company; a number shared by colleagues of one company gives
 * the company; a company's own phone number gives the company. Anything unclear stays unmatched. Returns the number of rows matched.
 */
function comm_match(PDO $pdo): int
{
    $users = []; $companies = [];
    foreach ($pdo->query("SELECT id, company_id, phone FROM users WHERE phone <> ''")->fetchAll() as $u) {
        $k = phone_key((string) $u['phone']);
        if ($k !== '') { $users[$k][] = [(int) $u['id'], $u['company_id'] !== null ? (int) $u['company_id'] : null]; }
    }
    foreach ($pdo->query("SELECT id, phone FROM companies WHERE phone <> ''")->fetchAll() as $c) {
        $k = phone_key((string) $c['phone']);
        if ($k !== '') { $companies[$k][] = (int) $c['id']; }
    }
    $rows = $pdo->query("SELECT id, party_key FROM comm_log WHERE user_id IS NULL AND company_id IS NULL AND party_key <> '' AND started_at >= NOW() - INTERVAL 90 DAY LIMIT 5000")->fetchAll();
    $upd = $pdo->prepare('UPDATE comm_log SET user_id = ?, company_id = ? WHERE id = ? AND user_id IS NULL AND company_id IS NULL');
    $n = 0;
    foreach ($rows as $r) {
        $k = (string) $r['party_key']; $uid = null; $cid = null;
        if (isset($users[$k])) {
            if (count($users[$k]) === 1) { [$uid, $cid] = $users[$k][0]; }
            else { $cs = array_unique(array_column($users[$k], 1)); if (count($cs) === 1 && $cs[0] !== null) { $cid = $cs[0]; } }
        }
        if ($uid === null && $cid === null && isset($companies[$k]) && count(array_unique($companies[$k])) === 1) { $cid = $companies[$k][0]; }
        if ($uid !== null || $cid !== null) { $upd->execute([$uid, $cid, (int) $r['id']]); $n += $upd->rowCount(); }
    }
    return $n;
}

/** Removes calls and messages older than the retention period. */
function comm_purge(PDO $pdo): int
{
    $s = $pdo->prepare('DELETE FROM comm_log WHERE started_at < ?');
    $s->execute([date('Y-m-d H:i:s', strtotime('-' . COMM_RETENTION_MONTHS . ' months'))]);
    return $s->rowCount();
}

/**
 * The WHERE part and arguments for the Calls page. Every value is checked against a fixed list or cast to a number.
 * @param array<string,mixed> $q the query string
 * @return array{0: string, 1: list<mixed>, 2: array<string,string>} where, arguments, the cleaned filter values
 */
function comm_filters(array $q): array
{
    $w = []; $a = []; $f = [];
    $q = array_map(static fn($v) => is_string($v) ? $v : '', $q);   // an array in the query string (?kind[]=x) counts as empty
    $pick = static fn(string $k, array $allowed): string => isset($q[$k]) && is_string($q[$k]) && array_key_exists($q[$k], $allowed) ? $q[$k] : '';
    $f['kind'] = $pick('kind', COMM_KINDS);
    $f['service'] = $pick('service', COMM_SERVICES);
    $f['direction'] = $pick('direction', COMM_DIRECTIONS);
    $f['status'] = $pick('status', COMM_STATUSES);
    if ($f['kind'] !== '') { $w[] = 'c.kind = ?'; $a[] = $f['kind']; }
    if ($f['service'] !== '') { $w[] = 'c.provider = ?'; $a[] = $f['service']; }
    if ($f['direction'] !== '') { $w[] = 'c.direction = ?'; $a[] = $f['direction']; }
    if ($f['status'] !== '') { $w[] = 'c.status = ?'; $a[] = $f['status']; }
    $co = (string) ($q['company'] ?? '');
    if ($co === 'none') { $w[] = 'c.company_id IS NULL'; $f['company'] = 'none'; }
    elseif (ctype_digit($co) && (int) $co > 0) { $w[] = 'c.company_id = ?'; $a[] = (int) $co; $f['company'] = $co; }
    else { $f['company'] = ''; }
    $sf = mb_substr(trim((string) ($q['staff'] ?? '')), 0, 120);   // a colleague, by the name the phone system gives
    if ($sf !== '') { $w[] = 'c.staff_name = ?'; $a[] = $sf; }
    $f['staff'] = $sf;
    $who = mb_substr(trim((string) ($q['who'] ?? '')), 0, 120);   // a name typed or picked: a caller or colleague (as the phone system names them) or a person in the CRM
    if ($who !== '') {
        $lw = '%' . addcslashes($who, '%_\\') . '%';
        $w[] = '(c.remote_name LIKE ? OR c.staff_name LIKE ? OR c.user_id IN (SELECT id FROM users WHERE name LIKE ?))';
        array_push($a, $lw, $lw, $lw);
    }
    $f['who'] = $who;
    $pe = (string) ($q['person'] ?? '');
    if (ctype_digit($pe) && (int) $pe > 0) { $w[] = 'c.user_id = ?'; $a[] = (int) $pe; $f['person'] = $pe; } else { $f['person'] = ''; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $d = (string) ($q[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false && date('Y-m-d', strtotime($d)) === $d) {
            $w[] = 'c.started_at ' . $op . ' ?'; $a[] = $d . ($k === 'from' ? ' 00:00:00' : ' 23:59:59'); $f[$k] = $d;
        } else { $f[$k] = ''; }
    }
    $s = trim((string) ($q['q'] ?? ''));
    if ($s !== '') {
        $s = mb_substr($s, 0, 60); $f['q'] = $s;
        $like = '%' . addcslashes($s, '%_\\') . '%';
        $w[] = '(c.from_number LIKE ? OR c.to_number LIKE ? OR c.body LIKE ? OR c.remote_name LIKE ? OR c.staff_name LIKE ?)'; array_push($a, $like, $like, $like, $like, $like);
    } else { $f['q'] = ''; }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a, $f];
}

/** A small box with the latest calls and messages of a person ('user_id') or a company ('company_id'), for their pages. */
function comm_panel(PDO $pdo, string $column, int $id, string $title): string
{
    if (!in_array($column, ['user_id', 'company_id'], true)) { return ''; }
    $s = $pdo->prepare("SELECT c.* FROM comm_log c WHERE c.$column = ? ORDER BY c.started_at DESC, c.id DESC LIMIT 5");
    $s->execute([$id]);
    $rows = $s->fetchAll();
    $t = $pdo->prepare("SELECT COUNT(*) FROM comm_log WHERE $column = ?");
    $t->execute([$id]);
    $total = (int) $t->fetchColumn();
    $all = 'calls/calls.php?' . ($column === 'user_id' ? 'person=' : 'company=') . $id;
    $h = '<section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="comm-' . $column . '-h"><div class="flex items-center justify-between gap-3">'
        . '<h2 id="comm-' . $column . '-h" class="text-lg font-bold tracking-tight">' . e($title) . '</h2>'
        . ($total > 0 ? '<a href="' . e($all) . '" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">All ' . $total . '</a>' : '') . '</div>';
    if (!$rows) { return $h . '<p class="mt-3 text-sm text-soft">No calls or messages matched yet. They appear here when a phone system or Twilio is connected and the number is on file.</p></section>'; }
    $h .= '<ul class="mt-3 divide-y divide-line text-sm">';
    foreach ($rows as $r) {
        $dir = COMM_DIRECTIONS[$r['direction']] ?? $r['direction'];
        $what = $r['kind'] === 'sms' ? 'Message' : 'Call';
        $h .= '<li class="flex flex-wrap items-center justify-between gap-2 py-2.5"><span><span class="font-semibold">' . e($dir . ' ' . strtolower($what)) . '</span>'
            . ' <span class="text-soft">' . e($r['direction'] === 'in' ? $r['from_number'] : $r['to_number']) . '</span></span>'
            . '<span class="flex items-center gap-2 text-soft">' . ($r['kind'] === 'call' && (int) $r['duration_s'] > 0 ? e(duration((int) $r['duration_s'])) . ' · ' : '') . e(ago($r['started_at'])) . ' ' . badge((string) $r['status']) . '</span></li>';
    }
    return $h . '</ul></section>';
}

/** A small box with the latest short links of a company, for its page. */
function shortlinks_panel(PDO $pdo, int $companyId): string
{
    $s = $pdo->prepare('SELECT * FROM short_links WHERE company_id = ? ORDER BY COALESCE(created_remote, synced_at) DESC, id DESC LIMIT 5');
    $s->execute([$companyId]);
    $rows = $s->fetchAll();
    $t = $pdo->prepare('SELECT COUNT(*) FROM short_links WHERE company_id = ?');
    $t->execute([$companyId]);
    $total = (int) $t->fetchColumn();
    $h = '<section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="sl-h"><div class="flex items-center justify-between gap-3"><h2 id="sl-h" class="text-lg font-bold tracking-tight">Short links</h2>'
        . '<a href="links/links.php' . ($total > 0 ? '?company=' . $companyId : '') . '" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">' . ($total > 0 ? 'All ' . $total : 'Make one') . '</a></div>';
    if (!$rows) { return $h . '<p class="mt-3 text-sm text-soft">No short links for this company yet.</p></section>'; }
    $h .= '<ul class="mt-3 divide-y divide-line text-sm">';
    foreach ($rows as $r) {
        $h .= '<li class="py-2.5"><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="' . e((string) $r['short_url']) . '" target="_blank" rel="noopener noreferrer">' . e(preg_replace('~^https?://~i', '', (string) $r['short_url'])) . '</a>'
            . ($r['title'] !== '' ? ' <span class="text-soft">' . e((string) $r['title']) . '</span>' : '') . '</li>';
    }
    return $h . '</ul></section>';
}
