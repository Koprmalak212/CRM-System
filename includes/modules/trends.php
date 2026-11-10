<?php
declare(strict_types=1);

/*
 * Trends for the Overview (stage 9). Everything is a query over existing tables; the charts are plain HTML like the activity chart
 * (no chart library). Each series is a list of ['key' => ..., 'label' => ..., 'value' => number|null] for every period in the range,
 * with 0 for periods that had nothing, so the chart always shows the whole range.
 */

const TREND_MONTHS = 12;
const TREND_WEEKS = 12;

/** The last $n calendar months, oldest first: [['key' => '2026-11', 'label' => 'Nov 26'], ...]. */
function trend_month_keys(int $n = TREND_MONTHS, ?DateTimeImmutable $now = null): array
{
    $first = ($now ?? new DateTimeImmutable('today'))->modify('first day of this month');
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $d = $first->modify("-$i month");
        $out[] = ['key' => $d->format('Y-m'), 'label' => $d->format('M y')];
    }
    return $out;
}

/** The last $n ISO weeks (Monday to Sunday), oldest first. Keys match MySQL YEARWEEK(date, 3). */
function trend_week_keys(int $n = TREND_WEEKS, ?DateTimeImmutable $now = null): array
{
    $monday = ($now ?? new DateTimeImmutable('today'))->modify('monday this week');
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $d = $monday->modify("-$i week");
        $out[] = ['key' => $d->format('oW'), 'label' => 'Wk ' . $d->format('W'), 'from' => $d->format('j M')];
    }
    return $out;
}

/** Fills every period of the range from a key => value map. */
function trend_fill(array $periods, array $byKey, ?float $missing = 0.0): array
{
    foreach ($periods as &$p) { $p['value'] = array_key_exists($p['key'], $byKey) ? (float) $byKey[$p['key']] : $missing; }
    return $periods;
}

