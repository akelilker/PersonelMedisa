<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\CsvResponse;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Organizasyon\SubeReadModel;
use PDO;
use RuntimeException;
use Throwable;

/**
 * S97-D: Shared personel-import reference catalog (dry-run + export owner).
 * Read-only. Never queries personeller. Never writes.
 */
final class PersonelImportReferenceCatalogService
{
    public const FILENAME = 'yukleme-kilavuzu.csv';
    public const OPEN_BAGLI_SUBE = 'TUM_YETKILI_SUBELER';
    public const SHA_HEADER = 'X-Personel-Import-Reference-SHA256';

    public const CSV_COLUMNS = [
        'bolum',
        'baslik',
        'deger',
        'aciklama',
    ];

    private const CORE_TABLES = [
        'subeler',
        'departmanlar',
        'gorevler',
        'personel_tipleri',
    ];

    private const TUR_ORDER = [
        'SUBE' => 1,
        'DEPARTMAN' => 2,
        'BOLUM' => 3,
        'BIRIM' => 4,
        'GOREV' => 5,
        'POZISYON' => 6,
        'PERSONEL_TIPI' => 7,
        'SGK_ISVEREN' => 8,
        'CALISMA_LOKASYONU' => 9,
        'CALISAN_KAPSAMI' => 10,
        'SABIT_DEGER' => 11,
    ];

