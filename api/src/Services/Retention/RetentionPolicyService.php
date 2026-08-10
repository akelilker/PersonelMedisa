<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTime;
use DateTimeImmutable;
use Medisa\Api\Services\PuantajDonemPeriodService;
use PDO;
use RuntimeException;

/**
 * Canonical owner for Medisa saklama politikası retention evaluation.
 * Fail-closed. Never auto-deletes. Physical execute always returns EXECUTION_HANDLER_NOT_IMPLEMENTED.
 */
class RetentionPolicyService
{
    public const CODE_UNKNOWN_CATEGORY = 'UNKNOWN_CATEGORY';
    public const CODE_TRIGGER_NOT_RESOLVED = 'TRIGGER_NOT_RESOLVED';
    public const CODE_PERIOD_NOT_CLOSED = 'PERIOD_NOT_CLOSED';
    public const CODE_TERMINATION_DATE_MISSING = 'TERMINATION_DATE_MISSING';
    public const CODE_RETENTION_NOT_MATURE = 'RETENTION_NOT_MATURE';
    public const CODE_LEGAL_HOLD_ACTIVE = 'LEGAL_HOLD_ACTIVE';
    public const CODE_NO_GM_APPROVAL = 'NO_GM_APPROVAL';
    public const CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED = 'ARCHIVE_SOURCE_INTEGRITY_CHANGED';
    public const CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED = 'EXECUTION_HANDLER_NOT_IMPLEMENTED';
    public const CODE_APPROVED_FOR_DESTRUCTION = 'APPROVED_FOR_DESTRUCTION';

