<?php
declare(strict_types=1);

require __DIR__ . '/security.php';

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}


function flash(?string $message = null, string $kind = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['m' => $message, 'k' => $kind];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return intdiv($diff, 60) . ' min ago';
    if ($diff < 86400) return intdiv($diff, 3600) . ' h ago';
    if ($diff < 86400 * 30) return intdiv($diff, 86400) . ' d ago';
    return date('j M Y', strtotime($datetime));
}

function duration(int $seconds): string
{
    if ($seconds < 60) return $seconds . ' s';
    if ($seconds < 3600) return intdiv($seconds, 60) . ' min ' . ($seconds % 60) . ' s';
    return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
}

function bytes_h(int|float $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return ($i === 0 ? (string) (int) $b : number_format($b, $b < 10 ? 1 : 0)) . ' ' . $u[$i];
}

function icon(string $name, string $cls = 'h-[18px] w-[18px]'): string
{
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'network' => '<rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/>',
        'file' => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'settings' => '<line x1="21" y1="4" x2="14" y2="4"/><line x1="10" y1="4" x2="3" y2="4"/><line x1="21" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="3" y2="12"/><line x1="21" y1="20" x2="16" y2="20"/><line x1="12" y1="20" x2="3" y2="20"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="16" y1="18" x2="16" y2="22"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'left' => '<polyline points="15 18 9 12 15 6"/>',
        'right' => '<polyline points="9 18 15 12 9 6"/>',
        'sort' => '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>',
        'up' => '<path d="m18 15-6-6-6 6"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'menu' => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'arrow' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/>',
        'gauge' => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
        'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
    ];
    return '<svg class="' . $cls . ' icon-' . $name . ' shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function badge(string $status): string
{
    $map = [
        'active' => ['ok', 'Active'], 'completed' => ['ok', 'Completed'], 'ready' => ['ok', 'Ready'], 'ok' => ['ok', 'Operational'],
        'pending' => ['wait', 'Pending'], 'running' => ['wait', 'Running'], 'idle' => ['wait', 'Idle'], 'scheduled' => ['wait', 'Scheduled'],
        'info' => ['info', 'Info'],
        'warning' => ['warn', 'Warning'], 'degraded' => ['warn', 'Degraded'],
        'failed' => ['bad', 'Failed'], 'suspended' => ['bad', 'Suspended'], 'error' => ['bad', 'Error'],
        'up' => ['ok', 'Up'], 'slow' => ['warn', 'Slow'], 'down' => ['bad', 'Down'], 'unknown' => ['info', 'Not checked yet'], 'admin' => ['ok', 'Admin'], 'user' => ['info', 'User'],
        'paid' => ['ok', 'Paid'], 'overdue' => ['bad', 'Overdue'], 'due' => ['wait', 'Due soon'], 'open' => ['info', 'Open'],
        'critical' => ['bad', 'Critical'], 'flagged' => ['bad', 'Flagged'], 'outage' => ['bad', 'Outage'],
    ];
    [$tone, $label] = $map[$status] ?? ['info', ucfirst($status)];
    $cls = [
        'ok' => 'bg-lime text-navy',
        'wait' => 'bg-butter text-navy',
        'info' => 'bg-white/10 text-ink',
        'warn' => 'bg-[#FFD9A0] text-[#5E3200]',
        'bad' => 'bg-[#FFD3CC] text-[#7E160A]',
    ][$tone];
    return '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ' . $cls . '"><span class="h-1.5 w-1.5 rounded-full bg-current' . (in_array($tone, ['bad', 'warn'], true) ? ' dot-pulse' : '') . '"></span>' . e($label) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return strtoupper(substr($parts[0] ?? '?', 0, 1) . substr($parts[1] ?? '', 0, 1));
}

function qs(array $base, array $override = []): string
{
    return '?' . http_build_query(array_filter(array_merge($base, $override), fn($v) => $v !== '' && $v !== null));
}

