<?php
// Mock of the partner API (router script for `php -S`).
// Behaviour depends on the click_id prefix so retry logic can be tested:
//   fail500*  -> always HTTP 500        (retried, ends as "failed")
//   rate429*  -> always HTTP 429        (retried)
//   fail400*  -> always HTTP 400        (NOT retried)
//   slow*     -> responds after 5s      (client timeout -> retried)
//   flaky*    -> 503, 503, then 200     (succeeds on the 3rd attempt)
//   anything else -> HTTP 200 {"status":"ok"}

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    return;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body) || empty($body['click_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Bad payload']);
    return;
}

$id = (string) $body['click_id'];
error_log('[partner-mock] received ' . json_encode($body));

if (str_starts_with($id, 'fail500')) {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
    return;
}
if (str_starts_with($id, 'rate429')) {
    http_response_code(429);
    echo json_encode(['status' => 'rate_limited']);
    return;
}
if (str_starts_with($id, 'fail400')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'bad request']);
    return;
}
if (str_starts_with($id, 'slow')) {
    sleep(5);
}
if (str_starts_with($id, 'flaky')) {
    $file = sys_get_temp_dir() . '/flaky_' . md5($id);
    $n = (int) @file_get_contents($file) + 1;
    file_put_contents($file, (string) $n);
    if ($n <= 2) {
        http_response_code(503);
        echo json_encode(['status' => 'unavailable']);
        return;
    }
}

echo json_encode(['status' => 'ok']);
