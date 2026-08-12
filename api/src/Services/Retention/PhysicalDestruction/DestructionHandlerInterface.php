<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

use PDO;

/**
 * Per-category physical destruction handler.
 * plan() is read-only / PII-free. execute() mutates only the canonical target scope.
 */
interface DestructionHandlerInterface
{
    /** @return string RetentionCategories::* */
    public function category();

    /**
     * @return string One of PhysicalDestructionCodes::MODE_*
     */
    public function executionMode();

    /**
     * Whether this handler may physically mutate (false → POLICY_DECISION_REQUIRED fail-closed).
     */
    public function isExecutable();

    /**
     * Deterministic PII-free plan fragment (db_operation_codes, expected counts, etc.).
     *
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function plan(PDO $pdo, array $talep, array $context);

    /**
     * Perform physical mutation inside an open transaction (caller owns commit/rollback).
     * Must not commit/rollback the outer transaction.
     *
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     * @param array<string, mixed> $plan
     * @return array{result_code: string, summary: array<string, mixed>}
     */
    public function execute(PDO $pdo, array $talep, array $context, array $plan);
}
