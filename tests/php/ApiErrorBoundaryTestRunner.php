<?php

declare(strict_types=1);

/**
 * Proves the canonical API error boundary: uncaught Throwables and shutdown-time
 * fatals both answer with the contract shape, a generic code and a correlation id,
 * while the technical detail only ever reaches the error log under that same id.
 */

require dirname(__DIR__, 2) . '/api/src/Http/ErrorBoundary.php';

use Medisa\Api\Http\ErrorBoundary;

$failures = [];

function aebAssert(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $label . ($detail === '' ? '' : ' :: ' . $detail);
    }
}

/**
 * Runs one isolated PHP subprocess so the real handlers, real exit path and real
 * error_log destination are exercised instead of being simulated in-process.
 *
 * @return array{stdout: string, log: string, status: int}
 */
function aebRunScenario(string $phpBody): array
{
    $root = dirname(__DIR__, 2);
    $scriptPath = tempnam(sys_get_temp_dir(), 'aeb_') . '.php';
    $logPath = tempnam(sys_get_temp_dir(), 'aeblog_');

    $script = "<?php\n"
        . "require " . var_export($root . '/api/src/Http/ErrorBoundary.php', true) . ";\n"
        . "Medisa\\Api\\Http\\ErrorBoundary::install();\n"
        . "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_SERVER['REQUEST_URI'] = '/api/personeller?search=Gizli%20Ad&token=secret';\n"
        . $phpBody . "\n";

    file_put_contents($scriptPath, $script);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        PHP_BINARY . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($logPath)
            . ' -d memory_limit=64M ' . escapeshellarg($scriptPath),
        $descriptors,
        $pipes
    );

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';

    @unlink($scriptPath);
    @unlink($logPath);

    return ['stdout' => (string) $stdout, 'log' => $log, 'status' => (int) $status];
}

/** @return array<string, mixed>|null */
function aebDecode(string $stdout): ?array
{
    $decoded = json_decode($stdout, true);

    return is_array($decoded) ? $decoded : null;
}

// A) Uncaught Throwable carrying a message a response must never repeat.
$thrown = aebRunScenario(
    "throw new \\RuntimeException('SQLSTATE[42S22] Unknown column tc_kimlik_no of 12345678901');"
);
$body = aebDecode($thrown['stdout']);

aebAssert('A1 uncaught throwable emits json body', $body !== null, $thrown['stdout']);
aebAssert('A2 contract shape preserved', $body !== null
    && array_key_exists('data', $body)
    && array_key_exists('meta', $body)
    && array_key_exists('errors', $body));
aebAssert('A3 data is null', $body !== null && $body['data'] === null);
aebAssert('A4 generic public code', $body !== null
    && ($body['errors'][0]['code'] ?? null) === 'INTERNAL_SERVER_ERROR');
aebAssert('A5 generic public message', $body !== null
    && ($body['errors'][0]['message'] ?? null) === ErrorBoundary::MESSAGE);

$errorId = $body['errors'][0]['error_id'] ?? '';
aebAssert('A6 correlation id present and opaque', is_string($errorId)
    && preg_match('/^[0-9a-f]{16}$/', $errorId) === 1, (string) $errorId);
aebAssert('A7 correlation id mirrored in meta', $body !== null
    && ($body['meta']['error_id'] ?? null) === $errorId);

// B) Nothing technical or sensitive may cross into the response.
$stdout = $thrown['stdout'];
aebAssert('B1 no exception class in response', strpos($stdout, 'RuntimeException') === false);
aebAssert('B2 no exception message in response', strpos($stdout, 'SQLSTATE') === false);
aebAssert('B3 no SQL identifier in response', strpos($stdout, 'Unknown column') === false);
aebAssert('B4 no PII in response', strpos($stdout, '12345678901') === false);
aebAssert('B5 no file path in response', strpos($stdout, '.php') === false);
aebAssert('B6 no line number key in response', strpos($stdout, '"line"') === false
    && strpos($stdout, '"file"') === false);
aebAssert('B7 no trace in response', strpos($stdout, 'trace') === false);
aebAssert('B8 no token from request uri in response', strpos($stdout, 'secret') === false);
aebAssert('B9 no search term from request uri in response', strpos($stdout, 'Gizli') === false);

// C) The log keeps the detail, tied to the very same correlation id.
$log = $thrown['log'];
aebAssert('C1 log carries the correlation id', strpos($log, (string) $errorId) !== false, $log);
aebAssert('C2 log carries exception class', strpos($log, 'RuntimeException') !== false);
aebAssert('C3 log carries message', strpos($log, 'SQLSTATE[42S22]') !== false);
aebAssert('C4 log carries file and line', strpos($log, '"file"') !== false
    && strpos($log, '"line"') !== false);
aebAssert('C5 log carries trace frames', strpos($log, '"trace"') !== false);
aebAssert('C6 log carries request method and path', strpos($log, '"method":"GET"') !== false
    && strpos($log, '/api/personeller') !== false);
