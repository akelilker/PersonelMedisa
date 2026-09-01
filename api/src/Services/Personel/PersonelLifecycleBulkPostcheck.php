<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

/**
 * Count contract guard for MG personnel bulk reconciliation (139/135 baseline).
 */
final class PersonelLifecycleBulkPostcheck
{
    public const BASELINE_TOTAL = 139;
    public const BASELINE_ACTIVE = 135;
    public const BINDING_CREATE_COUNT = 14;
    public const BINDING_EXIT_COUNT = 3;
    public const EXPECTED_TOTAL_AFTER = 153;
    public const EXPECTED_ACTIVE_AFTER = 146;

    /** @param list<array<string, mixed>> $rows */
    public static function summarize(array $rows): array
    {
        $create = 0;
        $exit = 0;
        foreach ($rows as $row) {
            $op = PersonelLifecycleBulkRowContract::resolveOperationType($row);
            if ($op === PersonelLifecycleBulkRowContract::OP_CREATE) {
                $create++;
            } elseif ($op === PersonelLifecycleBulkRowContract::OP_EXIT) {
                $exit++;
            }
        }

        return [
            'baseline_total' => self::BASELINE_TOTAL,
            'baseline_active' => self::BASELINE_ACTIVE,
            'create_count' => $create,
            'exit_count' => $exit,
            'expected_total_after' => self::BASELINE_TOTAL + $create,
            'expected_active_after' => self::BASELINE_ACTIVE + $create - $exit,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public static function validateBindingContract(array $rows): array
    {
        $summary = self::summarize($rows);
        $errors = [];

        if ($summary['create_count'] !== self::BINDING_CREATE_COUNT) {
            $errors[] = 'POSTCHECK_CREATE_COUNT_MISMATCH';
        }
        if ($summary['exit_count'] !== self::BINDING_EXIT_COUNT) {
            $errors[] = 'POSTCHECK_EXIT_COUNT_MISMATCH';
        }
        if ($summary['expected_total_after'] !== self::EXPECTED_TOTAL_AFTER) {
            $errors[] = 'POSTCHECK_TOTAL_MISMATCH';
        }
        if ($summary['expected_active_after'] !== self::EXPECTED_ACTIVE_AFTER) {
            $errors[] = 'POSTCHECK_ACTIVE_MISMATCH';
        }

        return $errors;
    }
}
