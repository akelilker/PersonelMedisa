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

        $normalizedRows = array_map(static function (array $raw): array {
            return PersonelLifecycleBulkRowContract::normalizeRow($raw);
        }, $rows);
        $postcheck = PersonelLifecycleBulkPostcheck::summarize($normalizedRows);
        $hasLifecycleMutations = ($postcheck['create_count'] ?? 0) > 0 || ($postcheck['exit_count'] ?? 0) > 0;
        $postcheckErrors = $hasLifecycleMutations
            ? PersonelLifecycleBulkPostcheck::validateBindingContract($normalizedRows)
            : [];

        $preimageChecksum = hash('sha256', json_encode($satirlar, JSON_UNESCAPED_UNICODE));
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

        $gerekce = trim((string) ($row['gerekce'] ?? ($row['payload']['gerekce'] ?? '')));
        if ($gerekce === '' && !in_array($op, [PersonelLifecycleBulkRowContract::OP_EXIT], true)) {
            $errors[] = 'GEREKCE_EKSIK';
        }

        $plan = self::buildMutationPlan($pdo, $op, $personel, $row);
        if ($plan === null) {
            $errors[] = 'PLAN_URETILEMEDI';
        } elseif ($plan['no_change'] ?? false) {
            return self::resultRow($op, 'NO_CHANGE', $errors, $personel, $plan);
        } elseif ($op === PersonelLifecycleBulkRowContract::OP_ORG_UPDATE && $personel !== null) {
            try {
                $targets = is_array($plan['targets'] ?? null) ? $plan['targets'] : [];
                $plan['targets'] = PersonelLifecycleBulkMutationPlanner::planOrgTargets($pdo, $personel, $targets);
                $plan['canonical_ready'] = true;
            } catch (\Throwable $e) {
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

        return $row;
    }

    /** @return array<string, mixed>|null */
    private static function matchPersonel(PDO $pdo, int $personelId, string $sicil): ?array
    {
        if ($personelId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, sicil_no, sube_id, bolum_id, birim_id, aktif_durum, gorev_id, departman_id,
                        sgk_isveren_id, calisma_lokasyonu_id, pozisyon_id
                 FROM personeller WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        }
        if ($sicil !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, sicil_no, sube_id, bolum_id, birim_id, aktif_durum, gorev_id, departman_id,
                        sgk_isveren_id, calisma_lokasyonu_id, pozisyon_id
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
        foreach (PersonelOrganizasyonDegisikligiService::TRACKED_FIELDS as $field) {
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
                $targets = [
                    'gorev_id' => $payload['gorev_id'] ?? null,
                    'departman_id' => $payload['departman_id'] ?? null,
                    'bolum_id' => $payload['bolum_id'] ?? null,
                    'birim_id' => $payload['birim_id'] ?? null,
                    'pozisyon_id' => $payload['pozisyon_id'] ?? null,
                    'sgk_isveren_id' => $payload['sgk_isveren_id'] ?? null,
                    'calisma_lokasyonu_id' => $payload['calisma_lokasyonu_id'] ?? null,
                ];
                if (trim((string) ($row['yeni_gorev_unvan'] ?? '')) !== '') {
                    $targets['gorev_id'] = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName(
                        $pdo,
                        'gorevler',
                        (string) $row['yeni_gorev_unvan']
                    );
                }
                if (trim((string) ($row['yeni_departman'] ?? '')) !== '') {
                    $targets['departman_id'] = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName(
                        $pdo,
                        'departmanlar',
                        (string) $row['yeni_departman']
                    );
                }
                if (trim((string) ($row['yeni_bolum'] ?? '')) !== '') {
                    $targets['bolum_id'] = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName(
                        $pdo,
                        'bolumler',
                        (string) $row['yeni_bolum']
                    );
                }
                if (trim((string) ($row['yeni_birim'] ?? '')) !== '') {
                    $targets['birim_id'] = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName(
                        $pdo,
                        'birimler',
                        (string) $row['yeni_birim']
                    );
                }
                if (trim((string) ($row['yeni_pozisyon'] ?? '')) !== '') {
                    $targets['pozisyon_id'] = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName(
                        $pdo,
                        'pozisyonlar',
                        (string) $row['yeni_pozisyon']
                    );
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
}
