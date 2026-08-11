<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTimeImmutable;
use Medisa\Api\Services\PuantajDonemPeriodService;
use PDO;
use RuntimeException;

/**
 * Explicit per-category PERIOD_CLOSURE trigger owners.
 * Never uses generic puantaj seal for all period categories.
 */
class RetentionPeriodTriggerResolver
{
    /**
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    public static function resolve(PDO $pdo, $category, array $context)
    {
        $category = (string) $category;

        switch ($category) {
            case RetentionCategories::PUANTAJ:
                return self::resolvePuantaj($pdo, $context);
            case RetentionCategories::BORDRO:
                return self::resolveBordro($pdo, $context);
            case RetentionCategories::SGK_EKSIK_GUN:
                return self::resolveSgkEksikGun($pdo, $context);
            case RetentionCategories::FAZLA_CALISMA:
            case RetentionCategories::SERBEST_ZAMAN:
                return self::resolveHaftalikKapanis($pdo, $context);
            case RetentionCategories::ONAY_AUDIT:
                return self::resolveOnayAudit($pdo, $context);
            default:
                throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    private static function resolvePuantaj(PDO $pdo, array $context)
    {
        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
        $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $seal = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay);
        if ($seal === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        $createdAt = isset($seal['created_at']) ? (string) $seal['created_at'] : '';
        $date = self::toDateYmd($createdAt);
        if ($date === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        return [
            'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            'trigger_date' => $date,
        ];
    }

    /**
     * BORDRO owner: kesinleşmiş maaş çalıştırma — NOT puantaj seal alone.
     *
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    private static function resolveBordro(PDO $pdo, array $context)
    {
        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
        $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        if (!self::tableExists($pdo, 'maas_hesaplama_calistirmalari')) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        $stmt = $pdo->prepare(
            "SELECT kesinlestirme_at, created_at
             FROM maas_hesaplama_calistirmalari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND state = 'HESAPLANDI'
               AND bordro_onay_durumu = 'KESINLESTI'
             ORDER BY revision_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        $raw = !empty($row['kesinlestirme_at'])
            ? (string) $row['kesinlestirme_at']
            : (string) ($row['created_at'] ?? '');
        $date = self::toDateYmd($raw);
        if ($date === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        return [
            'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            'trigger_date' => $date,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    private static function resolveSgkEksikGun(PDO $pdo, array $context)
    {
        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
        $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        if (!self::tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        $stmt = $pdo->prepare(
            "SELECT cutoff_at, created_at
             FROM maas_hesaplama_donem_snapshotlari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND state = 'OLUSTURULDU'
             ORDER BY revision_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        $raw = !empty($row['cutoff_at'])
            ? (string) $row['cutoff_at']
            : (string) ($row['created_at'] ?? '');
        $date = self::toDateYmd($raw);
        if ($date === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        return [
            'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            'trigger_date' => $date,
        ];
    }

    /**
     * Prefer haftalık kapanış owner over puantaj seal.
     *
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    private static function resolveHaftalikKapanis(PDO $pdo, array $context)
    {
        if (!self::tableExists($pdo, 'haftalik_kapanislar')) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        $haftalikId = isset($context['haftalik_kapanis_id']) ? (int) $context['haftalik_kapanis_id'] : 0;
        $haftaBaslangic = isset($context['hafta_baslangic']) ? trim((string) $context['hafta_baslangic']) : '';

        if ($haftalikId > 0) {
            $stmt = $pdo->prepare(
                "SELECT hafta_bitis, created_at FROM haftalik_kapanislar
                 WHERE id = :id AND state = 'KAPANDI' LIMIT 1"
            );
            $stmt->execute(['id' => $haftalikId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
            }
            $raw = !empty($row['hafta_bitis'])
                ? (string) $row['hafta_bitis']
                : (string) ($row['created_at'] ?? '');
            $date = self::toDateYmd($raw);
            if ($date === null) {
                throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
            }

            return [
                'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
                'trigger_date' => $date,
            ];
        }

        if ($haftaBaslangic !== '') {
            $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
            $sql = "SELECT hafta_bitis, created_at FROM haftalik_kapanislar
                    WHERE hafta_baslangic = :hb AND state = 'KAPANDI'";
            $params = ['hb' => $haftaBaslangic];
            if ($subeId > 0) {
                $sql .= ' AND sube_id = :sube_id';
                $params['sube_id'] = $subeId;
            }
            $sql .= ' ORDER BY id DESC LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
            }
            $raw = !empty($row['hafta_bitis'])
                ? (string) $row['hafta_bitis']
                : (string) ($row['created_at'] ?? '');
            $date = self::toDateYmd($raw);
            if ($date === null) {
                throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
            }

            return [
                'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
                'trigger_date' => $date,
            ];
        }

        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
        $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $monthStart = sprintf('%04d-%02d-01', $yil, $ay);
        $monthEndDt = DateTimeImmutable::createFromFormat('Y-m-d', $monthStart);
        if (!$monthEndDt) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $monthEnd = $monthEndDt->modify('last day of this month')->format('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT hafta_bitis, created_at FROM haftalik_kapanislar
             WHERE sube_id = :sube_id AND state = 'KAPANDI'
               AND hafta_baslangic <= :month_end
               AND hafta_bitis >= :month_start
             ORDER BY hafta_bitis DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'sube_id' => $subeId,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        $raw = !empty($row['hafta_bitis'])
            ? (string) $row['hafta_bitis']
            : (string) ($row['created_at'] ?? '');
        $date = self::toDateYmd($raw);
        if ($date === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        return [
            'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            'trigger_date' => $date,
        ];
    }

    /**
     * ONAY_AUDIT binds to parent_category trigger — never universal puantaj seal.
     *
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    private static function resolveOnayAudit(PDO $pdo, array $context)
    {
        $parent = isset($context['parent_category']) ? trim((string) $context['parent_category']) : '';
        if ($parent === '' || $parent === RetentionCategories::ONAY_AUDIT) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        if (!RetentionCategories::isKnown($parent)) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $triggerType = RetentionCategories::triggerTypeForCategory($parent);
        if ($triggerType === RetentionCategories::TRIGGER_PERIOD_CLOSURE) {
            return self::resolve($pdo, $parent, $context);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId <= 0) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $termination = RetentionPolicyService::resolveTerminationDate($pdo, $personelId);
        if ($termination === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TERMINATION_DATE_MISSING);
        }

        return [
            'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            'trigger_date' => $termination,
        ];
    }

    /**
     * @return string|null Y-m-d
     */
    private static function toDateYmd($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw)
            ?: DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));

        return $dt ? $dt->format('Y-m-d') : null;
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }
}
