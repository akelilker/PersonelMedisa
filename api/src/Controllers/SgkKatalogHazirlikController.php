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
use Medisa\Api\Services\Payroll\SgkOperasyonelKanitBase64Guard;
use Medisa\Api\Services\Payroll\SgkOperasyonelKanitValidator;
use Medisa\Api\Services\Payroll\SgkSurecKodEslemeValidator;
use PDO;
use RuntimeException;

/**
 * S85-C1: Read-only / dry-run SGK catalog readiness endpoints. No seed/write activation.
 */
class SgkKatalogHazirlikController
{
    public static function tamlik(Request $request)
    {
        [$pdo] = self::context($request, 'bordro_on_izleme.view');
        $body = self::jsonBody($request);
        if (empty($body['manifests'])) {
            $body['manifests'] = self::loadManifests($pdo);
        }
        $body['katalog_surumu'] = $body['katalog_surumu'] ?? '';
        $body['kod_satirlari'] = $body['kod_satirlari'] ?? [];
        JsonResponse::success(SgkKatalogTamlikService::evaluate($body));
    }

    public static function manifests(Request $request)
    {
        [$pdo] = self::context($request, 'bordro_on_izleme.view');
        $items = self::loadManifests($pdo);
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
        foreach (self::loadManifests($pdo) as $item) {
            if ((string) $item['kaynak_id'] === (string) $kaynakId) {
                JsonResponse::success($item);
                return;
            }
        }
        JsonResponse::error(404, 'SGK_KAYNAK_BULUNAMADI', 'Kaynak manifesti bulunamadi.');
    }

    public static function surumler(Request $request)
    {
        self::context($request, 'bordro_on_izleme.view');
        JsonResponse::success([
            'items' => [],
            'total' => 0,
            'dogrulanmis_tam_var_mi' => false,
            'response_hash' => hash('sha256', '{"items":[]}'),
        ]);
    }

    public static function importDryRun(Request $request)
    {
        [$pdo] = self::context($request, 'mevzuat_parametreleri.view');
        $body = self::jsonBody($request);
        if (empty($body['manifests'])) {
            $body['manifests'] = self::loadManifests($pdo);
        }
        JsonResponse::success(SgkKatalogImportValidator::dryRun($body));
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
            'manifests' => self::loadManifests($pdo),
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
            'approve_disabled_mi' => true,
            'import_write_disabled_mi' => true,
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
                'manifests' => self::loadManifests($pdo),
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
    private static function loadManifests(PDO $pdo): array
    {
        try {
            return SgkKaynakManifestReader::fetchAll($pdo);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === SgkKaynakManifestReader::STORAGE_ERROR_CODE) {
                // Do not leak PDO/SQL/internal exception details to clients.
                JsonResponse::error(
                    503,
                    SgkKaynakManifestReader::STORAGE_ERROR_CODE,
                    'SGK kaynak manifesti okunamadi. Sema veya baglanti durumunu kontrol edin.'
                );
            }
            JsonResponse::error(
                503,
                SgkKaynakManifestReader::STORAGE_ERROR_CODE,
                'SGK kaynak manifesti okunamadi. Sema veya baglanti durumunu kontrol edin.'
            );
        }
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
