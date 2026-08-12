<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * Fail-closed handler when category strategy is not unambiguously determined by schema/business owners.
 */
final class PolicyRequiredDestructionHandler implements DestructionHandlerInterface
{
    /** @var string */
    private $category;

    /** @var string */
    private $reason;

    public function __construct($category, $reason)
    {
        $this->category = (string) $category;
        $this->reason = (string) $reason;
    }

    public function category()
    {
        return $this->category;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_POLICY_DECISION_REQUIRED;
    }

    public function isExecutable()
    {
        return false;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        return [
            'db_operation_codes' => ['POLICY_BLOCK'],
            'expected_row_counts' => [],
            'external_file_count' => 0,
            'policy_blocker' => $this->reason,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED);
    }
}
