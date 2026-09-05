<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Scope\SubeScope;
use PDO;

/**
 * SELECT-only bulk lifecycle dry-run owner (extends export change sheet contract).
 */
final class PersonelLifecycleBulkDryRunService
{
    public const SCHEMA_VERSION = 'personel-lifecycle-bulk-v2';
    public const MAX_ROWS = 500;

    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function dryRun(
        PDO $pdo,
        array $user,
        Request $request,
        array $rows,
        $activeSubeHeader = null,
        ?string $deployedSha = null
    ): array {
        if (count($rows) > self::MAX_ROWS) {
            throw new PersonelImportException('ROW_LIMIT', 'En fazla ' . self::MAX_ROWS . ' satir analiz edilebilir.');
        }

        $scope = $activeSubeHeader ?? SubeScope::resolveScope($user, $request);
        $seenKeys = [];
        $seenMutationIds = [];
        $satirlar = [];
        $ready = 0;
        $blocked = 0;
        $noChange = 0;

        foreach ($rows as $index => $rawRow) {
            $rowNum = $index + 1;
            $row = PersonelLifecycleBulkRowContract::normalizeRow($rawRow);
            $analysis = self::analyzeRow($pdo, $user, $request, $scope, $row, $seenKeys, $seenMutationIds);
            $analysis['satir_no'] = $rowNum;
            $analysis['mutation_id'] = $row['mutation_id'];
            $satirlar[] = $analysis;
            if (($analysis['durum'] ?? '') === 'READY') {
                $ready++;
            } elseif (($analysis['durum'] ?? '') === 'NO_CHANGE') {
                $noChange++;
            } else {
                $blocked++;
            }
        }

        $inventory = PersonelLifecycleBulkPostcheck::captureInventory($pdo);
        $postcheck = PersonelLifecycleBulkPostcheck::summarize($inventory, $satirlar);
        $postcheckErrors = PersonelLifecycleBulkPostcheck::validateContract($inventory, $satirlar);

        $preimageChecksum = PersonelLifecycleBulkPostcheck::checksumPreimage(
            (string) $inventory['inventory_fingerprint'],
            $satirlar
        );
        $sourceChecksum = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'dry_run_checksum' => $preimageChecksum,
            'source_checksum' => $sourceChecksum,
            'preimage_checksum' => $preimageChecksum,
            'deployed_sha' => $deployedSha,
            'can_apply' => $blocked === 0 && $ready > 0 && count($postcheckErrors) === 0,
            'ozet' => [
                'toplam_satir' => count($rows),
                'ready' => $ready,
                'blocked' => $blocked,
                'no_change' => $noChange,
            ],
            'postcheck' => $postcheck,
            'postcheck_errors' => $postcheckErrors,
            'satirlar' => $satirlar,
            'active_sube_id' => $scope,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     * @param array<string, true> $seenKeys
     * @param array<string, true> $seenMutationIds
     * @return array<string, mixed>
     */
    private static function analyzeRow(
        PDO $pdo,
        array $user,
        Request $request,
        $scope,
        array $row,
        array &$seenKeys,
        array &$seenMutationIds
    ): array {
        $errors = [];
        $opRaw = PersonelLifecycleBulkRowContract::resolveOperationType($row);
        $op = PersonelLifecycleBulkRowContract::mapLegacyToCanonical($opRaw);

        if ($opRaw === '') {
            $errors[] = 'OPERATION_TYPE_EKSIK';
        } elseif (!in_array($opRaw, PersonelLifecycleBulkRowContract::OPERATION_TYPES, true)) {
            $errors[] = 'OPERATION_TYPE_GECERSIZ';
        }

        $mutationId = (string) ($row['mutation_id'] ?? '');
        if ($mutationId === '') {
            $errors[] = 'MUTATION_ID_EKSIK';
        } elseif (isset($seenMutationIds[$mutationId])) {
            $errors[] = 'DUPLICATE_MUTATION_ID';
        } else {
            $seenMutationIds[$mutationId] = true;
        }

        $personelId = (int) ($row['personel_id'] ?? ($row['payload']['personel_id'] ?? 0));
        $sicil = trim((string) ($row['sicil_no'] ?? ''));
        $matchKey = $personelId > 0 ? 'id:' . $personelId : ($sicil !== '' ? 'sicil:' . $sicil : '');
        if ($matchKey !== '' && $op !== PersonelLifecycleBulkRowContract::OP_CREATE) {
            if (isset($seenKeys[$matchKey])) {
                $errors[] = 'DUPLICATE_SATIR';
            }
            $seenKeys[$matchKey] = true;
        }

        $personel = null;
        if (!in_array($op, [PersonelLifecycleBulkRowContract::OP_CREATE, PersonelLifecycleBulkRowContract::OP_REFERENCE], true)
            && $opRaw !== 'SADECE_NOT'
        ) {
            if ($personelId <= 0 && $sicil === '') {
                $errors[] = 'PERSONEL_ESLESME_EKSIK';
            } else {
                $personel = self::matchPersonel($pdo, $personelId, $sicil);
                if ($personel === null) {
                    $errors[] = 'PERSONEL_BULUNAMADI';
                } elseif ($personelId > 0 && $sicil !== '' && (string) $personel['sicil_no'] !== $sicil) {
                    $errors[] = 'PERSONEL_ID_SICIL_CELISKI';
                }
            }
        }

        if ($personel !== null) {
            try {
                OrgScope::assertPersonelAccess($user, $request, $personel, $pdo);
            } catch (\Throwable $e) {
                $errors[] = 'SCOPE_IHLALI';
            }
        }

        if ($opRaw === 'SADECE_NOT' || $opRaw === 'DEGISIKLIK_YOK') {
            return self::resultRow($opRaw, count($errors) === 0 ? 'NO_CHANGE' : 'BLOCKED', $errors, $personel, null);
        }

        if ($op === PersonelLifecycleBulkRowContract::OP_CREATE) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $iseGiris = trim((string) ($payload['ise_giris_tarihi'] ?? $row['ise_giris_tarihi'] ?? ''));
            if ($iseGiris === '') {
                $errors[] = 'ISE_GIRIS_TARIHI_EKSIK';
            }

            if (count($errors) === 0) {
                try {
                    $planned = PersonelLifecycleBulkMutationPlanner::planCreatePayload($pdo, $user, $payload);

                    return self::resultRow($op, 'READY', $errors, null, [
                        'owner' => 'PersonelCreateService',
                        'operation_type' => $op,
                        'incomplete' => $planned['incomplete'],
                        'payload' => $planned['payload'],
                        'personel_ref' => (string) ($row['personel_ref'] ?? ''),
                        'canonical_ready' => true,
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = PersonelLifecycleBulkMutationPlanner::dryRunErrorCode($e);
                    $detail = PersonelLifecycleBulkMutationPlanner::dryRunValidationDetail($e);

                    return self::resultRow($op, 'BLOCKED', $errors, null, null, $detail);
                }
            }

            return self::resultRow($op, 'BLOCKED', $errors, null, null);
        }

        if ($op === PersonelLifecycleBulkRowContract::OP_REFERENCE) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            if (trim((string) ($payload['ad'] ?? '')) === '') {
                $errors[] = 'REFERANS_AD_EKSIK';
            }

            return self::resultRow($op, count($errors) === 0 ? 'READY' : 'BLOCKED', $errors, null, [
                'owner' => 'PersonelLifecycleBulkReferenceResolver',
                'operation_type' => $op,
                'payload' => $payload,
            ]);
        }

        if ($personel === null) {
            return self::resultRow($op, 'BLOCKED', $errors, null, null);
        }

        if ($op === PersonelLifecycleBulkRowContract::OP_EXIT
            && strtoupper(trim((string) ($personel['aktif_durum'] ?? ''))) !== 'AKTIF'
        ) {
            $errors[] = 'EXIT_PREIMAGE_NOT_AKTIF';
        }

        if ($op === PersonelLifecycleBulkRowContract::OP_HISTORICAL_EXIT_DATE_BACKFILL
            && strtoupper(trim((string) ($personel['aktif_durum'] ?? ''))) !== 'PASIF'
        ) {
            $errors[] = PersonelHistoricalExitDateBackfillService::ERROR_NOT_PASIF;
        }

        $gerekce = trim((string) ($row['gerekce'] ?? ($row['payload']['gerekce'] ?? '')));
        if ($gerekce === '' && !in_array($op, [
            PersonelLifecycleBulkRowContract::OP_EXIT,
            PersonelLifecycleBulkRowContract::OP_HISTORICAL_EXIT_DATE_BACKFILL,
        ], true)) {
            $errors[] = 'GEREKCE_EKSIK';
        }

        try {
            $plan = self::buildMutationPlan($pdo, $op, $personel, $row);
        } catch (PersonelValidationException $e) {
            $errors[] = PersonelLifecycleBulkMutationPlanner::dryRunErrorCode($e);

            return self::resultRow(
                $op,
                'BLOCKED',
                $errors,
                $personel,
                null,
                PersonelLifecycleBulkMutationPlanner::dryRunValidationDetail($e)
            );
        }
        if ($plan === null) {
            $errors[] = 'PLAN_URETILEMEDI';
        } elseif ($plan['no_change'] ?? false) {
            return self::resultRow($op, 'NO_CHANGE', $errors, $personel, $plan);
        } elseif ($op === PersonelLifecycleBulkRowContract::OP_ORG_UPDATE && $personel !== null) {
            try {
                if (($plan['owner'] ?? '') === 'PersonelLifecycleBulkApplyService') {
                    $targets = is_array($plan['axes']['organization']['targets'] ?? null)
                        ? $plan['axes']['organization']['targets']
                        : [];
                    $plan['axes']['organization']['targets'] = PersonelLifecycleBulkMutationPlanner::planOrgTargets(
                        $pdo,
                        $personel,
                        $targets
                    );
                } else {
                    $targets = is_array($plan['targets'] ?? null) ? $plan['targets'] : [];
                    $plan['targets'] = PersonelLifecycleBulkMutationPlanner::planOrgTargets($pdo, $personel, $targets);
                }
                $plan['canonical_ready'] = true;
            } catch (PersonelValidationException $e) {
                $errors[] = PersonelLifecycleBulkMutationPlanner::dryRunErrorCode($e);
                $detail = PersonelLifecycleBulkMutationPlanner::dryRunValidationDetail($e);

                return self::resultRow($op, 'BLOCKED', $errors, $personel, null, $detail);
            }
        }

        return self::resultRow($op, count($errors) === 0 ? 'READY' : 'BLOCKED', $errors, $personel, $plan);
    }

    /**
     * @param array<string, mixed>|null $personel
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private static function resultRow(
        string $tip,
        string $durum,
        array $errors,
        ?array $personel,
        ?array $plan,
        ?array $validationDetail = null
    ): array {
        $row = [
            'islem_tipi' => $tip,
            'durum' => $durum,
            'hata_kodlari' => $errors,
            'personel_id' => $personel !== null ? (int) $personel['id'] : null,
            'sicil_no' => $personel !== null ? (string) ($personel['sicil_no'] ?? '') : null,
            'mutation_plan' => $plan,
        ];
        if ($validationDetail !== null) {
            $row['validation_field'] = $validationDetail['field'] ?? null;
            $row['validation_code'] = $validationDetail['code'] ?? null;
        }
        if ($personel !== null) {
            $row['preimage_aktif_durum'] = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private static function matchPersonel(PDO $pdo, int $personelId, string $sicil): ?array
    {
        if ($personelId > 0) {
            $stmt = $pdo->prepare(
            'SELECT id, sicil_no, sube_id, bolum_id, birim_id, aktif_durum, gorev_id, departman_id,
                        sgk_isveren_id, calisma_lokasyonu_id, pozisyon_id, bagli_amir_id, calisan_kapsami
                 FROM personeller WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        }
        if ($sicil !== '') {
            $stmt = $pdo->prepare(
            'SELECT id, sicil_no, sube_id, bolum_id, birim_id, aktif_durum, gorev_id, departman_id,
                        sgk_isveren_id, calisma_lokasyonu_id, pozisyon_id, bagli_amir_id, calisan_kapsami
                 FROM personeller WHERE sicil_no = :sicil LIMIT 1'
            );
            $stmt->execute(['sicil' => $sicil]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $personel
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function buildMutationPlan(PDO $pdo, string $op, array $personel, array $row): ?array
    {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $personelId = (int) $personel['id'];
        $preimage = [];
        foreach (array_merge(['sube_id', 'bagli_amir_id', 'calisan_kapsami'], PersonelOrganizasyonDegisikligiService::TRACKED_FIELDS) as $field) {
            $preimage[$field] = $personel[$field] ?? null;
        }

        switch ($op) {
            case PersonelLifecycleBulkRowContract::OP_EXIT:
                return [
                    'owner' => 'PersonelIstenAyrilmaService',
                    'operation_type' => $op,
                    'personel_id' => $personelId,
                    'exit_date' => trim((string) ($payload['exit_date'] ?? $row['isten_cikis_tarihi'] ?? '')),
                    'aciklama' => trim((string) ($payload['aciklama'] ?? $row['gerekce'] ?? '')),
                ];
            case PersonelLifecycleBulkRowContract::OP_HISTORICAL_EXIT_DATE_BACKFILL:
                $exitDate = trim((string) ($payload['exit_date'] ?? $row['isten_cikis_tarihi'] ?? ''));
                $aciklama = trim((string) ($payload['aciklama'] ?? $row['gerekce'] ?? ''));
                $planned = PersonelHistoricalExitDateBackfillService::plan(
                    $pdo,
                    $personelId,
                    $exitDate,
                    $aciklama !== '' ? $aciklama : null
                );

                return [
                    'owner' => 'PersonelHistoricalExitDateBackfillService',
                    'operation_type' => $op,
                    'personel_id' => $personelId,
                    'exit_date' => $planned['exit_date'],
                    'aciklama' => $planned['aciklama'],
                    'action' => $planned['action'],
                    'no_change' => $planned['no_change'],
                    'preimage' => $planned['preimage'],
                    'postimage' => $planned['postimage'],
                ];
            case PersonelLifecycleBulkRowContract::OP_BASIC_UPDATE:
                return [
                    'owner' => 'PersonelBasicUpdateService',
                    'operation_type' => $op,
                    'personel_id' => $personelId,
                    'payload' => $payload,
                ];
            case PersonelLifecycleBulkRowContract::OP_BRANCH:
                return [
                    'owner' => 'PersonelKaliciSubeDegisikligiService',
                    'operation_type' => $op,
                    'personel_id' => $personelId,
                    'yeni_sube_id' => (int) ($payload['yeni_sube_id'] ?? 0),
                    'yeni_sube' => trim((string) ($payload['yeni_sube'] ?? $row['yeni_sube'] ?? '')),
                    'gerekce' => trim((string) ($payload['gerekce'] ?? $row['gerekce'] ?? '')),
                    'preimage_sube_id' => (int) ($personel['sube_id'] ?? 0),
                ];
            case PersonelLifecycleBulkRowContract::OP_ORG_UPDATE:
                $targets = [];
                foreach (PersonelOrganizasyonDegisikligiService::TRACKED_FIELDS as $field) {
                    if (array_key_exists($field, $payload)) {
                        $targets[$field] = $payload[$field];
                    }
                }
                if (trim((string) ($row['yeni_gorev_unvan'] ?? '')) !== '') {
                    $targets['gorev_id'] = self::resolveReferenceOrFail(
                        $pdo,
                        'gorevler',
                        (string) $row['yeni_gorev_unvan'],
                        'gorev_id'
                    );
                }
                if (trim((string) ($row['yeni_departman'] ?? '')) !== '') {
                    $targets['departman_id'] = self::resolveReferenceOrFail(
                        $pdo,
                        'departmanlar',
                        (string) $row['yeni_departman'],
                        'departman_id'
                    );
                }
                if (trim((string) ($row['yeni_bolum'] ?? '')) !== '') {
                    $targets['bolum_id'] = self::resolveReferenceOrFail(
                        $pdo,
                        'bolumler',
                        (string) $row['yeni_bolum'],
                        'bolum_id'
                    );
                }
                if (trim((string) ($row['yeni_birim'] ?? '')) !== '') {
                    $targets['birim_id'] = self::resolveReferenceOrFail(
                        $pdo,
                        'birimler',
                        (string) $row['yeni_birim'],
                        'birim_id'
                    );
                }
                if (trim((string) ($row['yeni_pozisyon'] ?? '')) !== '') {
                    $targets['pozisyon_id'] = self::resolveReferenceOrFail(
                        $pdo,
                        'pozisyonlar',
                        (string) $row['yeni_pozisyon'],
                        'pozisyon_id'
                    );
                }

                $basicRaw = [];
                foreach (PersonelBasicUpdateService::allowedColumns() as $field) {
                    if (array_key_exists($field, $payload)) {
                        $basicRaw[$field] = $payload[$field];
                    }
                }
                $managerName = trim((string) (
                    $payload['bagli_amir']
                    ?? $payload['bagli_amir_adi']
                    ?? $row['yeni_bagli_amir']
                    ?? $row['bagli_amir']
                    ?? ''
                ));
                if ($managerName !== '') {
                    $resolvedManagerId = self::resolveManagerOrFail($pdo, $managerName);
                    if (array_key_exists('bagli_amir_id', $basicRaw)
                        && (int) $basicRaw['bagli_amir_id'] !== $resolvedManagerId
                    ) {
                        throw new PersonelValidationException(
                            'bagli_amir_id',
                            'Bağlı amir kimliği ile adı çelişiyor.',
                            'MANAGER_REFERENCE_CONFLICT'
                        );
                    }
                    $basicRaw['bagli_amir_id'] = $resolvedManagerId;
                }
                $basicPayload = PersonelLifecycleBulkMutationPlanner::planBasicPayload($pdo, $personelId, $basicRaw);
                if (($basicPayload['calisan_kapsami'] ?? null) === PersonelCalisanKapsamService::DIS_KAYNAK
                    && ($personel['sgk_isveren_id'] ?? null) !== null
                    && !array_key_exists('sgk_isveren_id', $targets)
                ) {
                    $targets['sgk_isveren_id'] = null;
                }
                $branchId = array_key_exists('yeni_sube_id', $payload) ? (int) $payload['yeni_sube_id'] : 0;
                if ($branchId <= 0 && trim((string) ($row['yeni_sube'] ?? '')) !== '') {
                    $branchId = self::resolveReferenceOrFail($pdo, 'subeler', (string) $row['yeni_sube'], 'yeni_sube_id');
                }
                $axes = [];
                if (count($basicPayload) > 0) {
                    $axes['basic'] = ['payload' => $basicPayload];
                }
                if (count($targets) > 0) {
                    $axes['organization'] = ['preimage' => $preimage, 'targets' => $targets];
                }
                if ($branchId > 0) {
                    $axes['branch'] = ['preimage_sube_id' => $personel['sube_id'] ?? null, 'yeni_sube_id' => $branchId];
                }
                if (count($axes) > 1) {
                    return [
                        'owner' => 'PersonelLifecycleBulkApplyService',
                        'mode' => 'MULTI_AXIS',
                        'operation_type' => $op,
                        'personel_id' => $personelId,
                        'preimage' => $preimage,
                        'axes' => $axes,
                        'gerekce' => trim((string) ($payload['gerekce'] ?? $row['gerekce'] ?? '')),
                    ];
                }
                if (count($axes) === 0) {
                    return ['no_change' => true, 'operation_type' => $op, 'personel_id' => $personelId];
                }
                if (isset($axes['basic'])) {
                    return ['owner' => 'PersonelBasicUpdateService', 'operation_type' => $op, 'personel_id' => $personelId, 'payload' => $axes['basic']['payload']];
                }
                if (isset($axes['branch'])) {
                    return ['owner' => 'PersonelKaliciSubeDegisikligiService', 'operation_type' => $op, 'personel_id' => $personelId, 'yeni_sube_id' => $branchId, 'gerekce' => trim((string) ($payload['gerekce'] ?? $row['gerekce'] ?? '')), 'preimage_sube_id' => $personel['sube_id'] ?? null];
                }

                return [
                    'owner' => 'PersonelOrganizasyonDegisikligiService',
                    'operation_type' => $op,
                    'personel_id' => $personelId,
                    'preimage' => $preimage,
                    'targets' => $targets,
                    'gerekce' => trim((string) ($payload['gerekce'] ?? $row['gerekce'] ?? '')),
                ];
            default:
                return null;
        }
    }

    private static function resolveReferenceOrFail(PDO $pdo, string $table, string $name, string $field): int
    {
        $id = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName($pdo, $table, $name);
        if ($id === null) {
            throw new PersonelValidationException($field, 'Aktif ve tekil referans bulunamadı.', 'REFERENCE_NOT_RESOLVED');
        }

        return (int) $id;
    }

    private static function resolveManagerOrFail(PDO $pdo, string $name): int
    {
        $ids = PersonelLifecycleBulkReferenceResolver::resolveBagliAmirUserIds($pdo, $name);
        if (count($ids) === 0) {
            throw new PersonelValidationException(
                'bagli_amir',
                'Aktif ve tekil bağlı amir bulunamadı.',
                'MISSING_MANAGER_PERSONNEL_REFERENCE'
            );
        }
        if (count($ids) !== 1) {
            throw new PersonelValidationException(
                'bagli_amir',
                'Bağlı amir adı birden fazla aktif kullanıcıyla eşleşiyor.',
                'AMBIGUOUS_MANAGER_PERSONNEL_REFERENCE'
            );
        }

        return (int) $ids[0];
    }
}
