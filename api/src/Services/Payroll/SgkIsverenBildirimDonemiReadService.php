<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;
use PDOException;

/**
 * Canonical SGK bildirim donemi read owner.
 *
 * The reporting period belongs to the SGK employer (isveren/isyeri), not to a
 * branch, company or physical location. Payroll resolves it from
 * personeller.sgk_isveren_id and never infers it from the organizational branch axis.
 *
 * Legal values (locked):
 *   AY_1_SON_GUN         = 1st of month -> real calendar month end (28/29/30/31)
 *   AY_15_SONRAKI_AY_14  = 15th of month -> 14th of the following month
 *
 * Draft / approval-pending / cancelled rows never enter runtime. When more than
 * one approved row is effective for the same employer interval the read is
 * fail-closed with CONFLICT, exactly like the legacy branch-scoped selector.
 */
final class SgkIsverenBildirimDonemiReadService
{
    public const STATE_APPROVED = 'ONAYLANDI';
    public const STATE_NO_PERIOD = 'NO_PERIOD';
    public const STATE_CONFLICT = 'CONFLICT';

    /** @var list<string> */
    public const BILDIRIM_DONEM_TIPLERI = ['AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14'];

    public const TABLE = 'sgk_isveren_bildirim_donemi_surumleri';

    /**
     * @return array{donem: array<string,mixed>|null, bildirim_donem_tipi: string|null, state: string}
     */
    public static function resolveForPeriod(PDO $pdo, int $sgkIsverenId, string $from, string $to): array
    {
        if ($sgkIsverenId < 1 || trim($from) === '' || trim($to) === '' || $from > $to) {
            return self::noPeriod();
        }
        if (!self::isSchemaReady($pdo)) {
            return self::noPeriod();
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT *
                 FROM sgk_isveren_bildirim_donemi_surumleri
                 WHERE sgk_isveren_id = :sgk_isveren_id
                   AND state = 'ONAYLANDI'
                   AND gecerlilik_baslangic <= :bitis
                   AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)
                 ORDER BY gecerlilik_baslangic DESC, id DESC"
            );
            $stmt->execute([
                'sgk_isveren_id' => $sgkIsverenId,
                'baslangic' => $from,
                'bitis' => $to,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Missing table / partial schema must never fabricate a period.
            return self::noPeriod();
        }

        if (count($rows) === 0) {
            return self::noPeriod();
        }
        if (count($rows) !== 1) {
            return [
                'donem' => null,
                'bildirim_donem_tipi' => null,
                'state' => self::STATE_CONFLICT,
            ];
        }

        $donem = $rows[0];
        $tip = strtoupper(trim((string) ($donem['bildirim_donem_tipi'] ?? '')));
        if (!in_array($tip, self::BILDIRIM_DONEM_TIPLERI, true)) {
            return self::noPeriod();
        }

        return [
            'donem' => $donem,
            'bildirim_donem_tipi' => $tip,
            'state' => self::STATE_APPROVED,
        ];
    }

    public static function isSchemaReady(PDO $pdo): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute(['t' => self::TABLE]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** @return array{donem: null, bildirim_donem_tipi: null, state: string} */
    private static function noPeriod(): array
    {
        return [
            'donem' => null,
            'bildirim_donem_tipi' => null,
            'state' => self::STATE_NO_PERIOD,
        ];
    }
}
