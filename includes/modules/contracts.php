<?php
declare(strict_types=1);

/*
 * Contracts & subscriptions: per company, which services it has, the price per month, the start and end date, how it renews and how
 * it is invoiced.
 *
 *   - A line is one service: a kind (fiber, 3CX seats, Yeastar seats, security, ...), a quantity and a price per unit per month.
 *   - Status: draft (being prepared: no invoices, no alerts), active, ended.
 *   - End date: empty means the contract runs until someone ends it. With "renews automatically" the end date moves on by the renewal
 *     period when it is reached; without it the contract ends that day.
 *   - Renewal alert: notice_days before the end date a notification is raised once, for both kinds.
 *   - Invoices: with a billing period (month, quarter, year) the contract creates its own invoices, one per period, dated on the scheduled
 *     day and holding one line per service. A unique key on (contract, issue date) means a period can never be invoiced twice. This only
 *     happens while the Invoices module is on; with it off the contract is still tracked and nothing is invoiced.
 */

if (!function_exists('next_doc_number')) { require __DIR__ . '/lines.php'; }
if (!function_exists('recur_next_date')) { require __DIR__ . '/recurring.php'; }
if (!function_exists('company_address_line')) { require __DIR__ . '/companies.php'; }

const CONTRACT_STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'ended' => 'Ended'];
const CONTRACT_BILLING = ['month' => 'Every month', 'quarter' => 'Every quarter', 'year' => 'Every year', 'none' => 'Not invoiced from the contract'];
const CONTRACT_MONTHS = ['month' => 1, 'quarter' => 3, 'year' => 12];
const CONTRACT_SERVICES = ['fiber' => 'Fiber / internet', 'voip_3cx' => '3CX seats', 'voip_yeastar' => 'Yeastar seats', 'security' => 'Security', 'numbers' => 'Phone numbers', 'other' => 'Other'];

/**
 * Every contract with its company, its price per month and what its end date means today:
 *   days_left (NULL without an end date) and renewal_due (1 while an active contract is inside its notice period).
 */
function contract_base_sql(): string
{
    return "SELECT c.*, co.name AS company_name, COALESCE(m.monthly, 0) AS monthly_cents, DATEDIFF(c.end_date, CURDATE()) AS days_left,
        (c.status = 'active' AND c.end_date IS NOT NULL AND c.end_date <= CURDATE() + INTERVAL c.notice_days DAY) AS renewal_due
      FROM contracts c JOIN companies co ON co.id = c.company_id
      LEFT JOIN (SELECT contract_id, SUM(ROUND(qty * unit_cents)) AS monthly FROM contract_items GROUP BY contract_id) m ON m.contract_id = c.id";
}

