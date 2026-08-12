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
 * SAVUNMA: anonymize savunma fields on shared disiplin_vakalar; delete linked savunma belge files/metadata when present.
 */
final class SavunmaDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::SAVUNMA;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_COMPOSITE;
    }

    public function isExecutable()
    {
        return true;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        $vakaId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        $count = 0;
        $fileCount = 0;
        if ($vakaId > 0 && $this->tableExists($pdo, 'disiplin_vakalar')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM disiplin_vakalar WHERE id = :id');
            $stmt->execute(['id' => $vakaId]);
            $count = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT savunma_belge_surec_id FROM disiplin_vakalar WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $vakaId]);
            $belgeSurecId = (int) ($stmt->fetchColumn() ?: 0);
            if ($belgeSurecId > 0 && $this->tableExists($pdo, 'personel_belge_dosya_surumleri')) {
                $c = $pdo->prepare(
                    'SELECT COUNT(*) FROM personel_belge_dosya_surumleri
                     WHERE surec_id = :sid AND storage_key IS NOT NULL AND storage_key <> \'\''
                );
                $c->execute(['sid' => $belgeSurecId]);
                $fileCount = (int) $c->fetchColumn();
            }
        }

        return [
            'db_operation_codes' => [
                'ANONYMIZE_DISIPLIN_VAKA_SAVUNMA_FIELDS',
                'DELETE_SAVUNMA_BELGE_FILES_IF_LINKED',
            ],
            'expected_row_counts' => ['disiplin_vakalar' => $count],
            'external_file_count' => $fileCount,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $vakaId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        if ($vakaId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        $stmt = $pdo->prepare(
            'SELECT id, personel_id, savunma_konu, savunma_belge_surec_id
             FROM disiplin_vakalar WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $vakaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }
        if ((string) ($row['savunma_konu'] ?? '') === 'DESTROYED') {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId > 0 && (int) ($row['personel_id'] ?? 0) !== $personelId) {
            throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
        }

        $filesDeleted = 0;
        $belgeSurecId = (int) ($row['savunma_belge_surec_id'] ?? 0);
        if ($belgeSurecId > 0 && $this->tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            $verStmt = $pdo->prepare(
                'SELECT storage_key FROM personel_belge_dosya_surumleri WHERE surec_id = :sid'
            );
            $verStmt->execute(['sid' => $belgeSurecId]);
            while ($ver = $verStmt->fetch(PDO::FETCH_ASSOC)) {
                $key = trim((string) ($ver['storage_key'] ?? ''));
                if ($key === '' || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,16}$/', $key)) {
                    continue;
                }
                try {
                    PersonelBelgeStorageService::deleteKey($key);
                    $filesDeleted++;
                } catch (Throwable $e) {
                    if ($e->getMessage() !== 'PERSONEL_BELGE_DOSYA_BULUNAMADI') {
                        throw $e;
                    }
                }
            }
            if ($this->tableExists($pdo, 'personel_belge_auditleri')) {
                $pdo->prepare('DELETE FROM personel_belge_auditleri WHERE surec_id = :sid')
                    ->execute(['sid' => $belgeSurecId]);
            }
            $pdo->prepare('DELETE FROM personel_belge_dosya_surumleri WHERE surec_id = :sid')
                ->execute(['sid' => $belgeSurecId]);
        }

        $upd = $pdo->prepare(
            "UPDATE disiplin_vakalar SET
                savunma_talep_tarihi = NULL,
                savunma_deadline_at = NULL,
                savunma_yer = NULL,
                savunma_konu = 'DESTROYED',
                savunma_isteyen_user_id = NULL,
                savunma_belge_surec_id = NULL,
                savunma_received_at = NULL
             WHERE id = :id"
        );
        $upd->execute(['id' => $vakaId]);

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_anonymized' => ['disiplin_vakalar' => 1],
                'files_deleted' => $filesDeleted,
                'savunma_belge_surec_cleared' => $belgeSurecId > 0 ? 1 : 0,
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
