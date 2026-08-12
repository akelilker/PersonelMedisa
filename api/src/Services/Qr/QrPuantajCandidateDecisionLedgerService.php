<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use PDO;

/**
 * Append-only QR puantaj candidate decision ledger (S3F).
 * No UPDATE / DELETE business API.
 */
class QrPuantajCandidateDecisionLedgerService
{
    public const TABLE = 'qr_puantaj_candidate_decision_ledger';

    public static function assertSchemaReady(PDO $pdo)
    {
        $stmt = $pdo->query("SHOW TABLES LIKE '" . self::TABLE . "'");
        $exists = $stmt && (bool) $stmt->fetch(PDO::FETCH_NUM);
        if (!$exists) {
            throw new \RuntimeException('QR_PUANTAJ_DECISION_LEDGER_MISSING');
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function computeDecisionHash(array $row)
    {
        $payload = [
            'candidate_hash' => (string) ($row['candidate_hash'] ?? ''),
            'decision_type' => (string) ($row['decision_type'] ?? ''),
            'decision_reason' => (string) ($row['decision_reason'] ?? ''),
            'decided_by_user_id' => (int) ($row['decided_by_user_id'] ?? 0),
            'personel_id' => (int) ($row['personel_id'] ?? 0),
            'sube_id' => (int) ($row['sube_id'] ?? 0),
            'candidate_date' => (string) ($row['candidate_date'] ?? ''),
            'puantaj_id' => isset($row['puantaj_id']) && $row['puantaj_id'] !== null
                ? (int) $row['puantaj_id']
                : null,
            'algorithm_version' => (string) ($row['algorithm_version'] ?? ''),
            'interval_algorithm_version' => (string) ($row['interval_algorithm_version'] ?? ''),
            'decision_algorithm_version' => (string) ($row['decision_algorithm_version'] ?? ''),
            'candidate_snapshot' => self::decodeJsonField($row['candidate_snapshot'] ?? null),
            'before_puantaj_snapshot' => self::decodeJsonField($row['before_puantaj_snapshot'] ?? null),
            'after_puantaj_snapshot' => self::decodeJsonField($row['after_puantaj_snapshot'] ?? null),
            'request_nonce' => (string) ($row['request_nonce'] ?? ''),
            'supersedes_decision_id' => isset($row['supersedes_decision_id']) && $row['supersedes_decision_id'] !== null
                ? (int) $row['supersedes_decision_id']
                : null,
            'previous_decision_hash' => isset($row['previous_decision_hash']) && $row['previous_decision_hash'] !== null
                ? (string) $row['previous_decision_hash']
                : null,
            'created_at' => self::normalizeCreatedAt($row['created_at'] ?? ''),
        ];

        return hash('sha256', QrPuantajCandidateHashService::canonicalJson($payload));
    }

    private static function normalizeCreatedAt($value)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(\.(\d{1,6}))?$/', $raw, $m)) {
            $frac = isset($m[3]) ? str_pad($m[3], 6, '0') : '000000';

            return $m[1] . '.' . $frac;
        }

        return $raw;
    }

    public static function verifyDecisionHash(array $row)
    {
        $stored = strtolower(trim((string) ($row['decision_hash'] ?? '')));
        $computed = self::computeDecisionHash($row);

        return $stored !== '' && hash_equals($stored, $computed);
    }