/** Payments received per month, in cents, last 12 months. */
function trend_revenue(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $months = trend_month_keys(TREND_MONTHS, $now);
    $s = $pdo->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') k, SUM(amount_cents) v FROM payments WHERE paid_at >= ? GROUP BY k");
    $s->execute([$months[0]['key'] . '-01']);
    return trend_fill($months, $s->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** New users per month, last 12 months. */
function trend_new_users(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $months = trend_month_keys(TREND_MONTHS, $now);
    $s = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') k, COUNT(*) v FROM users WHERE created_at >= ? GROUP BY k");
    $s->execute([$months[0]['key'] . '-01']);
    return trend_fill($months, $s->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Tickets opened per week, last 12 weeks. */
function trend_ticket_volume(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $weeks = trend_week_keys(TREND_WEEKS, $now);
    $s = $pdo->query("SELECT YEARWEEK(created_at, 3) k, COUNT(*) v FROM tickets WHERE created_at >= '" . ($now ?? new DateTimeImmutable('today'))->modify('monday this week')->modify('-' . (TREND_WEEKS - 1) . ' week')->format('Y-m-d') . "' GROUP BY k");
    return trend_fill($weeks, $s->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Average time tickets resolved in each week had been open, in hours; null for a week in which nothing was resolved. */
function trend_ticket_open_time(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $weeks = trend_week_keys(TREND_WEEKS, $now);
    $from = ($now ?? new DateTimeImmutable('today'))->modify('monday this week')->modify('-' . (TREND_WEEKS - 1) . ' week')->format('Y-m-d');
    $s = $pdo->prepare("SELECT YEARWEEK(resolved_at, 3) k, AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60 v FROM tickets WHERE resolved_at IS NOT NULL AND resolved_at >= ? GROUP BY k");
    $s->execute([$from]);
    return trend_fill($weeks, $s->fetchAll(PDO::FETCH_KEY_PAIR), null);
}

function trend_has_data(array $series): bool
{
    foreach ($series as $p) { if ($p['value'] !== null && $p['value'] > 0) { return true; } }
    return false;
}

function trend_fmt_hours(float $h): string
{
    return $h >= 48 ? number_format($h / 24, 1) . ' days' : number_format($h, 1) . ' h';
}

/**
 * One chart card. $fmt turns a value into text (shown on hover or focus and in the table). Missing values (null) show as an empty slot.
 * Accessible: the chart has a text label with the peak and the total, every column has the same text, and "View as table" lists all values.
 */
function trend_chart(string $id, string $title, string $sub, array $series, callable $fmt, string $valueHeading, string $summary): string
{
    $h = '<section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="' . e($id) . '-h"><h3 id="' . e($id) . '-h" class="text-base font-bold tracking-tight">' . e($title) . '</h3><p class="text-sm text-soft">' . e($sub) . '</p>';
    if (!trend_has_data($series)) {
        return $h . '<div class="mt-5 grid h-48 place-items-center rounded-xl border border-dashed border-line px-4 text-center text-sm text-soft" data-empty="1">No data yet. Numbers appear here as soon as there is something to count.</div></section>';
    }
    $vals = array_map(fn($p) => (float) ($p['value'] ?? 0), $series);
    $max = max(1.0, ...$vals);
    $n = count($series);
    $h .= '<div class="chart mt-5 flex h-48 items-end gap-1.5 border-b border-line" role="img" aria-label="' . e($title . '. ' . $summary) . '">';
    foreach ($series as $i => $p) {
        $v = $p['value'];
        $text = $p['label'] . ': ' . ($v === null ? 'no data' : $fmt($v));
        $pct = $v === null ? 0 : max($v > 0 ? 2 : 0, $v / $max * 100);
        $h .= '<div class="vbar grow col flex h-full min-w-0 flex-1 flex-col justify-end" tabindex="0" style="animation-delay:' . ($i * 28) . 'ms" data-tip="' . e($text) . '" aria-label="' . e($text) . '">'
            . '<div class="rounded-t-[3px] ' . ($v === null ? 'bg-transparent' : ($v > 0 ? 'bg-lime' : 'bg-line')) . '" style="height:' . round($pct, 1) . '%"></div></div>';
    }
    $h .= '</div><div class="mt-2 flex justify-between text-xs text-soft"><span>' . e($series[0]['label']) . '</span><span>' . e($series[intdiv($n - 1, 2)]['label']) . '</span><span>' . e($series[$n - 1]['label']) . '</span></div>';
    $h .= '<details class="mt-3 text-sm"><summary class="cursor-pointer font-semibold underline decoration-lime decoration-2 underline-offset-4">View as table</summary>'
        . '<table class="mt-2 w-full text-left text-sm" data-plain><caption class="sr-only">' . e($title) . '</caption><thead class="text-xs text-soft"><tr><th scope="col" class="py-1.5 pr-4 font-semibold">Period</th><th scope="col" class="py-1.5 text-right font-semibold">' . e($valueHeading) . '</th></tr></thead><tbody class="divide-y divide-line">';
    foreach ($series as $p) {
        $h .= '<tr><th scope="row" class="py-1.5 pr-4 font-normal text-soft">' . e($p['label']) . '</th><td class="py-1.5 text-right tabular-nums notranslate">' . ($p['value'] === null ? '–' : e($fmt($p['value']))) . '</td></tr>';
    }
    return $h . '</tbody></table></details></section>';
}

/** The four trend cards for the Overview. */
function trends_section(PDO $pdo): string
{
    $rev = trend_revenue($pdo);
    $usr = trend_new_users($pdo);
    $vol = trend_ticket_volume($pdo);
    $open = trend_ticket_open_time($pdo);
    $sumRev = (int) array_sum(array_column($rev, 'value'));
    $sumUsr = (int) array_sum(array_column($usr, 'value'));
    $sumVol = (int) array_sum(array_column($vol, 'value'));
    $openVals = array_filter(array_column($open, 'value'), fn($v) => $v !== null);
    $out = '<div class="mt-6"><h2 class="rise text-lg font-bold tracking-tight">Trends</h2><p class="rise text-sm text-soft">Hover or focus a bar for its value. Every chart has a table underneath.</p></div>'
        . '<div class="mt-4 grid items-start gap-6 lg:grid-cols-2">';
    $out .= trend_chart('tr-rev', 'Revenue per month', 'Payments received, last 12 months', $rev, fn($v) => money((int) round($v)), 'Received', 'Total ' . money($sumRev) . ' over the last 12 months, best month ' . money((int) max(array_column($rev, 'value'))) . '.');
    $out .= trend_chart('tr-usr', 'New users per month', 'Accounts created, last 12 months', $usr, fn($v) => (int) $v . ' new', 'New users', $sumUsr . ' new users over the last 12 months, best month ' . (int) max(array_column($usr, 'value')) . '.');
    $out .= trend_chart('tr-vol', 'Tickets opened per week', 'Last 12 weeks', $vol, fn($v) => (int) $v . ' tickets', 'Tickets', $sumVol . ' tickets over the last 12 weeks, busiest week ' . (int) max(array_column($vol, 'value')) . '.');
    $out .= trend_chart('tr-open', 'Average time to resolve', 'Hours a ticket was open, by the week it was resolved', $open, 'trend_fmt_hours', 'Average open time', $openVals ? 'Average ' . trend_fmt_hours(array_sum($openVals) / count($openVals)) . ' across the weeks with resolved tickets.' : 'No tickets were resolved in this period.');
    return $out . '</div>';
}
