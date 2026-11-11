<?php
// A stand-in 3CX for tests/run.php: php -S 127.0.0.1:3198 tests/mock_3cx.php   The first part of the path picks the behaviour: /api accepts an API client only, /admin a Management Console login only, /2fa a login that wants a security code, /none neither, /noperm an API client whose token works but may not read the call history (HTTP 403).
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
preg_match('#^/(api|admin|2fa|none|noperm|slow|noorder|broken|logdata)(/.*)$#', $u, $m); $mode = $m[1] ?? 'none'; $path = $m[2] ?? $u;
$body = (string) file_get_contents('php://input');
header('Content-Type: application/json');
if ($path === '/connect/token') {
    parse_str($body, $f);
    if (in_array($mode, ['api', 'noperm', 'slow', 'noorder', 'broken', 'logdata'], true) && ($f['client_id'] ?? '') === 'apiclient' && ($f['client_secret'] ?? '') === 'apisecret') { echo json_encode(['access_token' => 'tok-api', 'token_type' => 'Bearer']); exit; }
    http_response_code(400); echo json_encode(['error' => 'invalid_client']); exit;
}
if ($path === '/webclient/api/Login/GetAccessToken') {
    if ($mode === 'none' || $mode === 'api') { http_response_code(404); exit; }
    $j = json_decode($body, true);
    if ($mode === '2fa') { echo json_encode(['Status' => 'RequireSecurityCode']); exit; }
    if (($j['Username'] ?? '') === 'sysadmin' && ($j['Password'] ?? '') === 'adminpass') { echo json_encode(['Status' => 'AuthSuccess', 'Token' => ['token_type' => 'Bearer', 'access_token' => 'tok-admin']]); exit; }
    echo json_encode(['Status' => 'AuthFailed']); exit;
}
if ($path === '/xapi/v1/$metadata') {
    header('Content-Type: application/xml');
    echo '<?xml version="1.0"?><edmx:Edmx><edmx:DataServices><Schema><EntityType Name="CallHistoryView"><Key><PropertyRef Name="SegmentId"/></Key><Property Name="SegmentId" Type="Edm.Int32"/><Property Name="StartTime" Type="Edm.DateTimeOffset"/><Property Name="SourceDn" Type="Edm.String"/></EntityType><Function Name="GetCallLogData"/><EntitySet Name="ReportCallLogData"/></Schema></edmx:DataServices></edmx:Edmx>';
    exit;
}
if (str_starts_with($path, '/xapi/v1/ReportCallLogData/Pbx.GetCallLogData(')) {   // the call-log function: only the mode "logdata" has it
    if (!in_array($_SERVER['HTTP_AUTHORIZATION'] ?? '', ['Bearer tok-api', 'Bearer tok-admin'], true)) { http_response_code(401); exit; }
    if ($mode === 'noperm') { http_response_code(403); exit; }
    if ($mode !== 'logdata' || !str_contains($path, 'periodFrom=') || !str_contains($path, 'periodTo=')) { http_response_code(404); exit; }
    echo json_encode(['value' => [['SegmentId' => 11, 'StartTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 600), 'SourceType' => 'ExternalLine', 'SourceCallerId' => '+31101234567', 'DestinationType' => 'Extension', 'DestinationCallerId' => '100', 'TotalDuration' => 'PT1M12S', 'Status' => 'Answered']]]);
    exit;
}
if ($path === '/xapi/v1/CallHistoryView') {
    if ($mode === 'logdata') { http_response_code(500); exit; }   // like the PBX of the owner: the list with a filter answers HTTP 500 with nothing in it
    $ok = in_array($_SERVER['HTTP_AUTHORIZATION'] ?? '', ['Bearer tok-api', 'Bearer tok-admin'], true);
    if (!$ok) { http_response_code(401); exit; }
    if ($mode !== 'broken' && !str_contains((string) ($_SERVER['QUERY_STRING'] ?? ''), 'StartTime')) { http_response_code(400); echo json_encode(['error' => 'the call history must be asked for a period']); exit; }   // like a PBX with a large history
    if ($mode === 'slow') { sleep(3); }
    $q = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($mode === 'broken' || ($mode === 'noorder' && str_contains($q, 'orderby'))) { http_response_code(500); echo json_encode(['error' => ['code' => 'InternalError', 'message' => 'Invalid property in orderby']]); exit; }
    if ($mode === 'noorder') { echo json_encode(['value' => [['SegmentId' => 7, 'StartTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 600), 'SourceType' => 'Line', 'SourceCallerId' => '+31101234567', 'DestinationType' => 'Extension', 'DestinationCallerId' => '100', 'TalkingDuration' => 42, 'Answered' => true], ['SegmentId' => 3, 'StartTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 90 * 86400), 'SourceType' => 'Line', 'SourceCallerId' => '+31107654321', 'DestinationCallerId' => '101', 'TalkingDuration' => 5]]]); exit; }
    if ($mode === 'noperm') { http_response_code(403); echo json_encode(['error' => ['code' => 'Forbidden']]); exit; }
    echo json_encode(['value' => []]); exit;
}
http_response_code(404);
