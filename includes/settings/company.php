<?php
declare(strict_types=1);

/** IBAN check: country code, two check digits, 15 to 34 characters, and the ISO 7064 mod-97 test. */
function iban_valid(string $iban): bool
{
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) { return false; }
    $r = substr($iban, 4) . substr($iban, 0, 4);
    $num = '';
    foreach (str_split($r) as $ch) { $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch; }
    $mod = 0;
    foreach (str_split($num, 7) as $chunk) { $mod = (int) ($mod . $chunk) % 97; }
    return $mod === 1;
}

function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('company:handle'); }
    $f = [];
    foreach (['name', 'address1', 'address2', 'address3', 'kvk', 'btw_number', 'iban', 'email', 'phone'] as $k) { $f[$k] = trim((string) ($_POST[$k] ?? '')); }
    $f['btw_number'] = strtoupper(str_replace([' ', '.', '-'], '', $f['btw_number']));
    $f['iban'] = strtoupper(str_replace(' ', '', $f['iban']));
    $err = [];
    foreach (['name' => 80, 'address1' => 80, 'address2' => 80, 'address3' => 80, 'email' => 190, 'phone' => 40] as $k => $max) { if (mb_strlen($f[$k]) > $max) { $err[] = ucfirst($k) . ' can be at most ' . $max . ' characters.'; } }
    if ($f['kvk'] !== '' && !preg_match('/^\d{8}$/', $f['kvk'])) { $err[] = 'A KvK number has 8 digits.'; }
    if ($f['btw_number'] !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{8,12}$/', $f['btw_number'])) { $err[] = 'A BTW number starts with a country code, for example NL123456789B01.'; }
    if ($f['btw_number'] !== '' && str_starts_with($f['btw_number'], 'NL') && !preg_match('/^NL\d{9}B\d{2}$/', $f['btw_number'])) { $err[] = 'A Dutch BTW number looks like NL123456789B01.'; }
    if ($f['iban'] !== '' && !iban_valid($f['iban'])) { $err[] = 'That IBAN is not valid. Check it for typing mistakes.'; }
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $err[] = 'The email address is not valid.'; }
    if ($err) { flash(implode(' ', $err), 'bad'); return; }
    $pdo->prepare('INSERT INTO company_profile (id, name, address1, address2, address3, kvk, btw_number, iban, email, phone, updated_at) VALUES (1,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE name = VALUES(name), address1 = VALUES(address1), address2 = VALUES(address2), address3 = VALUES(address3), kvk = VALUES(kvk), btw_number = VALUES(btw_number), iban = VALUES(iban), email = VALUES(email), phone = VALUES(phone), updated_at = VALUES(updated_at)')
        ->execute([$f['name'], $f['address1'], $f['address2'], $f['address3'], $f['kvk'], $f['btw_number'], $f['iban'], $f['email'], $f['phone']]);
    log_activity('info', 'Company details saved', ($f['btw_number'] !== '' ? 'BTW set' : 'BTW empty') . ' · ' . ($f['iban'] !== '' ? 'IBAN set' : 'IBAN empty'));
    flash('Company details saved. They appear on the next invoice and PDF.');
}

function tab_render(PDO $pdo, array $admin): void
{
    $r = $pdo->query('SELECT * FROM company_profile WHERE id = 1')->fetch() ?: array_fill_keys(['name', 'address1', 'address2', 'address3', 'kvk', 'btw_number', 'iban', 'email', 'phone'], '');
    $co = require dirname(__DIR__) . '/modules/company.php';
    $field = 'mt-1.5 h-11 w-full rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $in = fn(string $k, string $label, string $ph = '', string $hint = '', bool $wide = false) => '<div' . ($wide ? ' class="sm:col-span-2"' : '') . '><label for="' . $k . '" class="text-sm font-semibold">' . e($label) . '</label><input id="' . $k . '" name="' . $k . '" value="' . e($r[$k]) . '" placeholder="' . e($ph) . '" class="' . $field . '">' . ($hint !== '' ? '<p class="mt-1 text-xs text-soft">' . e($hint) . '</p>' : '') . '</div>';
    ?>
<section class="rise max-w-3xl rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="co-h">
  <h2 id="co-h" class="text-lg font-bold tracking-tight">Company details on invoices</h2>
  <p class="mt-1 text-sm text-soft">Printed on every invoice and PDF. <strong class="text-ink">BTW number and IBAN are empty on purpose</strong>: fill them in yourself. While a field is empty it is left off the PDF, so nothing made-up is ever shown. Empty fields below use the default shown as a hint.</p>
  <?php if (!empty($co['btw_is_placeholder'])): ?><p role="status" class="mt-4 rounded-lg bg-butter-100 px-4 py-3 text-sm"><strong class="text-butter">Still to fill in:</strong> <?= $co['btw'] === '' ? 'BTW number' : '' ?><?= $co['btw'] === '' && $co['iban'] === '' ? ' and ' : '' ?><?= $co['iban'] === '' ? 'IBAN' : '' ?>.</p><?php endif; ?>
  <form method="post" class="mt-5 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="company">
    <?= $in('name', 'Company name', 'Telecomlijn Zakelijk', '', true) ?>
    <?= $in('address1', 'Address line 1', 'Seinhuiswachter 12') ?>
    <?= $in('address2', 'Address line 2', '3034 KH Rotterdam') ?>
    <?= $in('address3', 'Address line 3', 'Nederland') ?>
    <?= $in('kvk', 'KvK number', '65586492', '8 digits') ?>
    <?= $in('btw_number', 'BTW number (BTW-id)', 'NL123456789B01', 'Left empty: not printed') ?>
    <?= $in('iban', 'IBAN', 'NL91 ABNA 0417 1643 00', 'Left empty: not printed. Checked for typing mistakes.') ?>
    <?= $in('email', 'Email', 'office@telecomlijn.nl') ?>
    <?= $in('phone', 'Phone', '010 200 2000') ?>
    <div class="sm:col-span-2"><button class="pressable h-11 rounded-lg bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Save</button></div>
  </form>
</section>
<?php
}
