<?php
declare(strict_types=1);

const APPT_STATUSES = ['scheduled' => 'Scheduled', 'done' => 'Done', 'cancelled' => 'Cancelled'];

function appointment_find(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT a.*, c.name AS company_name, u.name AS user_name FROM appointments a JOIN companies c ON c.id = a.company_id LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** "2026-11-10T14:30" (a datetime-local field) or "2026-11-10 14:30[:00]" to "Y-m-d H:i:s"; null when it is not a real moment between 2000 and 2100. */
function appt_parse_time(string $s): ?string
{
    $s = trim(str_replace('T', ' ', $s));
    if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) { return null; }
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00'));
    if ($d === false || $d->format('Y-m-d H:i:s') !== $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00')) { return null; }
    return ($d->format('Y') >= 2000 && $d->format('Y') <= 2100) ? $d->format('Y-m-d H:i:s') : null;
}

/**
 * Validates the appointment form. A contact person must belong to the chosen company.
 * Returns [clean values, errors].
 */
function appointment_clean(PDO $pdo, array $in): array
{
    $err = [];
    $d = [
        'company_id' => (int) ($in['company_id'] ?? 0), 'user_id' => (int) ($in['user_id'] ?? 0),
        'title' => trim((string) ($in['title'] ?? '')), 'location' => trim((string) ($in['location'] ?? '')),
        'description' => trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? ''))), 'status' => (string) ($in['status'] ?? 'scheduled'),
    ];
    $start = appt_parse_time((string) ($in['starts_at'] ?? ''));
    $end = appt_parse_time((string) ($in['ends_at'] ?? ''));
    $c = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ?'); $c->execute([$d['company_id']]);
    if (!(int) $c->fetchColumn()) { $err[] = 'Choose a company.'; }
    if ($d['title'] === '' || mb_strlen($d['title']) > 160) { $err[] = 'Enter a title of up to 160 characters.'; }
    if (mb_strlen($d['location']) > 160) { $err[] = 'The location can be at most 160 characters.'; }
    if (mb_strlen($d['description']) > 1000) { $err[] = 'The description can be at most 1000 characters.'; }
    if (!isset(APPT_STATUSES[$d['status']])) { $err[] = 'Choose a status.'; }
    if ($start === null) { $err[] = 'Enter a valid start date and time.'; }
    if ($end === null) { $err[] = 'Enter a valid end date and time.'; }
    if ($start !== null && $end !== null && $end < $start) { $err[] = 'The end can not be before the start.'; }
    if ($start !== null && $end !== null && strtotime($end) - strtotime($start) > 14 * 86400) { $err[] = 'An appointment can last at most 14 days.'; }
    if ($d['user_id'] > 0) {
        $u = $pdo->prepare('SELECT company_id FROM users WHERE id = ?'); $u->execute([$d['user_id']]);
        $row = $u->fetch();
        if (!$row) { $err[] = 'That contact person does not exist.'; }
        elseif ((int) $row['company_id'] !== $d['company_id']) { $err[] = 'The contact person does not belong to that company.'; }
    }
    $d['starts_at'] = $start; $d['ends_at'] = $end;
    return [$d, $err];
}

/** Appointments in a date range (inclusive start, exclusive end), optionally for one company, with names. Newest rule: by start time. */
function appointments_between(PDO $pdo, string $from, string $to, int $companyId = 0): array
{
    $sql = 'SELECT a.*, c.name AS company_name, u.name AS user_name FROM appointments a JOIN companies c ON c.id = a.company_id LEFT JOIN users u ON u.id = a.user_id WHERE a.starts_at < ? AND a.ends_at >= ?' . ($companyId > 0 ? ' AND a.company_id = ?' : '') . ' ORDER BY a.starts_at, a.id';
    $s = $pdo->prepare($sql);
    $s->execute($companyId > 0 ? [$to, $from, $companyId] : [$to, $from]);
    return $s->fetchAll();
}

/** Month as a list of weeks (Monday first), each a list of 7 'Y-m-d' dates, covering the whole month. */
function calendar_weeks(string $month): array
{
    $first = new DateTimeImmutable($month . '-01');
    $start = $first->modify('monday this week');
    if ($first->format('N') === '1') { $start = $first; }
    $last = $first->modify('last day of this month');
    $weeks = [];
    for ($d = $start; $d <= $last; $d = $d->modify('+7 day')) {
        $w = [];
        for ($i = 0; $i < 7; $i++) { $w[] = $d->modify("+$i day")->format('Y-m-d'); }
        $weeks[] = $w;
    }
    return $weeks;
}

