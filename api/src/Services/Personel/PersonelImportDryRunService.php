<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Scope\SubeScope;
use PDO;
use RuntimeException;

/**
 * S97-A: Personel ana veri CSV dry-run (no INSERT/UPDATE/DELETE).
 */
final class PersonelImportDryRunService
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 2097152; // 2 MB

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
        'acil_durum_kisi',
        'acil_durum_telefon',
        'ise_giris_tarihi',
        'sube',
        'departman',
        'gorev',
        'personel_tipi',
    ];

    private const OPTIONAL_COLUMNS = [
        'dogum_yeri',
        'kan_grubu',
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

        if (strncmp($csvContent, "\xEF\xBB\xBF", 3) === 0) {
            $csvContent = substr($csvContent, 3);
        }

        $lines = preg_split("/\r\n|\n|\r/", $csvContent);
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

        $allowedSubeIds = SubeScope::allowedSubeIds($user);
        $activeSubeId = self::parsePositiveInt($activeSubeHeader);

        $refCatalog = self::loadReferenceCatalog($pdo);
        $existingTc = self::loadExistingTcSet($pdo);
        $existingSicil = self::loadExistingSicilSet($pdo);

        $seenTc = [];
        $seenSicil = [];
        $satirlar = [];
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

            // Resolve references first (exact unique name match).
            $resolved = self::resolveReferences($rowMap, $refCatalog, $hataKodlari);

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
                    && !self::isSubeDepartmanLinked($subeId, (int) $resolved['departman_id'], $refCatalog)
                ) {
                    $hataKodlari[] = 'PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI';
                }
            }

            $hataKodlari = array_values(array_unique($hataKodlari));
            $isValid = count($hataKodlari) === 0;
            if ($isValid) {
                $gecerli++;
                $aday++;
                $durum = 'GECERLI';
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

        return [
            'ozet' => [
                'toplam_satir' => count($dataLines),
                'gecerli_satir' => $gecerli,
                'hatali_satir' => $hatali,
                'warning_sayisi' => $warningSayisi,
                'kayit_olusturulacak_aday' => $aday,
                'veritabaninda_mevcut' => $mevcut,
            ],
            'satirlar' => $satirlar,
            'yazma' => [
                'personel_write' => false,
                'salary_write' => false,
                'wage_model_assumption' => false,
            ],
        ];
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
     * @return array{sube_id: ?int, departman_id: ?int, gorev_id: ?int, personel_tipi_id: ?int}
     */
    private static function resolveReferences(array $rowMap, array $catalog, array &$hataKodlari)
    {
        $subeId = self::resolveExactUnique($rowMap['sube'] ?? '', $catalog['sube'], 'sube', $hataKodlari);
        $departmanId = self::resolveExactUnique($rowMap['departman'] ?? '', $catalog['departman'], 'departman', $hataKodlari);
        $gorevId = self::resolveExactUnique($rowMap['gorev'] ?? '', $catalog['gorev'], 'gorev', $hataKodlari);
        $personelTipiId = self::resolveExactUnique($rowMap['personel_tipi'] ?? '', $catalog['personel_tipi'], 'personel_tipi', $hataKodlari);

        return [
            'sube_id' => $subeId,
            'departman_id' => $departmanId,
            'gorev_id' => $gorevId,
            'personel_tipi_id' => $personelTipiId,
        ];
    }

    /**
     * @param array<string, list<int>> $index
     * @param list<string> $hataKodlari
     */
    private static function resolveExactUnique($name, array $index, $field, array &$hataKodlari)
    {
        $key = trim((string) $name);
        if ($key === '') {
            return null;
        }
        if (!isset($index[$key])) {
            $hataKodlari[] = 'PERSONEL_IMPORT_REFERANS_BULUNAMADI';
            return null;
        }
        $ids = $index[$key];
        if (count($ids) !== 1) {
            $hataKodlari[] = 'PERSONEL_IMPORT_REFERANS_BELIRSIZ';
            return null;
        }

        return (int) $ids[0];
    }

    /** @return array<string, mixed> */
    private static function loadReferenceCatalog(PDO $pdo)
    {
        return [
            'sube' => self::loadNameIndex($pdo, 'subeler'),
            'departman' => self::loadNameIndex($pdo, 'departmanlar'),
            'gorev' => self::loadNameIndex($pdo, 'gorevler'),
            'personel_tipi' => self::loadNameIndex($pdo, 'personel_tipleri'),
            'sube_departman' => self::loadSubeDepartmanPairs($pdo),
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private static function loadNameIndex(PDO $pdo, $table)
    {
        $allowed = ['subeler', 'departmanlar', 'gorevler', 'personel_tipleri'];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Invalid reference table.');
        }

        $stmt = $pdo->query("SELECT id, ad FROM $table WHERE durum = 'AKTIF'");
        $index = [];
        if (!$stmt) {
            return $index;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) ($row['ad'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!isset($index[$name])) {
                $index[$name] = [];
            }
            $index[$name][] = (int) $row['id'];
        }

        return $index;
    }

    /** @return array<string, true> */
    private static function loadSubeDepartmanPairs(PDO $pdo)
    {
        $pairs = [];
        try {
            $stmt = $pdo->query('SELECT sube_id, departman_id FROM sube_departmanlar');
            if (!$stmt) {
                return $pairs;
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = ((int) $row['sube_id']) . ':' . ((int) $row['departman_id']);
                $pairs[$key] = true;
            }
        } catch (\Throwable $e) {
            return $pairs;
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private static function isSubeDepartmanLinked($subeId, $departmanId, array $catalog)
    {
        $pairs = $catalog['sube_departman'] ?? [];
        if (!is_array($pairs) || count($pairs) === 0) {
            // No mapping rows → treat as open (matches create which only checks AKTIF FKs).
            return true;
        }

        $key = ((int) $subeId) . ':' . ((int) $departmanId);

        return isset($pairs[$key]);
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
