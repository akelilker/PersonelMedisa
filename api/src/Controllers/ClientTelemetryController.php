<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;

/**
 * Authenticated, allowlisted client telemetry ingestion.
 * No DB persistence — privacy-safe server log only. Rejects unknown fields / oversized bodies.
 */
class ClientTelemetryController
{
    private const MAX_BODY_BYTES = 4096;
    private const MAX_STRING = 256;
    private const MAX_FINGERPRINT = 64;

    /** @var array<int, string> */
    private static $allowedEventTypes = [
        'client_error',
        'api_fail',
        'react_boundary',
        'window_error',
        'unhandled_rejection',
    ];

    /** @var array<int, string> */
    private static $allowedKeys = [
        'event_type',
        'error_fingerprint',
        'error_code',
        'source',
        'route_template',
        'endpoint_template',
        'status',
        'method',
        'app_version',
        'app_env',
        'release_sha',
        'timestamp',
        'request_id',
        'attempt_count',
        'user_id',
        'active_sube_id',
        'ui_profile',
    ];

    public static function ingest(Request $request)
    {
        // Allow during forced password-change so onboarding errors remain reportable.
        $user = AuthMiddleware::authenticate($request, true, true);

        $raw = $request->getRawBody();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            JsonResponse::badRequest('Telemetry body too large.', 'TELEMETRY_BODY_TOO_LARGE');
        }

        if ($request->hasInvalidJsonBody()) {
            JsonResponse::badRequest('Invalid JSON body.', 'INVALID_JSON');
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            JsonResponse::badRequest('Telemetry payload must be an object.', 'INVALID_TELEMETRY');
        }

        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, self::$allowedKeys, true)) {
                JsonResponse::badRequest('Unknown telemetry field.', 'TELEMETRY_UNKNOWN_FIELD', is_string($key) ? $key : null);
            }
        }

        $eventType = isset($body['event_type']) && is_string($body['event_type']) ? $body['event_type'] : '';
        if (!in_array($eventType, self::$allowedEventTypes, true)) {
            JsonResponse::badRequest('Invalid event_type.', 'TELEMETRY_EVENT_TYPE', 'event_type');
        }

        $fingerprint = isset($body['error_fingerprint']) && is_string($body['error_fingerprint'])
            ? trim($body['error_fingerprint'])
            : '';
        if ($fingerprint === '' || strlen($fingerprint) > self::MAX_FINGERPRINT) {
            JsonResponse::badRequest('Invalid error_fingerprint.', 'TELEMETRY_FINGERPRINT', 'error_fingerprint');
        }

        $safe = [
            'event_type' => $eventType,
            'error_fingerprint' => self::clip($fingerprint, self::MAX_FINGERPRINT),
            'actor_user_id' => isset($user['id']) ? (int) $user['id'] : null,
        ];

        foreach (['error_code', 'source', 'route_template', 'endpoint_template', 'method', 'app_version', 'app_env', 'release_sha', 'timestamp', 'request_id', 'ui_profile'] as $strKey) {
            if (!array_key_exists($strKey, $body)) {
                continue;
            }
            if ($body[$strKey] === null) {
                $safe[$strKey] = null;
                continue;
            }
            if (!is_string($body[$strKey])) {
                JsonResponse::badRequest('Invalid string field.', 'TELEMETRY_FIELD_TYPE', $strKey);
            }
            $safe[$strKey] = self::clip($body[$strKey], self::MAX_STRING);
        }

        foreach (['status', 'attempt_count', 'user_id', 'active_sube_id'] as $numKey) {
            if (!array_key_exists($numKey, $body) || $body[$numKey] === null) {
                continue;
            }
            if (!is_int($body[$numKey]) && !(is_float($body[$numKey]) && (int) $body[$numKey] == $body[$numKey])) {
                JsonResponse::badRequest('Invalid numeric field.', 'TELEMETRY_FIELD_TYPE', $numKey);
            }
            $safe[$numKey] = (int) $body[$numKey];
        }

        // Never echo stack/body/internals to client.
        error_log('[medisa-client-telemetry] ' . json_encode($safe, JSON_UNESCAPED_UNICODE));

        JsonResponse::success(['accepted' => true]);
    }

    private static function clip($value, $max)
    {
        $s = trim((string) $value);
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max);
    }
}
