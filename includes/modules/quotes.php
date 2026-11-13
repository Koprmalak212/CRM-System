<?php
declare(strict_types=1);

/*
 * Quotes (offertes): an offer to a customer with the same lines as an invoice, a date until which it is valid, and an outcome.
 *
 *   - Status: draft, sent, accepted (won) or declined (lost). A draft or sent quote whose "valid until" has passed counts as expired;
 *     that is worked out when it is shown and never stored, so changing the date brings the quote back to life.
 *   - An accepted quote becomes an invoice (its lines are billed once) or a contract (its lines become the monthly services) with one
 *     click, each at most once. Those buttons only exist while the Invoices or Contracts module is on.
 *   - Win rate: accepted quotes as a share of the quotes that have an outcome (accepted, declined or expired).
 */

if (!function_exists('next_doc_number')) { require __DIR__ . '/lines.php'; }
if (!function_exists('company_find')) { require __DIR__ . '/companies.php'; }

const QUOTE_STATES = ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'];
const QUOTE_VALID_DAYS = ['14' => '14 days', '30' => '30 days', '60' => '60 days', '90' => '90 days'];

/** Every quote with its derived state (draft, sent, accepted, declined or expired) and the days left until it expires. */
function quote_base_sql(): string
{
    return "SELECT q.*, DATEDIFF(q.valid_until, CURDATE()) AS days_left,
        CASE WHEN q.status IN ('draft','sent') AND q.valid_until < CURDATE() THEN 'expired' ELSE q.status END AS state
      FROM quotes q";
}