    /**
     * Catalog shape used by dry-run exact-name resolution.
     * Departments are independent personnel references (OPEN_BRANCH_DEPARTMENT).
     *
     * @return array{
     *   sube: array<string, list<int>>,
     *   departman: array<string, list<int>>,
     *   gorev: array<string, list<int>>,
     *   personel_tipi: array<string, list<int>>
     * }
     */
    public static function loadCatalogForDryRun(PDO $pdo): array
    {
        $catalog = [
            'sube' => self::loadNameIndex($pdo, 'subeler'),
            'departman' => self::loadNameIndex($pdo, 'departmanlar'),
            'gorev' => self::loadNameIndex($pdo, 'gorevler'),
            'personel_tipi' => self::loadNameIndex($pdo, 'personel_tipleri'),
            'sgk_isveren' => PersonelOrgLocationSchema::isReady($pdo)
                ? self::loadNameIndex($pdo, 'sgk_isverenler')
                : [],
            'calisma_lokasyonu' => PersonelOrgLocationSchema::isReady($pdo)
                ? self::loadNameIndex($pdo, 'calisma_lokasyonlari')
                : [],
            'bolum_by_departman' => [],
            'birim_by_bolum' => [],
            'pozisyon' => [],
        ];

        if (PersonelOrgStructureSchema::isReady($pdo)) {
            $catalog['bolum_by_departman'] = self::loadChildNameIndexByParent(
                $pdo,
                'bolumler',
                'departman_id'
            );
            $catalog['birim_by_bolum'] = self::loadChildNameIndexByParent(
                $pdo,
                'birimler',
                'bolum_id'
            );
            $catalog['pozisyon'] = self::loadNameIndex($pdo, 'pozisyonlar');
        }

        return $catalog;
    }

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            foreach (self::CORE_TABLES as $table) {
                $stmt = $pdo->prepare(
                    "SELECT 1
                     FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                     LIMIT 1"
                );
                $stmt->execute(['table_name' => $table]);
                if (!$stmt->fetchColumn()) {
                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{filename: string, csv: string, sha256: string, body: string}
     */
    public static function buildExport(PDO $pdo, array $user, $activeSubeHeader = null): array
    {
        if (!self::schemaReady($pdo)) {
            throw new PersonelImportException(
                'SCHEMA_NOT_READY',
                'Personel import referans semasi henuz hazir degil.',
                409
            );
        }

        $allowedSubeIds = SubeScope::allowedSubeIds($user);
        $activeSubeId = self::parsePositiveInt($activeSubeHeader);
        if ($activeSubeId !== null && count($allowedSubeIds) > 0 && !in_array($activeSubeId, $allowedSubeIds, true)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_SUBE_SCOPE_IHLALI',
                'Secili sube icin yetkiniz yok.',
                403
            );
        }

        $scopeSubeIds = self::resolveExportSubeIds($pdo, $allowedSubeIds, $activeSubeId);
        $subeIndex = self::loadNameIndex($pdo, 'subeler');
        $departmanIndex = self::loadNameIndex($pdo, 'departmanlar');
        $gorevIndex = self::loadNameIndex($pdo, 'gorevler');
        $personelTipiIndex = self::loadNameIndex($pdo, 'personel_tipleri');

        $guideRows = [];
        $addGuide = static function (array &$out, string $bolum, string $baslik, string $deger = '', string $aciklama = '') {
            $out[] = [
                'bolum' => $bolum,
                'baslik' => $baslik,
                'deger' => $deger,
                'aciklama' => $aciklama,
            ];
        };

        $addGuide($guideRows, '1. Şablon Nasıl Doldurulur?', 'Genel', '', 'Şablon dosyasındaki kolon başlıklarını değiştirmeyin. Her satır bir personel adayıdır.');
        $addGuide($guideRows, '1. Şablon Nasıl Doldurulur?', 'Şube', '', 'Şube adını listede göründüğü tam adıyla yazın. Kısa veya ortak adlar (ör. yalnız Ankara) birden fazla şubeyle eşleşebilir; bu durumda aktarım durur.');
        $addGuide($guideRows, '1. Şablon Nasıl Doldurulur?', 'Departman / Bölüm / Birim', '', 'Önce departman, sonra o departmana bağlı bölüm, sonra o bölüme bağlı birim yazın. Üst kayıt olmadan alt kayıt eşleşmez.');
        $addGuide($guideRows, '1. Şablon Nasıl Doldurulur?', 'Yazım farkları', '', 'Büyük/küçük harf, Türkçe karakter (ş/s, ğ/g, ü/u, ö/o, ç/c, ı/i) ve fazla boşluk tek bir kayıtla net eşleşiyorsa sistem kabul eder; birden fazla kayıtla eşleşirse düzeltmeniz istenir.');
        $addGuide($guideRows, '10. Dikkat Edilecek Noktalar', 'Belirsizlik', '', 'Örnek: Ankara tek başına kullanılamaz. Medisa Ankara veya Karyapı Ankara gibi listedeki tam adlardan uygun olanı yazın.');
        $addGuide($guideRows, '10. Dikkat Edilecek Noktalar', 'Yetki', '', 'Yalnız yetkili olduğunuz şube ve kayıtlar listelenir. Listede olmayan değer kullanılamaz.');

        $legacyRows = [];
        self::appendNameRows($legacyRows, 'SUBE', $subeIndex, $scopeSubeIds, '');
        self::appendNameRows($legacyRows, 'DEPARTMAN', $departmanIndex, null, self::OPEN_BAGLI_SUBE);
        self::appendNameRows($legacyRows, 'GOREV', $gorevIndex, null, '');
        self::appendNameRows($legacyRows, 'PERSONEL_TIPI', $personelTipiIndex, null, '');

        if (PersonelOrgLocationSchema::isReady($pdo)) {
            $sgkIsverenIndex = self::loadNameIndex($pdo, 'sgk_isverenler');
            $calismaLokasyonuIndex = self::loadNameIndex($pdo, 'calisma_lokasyonlari');
            self::appendNameRows($legacyRows, 'SGK_ISVEREN', $sgkIsverenIndex, null, '');
            self::appendNameRows($legacyRows, 'CALISMA_LOKASYONU', $calismaLokasyonuIndex, null, '');
        }

        if (PersonelOrgStructureSchema::isReady($pdo)) {
            self::appendHierarchicalBolumRows($pdo, $legacyRows);
            self::appendHierarchicalBirimRows($pdo, $legacyRows);
            $pozisyonIndex = self::loadNameIndex($pdo, 'pozisyonlar');
            self::appendNameRows($legacyRows, 'POZISYON', $pozisyonIndex, null, '');
        }

        $legacyRows[] = [
            'referans_turu' => 'CALISAN_KAPSAMI',
            'deger' => PersonelCalisanKapsamService::IC_PERSONEL,
            'bagli_sube' => '',
            'kullanilabilir' => 'EVET',
            'eslesme_sayisi' => '1',
            'uyari_kodu' => '',
            'aciklama' => 'İç Personel (varsayılan).',
        ];
        $legacyRows[] = [
            'referans_turu' => 'CALISAN_KAPSAMI',
            'deger' => PersonelCalisanKapsamService::DIS_KAYNAK,
            'bagli_sube' => '',
            'kullanilabilir' => 'EVET',
            'eslesme_sayisi' => '1',
            'uyari_kodu' => '',
            'aciklama' => 'Harici Personel.',
        ];

        self::sortRows($legacyRows);

        $sectionMap = [
            'SUBE' => '2. Kullanılabilir Şubeler',
            'DEPARTMAN' => '3. Departman / Bölüm / Birim',
            'BOLUM' => '3. Departman / Bölüm / Birim',
            'BIRIM' => '3. Departman / Bölüm / Birim',
            'GOREV' => '4. Görevler',
            'POZISYON' => '5. Pozisyonlar',
            'PERSONEL_TIPI' => '6. Statü / Personel Tipi',
            'SGK_ISVEREN' => '7. SGK İşveren',
            'CALISMA_LOKASYONU' => '8. Çalışma Lokasyonu',
            'CALISAN_KAPSAMI' => '9. Çalışan Kapsamı',
        ];

        foreach ($legacyRows as $row) {
            $tur = (string) ($row['referans_turu'] ?? '');
            $bolum = $sectionMap[$tur] ?? ('Liste: ' . $tur);
            $kullanilabilir = (string) ($row['kullanilabilir'] ?? '');
            if ($kullanilabilir !== '' && $kullanilabilir !== 'EVET') {
                continue;
            }
            $aciklama = (string) ($row['aciklama'] ?? '');
            $bagli = (string) ($row['bagli_sube'] ?? '');
            if ($bagli !== '' && $bagli !== self::OPEN_BAGLI_SUBE) {
                $aciklama = trim($aciklama . ' Bağlı: ' . $bagli);
            }
            if ($tur === 'SUBE') {
                $aciklama = 'Şube adını listede göründüğü tam adıyla yazın.';
            }
            $addGuide(
                $guideRows,
                $bolum,
                $tur,
                (string) ($row['deger'] ?? ''),
                $aciklama
            );
        }

        $body = CsvResponse::buildSemicolon(self::CSV_COLUMNS, $guideRows);
        $sha256 = hash('sha256', $body);
        $csv = "\xEF\xBB\xBF" . $body;
        return [
            'filename' => self::FILENAME,
            'csv' => $csv,
            'body' => $body,
            'sha256' => $sha256,
        ];
    }

    /**
     * @param array<string, list<int>> $index
     * @param list<string> $hataKodlari
     * @return int|null
     */
    /**
     * Lookup-only normalization: whitespace collapse, TR case, safe diacritic fold.
     * Never persist this string as canonical DB value.
     */
    public static function normalizeMatchKey($value): string
    {
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        if (!is_string($s)) {
            return '';
        }
        // Turkish dotted/dotless I before generic lowercasing.
        $s = str_replace(['İ', 'I'], ['i', 'ı'], $s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'ş' => 's',
            'ğ' => 'g',
            'ü' => 'u',
            'ö' => 'o',
            'ç' => 'c',
            'ı' => 'i',
        ]);

        return $s;
    }

