<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;

/**
 * Canonical effective approved SGK company-policy selector.
 *
 * Runtime calculation and authenticated read surfaces must use this selector.
 * Draft and approval-pending rows are intentionally excluded.
 */
final class SgkSirketPolitikaReadService
{
    public const STATE_APPROVED = 'ONAYLANDI';
    public const STATE_NO_APPROVED_POLICY = 'NO_APPROVED_POLICY';
    public const STATE_CONFLICT = 'CONFLICT';

    /**
     * @return array{politika: array<string,mixed>|null, degerler: array<string,string>, state: string}
     */
    public static function resolveForPeriod(PDO $pdo, int $subeId, string $from, string $to): array
    {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM sgk_sirket_politika_surumleri
             WHERE sube_id = :sube_id
               AND state = 'ONAYLANDI'
               AND gecerlilik_baslangic <= :bitis
               AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)
             ORDER BY gecerlilik_baslangic DESC, id DESC"
        );
        $stmt->execute([
            'sube_id' => $subeId,
            'baslangic' => $from,
            'bitis' => $to,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) === 0) {
            return [
                'politika' => null,
                'degerler' => [],
                'state' => self::STATE_NO_APPROVED_POLICY,
            ];
        }

        if (count($rows) !== 1) {
            return [
                'politika' => null,
                'degerler' => [],
                'state' => self::STATE_CONFLICT,
            ];
        }

        $policy = $rows[0];
        $valueStmt = $pdo->prepare(
            'SELECT politika_kodu, deger
             FROM sgk_sirket_politika_degerleri
             WHERE politika_surum_id = :id
             ORDER BY politika_kodu ASC'
        );
        $valueStmt->execute(['id' => (int) $policy['id']]);

        $values = [];
        foreach ($valueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string) $row['politika_kodu']] = (string) $row['deger'];
        }

        return [
            'politika' => $policy,
            'degerler' => $values,
            'state' => self::STATE_APPROVED,
        ];
    }

    /**
     * Inventory all lifecycle revisions for one branch while independently
     * marking their relationship to the requested period. The effective
     * selector remains the only source of effective-approved truth.
     *
     * @return list<array<string,mixed>>
     */
    public static function listRevisionInventory(PDO $pdo, int $subeId, string $from, string $to): array
    {
        $resolved = self::resolveForPeriod($pdo, $subeId, $from, $to);
        $effectiveId = $resolved['politika'] !== null ? (int) $resolved['politika']['id'] : null;

        $stmt = $pdo->prepare(
            'SELECT id, sube_id, surum_kodu, state, bildirim_donem_tipi,
                    gecerlilik_baslangic, gecerlilik_bitis, politika_hash,
                    hazirlayan_id, onaylayan_id, onay_zamani, created_at
             FROM sgk_sirket_politika_surumleri
             WHERE sube_id = :sube_id
             ORDER BY gecerlilik_baslangic ASC, id ASC'
        );
        $stmt->execute(['sube_id' => $subeId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $validFrom = (string) ($row['gecerlilik_baslangic'] ?? '');
            $validTo = $row['gecerlilik_bitis'] !== null
                ? (string) $row['gecerlilik_bitis']
                : '9999-12-31';
            $overlaps = $validFrom <= $to && $validTo >= $from;
            $items[] = [
                'sube_id' => (int) $row['sube_id'],
                'policy_id' => (int) $row['id'],
                'surum_kodu' => (string) $row['surum_kodu'],
                'state' => (string) $row['state'],
                'bildirim_donem_tipi' => (string) $row['bildirim_donem_tipi'],
                'gecerlilik_baslangic' => $row['gecerlilik_baslangic'],
                'gecerlilik_bitis' => $row['gecerlilik_bitis'],
                'created_at' => $row['created_at'],
                'onay_zamani' => $row['onay_zamani'],
                'hazirlayan_id' => $row['hazirlayan_id'],
                'onaylayan_id' => $row['onaylayan_id'],
                'politika_hash' => $row['politika_hash'],
                'effective_for_requested_period' => $effectiveId !== null && $effectiveId === (int) $row['id'],
                'overlaps_requested_period' => $overlaps,
                'degerler' => self::loadValues($pdo, (int) $row['id']),
            ];
        }

        return $items;
    }

    /**
     * @param list<int> $allowedSubeIds
     * @return list<array<string,mixed>>
     */
    public static function listEffective(
        PDO $pdo,
        ?int $scopeSubeId,
        array $allowedSubeIds,
        string $from,
        string $to
    ): array {
        $where = ["durum = 'AKTIF'"];
        $params = [];

        if ($scopeSubeId !== null) {
            $where[] = 'id = :scope_sube_id';
            $params['scope_sube_id'] = $scopeSubeId;
        } elseif ($allowedSubeIds !== []) {
            $placeholders = [];
            foreach (array_values($allowedSubeIds) as $index => $subeId) {
                $key = 'allowed_sube_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $subeId;
            }
            $where[] = 'id IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $pdo->prepare(
            'SELECT id
             FROM subeler
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY id ASC'
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $subeId) {
            $resolved = self::resolveForPeriod($pdo, (int) $subeId, $from, $to);
            $policy = $resolved['politika'];
            $item = [
                'sube_id' => (int) $subeId,
                'state' => $resolved['state'],
            ];
            if ($policy !== null) {
                $item += [
                    'approved_policy_id' => (int) $policy['id'],
                    'surum_kodu' => (string) $policy['surum_kodu'],
                    'status' => (string) $policy['state'],
                    'bildirim_donem_tipi' => (string) $policy['bildirim_donem_tipi'],
                    'gecerlilik_baslangic' => $policy['gecerlilik_baslangic'],
                    'gecerlilik_bitis' => $policy['gecerlilik_bitis'],
                    'politika_hash' => $policy['politika_hash'],
                    'hazirlayan_id' => $policy['hazirlayan_id'],
                    'onaylayan_id' => $policy['onaylayan_id'],
                    'onay_zamani' => $policy['onay_zamani'],
                    'degerler' => $resolved['degerler'],
                ];
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string, string> */
    private static function loadValues(PDO $pdo, int $policyId): array
    {
        $stmt = $pdo->prepare(
            'SELECT politika_kodu, deger
             FROM sgk_sirket_politika_degerleri
             WHERE politika_surum_id = :id
             ORDER BY politika_kodu ASC'
        );
        $stmt->execute(['id' => $policyId]);

        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string) $row['politika_kodu']] = (string) $row['deger'];
        }

        return $values;
    }
}
