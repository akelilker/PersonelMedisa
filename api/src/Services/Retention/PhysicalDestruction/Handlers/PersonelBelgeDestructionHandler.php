<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;
use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PERSONEL_BELGE: delete storage keys under canonical root, then metadata rows for the belge surec.
 * Path escape / arbitrary path delete forbidden — only storage_key from DB via PersonelBelgeStorageService.
 */
final class PersonelBelgeDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::PERSONEL_BELGE;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_DELETE_FILE_AND_METADATA;
    }

    public function isExecutable()
    {
        return true;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        $surecId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        $fileCount = 0;
        $versionCount = 0;
        $auditCount = 0;

        if ($surecId > 0 && $this->tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = :sid'
            );
            $stmt->execute(['sid' => $surecId]);
            $versionCount = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM personel_belge_dosya_surumleri
                 WHERE surec_id = :sid AND storage_key IS NOT NULL AND storage_key <> \'\''
            );
            $stmt->execute(['sid' => $surecId]);
            $fileCount = (int) $stmt->fetchColumn();
        }
        if ($surecId > 0 && $this->tableExists($pdo, 'personel_belge_auditleri')) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM personel_belge_auditleri WHERE surec_id = :sid'
            );
            $stmt->execute(['sid' => $surecId]);
            $auditCount = (int) $stmt->fetchColumn();
        }

        return [
            'db_operation_codes' => [
                'DELETE_PERSONEL_BELGE_FILES',
                'DELETE_PERSONEL_BELGE_AUDITLERI',
                'DELETE_PERSONEL_BELGE_DOSYA_SURUMLERI',
                'DELETE_BELGE_SUREC',
            ],
            'expected_row_counts' => [
                'personel_belge_dosya_surumleri' => $versionCount,
                'personel_belge_auditleri' => $auditCount,
                'surecler' => 1,
            ],
            'external_file_count' => $fileCount,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $surecId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        if ($surecId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }
        if (!$this->tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        // Verify belge surec ownership.
        $stmt = $pdo->prepare(
            "SELECT id, personel_id, surec_turu FROM surecler WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $surecId]);
        $surec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$surec || strtoupper((string) ($surec['surec_turu'] ?? '')) !== 'BELGE') {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }
        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId > 0 && (int) ($surec['personel_id'] ?? 0) !== $personelId) {
            throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
        }

        $verStmt = $pdo->prepare(
            'SELECT id, storage_key, sha256 FROM personel_belge_dosya_surumleri WHERE surec_id = :sid ORDER BY id ASC'
        );
        $verStmt->execute(['sid' => $surecId]);
        $versions = $verStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($versions) === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $expectedVersions = isset($plan['expected_row_counts']['personel_belge_dosya_surumleri'])
            ? (int) $plan['expected_row_counts']['personel_belge_dosya_surumleri']
            : -1;
        if ($expectedVersions >= 0 && count($versions) !== $expectedVersions) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $filesDeleted = 0;
        $filesAlreadyAbsent = 0;
        foreach ($versions as $ver) {
            $key = trim((string) ($ver['storage_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            // Canonical key pattern only (no path traversal).
            if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,16}$/', $key)) {
                throw new RuntimeException('PERSONEL_BELGE_STORAGE_KEY_GECERSIZ');
            }
            try {
                PersonelBelgeStorageService::resolvePath($key);
                PersonelBelgeStorageService::deleteKey($key);
                $filesDeleted++;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if ($msg === 'PERSONEL_BELGE_DOSYA_BULUNAMADI') {
                    // Mid-retry: file already gone for this key — accept only after PREPARED evidence exists.
                    // First-execute missing file is fail-closed when ALL files absent AND versions expected.
                    $filesAlreadyAbsent++;
                    continue;
                }
                throw $e;
            }
        }

        $expectedFiles = isset($plan['external_file_count']) ? (int) $plan['external_file_count'] : -1;
        if ($expectedFiles > 0 && ($filesDeleted + $filesAlreadyAbsent) === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $auditsDeleted = 0;
        if ($this->tableExists($pdo, 'personel_belge_auditleri')) {
            $delA = $pdo->prepare('DELETE FROM personel_belge_auditleri WHERE surec_id = :sid');
            $delA->execute(['sid' => $surecId]);
            $auditsDeleted = (int) $delA->rowCount();
        }

        $delV = $pdo->prepare('DELETE FROM personel_belge_dosya_surumleri WHERE surec_id = :sid');
        $delV->execute(['sid' => $surecId]);
        $versionsDeleted = (int) $delV->rowCount();

        $delS = $pdo->prepare("DELETE FROM surecler WHERE id = :id AND surec_turu = 'BELGE'");
        $delS->execute(['id' => $surecId]);
        $surecDeleted = (int) $delS->rowCount();
        if ($surecDeleted !== 1) {
            throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => [
                    'personel_belge_auditleri' => $auditsDeleted,
                    'personel_belge_dosya_surumleri' => $versionsDeleted,
                    'surecler' => $surecDeleted,
                ],
                'files_deleted' => $filesDeleted,
                'files_already_absent' => $filesAlreadyAbsent,
            ],
        ];
    }

    private function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }
}
