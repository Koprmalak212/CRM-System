<?php
declare(strict_types=1);

// Guards instead of require_once: pages already load these files with plain require, and a second load would redeclare their functions.
if (!function_exists('company_clean')) { require __DIR__ . '/companies.php'; }
if (!function_exists('invoice_base_sql')) { require __DIR__ . '/invoices.php'; }

const CSV_TYPES = ['users' => 'Users', 'companies' => 'Companies', 'invoices' => 'Invoices', 'tickets' => 'Tickets', 'logs' => 'Activity log'];
const CSV_IMPORT_TYPES = ['users' => 'Users', 'companies' => 'Companies'];
const CSV_MAX_BYTES = 204800;   // 200 KB: Apache refuses whole requests over 256 KB (LimitRequestBody), so stay below that
const CSV_MAX_ROWS = 1000;

/** Stops a spreadsheet from running a cell as a formula (CSV injection): text starting with = + - @ tab or CR gets a leading apostrophe. */
function csv_cell(mixed $v): mixed
{
    return (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

/** The reverse of csv_cell, so an exported file imports back unchanged. */
function csv_uncell(string $v): string
{
    return (strlen($v) > 1 && $v[0] === "'" && strpbrk($v[1], "=+-@\t\r") !== false) ? substr($v, 1) : $v;
}

function csv_cents(int $c): string { return number_format($c / 100, 2, '.', ''); }

/** Header row and a query for each exportable type. Money is plain euros with a dot, so any spreadsheet reads it. */
function csv_export_def(string $type): ?array
{
    return match ($type) {
        'users' => [['name', 'email', 'role', 'status', 'country', 'company', 'job_title', 'phone', 'member_since'],
            'SELECT u.name, u.email, u.role, u.status, u.country, COALESCE(c.name, "") company, u.job_title, u.phone, u.created_at FROM users u LEFT JOIN companies c ON c.id = u.company_id ORDER BY u.id'],
        'companies' => [['name', 'address', 'postcode', 'city', 'country', 'kvk', 'vat_number', 'email', 'phone', 'website', 'notes'],
            'SELECT name, address, postcode, city, country, kvk, vat_number, email, phone, website, notes FROM companies ORDER BY id'],
        'invoices' => [['number', 'customer', 'company', 'email', 'issue_date', 'due_date', 'subtotal', 'vat', 'total', 'paid', 'balance', 'status'],
            'SELECT t.number, t.customer, COALESCE(c.name, "") company, t.email, t.issue_date, t.due_date, t.subtotal_cents, t.vat_cents, t.total_cents, t.paid_cents, t.balance_cents, t.state FROM (' . invoice_base_sql() . ') t LEFT JOIN companies c ON c.id = t.company_id ORDER BY t.id'],
        'tickets' => [['reference', 'subject', 'company', 'contact', 'status', 'priority', 'assignee', 'created', 'updated'],
            'SELECT t.reference, t.subject, COALESCE(c.name, "") company, COALESCE(u.name, "") contact, t.status, t.priority, COALESCE(a.name, "") assignee, t.created_at, t.updated_at FROM tickets t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users u ON u.id = t.user_id LEFT JOIN admins a ON a.id = t.assignee_id ORDER BY t.id'],
        'logs' => [['time', 'level', 'actor', 'action', 'target', 'ip'],
            'SELECT created_at, level, actor, action, target, ip FROM activity_logs ORDER BY id'],
        default => null,
    };
}

/** Writes the whole export to $out (a stream) and returns the number of rows. UTF-8 with a BOM so Excel opens it correctly. */
function csv_export_write(PDO $pdo, string $type, $out): int
{
    [$head, $sql] = csv_export_def($type);
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head);
    $money = ['subtotal', 'vat', 'total', 'paid', 'balance'];
    $n = 0;
    foreach ($pdo->query($sql) as $row) {
        $cells = [];
        foreach (array_values($row) as $i => $v) {
            $cells[] = $type === 'invoices' && in_array($head[$i], $money, true) ? csv_cents((int) $v) : csv_cell((string) $v);
        }
        fputcsv($out, $cells);
        $n++;
    }
    return $n;
}

/** The module that owns an export type, or null for core data (users, companies). */
function csv_type_module(string $type): ?string
{
    return match ($type) { 'invoices' => 'invoices', 'tickets' => 'tickets', 'logs' => 'logs', default => null };
}

/** Export and import buttons for a list page. Only admins get them, and not for a type whose module is off. */
function csv_buttons(string $type): string
{
    $m = csv_type_module($type);
    if (!is_admin() || !isset(CSV_TYPES[$type]) || ($m !== null && !module_enabled($m))) { return ''; }
    $cls = 'pressable inline-flex h-11 items-center gap-2 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy';
    $h = '<a href="system/export.php?type=' . e($type) . '" class="' . $cls . '">' . icon('download', 'h-4 w-4') . 'Export CSV</a>';
    if (isset(CSV_IMPORT_TYPES[$type])) { $h .= '<a href="system/import.php?type=' . e($type) . '" class="' . $cls . '">' . icon('plus', 'h-4 w-4') . 'Import CSV</a>'; }
    return $h;
}

/**
 * Parses CSV text into rows keyed by header name. Accepts a BOM, comma or semicolon (Excel in the Netherlands writes semicolons),
 * quoted cells with line breaks and either line ending. Returns [rows, error]; rows carry '_line' for error messages.
 */
function csv_parse(string $text): array
{
    if (strlen($text) > CSV_MAX_BYTES) { return [[], 'The file is larger than ' . (CSV_MAX_BYTES / 1024) . ' KB.']; }
    if (str_starts_with($text, "\xEF\xBB\xBF")) { $text = substr($text, 3); }
    if (str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) { return [[], 'This does not look like a UTF-8 text file. In Excel choose "CSV UTF-8" when saving.']; }
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (trim($text) === '') { return [[], 'The file is empty.']; }
    $firstLine = strstr($text, "\n", true);
    $firstLine = $firstLine === false ? $text : $firstLine;
    $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $text);
    rewind($fh);
    $head = fgetcsv($fh, 0, $delim, '"', '');
    if (!$head || $head === [null]) { return [[], 'The first line must be the column names.']; }
    $head = array_map(fn($h) => str_replace(' ', '_', strtolower(trim((string) $h))), $head);
    $rows = [];
    $line = 1;
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($r === [null]) { continue; }   // blank line
        if (count($rows) >= CSV_MAX_ROWS) { return [[], 'A file can have at most ' . CSV_MAX_ROWS . ' rows.']; }
        $row = ['_line' => $line];
        if (count($r) > count($head) && trim(implode('', array_slice($r, count($head)))) !== '') { $row['_bad'] = 'has ' . count($r) . ' columns, the header has ' . count($head); }
        foreach ($head as $i => $name) { $row[$name] = csv_uncell(trim((string) ($r[$i] ?? ''))); }
        $rows[] = $row;
    }
    fclose($fh);
    return [$rows, null];
}

