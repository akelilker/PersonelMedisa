<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * PERSONEL_OZLUK: schema-forced PII anonymize/tombstone (hard DELETE blocked by RESTRICT wall + CASCADE risk).
 * Last-stage gate: dependent retention category source rows must not remain.
 */
final class PersonelOzlukDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::PERSONEL_OZLUK;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_ANONYMIZE_FIELDS;
    }

    public function isExecutable()
    {
        return true;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        $personelId = $this->personelId($talep, $context);
        $exists = 0;
        if ($personelId > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM personeller WHERE id = :id');
            $stmt->execute(['id' => $personelId]);
            $exists = (int) $stmt->fetchColumn();
        }

        return [
            'db_operation_codes' => [
                'GATE_DEPENDENT_RETENTION_RECORDS',
                'ANONYMIZE_PERSONEL_PII_TOMBSTONE',
                'UNBIND_USERS_PERSONEL',
            ],
            'expected_row_counts' => [
                'personeller' => $exists,
            ],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $personelId = $this->personelId($talep, $context);
        if ($personelId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        $stmt = $pdo->prepare('SELECT id, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $deps = $this->dependentRetentionRemain($pdo, $personelId);
        if ($deps > 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
        }

        // Unbind user↔personel before anonymize (RESTRICT).
        $usersUnbound = 0;
        if ($this->columnExists($pdo, 'users', 'personel_id')) {
            $u = $pdo->prepare('UPDATE users SET personel_id = NULL WHERE personel_id = :pid');
            $u->execute(['pid' => $personelId]);
            $usersUnbound = (int) $u->rowCount();
        }

        // Unique synthetic TC: 9 + zero-padded id (11 chars). Non-PII tombstone labels.
        $tc = '9' . str_pad((string) $personelId, 10, '0', STR_PAD_LEFT);
        if (strlen($tc) !== 11) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        $upd = $pdo->prepare(
            "UPDATE personeller SET
                tc_kimlik_no = :tc,
                ad = 'DESTROYED',
                soyad = 'PERSONEL',
                telefon = '00000000000',
                acil_durum_kisi = 'DESTROYED',
                acil_durum_telefon = '00000000000',
                sicil_no = :sicil,
                dogum_yeri = NULL,
                kan_grubu = NULL,
                maas_tutari = NULL,
                aktif_durum = 'PASIF'
             WHERE id = :id"
        );
        $upd->execute([
            'tc' => $tc,
            'sicil' => 'D-' . $personelId,
            'id' => $personelId,
        ]);
        if ((int) $upd->rowCount() < 1) {
            // MySQL may report 0 if values already match — verify tombstone.
            $check = $pdo->prepare('SELECT ad, soyad, tc_kimlik_no FROM personeller WHERE id = :id');
            $check->execute(['id' => $personelId]);
            $after = $check->fetch(PDO::FETCH_ASSOC);
            if (!$after
                || (string) $after['ad'] !== 'DESTROYED'
                || (string) $after['soyad'] !== 'PERSONEL'
                || (string) $after['tc_kimlik_no'] !== $tc
            ) {
                throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
            }
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_anonymized' => [
                    'personeller' => 1,
                ],
                'users_unbound' => $usersUnbound,
                'files_deleted' => 0,
            ],
        ];
    }

    /**
     * Conservative last-stage gate — other retained category sources still present.
     */
    private function dependentRetentionRemain(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        $total = 0;

        if ($this->tableExists($pdo, 'qr_attendance_events')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = :pid');
            $stmt->execute(['pid' => $personelId]);
            $total += (int) $stmt->fetchColumn();
        }

        if ($this->tableExists($pdo, 'surecler')) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM surecler
                 WHERE personel_id = :pid
                   AND (state IS NULL OR state <> 'IPTAL')
                   AND UPPER(surec_turu) <> 'ISTEN_AYRILMA'"
            );
            $stmt->execute(['pid' => $personelId]);
            $total += (int) $stmt->fetchColumn();
        }

        if ($this->tableExists($pdo, 'disiplin_vakalar')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM disiplin_vakalar WHERE personel_id = :pid');
            $stmt->execute(['pid' => $personelId]);
            $total += (int) $stmt->fetchColumn();
        }

        if ($this->tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE personel_id = :pid'
            );
            $stmt->execute(['pid' => $personelId]);
            $total += (int) $stmt->fetchColumn();
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     */
    private function personelId(array $talep, array $context)
    {
        if (!empty($context['personel_id'])) {
            return (int) $context['personel_id'];
        }
        if (!empty($talep['personel_id'])) {
            return (int) $talep['personel_id'];
        }

        return (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
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

    private function columnExists(PDO $pdo, $table, $column)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table, 'c' => (string) $column]);

        return (bool) $stmt->fetchColumn();
    }
}
