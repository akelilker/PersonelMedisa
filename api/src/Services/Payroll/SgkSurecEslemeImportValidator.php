<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use Medisa\Api\Http\CsvResponse;
use PDO;
use PDOException;

require_once __DIR__ . '/SgkEslemeKararContract.php';
require_once __DIR__ . '/SgkKatalogContracts.php';

/**
 * S98-R1: Deterministic süreç→SGK mapping import dry-run (no write).
 * Supports no-code DAHIL, conditional wage/mazeret rules, dynamic codes.
 * Never mutates ONAYLANDI parent catalog rows.
 */
final class SgkSurecEslemeImportValidator
{
    public const FILENAME = 'sgk-surec-esleme-sablon.csv';
    public const SHA_HEADER = 'X-Sgk-Esleme-Sablon-SHA256';

    /** @var list<string> */
    public const CSV_COLUMNS = [
        'surec_turu',
        'alt_tur',
        'canonical_surec_turu',
        'karar_kurali',
        'kod_secim_modu',
        'eksik_gun_kodu',
        'kaynak_referansi',
    ];

    /**
     * Hardcoded raw inventory from kayit-surec-constants (+ PUANTAJ_EKSIK_GUN / KISMI wildcards).
     *
     * @return list<array{surec_turu: string, alt_tur: string}>
     */
    public static function rawSurecInventory(): array
    {
        $rows = [
            ['surec_turu' => 'IZIN', 'alt_tur' => 'YILLIK_IZIN'],
            ['surec_turu' => 'IZIN', 'alt_tur' => 'MAZERET_IZNI'],
            ['surec_turu' => 'IZIN', 'alt_tur' => 'UCRETSIZ_IZIN'],
            ['surec_turu' => 'RAPOR', 'alt_tur' => 'Raporlu_Hastalik'],
            ['surec_turu' => 'RAPOR', 'alt_tur' => 'Raporlu_Meslek_Hastaligi'],
            ['surec_turu' => 'RAPOR', 'alt_tur' => 'Raporlu_Analik'],
            ['surec_turu' => 'IS_KAZASI', 'alt_tur' => 'IS_KAZASI_BILDIRIMI'],
            ['surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'IZINSIZ_GELMEDI'],
            ['surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETLI_GEC_GELDI'],
            ['surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETSIZ_GEC_GELDI'],
            ['surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETLI_ERKEN_CIKTI'],
            ['surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETSIZ_ERKEN_CIKTI'],
            ['surec_turu' => 'PUANTAJ_EKSIK_GUN', 'alt_tur' => '*'],
            ['surec_turu' => 'KISMI', 'alt_tur' => '*'],
        ];
        usort($rows, static fn (array $a, array $b) => [$a['surec_turu'], $a['alt_tur']] <=> [$b['surec_turu'], $b['alt_tur']]);

        return $rows;
    }

    /**
     * @return array{filename: string, csv: string, sha256: string, body: string}
     */
    public static function buildTemplateExport(): array
    {
        $rows = [];
        foreach (self::rawSurecInventory() as $item) {
            $rows[] = array_merge($item, [
                'canonical_surec_turu' => '',
                'karar_kurali' => '',
                'kod_secim_modu' => '',
                'eksik_gun_kodu' => '',
                'kaynak_referansi' => '',
            ]);
        }
        $body = CsvResponse::buildSemicolon(self::CSV_COLUMNS, $rows);
        $sha256 = hash('sha256', $body);

        return [
            'filename' => self::FILENAME,
            'body' => $body,
            'csv' => "\xEF\xBB\xBF" . $body,
            'sha256' => $sha256,
        ];
    }

    /**
     * @param array{
     *   rows?: list<array<string,mixed>>,
     *   parent_surum_kodu?: string,
     *   successor_surum_kodu?: string|null,
     *   manifests?: list<array<string,mixed>>,
     *   target_katalog_surum_id?: int|null
     * } $payload
     * @return array<string,mixed>
     */
    public static function dryRun(PDO $pdo, array $payload): array
    {
        $rows = array_values($payload['rows'] ?? []);
        $parentKodu = trim((string) ($payload['parent_surum_kodu'] ?? ''));
        $successorKodu = trim((string) ($payload['successor_surum_kodu'] ?? ''));
        $targetId = isset($payload['target_katalog_surum_id']) ? (int) $payload['target_katalog_surum_id'] : null;

        $manifestIndex = self::indexManifests($pdo, $payload['manifests'] ?? []);
        $inventory = self::inventoryIndex();

        $parent = $parentKodu !== '' ? self::fetchParentCatalog($pdo, $parentKodu) : null;
        $parentCodes = $parent !== null ? self::loadParentCodes($pdo, (int) $parent['id']) : [];

        $hatali = [];
        $uyari = [];
        $canonical = [];
        $seenKeys = [];
        $decisionPending = 0;

        if ($targetId !== null && $targetId > 0) {
            $target = self::fetchSurumById($pdo, $targetId);
            if ($target !== null && (string) ($target['state'] ?? '') === 'ONAYLANDI') {
                $hatali[] = [
                    'row_index' => -1,
                    'errors' => ['ONAYLI_KATALOG_DOGRUDAN_YAZMA_YASAK'],
                ];
            }
        }

        if ($parentKodu !== '' && $parent === null) {
            $hatali[] = [
                'row_index' => -1,
                'errors' => ['PARENT_SURUM_BULUNAMADI'],
            ];
        } elseif ($parent !== null && (string) ($parent['state'] ?? '') !== 'ONAYLANDI') {
            $hatali[] = [
                'row_index' => -1,
                'errors' => ['PARENT_SURUM_ONAYLI_DEGIL'],
            ];
        }

        foreach ($rows as $index => $row) {
            $errors = [];
            $warnings = [];

            $surec = strtoupper(trim((string) ($row['surec_turu'] ?? '')));
            $alt = trim((string) ($row['alt_tur'] ?? '*'));
            if ($alt === '') {
                $alt = '*';
            }

            $invKey = $surec . '|' . $alt;
            if ($surec === '' || !isset($inventory[$invKey])) {
                if ($surec !== '' && self::isWildcardInventoryMatch($surec, $alt, $inventory)) {
                    // allowed via wildcard inventory row
                } elseif ($surec === '' && $alt === '*') {
                    $errors[] = 'EKSIK_SUREC_TURU';
                } elseif ($surec !== '' && isset($inventory[$surec . '|*'])) {
                    // raw surec with custom alt under wildcard-capable tur
                } else {
                    $errors[] = 'BILINMEYEN_RAW_SUREC';
                }
            }

            $canonicalTur = strtoupper(trim((string) ($row['canonical_surec_turu'] ?? '')));
            $kararKurali = strtoupper(trim((string) ($row['karar_kurali'] ?? '')));
            $kodModu = strtoupper(trim((string) ($row['kod_secim_modu'] ?? '')));
            $eksikKod = strtoupper(trim((string) ($row['eksik_gun_kodu'] ?? '')));
            $kaynakRef = trim((string) ($row['kaynak_referansi'] ?? ''));

            // Legacy CSV compatibility: map old prim_gunu_etkisi columns if new columns empty.
            if ($kararKurali === '' && $kodModu === '') {
                $legacyPrim = strtoupper(trim((string) ($row['prim_gunu_etkisi'] ?? '')));
                $legacyCozulmus = strtoupper(trim((string) ($row['cozulmus_prim_gunu_etkisi'] ?? '')));
                if ($legacyPrim === 'DAHIL') {
                    $kararKurali = 'HER_ZAMAN_DAHIL';
                    $kodModu = 'KOD_YOK';
                } elseif ($legacyPrim === 'DUSUR') {
                    $kararKurali = 'HER_ZAMAN_DUSUR';
                    $kodModu = 'SABIT_KOD';
                } elseif ($legacyPrim === 'KOSULLU' && $legacyCozulmus !== '') {
                    $kararKurali = 'HER_ZAMAN_DUSUR';
                    $kodModu = $eksikKod !== '' ? 'SABIT_KOD' : 'KOD_YOK';
                    $warnings[] = 'LEGACY_KOSULLU_SATIRI_YENI_KURALA_CEVIRIN';
                }
            }

            $decisionEmpty = $canonicalTur === '' && $kararKurali === '' && $kodModu === '' && $eksikKod === '' && $kaynakRef === '';
            if ($decisionEmpty) {
                $decisionPending++;
                $warnings[] = 'KARAR_BEKLIYOR';
                $uyari[] = [
                    'row_index' => $index,
                    'surec_turu' => $surec,
                    'alt_tur' => $alt,
                    'warnings' => $warnings,
                ];
                continue;
            }

            if ($canonicalTur === '' || !in_array($canonicalTur, SgkKatalogContracts::CANONICAL_SUREC_TURLERI, true)) {
                $errors[] = 'GECERSIZ_CANONICAL_SUREC_TURU';
            }

            $normalized = SgkEslemeKararContract::normalize([
                'karar_kurali' => $kararKurali,
                'kod_secim_modu' => $kodModu,
                'eksik_gun_kodu' => $eksikKod,
            ]);
            foreach ($normalized['errors'] as $normErr) {
                $errors[] = $normErr;
            }
            $resolvedKod = $normalized['eksik_gun_kodu'];
            if ($resolvedKod !== null && $parentCodes !== [] && !isset($parentCodes[$resolvedKod])) {
                $errors[] = 'PARENT_KATALOG_KODU_YOK';
            }
            if ($kaynakRef === '') {
                $errors[] = 'KAYNAK_REFERANSI_ZORUNLU';
            } elseif (!isset($manifestIndex[$kaynakRef])) {
                $errors[] = 'KAYNAK_MANIFEST_COZULEMEDI';
            }

            $dupKey = $surec . '|' . $alt;
            if (isset($seenKeys[$dupKey])) {
                $errors[] = 'DUPLICATE_SUREC_ALT_TUR';
            }
            $seenKeys[$dupKey] = $index;

            if ($surec !== '' && $alt !== '*' && isset($seenKeys[$surec . '|*'])) {
                $warnings[] = 'WILDCARD_EXACT_CAKISMA_EXACT_ONCELikLI';
            }
            if ($surec !== '' && $alt === '*' && self::hasExactSibling($seenKeys, $surec)) {
                $warnings[] = 'WILDCARD_EXACT_CAKISMA_EXACT_ONCELikLI';
            }

            if ($errors !== []) {
                $hatali[] = [
                    'row_index' => $index,
                    'surec_turu' => $surec,
                    'alt_tur' => $alt,
                    'errors' => array_values(array_unique($errors)),
                ];
                continue;
            }

            $canonical[] = [
                'surec_turu' => $surec,
                'alt_tur' => $alt,
                'canonical_surec_turu' => $canonicalTur,
                'karar_kurali' => $kararKurali,
                'kod_secim_modu' => $kodModu,
                'eksik_gun_kodu' => $resolvedKod,
                'prim_gunu_etkisi' => $normalized['prim_gunu_etkisi'],
                'kaynak_referansi' => $kaynakRef,
                'kaynak_manifest_id' => (int) $manifestIndex[$kaynakRef]['id'],
                'kosullar_json' => $normalized['kosullar_json'],
            ];

            if ($warnings !== []) {
                $uyari[] = [
                    'row_index' => $index,
                    'surec_turu' => $surec,
                    'alt_tur' => $alt,
                    'warnings' => $warnings,
                ];
            }
        }

        usort($canonical, static fn (array $a, array $b) => [$a['surec_turu'], $a['alt_tur']] <=> [$b['surec_turu'], $b['alt_tur']]);

        $parentHash = $parent !== null ? (string) ($parent['katalog_payload_hash'] ?? '') : '';
        $eslemeHash = SgkKatalogContracts::sha256Canonical([
            'rows' => $canonical,
            'parent_surum_kodu' => $parentKodu,
            'parent_katalog_payload_hash' => $parentHash,
            'successor_surum_kodu' => $successorKodu !== '' ? $successorKodu : null,
        ]);

        $structuralOk = $hatali === [] && $parent !== null;
        $applyOk = $structuralOk && $decisionPending === 0 && $canonical !== [];

        return [
            'mode' => 'DRY_RUN',
            'hatali_satirlar' => $hatali,
            'uyari_satirlari' => $uyari,
            'canonical_rows' => $canonical,
            'esleme_payload_hash' => $eslemeHash,
            'parent_surum' => $parent !== null ? [
                'id' => (int) $parent['id'],
                'surum_kodu' => (string) $parent['surum_kodu'],
                'state' => (string) $parent['state'],
                'katalog_payload_hash' => $parentHash,
                'kod_sayisi' => count($parentCodes),
            ] : null,
            'successor_surum_kodu' => $successorKodu !== '' ? $successorKodu : null,
            'apply_yapilabilir_mi' => $applyOk,
            'decision_pending_count' => $decisionPending,
            'response_hash' => SgkKatalogContracts::sha256Canonical([
                'esleme_payload_hash' => $eslemeHash,
                'apply_yapilabilir_mi' => $applyOk,
            ]),
        ];
    }

    /** @return array<string, true> */
    private static function inventoryIndex(): array
    {
        $out = [];
        foreach (self::rawSurecInventory() as $item) {
            $out[$item['surec_turu'] . '|' . $item['alt_tur']] = true;
        }

        return $out;
    }

    private static function isWildcardInventoryMatch(string $surec, string $alt, array $inventory): bool
    {
        if (isset($inventory[$surec . '|*'])) {
            return true;
        }

        return isset($inventory[$surec . '|' . $alt]);
    }

    /** @param array<string, int> $seen */
    private static function hasExactSibling(array $seen, string $surec): bool
    {
        foreach (array_keys($seen) as $key) {
            if (strpos($key, $surec . '|') === 0 && substr($key, -2) !== '|*') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $manifests
     * @return array<string, array{id: int, kaynak_id: string, durum: string}>
     */
    private static function indexManifests(PDO $pdo, array $manifests): array
    {
        $index = [];
        foreach ($manifests as $m) {
            $kid = (string) ($m['kaynak_id'] ?? $m['id'] ?? '');
            if ($kid === '') {
                continue;
            }
            $index[$kid] = [
                'id' => (int) ($m['db_id'] ?? $m['manifest_id'] ?? 0),
                'kaynak_id' => $kid,
                'durum' => strtoupper((string) ($m['durum'] ?? 'AKTIF')),
            ];
        }

        try {
            $stmt = $pdo->query("SELECT id, kaynak_id, durum FROM sgk_kaynak_manifestleri WHERE durum = 'AKTIF'");
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                $kid = (string) ($row['kaynak_id'] ?? '');
                if ($kid === '') {
                    continue;
                }
                $index[$kid] = [
                    'id' => (int) $row['id'],
                    'kaynak_id' => $kid,
                    'durum' => (string) ($row['durum'] ?? 'AKTIF'),
                ];
            }
            $resolve = $pdo->prepare('SELECT id, kaynak_id, durum FROM sgk_kaynak_manifestleri WHERE kaynak_id = :kid LIMIT 1');
            foreach (array_keys($index) as $key) {
                if ((int) ($index[$key]['id'] ?? 0) > 0) {
                    continue;
                }
                $resolve->execute(['kid' => $key]);
                $row = $resolve->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $index[$key] = [
                        'id' => (int) $row['id'],
                        'kaynak_id' => (string) $row['kaynak_id'],
                        'durum' => (string) ($row['durum'] ?? 'AKTIF'),
                    ];
                }
            }
        } catch (PDOException $e) {
            // fail-closed below when kaynak cannot resolve
        }

        foreach ($index as $key => $entry) {
            if ((int) $entry['id'] <= 0) {
                unset($index[$key]);
            }
        }

        return $index;
    }

    /** @return array<string,mixed>|null */
    private static function fetchParentCatalog(PDO $pdo, string $surumKodu): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM sgk_eksik_gun_katalog_surumleri
             WHERE surum_kodu = :kodu AND state = 'ONAYLANDI'
             LIMIT 1"
        );
        $stmt->execute(['kodu' => $surumKodu]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private static function fetchSurumById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM sgk_eksik_gun_katalog_surumleri WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, true> */
    private static function loadParentCodes(PDO $pdo, int $parentId): array
    {
        $stmt = $pdo->prepare(
            'SELECT eksik_gun_kodu FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = :id'
        );
        $stmt->execute(['id' => $parentId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kod = strtoupper(trim((string) ($row['eksik_gun_kodu'] ?? '')));
            if ($kod !== '') {
                $out[$kod] = true;
            }
        }

        return $out;
    }
}
