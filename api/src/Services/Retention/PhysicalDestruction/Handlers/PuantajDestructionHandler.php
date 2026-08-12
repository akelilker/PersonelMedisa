<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\PuantajDonemKilidiService;
use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * PUANTAJ (Pack 3B + snapshot-pin follow-up):
 * - DECISION_01 OPTION B: full muhur revision graph for (sube, yil, ay)
 * - DECISION_02 OPTION A: hard-delete period gunluk_puantaj after dependents cleared
 * - DECISION_03 OPTION C: QR ledger RESTRICT → fail-closed (do not delete ledger)
 * - PUANTAJ × PAYROLL SNAPSHOT OPTION A:
 *   - no pin → FULL_GRAPH_DELETE
 *   - pin via maas_hesaplama_donem_snapshotlari.muhur_id → preserve seal/revision headers;
 *     delete only seal lines + gunluk_puantaj
 *
 * Never auto-destroys owner-unclear RESTRICT children (etki, donem_kapanis).
 * Never mutates payroll snapshots or snapshot.muhur_id.
 */
final class PuantajDestructionHandler implements DestructionHandlerInterface
{
    public const MODE_FULL_GRAPH_DELETE = 'FULL_GRAPH_DELETE';
    public const MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE = 'SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE';

    public function category()
    {
        return RetentionCategories::PUANTAJ;
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
        $period = $this->period($talep, $context);
        $scope = $this->resolveScope($pdo, $period);
        $mode = $this->destructionMode($scope);

        $codes = [
            $mode === self::MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE
                ? 'PUANTAJ_SNAPSHOT_PINNED_SEAL_HEADERS_PRESERVE'
                : 'PUANTAJ_FULL_REVISION_GRAPH_DELETE',
            'PUANTAJ_SEAL_LINES_DELETE',
            'PUANTAJ_GUNLUK_HARD_DELETE',
            'PUANTAJ_QR_LEDGER_BLOCK_IF_PRESENT',
        ];

        return [
            'db_operation_codes' => $codes,
            'expected_row_counts' => [
                'destruction_mode' => $mode === self::MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE ? 1 : 0,
                'puantaj_aylik_muhurleri' => count($scope['seal_ids']),
                'puantaj_aylik_muhurleri_delete' => $mode === self::MODE_FULL_GRAPH_DELETE
                    ? count($scope['seal_ids'])
                    : 0,
                'puantaj_aylik_muhur_satirlari' => $scope['satir_count'],
                'gunluk_puantaj' => count($scope['daily_ids']),
                'puantaj_donem_reopen_talepleri' => $mode === self::MODE_FULL_GRAPH_DELETE
                    ? $scope['reopen_talep_count']
                    : 0,
                'open_reopen_talep_count' => $scope['open_reopen_talep_count'],
                'payroll_snapshot_pin_count' => $scope['payroll_snapshot_pin_count'],
                'qr_puantaj_candidate_decision_ledger_blocking' => $scope['qr_blocking_count'],
            ],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $period = $this->period($talep, $context);
        if ($period['sube_id'] <= 0 || $period['yil'] < 2000 || $period['ay'] < 1 || $period['ay'] > 12) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }
        if (!$this->tableExists($pdo, 'puantaj_aylik_muhurleri')) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        // Lock order (documented): destruction request FOR UPDATE (service) → period lock → scope re-read.
        // Reopen path acquires period lock only. Serializes destroy vs reopen create/approve/reseal.
        PuantajDonemKilidiService::acquire($pdo, $period['sube_id'], $period['yil'], $period['ay']);

        $scope = $this->resolveScope($pdo, $period);
        if (count($scope['seal_ids']) === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $mode = $this->destructionMode($scope);
        $this->assertPlanModeMatches($plan, $mode, $scope);

        $blocker = $this->dependencyBlocker($pdo, $scope, $mode);
        if ($blocker === PhysicalDestructionCodes::CODE_PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT);
        }
        if ($blocker === PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS);
        }
        if ($blocker !== null) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
        }

        if ($mode === self::MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE) {
            return $this->executePinnedPreserve($pdo, $period, $scope);
        }

        return $this->executeFullGraphDelete($pdo, $period, $scope);
    }

    /**
     * @param array{sube_id:int,yil:int,ay:int} $period
     * @param array<string, mixed> $scope
     * @return array{result_code: string, summary: array<string, mixed>}
     */
    private function executePinnedPreserve(PDO $pdo, array $period, array $scope)
    {
        $deleted = [
            'gunluk_puantaj' => 0,
            'puantaj_aylik_muhur_satirlari' => 0,
            'puantaj_aylik_muhurleri' => 0,
            'puantaj_donem_reopen_talepleri' => 0,
        ];

        if (count($scope['daily_ids']) === 0 && (int) $scope['satir_count'] === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        // Snapshot pin identity snapshot (must remain unchanged).
        $pinBefore = $this->loadPinRows($pdo, $scope['seal_ids']);

        // 1) Hard-delete period daily rows.
        if (count($scope['daily_ids']) > 0) {
            $placeholders = implode(',', array_fill(0, count($scope['daily_ids']), '?'));
            $delDaily = $pdo->prepare("DELETE FROM gunluk_puantaj WHERE id IN ({$placeholders})");
            $delDaily->execute($scope['daily_ids']);
            $deleted['gunluk_puantaj'] = (int) $delDaily->rowCount();
        }

        // 2) Delete personal seal-line payload only — headers / revision graph stay.
        $placeholders = implode(',', array_fill(0, count($scope['seal_ids']), '?'));
        if ($this->tableExists($pdo, 'puantaj_aylik_muhur_satirlari')) {
            $delLines = $pdo->prepare(
                "DELETE FROM puantaj_aylik_muhur_satirlari WHERE muhur_id IN ({$placeholders})"
            );
            $delLines->execute($scope['seal_ids']);
            $deleted['puantaj_aylik_muhur_satirlari'] = (int) $delLines->rowCount();
        }

        // Headers unchanged.
        $c = $pdo->prepare(
            "SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE id IN ({$placeholders})"
        );
        $c->execute($scope['seal_ids']);
        if ((int) $c->fetchColumn() !== count($scope['seal_ids'])) {
            throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
        }

        $graph = $this->loadSealGraphLinks($pdo, $scope['seal_ids']);
        foreach ($scope['seal_graph_links'] as $sealId => $links) {
            if (!isset($graph[$sealId])
                || $graph[$sealId]['parent_muhur_id'] !== $links['parent_muhur_id']
                || $graph[$sealId]['superseded_by_id'] !== $links['superseded_by_id']
            ) {
                throw new RuntimeException('PUANTAJ_REVISION_GRAPH_MUTATED');
            }
        }

        $pinAfter = $this->loadPinRows($pdo, $scope['seal_ids']);
        if ($pinAfter !== $pinBefore) {
            throw new RuntimeException('PAYROLL_SNAPSHOT_PIN_MUTATED');
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => $deleted,
                'files_deleted' => 0,
                'destruction_mode' => self::MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE,
                'preserved' => [
                    'puantaj_aylik_muhurleri' => true,
                    'revision_graph' => true,
                    'maas_hesaplama_donem_snapshotlari' => true,
                    'puantaj_donem_reopen_talepleri' => true,
                ],
                'period' => [
                    'sube_id' => $period['sube_id'],
                    'yil' => $period['yil'],
                    'ay' => $period['ay'],
                ],
            ],
        ];
    }

    /**
     * @param array{sube_id:int,yil:int,ay:int} $period
     * @param array<string, mixed> $scope
     * @return array{result_code: string, summary: array<string, mixed>}
     */
    private function executeFullGraphDelete(PDO $pdo, array $period, array $scope)
    {
        $deleted = [
            'gunluk_puantaj' => 0,
            'puantaj_donem_reopen_talepleri' => 0,
            'puantaj_aylik_muhur_satirlari' => 0,
            'puantaj_aylik_muhurleri' => 0,
        ];

        // 1) Hard-delete period daily rows (after RESTRICT children verified absent).
        if (count($scope['daily_ids']) > 0) {
            $placeholders = implode(',', array_fill(0, count($scope['daily_ids']), '?'));
            $delDaily = $pdo->prepare("DELETE FROM gunluk_puantaj WHERE id IN ({$placeholders})");
            $delDaily->execute($scope['daily_ids']);
            $deleted['gunluk_puantaj'] = (int) $delDaily->rowCount();
        }

        // 2) Clear seal → reopen talep pointer, then delete period reopen tales (required for seal DELETE).
        if ($this->columnExists($pdo, 'puantaj_aylik_muhurleri', 'reopen_talep_id')) {
            $placeholders = implode(',', array_fill(0, count($scope['seal_ids']), '?'));
            $pdo->prepare(
                "UPDATE puantaj_aylik_muhurleri SET reopen_talep_id = NULL WHERE id IN ({$placeholders})"
            )->execute($scope['seal_ids']);
        }
        if ($this->tableExists($pdo, 'puantaj_donem_reopen_talepleri') && count($scope['seal_ids']) > 0) {
            $placeholders = implode(',', array_fill(0, count($scope['seal_ids']), '?'));
            $delReopen = $pdo->prepare(
                "DELETE FROM puantaj_donem_reopen_talepleri
                 WHERE kaynak_muhur_id IN ({$placeholders})
                    OR reseal_muhur_id IN ({$placeholders})"
            );
            $params = array_merge($scope['seal_ids'], $scope['seal_ids']);
            $delReopen->execute($params);
            $deleted['puantaj_donem_reopen_talepleri'] = (int) $delReopen->rowCount();
        }

        // 3) Break self-FK revision graph, then delete seals (satirlari CASCADE).
        $placeholders = implode(',', array_fill(0, count($scope['seal_ids']), '?'));
        if ($this->columnExists($pdo, 'puantaj_aylik_muhurleri', 'parent_muhur_id')) {
            $pdo->prepare(
                "UPDATE puantaj_aylik_muhurleri
                 SET parent_muhur_id = NULL, superseded_by_id = NULL
                 WHERE id IN ({$placeholders})"
            )->execute($scope['seal_ids']);
        }

        $satirBefore = 0;
        if ($this->tableExists($pdo, 'puantaj_aylik_muhur_satirlari')) {
            $c = $pdo->prepare(
                "SELECT COUNT(*) FROM puantaj_aylik_muhur_satirlari WHERE muhur_id IN ({$placeholders})"
            );
            $c->execute($scope['seal_ids']);
            $satirBefore = (int) $c->fetchColumn();
        }

        $delSeals = $pdo->prepare(
            "DELETE FROM puantaj_aylik_muhurleri WHERE id IN ({$placeholders})"
        );
        $delSeals->execute($scope['seal_ids']);
        $deleted['puantaj_aylik_muhurleri'] = (int) $delSeals->rowCount();
        $deleted['puantaj_aylik_muhur_satirlari'] = $satirBefore;

        if ($deleted['puantaj_aylik_muhurleri'] !== count($scope['seal_ids'])) {
            throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => $deleted,
                'files_deleted' => 0,
                'destruction_mode' => self::MODE_FULL_GRAPH_DELETE,
                'period' => [
                    'sube_id' => $period['sube_id'],
                    'yil' => $period['yil'],
                    'ay' => $period['ay'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     * @return array{sube_id:int,yil:int,ay:int}
     */
    private function period(array $talep, array $context)
    {
        return [
            'sube_id' => (int) ($context['sube_id'] ?? $talep['canonical_sube_id'] ?? $talep['sube_id'] ?? 0),
            'yil' => (int) ($context['yil'] ?? $talep['period_yil'] ?? $talep['yil'] ?? 0),
            'ay' => (int) ($context['ay'] ?? $talep['period_ay'] ?? $talep['ay'] ?? 0),
        ];
    }

    /**
     * @param array{sube_id:int,yil:int,ay:int} $period
     * @return array{
     *   seal_ids:list<int>,
     *   daily_ids:list<int>,
     *   satir_count:int,
     *   reopen_talep_count:int,
     *   open_reopen_talep_count:int,
     *   qr_blocking_count:int,
     *   payroll_snapshot_pin_count:int,
     *   seal_graph_links:array<int, array{parent_muhur_id: ?int, superseded_by_id: ?int}>
     * }
     */
    private function resolveScope(PDO $pdo, array $period)
    {
        $sealIds = [];
        $stmt = $pdo->prepare(
            'SELECT id FROM puantaj_aylik_muhurleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
             ORDER BY revision_no ASC, id ASC'
        );
        $stmt->execute([
            'sube_id' => $period['sube_id'],
            'yil' => $period['yil'],
            'ay' => $period['ay'],
        ]);
        while ($id = $stmt->fetchColumn()) {
            $sealIds[] = (int) $id;
        }

        $dailyIds = [];
        if ($this->tableExists($pdo, 'gunluk_puantaj')) {
            $daily = $pdo->prepare(
                'SELECT gp.id
                 FROM gunluk_puantaj gp
                 INNER JOIN personeller p ON p.id = gp.personel_id
                 WHERE p.sube_id = :sube_id
                   AND YEAR(gp.tarih) = :yil
                   AND MONTH(gp.tarih) = :ay'
            );
            $daily->execute([
                'sube_id' => $period['sube_id'],
                'yil' => $period['yil'],
                'ay' => $period['ay'],
            ]);
            while ($id = $daily->fetchColumn()) {
                $dailyIds[] = (int) $id;
            }
            if (count($sealIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($sealIds), '?'));
                $bySeal = $pdo->prepare(
                    "SELECT id FROM gunluk_puantaj WHERE muhur_id IN ({$placeholders})"
                );
                $bySeal->execute($sealIds);
                while ($id = $bySeal->fetchColumn()) {
                    $id = (int) $id;
                    if (!in_array($id, $dailyIds, true)) {
                        $dailyIds[] = $id;
                    }
                }
            }
        }

        $satirCount = 0;
        if (count($sealIds) > 0 && $this->tableExists($pdo, 'puantaj_aylik_muhur_satirlari')) {
            $placeholders = implode(',', array_fill(0, count($sealIds), '?'));
            $c = $pdo->prepare(
                "SELECT COUNT(*) FROM puantaj_aylik_muhur_satirlari WHERE muhur_id IN ({$placeholders})"
            );
            $c->execute($sealIds);
            $satirCount = (int) $c->fetchColumn();
        }

        $reopenCount = 0;
        $openReopenCount = 0;
        if ($this->tableExists($pdo, 'puantaj_donem_reopen_talepleri')) {
            if (count($sealIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($sealIds), '?'));
                $c = $pdo->prepare(
                    "SELECT COUNT(*) FROM puantaj_donem_reopen_talepleri
                     WHERE kaynak_muhur_id IN ({$placeholders})
                        OR reseal_muhur_id IN ({$placeholders})"
                );
                $c->execute(array_merge($sealIds, $sealIds));
                $reopenCount = (int) $c->fetchColumn();
            }

            $open = $pdo->prepare(
                'SELECT COUNT(*) FROM puantaj_donem_reopen_talepleri
                 WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
                   AND talep_durumu IN (:pending, :approved)'
            );
            $open->execute([
                'sube_id' => $period['sube_id'],
                'yil' => $period['yil'],
                'ay' => $period['ay'],
                'pending' => PuantajDonemPeriodService::TALEP_ONAY_BEKLIYOR,
                'approved' => PuantajDonemPeriodService::TALEP_ONAYLANDI,
            ]);
            $openReopenCount = (int) $open->fetchColumn();
        }

        $qrBlocking = 0;
        if (count($dailyIds) > 0 && $this->tableExists($pdo, 'qr_puantaj_candidate_decision_ledger')) {
            $placeholders = implode(',', array_fill(0, count($dailyIds), '?'));
            $c = $pdo->prepare(
                "SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger
                 WHERE puantaj_id IN ({$placeholders})"
            );
            $c->execute($dailyIds);
            $qrBlocking = (int) $c->fetchColumn();
        }

        $pinCount = 0;
        if (count($sealIds) > 0 && $this->tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            $placeholders = implode(',', array_fill(0, count($sealIds), '?'));
            $c = $pdo->prepare(
                "SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari
                 WHERE muhur_id IN ({$placeholders})"
            );
            $c->execute($sealIds);
            $pinCount = (int) $c->fetchColumn();
        }

        return [
            'seal_ids' => $sealIds,
            'daily_ids' => $dailyIds,
            'satir_count' => $satirCount,
            'reopen_talep_count' => $reopenCount,
            'open_reopen_talep_count' => $openReopenCount,
            'qr_blocking_count' => $qrBlocking,
            'payroll_snapshot_pin_count' => $pinCount,
            'seal_graph_links' => $this->loadSealGraphLinks($pdo, $sealIds),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function destructionMode(array $scope)
    {
        return ((int) ($scope['payroll_snapshot_pin_count'] ?? 0) > 0)
            ? self::MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE
            : self::MODE_FULL_GRAPH_DELETE;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $scope
     */
    private function assertPlanModeMatches(array $plan, $mode, array $scope)
    {
        $codes = isset($plan['db_operation_codes']) && is_array($plan['db_operation_codes'])
            ? $plan['db_operation_codes']
            : [];
        $plannedPinned = in_array('PUANTAJ_SNAPSHOT_PINNED_SEAL_HEADERS_PRESERVE', $codes, true);
        $plannedFull = in_array('PUANTAJ_FULL_REVISION_GRAPH_DELETE', $codes, true);
        if ($mode === self::MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE && !$plannedPinned) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
        if ($mode === self::MODE_FULL_GRAPH_DELETE && !$plannedFull) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $expectedModeFlag = isset($plan['expected_row_counts']['destruction_mode'])
            ? (int) $plan['expected_row_counts']['destruction_mode']
            : -1;
        $actualModeFlag = $mode === self::MODE_SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE ? 1 : 0;
        if ($expectedModeFlag >= 0 && $expectedModeFlag !== $actualModeFlag) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $expectedPins = isset($plan['expected_row_counts']['payroll_snapshot_pin_count'])
            ? (int) $plan['expected_row_counts']['payroll_snapshot_pin_count']
            : -1;
        if ($expectedPins >= 0 && $expectedPins !== (int) $scope['payroll_snapshot_pin_count']) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $expectedSeals = isset($plan['expected_row_counts']['puantaj_aylik_muhurleri'])
            ? (int) $plan['expected_row_counts']['puantaj_aylik_muhurleri']
            : -1;
        if ($expectedSeals >= 0 && $expectedSeals !== count($scope['seal_ids'])) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $expectedLines = isset($plan['expected_row_counts']['puantaj_aylik_muhur_satirlari'])
            ? (int) $plan['expected_row_counts']['puantaj_aylik_muhur_satirlari']
            : -1;
        if ($expectedLines >= 0 && $expectedLines !== (int) $scope['satir_count']) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $expectedDaily = isset($plan['expected_row_counts']['gunluk_puantaj'])
            ? (int) $plan['expected_row_counts']['gunluk_puantaj']
            : -1;
        if ($expectedDaily >= 0 && $expectedDaily !== count($scope['daily_ids'])) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $expectedOpenReopen = isset($plan['expected_row_counts']['open_reopen_talep_count'])
            ? (int) $plan['expected_row_counts']['open_reopen_talep_count']
            : -1;
        if ($expectedOpenReopen >= 0 && $expectedOpenReopen !== (int) $scope['open_reopen_talep_count']) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @return string|null
     */
    private function dependencyBlocker(PDO $pdo, array $scope, $mode)
    {
        if ((int) ($scope['qr_blocking_count'] ?? 0) > 0) {
            return PhysicalDestructionCodes::CODE_PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT;
        }

        if ((int) ($scope['open_reopen_talep_count'] ?? 0) > 0) {
            return PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS;
        }

        $dailyIds = $scope['daily_ids'];
        $sealIds = $scope['seal_ids'];

        if (count($dailyIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($dailyIds), '?'));

            if ($this->tableExists($pdo, 'onayli_bildirim_puantaj_etki_adaylari')) {
                if ($this->columnExists($pdo, 'onayli_bildirim_puantaj_etki_adaylari', 'uygulanan_puantaj_id')) {
                    $c = $pdo->prepare(
                        "SELECT COUNT(*) FROM onayli_bildirim_puantaj_etki_adaylari
                         WHERE mevcut_puantaj_id IN ({$placeholders})
                            OR uygulanan_puantaj_id IN ({$placeholders})"
                    );
                    $c->execute(array_merge($dailyIds, $dailyIds));
                } else {
                    $c = $pdo->prepare(
                        "SELECT COUNT(*) FROM onayli_bildirim_puantaj_etki_adaylari
                         WHERE mevcut_puantaj_id IN ({$placeholders})"
                    );
                    $c->execute($dailyIds);
                }
                if ((int) $c->fetchColumn() > 0) {
                    return PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN;
                }
            }

            if ($this->tableExists($pdo, 'bildirim_puantaj_etki_cakisma_cozumleri')) {
                $c = $pdo->prepare(
                    "SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri
                     WHERE puantaj_id IN ({$placeholders})"
                );
                $c->execute($dailyIds);
                if ((int) $c->fetchColumn() > 0) {
                    return PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN;
                }
            }
        }

        // Payroll snapshot pin is a MODE switch, not a hard block.
        if ($mode === self::MODE_FULL_GRAPH_DELETE && count($sealIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($sealIds), '?'));

            if ($this->tableExists($pdo, 'donem_kapanis_auditleri')) {
                $c = $pdo->prepare(
                    "SELECT COUNT(*) FROM donem_kapanis_auditleri
                     WHERE muhur_id IN ({$placeholders})"
                );
                $c->execute($sealIds);
                if ((int) $c->fetchColumn() > 0) {
                    return PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN;
                }
            }

            if ($this->tableExists($pdo, 'maas_hesaplama_snapshot_auditleri')
                && $this->columnExists($pdo, 'maas_hesaplama_snapshot_auditleri', 'muhur_id')
            ) {
                $c = $pdo->prepare(
                    "SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri
                     WHERE muhur_id IN ({$placeholders})"
                );
                $c->execute($sealIds);
                if ((int) $c->fetchColumn() > 0) {
                    return PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN;
                }
            }
        }

        return null;
    }

    /**
     * @param list<int> $sealIds
     * @return array<int, array{parent_muhur_id: ?int, superseded_by_id: ?int}>
     */
    private function loadSealGraphLinks(PDO $pdo, array $sealIds)
    {
        $out = [];
        if (count($sealIds) === 0
            || !$this->columnExists($pdo, 'puantaj_aylik_muhurleri', 'parent_muhur_id')
        ) {
            foreach ($sealIds as $id) {
                $out[$id] = ['parent_muhur_id' => null, 'superseded_by_id' => null];
            }

            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($sealIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, parent_muhur_id, superseded_by_id
             FROM puantaj_aylik_muhurleri WHERE id IN ({$placeholders})"
        );
        $stmt->execute($sealIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $out[$id] = [
                'parent_muhur_id' => $row['parent_muhur_id'] !== null ? (int) $row['parent_muhur_id'] : null,
                'superseded_by_id' => $row['superseded_by_id'] !== null ? (int) $row['superseded_by_id'] : null,
            ];
        }

        return $out;
    }

    /**
     * Deterministic pin fingerprint: id|muhur_id pairs sorted.
     *
     * @param list<int> $sealIds
     * @return list<string>
     */
    private function loadPinRows(PDO $pdo, array $sealIds)
    {
        if (count($sealIds) === 0 || !$this->tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($sealIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, muhur_id FROM maas_hesaplama_donem_snapshotlari
             WHERE muhur_id IN ({$placeholders})
             ORDER BY id ASC"
        );
        $stmt->execute($sealIds);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = (int) $row['id'] . ':' . (int) $row['muhur_id'];
        }

        return $rows;
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
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c
             LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table, 'c' => (string) $column]);

        return (bool) $stmt->fetchColumn();
    }
}