function quote_find(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM (' . quote_base_sql() . ') t WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function quote_items(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id');
    $s->execute([$id]);
    return $s->fetchAll();
}

function quote_not_found(): never
{
    http_response_code(404);
    page_start('Quote not found', 'quotes', 'That quote does not exist. It may have been removed.');
    echo '<a href="quotes/quotes.php" class="pressable inline-flex h-11 items-center rounded-lg bg-lime px-5 text-sm font-bold text-navy">Back to quotes</a>';
    page_end();
    exit;
}

function quote_state_text(array $q): string
{
    $d = (int) $q['days_left'];
    return match ($q['state']) {
        'accepted' => 'Accepted' . ($q['decided_at'] ? ' on ' . date('j M Y', strtotime((string) $q['decided_at'])) : ''),
        'declined' => 'Declined' . ($q['decided_at'] ? ' on ' . date('j M Y', strtotime((string) $q['decided_at'])) : ''),
        'expired' => 'Expired ' . abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago',
        default => $d === 0 ? 'Valid until today' : 'Valid for ' . $d . ' more day' . ($d === 1 ? '' : 's'),
    };
}

/** May the text and lines of this quote still be changed? Not once it has an outcome. */
function quote_editable(array $q): bool { return in_array($q['status'], ['draft', 'sent'], true); }

/** Validates the quote form. Returns [clean values, items, rows to show again, errors]. */
function quote_validate(PDO $pdo, array $in): array
{
    $err = [];
    $d = [
        'company_id' => (int) ($in['company_id'] ?? 0),
        'customer' => trim((string) ($in['customer'] ?? '')),
        'email' => trim((string) ($in['email'] ?? '')),
        'address' => trim((string) ($in['address'] ?? '')),
        'title' => trim((string) ($in['title'] ?? '')),
        'issue_date' => (string) ($in['issue_date'] ?? ''),
        'valid_until' => (string) ($in['valid_until'] ?? ''),
        'vat_rate' => (string) ($in['vat'] ?? '21'),
        'notes' => trim((string) ($in['notes'] ?? '')),
    ];
    if ($d['company_id'] > 0 && !company_find($pdo, $d['company_id'])) { $err[] = 'That company does not exist.'; $d['company_id'] = 0; }
    if ($d['customer'] === '' || mb_strlen($d['customer']) > 160) { $err[] = 'Enter the customer name.'; }
    if ($d['email'] !== '' && (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 190)) { $err[] = 'The email address is not valid.'; }
    if (mb_strlen($d['address']) > 255) { $err[] = 'The address can be at most 255 characters.'; }
    if (mb_strlen($d['title']) > 160) { $err[] = 'The subject can be at most 160 characters.'; }
    $dateOk = fn(string $v) => valid_date($v) && $v >= '2000-01-01' && $v <= '2100-12-31';
    if (!$dateOk($d['issue_date'])) { $err[] = 'Choose the quote date (between 2000 and 2100).'; }
    if (!$dateOk($d['valid_until'])) { $err[] = 'Choose the date until which the quote is valid.'; }
    elseif ($dateOk($d['issue_date']) && $d['valid_until'] < $d['issue_date']) { $err[] = 'The quote can not stop being valid before its own date.'; }
    if (!in_array($d['vat_rate'], ['0', '9', '21'], true)) { $err[] = 'Choose a BTW rate.'; }
    if (mb_strlen($d['notes']) > 500) { $err[] = 'The note can be at most 500 characters.'; }
    if ($d['vat_rate'] === '0' && $d['notes'] === '') { $err[] = 'State the reason for 0% BTW in the note, for example "BTW verlegd".'; }
    [$items, $old, $lineErr] = lines_read($in);
    $d['vat_rate'] = (int) $d['vat_rate'];
    return [$d, $items, $old, array_merge($err, $lineErr)];
}

/** Stores a new quote ($id = 0, as a draft) or the changes to one. Returns the quote id. */
function quote_save(PDO $pdo, int $id, array $d, array $items): int
{
    $sub = lines_subtotal($items);
    $vat = (int) round($sub * $d['vat_rate'] / 100);
    $pdo->beginTransaction();
    try {
        if ($id === 0) {
            $pdo->prepare("INSERT INTO quotes (number, company_id, customer, email, customer_address, title, issue_date, valid_until, vat_rate, subtotal_cents, vat_cents, total_cents, notes, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'draft', ?, NOW())")
                ->execute([next_doc_number($pdo, 'quotes', 'OFF'), $d['company_id'] > 0 ? $d['company_id'] : null, $d['customer'], $d['email'], $d['address'], $d['title'], $d['issue_date'], $d['valid_until'], $d['vat_rate'], $sub, $vat, $sub + $vat, $d['notes'], mb_substr((string) ($_SESSION['admin']['username'] ?? 'system'), 0, 60)]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE quotes SET company_id = ?, customer = ?, email = ?, customer_address = ?, title = ?, issue_date = ?, valid_until = ?, vat_rate = ?, subtotal_cents = ?, vat_cents = ?, total_cents = ?, notes = ? WHERE id = ?')
                ->execute([$d['company_id'] > 0 ? $d['company_id'] : null, $d['customer'], $d['email'], $d['address'], $d['title'], $d['issue_date'], $d['valid_until'], $d['vat_rate'], $sub, $vat, $sub + $vat, $d['notes'], $id]);
            $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
        }
        $ins = $pdo->prepare('INSERT INTO quote_items (quote_id, description, qty, unit_cents) VALUES (?,?,?,?)');
        foreach ($items as $it) { $ins->execute([$id, $it['description'], $it['qty'], $it['unit_cents']]); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $id;
}

/**
 * Moves a quote to sent, accepted, declined, or back to draft. Returns an error message or null.
 * An accepted quote that already became an invoice or contract can not be reopened.
 */
function quote_set_status(PDO $pdo, array $q, string $to, string $reason = ''): ?string
{
    if (!in_array($to, ['draft', 'sent', 'accepted', 'declined'], true) || $to === $q['status']) { return 'That is not a possible step for this quote.'; }
    if ($q['invoice_id'] !== null || $q['contract_id'] !== null) { return 'This quote already became an invoice or a contract, so its outcome is fixed.'; }
    if ($to === 'accepted' && $q['state'] === 'expired') { return 'This quote has expired. Set a new "valid until" date first.'; }
    $decided = in_array($to, ['accepted', 'declined'], true);
    $pdo->prepare('UPDATE quotes SET status = ?, decided_at = ?, lost_reason = ? WHERE id = ?')->execute([$to, $decided ? date('Y-m-d H:i:s') : null, $to === 'declined' ? mb_substr(trim($reason), 0, 200) : '', (int) $q['id']]);
    log_activity('info', 'Quote ' . $to, $q['number'] . ' · ' . $q['customer']);
    return null;
}

/**
 * Turns a quote into an invoice: the same customer and lines, dated today, due in $terms days. The quote is marked accepted.
 * Returns [invoice id or 0, error or null]. A quote becomes an invoice at most once, also when two people click at the same moment.
 */
function quote_to_invoice(PDO $pdo, int $quoteId, int $terms = 14): array
{
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM quotes WHERE id = ? FOR UPDATE');
        $s->execute([$quoteId]);
        $q = $s->fetch();
        if (!$q) { $pdo->rollBack(); return [0, 'That quote does not exist.']; }
        if ($q['invoice_id'] !== null) { $pdo->rollBack(); return [0, 'This quote already became an invoice.']; }
        if ($q['status'] === 'declined') { $pdo->rollBack(); return [0, 'A declined quote can not be invoiced. Reopen it first.']; }
        if (!filter_var((string) $q['email'], FILTER_VALIDATE_EMAIL) || (string) $q['customer_address'] === '') { $pdo->rollBack(); return [0, 'An invoice needs the email address and the address of the customer. Add them to the quote first.']; }
        $items = quote_items($pdo, $quoteId);
        $number = next_invoice_number($pdo);
        $today = date('Y-m-d');
        $note = trim(($q['notes'] !== '' ? $q['notes'] . ' ' : '') . 'Quote ' . $q['number'] . '.');
        $pdo->prepare('INSERT INTO invoices (number, company_id, customer, email, customer_address, supply_date, issue_date, due_date, vat_rate, subtotal_cents, vat_cents, total_cents, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([$number, $q['company_id'], $q['customer'], $q['email'], $q['customer_address'], $today, $today, date('Y-m-d', strtotime("+$terms days")), (int) $q['vat_rate'], (int) $q['subtotal_cents'], (int) $q['vat_cents'], (int) $q['total_cents'], mb_substr($note, 0, 255)]);
        $iid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents) VALUES (?,?,?,?)');
        foreach ($items as $it) { $ins->execute([$iid, $it['description'], $it['qty'], $it['unit_cents']]); }
        $pdo->prepare("UPDATE quotes SET invoice_id = ?, status = 'accepted', decided_at = COALESCE(decided_at, NOW()), lost_reason = '' WHERE id = ?")->execute([$iid, $quoteId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_activity('info', 'Quote became an invoice', $q['number'] . ' → ' . $number);
    return [$iid, null];
}

/**
 * Turns a quote into a contract: its lines become the monthly services. The contract is made as a DRAFT that starts today and runs
 * for a year, so nothing is invoiced until someone has checked the dates and made it active. The quote is marked accepted.
 * Returns [contract id or 0, error or null].
 */
function quote_to_contract(PDO $pdo, int $quoteId): array
{
    if (!function_exists('contract_save')) { require __DIR__ . '/contracts.php'; }
    $s = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
    $s->execute([$quoteId]);
    $q = $s->fetch();
    if (!$q) { return [0, 'That quote does not exist.']; }
    if ($q['contract_id'] !== null) { return [0, 'This quote already became a contract.']; }
    if ($q['status'] === 'declined') { return [0, 'A declined quote can not become a contract. Reopen it first.']; }
    if ($q['company_id'] === null) { return [0, 'A contract belongs to a company. Link the quote to a company first.']; }
    $claim = $pdo->prepare('UPDATE quotes SET contract_id = 0 WHERE id = ? AND contract_id IS NULL');   // claimed first, so two clicks make one contract
    $claim->execute([$quoteId]);
    if ($claim->rowCount() !== 1) { return [0, 'This quote already became a contract.']; }
    try {
        $items = array_map(fn($it) => ['service' => 'other', 'description' => $it['description'], 'qty' => (float) $it['qty'], 'unit_cents' => (int) $it['unit_cents']], quote_items($pdo, $quoteId));
        $today = date('Y-m-d');
        $cid = contract_save($pdo, 0, ['company_id' => (int) $q['company_id'], 'title' => $q['title'] !== '' ? $q['title'] : 'Services from quote ' . $q['number'], 'status' => 'draft', 'start_date' => $today,
            'end_date' => date('Y-m-d', strtotime('+1 year -1 day')), 'auto_renew' => 1, 'renew_months' => 12, 'notice_days' => 60, 'billing' => 'month', 'vat_rate' => (int) $q['vat_rate'], 'terms_days' => 14,
            'next_invoice_date' => $today, 'sla_respond_min' => null, 'sla_resolve_min' => null, 'notes' => 'Made from quote ' . $q['number'] . '.'], $items, $quoteId);
        $pdo->prepare("UPDATE quotes SET contract_id = ?, status = 'accepted', decided_at = COALESCE(decided_at, NOW()), lost_reason = '' WHERE id = ?")->execute([$cid, $quoteId]);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE quotes SET contract_id = NULL WHERE id = ? AND contract_id = 0')->execute([$quoteId]);
        throw $e;
    }
    log_activity('info', 'Quote became a contract', $q['number'] . ' · ' . $q['customer']);
    return [$cid, null];
}

/**
 * The numbers on top of the quotes page: what is still open, what was won and lost, and the win rate (null while nothing has an outcome).
 * $days limits won, lost and the rate to quotes dated in the last $days days (0 = all).
 */
function quotes_stats(PDO $pdo, int $days = 0): array
{
    $when = $days > 0 ? ' AND issue_date >= CURDATE() - INTERVAL ' . (int) $days . ' DAY' : '';
    $r = $pdo->query("SELECT
        COALESCE(SUM(state IN ('draft','sent')), 0) AS open_n, COALESCE(SUM(CASE WHEN state IN ('draft','sent') THEN total_cents END), 0) AS open_sum,
        COALESCE(SUM(state = 'accepted'$when), 0) AS won_n, COALESCE(SUM(CASE WHEN state = 'accepted'$when THEN total_cents END), 0) AS won_sum,
        COALESCE(SUM(state IN ('declined','expired')$when), 0) AS lost_n, COALESCE(SUM(CASE WHEN state IN ('declined','expired')$when THEN total_cents END), 0) AS lost_sum
      FROM (" . quote_base_sql() . ') t')->fetch();
    $decided = (int) $r['won_n'] + (int) $r['lost_n'];
    $r['win_rate'] = $decided > 0 ? round((int) $r['won_n'] / $decided * 100, 1) : null;
    return $r;
}

/** "Quotes" panel for a company page. */
function quotes_panel(PDO $pdo, int $companyId): string
{
    $s = $pdo->prepare('SELECT * FROM (' . quote_base_sql() . ') t WHERE company_id = ? ORDER BY issue_date DESC, id DESC LIMIT 6');
    $s->execute([$companyId]);
    $rows = $s->fetchAll();
    $h = '<section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="qt-h"><div class="flex items-center justify-between gap-3"><h2 id="qt-h" class="text-base font-bold">Quotes</h2><a href="quotes/quote_edit.php?company=' . $companyId . '" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">New quote</a></div>';
    if (!$rows) { return $h . '<p class="mt-3 text-sm text-soft">No quotes yet.</p></section>'; }
    $h .= '<ul class="mt-4 divide-y divide-line">';
    foreach ($rows as $r) {
        $h .= '<li class="py-2.5"><div class="flex items-center justify-between gap-2"><a href="quotes/quote.php?id=' . (int) $r['id'] . '" class="min-w-0 truncate text-sm font-semibold hover:underline">' . e($r['title'] !== '' ? $r['title'] : $r['number']) . '</a>' . badge($r['state']) . '</div>'
            . '<p class="mt-0.5 text-xs text-soft"><span class="notranslate">' . e($r['number']) . ' · ' . e(money((int) $r['total_cents'])) . '</span> · ' . e(quote_state_text($r)) . '</p></li>';
    }
    return $h . '</ul></section>';
}
