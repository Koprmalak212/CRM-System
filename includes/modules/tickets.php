<?php
declare(strict_types=1);

const TICKET_STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting', 'resolved' => 'Resolved', 'closed' => 'Closed'];
const TICKET_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

function ticket_find(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT t.*, c.name AS company_name, u.name AS user_name, a.name AS assignee_name
        FROM tickets t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users u ON u.id = t.user_id LEFT JOIN admins a ON a.id = t.assignee_id WHERE t.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function ticket_not_found(): never
{
    http_response_code(404);
    page_start('Ticket not found', 'tickets', 'That ticket does not exist. It may have been removed.');
    echo '<a href="tickets/tickets.php" class="pressable inline-flex h-11 items-center rounded-xl bg-lime px-5 text-sm font-bold text-navy">Back to tickets</a>';
    page_end();
    exit;
}

/** Staff accounts a ticket can be assigned to: id => name. */
function ticket_assignees(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM admins WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** May a ticket move from $from to $to? A closed ticket can only be reopened. */
function ticket_can_move(string $from, string $to): bool
{
    if (!isset(TICKET_STATUSES[$to]) || $from === $to) { return false; }
    return $from === 'closed' ? $to === 'open' : true;
}

/**
 * Validates and stores a new ticket. Returns [ticket id or 0, errors].
 * A user without a company takes over the user's company; a user who belongs to another company is refused.
 */
function ticket_create(PDO $pdo, array $in, ?string $author = null): array
{
    $err = [];
    $subject = trim((string) ($in['subject'] ?? ''));
    $desc = trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? '')));
    $priority = (string) ($in['priority'] ?? 'normal');
    $companyId = (int) ($in['company_id'] ?? 0);
    $userId = (int) ($in['user_id'] ?? 0);
    $assignee = (int) ($in['assignee_id'] ?? 0);
    if ($subject === '') { $err[] = 'Enter a subject.'; }
    if (mb_strlen($subject) > 160) { $err[] = 'The subject can be at most 160 characters.'; }
    if ($desc === '') { $err[] = 'Describe the problem or request.'; }
    if (mb_strlen($desc) > 2000) { $err[] = 'The description can be at most 2000 characters.'; }
    if (!isset(TICKET_PRIORITIES[$priority])) { $err[] = 'Choose a priority.'; }
    $company = null;
    if ($companyId > 0) {
        $s = $pdo->prepare('SELECT id FROM companies WHERE id = ?'); $s->execute([$companyId]);
        if (!$s->fetchColumn()) { $err[] = 'That company does not exist.'; } else { $company = $companyId; }
    }
    $user = null;
    if ($userId > 0) {
        $s = $pdo->prepare('SELECT id, company_id FROM users WHERE id = ?'); $s->execute([$userId]);
        $u = $s->fetch();
        if (!$u) { $err[] = 'That user does not exist.'; }
        else {
            $user = $userId;
            if ($company === null && $u['company_id'] !== null && $companyId <= 0) { $company = (int) $u['company_id']; }
            elseif ($company !== null && (int) $u['company_id'] !== $company) { $err[] = 'That user does not belong to the chosen company.'; }
        }
    }
    $aid = null;
    if ($assignee > 0) {
        if (!isset(ticket_assignees($pdo)[$assignee])) { $err[] = 'That assignee does not exist.'; } else { $aid = $assignee; }
    }
    if ($err) { return [0, $err]; }
    $author = mb_substr($author ?? (string) ($_SESSION['admin']['username'] ?? 'system'), 0, 60);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO tickets (reference, company_id, user_id, subject, description, priority, assignee_id, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute(['TMP-' . bin2hex(random_bytes(6)), $company, $user, $subject, $desc, $priority, $aid, $author]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE tickets SET reference = ? WHERE id = ?')->execute([ticket_reference($id), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_activity('info', 'Ticket opened', ticket_reference($id) . ' · ' . $subject);
    return [$id, []];
}

function ticket_reference(int $id): string { return 'TCK-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT); }

/** Adds a comment. Returns an error message or null. */
function ticket_comment(PDO $pdo, int $ticketId, string $body, ?string $author = null): ?string
{
    $body = trim(str_replace("\r\n", "\n", $body));
    if (!ticket_find($pdo, $ticketId)) { return 'That ticket does not exist.'; }
    if ($body === '') { return 'Write a comment first.'; }
    if (mb_strlen($body) > 2000) { return 'A comment can be at most 2000 characters.'; }
    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, author, body, created_at) VALUES (?,?,?,NOW())')
        ->execute([$ticketId, mb_substr($author ?? (string) ($_SESSION['admin']['username'] ?? 'system'), 0, 60), $body]);
    $pdo->prepare('UPDATE tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticketId]);
    return null;
}

/**
 * Changes status, priority and assignee. Returns [list of change descriptions, list of errors].
 * Anything not sent, or sent unchanged, is left alone.
 */
function ticket_update(PDO $pdo, array $t, array $in): array
{
    $set = []; $args = []; $changes = []; $err = [];
    if (isset($in['status']) && $in['status'] !== $t['status']) {
        if (!ticket_can_move($t['status'], (string) $in['status'])) { $err[] = $t['status'] === 'closed' ? 'A closed ticket can only be reopened.' : 'That is not a valid status.'; }
        else {
            $set[] = 'status = ?'; $args[] = $in['status'];
            $set[] = in_array($in['status'], ['resolved', 'closed'], true) ? 'resolved_at = COALESCE(resolved_at, NOW())' : 'resolved_at = NULL';
            $changes[] = 'status ' . TICKET_STATUSES[$t['status']] . ' → ' . TICKET_STATUSES[$in['status']];
        }
    }
    if (isset($in['priority']) && $in['priority'] !== $t['priority']) {
        if (!isset(TICKET_PRIORITIES[(string) $in['priority']])) { $err[] = 'That is not a valid priority.'; }
        else { $set[] = 'priority = ?'; $args[] = $in['priority']; $changes[] = 'priority ' . TICKET_PRIORITIES[$t['priority']] . ' → ' . TICKET_PRIORITIES[$in['priority']]; }
    }
    if (isset($in['assignee_id']) && (int) $in['assignee_id'] !== (int) $t['assignee_id']) {
        $a = (int) $in['assignee_id'];
        $who = ticket_assignees($pdo);
        if ($a > 0 && !isset($who[$a])) { $err[] = 'That assignee does not exist.'; }
        else { $set[] = 'assignee_id = ?'; $args[] = $a > 0 ? $a : null; $changes[] = 'assigned to ' . ($a > 0 ? $who[$a] : 'nobody'); }
    }
    if ($set && !$err) {
        $pdo->prepare('UPDATE tickets SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ?')->execute([...$args, (int) $t['id']]);
        log_activity('info', 'Ticket updated', $t['reference'] . ' · ' . implode(', ', $changes));
    }
    return [$err ? [] : $changes, $err];
}

/** Open (not resolved or closed) tickets of a company or user, newest first. */
function tickets_open_for(PDO $pdo, string $col, int $id, int $limit = 10): array
{
    $col = $col === 'user_id' ? 'user_id' : 'company_id';
    $s = $pdo->prepare("SELECT id, reference, subject, status, priority FROM tickets WHERE $col = ? AND status NOT IN ('resolved','closed') ORDER BY FIELD(priority,'urgent','high','normal','low'), updated_at DESC LIMIT " . (int) $limit);
    $s->execute([$id]);
    return $s->fetchAll();
}

/** Small "Open tickets" panel for companies/company.php and users/user.php. */
function tickets_panel(PDO $pdo, string $col, int $id, string $newLink): string
{
    $rows = tickets_open_for($pdo, $col, $id);
    $h = '<section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="tk-h"><div class="flex items-center justify-between gap-3"><h2 id="tk-h" class="text-base font-bold">Open tickets</h2><a href="' . e($newLink) . '" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">New ticket</a></div>';
    if (!$rows) { return $h . '<p class="mt-3 text-sm text-soft">No open tickets.</p></section>'; }
    $h .= '<ul class="mt-4 divide-y divide-line">';
    foreach ($rows as $r) {
        $h .= '<li class="py-2.5"><div class="flex items-center justify-between gap-2"><a href="tickets/ticket.php?id=' . (int) $r['id'] . '" class="min-w-0 truncate text-sm font-semibold hover:underline">' . e($r['subject']) . '</a>' . badge($r['priority']) . '</div><p class="mt-0.5 text-xs text-soft"><span class="notranslate">' . e($r['reference']) . '</span> · ' . e(TICKET_STATUSES[$r['status']]) . '</p></li>';
    }
    return $h . '</ul></section>';
}
