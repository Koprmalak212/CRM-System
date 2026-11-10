<?php
declare(strict_types=1);

const NOTIFY_REFRESH_SECONDS = 180;   // the checks below run at most every three minutes, whoever loads a page
const NOTIFY_KEEP_DAYS = 90;

/**
 * Where notification emails go: the "Send notifications to" setting (EMAIL_TARGET) in Settings > Email. Nothing is built into the code.
 * Only when that setting has never been saved can the EMAIL_TARGET environment variable (or config.local.php) supply it.
 * An empty saved value means: do not email.
 */
function notify_email(PDO $pdo): string
{
    $s = $pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'");
    $v = $s ? $s->fetchColumn() : false;
    if ($v !== false) { return (string) $v; }
    require_once __DIR__ . '/mailer.php';
    return (string) (mail_env()['target'] ?? '');
}

/** Stores a notification once. The unique key is the event itself, so the same event is never announced twice. */
function notify_add(PDO $pdo, string $kind, string $key, string $title, string $body, string $link, bool $demo): bool
{
    $s = $pdo->prepare('INSERT IGNORE INTO notifications (kind, dedupe_key, title, body, link, is_demo, created_at) VALUES (?,?,?,?,?,?,NOW())');
    $s->execute([$kind, mb_substr($key, 0, 120), mb_substr($title, 0, 160), mb_substr($body, 0, 300), mb_substr($link, 0, 200), $demo ? 1 : 0]);
    return $s->rowCount() === 1;
}

/**
 * Looks for things that need attention and records each one once:
 * an invoice became overdue, a backup failed, a monitored service (endpoint check or Uptime Kuma monitor) is down, an urgent ticket is open.
 * Runs at most every NOTIFY_REFRESH_SECONDS unless $force. Returns the number of new notifications.
 */
function notifications_refresh(PDO $pdo, bool $force = false): int
{
    $now = time();
    if (!$force) {
        // claim the slot atomically so two simultaneous page loads do not both run the checks
        $pdo->prepare("INSERT IGNORE INTO app_meta (k, v) VALUES ('notif_refreshed_at', '0')")->execute();
        $claim = $pdo->prepare("UPDATE app_meta SET v = ? WHERE k = 'notif_refreshed_at' AND CAST(v AS UNSIGNED) <= ?");
        $claim->execute([(string) $now, $now - NOTIFY_REFRESH_SECONDS]);
        if ($claim->rowCount() !== 1) { return 0; }
    } else {
        $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notif_refreshed_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) $now]);
    }
    $new = 0;

    // Recurring invoices that became due are created first, so an overdue one is noticed in the same pass. An error here is only logged.
    try { require_once __DIR__ . '/recurring.php'; recurring_run_due($pdo); } catch (Throwable $e) { error_log('Recurring run failed: ' . $e->getMessage()); }

    // 1. invoices past their due date with money still owed
    if (!function_exists('invoice_base_sql')) { require __DIR__ . '/invoices.php'; }
    foreach ($pdo->query("SELECT id, number, customer, balance_cents, is_demo FROM (" . invoice_base_sql() . ") t WHERE state = 'overdue' LIMIT 200") as $r) {
        $new += notify_add($pdo, 'invoice_overdue', 'invoice-overdue:' . $r['id'], 'Invoice ' . $r['number'] . ' is overdue', $r['customer'] . ' still owes ' . money((int) $r['balance_cents']) . '.', 'invoice.php?id=' . $r['id'], (bool) $r['is_demo']) ? 1 : 0;
    }
    // 2. backups that failed in the last two days
    foreach ($pdo->query("SELECT id, name, started_at, is_demo FROM backups WHERE status = 'failed' AND started_at >= NOW() - INTERVAL 48 HOUR LIMIT 50") as $r) {
        $new += notify_add($pdo, 'backup_failed', 'backup-failed:' . $r['id'], 'Backup failed', $r['name'] . ' did not complete (' . $r['started_at'] . ').', 'backups.php', (bool) $r['is_demo']) ? 1 : 0;
    }
    // 3a. endpoint checks: one notification per outage (the key holds the moment the outage began, so a second outage is announced again)
    $streak = $pdo->prepare("SELECT MIN(checked_at) FROM endpoint_checks WHERE endpoint_id = ? AND state = 'down' AND checked_at > COALESCE((SELECT MAX(checked_at) FROM endpoint_checks WHERE endpoint_id = ? AND state <> 'down'), '1970-01-01')");
    foreach ($pdo->query("SELECT id, name, last_checked_at, last_error, is_demo FROM monitored_endpoints WHERE enabled = 1 AND last_state = 'down'")->fetchAll() as $r) {
        $streak->execute([$r['id'], $r['id']]);
        $since = (string) ($streak->fetchColumn() ?: $r['last_checked_at'] ?: 'unknown');
        $new += notify_add($pdo, 'service_down', 'endpoint-down:' . $r['id'] . ':' . $since, $r['name'] . ' is down', 'The health check failed' . ($r['last_error'] !== '' ? ': ' . $r['last_error'] : '') . '.', 'settings.php?tab=integrations', (bool) $r['is_demo']) ? 1 : 0;
    }
    // 3b. Uptime Kuma monitors that went down in the last day
    foreach ($pdo->query("SELECT id, monitor_name, at FROM kuma_heartbeats WHERE important = 1 AND status = 0 AND at >= NOW() - INTERVAL 24 HOUR ORDER BY id DESC LIMIT 50") as $r) {
        $new += notify_add($pdo, 'service_down', 'kuma-down:' . $r['id'], $r['monitor_name'] . ' is down', 'Uptime Kuma reported this monitor down at ' . $r['at'] . '.', 'uptime.php', false) ? 1 : 0;
    }
    // 4. urgent tickets that are still open
    foreach ($pdo->query("SELECT id, reference, subject, is_demo FROM tickets WHERE priority = 'urgent' AND status NOT IN ('resolved','closed') LIMIT 100") as $r) {
        $new += notify_add($pdo, 'ticket_urgent', 'ticket-urgent:' . $r['id'], 'Urgent ticket ' . $r['reference'], $r['subject'], 'ticket.php?id=' . $r['id'], (bool) $r['is_demo']) ? 1 : 0;
    }
    $pdo->exec('DELETE FROM notifications WHERE created_at < NOW() - INTERVAL ' . NOTIFY_KEEP_DAYS . ' DAY');
    require_once __DIR__ . '/mailer.php';
    try { mail_notifications_digest($pdo); mail_purge($pdo); } catch (Throwable $e) { error_log('Notification email failed: ' . $e->getMessage()); }   // email trouble must never break a page
    return $new;
}

