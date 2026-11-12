<?php
/**
 * HTTP front for the straighten demo, with the contract of the Python service it replaces:
 *
 *   POST /analyze/straighten   body: the full overhead frame (JPEG/PNG)
 *     200 image/jpeg, the upright crop; measurement as JSON in X-Straighten
 *     400 not an image, 422 no paper in the frame
 *   GET  /status               versions and runtime
 */
declare(strict_types=1);

require __DIR__ . '/../src/Straighten.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/analyze/straighten') {
    $body = file_get_contents('php://input');
    if ($body === '' || $body === false) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['detail' => 'Send an image (JPEG/PNG) as the request body']);
        return;
    }
    try {
        $started = hrtime(true);
        $result = Demo\Straighten::fromBytes($body);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['detail' => $e->getMessage()]);
        return;
    }
    if ($result === null) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['detail' => 'No paper found in the frame']);
        return;
    }
    header('Content-Type: image/jpeg');
    header('X-Straighten: ' . json_encode($result['info']));
    header('Server-Timing: straighten;dur=' . round((hrtime(true) - $started) / 1e6, 1));
    echo $result['jpeg'];
    return;
}

header('Content-Type: application/json');
echo json_encode([
    'service' => 'opencv-php straighten demo',
    'opencv' => CV\getVersionString(),
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'threadSafe' => (bool) PHP_ZTS,
    'threads' => CV\getNumThreads(),
    'endpoints' => ['POST /analyze/straighten'],
], JSON_PRETTY_PRINT) . "\n";