/**
 * Imports parsed rows. Returns ['added' => n, 'skipped' => [[line, why]], 'rejected' => [[line, why]], 'error' => ?string].
 * Duplicates (same email, or same KvK number for companies) are skipped, never overwritten. Good rows are saved in one transaction.
 */
function csv_import(PDO $pdo, string $type, array $rows): array
{
    $res = ['added' => 0, 'skipped' => [], 'rejected' => [], 'error' => null];
    $need = $type === 'users' ? ['name', 'email'] : ['name'];
    if (!$rows) { $res['error'] = 'The file has a header but no rows.'; return $res; }
    foreach ($need as $col) {
        if (!array_key_exists($col, $rows[0])) { $res['error'] = 'The file needs a "' . $col . '" column. Columns found: ' . implode(', ', array_filter(array_keys($rows[0]), fn($k) => $k[0] !== '_')) . '.'; return $res; }
    }
    $companyIds = [];
    foreach ($pdo->query('SELECT id, name FROM companies') as $c) { $companyIds[mb_strtolower($c['name'])] = (int) $c['id']; }
    $seenEmail = []; $seenKvk = [];
    if ($type === 'users') { foreach ($pdo->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN) as $m) { $seenEmail[mb_strtolower($m)] = true; } }
    else {
        foreach ($pdo->query("SELECT email, kvk FROM companies")->fetchAll() as $c) { if ($c['email'] !== '') { $seenEmail[mb_strtolower($c['email'])] = true; } if ($c['kvk'] !== '') { $seenKvk[$c['kvk']] = true; } }
    }
    $good = [];
    foreach ($rows as $r) {
        $line = $r['_line'];
        if (isset($r['_bad'])) { $res['rejected'][] = [$line, 'the row ' . $r['_bad']]; continue; }
        if ($type === 'users') {
            $email = mb_strtolower((string) ($r['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) { $res['rejected'][] = [$line, 'the email address is not valid']; continue; }
            if (isset($seenEmail[$email])) { $res['skipped'][] = [$line, $email . ' already exists']; continue; }
            $name = (string) $r['name'];
            if ($name === '' || mb_strlen($name) > 120) { $res['rejected'][] = [$line, 'the name is empty or longer than 120 characters']; continue; }
            $role = strtolower((string) ($r['role'] ?? '')) ?: 'viewer';
            $status = strtolower((string) ($r['status'] ?? '')) ?: 'active';
            if (!in_array($role, ['admin', 'editor', 'support', 'viewer'], true)) { $res['rejected'][] = [$line, 'the role "' . $role . '" is not admin, editor, support or viewer']; continue; }
            if (!in_array($status, ['active', 'pending', 'suspended'], true)) { $res['rejected'][] = [$line, 'the status "' . $status . '" is not active, pending or suspended']; continue; }
            $cid = null;
            if (($r['company'] ?? '') !== '') {
                $cid = $companyIds[mb_strtolower((string) $r['company'])] ?? null;
                if ($cid === null) { $res['rejected'][] = [$line, 'the company "' . mb_substr((string) $r['company'], 0, 60) . '" does not exist: import the companies first']; continue; }
            }
            $country = (string) ($r['country'] ?? '') ?: 'Netherlands';
            $jt = (string) ($r['job_title'] ?? ''); $ph = (string) ($r['phone'] ?? '');
            if (mb_strlen($country) > 60 || mb_strlen($jt) > 80 || mb_strlen($ph) > 40) { $res['rejected'][] = [$line, 'country, job title or phone is too long']; continue; }
            $seenEmail[$email] = true;
            $good[] = [$name, $email, $role, $status, $country, $cid, $jt, $ph];
        } else {
            $kvk = (string) ($r['kvk'] ?? '');
            $mail = mb_strtolower((string) ($r['email'] ?? ''));
            if ($kvk !== '' && isset($seenKvk[$kvk])) { $res['skipped'][] = [$line, 'KvK number ' . $kvk . ' already exists']; continue; }
            if ($mail !== '' && isset($seenEmail[$mail])) { $res['skipped'][] = [$line, $mail . ' already belongs to a company']; continue; }
            [$d, $errs] = company_clean($pdo, $r);
            if ($errs) { $res['rejected'][] = [$line, mb_strtolower(rtrim($errs[0], '.'))]; continue; }
            if ($kvk !== '') { $seenKvk[$kvk] = true; }
            if ($mail !== '') { $seenEmail[$mail] = true; }
            $good[] = $d;
        }
    }
    $pdo->beginTransaction();
    try {
        if ($type === 'users') {
            $ins = $pdo->prepare('INSERT INTO users (name, email, role, status, country, company_id, job_title, phone, logins, last_seen, created_at) VALUES (?,?,?,?,?,?,?,?,0,NOW(),NOW())');
            foreach ($good as $g) { $ins->execute($g); }
        } else {
            $cols = ['name', 'address', 'postcode', 'city', 'country', 'kvk', 'vat_number', 'email', 'phone', 'website', 'notes'];
            $ins = $pdo->prepare('INSERT INTO companies (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
            foreach ($good as $g) { $ins->execute(array_map(fn($c) => $g[$c], $cols)); }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $res['added'] = count($good);
    return $res;
}