function notifications_unread(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn();
}

/** Marks one notification (id > 0) or all of them as read. Returns how many changed. */
function notifications_mark_read(PDO $pdo, int $id = 0): int
{
    $s = $id > 0 ? $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND read_at IS NULL') : $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE read_at IS NULL');
    $s->execute($id > 0 ? [$id] : []);
    return $s->rowCount();
}

const NOTIFY_ICONS = ['invoice_overdue' => 'card', 'backup_failed' => 'database', 'service_down' => 'alert', 'ticket_urgent' => 'ticket'];

/** The bell in the top bar: unread count and a small dropdown with the latest items. Works without JavaScript (a details element). */
function notifications_bell(PDO $pdo): string
{
    $unread = notifications_unread($pdo);
    $rows = $pdo->query('SELECT * FROM notifications ORDER BY created_at DESC, id DESC LIMIT 6')->fetchAll();
    $label = $unread > 0 ? 'Notifications, ' . $unread . ' unread' : 'Notifications, none unread';
    $h = '<details id="bell" class="relative"><summary class="bell-btn relative grid h-10 w-10 cursor-pointer list-none place-items-center rounded-xl hover:bg-ink/10" aria-label="' . e($label) . '"'
        . ($unread > 0 ? ' data-unread="1"' : '') . '><svg class="bell-ic h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
    if ($unread > 0) { $h .= '<span class="absolute -right-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-[#C0261B] px-1 text-[11px] font-bold leading-none text-white" aria-hidden="true">' . ($unread > 99 ? '99+' : $unread) . '</span>'; }
    $h .= '</summary><div class="absolute right-0 top-12 z-50 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-line bg-panel shadow-[0_14px_30px_-10px_rgba(0,0,0,.8)]">'
        . '<div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3"><p class="text-sm font-bold">Notifications</p>';
    if ($unread > 0) {
        $h .= '<form method="post" action="notifications.php">' . csrf_field() . '<input type="hidden" name="action" value="read_all"><input type="hidden" name="back" value="' . e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'))) . '"><button class="text-xs font-semibold underline decoration-lime decoration-2 underline-offset-4">Mark all read</button></form>';
    }
    $h .= '</div>';
    if (!$rows) { $h .= '<p class="px-4 py-6 text-center text-sm text-soft">Nothing needs attention.</p>'; }
    else {
        $h .= '<ul class="max-h-[22rem] divide-y divide-line overflow-y-auto">';
        foreach ($rows as $r) {
            $h .= '<li><a href="notifications.php?go=' . (int) $r['id'] . '" class="flex gap-3 px-4 py-3 hover:bg-butter-50"><span class="mt-1.5 h-2 w-2 shrink-0 rounded-full ' . ($r['read_at'] === null ? 'bg-lime' : 'bg-transparent') . '" aria-hidden="true"></span>'
                . '<span class="min-w-0"><span class="block truncate text-sm font-semibold">' . ($r['read_at'] === null ? '<span class="sr-only">Unread: </span>' : '') . e($r['title']) . '</span><span class="block truncate text-xs text-soft">' . e($r['body']) . '</span><span class="block text-xs text-soft">' . e(ago($r['created_at'])) . '</span></span></a></li>';
        }
        $h .= '</ul>';
    }
    return $h . '<a href="notifications.php" class="block border-t border-line px-4 py-3 text-center text-sm font-semibold hover:bg-butter-50">See all notifications</a></div></details>';
}
