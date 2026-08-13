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
        'GOREV' => 3,
        'PERSONEL_TIPI' => 4,
        'SABIT_DEGER' => 5,
    ];

    /**
     * Catalog shape used by dry-run resolve/link checks.
     *
     * @return array{
     *   sube: array<string, list<int>>,
     *   departman: array<string, list<int>>,
     *   gorev: array<string, list<int>>,
     *   personel_tipi: array<string, list<int>>,
     *   sube_departman: array<string, true>
     * }
     */
    public static function loadCatalogForDryRun(PDO $pdo): array
    {
        // Mapping query failure must fail-closed (not silent open model).
        $pairs = self::loadSubeDepartmanPairsStrict($pdo)['pairs'];

        return [
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
            'sube_departman' => $pairs,
        ];
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

        $pairResult = self::loadSubeDepartmanPairsStrict($pdo);
        $pairs = $pairResult['pairs'];
        $mappingMode = count($pairs) === 0 ? 'open' : 'mapped';

        $idToSubeName = self::buildIdToUniqueName($subeIndex);
        $rows = [];

        self::appendNameRows($rows, 'SUBE', $subeIndex, $scopeSubeIds, '');
        self::appendDepartmanRows(
            $rows,
            $departmanIndex,
            $scopeSubeIds,
            $idToSubeName,
            $pairs,
            $mappingMode
        );
        self::appendNameRows($rows, 'GOREV', $gorevIndex, null, '');
        self::appendNameRows($rows, 'PERSONEL_TIPI', $personelTipiIndex, null, '');

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
     * @param array<string, mixed> $catalog
     */
    public static function isSubeDepartmanLinked($subeId, $departmanId, array $catalog): bool
    {
        $pairs = $catalog['sube_departman'] ?? [];
        if (!is_array($pairs) || count($pairs) === 0) {
            // No mapping rows → treat as open (matches create which only checks AKTIF FKs).
            return true;
        }

        $key = ((int) $subeId) . ':' . ((int) $departmanId);

        return isset($pairs[$key]);
    }

    /**
     * @return array<string, list<int>>
     */
    public static function loadNameIndex(PDO $pdo, $table): array
    {
        $allowed = ['subeler', 'departmanlar', 'gorevler', 'personel_tipleri', 'sgk_isverenler', 'calisma_lokasyonlari'];
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

    /** @return array{pairs: array<string, true>} */
    public static function loadSubeDepartmanPairsStrict(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT sube_id, departman_id FROM sube_departmanlar');
            if (!$stmt) {
                throw new RuntimeException('sube_departmanlar query failed');
            }
            $pairs = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = ((int) $row['sube_id']) . ':' . ((int) $row['departman_id']);
                $pairs[$key] = true;
            }

            return ['pairs' => $pairs];
        } catch (Throwable $e) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_REFERANS_PAKETI_HAZIRLANAMADI',
                'Personel import referans paketi hazirlanamadi.',
                409
            );
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
     * @param list<array<string, mixed>> $rows
     * @param array<string, list<int>> $departmanIndex
     * @param list<int> $scopeSubeIds
     * @param array<int, string> $idToSubeName
     * @param array<string, true> $pairs
     */
    private static function appendDepartmanRows(
        array &$rows,
        array $departmanIndex,
        array $scopeSubeIds,
        array $idToSubeName,
        array $pairs,
        string $mappingMode
    ): void {
        if ($mappingMode === 'open') {
            self::appendNameRows($rows, 'DEPARTMAN', $departmanIndex, null, self::OPEN_BAGLI_SUBE);

            return;
        }

        $idToDepartmanName = self::buildIdToUniqueName($departmanIndex);
        $ambiguousNames = [];
        foreach ($departmanIndex as $name => $ids) {
            if (count($ids) !== 1) {
                $ambiguousNames[$name] = count($ids);
            }
        }

        foreach ($ambiguousNames as $name => $count) {
            $rows[] = [
                'referans_turu' => 'DEPARTMAN',
                'deger' => (string) $name,
                'bagli_sube' => '',
                'kullanilabilir' => 'HAYIR',
                'eslesme_sayisi' => (string) (int) $count,
                'uyari_kodu' => 'PERSONEL_IMPORT_REFERANS_BELIRSIZ',
                'aciklama' => 'Bu değer birden fazla aktif kayıtla eşleştiği için importta kullanılamaz.',
            ];
        }

        $emitted = [];
        foreach ($scopeSubeIds as $subeId) {
            $subeName = $idToSubeName[(int) $subeId] ?? null;
            if ($subeName === null || $subeName === '') {
                continue;
            }
            foreach ($pairs as $pairKey => $_) {
                $parts = explode(':', (string) $pairKey, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                if ((int) $parts[0] !== (int) $subeId) {
                    continue;
                }
                $departmanId = (int) $parts[1];
                $departmanName = $idToDepartmanName[$departmanId] ?? null;
                if ($departmanName === null || $departmanName === '') {
                    continue;
                }
                if (isset($ambiguousNames[$departmanName])) {
                    continue;
                }
                $dedupe = $departmanName . "\0" . $subeName;
                if (isset($emitted[$dedupe])) {
                    continue;
                }
                $emitted[$dedupe] = true;
                $rows[] = [
                    'referans_turu' => 'DEPARTMAN',
                    'deger' => $departmanName,
                    'bagli_sube' => $subeName,
                    'kullanilabilir' => 'EVET',
                    'eslesme_sayisi' => '1',
                    'uyari_kodu' => '',
                    'aciklama' => '',
                ];
            }
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

    /**
     * @param array<string, list<int>> $index
     * @return array<int, string>
     */
    private static function buildIdToUniqueName(array $index): array
    {
        $map = [];
        foreach ($index as $name => $ids) {
            if (count($ids) !== 1) {
                continue;
            }
            $map[(int) $ids[0]] = (string) $name;
        }

        return $map;
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