    /**
     * Latest decision for personel + date + candidate_hash (current hash only).
     *
     * @return array<string,mixed>|null
     */
    public static function findLatestForCandidateHash(PDO $pdo, $personelId, $candidateDate, $candidateHash)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE personel_id = :personel_id
               AND candidate_date = :candidate_date
               AND candidate_hash = :candidate_hash
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'candidate_date' => (string) $candidateDate,
            'candidate_hash' => (string) $candidateHash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByUserNonce(PDO $pdo, $userId, $nonce)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE decided_by_user_id = :user_id AND request_nonce = :nonce
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => (int) $userId,
            'nonce' => (string) $nonce,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForPersonelDate(PDO $pdo, $personelId, $candidateDate)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE personel_id = :personel_id AND candidate_date = :candidate_date
             ORDER BY id ASC'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'candidate_date' => (string) $candidateDate,
        ]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function append(PDO $pdo, array $input)
    {
        $createdAt = isset($input['created_at']) && (string) $input['created_at'] !== ''
            ? (string) $input['created_at']
            : gmdate('Y-m-d H:i:s.u');
        // Normalize to DATETIME(6) — PHP u may be 6 digits already
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt)) {
            $createdAt .= '.000000';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.(\d{1,6})$/', $createdAt, $m)) {
            $createdAt = substr($createdAt, 0, 19) . '.' . str_pad($m[1], 6, '0');
        }

        $rowForHash = [
            'candidate_hash' => (string) $input['candidate_hash'],
            'decision_type' => (string) $input['decision_type'],
            'decision_reason' => (string) $input['decision_reason'],
            'decided_by_user_id' => (int) $input['decided_by_user_id'],
            'personel_id' => (int) $input['personel_id'],
            'sube_id' => (int) $input['sube_id'],
            'candidate_date' => (string) $input['candidate_date'],
            'puantaj_id' => $input['puantaj_id'] ?? null,
            'algorithm_version' => (string) $input['algorithm_version'],
            'interval_algorithm_version' => (string) $input['interval_algorithm_version'],
            'decision_algorithm_version' => (string) $input['decision_algorithm_version'],
            'candidate_snapshot' => $input['candidate_snapshot'],
            'before_puantaj_snapshot' => $input['before_puantaj_snapshot'] ?? null,
            'after_puantaj_snapshot' => $input['after_puantaj_snapshot'] ?? null,
            'request_nonce' => (string) $input['request_nonce'],
            'supersedes_decision_id' => $input['supersedes_decision_id'] ?? null,
            'previous_decision_hash' => $input['previous_decision_hash'] ?? null,
            'created_at' => $createdAt,
        ];
        $decisionHash = self::computeDecisionHash($rowForHash);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                personel_id, sube_id, candidate_date, candidate_hash, decision_type, decision_reason,
                puantaj_id, algorithm_version, interval_algorithm_version, decision_algorithm_version,
                candidate_snapshot, before_puantaj_snapshot, after_puantaj_snapshot,
                decided_by_user_id, request_nonce, supersedes_decision_id, previous_decision_hash,
                decision_hash, created_at
             ) VALUES (
                :personel_id, :sube_id, :candidate_date, :candidate_hash, :decision_type, :decision_reason,
                :puantaj_id, :algorithm_version, :interval_algorithm_version, :decision_algorithm_version,
                :candidate_snapshot, :before_puantaj_snapshot, :after_puantaj_snapshot,
                :decided_by_user_id, :request_nonce, :supersedes_decision_id, :previous_decision_hash,
                :decision_hash, :created_at
             )'
        );
        $stmt->execute([
            'personel_id' => (int) $input['personel_id'],
            'sube_id' => (int) $input['sube_id'],
            'candidate_date' => (string) $input['candidate_date'],
            'candidate_hash' => (string) $input['candidate_hash'],
            'decision_type' => (string) $input['decision_type'],
            'decision_reason' => (string) $input['decision_reason'],
            'puantaj_id' => $input['puantaj_id'] ?? null,
            'algorithm_version' => (string) $input['algorithm_version'],
            'interval_algorithm_version' => (string) $input['interval_algorithm_version'],
            'decision_algorithm_version' => (string) $input['decision_algorithm_version'],
            'candidate_snapshot' => self::encodeJson($input['candidate_snapshot']),
            'before_puantaj_snapshot' => isset($input['before_puantaj_snapshot'])
                ? self::encodeJson($input['before_puantaj_snapshot'])
                : null,
            'after_puantaj_snapshot' => isset($input['after_puantaj_snapshot'])
                ? self::encodeJson($input['after_puantaj_snapshot'])
                : null,
            'decided_by_user_id' => (int) $input['decided_by_user_id'],
            'request_nonce' => (string) $input['request_nonce'],
            'supersedes_decision_id' => $input['supersedes_decision_id'] ?? null,
            'previous_decision_hash' => $input['previous_decision_hash'] ?? null,
            'decision_hash' => $decisionHash,
            'created_at' => $createdAt,
        ]);

        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $inserted = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($inserted)) {
            throw new \RuntimeException('QR_DECISION_INSERT_FAILED');
        }

        return $inserted;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>|list<mixed>|null
     */
    private static function decodeJsonField($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $raw = (string) $value;
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed>|list<mixed>|null $value
     */
    private static function encodeJson($value)
    {
        if ($value === null) {
            return null;
        }

        return QrPuantajCandidateHashService::canonicalJson(is_array($value) ? $value : []);
    }

    /**
     * Public audit mapping (no unnecessary PII).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function mapPublic(array $row)
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'personel_id' => (int) ($row['personel_id'] ?? 0),
            'sube_id' => (int) ($row['sube_id'] ?? 0),
            'candidate_date' => (string) ($row['candidate_date'] ?? ''),
            'candidate_hash' => (string) ($row['candidate_hash'] ?? ''),
            'decision_type' => (string) ($row['decision_type'] ?? ''),
            'decision_reason' => (string) ($row['decision_reason'] ?? ''),
            'puantaj_id' => isset($row['puantaj_id']) && $row['puantaj_id'] !== null
                ? (int) $row['puantaj_id']
                : null,
            'algorithm_version' => (string) ($row['algorithm_version'] ?? ''),
            'interval_algorithm_version' => (string) ($row['interval_algorithm_version'] ?? ''),
            'decision_algorithm_version' => (string) ($row['decision_algorithm_version'] ?? ''),
            'candidate_snapshot' => self::decodeJsonField($row['candidate_snapshot'] ?? null),
            'before_puantaj_snapshot' => self::decodeJsonField($row['before_puantaj_snapshot'] ?? null),
            'after_puantaj_snapshot' => self::decodeJsonField($row['after_puantaj_snapshot'] ?? null),
            'decided_by_user_id' => (int) ($row['decided_by_user_id'] ?? 0),
            'request_nonce' => (string) ($row['request_nonce'] ?? ''),
            'supersedes_decision_id' => isset($row['supersedes_decision_id']) && $row['supersedes_decision_id'] !== null
                ? (int) $row['supersedes_decision_id']
                : null,
            'previous_decision_hash' => isset($row['previous_decision_hash']) && $row['previous_decision_hash'] !== null
                ? (string) $row['previous_decision_hash']
                : null,
            'decision_hash' => (string) ($row['decision_hash'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'audit_integrity' => self::verifyDecisionHash($row) ? 'OK' : 'MISMATCH',
        ];
    }
}
