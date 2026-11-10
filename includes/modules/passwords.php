<?php
declare(strict_types=1);

/**
 * A random password for a new account, for example "kT7p-Qm4x-Rv9c-Ws2h-N!6d" (shown once, never stored in readable form).
 * 20 letters, digits and symbols from an alphabet without look-alike characters (no 0 O 1 l I), at least one of each kind,
 * drawn with random_int() (a cryptographically secure source), in groups of five so it can be typed.
 */
function random_password(int $length = 20): string
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%*+=?@'];
    $length = max(12, $length);
    $chars = [];
    foreach ($sets as $set) { $chars[] = $set[random_int(0, strlen($set) - 1)]; }   // one of each kind
    $all = implode('', $sets);
    while (count($chars) < $length) { $chars[] = $all[random_int(0, strlen($all) - 1)]; }
    for ($i = count($chars) - 1; $i > 0; $i--) {                                   // shuffle, so the guaranteed ones are not always first
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('-', str_split(implode('', $chars), 5));
}
