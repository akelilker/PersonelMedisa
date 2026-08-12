<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use Medisa\Api\Services\Retention\RetentionSchemaGate;
use Medisa\Api\Services\Retention\RetentionSourceAdapterService;
use Medisa\Api\Services\Retention\RetentionTargetResolver;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Canonical owner for physical destruction plan + execute.
 * Eligibility remains RetentionPolicyService. Workflow orchestration may call this.
 *
 * Default feature flag OFF → DESTRUCTION_EXECUTION_DISABLED (fail-closed).
 * Never trusts client table/column/SHA/scope. Client may send only request id + plan hash + nonce + confirmation.
 */
final class PhysicalDestructionService
{
    /**
     * Config key + env override. Default false.
     */
    public static function isEnabled()
    {
        $env = getenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED');
        if (is_string($env) && $env !== '') {
            $v = strtolower(trim($env));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        if (function_exists('medisa_config')) {
            $cfg = medisa_config('retention_physical_destruction_enabled', false);

            return $cfg === true || $cfg === 1 || $cfg === '1';
        }

        return false;
    }

    /**
     * Build PII-free deterministic plan + plan_hash for an approved request context.
     *
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function buildPlan(PDO $pdo, array $talep, array $context)
    {
        $category = (string) ($talep['category'] ?? $context['category'] ?? '');
        $handler = RetentionDestructionHandlerRegistry::forCategory($category);
        $handlerPlan = $handler->plan($pdo, $talep, $context);

        $plan = [
            'category' => $category,
            'entity_type' => (string) ($talep['entity_type'] ?? $context['entity_type'] ?? ''),
            'record_id' => (int) ($talep['record_id'] ?? $context['record_id'] ?? 0),
            'handler_version' => PhysicalDestructionCodes::HANDLER_VERSION,
            'execution_mode' => $handler->executionMode(),
            'db_operation_codes' => isset($handlerPlan['db_operation_codes']) && is_array($handlerPlan['db_operation_codes'])
                ? array_values($handlerPlan['db_operation_codes'])
                : [],
            'expected_row_counts' => isset($handlerPlan['expected_row_counts']) && is_array($handlerPlan['expected_row_counts'])
                ? $handlerPlan['expected_row_counts']
                : new \stdClass(),
            'external_file_count' => isset($handlerPlan['external_file_count'])
                ? (int) $handlerPlan['external_file_count']
                : 0,
            'source_version_identity' => (string) (
                $context['source_version_identity']
                ?? $talep['source_version_identity_snapshot']
                ?? ''
            ),
            'source_sha256' => isset($context['current_sha256'])
                ? strtolower((string) $context['current_sha256'])
                : (isset($talep['source_sha256_snapshot'])
                    ? strtolower((string) $talep['source_sha256_snapshot'])
                    : null),
            'request_id' => (int) ($talep['id'] ?? 0),
            'policy_blocker' => isset($handlerPlan['policy_blocker']) && $handlerPlan['policy_blocker'] !== null && $handlerPlan['policy_blocker'] !== ''
                ? (string) $handlerPlan['policy_blocker']
                : null,
            'executable' => (bool) $handler->isExecutable()
                && !(isset($handlerPlan['policy_blocker']) && $handlerPlan['policy_blocker'] !== null && $handlerPlan['policy_blocker'] !== ''),
        ];

        // Normalize expected_row_counts to object-like assoc for stable JSON.
        if ($plan['expected_row_counts'] instanceof \stdClass) {
            $plan['expected_row_counts'] = [];
        }

        $planHash = self::hashPlan($plan);
        $plan['plan_hash'] = $planHash;

        return $plan;
    }

    /**
     * Canonical stable JSON → SHA256 (sorted keys, no PII fields beyond opaque ids/hashes).
     *
     * @param array<string, mixed> $plan
     */
    public static function hashPlan(array $plan)
    {
        $canonical = [
            'category' => (string) ($plan['category'] ?? ''),
            'entity_type' => (string) ($plan['entity_type'] ?? ''),
            'record_id' => (int) ($plan['record_id'] ?? 0),
            'handler_version' => (string) ($plan['handler_version'] ?? ''),
            'execution_mode' => (string) ($plan['execution_mode'] ?? ''),
            'db_operation_codes' => array_values(isset($plan['db_operation_codes']) && is_array($plan['db_operation_codes'])
                ? $plan['db_operation_codes']
                : []),
            'expected_row_counts' => self::normalizeCounts(
                isset($plan['expected_row_counts']) && is_array($plan['expected_row_counts'])
                    ? $plan['expected_row_counts']
                    : []
            ),
            'external_file_count' => (int) ($plan['external_file_count'] ?? 0),
            'source_version_identity' => (string) ($plan['source_version_identity'] ?? ''),
            'source_sha256' => $plan['source_sha256'] !== null && $plan['source_sha256'] !== ''
                ? strtolower((string) $plan['source_sha256'])
                : null,
            'request_id' => (int) ($plan['request_id'] ?? 0),
            'executable' => !empty($plan['executable']),
            'policy_blocker' => isset($plan['policy_blocker']) && $plan['policy_blocker'] !== null && $plan['policy_blocker'] !== ''
                ? (string) $plan['policy_blocker']
                : null,
        ];

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('DESTRUCTION_PLAN_HASH_FAILED');
        }

        return hash('sha256', $json);
    }

