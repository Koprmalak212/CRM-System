<?php
declare(strict_types=1);

function money(int $cents): string
{
    return '€ ' . number_format($cents / 100, 2, ',', '.');
}

/** Accepts "1.234,56", "1234.56", "1234" and returns cents, or null when it is not a positive amount. */
function parse_money(string $s): ?int
{
    $s = trim(str_replace(['€', ' '], '', $s));
    if ($s === '') return null;
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
    }
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
    $c = (int) round(((float) $s) * 100);
    return $c > 0 ? $c : null;
}

/**
 * Every invoice with what has been paid and a derived state:
 * paid, overdue (past due date), due (due within 7 days) or open.
 */
function invoice_base_sql(): string
{
    return "SELECT i.*, COALESCE(p.paid, 0) AS paid_cents, (i.total_cents - COALESCE(p.paid, 0)) AS balance_cents,
        DATEDIFF(i.due_date, CURDATE()) AS days_to_due,
        CASE
          WHEN i.total_cents - COALESCE(p.paid, 0) <= 0 THEN 'paid'
          WHEN i.due_date < CURDATE() THEN 'overdue'
          WHEN i.due_date <= CURDATE() + INTERVAL 7 DAY THEN 'due'
          ELSE 'open'
        END AS state
      FROM invoices i
      LEFT JOIN (SELECT invoice_id, SUM(amount_cents) AS paid FROM payments GROUP BY invoice_id) p ON p.invoice_id = i.id";
}

function invoice_state_text(array $r): string
{
    $d = (int) $r['days_to_due'];
    return match ($r['state']) {
        'paid' => 'Paid in full',
        'overdue' => abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ' overdue',
        default => $d === 0 ? 'Due today' : ($d === 1 ? 'Due tomorrow' : 'Due in ' . $d . ' days'),
    };
}

function next_invoice_number(PDO $pdo): string
{
    $prefix = 'INV-' . date('Y') . '-';
    $stmt = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM invoices WHERE number LIKE ?');
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $n = (int) $stmt->fetchColumn();
    return $prefix . str_pad((string) max($n + 1, 1001), 4, '0', STR_PAD_LEFT);
}

function valid_date(string $s): bool
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}
