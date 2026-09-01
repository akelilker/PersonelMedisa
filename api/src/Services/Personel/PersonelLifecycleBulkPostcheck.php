<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Dynamic count contract for lifecycle bulk apply — derived from live inventory
 * and canonical dry-run analysis, not hardcoded reconciliation targets.
 */
final class PersonelLifecycleBulkPostcheck
{
    public const INVENTORY_SCHEMA = 'personel-lifecycle-inventory-v1';

    /** @return array{baseline_total:int,baseline_active:int,inventory_fingerprint:string} */
    public static function captureInventory(PDO $pdo): array
    {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
        $active = (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE aktif_durum = 'AKTIF'")->fetchColumn();

        return [
            'baseline_total' => $total,
            'baseline_active' => $active,
            'inventory_fingerprint' => self::fingerprintInventory($total, $active),
        ];
    }

    /**
     * @param array{baseline_total:int,baseline_active:int,inventory_fingerprint:string} $inventory
     * @param list<array<string, mixed>> $analysisRows
     * @return array<string, mixed>
     */
    public static function summarize(array $inventory, array $analysisRows): array
    {
        $impact = self::planImpact($analysisRows);

        return [
            'baseline_total' => $inventory['baseline_total'],
            'baseline_active' => $inventory['baseline_active'],
            'inventory_fingerprint' => $inventory['inventory_fingerprint'],
            'create_count' => $impact['create_count'],
            'exit_count' => $impact['exit_count'],
            'rehire_count' => $impact['rehire_count'],
            'neutral_count' => $impact['neutral_count'],
            'total_delta' => $impact['total_delta'],
            'active_delta' => $impact['active_delta'],
            'expected_total_after' => $inventory['baseline_total'] + $impact['total_delta'],
            'expected_active_after' => $inventory['baseline_active'] + $impact['active_delta'],
        ];
    }

    /**
     * @param array{baseline_total:int,baseline_active:int,inventory_fingerprint:string} $inventory
     * @param list<array<string, mixed>> $analysisRows
     * @return list<string>
     */
    public static function validateContract(array $inventory, array $analysisRows): array
    {
        $errors = [];
        $impact = self::planImpact($analysisRows);
        $summary = self::summarize($inventory, $analysisRows);

        if ($summary['expected_total_after'] !== $inventory['baseline_total'] + $impact['total_delta']) {
            $errors[] = 'POSTCHECK_TOTAL_MISMATCH';
        }
        if ($summary['expected_active_after'] !== $inventory['baseline_active'] + $impact['active_delta']) {
            $errors[] = 'POSTCHECK_ACTIVE_MISMATCH';
        }

        foreach ($analysisRows as $row) {
            if (($row['durum'] ?? '') !== 'READY') {
                continue;
            }
            $op = self::resolveCanonicalOperation($row);
            if ($op === PersonelLifecycleBulkRowContract::OP_EXIT) {
                $preimage = strtoupper(trim((string) ($row['preimage_aktif_durum'] ?? '')));
                if ($preimage !== 'AKTIF') {
                    $errors[] = 'POSTCHECK_EXIT_PREIMAGE_NOT_AKTIF';
                }
            }
        }

        if ($impact['total_delta'] < 0 || $impact['active_delta'] < -$impact['exit_count']) {
            $errors[] = 'POSTCHECK_NEGATIVE_INVENTORY';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param list<array<string, mixed>> $analysisRows
     * @return array{
     *   create_count:int,
     *   exit_count:int,
     *   rehire_count:int,
     *   neutral_count:int,
     *   total_delta:int,
     *   active_delta:int
     * }
     */
    private static function planImpact(array $analysisRows): array
    {
        $create = 0;
        $exit = 0;
        $rehire = 0;
        $neutral = 0;
        $totalDelta = 0;
        $activeDelta = 0;

        foreach ($analysisRows as $row) {
            if (($row['durum'] ?? '') !== 'READY') {
                continue;
            }

            $op = self::resolveCanonicalOperation($row);
            switch ($op) {
                case PersonelLifecycleBulkRowContract::OP_CREATE:
                    $create++;
                    $totalDelta++;
                    $activeDelta += self::createActiveDelta($row);
                    break;
                case PersonelLifecycleBulkRowContract::OP_EXIT:
                    $exit++;
                    if (strtoupper(trim((string) ($row['preimage_aktif_durum'] ?? ''))) === 'AKTIF') {
                        $activeDelta--;
                    }
                    break;
                case 'YENIDEN_ISE_ALMA':
                    $rehire++;
                    if (strtoupper(trim((string) ($row['preimage_aktif_durum'] ?? ''))) === 'PASIF') {
                        $activeDelta++;
                    }
                    break;
                case PersonelLifecycleBulkRowContract::OP_BASIC_UPDATE:
                case PersonelLifecycleBulkRowContract::OP_ORG_UPDATE:
                case PersonelLifecycleBulkRowContract::OP_BRANCH:
                case PersonelLifecycleBulkRowContract::OP_REFERENCE:
                    $neutral++;
                    break;
                default:
                    break;
            }
        }

        return [
            'create_count' => $create,
            'exit_count' => $exit,
            'rehire_count' => $rehire,
            'neutral_count' => $neutral,
            'total_delta' => $totalDelta,
            'active_delta' => $activeDelta,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function resolveCanonicalOperation(array $row): string
    {
        $raw = strtoupper(trim((string) ($row['islem_tipi'] ?? '')));
        if ($raw === '') {
            $raw = PersonelLifecycleBulkRowContract::resolveOperationType($row);
        }

        return PersonelLifecycleBulkRowContract::mapLegacyToCanonical($raw);
    }

    /** @param array<string, mixed> $row */
    private static function createActiveDelta(array $row): int
    {
        $plan = is_array($row['mutation_plan'] ?? null) ? $row['mutation_plan'] : [];
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        $aktif = strtoupper(trim((string) ($payload['aktif_durum'] ?? 'AKTIF')));

        return $aktif === 'AKTIF' ? 1 : 0;
    }

    private static function fingerprintInventory(int $total, int $active): string
    {
        return hash('sha256', json_encode([
            'schema' => self::INVENTORY_SCHEMA,
            'total' => $total,
            'active' => $active,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Bind inventory fingerprint into the canonical dry-run checksum preimage.
     *
     * @param list<array<string, mixed>> $analysisRows
     */
    public static function checksumPreimage(string $inventoryFingerprint, array $analysisRows): string
    {
        return hash('sha256', json_encode([
            'inventory_fingerprint' => $inventoryFingerprint,
            'plan' => $analysisRows,
        ], JSON_UNESCAPED_UNICODE));
    }
}
