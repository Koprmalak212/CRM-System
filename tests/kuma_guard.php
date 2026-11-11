<?php
declare(strict_types=1);

/*
 * Guard for the Kuma connection during a test run. The suite changes the saved Kuma settings (it has to, to test them) and puts the original back in a
 * "finally" at the end. A fatal error or a killed run skips that, and the NEXT run would then take the test values for the real ones and "restore" them.
 * So the original is also written to a file (storage/test_kuma_backup.json, closed to the web) before anything changes, a shutdown function puts it back
 * when the run ends in a fatal error, and the next run puts back what a killed run left behind before it takes its own backup.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

/** Writes the current Kuma settings (or "none") to $file. Returns the row, null when nothing was saved. */
function kuma_guard_save(PDO $pdo, string $file): ?array
{
    $row = $pdo->query('SELECT * FROM kuma_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    @file_put_contents($file, json_encode(['row' => $row], JSON_UNESCAPED_UNICODE));
    return $row;
}

/** Puts the settings in $file back and deletes the file. Returns true when there was a file to restore from. */
function kuma_guard_restore(PDO $pdo, string $file): bool
{
    if (!is_file($file)) { return false; }
    $left = json_decode((string) file_get_contents($file), true);
    if (!is_array($left) || !array_key_exists('row', $left)) { @unlink($file); return false; }
    $pdo->exec('DELETE FROM kuma_settings');
    if (is_array($left['row']) && $left['row']) {
        $cols = array_keys($left['row']);
        $pdo->prepare('INSERT INTO kuma_settings (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($left['row']));
    }
    @unlink($file);
    return true;
}
