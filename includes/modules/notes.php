<?php
declare(strict_types=1);

const NOTE_KINDS = ['note' => 'Note', 'call' => 'Call', 'email' => 'Email', 'meeting' => 'Meeting'];
const NOTE_MAX = 2000;

/** Does the user or company a note is about exist? */
function note_target_exists(PDO $pdo, string $type, int $id): bool
{
    if (!in_array($type, ['user', 'company'], true) || $id <= 0) { return false; }
    $s = $pdo->prepare('SELECT COUNT(*) FROM ' . ($type === 'user' ? 'users' : 'companies') . ' WHERE id = ?');
    $s->execute([$id]);
    return (int) $s->fetchColumn() === 1;
}

/** Adds a note. Returns an error message, or null when saved. The author is always the signed-in account. */
function note_add(PDO $pdo, string $type, int $id, string $kind, string $body, ?string $author = null): ?string
{
    $body = trim(str_replace("\r\n", "\n", $body));
    if (!note_target_exists($pdo, $type, $id)) { return 'That ' . ($type === 'company' ? 'company' : 'user') . ' does not exist.'; }
    if (!isset(NOTE_KINDS[$kind])) { return 'Choose what kind of entry this is.'; }
    if ($body === '') { return 'Write something first.'; }
    if (mb_strlen($body) > NOTE_MAX) { return 'A note can be at most ' . NOTE_MAX . ' characters.'; }
    $author = mb_substr($author ?? (string) ($_SESSION['admin']['username'] ?? 'system'), 0, 60);
    $pdo->prepare('INSERT INTO notes (about_type, about_id, kind, body, author, created_at) VALUES (?,?,?,?,?,NOW())')->execute([$type, $id, $kind, $body, $author]);
    log_activity('info', 'Note added', $type . ' #' . $id . ' · ' . $kind);
    return null;
}

/** May the signed-in account change this note? Its author, or an admin. */
function note_can_change(array $note): bool
{
    return is_admin() || (string) $note['author'] === (string) ($_SESSION['admin']['username'] ?? '');
}

/** Handles the add / edit / delete forms on users/user.php / companies/company.php. Redirects either way. */
function notes_handle_post(PDO $pdo, string $type, int $id, string $back): never
{
    $action = (string) ($_POST['action'] ?? 'note');
    if ($action === 'note') {
        $err = note_add($pdo, $type, $id, (string) ($_POST['kind'] ?? ''), (string) ($_POST['body'] ?? ''));
        flash($err ?? 'Note added.', $err ? 'error' : 'ok');
        redirect($back . '#timeline');
    }
    $s = $pdo->prepare('SELECT * FROM notes WHERE id = ? AND about_type = ? AND about_id = ?');
    $s->execute([(int) ($_POST['note_id'] ?? 0), $type, $id]);
    $note = $s->fetch();
    if (!$note) { flash('That note no longer exists.', 'error'); redirect($back . '#timeline'); }
    if (!note_can_change($note)) { deny_access('note:change'); }
    if ($action === 'note_delete') {
        $pdo->prepare('DELETE FROM notes WHERE id = ?')->execute([$note['id']]);
        log_activity('warning', 'Note deleted', $type . ' #' . $id . ' · ' . $note['kind']);
        flash('Note deleted.');
    } else {   // note_edit
        $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
        $kind = (string) ($_POST['kind'] ?? $note['kind']);
        if ($body === '') { flash('A note can not be empty. Delete it instead.', 'error'); }
        elseif (mb_strlen($body) > NOTE_MAX) { flash('A note can be at most ' . NOTE_MAX . ' characters.', 'error'); }
        elseif (!isset(NOTE_KINDS[$kind])) { flash('Choose what kind of entry this is.', 'error'); }
        else {
            $pdo->prepare('UPDATE notes SET body = ?, kind = ?, edited_at = NOW() WHERE id = ?')->execute([$body, $kind, $note['id']]);   // the author and the original time stay
            log_activity('info', 'Note edited', $type . ' #' . $id . ' · ' . $kind);
            flash('Note saved.');
        }
    }
    redirect($back . '#timeline');
}

/**
 * The timeline of a user or company: notes, and for a company also the invoices issued to it. Newest first.
 * @return list<array{at:string,kind:string,title:string,text:string,by:string,link:?string}>
 */
