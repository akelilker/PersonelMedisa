<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use Medisa\Api\Http\CsvResponse;
use Medisa\Api\Scope\SubeScope;
use PDO;
use PDOException;

/**
 * S98: Company SGK policy package dry-run (no write, no invented defaults).
 */
final class SgkSirketPolitikaImportValidator
{
    public const FILENAME = 'sgk-sirket-politikasi-sablon.csv';
    public const SHA_HEADER = 'X-Sgk-Politika-Sablon-SHA256';

    /** @var list<string> */
    public const CSV_COLUMNS = [
        'sube',
        'surum_kodu',
        'gecerlilik_baslangic',
        'gecerlilik_bitis',
        'bildirim_donem_tipi',
        'politika_kodu',
        'deger',
        'aciklama',
    ];

    /**
     * @param array<string, mixed> $user
     * @return array{filename: string, csv: string, sha256: string, body: string}
     */
    public static function buildTemplateExport(PDO $pdo, array $user): array
    {
        $allowed = SubeScope::allowedSubeIds($user);
        $subeler = self::loadScopedSubeler($pdo, $allowed);
        $rows = [];
        foreach ($subeler as $sube) {
            foreach (SgkSirketPolitikaCatalog::knownCodes() as $code) {
                $rows[] = [
                    'sube' => (string) $sube['label'],
                    'surum_kodu' => '',
                    'gecerlilik_baslangic' => '',
                    'gecerlilik_bitis' => '',
                    'bildirim_donem_tipi' => '',
                    'politika_kodu' => $code,
                    'deger' => '',
                    'aciklama' => '',
                ];
            }
        }
        usort($rows, static fn (array $a, array $b) => [$a['sube'], $a['politika_kodu']] <=> [$b['sube'], $b['politika_kodu']]);

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
     *   sube_id?: int,
     *   sube?: string,
     *   surum_kodu?: string,
     *   gecerlilik_baslangic?: string,
     *   gecerlilik_bitis?: string|null,
     *   bildirim_donem_tipi?: string,
     *   aciklama?: string,
     *   degerler?: array<string,string>,
     *   rows?: list<array<string,mixed>>
     * } $payload
     * @return array<string,mixed>
     */
    public static function dryRun(PDO $pdo, array $payload): array
    {
        $package = self::normalizePackage($payload);
        $hatali = [];
        $uyari = [];

        $subeId = self::resolveSubeId($pdo, $package);
        if ($subeId === null) {
            $hatali[] = ['row_index' => -1, 'errors' => ['SUBE_BULUNAMADI_VEYA_PASIF']];
        }

        $surumKodu = trim((string) ($package['surum_kodu'] ?? ''));
        if ($surumKodu === '') {
            $hatali[] = ['row_index' => -1, 'errors' => ['SURUM_KODU_ZORUNLU']];
        }

        $bas = trim((string) ($package['gecerlilik_baslangic'] ?? ''));
        $bit = $package['gecerlilik_bitis'] ?? null;
        $bit = is_string($bit) && trim($bit) !== '' ? trim($bit) : null;
        if ($bas === '' || !SgkKatalogContracts::isDate($bas)) {
            $hatali[] = ['row_index' => -1, 'errors' => ['GECERSIZ_GECERLILIK_BASLANGIC']];
        }
        if ($bit !== null && !SgkKatalogContracts::isDate($bit)) {
            $hatali[] = ['row_index' => -1, 'errors' => ['GECERSIZ_GECERLILIK_BITIS']];
        }
        if ($bas !== '' && $bit !== null && SgkKatalogContracts::isDate($bas) && SgkKatalogContracts::isDate($bit) && $bit < $bas) {
            $hatali[] = ['row_index' => -1, 'errors' => ['GECERLILIK_TARIH_CAKISMASI']];
        }

        $donemTipi = strtoupper(trim((string) ($package['bildirim_donem_tipi'] ?? '')));
        if ($donemTipi === '' || !in_array($donemTipi, SgkSirketPolitikaCatalog::BILDIRIM_DONEM_TIPLERI, true)) {
            $hatali[] = ['row_index' => -1, 'errors' => ['GECERSIZ_BILDIRIM_DONEM_TIPI']];
        }

        $degerler = is_array($package['degerler'] ?? null) ? $package['degerler'] : [];
        foreach (array_keys($degerler) as $code) {
            if (!SgkSirketPolitikaCatalog::isKnownCode((string) $code)) {
                $hatali[] = ['row_index' => -1, 'errors' => ['BILINMEYEN_POLITIKA_KODU:' . $code]];
            }
        }
        foreach (SgkSirketPolitikaCatalog::knownCodes() as $requiredCode) {
            $def = SgkSirketPolitikaCatalog::definition($requiredCode);
            if (!empty($def['zorunlu'])) {
                $val = trim((string) ($degerler[$requiredCode] ?? ''));
                if ($val === '') {
                    $hatali[] = ['row_index' => -1, 'errors' => ['ZORUNLU_POLITIKA_DEGERI_EKSIK:' . $requiredCode]];
                } elseif (!empty($def['allowed_values']) && is_array($def['allowed_values']) && !in_array($val, $def['allowed_values'], true)) {
                    $hatali[] = ['row_index' => -1, 'errors' => ['GECERSIZ_POLITIKA_DEGERI:' . $requiredCode]];
                }
            }
        }

        $existing = null;
        if ($subeId !== null && $surumKodu !== '') {
            $existing = self::fetchSurum($pdo, $subeId, $surumKodu);
            if ($existing !== null && (string) ($existing['state'] ?? '') === 'ONAYLANDI') {
                $hatali[] = ['row_index' => -1, 'errors' => ['ONAYLI_SURUM_DEGISTIRILEMEZ']];
            }
        }

        $overlap = false;
        if ($subeId !== null && $bas !== '' && SgkKatalogContracts::isDate($bas)) {
            $overlap = self::hasApprovedOverlap($pdo, $subeId, $bas, $bit, $existing !== null ? (int) $existing['id'] : null);
            if ($overlap) {
                $hatali[] = ['row_index' => -1, 'errors' => ['SGK_POLITIKA_TARIH_CAKISMA']];
            }
        }

        $canonical = [
            'sube_id' => $subeId,
            'surum_kodu' => $surumKodu,
            'gecerlilik_baslangic' => $bas,
            'gecerlilik_bitis' => $bit,
            'bildirim_donem_tipi' => $donemTipi,
            'aciklama' => trim((string) ($package['aciklama'] ?? 'S98 sirket SGK politikasi')),
            'degerler' => self::canonicalizeDegerler($degerler),
        ];

        $politikaHash = SgkKatalogContracts::sha256Canonical($canonical);
        if ($existing !== null
            && (string) ($existing['state'] ?? '') === 'TASLAK'
            && (string) ($existing['politika_hash'] ?? '') === $politikaHash) {
            $uyari[] = ['code' => 'TASLAK_IDEMPOTENT_HASH'];
        }

        $importOk = $hatali === [] && $subeId !== null;

        return [
            'mode' => 'DRY_RUN',
            'hatali_satirlar' => $hatali,
            'uyari_satirlari' => $uyari,
            'canonical_payload' => $canonical,
            'politika_hash' => $politikaHash,
            'import_yapilabilir_mi' => $importOk,
            'overlap_var_mi' => $overlap,
            'response_hash' => SgkKatalogContracts::sha256Canonical([
                'politika_hash' => $politikaHash,
                'import_yapilabilir_mi' => $importOk,
            ]),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizePackage(array $payload): array
    {
        if (!empty($payload['rows']) && is_array($payload['rows'])) {
            $first = $payload['rows'][0] ?? [];
            $degerler = is_array($payload['degerler'] ?? null) ? $payload['degerler'] : [];
            foreach ($payload['rows'] as $row) {
                $code = trim((string) ($row['politika_kodu'] ?? ''));
                $val = trim((string) ($row['deger'] ?? ''));
                if ($code !== '') {
                    $degerler[$code] = $val;
                }
            }

            return array_merge($payload, [
                'sube' => $payload['sube'] ?? ($first['sube'] ?? null),
                'surum_kodu' => $payload['surum_kodu'] ?? ($first['surum_kodu'] ?? null),
                'gecerlilik_baslangic' => $payload['gecerlilik_baslangic'] ?? ($first['gecerlilik_baslangic'] ?? null),
                'gecerlilik_bitis' => $payload['gecerlilik_bitis'] ?? ($first['gecerlilik_bitis'] ?? null),
                'bildirim_donem_tipi' => $payload['bildirim_donem_tipi'] ?? ($first['bildirim_donem_tipi'] ?? null),
                'aciklama' => $payload['aciklama'] ?? ($first['aciklama'] ?? null),
                'degerler' => $degerler,
            ]);
        }

        return $payload;
    }

    /** @param array<string, string> $degerler @return array<string, string> */
    private static function canonicalizeDegerler(array $degerler): array
    {
        $out = [];
        foreach ($degerler as $code => $value) {
            $c = strtoupper(trim((string) $code));
            if ($c === '') {
                continue;
            }
            $out[$c] = trim((string) $value);
        }
        ksort($out);

        return $out;
    }

    /** @param array<string,mixed> $package */
    private static function resolveSubeId(PDO $pdo, array $package): ?int
    {
        if (isset($package['sube_id']) && (int) $package['sube_id'] > 0) {
            return self::assertActiveSube($pdo, (int) $package['sube_id']);
        }
        $label = trim((string) ($package['sube'] ?? ''));
        if ($label === '') {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM subeler WHERE durum = 'AKTIF' AND (ad = :label OR kod = :label) LIMIT 2"
            );
            $stmt->execute(['label' => $label]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) {
                return (int) $rows[0]['id'];
            }
        } catch (PDOException $e) {
            return null;
        }

        return null;
    }

    private static function assertActiveSube(PDO $pdo, int $subeId): ?int
    {
        try {
            $stmt = $pdo->prepare("SELECT id FROM subeler WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => $subeId]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int) $id : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private static function fetchSurum(PDO $pdo, int $subeId, string $surumKodu): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM sgk_sirket_politika_surumleri WHERE sube_id = :sube AND surum_kodu = :kodu LIMIT 1'
        );
        $stmt->execute(['sube' => $subeId, 'kodu' => $surumKodu]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function hasApprovedOverlap(PDO $pdo, int $subeId, string $bas, ?string $bit, ?int $excludeId): bool
    {
        $end = $bit ?? '9999-12-31';
        $sql = "SELECT id FROM sgk_sirket_politika_surumleri
                WHERE sube_id = :sube AND state = 'ONAYLANDI'
                  AND gecerlilik_baslangic <= :bitis
                  AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)";
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $params = ['sube' => $subeId, 'baslangic' => $bas, 'bitis' => $end];
        if ($excludeId !== null) {
            $params['exclude'] = $excludeId;
        }
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param list<int> $allowedSubeIds
     * @return list<array{id: int, label: string}>
     */
    private static function loadScopedSubeler(PDO $pdo, array $allowedSubeIds): array
    {
        try {
            if (count($allowedSubeIds) === 0) {
                $stmt = $pdo->query("SELECT id, ad, kod FROM subeler WHERE durum = 'AKTIF' ORDER BY ad ASC");
            } else {
                $ph = implode(',', array_fill(0, count($allowedSubeIds), '?'));
                $stmt = $pdo->prepare("SELECT id, ad, kod FROM subeler WHERE durum = 'AKTIF' AND id IN ($ph) ORDER BY ad ASC");
                $stmt->execute($allowedSubeIds);
            }
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'label' => (string) ($row['ad'] ?? $row['kod'] ?? ''),
            ];
        }

        return $out;
    }
}
