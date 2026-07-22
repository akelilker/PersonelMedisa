<?php

declare(strict_types=1);

/**
 * Child process for S90 auth gate: captures JsonResponse exit status/body.
 */

$payloadFile = $argv[1] ?? '';
if ($payloadFile === '' || !is_file($payloadFile)) {
    fwrite(STDERR, "payload missing\n");
    exit(2);
}

$payload = json_decode((string) file_get_contents($payloadFile), true);
if (!is_array($payload)) {
    fwrite(STDERR, "payload invalid\n");
    exit(2);
}

$statusFile = (string) ($payload['status_file'] ?? '');
$method = strtoupper((string) ($payload['method'] ?? 'GET'));
$path = (string) ($payload['path'] ?? '/');
$headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
$query = is_array($payload['query'] ?? null) ? $payload['query'] : [];
$mode = (string) ($payload['mode'] ?? 'router');

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $path . (count($query) > 0 ? ('?' . http_build_query($query)) : '');
$_GET = [];
foreach ($query as $k => $v) {
    $_GET[(string) $k] = $v;
}

// Clear potentially sticky auth headers from parent environment.
foreach (array_keys($_SERVER) as $key) {
    if (stripos((string) $key, 'HTTP_') === 0 || $key === 'REDIRECT_HTTP_AUTHORIZATION') {
        unset($_SERVER[$key]);
    }
}
foreach ($headers as $name => $value) {
    $headerName = strtoupper(str_replace('-', '_', (string) $name));
    $_SERVER['HTTP_' . $headerName] = (string) $value;
    if (strcasecmp((string) $name, 'Authorization') === 0) {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = (string) $value;
    }
}

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\BildirimlerController;
use Medisa\Api\Http\Request;
use Medisa\Api\Router;

$ref = new ReflectionClass(AuthMiddleware::class);
$prop = $ref->getProperty('user');
$prop->setAccessible(true);
$prop->setValue(null, null);

register_shutdown_function(static function () use ($statusFile) {
    $code = http_response_code();
    if (is_string($statusFile) && $statusFile !== '') {
        file_put_contents($statusFile, (string) $code);
    }
});

if ($mode === 'controller_list') {
    $request = new Request();
    $r = new ReflectionClass($request);
    foreach ([
        'method' => $method,
        'path' => $path,
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'jsonBody' => null,
        'jsonBodyParsed' => true,
        'jsonBodyInvalid' => false,
    ] as $name => $value) {
        $p = $r->getProperty($name);
        $p->setAccessible(true);
        $p->setValue($request, $value);
    }
    BildirimlerController::list($request);
    exit(0);
}

$router = new Router();
$router->dispatch();
