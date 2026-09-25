<?php
require_once __DIR__ . '/api_bootstrap.php';

app_require_method('GET');

$probe = strtolower(trim((string)($_GET['probe'] ?? 'ready')));
if ($probe === 'live') {
    app_json_response([
        'status' => 'ok',
        'probe' => 'live',
        'request_id' => app_request_id(),
    ]);
}

$conn = app_db();
try {
    $result = $conn->query('SELECT 1');
} catch (Throwable $error) {
    $result = false;
}
if ($result === false) {
    app_json_response([
        'status' => 'error',
        'probe' => 'ready',
        'checks' => ['database' => 'failed'],
        'request_id' => app_request_id(),
    ], 503);
}

$conn->close();
app_json_response([
    'status' => 'ok',
    'probe' => 'ready',
    'checks' => ['database' => 'ok'],
    'request_id' => app_request_id(),
]);