function th_sort(string $label, string $col, string $cur, string $dir, array $base, string $align = 'left'): string
{
    $next = ($cur === $col && $dir === 'asc') ? 'desc' : 'asc';
    $active = $cur === $col;
    $ic = $active ? ($dir === 'asc' ? 'up' : 'down') : 'sort';
    $aria = $active ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $url = qs($base, ['sort' => $col, 'dir' => $next, 'page' => 1]);
    return '<th scope="col" aria-sort="' . $aria . '" class="px-4 py-3 text-' . $align . ' font-semibold"><a href="' . e($url) . '" class="inline-flex items-center gap-1 rounded hover:text-ink ' . ($active ? 'text-ink' : '') . '">' . e($label) . icon($ic, 'h-3.5 w-3.5 ' . ($active ? '' : 'opacity-50')) . '</a></th>';
}

function pagination(int $total, int $per, int $page, array $base): string
{
    if ($total === 0) return '';
    $pages = max(1, (int) ceil($total / $per));
    $from = ($page - 1) * $per + 1;
    $to = min($total, $page * $per);
    $btn = 'inline-flex h-9 items-center gap-1 rounded-lg border border-line bg-panel px-3 text-sm font-medium text-ink hover:bg-butter-50';
    $dis = 'inline-flex h-9 cursor-not-allowed items-center gap-1 rounded-lg border border-dashed border-line px-3 text-sm font-medium text-soft';
    $prev = $page > 1 ? '<a class="' . $btn . '" href="' . e(qs($base, ['page' => $page - 1])) . '">' . icon('left', 'h-4 w-4') . 'Previous</a>' : '<span aria-disabled="true" class="' . $dis . '">' . icon('left', 'h-4 w-4') . 'Previous</span>';
    $next = $page < $pages ? '<a class="' . $btn . '" href="' . e(qs($base, ['page' => $page + 1])) . '">Next' . icon('right', 'h-4 w-4') . '</a>' : '<span aria-disabled="true" class="' . $dis . '">Next' . icon('right', 'h-4 w-4') . '</span>';
    return '<nav class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3" aria-label="Pagination"><p class="text-sm text-soft tabular-nums">Showing ' . $from . '–' . $to . ' of ' . number_format($total) . '</p><div class="flex items-center gap-2">' . $prev . '<span class="px-2 text-sm text-soft tabular-nums">Page ' . $page . ' of ' . $pages . '</span>' . $next . '</div></nav>';
}

/** Audit trail. Never pass passwords or tokens in $target. Values are length-capped to the column sizes. */
function log_activity(string $level, string $action, string $target = '', ?string $actor = null): void
{
    $a = $actor ?? ($_SESSION['admin']['username'] ?? 'system');
    db()->prepare('INSERT INTO activity_logs (level, actor, action, target, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([$level, mb_substr($a, 0, 190), mb_substr($action, 0, 160), mb_substr($target, 0, 120), client_ip()]);
}

require __DIR__ . '/demo.php';

enforce_rate_limit();   // last, so every page gets the same flood protection before doing any work

/** Round profile picture, or the person's initials when they have none. Only files inside assets/avatars/ are ever used. */
function avatar_html(?string $path, string $name, string $size = 'h-9 w-9', string $text = 'text-sm'): string
{
    if ($path !== null && preg_match('#^assets/avatars/[a-z0-9._-]+\.(gif|png|jpe?g|webp)$#i', $path) && is_file(dirname(__DIR__) . '/' . $path)) {
        return '<img src="' . e($path) . '" alt="" width="64" height="64" class="' . $size . ' shrink-0 rounded-full bg-raised object-cover">';
    }
    return '<span class="grid ' . $size . ' shrink-0 place-items-center rounded-full bg-lime ' . $text . ' font-bold text-navy" aria-hidden="true">' . e(initials($name)) . '</span>';
}