aebAssert('C7 log omits the query string', strpos($log, 'search=') === false
    && strpos($log, 'secret') === false);
aebAssert('C8 log is single-line tagged json', strpos($log, '[medisa-api-error]') !== false);

// D) Shutdown-time fatals never reach an exception handler; the boundary must
//    still answer instead of leaving PHP's empty 500.
// E_USER_ERROR bypasses set_exception_handler entirely, unlike PHP 8's Error
// objects, so it exercises the shutdown half of the boundary on its own.
$fatal = aebRunScenario("trigger_error('fatal detail of 12345678901', E_USER_ERROR);");
$fatalBody = aebDecode($fatal['stdout']);
aebAssert('D1 fatal emits json body', $fatalBody !== null, $fatal['stdout']);
aebAssert('D2 fatal uses the generic code', $fatalBody !== null
    && ($fatalBody['errors'][0]['code'] ?? null) === 'INTERNAL_SERVER_ERROR');
$fatalId = $fatalBody['errors'][0]['error_id'] ?? '';
aebAssert('D3 fatal correlation id opaque', is_string($fatalId)
    && preg_match('/^[0-9a-f]{16}$/', $fatalId) === 1);
aebAssert('D4 fatal logged as shutdown_fatal', strpos($fatal['log'], 'shutdown_fatal') !== false);
aebAssert('D5 fatal log carries the same id', strpos($fatal['log'], (string) $fatalId) !== false);
aebAssert('D6 fatal detail stays out of the response',
    strpos($fatal['stdout'], 'fatal detail') === false
    && strpos($fatal['stdout'], '12345678901') === false);
aebAssert('D7 fatal detail reaches the log', strpos($fatal['log'], 'fatal detail') !== false);

// D8) A PHP 8 Error (undefined function) is a Throwable, so it must be reported
//     through the exception half rather than being missed by both.
$phpError = aebRunScenario('undefined_function_that_does_not_exist();');
$phpErrorBody = aebDecode($phpError['stdout']);
aebAssert('D8 php Error reported as uncaught throwable', $phpErrorBody !== null
    && ($phpErrorBody['errors'][0]['code'] ?? null) === 'INTERNAL_SERVER_ERROR'
    && strpos($phpError['log'], 'uncaught_throwable') !== false, $phpError['stdout']);

// E) Correlation ids must not be reused across requests.
$second = aebRunScenario("throw new \\RuntimeException('second');");
$secondBody = aebDecode($second['stdout']);
aebAssert('E1 ids differ per request', $secondBody !== null
    && ($secondBody['errors'][0]['error_id'] ?? '') !== $errorId);

// F) A clean exit must stay untouched by the shutdown hook.
$clean = aebRunScenario("echo json_encode(['data' => ['ok' => true]]);");
aebAssert('F1 successful response not rewritten', trim($clean['stdout']) === '{"data":{"ok":true}}',
    $clean['stdout']);
aebAssert('F2 no error logged on success', trim($clean['log']) === '', $clean['log']);

// G) A response already flushed cannot be replaced; only the log records it.
$partial = aebRunScenario("echo '{\"data\":\"partial\"}'; flush(); throw new \\RuntimeException('late');");
aebAssert('G1 partial output preserved',
    strpos($partial['stdout'], '{"data":"partial"}') === 0, $partial['stdout']);
aebAssert('G2 late failure still logged', strpos($partial['log'], 'late') !== false);

// I) The web-denied runtime sink mirrors the same line, because error_log() on
//    shared hosting lands where the deploy tooling cannot read it.
$runtimeLogPath = dirname(__DIR__, 2) . '/api/runtime/api-error-boundary.log';
aebAssert('I1 runtime sink written', is_file($runtimeLogPath), $runtimeLogPath);
if (is_file($runtimeLogPath)) {
    $runtimeLog = (string) file_get_contents($runtimeLogPath);
    aebAssert('I2 runtime sink carries the correlation id',
        strpos($runtimeLog, (string) $errorId) !== false);
    aebAssert('I3 runtime sink carries the technical detail',
        strpos($runtimeLog, 'SQLSTATE[42S22]') !== false);
    aebAssert('I4 runtime sink omits the query string',
        strpos($runtimeLog, 'search=') === false && strpos($runtimeLog, 'secret') === false);
}
aebAssert('I5 runtime sink stays under the web-denied runtime directory',
    is_file(dirname(__DIR__, 2) . '/api/runtime/.htaccess'));

// H) The boundary is actually installed by the API bootstrap.
$bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/api/src/bootstrap.php');
aebAssert('H1 bootstrap installs the boundary',
    strpos($bootstrap, 'ErrorBoundary::install()') !== false);
aebAssert('H2 boundary installed before config is required',
    strpos($bootstrap, 'ErrorBoundary::install()') < strpos($bootstrap, "Config/config.php"));

if (count($failures) > 0) {
    fwrite(STDERR, "verify-api-error-boundary: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo "verify-api-error-boundary: OK\n";
