<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';

/*
 * "Stay signed in": the page calls this when the person answers the warning that the session is about to end (assets/js/ux.js).
 * Loading it counts as activity, exactly like opening any other page, so the 30 minutes start again. It never extends the 8 hour limit.
 * 204 = still signed in, 401 = the session has already ended.
 */
header('Cache-Control: no-store');
if (empty($_SESSION['admin'])) { http_response_code(401); exit; }
require_login();
http_response_code(204);