    /**
     * @param array<string, list<int>> $index canonical name => ids
     * @param list<string> $hataKodlari
     * @param list<array{field:string,input:string,canonical:string}>|null $autoMatches
     * @param list<array{field:string,input:string,candidates:list<string>}>|null $ambiguous
     * @return int|null
     */
    public static function resolveExactUnique($name, array $index, $field, array &$hataKodlari, ?array &$autoMatches = null, ?array &$ambiguous = null, ?array &$unknownMatches = null)
    {
        $raw = trim((string) $name);
        if ($raw === '') {
            return null;
        }

        if (isset($index[$raw])) {
            $ids = array_values(array_unique(array_map('intval', $index[$raw])));
            if (count($ids) === 1) {
                return $ids[0];
            }
            $hataKodlari[] = 'PERSONEL_IMPORT_REFERANS_BELIRSIZ';
            if ($ambiguous !== null) {
                $ambiguous[] = [
                    'field' => (string) $field,
                    'input' => $raw,
                    'candidates' => array_keys(array_filter(
                        $index,
                        static function ($v, $k) use ($raw) {
                            return $k === $raw;
                        },
                        ARRAY_FILTER_USE_BOTH
                    )),
                ];
                // Prefer listing canonical names that share these ids
                $cands = [];
                foreach ($index as $canonical => $idList) {
                    foreach ($idList as $id) {
                        if (in_array((int) $id, $ids, true)) {
                            $cands[(string) $canonical] = true;
                        }
                    }
                }
                $ambiguous[count($ambiguous) - 1]['candidates'] = array_keys($cands);
            }

            return null;
        }

        $want = self::normalizeMatchKey($raw);
        $idToCanonical = [];
        foreach ($index as $canonical => $idList) {
            if (self::normalizeMatchKey((string) $canonical) !== $want) {
                continue;
            }
            foreach ($idList as $id) {
                $idToCanonical[(int) $id] = (string) $canonical;
            }
        }

        if (count($idToCanonical) === 0) {
            $hataKodlari[] = 'PERSONEL_IMPORT_REFERANS_BULUNAMADI';
            if ($unknownMatches !== null) {
                $unknownMatches[] = [
                    'field' => (string) $field,
                    'input' => $raw,
                ];
            }

            return null;
        }

        if (count($idToCanonical) > 1) {
            $hataKodlari[] = 'PERSONEL_IMPORT_REFERANS_BELIRSIZ';
            if ($ambiguous !== null) {
                $ambiguous[] = [
                    'field' => (string) $field,
                    'input' => $raw,
                    'candidates' => array_values(array_unique(array_values($idToCanonical))),
                ];
            }

            return null;
        }

        $id = (int) array_key_first($idToCanonical);
        $canonical = $idToCanonical[$id];
        if ($autoMatches !== null && $raw !== $canonical) {
            $autoMatches[] = [
                'field' => (string) $field,
                'input' => $raw,
                'canonical' => $canonical,
            ];
        }

        return $id;
    }

