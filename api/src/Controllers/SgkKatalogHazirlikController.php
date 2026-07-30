<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Payroll\SgkCokluNedenValidator;
use Medisa\Api\Services\Payroll\SgkKatalogImportValidator;
use Medisa\Api\Services\Payroll\SgkKatalogOnayService;
use Medisa\Api\Services\Payroll\SgkKatalogPreviewService;
use Medisa\Api\Services\Payroll\SgkKatalogTamlikService;
use Medisa\Api\Services\Payroll\SgkKaynakManifestReader;
use Medisa\Api\Services\Payroll\SgkKatalogWriteService;
use Medisa\Api\Services\Payroll\SgkOperasyonelKanitBase64Guard;
use Medisa\Api\Services\Payroll\SgkOperasyonelKanitValidator;
use Medisa\Api\Services\Payroll\SgkSurecKodEslemeValidator;
use PDO;
use RuntimeException;

/**
 * S85-C1 / S106: SGK catalog readiness endpoints. Write activated for GENEL_YONETICI when tamlik allows.
 */
class SgkKatalogHazirlikController
{
    public static function tamlik(Request $request)
    {
        [$pdo] = self::context($request, 'bordro_on_izleme.view');
        $body = self::jsonBody($request);
        $hasExplicitPackage = !empty($body['kod_satirlari'])
            || !empty($body['rows'])
            || isset($body['tamlik_input']);
        if (!$hasExplicitPackage) {
            $stored = SgkKatalogWriteService::storedApprovedTamlik($pdo);
            if ($stored !== null) {
                JsonResponse::success($stored);
                return;
            }
        }
        if (empty($body['manifests'])) {
            $body['manifests'] = self::loadManifests($pdo, 'tamlik');
        }
        $body['katalog_surumu'] = $body['katalog_surumu'] ?? '';
        $body['kod_satirlari'] = $body['kod_satirlari'] ?? [];
        JsonResponse::success(SgkKatalogTamlikService::evaluate($body));
    }

    public static function manifests(Request $request)
    {
        [$pdo] = self::context($request, 'bordro_on_izleme.view');
        $items = self::loadManifests($pdo, 'kaynaklar');
        $page = max(1, (int) $request->getQuery('page', 1));
        $limit = min(100, max(1, (int) $request->getQuery('limit', 50)));
        $offset = ($page - 1) * $limit;
        $slice = array_slice($items, $offset, $limit);
        JsonResponse::success([
            'items' => $slice,
            'page' => $page,
            'limit' => $limit,
            'total' => count($items),
            'seed_var_mi' => false,
            'response_hash' => hash('sha256', json_encode(['total' => count($items), 'ids' => array_column($slice, 'kaynak_id')], JSON_UNESCAPED_UNICODE)),
        ]);
    }

    public static function manifestDetail(Request $request, $kaynakId)
    {
        [$pdo] = self::context($request, 'bordro_on_izleme.view');
        foreach (self::loadManifests($pdo, 'kaynaklar_detail') as $item) {
            if ((string) $item['kaynak_id'] === (string) $kaynakId) {
                JsonResponse::success($item);
                return;
            }
        }
        JsonResponse::error(404, 'SGK_KAYNAK_BULUNAMADI', 'Kaynak manifesti bulunamadi.');
    }

