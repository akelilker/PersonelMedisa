<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;

/**
 * Authenticated, allowlisted client telemetry ingestion.
 * No DB persistence — privacy-safe server log only. Rejects unknown fields / oversized bodies.
 *
 * Actor attribution is server-authoritative (AuthMiddleware). Client user_id is rejected.
 * client_ui_profile / client_active_sube_id are non-authoritative diagnostic context only —
 * this endpoint is never an audit trail.
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
        // Non-authoritative client context only (never actor identity):
        'client_active_sube_id',
        'client_ui_profile',
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

        // Authoritative identity must never be client-supplied.
        if (array_key_exists('user_id', $body) || array_key_exists('actor_user_id', $body)) {
            JsonResponse::badRequest(
                'Client must not supply actor identity fields.',
                'TELEMETRY_CLIENT_ACTOR_FORBIDDEN',
                array_key_exists('user_id', $body) ? 'user_id' : 'actor_user_id'
            );
        }
        // Legacy non-authoritative names rejected so clients cannot spoof via rename confusion.
        if (array_key_exists('active_sube_id', $body) || array_key_exists('ui_profile', $body)) {
            JsonResponse::badRequest(
                'Use client_active_sube_id / client_ui_profile for non-authoritative context.',
                'TELEMETRY_LEGACY_CONTEXT_FIELD',
                array_key_exists('active_sube_id', $body) ? 'active_sube_id' : 'ui_profile'
            );
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

        $actorUserId = isset($user['id']) ? (int) $user['id'] : null;

        $safe = [
            'event_type' => $eventType,
            'error_fingerprint' => self::clip($fingerprint, self::MAX_FINGERPRINT),
            // Server-authenticated only — never taken from body.
            'actor_user_id' => $actorUserId,
        ];

        foreach (['error_code', 'source', 'route_template', 'endpoint_template', 'method', 'app_version', 'app_env', 'release_sha', 'timestamp', 'request_id'] as $strKey) {
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

        if (array_key_exists('client_ui_profile', $body) && $body['client_ui_profile'] !== null) {
            if (!is_string($body['client_ui_profile'])) {
                JsonResponse::badRequest('Invalid string field.', 'TELEMETRY_FIELD_TYPE', 'client_ui_profile');
            }
            // Non-authoritative diagnostic context only.
            $safe['client_ui_profile'] = self::clip($body['client_ui_profile'], self::MAX_STRING);
        }

        foreach (['status', 'attempt_count'] as $numKey) {
            if (!array_key_exists($numKey, $body) || $body[$numKey] === null) {
                continue;
            }
            if (!is_int($body[$numKey]) && !(is_float($body[$numKey]) && (int) $body[$numKey] == $body[$numKey])) {
                JsonResponse::badRequest('Invalid numeric field.', 'TELEMETRY_FIELD_TYPE', $numKey);
            }
            $safe[$numKey] = (int) $body[$numKey];
        }

        if (array_key_exists('client_active_sube_id', $body) && $body['client_active_sube_id'] !== null) {
            if (!is_int($body['client_active_sube_id']) && !(is_float($body['client_active_sube_id']) && (int) $body['client_active_sube_id'] == $body['client_active_sube_id'])) {
                JsonResponse::badRequest('Invalid numeric field.', 'TELEMETRY_FIELD_TYPE', 'client_active_sube_id');
            }
            $clientSube = (int) $body['client_active_sube_id'];
            $scopeIds = self::actorSubeIds($user);
            if (count($scopeIds) > 0 && !in_array($clientSube, $scopeIds, true)) {
                JsonResponse::badRequest(
                    'client_active_sube_id is outside authenticated scope.',
                    'TELEMETRY_SUBE_OUT_OF_SCOPE',
                    'client_active_sube_id'
                );
            }
            // Non-authoritative; only attach when scope is empty (cannot validate) or in-scope.
            if (count($scopeIds) === 0 || in_array($clientSube, $scopeIds, true)) {
                $safe['client_active_sube_id'] = $clientSube;
            }
        }

        // Never echo stack/body/internals to client.
        error_log('[medisa-client-telemetry] ' . json_encode($safe, JSON_UNESCAPED_UNICODE));

        JsonResponse::success(['accepted' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    private static function actorSubeIds(array $user)
    {
        $ids = [];
        if (isset($user['sube_ids']) && is_array($user['sube_ids'])) {
            $raw = $user['sube_ids'];
        } elseif (isset($user['subeler']) && is_array($user['subeler'])) {
            $raw = $user['subeler'];
        } else {
            $raw = [];
        }
        foreach ($raw as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[] = $n;
            }
        }
        return array_values(array_unique($ids));
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
