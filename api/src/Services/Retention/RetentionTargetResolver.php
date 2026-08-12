<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use PDO;
use RuntimeException;

/**
 * Canonical target resolution for destruction requests.
 * Rejects client-trusted source_identity / sha — server derives context.
 */
class RetentionTargetResolver
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validateAndResolve(
        PDO $pdo,
        $category,
        $entityType,
        $recordId,
        $personelId = null,
        array $periodHints = []
    ) {
        $category = trim((string) $category);
        $entityType = trim((string) $entityType);
        $recordId = (int) $recordId;
        $personelId = $personelId !== null && (int) $personelId > 0 ? (int) $personelId : null;

        if (!RetentionCategories::isKnown($category)) {
            throw new RuntimeException(RetentionPolicyService::CODE_UNKNOWN_CATEGORY);
        }
        if ($entityType === '' || $recordId <= 0) {
            throw new RuntimeException('RETENTION_TARGET_INVALID');
        }

        $context = [
            'category' => $category,
            'entity_type' => $entityType,
            'record_id' => $recordId,
            'personel_id' => $personelId,
        ];

        foreach (['sube_id', 'yil', 'ay', 'haftalik_kapanis_id', 'hafta_baslangic', 'parent_category'] as $key) {
            if (isset($periodHints[$key]) && $periodHints[$key] !== null && $periodHints[$key] !== '') {
                $context[$key] = $periodHints[$key];
            }
        }

        // Derive from personel when possible.
        if ($personelId !== null) {
            $stmt = $pdo->prepare(
                'SELECT id, sube_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $personelId]);
            $personel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$personel) {
                throw new RuntimeException('RETENTION_TARGET_PERSONEL_NOT_FOUND');
            }
            if (!isset($context['sube_id']) || (int) $context['sube_id'] <= 0) {
                $context['sube_id'] = (int) $personel['sube_id'];
            }
        }

        // Entity existence + personel match for known domains.
        if ($entityType === 'personel' || $entityType === 'personeller') {
            $stmt = $pdo->prepare('SELECT id, sube_id FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            if ($personelId !== null && (int) $personelId !== (int) $row['id']) {
                throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
            }
            $context['personel_id'] = (int) $row['id'];
            $context['sube_id'] = (int) $row['sube_id'];
            $context['entity_type'] = 'personel';
        } elseif ($entityType === 'surec' || $entityType === 'surecler') {
            if (!self::tableExists($pdo, 'surecler')) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $stmt = $pdo->prepare('SELECT id, personel_id FROM surecler WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $surecPersonel = (int) $row['personel_id'];
            if ($personelId !== null && $personelId !== $surecPersonel) {
                throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
            }
            $context['personel_id'] = $surecPersonel;
            $pStmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
            $pStmt->execute(['id' => $surecPersonel]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $context['sube_id'] = (int) $pRow['sube_id'];
            }
        } elseif ($entityType === 'belge' || $entityType === 'belge_kaydi' || $entityType === 'personel_belge_kayitlari') {
            // Canonical belge kaydı = surecler BELGE row (+ dosya sürümü SHA owner).
            if (!self::tableExists($pdo, 'surecler')) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $stmt = $pdo->prepare('SELECT id, personel_id FROM surecler WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $belgePersonel = (int) $row['personel_id'];
            if ($personelId !== null && $personelId !== $belgePersonel) {
                throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
            }
            $context['personel_id'] = $belgePersonel;
            $context['entity_type'] = 'surec';
            $pStmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
            $pStmt->execute(['id' => $belgePersonel]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $context['sube_id'] = (int) $pRow['sube_id'];
            }
        } elseif (in_array($entityType, ['haftalik_kapanis', 'haftalik_kapanislar'], true)) {
            if (!self::tableExists($pdo, 'haftalik_kapanislar')) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $stmt = $pdo->prepare(
                'SELECT id, sube_id, hafta_baslangic, state FROM haftalik_kapanislar WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $context['sube_id'] = (int) $row['sube_id'];
            $context['haftalik_kapanis_id'] = (int) $row['id'];
            $context['hafta_baslangic'] = (string) $row['hafta_baslangic'];
            $context['entity_type'] = 'haftalik_kapanis';
        } elseif (in_array($entityType, ['disiplin_vaka', 'disiplin_vakalar'], true)) {
            if (!self::tableExists($pdo, 'disiplin_vakalar')) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $stmt = $pdo->prepare(
                'SELECT id, personel_id, sube_id FROM disiplin_vakalar WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $vakaPersonel = (int) $row['personel_id'];
            if ($personelId !== null && $personelId !== $vakaPersonel) {
                throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
            }
            $context['personel_id'] = $vakaPersonel;
            $context['disiplin_vaka_id'] = (int) $row['id'];
            $context['entity_type'] = 'disiplin_vaka';
            if (!empty($row['sube_id'])) {
                $context['sube_id'] = (int) $row['sube_id'];
            }
        } elseif (in_array($entityType, ['qr_pc_decision', 'qr_puantaj_candidate_decision_ledger'], true)) {
            if (!self::tableExists($pdo, 'qr_puantaj_candidate_decision_ledger')) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $stmt = $pdo->prepare(
                'SELECT id, personel_id, sube_id, candidate_date FROM qr_puantaj_candidate_decision_ledger WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
            }
            $ledgerPersonel = (int) $row['personel_id'];
            if ($personelId !== null && $personelId !== $ledgerPersonel) {
                throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
            }
            $context['personel_id'] = $ledgerPersonel;
            $context['sube_id'] = (int) $row['sube_id'];
            $context['candidate_date'] = substr((string) $row['candidate_date'], 0, 10);
            $context['ledger_id'] = (int) $row['id'];
            $context['entity_type'] = 'qr_pc_decision';
            $context['parent_category'] = RetentionCategories::PUANTAJ;
            $context['audit_source_type'] = RetentionSourceAdapterService::AUDIT_SOURCE_QR_PUANTAJ_CANDIDATE_DECISION;
        }

        // Never accept client-trusted integrity fields into canonical context.
        unset($context['source_identity'], $context['source_sha256'], $context['current_sha256']);
        unset($context['as_of'], $context['gm_approved'], $context['has_gm_approval']);

        return $context;
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
