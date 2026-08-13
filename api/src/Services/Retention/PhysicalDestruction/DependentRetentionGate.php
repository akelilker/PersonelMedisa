<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

use PDO;
use RuntimeException;

/**
 * Shared fail-closed dependency checks for typed destruction handlers.
 * Never invents CASCADE; callers decide which dependents block.
 */
final class DependentRetentionGate
{
    public static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function assertNoRows(PDO $pdo, $sql, array $params, $code = null)
    {
        $code = $code !== null
            ? (string) $code
            : PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN;
        $stmt = $pdo->prepare((string) $sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException($code);
        }
    }

    /**
     * Surec-linked SGK / finans / resmi etki / PERSONEL_BELGE dependents
     * used by RAPOR/IS_KAZASI (IZIN-family). PERSONEL_BELGE is typed
     * (migration 038 ON DELETE RESTRICT) — never rely on raw FK exception.
     */
    public static function assertSurecLifecycleDependentsClear(PDO $pdo, $surecId)
    {
        $surecId = (int) $surecId;
        if ($surecId <= 0) {
            return;
        }
        if (self::tableExists($pdo, 'sgk_belge_surec_baglantilari')) {
            self::assertNoRows(
                $pdo,
                'SELECT COUNT(*) FROM sgk_belge_surec_baglantilari WHERE surec_id = :id',
                ['id' => $surecId]
            );
        }
        if (self::tableExists($pdo, 'sgk_is_goremezlik_finans_kayitlari')) {
            self::assertNoRows(
                $pdo,
                'SELECT COUNT(*) FROM sgk_is_goremezlik_finans_kayitlari WHERE surec_id = :id',
                ['id' => $surecId]
            );
        }
        if (self::tableExists($pdo, 'disiplin_vakalar')) {
            self::assertNoRows(
                $pdo,
                'SELECT COUNT(*) FROM disiplin_vakalar WHERE surec_id = :id',
                ['id' => $surecId]
            );
        }
        if (self::tableExists($pdo, 'onayli_bildirim_puantaj_etki_adaylari')) {
            self::assertNoRows(
                $pdo,
                'SELECT COUNT(*) FROM onayli_bildirim_puantaj_etki_adaylari WHERE resmi_surec_id = :id',
                ['id' => $surecId]
            );
        }
        self::assertPersonelBelgeDependentsClear($pdo, $surecId);
    }

    /**
     * personel_belge_dosya_surumleri / personel_belge_auditleri → surecler.id RESTRICT.
     * Clear via PERSONEL_BELGE handler first — never cascade from RAPOR/IS_KAZASI.
     */
    public static function assertPersonelBelgeDependentsClear(PDO $pdo, $surecId)
    {
        $surecId = (int) $surecId;
        if ($surecId <= 0) {
            return;
        }
        $code = PhysicalDestructionCodes::CODE_PERSONEL_BELGE_REMAINS;
        if (self::tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            self::assertNoRows(
                $pdo,
                'SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = :id',
                ['id' => $surecId],
                $code
            );
        }
        if (self::tableExists($pdo, 'personel_belge_auditleri')) {
            self::assertNoRows(
                $pdo,
                'SELECT COUNT(*) FROM personel_belge_auditleri WHERE surec_id = :id',
                ['id' => $surecId],
                $code
            );
        }
    }
}
