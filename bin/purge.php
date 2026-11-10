<?php
declare(strict_types=1);
// Retention job. Run daily, for example with Windows Task Scheduler:  C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\purge.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../includes/core/bootstrap.php';
$n = purge_expired_data(db());
echo "Removed $n activity-log rows older than " . LOG_RETENTION_MONTHS . " months.\n";
