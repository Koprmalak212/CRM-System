<?php
declare(strict_types=1);

if (!function_exists('next_invoice_number')) { require __DIR__ . '/invoices.php'; }
if (!function_exists('company_address_line')) { require __DIR__ . '/companies.php'; }

const RECUR_FREQ = ['month' => 'Every month', 'quarter' => 'Every quarter', 'year' => 'Every year'];

/**
 * The date after $from for a schedule. The day of the month is the anchor chosen when the schedule started, cut back to the last
 * day of shorter months (a schedule that starts on 31 January invoices on 28 or 29 February and goes back to the 31st in March).
 */
function recur_next_date(string $from, string $freq, int $anchorDay): string
{
    $months = ['month' => 1, 'quarter' => 3, 'year' => 12][$freq] ?? 1;
    $d = new DateTimeImmutable($from);
    $first = $d->modify('first day of this month')->modify("+$months month");
    $day = min(max(1, $anchorDay), (int) $first->format('t'));
    return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
}

/** Validates the "new schedule" form. Returns [clean values, errors]. */
function recurring_validate(PDO $pdo, array $in): array
{
    $err = [];
    $companyId = (int) ($in['company_id'] ?? 0);
    $desc = trim((string) ($in['description'] ?? ''));
    $amount = parse_money((string) ($in['amount'] ?? ''));
    $vat = (string) ($in['vat'] ?? '21');
    $freq = (string) ($in['frequency'] ?? 'month');
    $start = (string) ($in['next_date'] ?? '');
    $terms = (string) ($in['terms'] ?? '14');
    $s = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ?'); $s->execute([$companyId]);
    if (!(int) $s->fetchColumn()) { $err[] = 'Choose a company.'; }
    if ($desc === '' || mb_strlen($desc) > 200) { $err[] = 'Enter a description of up to 200 characters.'; }
    if ($amount === null || $amount > 100000000) { $err[] = 'Enter the amount per invoice, excluding BTW, for example 149,00.'; }
    if (!in_array($vat, ['0', '9', '21'], true)) { $err[] = 'Choose a BTW rate.'; }
    if (!isset(RECUR_FREQ[$freq])) { $err[] = 'Choose how often to invoice.'; }
    if (!valid_date($start) || $start < '2000-01-01' || $start > '2100-12-31') { $err[] = 'Choose the date of the first invoice (between 2000 and 2100).'; }
    if (!in_array($terms, ['0', '7', '14', '30', '60'], true)) { $err[] = 'Choose payment terms.'; }
    return [['company_id' => $companyId, 'description' => $desc, 'amount_cents' => (int) $amount, 'vat_rate' => (int) $vat, 'frequency' => $freq, 'next_date' => $start, 'terms_days' => (int) $terms], $err];
}

/**
 * Creates the invoices that are due: every active schedule whose next date is today or earlier gets ONE invoice per run, dated on its
 * scheduled day, and moves on to the next period. A unique key on (schedule, issue date) means a period can never be invoiced twice,
 * not even by two runs at the same moment. Returns ['created' => [[schedule id, invoice number], ...], 'already' => n].
 */
function recurring_run_due(PDO $pdo, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $res = ['created' => [], 'already' => 0];
    $got = (int) $pdo->query("SELECT GET_LOCK('recurring_run', 5)")->fetchColumn();
    if ($got !== 1) { return $res; }   // another run is busy; nothing is lost, the schedules stay due
    try {
        $due = $pdo->prepare('SELECT id FROM recurring_invoices WHERE active = 1 AND next_date <= ? ORDER BY next_date, id');
        $due->execute([$today]);
        foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $pdo->beginTransaction();
            try {
                $s = $pdo->prepare('SELECT r.*, c.name AS co_name, c.email AS co_email, c.address AS co_address, c.postcode AS co_postcode, c.city AS co_city FROM recurring_invoices r JOIN companies c ON c.id = r.company_id WHERE r.id = ? AND r.active = 1 AND r.next_date <= ? FOR UPDATE');
                $s->execute([$rid, $today]);
                $r = $s->fetch();
                if (!$r) { $pdo->rollBack(); continue; }
                $issue = (string) $r['next_date'];
                $sub = (int) $r['amount_cents'];
                $vat = (int) round($sub * (int) $r['vat_rate'] / 100);
                $exists = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE recurring_id = ? AND issue_date = ?');
                $exists->execute([$rid, $issue]);
                if ((int) $exists->fetchColumn() === 0) {
                    $number = next_invoice_number($pdo);
                    $note = (int) $r['vat_rate'] === 0 ? 'BTW verlegd / vrijgesteld. Recurring invoice.' : 'Recurring invoice (' . strtolower(RECUR_FREQ[$r['frequency']]) . ').';
                    $pdo->prepare('INSERT INTO invoices (number, company_id, recurring_id, customer, email, customer_address, supply_date, issue_date, due_date, vat_rate, subtotal_cents, vat_cents, total_cents, notes, created_at, is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
                        ->execute([$number, $r['company_id'], $rid, $r['co_name'], $r['co_email'], company_address_line(['address' => $r['co_address'], 'postcode' => $r['co_postcode'], 'city' => $r['co_city']]), $issue, $issue, date('Y-m-d', strtotime($issue . ' +' . (int) $r['terms_days'] . ' days')), (int) $r['vat_rate'], $sub, $vat, $sub + $vat, $note, (int) $r['is_demo']]);
                    $iid = (int) $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents) VALUES (?,?,1,?)')->execute([$iid, $r['description'], $sub]);
                    $res['created'][] = [(int) $rid, $number];
                    log_activity('info', 'Recurring invoice created', $number . ' · ' . $r['co_name'], 'scheduler');
                } else {
                    $res['already']++;
                }
                $pdo->prepare('UPDATE recurring_invoices SET next_date = ?, last_run = ? WHERE id = ?')->execute([recur_next_date($issue, $r['frequency'], (int) $r['anchor_day']), $issue, $rid]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Recurring invoice failed for schedule ' . $rid . ': ' . $e->getMessage());   // the others still run
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('recurring_run')")->fetchColumn();
    }
    return $res;
}
