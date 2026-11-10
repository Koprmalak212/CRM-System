<?php
declare(strict_types=1);

/**
 * Whether demo data is loaded (a single flag in app_meta, cached for the request). This is the only demo code that every page needs
 * (the banner and the footer note), so it lives here; the loader and remover are in includes/modules/demo.php and are only
 * included by the pages that use them (system/demo_data.php, the Overview's demo panel, bin/setup.php).
 */
function demo_loaded(?PDO $pdo = null): bool
{
    static $cache = null;
    if ($pdo === null && $cache !== null) { return $cache; }
    try { $v = ($pdo ?? db())->query("SELECT v FROM app_meta WHERE k = 'demo_loaded'")->fetchColumn() === '1'; }
    catch (Throwable $e) { $v = false; }
    if ($pdo === null) { $cache = $v; }
    return $v;
}
