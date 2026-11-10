<?php
declare(strict_types=1);

/**
 * Supplier details printed on every invoice and PDF (Dutch Wet OB art. 35a requires name, address, KvK and BTW-id).
 * Entered under Settings > Company details. The BTW number and IBAN start EMPTY on purpose: while they are empty they are left off
 * the PDF and the invoice page shows a reminder, instead of printing made-up numbers.
 */
$__co = [
    'name' => 'Telecomlijn Zakelijk',
    'address_lines' => ['Seinhuiswachter 12', '3034 KH Rotterdam', 'Nederland'],
    'kvk' => '65586492',
    'btw' => '',
    'iban' => '',
    'email' => 'office@telecomlijn.nl',
    'phone' => '010 200 2000',
];
try {
    $__r = db()->query('SELECT * FROM company_profile WHERE id = 1')->fetch();
    if ($__r) {
        foreach (['name', 'kvk', 'email', 'phone'] as $__k) { if ($__r[$__k] !== '') { $__co[$__k] = $__r[$__k]; } }
        $__lines = array_values(array_filter([$__r['address1'], $__r['address2'], $__r['address3']], fn($l) => $l !== ''));
        if ($__lines) { $__co['address_lines'] = $__lines; }
        $__co['btw'] = (string) $__r['btw_number'];
        $__co['iban'] = (string) $__r['iban'];
    }
} catch (Throwable $__e) { /* the table may not exist yet: the defaults above are used */ }
$__co['btw_is_placeholder'] = $__co['btw'] === '' || $__co['iban'] === '';   // true while either is still to be filled in
return $__co;
