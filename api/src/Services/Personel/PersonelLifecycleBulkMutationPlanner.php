<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Shared bulk lifecycle mutation planner — dry-run and apply must use the same path.
 */
final class PersonelLifecycleBulkMutationPlanner
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload Raw create payload from bulk row
     * @return array{payload: array<string, mixed>, incomplete: bool, canonical_ready: true}
     */
    public static function planCreatePayload(PDO $pdo, array $user, array $payload): array
    {
        if (PersonelIncompleteCreateService::hasIntent($payload)) {
            PersonelIncompleteCreateService::assertAuthorized($user);
            $canonical = PersonelIncompleteCreateService::normalizePayload($payload);
            $incomplete = true;
        } else {
            $canonical = PersonelCanonicalValidator::normalizeAndValidateCreatePayload($payload);
            $incomplete = false;
        }

        $canonical = PersonelOrgStructureSchema::inferMissingDepartmanForCreate($pdo, $canonical);
        PersonelCreateService::validateCreateReferences($pdo, $canonical);

        if (!empty($canonical['tc_kimlik_no']) && PersonelCreateService::tcExists($pdo, (string) $canonical['tc_kimlik_no'])) {
            throw new PersonelValidationException('tc_kimlik_no', 'Bu T.C. Kimlik No baska personelde kayitli.');
        }

        return [
            'payload' => $canonical,
            'incomplete' => $incomplete,
            'canonical_ready' => true,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, incomplete: bool, canonical_ready: true}
     */
    public static function planCreateMutation(PDO $pdo, array $user, array $payload): array
    {
        return self::planCreatePayload($pdo, $user, $payload);
    }

    public static function dryRunErrorCode(\Throwable $e): string
    {
        if ($e instanceof PersonelValidationException) {
            if ($e->getCodeString() === PersonelIncompleteCreateService::ERROR_FORBIDDEN) {
                return 'INCOMPLETE_CREATE_FORBIDDEN';
            }
            if ($e->getCodeString() === PersonelIncompleteCreateService::ERROR_INTENT_REQUIRED) {
                return 'INCOMPLETE_CREATE_INVALID';
            }

            return $e->getCodeString();
        }

        return 'VALIDATION_ERROR';
    }

    /**
     * @param array<string, mixed> $personel Current personel org row
     * @param array<string, mixed> $targets Partial org targets from bulk row
     * @return array<string, mixed>
     */
    public static function planOrgTargets(PDO $pdo, array $personel, array $targets): array
    {
        $merged = PersonelOrgStructureSchema::mergeEffectiveOrgState($personel, $targets);
        $withInference = PersonelOrgStructureSchema::inferMissingDepartmanForCreate($pdo, [
            'departman_id' => $merged['departman_id'],
            'bolum_id' => $merged['bolum_id'],
            'birim_id' => $merged['birim_id'],
            'pozisyon_id' => $merged['pozisyon_id'],
        ]);
        if (
            $merged['departman_id'] === null
            && $merged['bolum_id'] !== null
            && isset($withInference['departman_id'])
        ) {
            $targets['departman_id'] = $withInference['departman_id'];
        }

        $effective = PersonelOrgStructureSchema::mergeEffectiveOrgState($personel, $targets);
        PersonelOrgStructureSchema::assertHierarchyConsistent($pdo, $effective);

        return $targets;
    }

    /**
     * Canonicalize the basic axis during dry-run so apply never reinterprets
     * a multi-axis row. Reference checks intentionally stay in the existing
     * basic-update owner.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function planBasicPayload(PDO $pdo, int $personelId, array $payload): array
    {
        $validated = PersonelCanonicalValidator::normalizeAndValidateUpdatePayload($payload);
        $basic = [];
        foreach (PersonelBasicUpdateService::allowedColumns() as $field) {
            if (array_key_exists($field, $validated)) {
                $basic[$field] = $validated[$field];
            }
        }
        PersonelBasicUpdateService::assertPlanReferences($pdo, $basic, $personelId);

        return $basic;
    }

    /**
     * @return array{field:?string, code:string}|null
     */
    public static function dryRunValidationDetail(\Throwable $e): ?array
    {
        if (!$e instanceof PersonelValidationException) {
            return null;
        }

        return [
            'field' => $e->getField(),
            'code' => $e->getCodeString(),
        ];
    }
}