    public static function surumler(Request $request)
    {
        [$pdo] = self::context($request, 'bordro_on_izleme.view');
        try {
            $stmt = $pdo->query(
                "SELECT s.id, s.surum_kodu, s.state, s.tamlik_durumu, s.gecerlilik_baslangic, s.gecerlilik_bitis,
                        s.katalog_payload_hash, s.manifest_set_hash, s.onay_zamani,
                        (SELECT COUNT(*) FROM sgk_eksik_gun_kodlari k WHERE k.katalog_surum_id = s.id) AS kod_sayisi
                 FROM sgk_eksik_gun_katalog_surumleri s
                 ORDER BY s.id DESC"
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\PDOException $e) {
            JsonResponse::error(503, 'SGK_KATALOG_SURUM_OKUNAMADI', 'SGK katalog surumleri okunamadi.');
            return;
        }
        $items = [];
        $dogrulanmisTam = false;
        foreach ($rows as $row) {
            $tamlik = (string) ($row['tamlik_durumu'] ?? '');
            if ($tamlik === 'DOGRULANMIS_TAM') {
                $dogrulanmisTam = true;
            }
            $items[] = [
                'id' => (int) $row['id'],
                'surum_kodu' => (string) ($row['surum_kodu'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'tamlik_durumu' => $tamlik,
                'kod_sayisi' => (int) ($row['kod_sayisi'] ?? 0),
                'gecerlilik_baslangic' => $row['gecerlilik_baslangic'] ?? null,
                'gecerlilik_bitis' => $row['gecerlilik_bitis'] ?? null,
                'katalog_payload_hash' => $row['katalog_payload_hash'] ?? null,
                'manifest_set_hash' => $row['manifest_set_hash'] ?? null,
                'onay_zamani' => $row['onay_zamani'] ?? null,
            ];
        }
        JsonResponse::success([
            'items' => $items,
            'total' => count($items),
            'dogrulanmis_tam_var_mi' => $dogrulanmisTam,
            'response_hash' => hash(
                'sha256',
                json_encode(['total' => count($items), 'ids' => array_column($items, 'id')], JSON_UNESCAPED_UNICODE)
            ),
        ]);
    }

    public static function importDryRun(Request $request)
    {
        [$pdo] = self::context($request, 'mevzuat_parametreleri.view');
        $body = self::jsonBody($request);
        if (empty($body['manifests'])) {
            $body['manifests'] = self::loadManifests($pdo, 'import_dry_run');
        }
        JsonResponse::success(SgkKatalogImportValidator::dryRun($body));
    }

    public static function import(Request $request)
    {
        [$pdo, $user] = self::writeContext($request);
        $body = self::jsonBody($request);
        if (empty($body['manifests'])) {
            $body['manifests'] = self::loadManifests($pdo, 'import');
        }
        try {
            $result = SgkKatalogWriteService::import($pdo, $user, $body);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'SGK_KATALOG_WRITE_FORBIDDEN') {
                JsonResponse::error(403, 'SGK_KATALOG_WRITE_FORBIDDEN', 'SGK katalog yazma yalniz GENEL_YONETICI icindir.');
            }
            throw $e;
        }
        $status = (int) ($result['http_status'] ?? 200);
        if ($status >= 400) {
            JsonResponse::error($status, (string) ($result['code'] ?? 'SGK_KATALOG_IMPORT_HATASI'), (string) ($result['message'] ?? 'Import basarisiz.'), null, $result);
        }
        JsonResponse::success($result);
    }

    public static function submit(Request $request)
    {
        [$pdo, $user] = self::writeContext($request);
        $body = self::jsonBody($request);
        if (empty($body['manifests'])) {
            $body['manifests'] = self::loadManifests($pdo, 'submit');
        }
        try {
            $result = SgkKatalogWriteService::submit($pdo, $user, $body);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'SGK_KATALOG_WRITE_FORBIDDEN') {
                JsonResponse::error(403, 'SGK_KATALOG_WRITE_FORBIDDEN', 'SGK katalog yazma yalniz GENEL_YONETICI icindir.');
            }
            throw $e;
        }
        $status = (int) ($result['http_status'] ?? 200);
        if ($status >= 400) {
            JsonResponse::error($status, (string) ($result['code'] ?? 'SGK_KATALOG_SUBMIT_HATASI'), (string) ($result['message'] ?? 'Submit basarisiz.'), null, $result);
        }
        JsonResponse::success($result);
    }

    public static function approve(Request $request)
    {
        [$pdo, $user] = self::writeContext($request);
        $body = self::jsonBody($request);
        if (empty($body['manifests'])) {
            $body['manifests'] = self::loadManifests($pdo, 'approve');
        }
        try {
            $result = SgkKatalogWriteService::approve($pdo, $user, $body);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'SGK_KATALOG_WRITE_FORBIDDEN') {
                JsonResponse::error(403, 'SGK_KATALOG_WRITE_FORBIDDEN', 'SGK katalog yazma yalniz GENEL_YONETICI icindir.');
            }
            throw $e;
        }
        $status = (int) ($result['http_status'] ?? 200);
        if ($status >= 400) {
            JsonResponse::error($status, (string) ($result['code'] ?? 'SGK_KATALOG_APPROVE_HATASI'), (string) ($result['message'] ?? 'Approve basarisiz.'), null, $result);
        }
        JsonResponse::success($result);
    }

    public static function surecEslemeValidate(Request $request)
    {
        self::context($request, 'bordro_on_izleme.view');
        JsonResponse::success(SgkSurecKodEslemeValidator::validate(self::jsonBody($request)));
    }

    public static function cokluNedenValidate(Request $request)
    {
        self::context($request, 'bordro_on_izleme.view');
        JsonResponse::success(SgkCokluNedenValidator::validate(self::jsonBody($request)));
    }

    public static function blockerReport(Request $request)
    {
        [$pdo] = self::context($request, 'bordro_on_izleme.view');
        $tamlik = SgkKatalogTamlikService::evaluate([
            'manifests' => self::loadManifests($pdo, 'blocker_raporu'),
            'kod_satirlari' => [],
            'ebildirge_guncel_gorunum_dogrulandi_mi' => false,
        ]);
        $kismi = SgkKatalogPreviewService::kismiSureliPreview([]);
        $bildirim = SgkKatalogPreviewService::bildirimDonemiPreview([]);
        $esleme = SgkSurecKodEslemeValidator::validate(['surec_turu' => 'RAPOR', 'alt_tur' => 'Raporlu_Hastalik', 'mappings' => []]);
        $coklu = SgkCokluNedenValidator::validate(['kodlar' => ['01', '15'], 'kurallar' => []]);

        $all = array_merge(
            $tamlik['blocker_detaylari'] ?? [],
            $kismi['blocker_detaylari'] ?? [],
            $bildirim['blocker_detaylari'] ?? [],
            $esleme['blocker_detaylari'] ?? [],
            $coklu['blocker_detaylari'] ?? []
        );
        $codes = array_values(array_unique(array_map(static fn (array $b) => $b['code'], $all)));
        sort($codes);

        JsonResponse::success([
            'blocker_kodlari' => $codes,
            'blocker_detaylari' => $all,
            'tamlik' => $tamlik,
            'approve_disabled_mi' => empty($tamlik['approve_aktif_mi']),
            'import_write_disabled_mi' => empty($tamlik['import_yazma_aktif_mi']),
            'response_hash' => hash('sha256', json_encode($codes, JSON_UNESCAPED_UNICODE)),
        ]);
    }

