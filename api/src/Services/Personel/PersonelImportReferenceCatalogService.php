<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\CsvResponse;
use Medisa\Api\Scope\SubeScope;
use PDO;
use RuntimeException;
use Throwable;

/**
 * S97-D: Shared personel-import reference catalog (dry-run + export owner).
 * Read-only. Never queries personeller. Never writes.
 */
final class PersonelImportReferenceCatalogService
{
    public const FILENAME = 'personel-import-referanslari.csv';
    public const OPEN_BAGLI_SUBE = 'TUM_YETKILI_SUBELER';
    public const SHA_HEADER = 'X-Personel-Import-Reference-SHA256';

    public const CSV_COLUMNS = [
        'referans_turu',
        'deger',
        'bagli_sube',
        'kullanilabilir',
        'eslesme_sayisi',
        'uyari_kodu',
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

        $rows = [];

        self::appendNameRows($rows, 'SUBE', $subeIndex, $scopeSubeIds, '');
        // OPEN_BRANCH_DEPARTMENT: active departments are independent of sube_departmanlar.
        self::appendNameRows($rows, 'DEPARTMAN', $departmanIndex, null, self::OPEN_BAGLI_SUBE);
        self::appendNameRows($rows, 'GOREV', $gorevIndex, null, '');
        self::appendNameRows($rows, 'PERSONEL_TIPI', $personelTipiIndex, null, '');

        if (PersonelOrgLocationSchema::isReady($pdo)) {
            $sgkIsverenIndex = self::loadNameIndex($pdo, 'sgk_isverenler');
            $calismaLokasyonuIndex = self::loadNameIndex($pdo, 'calisma_lokasyonlari');
            self::appendNameRows($rows, 'SGK_ISVEREN', $sgkIsverenIndex, null, '');
            self::appendNameRows($rows, 'CALISMA_LOKASYONU', $calismaLokasyonuIndex, null, '');
        }

        if (PersonelOrgStructureSchema::isReady($pdo)) {
            self::appendHierarchicalBolumRows($pdo, $rows);
            self::appendHierarchicalBirimRows($pdo, $rows);
            $pozisyonIndex = self::loadNameIndex($pdo, 'pozisyonlar');
            self::appendNameRows($rows, 'POZISYON', $pozisyonIndex, null, '');
        }

        $rows[] = [
            'referans_turu' => 'CALISAN_KAPSAMI',
            'deger' => PersonelCalisanKapsamService::IC_PERSONEL,
            'bagli_sube' => '',
            'kullanilabilir' => 'EVET',
            'eslesme_sayisi' => '1',
            'uyari_kodu' => '',
            'aciklama' => 'Ic Personel (varsayilan; kolon yoksa IC_PERSONEL).',
        ];
        $rows[] = [
            'referans_turu' => 'CALISAN_KAPSAMI',
            'deger' => PersonelCalisanKapsamService::DIS_KAYNAK,
            'bagli_sube' => '',
            'kullanilabilir' => 'EVET',
            'eslesme_sayisi' => '1',
            'uyari_kodu' => '',
            'aciklama' => 'Dis Kaynak / SGK Baska Isverende. Dizin kaydi; TC/soyad/dogum/telefon opsiyonel.',
        ];

        self::sortRows($rows);

        $body = CsvResponse::buildSemicolon(self::CSV_COLUMNS, $rows);
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
    public static function resolveExactUnique($name, array $index, $field, array &$hataKodlari)
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
        array &$hataKodlari
    ) {
        $key = trim((string) $name);
        if ($key === '') {
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
