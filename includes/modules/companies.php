<?php
declare(strict_types=1);

/** One-line postal address, as printed on invoices: "Street 1, 1234 AB City". */
function company_address_line(array $c): string
{
    $city = trim(($c['postcode'] ?? '') . ' ' . ($c['city'] ?? ''));
    return implode(', ', array_filter([trim((string) ($c['address'] ?? '')), $city], fn($v) => $v !== ''));
}

/**
 * Validates the company form. Returns [clean values, list of error messages].
 * $ignoreId is the company being edited, so its own KvK number is not reported as a duplicate.
 */
function company_clean(PDO $pdo, array $in, int $ignoreId = 0): array
{
    $d = [];
    foreach (['name', 'address', 'postcode', 'city', 'country', 'kvk', 'vat_number', 'email', 'phone', 'website', 'notes'] as $f) {
        $d[$f] = trim((string) ($in[$f] ?? ''));
    }
    $d['vat_number'] = strtoupper(str_replace([' ', '.'], '', $d['vat_number']));
    $d['postcode'] = strtoupper($d['postcode']);
    if ($d['country'] === '') { $d['country'] = 'Netherlands'; }
    if ($d['website'] !== '' && !preg_match('#^https?://#i', $d['website'])) { $d['website'] = 'https://' . $d['website']; }
    $err = [];
    $max = ['name' => 120, 'address' => 160, 'postcode' => 12, 'city' => 80, 'country' => 60, 'kvk' => 12, 'vat_number' => 20, 'email' => 190, 'phone' => 40, 'website' => 190, 'notes' => 1000];
    $label = ['name' => 'Company name', 'address' => 'Address', 'postcode' => 'Postcode', 'city' => 'City', 'country' => 'Country', 'kvk' => 'KvK number', 'vat_number' => 'VAT number', 'email' => 'Email', 'phone' => 'Phone', 'website' => 'Website', 'notes' => 'Notes'];
    foreach ($max as $f => $m) {
        if (mb_strlen($d[$f]) > $m) { $err[] = $label[$f] . ' can be at most ' . $m . ' characters.'; }
    }
    if ($d['name'] === '') { $err[] = 'Enter the company name.'; }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $err[] = 'The email address is not valid.'; }
    if ($d['kvk'] !== '' && !preg_match('/^\d{8}$/', $d['kvk'])) { $err[] = 'A KvK number has 8 digits.'; }
    if ($d['vat_number'] !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{8,14}$/', $d['vat_number'])) { $err[] = 'A VAT number starts with a country code, for example NL123456789B01.'; }
    if ($d['website'] !== '' && !filter_var($d['website'], FILTER_VALIDATE_URL)) { $err[] = 'The website address is not valid.'; }
    if ($d['kvk'] !== '' && preg_match('/^\d{8}$/', $d['kvk'])) {
        $s = $pdo->prepare('SELECT name FROM companies WHERE kvk = ? AND id <> ? LIMIT 1');
        $s->execute([$d['kvk'], $ignoreId]);
        if ($other = $s->fetchColumn()) { $err[] = 'KvK number ' . $d['kvk'] . ' already belongs to ' . $other . '.'; }
    }
    return [$d, $err];
}

function company_find(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function company_not_found(): never
{
    http_response_code(404);
    page_start('Company not found', 'companies', 'That company does not exist. It may have been removed.');
    echo '<a href="companies/companies.php" class="pressable inline-flex h-11 items-center rounded-xl bg-lime px-5 text-sm font-bold text-navy">Back to companies</a>';
    page_end();
    exit;
}
