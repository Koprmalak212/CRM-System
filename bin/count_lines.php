<?php
declare(strict_types=1);

// Counts the lines of code of the project (PHP, JavaScript, CSS, SQL and workflow files; blank lines are not counted) and writes the
// number into the "lines of code" badge at the top of README.md. Run it after larger changes:   php bin/count_lines.php
// Left out: third-party and generated files (node_modules, the compiled assets/css/app.css, fonts), data dumps, screenshots, runtime files.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$root = dirname(__DIR__);
$skipDirs = ['.git', 'node_modules', 'vendor', 'storage', '.impeccable', 'screenshots', 'fonts'];
$skipFiles = ['app.css', 'ops_console_structure.sql', 'ops_console_data.sql'];
$langs = ['php' => 'PHP', 'js' => 'JavaScript', 'css' => 'CSS', 'sql' => 'SQL', 'yml' => 'Workflows'];

$count = array_fill_keys(array_keys($langs), 0);
$tests = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $f) use ($skipDirs, $skipFiles): bool {
        if ($f->isDir()) { return !in_array($f->getFilename(), $skipDirs, true); }
        return !in_array($f->getFilename(), $skipFiles, true);
    }
));
foreach ($it as $f) {
    $ext = strtolower($f->getExtension());
    if (!isset($langs[$ext])) { continue; }
    $n = 0;
    $h = fopen($f->getPathname(), 'rb');
    if ($h === false) { continue; }
    while (($line = fgets($h)) !== false) { if (trim($line) !== '') { $n++; } }
    fclose($h);
    $count[$ext] += $n;
    if (str_contains(str_replace('\\', '/', $f->getPathname()), '/tests/')) { $tests += $n; }
}
$total = array_sum($count);
foreach ($count as $ext => $n) { printf("  %-11s %8s\n", $langs[$ext], number_format($n)); }
printf("  %-11s %8s   (of which tests: %s)\n", 'Total', number_format($total), number_format($tests));

$readme = $root . '/README.md';
$text = (string) file_get_contents($readme);
$badge = '![Lines of code](https://img.shields.io/badge/lines%20of%20code-' . rawurlencode(number_format($total)) . '-111D36?style=for-the-badge&labelColor=111D36&color=C7F36B)';
$new = preg_replace('~!\[Lines of code\]\(https://img\.shields\.io/badge/lines%20of%20code-[^)]*\)~', $badge, $text, 1, $replaced);
if ($replaced === 0) {
    fwrite(STDERR, "README.md has no lines-of-code badge to update.\n");
    exit(1);
}
if ($new !== $text) { file_put_contents($readme, $new); echo "README.md updated.\n"; } else { echo "README.md already shows this number.\n"; }
