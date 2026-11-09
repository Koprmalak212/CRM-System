<?php
declare(strict_types=1);

/**
 * Supplier details printed on every invoice (Dutch Wet OB art. 35a requires name, address, KvK and BTW-id).
 * Values marked "fill in" are placeholders. Replace them before this system is used for real invoices.
 */
return [
    'name' => 'Telecomlijn Zakelijk',
    'address_lines' => ['Seinhuiswachter 12', '3034 KH Rotterdam', 'Nederland'],
    'kvk' => '65586492',
    'btw' => 'NL000000000B01',          // fill in: Telecomlijn's real BTW-identificatienummer
    'iban' => 'NL00BANK0000000000',     // fill in: the IBAN customers should pay to
    'email' => 'office@telecomlijn.nl',
    'phone' => '010 200 2000',
    'btw_is_placeholder' => true,       // set to false once the real BTW number and IBAN are entered
];
