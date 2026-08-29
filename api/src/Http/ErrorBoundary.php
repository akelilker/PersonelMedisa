<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

use Throwable;

/**
 * Canonical outer error boundary for the whole API.
 *
 * Without it an uncaught Throwable or a shutdown-time fatal leaves PHP to answer
 * with `display_errors=Off` defaults: HTTP 500, `text/html`, zero bytes. That is
 * indistinguishable from a transport failure and carries nothing to debug with,
 * so a production outage cannot be attributed to an owner at all.
 *
 * The split is deliberate. The client only ever learns that the request failed
 * and which correlation id to quote; the exception class, message, file, line and
 * stack frames go to the server error log under that same id. Response bodies
 * therefore stay free of messages, SQL, credentials and personal data even when
 * the underlying failure is a database error.
 */
final class ErrorBoundary
{
    /** Public error code; the API contract already uses INTERNAL_ERROR for 500s. */
    public const CODE = 'INTERNAL_SERVER_ERROR';

    public const MESSAGE = 'Beklenmeyen bir sunucu hatasi olustu.';

    private const LOG_PREFIX = '[medisa-api-error]';

    /**
     * Secondary sink under the web-denied api/runtime directory. error_log() alone
     * lands wherever the host decides, which on shared hosting is frequently a
     * place the deploy tooling cannot reach; this path is deterministic and
     * readable by the existing read-only FTP diagnostics owner.
     */
    private const RUNTIME_LOG_RELATIVE = '/runtime/api-error-boundary.log';

    /** Keeps the sink bounded; diagnostics only ever need the recent tail. */
    private const RUNTIME_LOG_MAX_BYTES = 262144;

    /** Deepest frames are the useful ones; a full trace only bloats the log. */
    private const MAX_TRACE_FRAMES = 12;

    /** @var bool Guards against answering twice when a handler itself fails. */
    private static $handled = false;

    /**
     * Installs both halves of the boundary. Safe to call more than once.
     */
    public static function install()
    {
        set_exception_handler(function (Throwable $error) {
            self::handleThrowable($error);
        });

        // Fatals (E_ERROR, parse/compile errors, exhausted memory) never reach the
        // exception handler, so the shutdown hook is the only place to catch them.
        register_shutdown_function(function () {
            self::handleShutdown();
        });
    }

    public static function handleThrowable(Throwable $error)
    {
        $errorId = self::newErrorId();

        self::log($errorId, [
            'kind' => 'uncaught_throwable',
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => self::formatTrace($error),
        ]);

        self::respond($errorId);
    }

    public static function handleShutdown()
    {
        if (self::$handled) {
            return;
        }

        $last = error_get_last();
        if ($last === null || !self::isFatal((int) ($last['type'] ?? 0))) {
            return;
        }

        $errorId = self::newErrorId();

        self::log($errorId, [
            'kind' => 'shutdown_fatal',
            'type' => self::describeErrorType((int) $last['type']),
            'message' => (string) ($last['message'] ?? ''),
            'file' => (string) ($last['file'] ?? ''),
            'line' => (int) ($last['line'] ?? 0),
        ]);

        self::respond($errorId);
    }

    /**
     * Emits the client-safe body: contract shape, generic code, correlation id.
     */
    private static function respond($errorId)
    {
        self::$handled = true;

        // A half-written response cannot be replaced; the log entry is the record.
        if (headers_sent()) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);

        echo json_encode([
            'data' => null,
            'meta' => ['error_id' => $errorId],
            'errors' => [
                [
                    'code' => self::CODE,
                    'message' => self::MESSAGE,
                    'error_id' => $errorId,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Opaque, unguessable and carrying no request content.
     */
    private static function newErrorId()
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return sprintf('%08x%08x', mt_rand(), mt_rand());
        }
    }

    /**
     * Request method and path help locate the failing route. The query string is
     * left out on purpose: search terms and identifiers are user data.
     *
     * @param array<string, mixed> $detail
     */
    private static function log($errorId, array $detail)
    {
        $detail['error_id'] = $errorId;
        $detail['method'] = isset($_SERVER['REQUEST_METHOD'])
            ? (string) $_SERVER['REQUEST_METHOD']
            : '';
        $detail['path'] = self::requestPathWithoutQuery();

        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $encoded = sprintf('{"error_id":"%s","kind":"log_encode_failed"}', $errorId);
        }

        $line = self::LOG_PREFIX . ' ' . $encoded;
        error_log($line);
        self::appendRuntimeLog($line);
    }

    /**
     * Best effort by contract: a diagnostics sink must never be able to turn one
     * failed request into a second, different failure.
     */
    private static function appendRuntimeLog($line)
    {
        try {
            $path = dirname(__DIR__, 2) . self::RUNTIME_LOG_RELATIVE;
            if (!is_dir(dirname($path))) {
                return;
            }
            if (is_file($path) && filesize($path) > self::RUNTIME_LOG_MAX_BYTES) {
                // Truncate rather than rotate: no file naming to keep in sync.
                file_put_contents($path, '');
            }
            file_put_contents(
                $path,
                gmdate('c') . ' ' . $line . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e) {
            // Swallowed on purpose; error_log() above already carries the detail.
        }
    }

    private static function requestPathWithoutQuery()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $queryStart = strpos($uri, '?');

        return $queryStart === false ? $uri : substr($uri, 0, $queryStart);
    }

    /**
     * File and line per frame only. Argument values would carry request data.
     */
    private static function formatTrace(Throwable $error)
    {
        $frames = [];
        foreach ($error->getTrace() as $frame) {
            if (count($frames) >= self::MAX_TRACE_FRAMES) {
                break;
            }
            $frames[] = sprintf(
                '%s:%s %s%s%s()',
                isset($frame['file']) ? (string) $frame['file'] : '?',
                isset($frame['line']) ? (string) $frame['line'] : '?',
                isset($frame['class']) ? (string) $frame['class'] : '',
                isset($frame['type']) ? (string) $frame['type'] : '',
                isset($frame['function']) ? (string) $frame['function'] : '?'
            );
        }

        return $frames;
    }

    private static function isFatal($type)
    {
        return in_array(
            $type,
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true
        );
    }

    private static function describeErrorType($type)
    {
        switch ($type) {
            case E_ERROR:
                return 'E_ERROR';
            case E_PARSE:
                return 'E_PARSE';
            case E_CORE_ERROR:
                return 'E_CORE_ERROR';
            case E_COMPILE_ERROR:
                return 'E_COMPILE_ERROR';
            case E_USER_ERROR:
                return 'E_USER_ERROR';
            default:
                return 'E_UNKNOWN_' . (int) $type;
        }
    }
}