    /**
     * Resolve name uniquely within a parent scope (Bölüm under Departman, Birim under Bölüm).
     * Parent null with nonblank child → referans bulunamadı (cannot resolve without parent).
     *
     * @param array<int, array<string, list<int>>> $byParent parentId => name => ids
     * @param list<string> $hataKodlari
     * @return int|null
     */
    public static function resolveExactUniqueWithinParent(
        $name,
        array $byParent,
        $parentId,
        $field,
        array &$hataKodlari,
        ?array &$autoMatches = null,
        ?array &$ambiguous = null,
        ?array &$unknownMatches = null
    ) {
        $raw = trim((string) $name);
        if ($raw === '') {
            return null;
        }
        if ($parentId === null || (int) $parentId < 1) {
            $hataKodlari[] = 'PERSONEL_IMPORT_REFERANS_BULUNAMADI';

            return null;
        }
        $parentKey = (int) $parentId;
        if (!isset($byParent[$parentKey]) || !is_array($byParent[$parentKey])) {
            $hataKodlari[] = 'PERSONEL_IMPORT_REFERANS_BULUNAMADI';

            return null;
        }
        $index = $byParent[$parentKey];

        return self::resolveExactUnique($raw, $index, $field, $hataKodlari, $autoMatches, $ambiguous, $unknownMatches);
    }