function timeline_items(PDO $pdo, string $type, int $id, int $limit = 50): array
{
    $items = [];
    $n = $pdo->prepare('SELECT id, kind, body, author, created_at, edited_at FROM notes WHERE about_type = ? AND about_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
    $n->execute([$type, $id]);
    foreach ($n->fetchAll() as $r) {
        $items[] = ['at' => $r['created_at'], 'kind' => $r['kind'], 'title' => NOTE_KINDS[$r['kind']], 'text' => $r['body'], 'by' => $r['author'], 'link' => null, 'note' => $r];
    }
    if ($type === 'company') {
        $i = $pdo->prepare('SELECT id, number, total_cents, created_at FROM invoices WHERE company_id = ? ORDER BY created_at DESC LIMIT ' . (int) $limit);
        $i->execute([$id]);
        foreach ($i->fetchAll() as $r) {
            $items[] = ['at' => $r['created_at'], 'kind' => 'invoice', 'title' => 'Invoice issued', 'text' => $r['number'] . ' · ' . money((int) $r['total_cents']), 'by' => '', 'link' => 'invoices/invoice.php?id=' . (int) $r['id']];
        }
    }
    if ($type === 'company') {
        $t = $pdo->prepare('SELECT id, reference, subject, created_at FROM tickets WHERE company_id = ? ORDER BY created_at DESC LIMIT ' . (int) $limit);
        $t->execute([$id]);
        foreach ($t->fetchAll() as $r) {
            $items[] = ['at' => $r['created_at'], 'kind' => 'ticket', 'title' => 'Ticket opened', 'text' => $r['reference'] . ' · ' . $r['subject'], 'by' => '', 'link' => 'tickets/ticket.php?id=' . (int) $r['id']];
        }
    }
    usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
    return array_slice($items, 0, $limit);
}

/** The "Add note" form and the timeline, as one panel. $action is the page the form posts to. */
function notes_panel(PDO $pdo, string $type, int $id, string $action): string
{
    $items = timeline_items($pdo, $type, $id);
    $field = 'rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $h = '<section id="timeline" class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel lg:col-span-3" aria-labelledby="tl-h"><h2 id="tl-h" class="text-base font-bold">Notes and timeline</h2>';
    $h .= '<form method="post" action="' . e($action) . '" class="mt-4 grid gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-start">' . csrf_field() . '<input type="hidden" name="action" value="note"><input type="hidden" name="id" value="' . $id . '">';
    $h .= '<div><label for="note_kind" class="sr-only">Kind</label><select id="note_kind" name="kind" class="h-11 w-full ' . $field . '">';
    foreach (NOTE_KINDS as $k => $l) { $h .= '<option value="' . $k . '">' . e($l) . '</option>'; }
    $h .= '</select></div><div><label for="note_body" class="sr-only">Write a note</label><textarea id="note_body" name="body" rows="2" maxlength="' . NOTE_MAX . '" required placeholder="What happened? Calls, emails and meetings can be logged here too." class="w-full py-2 ' . $field . '"></textarea></div>';
    $h .= '<button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Add note</button></form>';
    if ($items) {
        $h .= '<ol class="mt-5 space-y-4 border-l border-line pl-5">';
        foreach ($items as $it) {
            $dot = in_array($it['kind'], ['invoice', 'ticket'], true) ? 'bg-butter' : 'bg-lime';
            $h .= '<li class="relative"><span class="absolute -left-[1.62rem] top-1.5 h-2.5 w-2.5 rounded-full ' . $dot . '" aria-hidden="true"></span>'
                . '<div class="flex flex-wrap items-center gap-x-2 text-xs text-soft"><span class="font-semibold text-ink">' . e($it['title']) . '</span><span>' . e(ago($it['at'])) . (!empty($it['note']['edited_at']) ? ' (edited)' : '') . '</span>' . ($it['by'] !== '' ? '<span>· ' . e($it['by']) . '</span>' : '') . '</div>'
                . '<p class="mt-1 whitespace-pre-line text-sm">' . ($it['link'] ? '<a class="underline decoration-lime decoration-2 underline-offset-4" href="' . e($it['link']) . '">' . e($it['text']) . '</a>' : e($it['text'])) . '</p>' . (isset($it['note']) && note_can_change($it['note']) ? note_controls($it['note'], $action, $id) : '') . '</li>';
        }
        $h .= '</ol>';
    } else {
        $h .= '<p class="mt-4 text-sm text-soft">Nothing here yet. Add the first note above.</p>';
    }
    return $h . '</section>';
}


/** "Edit" for a note: a details element with the edit form, and Delete tucked inside it so it takes two steps. */
function note_controls(array $n, string $action, int $targetId): string
{
    $field = 'rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $h = '<details class="mt-1"><summary class="cursor-pointer text-xs font-semibold text-soft underline decoration-lime decoration-2 underline-offset-4 hover:text-ink">Edit</summary>';
    $h .= '<form method="post" action="' . e($action) . '" class="mt-2 grid gap-2 sm:grid-cols-[9rem_1fr_auto] sm:items-start">' . csrf_field() . '<input type="hidden" name="action" value="note_edit"><input type="hidden" name="id" value="' . $targetId . '"><input type="hidden" name="note_id" value="' . (int) $n['id'] . '">';
    $h .= '<select name="kind" aria-label="Kind" class="h-10 ' . $field . '">';
    foreach (NOTE_KINDS as $k => $l) { $h .= '<option value="' . $k . '"' . ($n['kind'] === $k ? ' selected' : '') . '>' . e($l) . '</option>'; }
    $h .= '</select><textarea name="body" rows="2" maxlength="' . NOTE_MAX . '" aria-label="Note text" required class="py-2 ' . $field . '">' . e($n['body']) . '</textarea><button class="h-10 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy">Save</button></form>';
    $h .= '<form method="post" action="' . e($action) . '" class="mt-2">' . csrf_field() . '<input type="hidden" name="action" value="note_delete"><input type="hidden" name="id" value="' . $targetId . '"><input type="hidden" name="note_id" value="' . (int) $n['id'] . '"><button class="h-9 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Delete this note</button></form></details>';
    return $h;
}
