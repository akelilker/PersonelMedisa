<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Scope\SubeScope;
use PDO;
use RuntimeException;

/**
 * S97-A/B: Personel ana veri CSV dry-run + shared analyze/manifest owner.
 * Dry-run path performs no INSERT/UPDATE/DELETE.
 */
final class PersonelImportDryRunService
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 2097152; // 2 MB
    public const SCHEMA_VERSION = 'personel-import-v1';
    public const PARSER_VERSION = 'personel-import-parser-v1';
    public const IMPORT_MODE = 'CREATE_ONLY_ALL_OR_NOTHING';

    public const TEMPLATE_COLUMNS = [
        'tc_kimlik_no',
        'sicil_no',
        'ad',
        'soyad',
        'dogum_tarihi',
        'dogum_yeri',
        'telefon',
        'kan_grubu',
        'acil_durum_kisi',
        'acil_durum_telefon',
        'ise_giris_tarihi',
        'sube',
        'departman',
        'gorev',
        'personel_tipi',
    ];

    private const REQUIRED_COLUMNS = [
        'tc_kimlik_no',
        'sicil_no',
        'ad',
        'soyad',
        'dogum_tarihi',
        'telefon',
        'ise_giris_tarihi',
        'sube',
        'departman',
        'gorev',
        'personel_tipi',
    ];

    private const OPTIONAL_COLUMNS = [
        'dogum_yeri',
        'kan_grubu',
        'acil_durum_kisi',
        'acil_durum_telefon',
        'sgk_isveren',
        'calisma_lokasyonu',
    ];

    private const ORG_LOCATION_OPTIONAL_COLUMNS = [
        'sgk_isveren',
        'calisma_lokasyonu',
    ];

    private const FORBIDDEN_UCRET_COLUMNS = [
        'maas_tutari',
        'ucret_tipi_id',
        'ucret_modeli',
        'aylik_ucret',
        'gunluk_ucret',
        'saatlik_ucret',
        'ucret_turu',
        'net_maas_tutari',
        'brut_maas_tutari',
        'bordro_kapsami',
        'sgk_statu',
        'sgk_durumu',
        'sgk_statu_kodu',
        'onceki_kumulatif_gelir_vergisi_matrahi',
        'onceki_kumulatif_gelir_vergisi',
        'onceki_kumulatif_sgk_matrahi',
        'onceki_kumulatif_sgk_primi',
        'bordro_devir',
        'devir_matrah',
        'devir_vergi',
    ];

    public static function buildTemplateCsv()
    {
        $header = implode(';', self::TEMPLATE_COLUMNS);
        $example = implode(';', [
            '',
            '',
            '',
            '',
            'YYYY-MM-DD',
            '',
            '',
            '',
            '',
            '',
            'YYYY-MM-DD',
            '',
            '',
            '',
            '',
        ]);

        return "\xEF\xBB\xBF" . $header . "\r\n" . $example . "\r\n";
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function dryRun(PDO $pdo, $csvContent, array $user, $activeSubeHeader = null)
    {
        $analysis = self::analyze($pdo, $csvContent, $user, $activeSubeHeader);

        return self::toPublicDryRunResult($analysis);
    }

    /**
     * Shared parse/validate/manifest pipeline for dry-run and apply.
     *
     * @param array<string, mixed> $user
     * @return array{
     *   source_sha256: string,
     *   manifest_hash: string,
     *   schema_version: string,
     *   headers: list<string>,
     *   allowed_sube_ids: list<int>,
     *   active_sube_id: ?int,
     *   ozet: array<string, int>,
     *   satirlar: list<array<string, mixed>>,
     *   candidates: list<array<string, mixed>>,
     *   can_apply: bool
     * }
     */
    public static function analyze(PDO $pdo, $csvContent, array $user, $activeSubeHeader = null)
    {
        if (!is_string($csvContent)) {
            throw new PersonelImportException('PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV icerigi okunamadi.', 400);
        }

        $byteLength = strlen($csvContent);
        if ($byteLength > self::MAX_BYTES) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_DOSYA_BOYUTU',
                'CSV dosyasi en fazla 2 MB olabilir.',
                400
            );
        }

        $normalizedCsv = $csvContent;
        if (strncmp($normalizedCsv, "\xEF\xBB\xBF", 3) === 0) {
            $normalizedCsv = substr($normalizedCsv, 3);
        }
        $sourceSha256 = hash('sha256', $normalizedCsv);

        $lines = preg_split("/\r\n|\n|\r/", $normalizedCsv);
        if ($lines === false) {
            throw new PersonelImportException('PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV icerigi okunamadi.', 400);
        }

        $nonEmpty = [];
        foreach ($lines as $index => $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $nonEmpty[] = ['raw_line_no' => $index + 1, 'line' => (string) $line];
        }

        if (count($nonEmpty) === 0) {
            throw new PersonelImportException('PERSONEL_IMPORT_EKSIK_ZORUNLU_KOLON', 'CSV baslik satiri zorunludur.', 400);
        }

        $headerParse = self::parseCsvLine($nonEmpty[0]['line']);
        $headers = array_map(static function ($h) {
            return self::normalizeHeader((string) $h);
        }, $headerParse);

        self::assertHeaderContract($headers);

        $dataLines = array_slice($nonEmpty, 1);
        if (count($dataLines) > self::MAX_ROWS) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_SATIR_SINIRI',
                'CSV en fazla ' . self::MAX_ROWS . ' satir icerebilir.',
                400
            );
        }

        // Org gate: headers alone are not enough — only reject when a data cell has a non-blank
        // sgk_isveren / calisma_lokasyonu value while schema is not ready.
        $orgHeaderIndexes = [];
        foreach (self::ORG_LOCATION_OPTIONAL_COLUMNS as $orgCol) {
            $idx = array_search($orgCol, $headers, true);
            if ($idx !== false) {
                $orgHeaderIndexes[] = (int) $idx;
            }
        }
        if (count($orgHeaderIndexes) > 0 && !PersonelOrgLocationSchema::isReady($pdo)) {
            $hasNonBlankOrgValue = false;
            foreach ($dataLines as $entry) {
                $cells = self::parseCsvLine($entry['line']);
                foreach ($orgHeaderIndexes as $orgIdx) {
                    if (!array_key_exists($orgIdx, $cells)) {
                        continue;
                    }
                    if (trim((string) $cells[$orgIdx]) !== '') {
                        $hasNonBlankOrgValue = true;
                        break 2;
                    }
                }
            }
            if ($hasNonBlankOrgValue) {
                throw new PersonelImportException(
                    PersonelOrgLocationSchema::ERROR_CODE,
                    'Org location schema hazir degil; sgk_isveren / calisma_lokasyonu kolonlari kabul edilmez.',
                    409
                );
            }
        }

        $allowedSubeIds = SubeScope::allowedSubeIds($user);
        $activeSubeId = self::parsePositiveInt($activeSubeHeader);

        $refCatalog = PersonelImportReferenceCatalogService::loadCatalogForDryRun($pdo);
        $existingTc = self::loadExistingTcSet($pdo);
        $existingSicil = self::loadExistingSicilSet($pdo);

        $seenTc = [];
        $seenSicil = [];
        $satirlar = [];
        $candidates = [];
        $manifestRows = [];
        $gecerli = 0;
        $hatali = 0;
        $warningSayisi = 0;
        $aday = 0;
        $mevcut = 0;

        foreach ($dataLines as $entry) {
            $satirNo = (int) $entry['raw_line_no'];
            $cells = self::parseCsvLine($entry['line']);
            $hataKodlari = [];

            if (count($cells) !== count($headers)) {
                $hataKodlari[] = 'PERSONEL_IMPORT_SATIR_KOLON_UYUMSUZ';
                $tcProbe = '';
                $sicilProbe = '';
                foreach ($headers as $index => $header) {
                    if ($header === 'tc_kimlik_no') {
                        $tcProbe = array_key_exists($index, $cells) ? trim((string) $cells[$index]) : '';
                    }
                    if ($header === 'sicil_no') {
                        $sicilProbe = array_key_exists($index, $cells) ? trim((string) $cells[$index]) : '';
                    }
                }
                $hatali++;
                $satirlar[] = [
                    'satir_no' => $satirNo,
                    'sicil_no' => $sicilProbe,
                    'tc_kimlik_no_masked' => PersonelCanonicalValidator::maskTcKimlikNo($tcProbe),
                    'durum' => 'HATALI',
                    'hata_kodlari' => $hataKodlari,
                    'uyarilar' => [],
                ];
                continue;
            }

            $rowMap = self::mapRow($headers, $cells);
            $tcRaw = trim((string) ($rowMap['tc_kimlik_no'] ?? ''));
            $sicilRaw = trim((string) ($rowMap['sicil_no'] ?? ''));
            $maskedTc = PersonelCanonicalValidator::maskTcKimlikNo($tcRaw);
            $hasOrgCols = in_array('sgk_isveren', $headers, true) || in_array('calisma_lokasyonu', $headers, true);

            $resolved = self::resolveReferences($rowMap, $refCatalog, $hataKodlari, $hasOrgCols);

            $importBody = [
                'tc_kimlik_no' => $tcRaw,
                'sicil_no' => $sicilRaw,
                'ad' => $rowMap['ad'] ?? '',
                'soyad' => $rowMap['soyad'] ?? '',
                'dogum_tarihi' => $rowMap['dogum_tarihi'] ?? '',
                'dogum_yeri' => $rowMap['dogum_yeri'] ?? '',
                'telefon' => $rowMap['telefon'] ?? '',
                'kan_grubu' => $rowMap['kan_grubu'] ?? '',
                'acil_durum_kisi' => $rowMap['acil_durum_kisi'] ?? '',
                'acil_durum_telefon' => $rowMap['acil_durum_telefon'] ?? '',
                'ise_giris_tarihi' => $rowMap['ise_giris_tarihi'] ?? '',
                'sube_id' => $resolved['sube_id'],
                'departman_id' => $resolved['departman_id'],
                'gorev_id' => $resolved['gorev_id'],
                'personel_tipi_id' => $resolved['personel_tipi_id'],
            ];
            if ($hasOrgCols) {
                $importBody['sgk_isveren_id'] = $resolved['sgk_isveren_id'];
                $importBody['calisma_lokasyonu_id'] = $resolved['calisma_lokasyonu_id'];
            }

            $fieldResult = PersonelCanonicalValidator::validateImportAnaVeriRow($importBody);
            foreach ($fieldResult['errors'] as $err) {
                $hataKodlari[] = (string) $err['code'];
            }

            if ($tcRaw !== '') {
                if (isset($seenTc[$tcRaw])) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_DOSYA_ICI_DUPLICATE_TC';
                } else {
                    $seenTc[$tcRaw] = $satirNo;
                }
                if (isset($existingTc[$tcRaw])) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_TC_MEVCUT';
                    $mevcut++;
                }
            }

            if ($sicilRaw !== '') {
                $sicilKey = self::normalizeSicilKey($sicilRaw);
                if (isset($seenSicil[$sicilKey])) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_DOSYA_ICI_DUPLICATE_SICIL';
                } else {
                    $seenSicil[$sicilKey] = $satirNo;
                }
                if (isset($existingSicil[$sicilKey])) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_SICIL_MEVCUT';
                    if ($tcRaw === '' || !isset($existingTc[$tcRaw])) {
                        $mevcut++;
                    }
                }
            }

            $subeId = $resolved['sube_id'];
            if ($subeId !== null) {
                if ($activeSubeId !== null && $activeSubeId !== $subeId) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_SUBE_SCOPE_IHLALI';
                }
                if (count($allowedSubeIds) > 0 && !in_array($subeId, $allowedSubeIds, true)) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_SUBE_SCOPE_IHLALI';
                }
                if (
                    $resolved['departman_id'] !== null
                    && !PersonelImportReferenceCatalogService::isSubeDepartmanLinked($subeId, (int) $resolved['departman_id'], $refCatalog)
                ) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI';
                }
            }

            $hataKodlari = array_values(array_unique($hataKodlari));
            $isValid = count($hataKodlari) === 0;
            $payload = is_array($fieldResult['payload'] ?? null) ? $fieldResult['payload'] : null;
            if ($isValid && $payload !== null) {
                $gecerli++;
                $aday++;
                $durum = 'GECERLI';
                // Ham TC yalniz bellek-ici manifest hesabina girer; saklanmaz/response'a cikmaz.
                $manifestRows[] = [
                    'satir_no' => $satirNo,
                    'tc_kimlik_no' => (string) $payload['tc_kimlik_no'],
                    'sicil_no' => (string) $payload['sicil_no'],
                    'ad' => (string) $payload['ad'],
                    'soyad' => (string) $payload['soyad'],
                    'dogum_tarihi' => (string) $payload['dogum_tarihi'],
                    'ise_giris_tarihi' => (string) $payload['ise_giris_tarihi'],
                    'telefon' => (string) $payload['telefon'],
                    'acil_durum_kisi' => $payload['acil_durum_kisi'] === null || $payload['acil_durum_kisi'] === ''
                        ? null
                        : (string) $payload['acil_durum_kisi'],
                    'acil_durum_telefon' => $payload['acil_durum_telefon'] === null || $payload['acil_durum_telefon'] === ''
                        ? null
                        : (string) $payload['acil_durum_telefon'],
                    'dogum_yeri' => $payload['dogum_yeri'],
                    'kan_grubu' => $payload['kan_grubu'],
                    'sube_id' => (int) $payload['sube_id'],
                    'departman_id' => (int) $payload['departman_id'],
                    'gorev_id' => (int) $payload['gorev_id'],
                    'personel_tipi_id' => (int) $payload['personel_tipi_id'],
                    'aktif_durum' => (string) $payload['aktif_durum'],
                ];
                if ($hasOrgCols) {
                    $manifestRows[count($manifestRows) - 1]['sgk_isveren_id'] = $payload['sgk_isveren_id'] ?? null;
                    $manifestRows[count($manifestRows) - 1]['calisma_lokasyonu_id'] = $payload['calisma_lokasyonu_id'] ?? null;
                }
                $candidates[] = [
                    'satir_no' => $satirNo,
                    'payload' => $payload,
                    'sicil_no' => (string) $payload['sicil_no'],
                    'ad' => (string) $payload['ad'],
                    'soyad' => (string) $payload['soyad'],
                    'tc_kimlik_no_masked' => $maskedTc,
                    'sube_id' => (int) $payload['sube_id'],
                    'departman_id' => (int) $payload['departman_id'],
                    'gorev_id' => (int) $payload['gorev_id'],
                    'personel_tipi_id' => (int) $payload['personel_tipi_id'],
                ];
                if ($hasOrgCols) {
                    $candidates[count($candidates) - 1]['sgk_isveren_id'] = isset($payload['sgk_isveren_id']) && $payload['sgk_isveren_id'] !== null
                        ? (int) $payload['sgk_isveren_id']
                        : null;
                    $candidates[count($candidates) - 1]['calisma_lokasyonu_id'] = isset($payload['calisma_lokasyonu_id']) && $payload['calisma_lokasyonu_id'] !== null
                        ? (int) $payload['calisma_lokasyonu_id']
                        : null;
                    $candidates[count($candidates) - 1]['_org_refs_in_hash'] = true;
                }
            } else {
                $hatali++;
                $durum = in_array('PERSONEL_IMPORT_TC_MEVCUT', $hataKodlari, true)
                    || in_array('PERSONEL_IMPORT_SICIL_MEVCUT', $hataKodlari, true)
                    ? 'MEVCUT'
                    : 'HATALI';
            }

            $satirlar[] = [
                'satir_no' => $satirNo,
                'sicil_no' => $sicilRaw,
                'tc_kimlik_no_masked' => $maskedTc,
                'durum' => $durum,
                'hata_kodlari' => $hataKodlari,
                'uyarilar' => [],
            ];
        }

        $sortedAllowed = array_values($allowedSubeIds);
        sort($sortedAllowed, SORT_NUMERIC);

        $manifestHash = self::buildManifestHash([
            'schema_version' => self::SCHEMA_VERSION,
            'parser_version' => self::PARSER_VERSION,
            'import_mode' => self::IMPORT_MODE,
            'headers' => $headers,
            'active_sube_id' => $activeSubeId,
            'allowed_sube_ids' => $sortedAllowed,
            'rows' => $manifestRows,
        ]);

        // row_hash TC'den bagimsiz: manifest_hash | satir_no | sicil | resolved refs
        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['row_hash'] = !empty($candidate['_org_refs_in_hash'])
                ? self::buildRowHash(
                    $manifestHash,
                    (int) $candidate['satir_no'],
                    (string) $candidate['sicil_no'],
                    (int) $candidate['sube_id'],
                    (int) $candidate['departman_id'],
                    (int) $candidate['gorev_id'],
                    (int) $candidate['personel_tipi_id'],
                    isset($candidate['sgk_isveren_id']) ? $candidate['sgk_isveren_id'] : null,
                    isset($candidate['calisma_lokasyonu_id']) ? $candidate['calisma_lokasyonu_id'] : null
                )
                : self::buildRowHashLegacy(
                    $manifestHash,
                    (int) $candidate['satir_no'],
                    (string) $candidate['sicil_no'],
                    (int) $candidate['sube_id'],
                    (int) $candidate['departman_id'],
                    (int) $candidate['gorev_id'],
                    (int) $candidate['personel_tipi_id']
                );
            unset($candidates[$index]['_org_refs_in_hash']);
        }

        $canApply = count($dataLines) > 0 && $hatali === 0 && $gecerli === count($dataLines);

        return [
            'source_sha256' => $sourceSha256,
            'manifest_hash' => $manifestHash,
            'schema_version' => self::SCHEMA_VERSION,
            'headers' => $headers,
            'allowed_sube_ids' => $sortedAllowed,
            'active_sube_id' => $activeSubeId,
            'ozet' => [
                'toplam_satir' => count($dataLines),
                'gecerli_satir' => $gecerli,
                'hatali_satir' => $hatali,
                'warning_sayisi' => $warningSayisi,
                'kayit_olusturulacak_aday' => $aday,
                'veritabaninda_mevcut' => $mevcut,
            ],
            'satirlar' => $satirlar,
            'candidates' => $candidates,
            'can_apply' => $canApply,
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    public static function toPublicDryRunResult(array $analysis)
    {
        $ozet = is_array($analysis['ozet'] ?? null) ? $analysis['ozet'] : [];

        return [
            'ozet' => $ozet,
            'satirlar' => $analysis['satirlar'] ?? [],
            'source_sha256' => (string) ($analysis['source_sha256'] ?? ''),
            'manifest_hash' => (string) ($analysis['manifest_hash'] ?? ''),
            'schema_version' => (string) ($analysis['schema_version'] ?? self::SCHEMA_VERSION),
            'row_count' => (int) ($ozet['toplam_satir'] ?? 0),
            'valid_row_count' => (int) ($ozet['gecerli_satir'] ?? 0),
            'can_apply' => (bool) ($analysis['can_apply'] ?? false),
            'yazma' => [
                'personel_write' => false,
                'salary_write' => false,
                'wage_model_assumption' => false,
            ],
        ];
    }

    /**
     * TC-independent durable row fingerprint for audit rows.
     * SHA256(manifest_hash | row_number | sicil_no | resolved_reference_ids)
     */
    /**
     * Legacy row fingerprint (pre-org-columns CSV) — preserves historical golden hashes.
     */
    public static function buildRowHashLegacy(
        string $manifestHash,
        int $satirNo,
        string $sicilNo,
        int $subeId,
        int $departmanId,
        int $gorevId,
        int $personelTipiId
    ): string {
        $material = implode('|', [
            strtolower($manifestHash),
            (string) $satirNo,
            $sicilNo,
            (string) $subeId,
            (string) $departmanId,
            (string) $gorevId,
            (string) $personelTipiId,
        ]);

        return hash('sha256', $material);
    }

    public static function buildRowHash(
        string $manifestHash,
        int $satirNo,
        string $sicilNo,
        int $subeId,
        int $departmanId,
        int $gorevId,
        int $personelTipiId,
        $sgkIsverenId = null,
        $calismaLokasyonuId = null
    ): string {
        $material = implode('|', [
            strtolower($manifestHash),
            (string) $satirNo,
            $sicilNo,
            (string) $subeId,
            (string) $departmanId,
            (string) $gorevId,
            (string) $personelTipiId,
            $sgkIsverenId === null ? '0' : (string) (int) $sgkIsverenId,
            $calismaLokasyonuId === null ? '0' : (string) (int) $calismaLokasyonuId,
        ]);

        return hash('sha256', $material);
    }

    /** @param array<string, mixed> $manifest */
    public static function buildManifestHash(array $manifest): string
    {
        return hash('sha256', self::canonicalJson($manifest));
    }

    /** @param array<string, mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Manifest JSON encode failed.');
        }

        return $encoded;
    }

    /** @param list<string> $headers */
    private static function assertHeaderContract(array $headers)
    {
        if (count($headers) === 0) {
            throw new PersonelImportException('PERSONEL_IMPORT_EKSIK_ZORUNLU_KOLON', 'CSV baslik satiri zorunludur.', 400);
        }

        $unique = [];
        foreach ($headers as $header) {
            if ($header === '') {
                throw new PersonelImportException('PERSONEL_IMPORT_BILINMEYEN_KOLON', 'Bos kolon basligi kabul edilmez.', 400);
            }
            if (isset($unique[$header])) {
                throw new PersonelImportException('PERSONEL_IMPORT_BILINMEYEN_KOLON', 'Tekrarlayan kolon basligi kabul edilmez.', 400);
            }
            $unique[$header] = true;
        }

        foreach ($headers as $header) {
            if (in_array($header, self::FORBIDDEN_UCRET_COLUMNS, true)) {
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_UCRET_KARARI_BEKLENIYOR',
                    'Bu asama ucret/bordro alanlarini kabul etmez. Ucret modeli karari bekleniyor.',
                    400
                );
            }
        }

        $allowed = array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);
        foreach ($headers as $header) {
            if (!in_array($header, $allowed, true)) {
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_BILINMEYEN_KOLON',
                    'Bilinmeyen kolon: ' . $header,
                    400
                );
            }
        }

        foreach (self::REQUIRED_COLUMNS as $required) {
            if (!in_array($required, $headers, true)) {
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_EKSIK_ZORUNLU_KOLON',
                    'Eksik zorunlu kolon: ' . $required,
                    400
                );
            }
        }
    }

    /**
     * @param list<string> $headers
     * @param list<string> $cells
     * @return array<string, string>
     */
    private static function mapRow(array $headers, array $cells)
    {
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = array_key_exists($index, $cells) ? (string) $cells[$index] : '';
        }

        return $row;
    }

    /**
     * @param array<string, string> $rowMap
     * @param array<string, mixed> $catalog
     * @param list<string> $hataKodlari
     * @return array{
     *   sube_id: ?int,
     *   departman_id: ?int,
     *   gorev_id: ?int,
     *   personel_tipi_id: ?int,
     *   sgk_isveren_id: ?int,
     *   calisma_lokasyonu_id: ?int
     * }
     */
    private static function resolveReferences(array $rowMap, array $catalog, array &$hataKodlari, bool $hasOrgCols = false)
    {
        $subeId = PersonelImportReferenceCatalogService::resolveExactUnique(
            $rowMap['sube'] ?? '',
            $catalog['sube'],
            'sube',
            $hataKodlari
        );
        $departmanId = PersonelImportReferenceCatalogService::resolveExactUnique(
            $rowMap['departman'] ?? '',
            $catalog['departman'],
            'departman',
            $hataKodlari
        );
        $gorevId = PersonelImportReferenceCatalogService::resolveExactUnique(
            $rowMap['gorev'] ?? '',
            $catalog['gorev'],
            'gorev',
            $hataKodlari
        );
        $personelTipiId = PersonelImportReferenceCatalogService::resolveExactUnique(
            $rowMap['personel_tipi'] ?? '',
            $catalog['personel_tipi'],
            'personel_tipi',
            $hataKodlari
        );

        $sgkIsverenId = null;
        $calismaLokasyonuId = null;
        if ($hasOrgCols) {
            if (array_key_exists('sgk_isveren', $rowMap)) {
                $raw = trim((string) $rowMap['sgk_isveren']);
                if ($raw !== '') {
                    $sgkIsverenId = PersonelImportReferenceCatalogService::resolveExactUnique(
                        $raw,
                        isset($catalog['sgk_isveren']) && is_array($catalog['sgk_isveren']) ? $catalog['sgk_isveren'] : [],
                        'sgk_isveren',
                        $hataKodlari
                    );
                }
            }
            if (array_key_exists('calisma_lokasyonu', $rowMap)) {
                $raw = trim((string) $rowMap['calisma_lokasyonu']);
                if ($raw !== '') {
                    $calismaLokasyonuId = PersonelImportReferenceCatalogService::resolveExactUnique(
                        $raw,
                        isset($catalog['calisma_lokasyonu']) && is_array($catalog['calisma_lokasyonu'])
                            ? $catalog['calisma_lokasyonu']
                            : [],
                        'calisma_lokasyonu',
                        $hataKodlari
                    );
                }
            }
        }

        return [
            'sube_id' => $subeId,
            'departman_id' => $departmanId,
            'gorev_id' => $gorevId,
            'personel_tipi_id' => $personelTipiId,
            'sgk_isveren_id' => $sgkIsverenId,
            'calisma_lokasyonu_id' => $calismaLokasyonuId,
        ];
    }

    /** @return array<string, true> */
    private static function loadExistingTcSet(PDO $pdo)
    {
        $set = [];
        $stmt = $pdo->query('SELECT tc_kimlik_no FROM personeller');
        if (!$stmt) {
            return $set;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tc = (string) ($row['tc_kimlik_no'] ?? '');
            if ($tc !== '') {
                $set[$tc] = true;
            }
        }

        return $set;
    }

    /** @return array<string, true> */
    private static function loadExistingSicilSet(PDO $pdo)
    {
        $set = [];
        $stmt = $pdo->query('SELECT sicil_no FROM personeller');
        if (!$stmt) {
            return $set;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sicil = self::normalizeSicilKey((string) ($row['sicil_no'] ?? ''));
            if ($sicil !== '') {
                $set[$sicil] = true;
            }
        }

        return $set;
    }

    private static function normalizeSicilKey($sicil)
    {
        return mb_strtolower(trim((string) $sicil), 'UTF-8');
    }

    private static function normalizeHeader($header)
    {
        $h = trim((string) $header);
        if (strncmp($h, "\xEF\xBB\xBF", 3) === 0) {
            $h = substr($h, 3);
        }
        $h = trim($h);

        return mb_strtolower($h, 'UTF-8');
    }

    /** @return list<string> */
    private static function parseCsvLine($line)
    {
        $delimiter = (substr_count((string) $line, ';') >= substr_count((string) $line, ',')) ? ';' : ',';
        $parsed = str_getcsv((string) $line, $delimiter, '"', '\\');
        if ($parsed === false) {
            return [];
        }

        return array_map(static function ($cell) {
            return trim((string) $cell);
        }, $parsed);
    }

    /** @param mixed $value */
    private static function parsePositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