    /**
     * @return array<string, list<int>>
     */
    public static function loadNameIndex(PDO $pdo, $table): array
    {
        $allowed = [
            'subeler',
            'departmanlar',
            'gorevler',
            'personel_tipleri',
            'sgk_isverenler',
            'calisma_lokasyonlari',
            'pozisyonlar',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Invalid reference table.');
        }

        if ($table === 'subeler') {
            return self::loadSubeNameIndex($pdo);
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

    /**
     * Branch names are the one reference where the short name is not a key:
     * "Ankara" can legitimately exist under two companies. The index therefore
     * carries the canonical company-qualified name ("Medisa Ankara") as the
     * resolvable entry, and keeps the short name pointing at every match so a
     * bare "Ankara" resolves to nothing and raises PERSONEL_IMPORT_REFERANS_BELIRSIZ
     * instead of silently picking one.
     *
     * On a pre-079 / unmapped database the derived name equals the raw name, so
     * the legacy single-key behaviour is preserved exactly.
     *
     * @return array<string, list<int>>
     */
    private static function loadSubeNameIndex(PDO $pdo): array
    {
        $sql = 'SELECT ' . SubeReadModel::selectColumns($pdo)
            . ' FROM subeler s' . SubeReadModel::joinSql($pdo)
            . " WHERE s.durum = 'AKTIF'";

        $stmt = $pdo->query($sql);
        $index = [];
        if (!$stmt) {
            return $index;
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped = SubeReadModel::mapRow($row);
            $id = (int) $mapped['id'];
            foreach ([$mapped['ad'], $mapped['tam_ad']] as $name) {
                $name = (string) $name;
                if ($name === '') {
                    continue;
                }
                if (!isset($index[$name])) {
                    $index[$name] = [];
                }
                if (!in_array($id, $index[$name], true)) {
                    $index[$name][] = $id;
                }
            }
        }

        return $index;
    }

    /**
     * @return array<int, array<string, list<int>>>
     */
    public static function loadChildNameIndexByParent(PDO $pdo, string $table, string $parentColumn): array
    {
        $allowed = [
            'bolumler' => 'departman_id',
            'birimler' => 'bolum_id',
        ];
        if (!isset($allowed[$table]) || $allowed[$table] !== $parentColumn) {
            throw new RuntimeException('Invalid hierarchical reference table.');
        }

        $stmt = $pdo->query(
            "SELECT id, ad, {$parentColumn} AS parent_id FROM {$table} WHERE durum = 'AKTIF'"
        );
        $index = [];
        if (!$stmt) {
            return $index;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            $name = (string) ($row['ad'] ?? '');
            if ($parentId < 1 || $name === '') {
                continue;
            }
            if (!isset($index[$parentId])) {
                $index[$parentId] = [];
            }
            if (!isset($index[$parentId][$name])) {
                $index[$parentId][$name] = [];
            }
            $index[$parentId][$name][] = (int) $row['id'];
        }

        return $index;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function appendHierarchicalBolumRows(PDO $pdo, array &$rows): void
    {
        $stmt = $pdo->query(
            "SELECT b.id, b.ad, b.departman_id, d.ad AS departman_adi
             FROM bolumler b
             INNER JOIN departmanlar d ON d.id = b.departman_id
             WHERE b.durum = 'AKTIF'
             ORDER BY d.ad ASC, b.ad ASC, b.id ASC"
        );
        if (!$stmt) {
            return;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $departmanAdi = (string) ($row['departman_adi'] ?? '');
            $ad = (string) ($row['ad'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $departmanId = (int) ($row['departman_id'] ?? 0);
            $rows[] = [
                'referans_turu' => 'BOLUM',
                'deger' => $ad,
                'bagli_sube' => $departmanAdi,
                'kullanilabilir' => 'EVET',
                'eslesme_sayisi' => '1',
                'uyari_kodu' => '',
                'aciklama' => 'id=' . $id . ';departman_id=' . $departmanId . ';departman_adi=' . $departmanAdi,
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function appendHierarchicalBirimRows(PDO $pdo, array &$rows): void
    {
        $stmt = $pdo->query(
            "SELECT bi.id, bi.ad, bi.bolum_id, b.ad AS bolum_adi, b.departman_id, d.ad AS departman_adi
             FROM birimler bi
             INNER JOIN bolumler b ON b.id = bi.bolum_id
             INNER JOIN departmanlar d ON d.id = b.departman_id
             WHERE bi.durum = 'AKTIF'
             ORDER BY d.ad ASC, b.ad ASC, bi.ad ASC, bi.id ASC"
        );
        if (!$stmt) {
            return;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ad = (string) ($row['ad'] ?? '');
            $bolumAdi = (string) ($row['bolum_adi'] ?? '');
            $departmanAdi = (string) ($row['departman_adi'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $bolumId = (int) ($row['bolum_id'] ?? 0);
            $departmanId = (int) ($row['departman_id'] ?? 0);
            $rows[] = [
                'referans_turu' => 'BIRIM',
                'deger' => $ad,
                'bagli_sube' => $departmanAdi . ' / ' . $bolumAdi,
                'kullanilabilir' => 'EVET',
                'eslesme_sayisi' => '1',
                'uyari_kodu' => '',
                'aciklama' => 'id=' . $id
                    . ';bolum_id=' . $bolumId
                    . ';bolum_adi=' . $bolumAdi
                    . ';departman_id=' . $departmanId
                    . ';departman_adi=' . $departmanAdi,
            ];
        }
    }

    /**
     * @param array<int, int> $allowedSubeIds
     * @return list<int>
     */
    private static function resolveExportSubeIds(PDO $pdo, array $allowedSubeIds, $activeSubeId): array
    {
        if ($activeSubeId !== null) {
            return [(int) $activeSubeId];
        }

        $allActive = [];
        $stmt = $pdo->query("SELECT id FROM subeler WHERE durum = 'AKTIF'");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $allActive[] = (int) $row['id'];
            }
        }

        if (count($allowedSubeIds) === 0) {
            sort($allActive);

            return $allActive;
        }

        $scoped = [];
        foreach ($allActive as $id) {
            if (in_array($id, $allowedSubeIds, true)) {
                $scoped[] = $id;
            }
        }
        sort($scoped);

        return $scoped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, list<int>> $index
     * @param list<int>|null $allowedIds null = no id filter (global catalogs)
     */
    private static function appendNameRows(
        array &$rows,
        string $tur,
        array $index,
        $allowedIds,
        string $bagliSube
    ): void {
        foreach ($index as $name => $ids) {
            // EXPORT_USABILITY = DRY_RUN_RESOLUTION_RESULT:
            // usability/eslesme_sayisi use the full active catalog (same as resolveExactUnique).
            // Scope only decides whether the name appears — never shrinks ambiguity.
            $scopedIds = $ids;
            if (is_array($allowedIds)) {
                $scopedIds = array_values(array_filter($ids, static function ($id) use ($allowedIds) {
                    return in_array((int) $id, $allowedIds, true);
                }));
            }
            if (count($scopedIds) === 0) {
                continue;
            }
            $rows[] = self::buildRow($tur, (string) $name, $bagliSube, $ids);
        }
    }

    /**
     * @param array<string, list<int>> $index
     * @param list<int> $ids
     * @return array<string, string>
     */
    private static function buildRow(string $tur, string $name, string $bagliSube, array $ids): array
    {
        $count = count($ids);
        if ($count !== 1) {
            return [
                'referans_turu' => $tur,
                'deger' => $name,
                'bagli_sube' => $bagliSube,
                'kullanilabilir' => 'HAYIR',
                'eslesme_sayisi' => (string) $count,
                'uyari_kodu' => 'PERSONEL_IMPORT_REFERANS_BELIRSIZ',
                'aciklama' => 'Bu değer birden fazla aktif kayıtla eşleştiği için importta kullanılamaz.',
            ];
        }

        return [
            'referans_turu' => $tur,
            'deger' => $name,
            'bagli_sube' => $bagliSube,
            'kullanilabilir' => 'EVET',
            'eslesme_sayisi' => '1',
            'uyari_kodu' => '',
            'aciklama' => '',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function sortRows(array &$rows): void
    {
        usort($rows, static function (array $a, array $b) {
            $ta = self::TUR_ORDER[(string) ($a['referans_turu'] ?? '')] ?? 99;
            $tb = self::TUR_ORDER[(string) ($b['referans_turu'] ?? '')] ?? 99;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            $ba = (string) ($a['bagli_sube'] ?? '');
            $bb = (string) ($b['bagli_sube'] ?? '');
            $cmpBagli = strcmp($ba, $bb);
            if ($cmpBagli !== 0) {
                return $cmpBagli;
            }
            $da = (string) ($a['deger'] ?? '');
            $db = (string) ($b['deger'] ?? '');
            $cmpDeger = strcmp($da, $db);
            if ($cmpDeger !== 0) {
                return $cmpDeger;
            }

            return strcmp(
                (string) ($a['uyari_kodu'] ?? ''),
                (string) ($b['uyari_kodu'] ?? '')
            );
        });
    }

    /** @param mixed $value */
    private static function parsePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
