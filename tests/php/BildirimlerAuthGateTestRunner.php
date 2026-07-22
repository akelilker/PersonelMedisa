<?php

declare(strict_types=1);

/**
 * S90: /bildirimler (and sibling routes) must return JSON 401 before DB/SubeScope
 * when Authorization is missing or invalid. Also asserts Router imports the controller.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\BildirimlerController;
use Medisa\Api\Http\Request;
use Medisa\Api\Router;

function s90Assert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s90ResetAuth(): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

/**
 * @param array<string, string> $headers
 * @param array<string, mixed> $query
 * @return array{status:int, payload:array<string,mixed>, stderr:string}
 */
function s90InvokeChild(string $mode, string $method, string $path, array $headers = [], array $query = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 's90_');
    if ($statusFile === false) {
        throw new RuntimeException('tempnam failed');
    }

    $payload = [
        'mode' => $mode,
        'method' => $method,
        'path' => $path,
        'headers' => $headers,
        'query' => $query,
        'status_file' => $statusFile,
    ];
    $payloadFile = tempnam(sys_get_temp_dir(), 's90p_');
    if ($payloadFile === false) {
        throw new RuntimeException('tempnam payload failed');
    }
    file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=pdo_mysql';
    }

    $child = __DIR__ . '/s90-bildirimler-auth-child.php';
    $cmd = array_merge([PHP_BINARY], $phpArgs, [$child, $payloadFile]);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($proc)) {
        throw new RuntimeException('proc_open failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $statusRaw = @file_get_contents($statusFile);
    @unlink($statusFile);
    @unlink($payloadFile);
    $status = is_string($statusRaw) ? (int) trim($statusRaw) : 0;
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'status' => $status,
        'payload' => $decoded,
        'stderr' => (string) $stderr,
        'stdout' => (string) $stdout,
    ];
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/BildirimlerController.php');

s90Assert(
    strpos($routerSource, 'use Medisa\\Api\\Controllers\\BildirimlerController;') !== false,
    'Router imports Controllers\\BildirimlerController'
);
s90Assert(
    strpos($routerSource, 'BildirimlerController::list') !== false,
    'Router dispatches GET /bildirimler to list'
);

foreach (['list', 'detail', 'create', 'update', 'cancel', 'submit', 'gunlukOzet'] as $method) {
    $fnPos = strpos($controllerSource, 'public static function ' . $method);
    s90Assert($fnPos !== false, 'method exists: ' . $method);
    $nextFn = strpos($controllerSource, 'public static function ', $fnPos + 10);
    $slice = $nextFn === false
        ? substr($controllerSource, $fnPos)
        : substr($controllerSource, $fnPos, $nextFn - $fnPos);
    $authInMethod = strpos($slice, 'AuthMiddleware::authenticate($request, true)');
    s90Assert($authInMethod !== false, 'auth gate present for ' . $method);
    $connInMethod = strpos($slice, 'Connection::get()');
    if ($connInMethod !== false) {
        s90Assert($authInMethod < $connInMethod, $method . ' authenticates before Connection::get');
    }
}

$cases = [
    ['router', 'GET', '/bildirimler', [], ['page' => '1', 'limit' => '8'], 'router no auth → 401'],
    ['controller_list', 'GET', '/bildirimler', [], ['page' => '1', 'limit' => '8'], 'controller list no auth → 401'],
    ['controller_list', 'GET', '/bildirimler', ['Authorization' => 'Bearer invalid.token.value'], ['page' => '1', 'limit' => '8'], 'controller list invalid token → 401'],
    ['router', 'GET', '/personeller', [], [], 'router personeller no auth → 401'],
    ['router', 'GET', '/referans/departmanlar', [], [], 'router referans departmanlar no auth → 401'],
];

foreach ($cases as [$mode, $method, $path, $headers, $query, $name]) {
    $result = s90InvokeChild($mode, $method, $path, $headers, $query);
    s90Assert($result['stderr'] === '' || stripos($result['stderr'], 'Warning') === false && stripos($result['stderr'], 'Fatal') === false, $name . ' no php fatal/warning in stderr');
    // Allow empty stderr only; if warning text appears fail
    s90Assert(stripos($result['stderr'], 'Fatal') === false, $name . ' no Fatal');
    s90Assert(stripos($result['stderr'], 'Warning') === false, $name . ' no Warning');
    s90Assert($result['status'] === 401, $name . ' status=401 got=' . $result['status']);
    $errors = $result['payload']['errors'] ?? null;
    s90Assert(is_array($errors) && isset($errors[0]['code']) && $errors[0]['code'] === 'UNAUTHORIZED', $name . ' UNAUTHORIZED code');
    s90Assert(!isset($result['payload']['stack']), $name . ' no stack');
}

echo "S90_BILDIRIMLER_AUTH_GATE_OK\n";