    public static function operasyonelKanitValidate(Request $request)
    {
        self::context($request, 'mevzuat_parametreleri.view');
        $body = self::jsonBody($request);
        $encoded = null;
        if (array_key_exists('dosya_icerik_base64', $body)) {
            if (!is_string($body['dosya_icerik_base64'])) {
                JsonResponse::error(
                    422,
                    SgkOperasyonelKanitBase64Guard::ERROR_BASE64_GECERSIZ,
                    'Operasyonel kanit Base64 alani metin olmalidir.',
                    'dosya_icerik_base64',
                    ['limit_byte' => SgkOperasyonelKanitBase64Guard::MAX_DECODED_BYTES]
                );
            }
            $encoded = $body['dosya_icerik_base64'];
        }

        $resolved = SgkOperasyonelKanitBase64Guard::resolve($encoded);
        if ($resolved['ok'] !== true) {
            // Never echo payload / decoded content in errors or logs.
            JsonResponse::error(
                $resolved['http'],
                $resolved['code'],
                $resolved['message'],
                $resolved['field'],
                $resolved['meta']
            );
        }

        JsonResponse::success(
            SgkOperasyonelKanitValidator::validate($body, $resolved['bytes']),
            [
                'operasyonel_kanit_max_decoded_bytes' => SgkOperasyonelKanitBase64Guard::MAX_DECODED_BYTES,
            ]
        );
    }

    public static function kismiSureliPreview(Request $request)
    {
        self::context($request, 'bordro_on_izleme.view');
        JsonResponse::success(SgkKatalogPreviewService::kismiSureliPreview(self::jsonBody($request)));
    }

    public static function bildirimDonemiPreview(Request $request)
    {
        self::context($request, 'bordro_on_izleme.view');
        JsonResponse::success(SgkKatalogPreviewService::bildirimDonemiPreview(self::jsonBody($request)));
    }

    public static function onayValidate(Request $request)
    {
        [$pdo] = self::context($request, 'mevzuat_parametreleri.manage');
        $body = self::jsonBody($request);
        if (empty($body['tamlik'])) {
            // P1: never evaluate approval readiness against a silent empty catalog.
            $body['tamlik'] = SgkKatalogTamlikService::evaluate([
                'manifests' => self::loadManifests($pdo, 'onay_validate'),
                'kod_satirlari' => [],
            ]);
        }
        JsonResponse::success(SgkKatalogOnayService::validateTransition($body));
    }

    /**
     * Successful empty table → []. Storage/schema/query failure → 503 (never disguised as empty).
     *
     * @return list<array<string,mixed>>
     */
    private static function loadManifests(PDO $pdo, string $action): array
    {
        try {
            return SgkKaynakManifestReader::fetchAll($pdo);
        } catch (RuntimeException $e) {
            // Server-only sanitized observability; client contract stays opaque 503.
            error_log(
                SgkKaynakManifestReader::formatSanitizedRuntimeLog(
                    $action,
                    $e,
                    self::class
                )
            );
            // Do not leak PDO/SQL/internal exception details to clients.
            JsonResponse::error(
                503,
                SgkKaynakManifestReader::STORAGE_ERROR_CODE,
                'SGK kaynak manifesti okunamadi. Sema veya baglanti durumunu kontrol edin.'
            );
        }
    }

    /** @return array{0:PDO,1:array} */
    private static function writeContext(Request $request): array
    {
        $user = AuthMiddleware::authenticate($request, true);
        if (strtoupper((string) ($user['rol'] ?? '')) !== 'GENEL_YONETICI') {
            JsonResponse::error(403, 'SGK_KATALOG_WRITE_FORBIDDEN', 'SGK katalog yazma yalniz GENEL_YONETICI icindir.');
        }
        $pdo = Connection::get();
        SubeScope::resolveScope($user, $request);

        return [$pdo, $user];
    }

    /** @return array{0:PDO,1:array,2:?int} */
    private static function context(Request $request, string $permission): array
    {
        $user = AuthMiddleware::authenticate($request, true);
        if (!RolePermissions::has($user, $permission)) {
            JsonResponse::error(403, 'SGK_KATALOG_ACCESS_FORBIDDEN', 'SGK katalog hazirlik erisimi yok.');
        }
        $pdo = Connection::get();
        $subeId = SubeScope::resolveScope($user, $request);

        return [$pdo, $user, $subeId !== null ? (int) $subeId : null];
    }

    /** @return array<string,mixed> */
    private static function jsonBody(Request $request): array
    {
        $body = $request->getJsonBody();
        return is_array($body) ? $body : [];
    }
}
