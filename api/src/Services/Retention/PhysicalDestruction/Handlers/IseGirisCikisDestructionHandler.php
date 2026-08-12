<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * ISE_GIRIS_CIKIS: delete only qr_attendance_events for the canonical personel target.
 * Never deletes personel master. Never touches other personel rows.
 */
final class IseGirisCikisDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::ISE_GIRIS_CIKIS;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_DELETE_ROWS;
    }

    public function isExecutable()
    {
        return true;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        $personelId = $this->personelId($talep, $context);
        $count = 0;
        if ($this->tableExists($pdo, 'qr_attendance_events') && $personelId > 0) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = :pid'
            );
            $stmt->execute(['pid' => $personelId]);
            $count = (int) $stmt->fetchColumn();
        }

        return [
            'db_operation_codes' => ['DELETE_QR_ATTENDANCE_EVENTS_BY_PERSONEL'],
            'expected_row_counts' => [
                'qr_attendance_events' => $count,
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
        if (!$this->tableExists($pdo, 'qr_attendance_events')) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $expected = isset($plan['expected_row_counts']['qr_attendance_events'])
            ? (int) $plan['expected_row_counts']['qr_attendance_events']
            : -1;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = :pid');
        $stmt->execute(['pid' => $personelId]);
        $before = (int) $stmt->fetchColumn();

        if ($before === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }
        if ($expected >= 0 && $before !== $expected) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $del = $pdo->prepare('DELETE FROM qr_attendance_events WHERE personel_id = :pid');
        $del->execute(['pid' => $personelId]);
        $deleted = (int) $del->rowCount();

        // Scope guard: never delete other personels (already scoped by WHERE).
        $stmt->execute(['pid' => $personelId]);
        $after = (int) $stmt->fetchColumn();
        if ($after !== 0) {
            throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => [
                    'qr_attendance_events' => $deleted,
                ],
                'files_deleted' => 0,
            ],
        ];
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
        if ((string) ($talep['entity_type'] ?? '') === 'personel'
            || (string) ($context['entity_type'] ?? '') === 'personel'
        ) {
            return (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        }

        return 0;
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