function contract_find(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM (' . contract_base_sql() . ') t WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function contract_items(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id = ? ORDER BY id');
    $s->execute([$id]);
    return $s->fetchAll();
}

function contract_not_found(): never
{
    http_response_code(404);
    page_start('Contract not found', 'contracts', 'That contract does not exist. It may have been removed.');
    echo '<a href="contracts/contracts.php" class="pressable inline-flex h-11 items-center rounded-lg bg-lime px-5 text-sm font-bold text-navy">Back to contracts</a>';
    page_end();
    exit;
}

/** What the end date means, in words: "Ends in 23 days", "Renews on 1 Mar 2027", "No end date". */
function contract_end_text(array $c): string
{
    if ($c['status'] === 'ended') { return 'Ended' . ($c['end_date'] !== null ? ' ' . date('j M Y', strtotime((string) $c['end_date'])) : ''); }
    if ($c['end_date'] === null) { return 'No end date'; }
    $d = (int) $c['days_left'];
    $when = $d < 0 ? abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' ago' : ($d === 0 ? 'today' : 'in ' . $d . ' day' . ($d === 1 ? '' : 's'));
    return ((int) $c['auto_renew'] === 1 ? 'Renews ' : 'Ends ') . $when;
}

/** Hours typed in a form ("4", "0,5") as minutes, or null when empty. false when it is not a sensible number. */
function contract_hours_to_min(string $s): int|null|false
{
    $s = trim(str_replace(',', '.', $s));
    if ($s === '') { return null; }
    if (!is_numeric($s) || (float) $s <= 0 || (float) $s > 8760) { return false; }
    return max(1, (int) round((float) $s * 60));
}

/** Validates the contract form. Returns [clean values, items, rows to show again, errors]. */
function contract_validate(PDO $pdo, array $in): array
{
    $err = [];
    $d = [
        'company_id' => (int) ($in['company_id'] ?? 0),
        'title' => trim((string) ($in['title'] ?? '')),
        'status' => (string) ($in['status'] ?? 'active'),
        'start_date' => (string) ($in['start_date'] ?? ''),
        'end_date' => trim((string) ($in['end_date'] ?? '')),
        'auto_renew' => !empty($in['auto_renew']) ? 1 : 0,
        'renew_months' => (int) ($in['renew_months'] ?? 12),
        'notice_days' => (int) ($in['notice_days'] ?? 60),
        'billing' => (string) ($in['billing'] ?? 'month'),
        'vat_rate' => (string) ($in['vat'] ?? '21'),
        'terms_days' => (string) ($in['terms'] ?? '14'),
        'next_invoice_date' => trim((string) ($in['next_invoice_date'] ?? '')),
        'notes' => trim(str_replace("\r\n", "\n", (string) ($in['notes'] ?? ''))),
    ];
    $s = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ?'); $s->execute([$d['company_id']]);
    if (!(int) $s->fetchColumn()) { $err[] = 'Choose a company.'; }
    if ($d['title'] === '' || mb_strlen($d['title']) > 160) { $err[] = 'Enter a name for the contract of up to 160 characters, for example "Fiber and telephony".'; }
    if (!in_array($d['status'], ['draft', 'active'], true)) { $err[] = 'Choose draft or active.'; }
    $dateOk = fn(string $v) => valid_date($v) && $v >= '2000-01-01' && $v <= '2100-12-31';
    if (!$dateOk($d['start_date'])) { $err[] = 'Choose the start date (between 2000 and 2100).'; }
    if ($d['end_date'] !== '' && !$dateOk($d['end_date'])) { $err[] = 'The end date is not a valid date.'; }
    elseif ($d['end_date'] !== '' && $dateOk($d['start_date']) && $d['end_date'] < $d['start_date']) { $err[] = 'The end date can not be before the start date.'; }
    if ($d['auto_renew'] && $d['end_date'] === '') { $err[] = 'A contract that renews automatically needs an end date: that is the day it renews.'; }
    if ($d['renew_months'] < 1 || $d['renew_months'] > 120) { $err[] = 'The renewal period must be between 1 and 120 months.'; }
    if ($d['notice_days'] < 0 || $d['notice_days'] > 365) { $err[] = 'The renewal alert must be between 0 and 365 days before the end date.'; }
    if (!isset(CONTRACT_BILLING[$d['billing']])) { $err[] = 'Choose how the contract is invoiced.'; }
    if (!in_array($d['vat_rate'], ['0', '9', '21'], true)) { $err[] = 'Choose a BTW rate.'; }
    if (!in_array($d['terms_days'], ['0', '7', '14', '30', '60'], true)) { $err[] = 'Choose payment terms.'; }
    if (mb_strlen($d['notes']) > 1000) { $err[] = 'The notes can be at most 1000 characters.'; }
    if ($d['billing'] !== 'none') {
        if ($d['next_invoice_date'] === '' && $dateOk($d['start_date'])) { $d['next_invoice_date'] = $d['start_date']; }
        if (!$dateOk($d['next_invoice_date'])) { $err[] = 'Choose the date of the next invoice.'; }
    } else { $d['next_invoice_date'] = ''; }
    foreach (['sla_respond_min' => ['sla_respond_h', 'The response target'], 'sla_resolve_min' => ['sla_resolve_h', 'The resolve target']] as $col => [$f, $label]) {
        $v = contract_hours_to_min((string) ($in[$f] ?? ''));
        if ($v === false) { $err[] = $label . ' must be a number of hours above zero, for example 4 or 0,5. Leave it empty to use the standard targets.'; $v = null; }
        $d[$col] = $v;
    }
    [$items, $old, $lineErr] = lines_read($in, array_keys(CONTRACT_SERVICES));
    $d['vat_rate'] = (int) $d['vat_rate']; $d['terms_days'] = (int) $d['terms_days'];
    return [$d, $items, $old, array_merge($err, $lineErr)];
}

/** Stores a new contract ($id = 0) or the changes to one. Returns the contract id. */
function contract_save(PDO $pdo, int $id, array $d, array $items, ?int $quoteId = null, bool $demo = false): int
{
    $end = $d['end_date'] !== '' ? $d['end_date'] : null;
    $next = $d['next_invoice_date'] !== '' ? $d['next_invoice_date'] : null;
    $anchor = $next !== null ? (int) date('j', strtotime($next)) : 1;
    $pdo->beginTransaction();
    try {
        if ($id === 0) {
            $number = next_doc_number($pdo, 'contracts', 'CTR');
            $pdo->prepare('INSERT INTO contracts (number, company_id, title, status, start_date, end_date, auto_renew, renew_months, notice_days, billing, vat_rate, terms_days, next_invoice_date, anchor_day, sla_respond_min, sla_resolve_min, notes, quote_id, created_by, is_demo, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$number, $d['company_id'], $d['title'], $d['status'], $d['start_date'], $end, $d['auto_renew'], $d['renew_months'], $d['notice_days'], $d['billing'], $d['vat_rate'], $d['terms_days'], $next, $anchor, $d['sla_respond_min'], $d['sla_resolve_min'], $d['notes'], $quoteId, mb_substr((string) ($_SESSION['admin']['username'] ?? 'system'), 0, 60), $demo ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE contracts SET company_id = ?, title = ?, status = ?, start_date = ?, end_date = ?, auto_renew = ?, renew_months = ?, notice_days = ?, billing = ?, vat_rate = ?, terms_days = ?, next_invoice_date = ?, anchor_day = ?, sla_respond_min = ?, sla_resolve_min = ?, notes = ?, ended_at = NULL WHERE id = ?')
                ->execute([$d['company_id'], $d['title'], $d['status'], $d['start_date'], $end, $d['auto_renew'], $d['renew_months'], $d['notice_days'], $d['billing'], $d['vat_rate'], $d['terms_days'], $next, $anchor, $d['sla_respond_min'], $d['sla_resolve_min'], $d['notes'], $id]);
            $pdo->prepare('DELETE FROM contract_items WHERE contract_id = ?')->execute([$id]);
        }
        $ins = $pdo->prepare('INSERT INTO contract_items (contract_id, service, description, qty, unit_cents) VALUES (?,?,?,?,?)');
        foreach ($items as $it) { $ins->execute([$id, $it['service'] ?? 'other', $it['description'], $it['qty'], $it['unit_cents']]); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $id;
}

/** The period an invoice dated $issue covers, as text: "1 Nov 2026 to 30 Nov 2026". */
function contract_period_text(string $issue, string $billing): string
{
    $from = new DateTimeImmutable($issue);
    $to = $from->modify('+' . (CONTRACT_MONTHS[$billing] ?? 1) . ' month')->modify('-1 day');
    return $from->format('j M Y') . ' to ' . $to->format('j M Y');
}

/** Creates the invoices of active contracts that are due on or before $today. Returns [[contract id, invoice number], ...]. */
function contracts_bill_due(PDO $pdo, string $today): array
{
    $made = [];
    $due = $pdo->prepare("SELECT id FROM contracts WHERE status = 'active' AND billing <> 'none' AND next_invoice_date IS NOT NULL AND next_invoice_date <= ? AND start_date <= ? AND (end_date IS NULL OR next_invoice_date <= end_date) ORDER BY next_invoice_date, id");
    $due->execute([$today, $today]);
    foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        for ($guard = 0; $guard < 36; $guard++) {   // a contract that is several periods behind catches up in one run
            $pdo->beginTransaction();
            try {
                $s = $pdo->prepare("SELECT c.*, co.name AS co_name, co.email AS co_email, co.address AS co_address, co.postcode AS co_postcode, co.city AS co_city FROM contracts c JOIN companies co ON co.id = c.company_id
                    WHERE c.id = ? AND c.status = 'active' AND c.billing <> 'none' AND c.next_invoice_date <= ? AND (c.end_date IS NULL OR c.next_invoice_date <= c.end_date) FOR UPDATE");
                $s->execute([$cid, $today]);
                $c = $s->fetch();
                if (!$c) { $pdo->rollBack(); break; }
                $issue = (string) $c['next_invoice_date'];
                $months = CONTRACT_MONTHS[$c['billing']];
                $items = contract_items($pdo, (int) $cid);
                $sub = 0;
                foreach ($items as $it) { $sub += (int) round((float) $it['qty'] * (int) $it['unit_cents'] * $months); }
                $exists = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE contract_id = ? AND issue_date = ?');
                $exists->execute([$cid, $issue]);
                if ($sub > 0 && (int) $exists->fetchColumn() === 0) {
                    $vat = (int) round($sub * (int) $c['vat_rate'] / 100);
                    $number = next_invoice_number($pdo);
                    $note = ((int) $c['vat_rate'] === 0 ? 'BTW verlegd / vrijgesteld. ' : '') . 'Contract ' . $c['number'] . ', period ' . contract_period_text($issue, $c['billing']) . '.';
                    $pdo->prepare('INSERT INTO invoices (number, company_id, contract_id, customer, email, customer_address, supply_date, issue_date, due_date, vat_rate, subtotal_cents, vat_cents, total_cents, notes, created_at, is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
                        ->execute([$number, $c['company_id'], $cid, $c['co_name'], $c['co_email'], company_address_line(['address' => $c['co_address'], 'postcode' => $c['co_postcode'], 'city' => $c['co_city']]), $issue, $issue, date('Y-m-d', strtotime($issue . ' +' . (int) $c['terms_days'] . ' days')), (int) $c['vat_rate'], $sub, $vat, $sub + $vat, mb_substr($note, 0, 255), (int) $c['is_demo']]);
                    $iid = (int) $pdo->lastInsertId();
                    $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents) VALUES (?,?,?,?)');
                    foreach ($items as $it) { $ins->execute([$iid, mb_substr($it['description'] . ($months > 1 ? ' (' . $months . ' months)' : ''), 0, 200), $it['qty'], (int) $it['unit_cents'] * $months]); }
                    $made[] = [(int) $cid, $number];
                    log_activity('info', 'Contract invoice created', $number . ' · ' . $c['number'] . ' · ' . $c['co_name'], 'scheduler');
                }
                $pdo->prepare('UPDATE contracts SET next_invoice_date = ?, last_invoiced = ? WHERE id = ?')->execute([recur_next_date($issue, $c['billing'], (int) $c['anchor_day']), $issue, $cid]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Contract invoice failed for contract ' . $cid . ': ' . $e->getMessage());   // the others still run
                break;
            }
        }
    }
    return $made;
}

/**
 * The daily work on contracts: invoices that are due, contracts that renew, contracts that end. Safe to run as often as you like.
 * Returns ['invoices' => [[contract id, invoice number], ...], 'renewed' => n, 'ended' => n].
 */
function contracts_run_due(PDO $pdo, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $res = ['invoices' => [], 'renewed' => 0, 'ended' => 0];
    if ((int) $pdo->query("SELECT GET_LOCK('contracts_run', 5)")->fetchColumn() !== 1) { return $res; }   // another run is busy; nothing is lost
    try {
        $bill = module_enabled('invoices');
        if ($bill) { $res['invoices'] = contracts_bill_due($pdo, $today); }   // first what was still owed inside the old period
        $past = $pdo->prepare("SELECT c.id, c.number, c.end_date, c.auto_renew, c.renew_months, c.is_demo, co.name AS co_name FROM contracts c JOIN companies co ON co.id = c.company_id WHERE c.status = 'active' AND c.end_date IS NOT NULL AND c.end_date < ?");
        $past->execute([$today]);
        foreach ($past->fetchAll() as $c) {
            if ((int) $c['auto_renew'] === 1) {
                $end = new DateTimeImmutable((string) $c['end_date']);
                for ($i = 0; $i < 240 && $end->format('Y-m-d') < $today; $i++) { $end = $end->modify('+' . max(1, (int) $c['renew_months']) . ' month'); }
                $pdo->prepare("UPDATE contracts SET end_date = ? WHERE id = ? AND status = 'active'")->execute([$end->format('Y-m-d'), $c['id']]);
                if (!(int) $c['is_demo']) { log_activity('info', 'Contract renewed', $c['number'] . ' · ' . $c['co_name'] . ' · until ' . $end->format('Y-m-d'), 'scheduler'); }
                $res['renewed']++;
            } else {
                $pdo->prepare("UPDATE contracts SET status = 'ended', ended_at = NOW() WHERE id = ? AND status = 'active'")->execute([$c['id']]);
                if (!(int) $c['is_demo']) { log_activity('info', 'Contract ended', $c['number'] . ' · ' . $c['co_name'], 'scheduler'); }
                $res['ended']++;
            }
        }
        if ($bill && $res['renewed'] > 0) { $res['invoices'] = array_merge($res['invoices'], contracts_bill_due($pdo, $today)); }   // a renewed contract goes on being invoiced
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('contracts_run')")->fetchColumn();
    }
    return $res;
}

/** The renewal alerts: one notification per contract and end date, raised when the notice period starts. Returns how many are new. */
function contracts_notify(PDO $pdo): int
{
    $new = 0;
    foreach ($pdo->query('SELECT id, number, title, company_name, end_date, days_left, auto_renew, monthly_cents, is_demo FROM (' . contract_base_sql() . ') t WHERE renewal_due = 1 AND end_date >= CURDATE() LIMIT 200') as $c) {
        $renews = (int) $c['auto_renew'] === 1;
        $new += notify_add($pdo, 'contract_renewal', 'contract-renewal:' . $c['id'] . ':' . $c['end_date'],
            'Contract ' . $c['number'] . ($renews ? ' renews' : ' ends') . ' on ' . date('j M Y', strtotime((string) $c['end_date'])),
            $c['company_name'] . ' · ' . $c['title'] . ' · ' . money((int) $c['monthly_cents']) . ' a month' . ($renews ? '. It renews by itself unless it is ended first.' : '. Agree a renewal or it stops.'),
            'contracts/contract.php?id=' . $c['id'], (bool) $c['is_demo']) ? 1 : 0;
    }
    return $new;
}

/** The numbers on top of the contracts page and for the Overview. */
function contracts_stats(PDO $pdo): array
{
    return $pdo->query("SELECT COALESCE(SUM(CASE WHEN status = 'active' THEN monthly_cents END), 0) AS mrr, COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(renewal_due = 1), 0) AS renewing,
        COALESCE(SUM(CASE WHEN renewal_due = 1 THEN monthly_cents END), 0) AS renewing_mrr, COALESCE(SUM(status = 'draft'), 0) AS drafts FROM (" . contract_base_sql() . ') t')->fetch();
}

/** "Contracts" panel for a company page: what the company has and what it pays a month. */
function contracts_panel(PDO $pdo, int $companyId): string
{
    $s = $pdo->prepare('SELECT * FROM (' . contract_base_sql() . ") t WHERE company_id = ? AND status <> 'ended' ORDER BY status = 'active' DESC, start_date DESC LIMIT 10");
    $s->execute([$companyId]);
    $rows = $s->fetchAll();
    $h = '<section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="ctr-h"><div class="flex items-center justify-between gap-3"><h2 id="ctr-h" class="text-base font-bold">Contracts</h2><a href="contracts/contract_edit.php?company=' . $companyId . '" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">New contract</a></div>';
    if (!$rows) { return $h . '<p class="mt-3 text-sm text-soft">No running contracts.</p></section>'; }
    $total = 0;
    $h .= '<ul class="mt-4 divide-y divide-line">';
    foreach ($rows as $r) {
        if ($r['status'] === 'active') { $total += (int) $r['monthly_cents']; }
        $h .= '<li class="py-2.5"><div class="flex items-center justify-between gap-2"><a href="contracts/contract.php?id=' . (int) $r['id'] . '" class="min-w-0 truncate text-sm font-semibold hover:underline">' . e($r['title']) . '</a>' . ((int) $r['renewal_due'] === 1 ? badge('due_renewal') : badge($r['status'])) . '</div>'
            . '<p class="mt-0.5 text-xs text-soft"><span class="notranslate">' . e($r['number']) . ' · ' . e(money((int) $r['monthly_cents'])) . '</span> a month · ' . e(contract_end_text($r)) . '</p></li>';
    }
    return $h . '</ul><p class="mt-3 border-t border-line pt-3 text-sm text-soft">Active, per month: <span class="font-semibold text-ink notranslate">' . e(money($total)) . '</span></p></section>';
}