function appt_time_range(array $a): string
{
    $s = strtotime($a['starts_at']); $e = strtotime($a['ends_at']);
    return date('Y-m-d', $s) === date('Y-m-d', $e) ? date('H:i', $s) . '–' . date('H:i', $e) : date('j M H:i', $s) . ' – ' . date('j M H:i', $e);
}

/** Upcoming appointments of a company, as a small panel. $highlightUser marks the ones where that person is the contact. */
function appointments_panel(PDO $pdo, int $companyId, string $title, string $newLink, int $highlightUser = 0, int $limit = 6): string
{
    $s = $pdo->prepare("SELECT a.*, u.name AS user_name FROM appointments a LEFT JOIN users u ON u.id = a.user_id WHERE a.company_id = ? AND a.ends_at >= NOW() AND a.status <> 'cancelled' ORDER BY a.starts_at LIMIT " . (int) $limit);
    $s->execute([$companyId]);
    $rows = $s->fetchAll();
    $h = '<section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="ap-h"><div class="flex items-center justify-between gap-3"><h2 id="ap-h" class="text-base font-bold">' . e($title) . '</h2><a href="' . e($newLink) . '" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">New appointment</a></div>';
    if (!$rows) { return $h . '<p class="mt-3 text-sm text-soft">No upcoming appointments.</p><p class="mt-2 text-sm"><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="calendar/calendar.php?company=' . $companyId . '">Open the calendar</a></p></section>'; }
    $h .= '<ul class="mt-4 divide-y divide-line">';
    foreach ($rows as $r) {
        $mine = $highlightUser > 0 && (int) $r['user_id'] === $highlightUser;
        $h .= '<li class="py-2.5"><div class="flex items-center justify-between gap-2"><a href="calendar/appointment_edit.php?id=' . (int) $r['id'] . '" class="min-w-0 truncate text-sm font-semibold hover:underline">' . e($r['title']) . '</a>' . ($mine ? '<span class="shrink-0 rounded-full bg-lime px-2 py-0.5 text-xs font-bold text-navy">You are the contact</span>' : '') . '</div>'
            . '<p class="mt-0.5 text-xs text-soft"><span class="notranslate">' . e(date('D j M Y', strtotime($r['starts_at']))) . ' · ' . e(appt_time_range($r)) . '</span>' . ($r['location'] !== '' ? ' · ' . e($r['location']) : '') . ($r['user_name'] !== null && !$mine ? ' · with ' . e($r['user_name']) : '') . '</p></li>';
    }
    return $h . '</ul><p class="mt-3 text-sm"><a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="calendar/calendar.php?company=' . $companyId . '">Open the calendar</a></p></section>';
}

function ics_escape(string $s): string { return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', ''], $s); }

/** Folds a content line at 75 bytes as iCalendar requires (continuation lines start with a space). */
function ics_fold(string $line): string
{
    $out = ''; $len = 0;
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $b = strlen($ch);
        if ($len + $b > 74) { $out .= "\r\n "; $len = 1; }
        $out .= $ch; $len += $b;
    }
    return $out;
}

/** An iCalendar (.ics) file for some appointments (floating local times, so they show at the same clock time everywhere). */
function appointments_ics(array $rows, string $name): string
{
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Telecomlijn Admin//Calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . ics_escape($name)];
    foreach ($rows as $a) {
        $L[] = 'BEGIN:VEVENT';
        $L[] = 'UID:appointment-' . (int) $a['id'] . '@telecomlijn-admin';
        $L[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $L[] = 'DTSTART:' . date('Ymd\THis', strtotime($a['starts_at']));
        $L[] = 'DTEND:' . date('Ymd\THis', strtotime($a['ends_at']));
        $L[] = 'SUMMARY:' . ics_escape($a['company_name'] . ': ' . $a['title']);
        if ($a['location'] !== '') { $L[] = 'LOCATION:' . ics_escape($a['location']); }
        $desc = trim(($a['user_name'] ? 'Contact: ' . $a['user_name'] . "\n" : '') . $a['description']);
        if ($desc !== '') { $L[] = 'DESCRIPTION:' . ics_escape($desc); }
        $L[] = 'STATUS:' . ['scheduled' => 'CONFIRMED', 'done' => 'CONFIRMED', 'cancelled' => 'CANCELLED'][$a['status']];
        $L[] = 'END:VEVENT';
    }
    $L[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('ics_fold', $L)) . "\r\n";
}
