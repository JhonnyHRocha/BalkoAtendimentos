<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
try {
    $route = ($_SERVER['REQUEST_METHOD'] ?? 'GET').' '.parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!in_array($route, require __DIR__.'/Routes/api.php', true)) {
        http_response_code(404); echo '{"error":"not_found"}'; exit;
    }
    if ($route === 'GET /health') { echo '{"ok":true}'; exit; }
    $app = require __DIR__.'/bootstrap.php';
    $controller = new App\Controllers\WebhookController($app['store'], $app['config']);
    [$code, $body] = $controller->handle(file_get_contents('php://input', false, null, 0, 65537), $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
    http_response_code($code);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log(json_encode(['event'=>'request_error', 'type'=>get_class($e)]));
    http_response_code(503); echo '{"error":"service_unavailable"}';
}
