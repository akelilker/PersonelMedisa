<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\OfflineMutationIdempotencyService;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use PDO;

/**
 * Production apply owner for bulk lifecycle rows — delegates to canonical owners per row.
 */
final class PersonelLifecycleBulkApplyService
{
    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function apply(
        PDO $pdo,
        array $user,
        Request $request,
        array $rows,
        string $dryRunChecksum,
        string $preimageChecksum,
        string $deployedSha,
        $activeSubeHeader = null
    ): array {
        $deployedSha = strtolower(trim($deployedSha));
        if (!preg_match('/^[a-f0-9]{40}$/', $deployedSha)) {
            throw new PersonelImportException('DEPLOYED_SHA_INVALID', 'deployed_sha zorunludur.', 422);
        }

        $dry = PersonelLifecycleBulkDryRunService::dryRun(
            $pdo,
            $user,
            $request,
            $rows,
            $activeSubeHeader,
            $deployedSha
        );

        if (($dry['dry_run_checksum'] ?? '') !== $dryRunChecksum) {
            throw new PersonelImportException(
                'DRY_RUN_STALE',
                'Dry-run checksum guncel degil; yeniden dry-run calistirin.'
            );
        }
        if (($dry['preimage_checksum'] ?? '') !== $preimageChecksum) {
            throw new PersonelImportException(
                'PREIMAGE_STALE',
                'Onizleme preimage checksum uyusmuyor; yeniden dry-run calistirin.'
            );
        }
        if (($dry['can_apply'] ?? false) !== true) {
            throw new PersonelImportException(
                'CANNOT_APPLY',
                'Dry-run BLOCKED satirlar veya postcheck hatalari iceriyor; apply reddedildi.'
            );
        }

        $auditContext = OrganizasyonAuditContext::fromRequest($request, $user);
        $actorId = (int) ($user['id'] ?? 0);
        $results = [];
        $applied = 0;
        $failed = 0;
        $refMap = [];
        $failedRefs = [];
        $ordered = self::orderRows($rows, $dry['satirlar']);

        foreach ($ordered as $entry) {
            $line = $entry['analysis'];
            $rawRow = $entry['raw'];
            if (($line['durum'] ?? '') !== 'READY') {
                continue;
            }

            $mutationId = (string) ($line['mutation_id'] ?? $rawRow['mutation_id'] ?? '');
            $dependsOn = trim((string) ($rawRow['depends_on_personel_ref'] ?? ''));
            if ($dependsOn !== '' && isset($failedRefs[$dependsOn])) {
                $failed++;
                $results[] = [
                    'mutation_id' => $mutationId,
                    'satir_no' => $line['satir_no'] ?? 0,
                    'durum' => 'FAILED',
                    'hata' => 'DEPENDENCY_BLOCKED',
                ];
                continue;
            }

            $plan = $line['mutation_plan'] ?? null;
            if (!is_array($plan)) {
                $failed++;
                $results[] = [
                    'mutation_id' => $mutationId,
                    'satir_no' => $line['satir_no'] ?? 0,
                    'durum' => 'FAILED',
                    'hata' => 'PLAN_YOK',
                ];
                continue;
            }

            $idemScope = 'personel-lifecycle-bulk:' . $mutationId;
            $idemHash = OfflineMutationIdempotencyService::hashPayload([
                'mutation_id' => $mutationId,
                'plan' => $plan,
                'deployed_sha' => $deployedSha,
            ]);

            $replay = OfflineMutationIdempotencyService::findCompletedReplay(
                $pdo,
                $actorId,
                $idemScope,
                $mutationId,
                $idemHash
            );
            if (is_array($replay)) {
                $applied++;
                $personelRef = trim((string) ($rawRow['personel_ref'] ?? ''));
                if ($personelRef !== '' && !empty($replay['result_entity_id'])) {
                    $refMap[$personelRef] = (int) $replay['result_entity_id'];
                }
                $results[] = [
                    'mutation_id' => $mutationId,
                    'satir_no' => $line['satir_no'] ?? 0,
                    'durum' => 'REPLAY',
                    'owner' => (string) ($plan['owner'] ?? ''),
                ];
                continue;
            }

            try {
                $applyResultHolder = ['entity_id' => null];
                $claimReplay = null;
                $claimFn = static function (PDO $inner) use (&$claimReplay, $actorId, $idemScope, $mutationId, $idemHash) {
                    $claimReplay = OfflineMutationIdempotencyService::claimInTransaction(
                        $inner,
                        $actorId,
                        $idemScope,
                        $mutationId,
                        $idemHash
                    );

                    return $claimReplay;
                };
                $completeFn = static function (PDO $inner) use ($actorId, $idemScope, $mutationId, &$applyResultHolder) {
                    OfflineMutationIdempotencyService::completeInTransaction(
                        $inner,
                        $actorId,
                        $idemScope,
                        $mutationId,
                        200,
                        'personel_lifecycle_bulk',
                        (int) ($applyResultHolder['entity_id'] ?? 0),
                        null
                    );
                };

                $applyResult = self::applyPlan(
                    $pdo,
                    $user,
                    $request,
                    $plan,
                    $auditContext,
                    $actorId,
                    $mutationId,
                    $claimFn,
                    $completeFn,
                    $applyResultHolder
                );

                $applied++;

                $personelRef = trim((string) ($rawRow['personel_ref'] ?? ''));
                if ($personelRef !== '' && !empty($applyResult['entity_id'])) {
                    $refMap[$personelRef] = (int) $applyResult['entity_id'];
                }

                $results[] = [
                    'mutation_id' => $mutationId,
                    'satir_no' => $line['satir_no'] ?? 0,
                    'durum' => is_array($claimReplay) ? 'REPLAY' : 'APPLIED',
                    'owner' => (string) ($plan['owner'] ?? ''),
                    'entity_id' => $applyResult['entity_id'] ?? null,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $personelRef = trim((string) ($rawRow['personel_ref'] ?? ''));
                if ($personelRef !== '') {
                    $failedRefs[$personelRef] = true;
                }
                $results[] = [
                    'mutation_id' => $mutationId,
                    'satir_no' => $line['satir_no'] ?? 0,
                    'durum' => 'FAILED',
                    'hata' => $e instanceof OrganizasyonException
                        ? $e->errorCode
                        : ($e instanceof PersonelValidationException || $e instanceof PersonelImportException
                            ? $e->getCodeString()
                            : 'APPLY_ERROR'),
                ];
            }
        }

        return [
            'applied_count' => $applied,
            'failed_count' => $failed,
            'satir_sonuclari' => $results,
            'personel_ref_map' => $refMap,
            'postcheck' => $dry['postcheck'] ?? null,
            'model' => 'INDEPENDENT_ROW_TRANSACTION',
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{entity_id:?int}
     */
    private static function applyPlan(
        PDO $pdo,
        array $user,
        Request $request,
        array $plan,
        OrganizasyonAuditContext $auditContext,
        int $actorId,
        string $mutationId,
        callable $claimFn,
        callable $completeFn,
        array &$applyResultHolder
    ): array {
        $owner = (string) ($plan['owner'] ?? '');

        if ($owner === 'PersonelLifecycleBulkApplyService' && ($plan['mode'] ?? '') === 'MULTI_AXIS') {
            $personelId = (int) ($plan['personel_id'] ?? 0);
            $axes = is_array($plan['axes'] ?? null) ? $plan['axes'] : [];
            if ($personelId <= 0 || count($axes) < 2) {
                throw new PersonelImportException('MULTI_AXIS_PLAN_INVALID', 'Multi-axis plan geçersiz.');
            }

            $pdo->beginTransaction();
            try {
                self::assertMultiAxisPreimage(
                    $pdo,
                    $personelId,
                    is_array($plan['preimage'] ?? null) ? $plan['preimage'] : []
                );
                $replay = $claimFn($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return ['entity_id' => (int) ($replay['result_entity_id'] ?? $personelId), 'replay' => true];
                }

                if (isset($axes['basic'])) {
                    $payload = is_array($axes['basic']['payload'] ?? null) ? $axes['basic']['payload'] : [];
                    PersonelBasicUpdateService::apply($pdo, $user, $request, $personelId, $payload);
                }
                if (isset($axes['organization'])) {
                    PersonelOrganizasyonDegisikligiService::applyInTransaction(
                        $pdo,
                        $user,
                        $request,
                        $personelId,
                        [
                            'gerekce' => (string) ($plan['gerekce'] ?? ''),
                            'preimage' => is_array($axes['organization']['preimage'] ?? null) ? $axes['organization']['preimage'] : [],
                            'targets' => is_array($axes['organization']['targets'] ?? null) ? $axes['organization']['targets'] : [],
                        ],
                        $auditContext
                    );
                }
                if (isset($axes['branch'])) {
                    PersonelKaliciSubeDegisikligiService::applyInTransaction(
                        $pdo,
                        $user,
                        $personelId,
                        [
                            'yeni_sube_id' => $axes['branch']['yeni_sube_id'] ?? null,
                            'beklenen_mevcut_sube_id' => $axes['branch']['preimage_sube_id'] ?? null,
                            'gerekce' => (string) ($plan['gerekce'] ?? ''),
                        ],
                        $auditContext
                    );
                }
                $applyResultHolder['entity_id'] = $personelId;
                $completeFn($pdo);
                $pdo->commit();

                return ['entity_id' => $personelId];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($owner === 'PersonelCreateService') {
            $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
            if (($plan['canonical_ready'] ?? false) === true) {
                $canonical = $payload;
            } else {
                $planned = PersonelLifecycleBulkMutationPlanner::planCreatePayload($pdo, $user, $payload);
                $canonical = $planned['payload'];
            }
            if (!empty($canonical['tc_kimlik_no']) && PersonelCreateService::tcExists($pdo, (string) $canonical['tc_kimlik_no'])) {
                throw new PersonelValidationException('tc_kimlik_no', 'Bu T.C. Kimlik No baska personelde kayitli.');
            }

            $pdo->beginTransaction();
            try {
                $replay = $claimFn($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return [
                        'entity_id' => (int) ($replay['result_entity_id'] ?? 0),
                        'replay' => true,
                    ];
                }
                $insertId = PersonelCreateService::insertPersonel($pdo, $canonical);
                $applyResultHolder['entity_id'] = $insertId;
                $completeFn($pdo);
                $pdo->commit();

                return ['entity_id' => $insertId];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($owner === 'PersonelBasicUpdateService') {
            $personelId = (int) ($plan['personel_id'] ?? 0);
            $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
            $validated = PersonelCanonicalValidator::normalizeAndValidateUpdatePayload($payload);

            $pdo->beginTransaction();
            try {
                $replay = $claimFn($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return [
                        'entity_id' => (int) ($replay['result_entity_id'] ?? $personelId),
                        'replay' => true,
                    ];
                }
                PersonelBasicUpdateService::apply($pdo, $user, $request, $personelId, $validated);
                $applyResultHolder['entity_id'] = $personelId;
                $completeFn($pdo);
                $pdo->commit();

                return ['entity_id' => $personelId];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($owner === 'PersonelOrganizasyonDegisikligiService') {
            $personelId = (int) ($plan['personel_id'] ?? 0);
            $body = [
                'gerekce' => (string) ($plan['gerekce'] ?? ''),
                'preimage' => is_array($plan['preimage'] ?? null) ? $plan['preimage'] : [],
                'targets' => is_array($plan['targets'] ?? null) ? $plan['targets'] : [],
            ];

            $applyResultHolder['entity_id'] = $personelId;
            $result = PersonelOrganizasyonDegisikligiService::apply(
                $pdo,
                $user,
                $request,
                $personelId,
                $body,
                $auditContext,
                $claimFn,
                $completeFn
            );

            return [
                'entity_id' => (int) ($result['personel_id'] ?? $personelId),
                'replay' => !empty($result['replay']),
            ];
        }

        if ($owner === 'PersonelKaliciSubeDegisikligiService') {
            $personelId = (int) ($plan['personel_id'] ?? 0);
            $yeniSubeId = (int) ($plan['yeni_sube_id'] ?? 0);
            if ($yeniSubeId <= 0 && trim((string) ($plan['yeni_sube'] ?? '')) !== '') {
                $yeniSubeId = (int) (PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName(
                    $pdo,
                    'subeler',
                    (string) $plan['yeni_sube']
                ) ?? 0);
            }
            if ($yeniSubeId <= 0) {
                throw new PersonelValidationException('yeni_sube_id', 'Gecersiz hedef sube.');
            }

            $preimageSubeId = array_key_exists('preimage_sube_id', $plan)
                ? $plan['preimage_sube_id']
                : ($plan['preimage']['sube_id'] ?? null);
            $body = [
                'yeni_sube_id' => $yeniSubeId,
                'gerekce' => (string) ($plan['gerekce'] ?? ''),
                'beklenen_mevcut_sube_id' => $preimageSubeId,
            ];

            $applyResultHolder['entity_id'] = $personelId;
            $result = PersonelKaliciSubeDegisikligiService::apply(
                $pdo,
                $user,
                $personelId,
                $body,
                $auditContext,
                $claimFn,
                $completeFn
            );

            return [
                'entity_id' => (int) ($result['personel_id'] ?? $personelId),
                'replay' => !empty($result['replay']),
            ];
        }

        if ($owner === 'PersonelIstenAyrilmaService') {
            $personelId = (int) ($plan['personel_id'] ?? 0);
            $exitDate = (string) ($plan['exit_date'] ?? '');

            $pdo->beginTransaction();
            try {
                $replay = $claimFn($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return [
                        'entity_id' => (int) ($replay['result_entity_id'] ?? 0),
                        'replay' => true,
                    ];
                }
                $result = PersonelIstenAyrilmaService::applyInTransaction(
                    $pdo,
                    $personelId,
                    $exitDate,
                    (string) ($plan['aciklama'] ?? ''),
                    $actorId
                );
                $applyResultHolder['entity_id'] = (int) $result['surec_id'];
                $completeFn($pdo);
                $pdo->commit();

                return ['entity_id' => (int) $result['surec_id']];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($owner === 'PersonelHistoricalExitDateBackfillService') {
            $personelId = (int) ($plan['personel_id'] ?? 0);
            $exitDate = (string) ($plan['exit_date'] ?? '');
            $aciklama = (string) ($plan['aciklama'] ?? '');

            $pdo->beginTransaction();
            try {
                $replay = $claimFn($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return [
                        'entity_id' => (int) ($replay['result_entity_id'] ?? 0),
                        'replay' => true,
                    ];
                }
                $result = PersonelHistoricalExitDateBackfillService::applyInTransaction(
                    $pdo,
                    $personelId,
                    $exitDate,
                    $aciklama !== '' ? $aciklama : null,
                    $actorId
                );
                $entityId = (int) ($result['surec_id'] ?? $personelId);
                $applyResultHolder['entity_id'] = $entityId;
                $completeFn($pdo);
                $pdo->commit();

                return [
                    'entity_id' => $entityId,
                    'already_applied' => !empty($result['already_applied']),
                    'action' => (string) ($result['action'] ?? ''),
                ];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($owner === 'PersonelHistoricalExitDateCorrectionService') {
            $personelId = (int) ($plan['personel_id'] ?? 0);
            $surecId = (int) ($plan['surec_id'] ?? 0);
            $expectedOld = (string) ($plan['old_exit_date'] ?? '');
            $exitDate = (string) ($plan['exit_date'] ?? $plan['new_exit_date'] ?? '');
            $aciklama = (string) ($plan['aciklama'] ?? '');

            $pdo->beginTransaction();
            try {
                $replay = $claimFn($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return [
                        'entity_id' => (int) ($replay['result_entity_id'] ?? 0),
                        'replay' => true,
                    ];
                }
                $result = PersonelHistoricalExitDateCorrectionService::applyInTransaction(
                    $pdo,
                    $personelId,
                    $surecId,
                    $expectedOld,
                    $exitDate,
                    $aciklama !== '' ? $aciklama : null,
                    $actorId
                );
                $entityId = (int) ($result['surec_id'] ?? $surecId);
                $applyResultHolder['entity_id'] = $entityId;
                $completeFn($pdo);
                $pdo->commit();

                return [
                    'entity_id' => $entityId,
                    'already_applied' => !empty($result['already_applied']),
                    'action' => (string) ($result['action'] ?? ''),
                    'old_exit_date' => (string) ($result['old_exit_date'] ?? ''),
                    'new_exit_date' => (string) ($result['new_exit_date'] ?? ''),
                    'audit' => is_array($result['audit'] ?? null) ? $result['audit'] : null,
                ];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($owner === 'PersonelLifecycleBulkReferenceResolver') {
            $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
            $table = (string) ($payload['table'] ?? 'gorevler');

            $pdo->beginTransaction();
            try {
                $replay = $claimFn($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return [
                        'entity_id' => (int) ($replay['result_entity_id'] ?? 0),
                        'replay' => true,
                    ];
                }
                $resolved = PersonelLifecycleBulkReferenceResolver::resolveOrCreateCatalog($pdo, $table, $payload);
                $applyResultHolder['entity_id'] = (int) $resolved['id'];
                $completeFn($pdo);
                $pdo->commit();

                return ['entity_id' => (int) $resolved['id']];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        throw new OrganizasyonException(501, 'NOT_IMPLEMENTED_ROW', 'Plan owner uygulanamadi.', $owner);
    }

    /** @param array<string, mixed> $expected */
    private static function assertMultiAxisPreimage(PDO $pdo, int $personelId, array $expected): void
    {
        $columns = array_values(array_unique(array_merge(
            ['id', 'sube_id', 'bagli_amir_id', 'calisan_kapsami'],
            PersonelOrganizasyonDegisikligiService::TRACKED_FIELDS
        )));
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $columns) . ' FROM personeller WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $personelId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            throw new PersonelValidationException('personel_id', 'Personel bulunamadı.', 'PERSONEL_BULUNAMADI');
        }

        foreach ($expected as $field => $before) {
            if (!in_array($field, $columns, true)) {
                continue;
            }
            $actual = $current[$field] ?? null;
            if ($field === 'calisan_kapsami') {
                $same = strtoupper(trim((string) $actual)) === strtoupper(trim((string) $before));
            } else {
                $same = self::nullableId($actual) === self::nullableId($before);
            }
            if (!$same) {
                throw new PersonelImportException(
                    'PERSONEL_LIFECYCLE_STALE_PREIMAGE',
                    'Personel önizlemesi güncel değil; yeniden dry-run çalıştırın.'
                );
            }
        }
    }

    /** @param mixed $value */
    private static function nullableId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $analysisRows
     * @return list<array{raw:array<string,mixed>,analysis:array<string,mixed>}>
     */
    private static function orderRows(array $rows, array $analysisRows): array
    {
        $priority = [
            PersonelLifecycleBulkRowContract::OP_REFERENCE => 10,
            PersonelLifecycleBulkRowContract::OP_CREATE => 20,
            PersonelLifecycleBulkRowContract::OP_BASIC_UPDATE => 30,
            PersonelLifecycleBulkRowContract::OP_ORG_UPDATE => 40,
            PersonelLifecycleBulkRowContract::OP_BRANCH => 50,
            PersonelLifecycleBulkRowContract::OP_EXIT => 60,
            PersonelLifecycleBulkRowContract::OP_HISTORICAL_EXIT_DATE_BACKFILL => 65,
            PersonelLifecycleBulkRowContract::OP_HISTORICAL_EXIT_DATE_CORRECTION => 66,
        ];

        $combined = [];
        foreach ($analysisRows as $index => $analysis) {
            $combined[] = [
                'raw' => PersonelLifecycleBulkRowContract::normalizeRow($rows[$index] ?? []),
                'analysis' => $analysis,
            ];
        }

        usort($combined, static function (array $a, array $b) use ($priority): int {
            $opA = PersonelLifecycleBulkRowContract::mapLegacyToCanonical(
                PersonelLifecycleBulkRowContract::resolveOperationType($a['raw'])
            );
            $opB = PersonelLifecycleBulkRowContract::mapLegacyToCanonical(
                PersonelLifecycleBulkRowContract::resolveOperationType($b['raw'])
            );
            $pA = $priority[$opA] ?? 100;
            $pB = $priority[$opB] ?? 100;
            if ($pA === $pB) {
                return ((int) ($a['analysis']['satir_no'] ?? 0)) <=> ((int) ($b['analysis']['satir_no'] ?? 0));
            }

            return $pA <=> $pB;
        });

        return $combined;
    }
}
