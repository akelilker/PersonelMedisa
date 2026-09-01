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
    public const SCHEMA_VERSION = 'personel-lifecycle-bulk-v1';
    public const MAX_ROWS = 500;

    /** @var list<string> */
    public const OPERATION_TYPES = [
        'YENI_GIRIS',
        'ISTEN_AYRILMA',
        'YENIDEN_ISE_ALMA',
        'GOREV_UNVAN_DEGISIKLIGI',
        'DEPARTMAN_BOLUM_BIRIM_POZISYON_DEGISIKLIGI',
        'KALICI_SUBE_DEGISIKLIGI',
        'CALISMA_LOKASYONU_DEGISIKLIGI',
        'SGK_ISVERENI_DEGISIKLIGI',
        'SADECE_NOT',
        'DEGISIKLIK_YOK',
    ];

    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function dryRun(PDO $pdo, array $user, Request $request, array $rows, $activeSubeHeader = null): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new PersonelImportException('ROW_LIMIT', 'En fazla ' . self::MAX_ROWS . ' satir analiz edilebilir.');
        }

        $scope = SubeScope::resolveScope($user, $activeSubeHeader);
        $seenKeys = [];
        $satirlar = [];
        $ready = 0;
        $blocked = 0;
        $noChange = 0;

        foreach ($rows as $index => $rawRow) {
            $rowNum = $index + 1;
            $row = self::normalizeRow($rawRow);
            $analysis = self::analyzeRow($pdo, $user, $request, $scope, $row, $seenKeys);
            $analysis['satir_no'] = $rowNum;
            $satirlar[] = $analysis;
            if (($analysis['durum'] ?? '') === 'READY') {
                $ready++;
            } elseif (($analysis['durum'] ?? '') === 'NO_CHANGE') {
                $noChange++;
            } else {
                $blocked++;
            }
        }

        $preimageChecksum = hash('sha256', json_encode($satirlar, JSON_UNESCAPED_UNICODE));
        $sourceChecksum = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'dry_run_checksum' => $preimageChecksum,
            'source_checksum' => $sourceChecksum,
            'preimage_checksum' => $preimageChecksum,
            'can_apply' => $blocked === 0 && $ready > 0,
            'ozet' => [
                'toplam_satir' => count($rows),
                'ready' => $ready,
                'blocked' => $blocked,
                'no_change' => $noChange,
            ],
            'satirlar' => $satirlar,
            'active_sube_id' => $scope,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private static function normalizeRow(array $raw): array
    {
        $out = [];
        foreach (PersonelExportService::CHANGE_TEMPLATE_HEADERS as $col) {
            $out[$col] = trim((string) ($raw[$col] ?? ''));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $row
     * @param array<string, true> $seenKeys
     * @return array<string, mixed>
     */
    private static function analyzeRow(PDO $pdo, array $user, Request $request, $scope, array $row, array &$seenKeys): array
    {
        $errors = [];
        $tip = strtoupper($row['islem_tipi'] ?? '');
        if ($tip === '') {
            $errors[] = 'ISLEM_TIPI_EKSIK';
        } elseif (!in_array($tip, self::OPERATION_TYPES, true)) {
            $errors[] = 'ISLEM_TIPI_GECERSIZ';
        }

        $personelId = (int) ($row['personel_id'] ?? 0);
        $sicil = $row['sicil_no'] ?? '';
        $matchKey = $personelId > 0 ? 'id:' . $personelId : ($sicil !== '' ? 'sicil:' . $sicil : '');
        if ($matchKey !== '') {
            if (isset($seenKeys[$matchKey])) {
                $errors[] = 'DUPLICATE_SATIR';
            }
            $seenKeys[$matchKey] = true;
        }

        $personel = null;
        if ($tip !== 'YENI_GIRIS' && $tip !== 'SADECE_NOT') {
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

        if ($tip === 'SADECE_NOT' || $tip === 'DEGISIKLIK_YOK') {
            return self::resultRow($tip, count($errors) === 0 ? 'NO_CHANGE' : 'BLOCKED', $errors, $personel, null);
        }

        if ($tip === 'YENI_GIRIS') {
            if (trim($row['ise_giris_tarihi'] ?? '') === '') {
                $errors[] = 'ISE_GIRIS_TARIHI_EKSIK';
            }

            return self::resultRow($tip, count($errors) === 0 ? 'READY' : 'BLOCKED', $errors, null, [
                'mutation' => 'PersonelCreateService',
                'plan' => 'create',
            ]);
        }

        if ($personel === null) {
            return self::resultRow($tip, 'BLOCKED', $errors, null, null);
        }

        $gerekce = trim($row['gerekce'] ?? '');
        if ($gerekce === '' && $tip !== 'DEGISIKLIK_YOK') {
            $errors[] = 'GEREKCE_EKSIK';
        }

        $plan = self::buildMutationPlan($tip, $personel, $row);
        if ($plan === null && $tip !== 'DEGISIKLIK_YOK') {
            $errors[] = 'PLAN_URETILEMEDI';
        } elseif ($plan !== null && ($plan['no_change'] ?? false)) {
            return self::resultRow($tip, 'NO_CHANGE', $errors, $personel, $plan);
        }

        return self::resultRow($tip, count($errors) === 0 ? 'READY' : 'BLOCKED', $errors, $personel, $plan);
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
        ?array $plan
    ): array {
        return [
            'islem_tipi' => $tip,
            'durum' => $durum,
            'hata_kodlari' => $errors,
            'personel_id' => $personel !== null ? (int) $personel['id'] : null,
            'sicil_no' => $personel !== null ? (string) ($personel['sicil_no'] ?? '') : null,
            'mutation_plan' => $plan,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function matchPersonel(PDO $pdo, int $personelId, string $sicil): ?array
    {
        if ($personelId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, sicil_no, sube_id, bolum_id, birim_id, aktif_durum, gorev_id, departman_id
                 FROM personeller WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        }
        if ($sicil !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, sicil_no, sube_id, bolum_id, birim_id, aktif_durum, gorev_id, departman_id
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
     * @param array<string, string> $row
     * @return array<string, mixed>|null
     */
    private static function buildMutationPlan(string $tip, array $personel, array $row): ?array
    {
        switch ($tip) {
            case 'ISTEN_AYRILMA':
                return [
                    'owner' => 'SureclerController::create ISTEN_AYRILMA',
                    'personel_id' => (int) $personel['id'],
                    'isten_cikis_tarihi' => $row['isten_cikis_tarihi'] ?? '',
                ];
            case 'YENIDEN_ISE_ALMA':
                return [
                    'owner' => 'SureclerController / rehire path',
                    'personel_id' => (int) $personel['id'],
                ];
            case 'GOREV_UNVAN_DEGISIKLIGI':
                return [
                    'owner' => 'PersonelOrganizasyonDegisikligiService',
                    'personel_id' => (int) $personel['id'],
                    'yeni_gorev' => $row['yeni_gorev_unvan'] ?? '',
                    'no_change' => trim($row['yeni_gorev_unvan'] ?? '') === '',
                ];
            case 'KALICI_SUBE_DEGISIKLIGI':
                return [
                    'owner' => 'PersonelKaliciSubeDegisikligiService',
                    'personel_id' => (int) $personel['id'],
                    'yeni_sube' => $row['yeni_sube'] ?? '',
                ];
            case 'CALISMA_LOKASYONU_DEGISIKLIGI':
                return [
                    'owner' => 'PersonelOrganizasyonDegisikligiService',
                    'personel_id' => (int) $personel['id'],
                    'yeni_calisma_lokasyonu' => $row['yeni_calisma_lokasyonu'] ?? '',
                ];
            case 'SGK_ISVERENI_DEGISIKLIGI':
                return [
                    'owner' => 'PersonelOrganizasyonDegisikligiService',
                    'personel_id' => (int) $personel['id'],
                    'yeni_sgk_isveren' => $row['yeni_sgk_isvereni'] ?? '',
                ];
            case 'DEPARTMAN_BOLUM_BIRIM_POZISYON_DEGISIKLIGI':
                $any = trim($row['yeni_departman'] . $row['yeni_bolum'] . $row['yeni_birim'] . $row['yeni_pozisyon']);

                return [
                    'owner' => 'PersonelOrganizasyonDegisikligiService',
                    'personel_id' => (int) $personel['id'],
                    'no_change' => $any === '',
                ];
            default:
                return null;
        }
    }
}