    /**
     * Non-destructive evaluate path: eligibility + plan (no physical mutation).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function evaluate(PDO $pdo, array $user, $talepId)
    {
        RolePermissions::assertAny($user, [
            'retention.destruction.approve',
            'retention.destruction.view',
            'retention.destruction.execute',
        ]);

        $talep = DestructionWorkflowService::getById($pdo, (int) $talepId);
        if (!$talep) {
            throw new RuntimeException('DESTRUCTION_REQUEST_NOT_FOUND');
        }

        $existing = self::findExecutionByTalepId($pdo, (int) $talep['id']);
        if ($existing && (string) ($existing['execution_state'] ?? '') === PhysicalDestructionCodes::STATE_EXECUTED) {
            return [
                'item' => $talep,
                'execution' => [
                    'eligible' => false,
                    'code' => PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
                    'message' => PhysicalDestructionCodes::message(PhysicalDestructionCodes::CODE_ALREADY_EXECUTED),
                    'execution_id' => (int) $existing['id'],
                    'plan_hash' => (string) $existing['plan_hash'],
                    'post_state' => PhysicalDestructionCodes::CODE_POST_STATE_DESTROYED_AS_APPROVED,
                ],
                'plan' => null,
            ];
        }

        $context = self::enrichContextFromTalep($pdo, $talep);
        $eligibility = RetentionPolicyService::evaluateFinalExecutionEligibility(
            $pdo,
            (string) $talep['category'],
            $context,
            $talep,
            RetentionClock::now()
        );

        $plan = null;
        $code = (string) ($eligibility['code'] ?? '');
        if ($code === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION) {
            if (!self::isEnabled()) {
                $eligibility['eligible'] = false;
                $eligibility['code'] = PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_DISABLED;
                $eligibility['message'] = PhysicalDestructionCodes::message(
                    PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_DISABLED
                );
            } else {
                $plan = self::buildPlan($pdo, $talep, $context);
                if (empty($plan['executable'])) {
                    $eligibility['eligible'] = false;
                    $eligibility['code'] = PhysicalDestructionCodes::CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED;
                    $eligibility['message'] = PhysicalDestructionCodes::message(
                        PhysicalDestructionCodes::CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED
                    );
                    $eligibility['policy_blocker'] = $plan['policy_blocker'] ?? null;
                } else {
                    $eligibility['plan_hash'] = $plan['plan_hash'];
                    $eligibility['execution_mode'] = $plan['execution_mode'];
                    $eligibility['handler_version'] = PhysicalDestructionCodes::HANDLER_VERSION;
                }
            }
        }

        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId > 0) {
            DestructionWorkflowService::appendAuditPublic(
                $pdo,
                (int) $talep['id'],
                (string) $talep['category'],
                (string) $talep['entity_type'],
                (int) $talep['record_id'],
                $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
                'EVALUATE_EXECUTION',
                $actorId,
                null,
                $eligibility['code'] ?? null
            );
        }

        return [
            'item' => $talep,
            'execution' => $eligibility,
            'plan' => $plan,
        ];
    }

    /**
     * Physical execute — destructive. Feature flag + auth + plan hash + locks required.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function execute(PDO $pdo, array $user, $talepId, array $payload)
    {
        RolePermissions::assert($user, 'retention.destruction.execute');
        $role = strtoupper(trim((string) ($user['rol'] ?? '')));
        if ($role !== 'GENEL_YONETICI') {
            JsonResponse::forbidden('Fiziksel imha yalnizca genel yonetici tarafindan yurutulur.');
        }

        if (!self::isEnabled()) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_DISABLED);
        }

        RetentionSchemaGate::assertReady($pdo, array_merge(
            RetentionSchemaGate::destructionTables(),
            ['retention_imha_executionlari']
        ));

        $talepId = (int) $talepId;
        $expectedPlanHash = strtolower(trim((string) ($payload['expected_plan_hash'] ?? '')));
        $nonce = strtolower(trim((string) ($payload['execution_nonce'] ?? '')));
        $confirmation = trim((string) ($payload['confirmation'] ?? ''));
        $actorId = (int) ($user['id'] ?? 0);

        if ($talepId <= 0 || $actorId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedPlanHash)) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $nonce)) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }
        if ($confirmation !== PhysicalDestructionCodes::CONFIRMATION_TOKEN) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_CONFIRMATION_REQUIRED);
        }

        $pdo->beginTransaction();
        try {
            $talep = DestructionWorkflowService::getById($pdo, $talepId, true);
            if (!$talep) {
                throw new RuntimeException('DESTRUCTION_REQUEST_NOT_FOUND');
            }

            $existing = self::findExecutionByTalepId($pdo, $talepId, true);
            if ($existing && (string) ($existing['execution_state'] ?? '') === PhysicalDestructionCodes::STATE_EXECUTED) {
                $pdo->commit();

                return [
                    'item' => $talep,
                    'execution' => [
                        'eligible' => false,
                        'code' => PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
                        'message' => PhysicalDestructionCodes::message(PhysicalDestructionCodes::CODE_ALREADY_EXECUTED),
                        'execution_id' => (int) $existing['id'],
                        'plan_hash' => (string) $existing['plan_hash'],
                        'mutation_count' => 0,
                        'post_state' => PhysicalDestructionCodes::CODE_POST_STATE_DESTROYED_AS_APPROVED,
                    ],
                ];
            }

            if ((string) ($talep['status'] ?? '') !== DestructionWorkflowService::STATUS_APPROVED) {
                throw new RuntimeException(RetentionPolicyService::CODE_DESTRUCTION_REQUEST_NOT_APPROVED);
            }

            $context = self::enrichContextFromTalep($pdo, $talep);
            $eligibility = RetentionPolicyService::evaluateFinalExecutionEligibility(
                $pdo,
                (string) $talep['category'],
                $context,
                $talep,
                RetentionClock::now()
            );
            if (($eligibility['code'] ?? '') !== RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION) {
                throw new RuntimeException((string) ($eligibility['code'] ?? 'NOT_ELIGIBLE'));
            }

            $plan = self::buildPlan($pdo, $talep, $context);
            if (!hash_equals((string) $plan['plan_hash'], $expectedPlanHash)) {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
            }

            $handler = RetentionDestructionHandlerRegistry::forCategory((string) $talep['category']);
            if (empty($plan['executable'])) {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED);
            }

            // Insert PREPARED evidence before mutation (idempotency key = imha_talep_id UNIQUE).
            if (!$existing) {
                $ins = $pdo->prepare(
                    'INSERT INTO retention_imha_executionlari
                        (imha_talep_id, handler_version, execution_mode, plan_hash,
                         source_version_identity_snapshot, source_sha256_snapshot,
                         execution_nonce, result_code, result_summary_json,
                         execution_state, executed_by)
                     VALUES
                        (:talep_id, :handler_version, :execution_mode, :plan_hash,
                         :source_identity, :source_sha,
                         :nonce, :result_code, :summary,
                         :state, :executed_by)'
                );
                try {
                    $ins->execute([
                        'talep_id' => $talepId,
                        'handler_version' => PhysicalDestructionCodes::HANDLER_VERSION,
                        'execution_mode' => (string) $plan['execution_mode'],
                        'plan_hash' => (string) $plan['plan_hash'],
                        'source_identity' => (string) $plan['source_version_identity'],
                        'source_sha' => $plan['source_sha256'],
                        'nonce' => $nonce,
                        'result_code' => PhysicalDestructionCodes::STATE_PREPARED,
                        'summary' => json_encode(['phase' => 'PREPARED'], JSON_UNESCAPED_SLASHES),
                        'state' => PhysicalDestructionCodes::STATE_PREPARED,
                        'executed_by' => $actorId,
                    ]);
                } catch (Throwable $e) {
                    // Concurrent insert lost UNIQUE race → re-read winner.
                    $existing = self::findExecutionByTalepId($pdo, $talepId, true);
                    if ($existing && (string) ($existing['execution_state'] ?? '') === PhysicalDestructionCodes::STATE_EXECUTED) {
                        $pdo->commit();

                        return [
                            'item' => $talep,
                            'execution' => [
                                'eligible' => false,
                                'code' => PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
                                'message' => PhysicalDestructionCodes::message(PhysicalDestructionCodes::CODE_ALREADY_EXECUTED),
                                'execution_id' => (int) $existing['id'],
                                'plan_hash' => (string) $existing['plan_hash'],
                                'mutation_count' => 0,
                                'post_state' => PhysicalDestructionCodes::CODE_POST_STATE_DESTROYED_AS_APPROVED,
                            ],
                        ];
                    }
                    throw $e;
                }
                $executionId = (int) $pdo->lastInsertId();
            } else {
                // Resume PREPARED / FAILED retry only if same plan hash.
                if ((string) ($existing['plan_hash'] ?? '') !== (string) $plan['plan_hash']) {
                    throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
                }
                if ((string) ($existing['execution_state'] ?? '') === PhysicalDestructionCodes::STATE_EXECUTED) {
                    $pdo->commit();

                    return [
                        'item' => $talep,
                        'execution' => [
                            'eligible' => false,
                            'code' => PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
                            'message' => PhysicalDestructionCodes::message(PhysicalDestructionCodes::CODE_ALREADY_EXECUTED),
                            'execution_id' => (int) $existing['id'],
                            'plan_hash' => (string) $existing['plan_hash'],
                            'mutation_count' => 0,
                            'post_state' => PhysicalDestructionCodes::CODE_POST_STATE_DESTROYED_AS_APPROVED,
                        ],
                    ];
                }
                $executionId = (int) $existing['id'];
            }

            $category = (string) $talep['category'];
            if (RetentionPhysicalDestroyGate::requiresGate($category)) {
                RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::physicalDestroyGateTables());
            }

            try {
                RetentionPhysicalDestroyGate::open($pdo, $executionId, $talepId, $category);
                $handlerResult = $handler->execute($pdo, $talep, $context, $plan);
            } finally {
                // Never leak bypass across commit/rollback or connection reuse.
                RetentionPhysicalDestroyGate::close($pdo);
            }

            $resultCode = (string) ($handlerResult['result_code'] ?? PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED);
            $summary = isset($handlerResult['summary']) && is_array($handlerResult['summary'])
                ? $handlerResult['summary']
                : [];

            // Never persist raw PII in summary — strip common keys defensively.
            $summary = self::sanitizeSummary($summary);
            $summary['handler_version'] = PhysicalDestructionCodes::HANDLER_VERSION;
            $summary['execution_mode'] = (string) $plan['execution_mode'];
            $summary['db_operation_codes'] = $plan['db_operation_codes'];

            $upd = $pdo->prepare(
                'UPDATE retention_imha_executionlari
                 SET result_code = :result_code,
                     result_summary_json = :summary,
                     execution_state = :state,
                     executed_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND execution_state <> :executed_guard'
            );
            $upd->execute([
                'result_code' => $resultCode,
                'summary' => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'state' => PhysicalDestructionCodes::STATE_EXECUTED,
                'id' => $executionId,
                'executed_guard' => PhysicalDestructionCodes::STATE_EXECUTED,
            ]);

            DestructionWorkflowService::appendAuditPublic(
                $pdo,
                $talepId,
                (string) $talep['category'],
                (string) $talep['entity_type'],
                (int) $talep['record_id'],
                $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
                'EXECUTE',
                $actorId,
                null,
                $resultCode
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    RetentionPhysicalDestroyGate::close($pdo);
                } catch (Throwable $ignored) {
                    // ignore gate cleanup errors during rollback path
                }
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'item' => DestructionWorkflowService::getById($pdo, $talepId),
            'execution' => [
                'eligible' => false,
                'code' => $resultCode,
                'message' => PhysicalDestructionCodes::message($resultCode) !== $resultCode
                    ? PhysicalDestructionCodes::message($resultCode)
                    : RetentionPolicyService::codeMessage($resultCode),
                'execution_id' => $executionId,
                'plan_hash' => (string) $plan['plan_hash'],
                'handler_version' => PhysicalDestructionCodes::HANDLER_VERSION,
                'summary' => $summary,
                'post_state' => PhysicalDestructionCodes::CODE_POST_STATE_DESTROYED_AS_APPROVED,
            ],
            'plan' => $plan,
        ];
    }

    /**
     * Post-destruction contract: approved physical destroy evidence for target.
     */
    public static function isDestroyedAsApproved(PDO $pdo, $category, $entityType, $recordId)
    {
        if (!self::tableExists($pdo, 'retention_imha_executionlari')) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT e.id
             FROM retention_imha_executionlari e
             INNER JOIN retention_imha_talepleri t ON t.id = e.imha_talep_id
             WHERE e.execution_state = 'EXECUTED'
               AND e.result_code IN ('DESTRUCTION_EXECUTED', 'ALREADY_EXECUTED')
               AND t.category = :category
               AND t.entity_type = :entity_type
               AND t.record_id = :record_id
             LIMIT 1"
        );
        $stmt->execute([
            'category' => (string) $category,
            'entity_type' => (string) $entityType,
            'record_id' => (int) $recordId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $talep
     * @return array<string, mixed>
     */
    private static function enrichContextFromTalep(PDO $pdo, array $talep)
    {
        $context = [
            'personel_id' => $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
            'entity_type' => (string) $talep['entity_type'],
            'record_id' => (int) $talep['record_id'],
            'category' => (string) ($talep['category'] ?? ''),
        ];
        if (!empty($talep['canonical_sube_id'])) {
            $context['sube_id'] = (int) $talep['canonical_sube_id'];
        }
        if (!empty($talep['period_yil'])) {
            $context['yil'] = (int) $talep['period_yil'];
        }
        if (!empty($talep['period_ay'])) {
            $context['ay'] = (int) $talep['period_ay'];
        }
        if (!empty($talep['source_version_identity_snapshot'])) {
            $context['source_version_identity'] = (string) $talep['source_version_identity_snapshot'];
        }

        try {
            $resolved = RetentionTargetResolver::validateAndResolve(
                $pdo,
                (string) $talep['category'],
                (string) $talep['entity_type'],
                (int) $talep['record_id'],
                $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
                array_filter([
                    'sube_id' => $context['sube_id'] ?? null,
                    'yil' => $context['yil'] ?? null,
                    'ay' => $context['ay'] ?? null,
                ], static function ($v) {
                    return $v !== null && $v !== '';
                })
            );
            foreach ($resolved as $key => $value) {
                if ($value !== null && $value !== '') {
                    $context[$key] = $value;
                }
            }
        } catch (RuntimeException $e) {
            // After physical destroy, source entity may be absent.
        }

        try {
            $source = RetentionSourceAdapterService::resolve($pdo, (string) $talep['category'], $context);
            $context['source_version_identity'] = $source['source_version_identity'];
            if ($source['source_sha256'] !== null) {
                $context['current_sha256'] = $source['source_sha256'];
            }
        } catch (RuntimeException $e) {
            // After physical destroy, source may be absent — eligibility/plan paths handle codes.
            // Keep snapshot identity for plan hash when present.
            if (!empty($talep['source_sha256_snapshot'])) {
                $context['current_sha256'] = (string) $talep['source_sha256_snapshot'];
            }
        }

        return $context;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findExecutionByTalepId(PDO $pdo, $talepId, $forUpdate = false)
    {
        if (!self::tableExists($pdo, 'retention_imha_executionlari')) {
            return null;
        }
        $sql = 'SELECT * FROM retention_imha_executionlari WHERE imha_talep_id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $talepId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, int|float|string> $counts
     * @return array<string, int>
     */
    private static function normalizeCounts(array $counts)
    {
        ksort($counts);
        $out = [];
        foreach ($counts as $k => $v) {
            $out[(string) $k] = (int) $v;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function sanitizeSummary(array $summary)
    {
        $blocked = [
            'ad', 'soyad', 'tc', 'tc_kimlik_no', 'telefon', 'path', 'absolute_path',
            'storage_key', 'bytes', 'file_bytes', 'maas', 'adres', 'acil_durum_kisi',
            'acil_durum_telefon', 'orijinal_dosya_adi',
        ];
        $out = [];
        foreach ($summary as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, $blocked, true)) {
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::sanitizeSummary($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
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
