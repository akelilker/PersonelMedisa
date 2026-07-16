<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

class DonemKapanisAuditService
{
    public const ACTION_CLOSE_BLOCKED = 'CLOSE_ATTEMPT_BLOCKED';
    public const ACTION_CLOSE_SUCCESS = 'CLOSE_SUCCESS';
    public const RESULT_BLOCKED = 'BLOCKED';
    public const RESULT_SEALED = 'SEALED';

    /** @param array<string, mixed> $user */
    public static function computeRequestHash(array $user, $subeId, $yil, $ay, array $payload, $preflightHash)
    {
        $canonical = [
            'actor_user_id' => (int) ($user['id'] ?? 0),
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'payload' => self::canonicalizePayload($payload),
            'preflight_hash' => (string) $preflightHash,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function computeResultHash(array $snapshot)
    {
        return hash('sha256', json_encode(self::canonicalizeRecursive($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $preflightSnapshot
     * @return array<string, mixed>|null existing row if idempotent
     */
    public static function recordBlocked(PDO $pdo, array $preflightSnapshot, array $user, $subeId, $yil, $ay, $requestHash)
    {
        return self::insertAudit(
            $pdo,
            (int) $subeId,
            (int) $yil,
            (int) $ay,
            self::ACTION_CLOSE_BLOCKED,
            self::RESULT_BLOCKED,
            null,
            (int) ($preflightSnapshot['blocker_count'] ?? 0),
            (int) ($preflightSnapshot['warning_count'] ?? 0),
            (string) ($preflightSnapshot['preflight_hash'] ?? ''),
            (string) $requestHash,
            $preflightSnapshot,
            (int) ($user['id'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $preflightSnapshot
     * @return array<string, mixed>|null
     */
    public static function recordSuccess(PDO $pdo, array $preflightSnapshot, array $user, $subeId, $yil, $ay, $muhurId, $requestHash)
    {
        return self::insertAudit(
            $pdo,
            (int) $subeId,
            (int) $yil,
            (int) $ay,
            self::ACTION_CLOSE_SUCCESS,
            self::RESULT_SEALED,
            (int) $muhurId,
            0,
            (int) ($preflightSnapshot['warning_count'] ?? 0),
            (string) ($preflightSnapshot['preflight_hash'] ?? ''),
            (string) $requestHash,
            $preflightSnapshot,
            (int) ($user['id'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $preflightSnapshot
     * @return array<string, mixed>|null
     */
    private static function insertAudit(
        PDO $pdo,
        $subeId,
        $yil,
        $ay,
        $action,
        $resultState,
        $muhurId,
        $blockerCount,
        $warningCount,
        $preflightHash,
        $requestHash,
        array $preflightSnapshot,
        $actorUserId
    ) {
        $existing = self::findByIdempotency($pdo, $subeId, $yil, $ay, $action, $requestHash);
        if ($existing) {
            return $existing;
        }

        $resultHash = self::computeResultHash([
            'action' => $action,
            'result_state' => $resultState,
            'muhur_id' => $muhurId,
            'preflight_hash' => $preflightHash,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO donem_kapanis_auditleri (
                sube_id, yil, ay, action, result_state, muhur_id,
                blocker_count, warning_count, preflight_hash, request_hash, result_hash,
                preflight_snapshot, actor_user_id
            ) VALUES (
                :sube_id, :yil, :ay, :action, :result_state, :muhur_id,
                :blocker_count, :warning_count, :preflight_hash, :request_hash, :result_hash,
                :preflight_snapshot, :actor_user_id
            )'
        );
        $stmt->execute([
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'action' => $action,
            'result_state' => $resultState,
            'muhur_id' => $muhurId,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
            'preflight_hash' => $preflightHash,
            'request_hash' => $requestHash,
            'result_hash' => $resultHash,
            'preflight_snapshot' => json_encode($preflightSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_user_id' => $actorUserId,
        ]);

        return self::findByIdempotency($pdo, $subeId, $yil, $ay, $action, $requestHash);
    }

    private static function findByIdempotency(PDO $pdo, $subeId, $yil, $ay, $action, $requestHash)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM donem_kapanis_auditleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND action = :action AND request_hash = :request_hash
             LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'action' => (string) $action,
            'request_hash' => (string) $requestHash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $payload */
    private static function canonicalizePayload(array $payload)
    {
        ksort($payload);

        return $payload;
    }

    /** @param mixed $value @return mixed */
    private static function canonicalizeRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isList($value)) {
            return array_map([self::class, 'canonicalizeRecursive'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalizeRecursive($item);
        }

        return $out;
    }

  /** @param array<mixed> $array */
    private static function isList(array $array)
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