    /**
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    public static function resolveTrigger(PDO $pdo, $category, array $context)
    {
        $category = (string) $category;
        $triggerType = RetentionCategories::triggerTypeForCategory($category);
        if ($triggerType === null) {
            throw new RuntimeException(self::CODE_UNKNOWN_CATEGORY);
        }

        if ($triggerType === RetentionCategories::TRIGGER_PERIOD_CLOSURE) {
            $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
            $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
            $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
            if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
                throw new RuntimeException(self::CODE_TRIGGER_NOT_RESOLVED);
            }

            $seal = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay);
            if ($seal === null) {
                throw new RuntimeException(self::CODE_PERIOD_NOT_CLOSED);
            }

            $createdAt = isset($seal['created_at']) ? (string) $seal['created_at'] : '';
            if ($createdAt === '') {
                throw new RuntimeException(self::CODE_TRIGGER_NOT_RESOLVED);
            }
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAt)
                ?: DateTimeImmutable::createFromFormat('Y-m-d', substr($createdAt, 0, 10));
            if (!$dt) {
                throw new RuntimeException(self::CODE_TRIGGER_NOT_RESOLVED);
            }

            return [
                'trigger_type' => RetentionCategories::TRIGGER_PERIOD_CLOSURE,
                'trigger_date' => $dt->format('Y-m-d'),
            ];
        }

        // TERMINATION_DATE
        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId <= 0) {
            throw new RuntimeException(self::CODE_TRIGGER_NOT_RESOLVED);
        }

        $termination = self::resolveTerminationDate($pdo, $personelId);
        if ($termination === null) {
            throw new RuntimeException(self::CODE_TERMINATION_DATE_MISSING);
        }

        return [
            'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
            'trigger_date' => $termination,
        ];
    }

    /**
     * Calendar +10 years from trigger date (not 3650 days).
     *
     * @return string Y-m-d
     */
    public static function calculateRetentionUntil(DateTime $triggerDate)
    {
        $until = clone $triggerDate;
        $until->modify('+' . RetentionCategories::POLICY_RETENTION_YEARS . ' years');

        return $until->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function hasActiveLegalHold(PDO $pdo, $category, array $context)
    {
        if (!self::tableExists($pdo, 'legal_holdlar')) {
            return false;
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        $recordId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        $entityType = isset($context['entity_type']) ? (string) $context['entity_type'] : '';
        $category = (string) $category;

        $sql = "SELECT id FROM legal_holdlar
            WHERE hold_state = 'ACTIVE'
              AND (
                    (personel_id IS NOT NULL AND :personel_id_check > 0 AND personel_id = :personel_id_match)
                 OR (target_category IS NOT NULL AND target_category = :category
                     AND (target_record_id IS NULL OR target_record_id = :record_id_cat))
                 OR (target_domain = :entity_type AND :entity_type_check <> ''
                     AND (target_record_id IS NULL OR target_record_id = :record_id_dom))
              )
            LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'personel_id_check' => $personelId,
            'personel_id_match' => $personelId,
            'category' => $category,
            'record_id_cat' => $recordId,
            'entity_type' => $entityType,
            'entity_type_check' => $entityType,
            'record_id_dom' => $recordId,
        ]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Fail-closed destruction eligibility matrix.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function evaluateDestructionEligibility(PDO $pdo, $category, array $context)
    {
        $category = (string) $category;
        $result = [
            'eligible' => false,
            'code' => self::CODE_UNKNOWN_CATEGORY,
            'category' => $category,
            'trigger_type' => null,
            'trigger_date' => null,
            'retention_until' => null,
            'policy_note' => RetentionCategories::POLICY_NOTE,
            'message' => '',
        ];

        if (!RetentionCategories::isKnown($category)) {
            $result['code'] = self::CODE_UNKNOWN_CATEGORY;
            $result['message'] = 'Bilinmeyen saklama kategorisi.';

            return $result;
        }

        try {
            $trigger = self::resolveTrigger($pdo, $category, $context);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if (!in_array($code, [
                self::CODE_PERIOD_NOT_CLOSED,
                self::CODE_TERMINATION_DATE_MISSING,
                self::CODE_TRIGGER_NOT_RESOLVED,
                self::CODE_UNKNOWN_CATEGORY,
            ], true)) {
                $code = self::CODE_TRIGGER_NOT_RESOLVED;
            }
            $result['code'] = $code;
            $result['message'] = self::codeMessage($code);

            return $result;
        }

        $result['trigger_type'] = $trigger['trigger_type'];
        $result['trigger_date'] = $trigger['trigger_date'];

        $triggerDt = DateTime::createFromFormat('Y-m-d', $trigger['trigger_date']);
        if (!$triggerDt) {
            $result['code'] = self::CODE_TRIGGER_NOT_RESOLVED;
            $result['message'] = self::codeMessage(self::CODE_TRIGGER_NOT_RESOLVED);

            return $result;
        }

        $retentionUntil = self::calculateRetentionUntil($triggerDt);
        $result['retention_until'] = $retentionUntil;

        $asOf = isset($context['as_of']) ? (string) $context['as_of'] : date('Y-m-d');
        if ($asOf < $retentionUntil) {
            $result['code'] = self::CODE_RETENTION_NOT_MATURE;
            $result['message'] = self::codeMessage(self::CODE_RETENTION_NOT_MATURE);

            return $result;
        }

        if (self::hasActiveLegalHold($pdo, $category, $context)) {
            $result['code'] = self::CODE_LEGAL_HOLD_ACTIVE;
            $result['message'] = self::codeMessage(self::CODE_LEGAL_HOLD_ACTIVE);

            return $result;
        }

        $gmApproved = !empty($context['gm_approved']) || !empty($context['has_gm_approval']);
        if (!$gmApproved) {
            $result['code'] = self::CODE_NO_GM_APPROVAL;
            $result['message'] = self::codeMessage(self::CODE_NO_GM_APPROVAL);

            return $result;
        }

        if (!empty($context['check_integrity'])) {
            $integrity = ArchiveManifestService::verifySourceIntegrity(
                $pdo,
                isset($context['entity_type']) ? (string) $context['entity_type'] : '',
                isset($context['record_id']) ? (int) $context['record_id'] : 0,
                $category,
                isset($context['current_sha256']) ? (string) $context['current_sha256'] : null
            );
            if ($integrity === self::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED) {
                $result['code'] = self::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED;
                $result['message'] = self::codeMessage(self::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED);

                return $result;
            }
        }

        $result['eligible'] = true;
        $result['code'] = self::CODE_APPROVED_FOR_DESTRUCTION;
        $result['message'] = self::codeMessage(self::CODE_APPROVED_FOR_DESTRUCTION);

        return $result;
    }

    /**
     * Physical execute path — always fail-closed with EXECUTION_HANDLER_NOT_IMPLEMENTED
     * after re-checking eligibility. Never performs generic DELETE.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function executeDestruction(PDO $pdo, $category, array $context)
    {
        $eligibility = self::evaluateDestructionEligibility($pdo, $category, $context);
        if (($eligibility['code'] ?? '') !== self::CODE_APPROVED_FOR_DESTRUCTION) {
            return $eligibility;
        }

        return [
            'eligible' => false,
            'code' => self::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED,
            'category' => (string) $category,
            'trigger_type' => $eligibility['trigger_type'] ?? null,
            'trigger_date' => $eligibility['trigger_date'] ?? null,
            'retention_until' => $eligibility['retention_until'] ?? null,
            'policy_note' => RetentionCategories::POLICY_NOTE,
            'message' => self::codeMessage(self::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED),
        ];
    }

    /**
     * @return string|null Y-m-d
     */
    public static function resolveTerminationDate(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            return null;
        }

        // Same pattern as MaasHesaplamaSnapshotService: AKTIF ISTEN_AYRILMA → baslangic_tarihi.
        if (self::tableExists($pdo, 'surecler')) {
            $stmt = $pdo->prepare(
                "SELECT MIN(s.baslangic_tarihi) AS cikis_tarihi
                 FROM surecler s
                 WHERE s.personel_id = :personel_id
                   AND s.surec_turu = 'ISTEN_AYRILMA'
                   AND s.state = 'AKTIF'"
            );
            $stmt->execute(['personel_id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['cikis_tarihi'])) {
                return substr((string) $row['cikis_tarihi'], 0, 10);
            }
        }

        // Optional personel cikis column if present (no second master table).
        if (self::columnExists($pdo, 'personeller', 'cikis_tarihi')) {
            $stmt = $pdo->prepare('SELECT cikis_tarihi FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['cikis_tarihi'])) {
                return substr((string) $row['cikis_tarihi'], 0, 10);
            }
        }

        return null;
    }

    public static function codeMessage($code)
    {
        $map = [
            self::CODE_UNKNOWN_CATEGORY => 'Bilinmeyen saklama kategorisi.',
            self::CODE_TRIGGER_NOT_RESOLVED => 'Saklama tetik tarihi cozumlenemedi.',
            self::CODE_PERIOD_NOT_CLOSED => 'Donem muhuru yok; period kapanisi gerekli.',
            self::CODE_TERMINATION_DATE_MISSING => 'Isten ayrilma / cikis tarihi eksik.',
            self::CODE_RETENTION_NOT_MATURE => 'Medisa saklama politikasi suresi dolmadi.',
            self::CODE_LEGAL_HOLD_ACTIVE => 'Aktif legal hold var; imha engellendi.',
            self::CODE_NO_GM_APPROVAL => 'Genel yonetici imha onayi yok.',
            self::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED => 'Arsiv kaynak butunlugu degismis.',
            self::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED => 'Fiziksel imha handler uygulanmadi (guvenli).',
            self::CODE_APPROVED_FOR_DESTRUCTION => 'Imha icin uygun (handler henuz yok).',
        ];

        return isset($map[$code]) ? $map[$code] : 'Saklama degerlendirmesi basarisiz.';
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

    private static function columnExists(PDO $pdo, $table, $column)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table, 'c' => (string) $column]);

        return (bool) $stmt->fetchColumn();
    }
}
