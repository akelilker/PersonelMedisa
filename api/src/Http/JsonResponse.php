<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

class JsonResponse
{
    /** @var bool */
    private static $capturing = false;

    /** @var array<string, mixed>|null First response produced inside the active capture scope. */
    private static $captured = null;

    /**
     * Arms the narrow in-process capture scope used by trusted CLI owners. Outside
     * this scope JsonResponse behaves exactly as before (emit + exit), so web
     * behaviour is unchanged.
     */
    public static function beginCapture(): void
    {
        self::$capturing = true;
        self::$captured = null;
    }

    /** Disarms capture and clears the captured response. Safe to call repeatedly. */
    public static function endCapture(): void
    {
        self::$capturing = false;
        self::$captured = null;
    }

    /** @return array<string, mixed>|null */
    public static function capturedResponse()
    {
        return self::$captured;
    }

    /** @param mixed $data */
    public static function success($data, $meta = [], $status = 200)
    {
        self::send([
            'data' => $data,
            'meta' => is_array($meta) ? $meta : [],
            'errors' => [],
        ], $status);
    }

    /**
     * @param array<int, array<string, string>> $errors
     * @param array<string, mixed> $meta
     */
    public static function error($status, $code, $message, $field = null, $meta = [])
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($field !== null && $field !== '') {
            $error['field'] = $field;
        }

        self::send([
            'data' => null,
            'meta' => is_array($meta) ? $meta : [],
            'errors' => [$error],
        ], $status);
    }

    public static function methodNotAllowed($message = 'Bu islem henuz desteklenmiyor.')
    {
        self::error(405, 'METHOD_NOT_ALLOWED', $message);
    }

    public static function unauthorized($message = 'Oturum gerekli.')
    {
        self::error(401, 'UNAUTHORIZED', $message);
    }

    public static function forbidden($message = 'Bu kayit aktif sube baglaminda goruntulenemiyor.')
    {
        self::error(403, 'FORBIDDEN', $message);
    }

    public static function notFound($message = 'Kayit bulunamadi.')
    {
        self::error(404, 'NOT_FOUND', $message);
    }

    public static function badRequest($message, $code = 'BAD_REQUEST', $field = null)
    {
        self::error(400, $code, $message, $field);
    }

    public static function serverError($message = 'Sunucu yapilandirmasi eksik.')
    {
        self::error(500, 'INTERNAL_ERROR', $message);
    }

    /** @param array<string, mixed> $payload */
    private static function send(array $payload, $status)
    {
        if (self::$capturing) {
            // Mirror exit() semantics without terminating the CLI worker: only the
            // first response wins (as it would on the web) and a signal unwinds the
            // controller so the capture scope can return the payload.
            if (self::$captured === null) {
                self::$captured = $payload;
            }
            throw new ResponseCaptured();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code((int) $status);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
