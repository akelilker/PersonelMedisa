<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\CsvResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\MaasHesaplamaAdayService;
use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\MaasHesaplamaSnapshotService;
use Medisa\Api\Services\SgkPrimGunuService;
use Medisa\Api\Services\PersonelBordroDevirService;
use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use Medisa\Api\Services\Payroll\MaasHesaplamaLegalParameterCatalog;
use PDO;

/**
 * S77-C: Maas hesaplama preflight ve degismez girdi snapshot endpointleri.
 * Maas hesaplamaz; yalniz hesaplama girdilerini dondurur.
 */
class MaasHesaplamaController
{
    public static function preflight(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);

        try {
            JsonResponse::success(MaasHesaplamaSnapshotService::buildPreflight($pdo, $subeId, $yil, $ay));
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Maas hesaplama preflight olusturulamadi.');
        }
    }

    public static function listSnapshots(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama.view');
        $yil = self::optionalQueryInt($request, 'yil', 2000, 2100);
        $ay = self::optionalQueryInt($request, 'ay', 1, 12);

        try {
            JsonResponse::success([
                'items' => MaasHesaplamaSnapshotService::listSnapshots($pdo, $subeId, $yil, $ay),
            ]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Snapshot listesi okunamadi.');
        }
    }

    public static function detail(Request $request, $snapshotId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama.view');

        try {
            $includePayloads = RolePermissions::has($user, 'maas_hesaplama.manage')
                && (string) $request->getQuery('include_payloads', '') === '1';
            $detail = MaasHesaplamaSnapshotService::getSnapshotDetail($pdo, (int) $snapshotId, $includePayloads);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Snapshot detayi okunamadi.');
            return;
        }
        if (!$detail) {
            JsonResponse::error(404, 'PAYROLL_SNAPSHOT_NOT_FOUND', 'Snapshot bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $detail['sube_id']);

        JsonResponse::success($detail);
    }

    public static function create(Request $request)
    {
        [$pdo, $user, $subeId] = self::contextFromBody($request, 'maas_hesaplama.manage');
        $body = $request->getJsonBody();
        $yil = self::readBodyInt($body, 'yil', 2000, 2100);
        $ay = self::readBodyInt($body, 'ay', 1, 12);
        $expectedPreflightHash = trim((string) ($body['expected_preflight_hash'] ?? ''));
        if ($expectedPreflightHash === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedPreflightHash)) {
            self::validationError('expected_preflight_hash', 'Gecerli expected_preflight_hash zorunludur.');
        }

        try {
            $result = MaasHesaplamaSnapshotService::createSnapshot($pdo, $subeId, $yil, $ay, $expectedPreflightHash, $user);
            JsonResponse::success([
                'snapshot' => $result['snapshot'],
                'idempotent' => (bool) $result['idempotent'],
                'audit' => $result['audit'],
            ], [], $result['idempotent'] ? 200 : 201);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Snapshot olusturulamadi.');
        }
    }

    public static function cancel(Request $request, $snapshotId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama.manage');
        $row = MaasHesaplamaSnapshotService::fetchSnapshotRow($pdo, (int) $snapshotId);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_SNAPSHOT_NOT_FOUND', 'Snapshot bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);

        $body = $request->getJsonBody();
        $neden = trim((string) ($body['neden'] ?? ''));
        if ($neden === '') {
            self::validationError('neden', 'Iptal nedeni zorunludur.');
        }

        try {
            $result = MaasHesaplamaSnapshotService::cancelSnapshot($pdo, (int) $snapshotId, $neden, $user);
            JsonResponse::success([
                'snapshot' => $result['snapshot'],
                'idempotent' => (bool) $result['idempotent'],
                'audit' => $result['audit'],
            ]);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Snapshot iptal edilemedi.');
        }
    }

    public static function audit(Request $request, $snapshotId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama.view');
        $row = MaasHesaplamaSnapshotService::fetchSnapshotRow($pdo, (int) $snapshotId);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_SNAPSHOT_NOT_FOUND', 'Snapshot bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);

        try {
            JsonResponse::success([
                'items' => MaasHesaplamaSnapshotService::listAudits(
                    $pdo,
                    (int) $row['sube_id'],
                    (int) $row['yil'],
                    (int) $row['ay'],
                    (int) $snapshotId
                ),
            ]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Snapshot audit kayitlari okunamadi.');
        }
    }

    public static function listAudits(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);

        try {
            JsonResponse::success([
                'items' => MaasHesaplamaSnapshotService::listAudits($pdo, $subeId, $yil, $ay),
            ]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Snapshot audit kayitlari okunamadi.');
        }
    }

    // ------------------------------------------------------------------
    // S77-D hesaplama adaylari
    // ------------------------------------------------------------------

    public static function calculationPreflight(Request $request, $snapshotId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.view');
        $row = MaasHesaplamaSnapshotService::fetchSnapshotRow($pdo, (int) $snapshotId);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_SNAPSHOT_INVALID', 'Snapshot bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);

        try {
            $preflight = MaasHesaplamaAdayService::buildCalculationPreflight($pdo, (int) $snapshotId);
            JsonResponse::success(MaasHesaplamaAdayService::publicPreflight($preflight));
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Hesaplama preflight olusturulamadi.');
        }
    }

    public static function calculate(Request $request, $snapshotId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.manage');
        $row = MaasHesaplamaSnapshotService::fetchSnapshotRow($pdo, (int) $snapshotId);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_SNAPSHOT_INVALID', 'Snapshot bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);

        $body = $request->getJsonBody();
        $expected = trim((string) ($body['expected_calculation_input_hash'] ?? ''));
        if ($expected === '' || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            self::validationError('expected_calculation_input_hash', 'Gecerli expected_calculation_input_hash zorunludur.');
        }
        $engineVersion = trim((string) ($body['engine_version'] ?? MaasHesaplamaEngine::ENGINE_VERSION));

        try {
            $result = MaasHesaplamaAdayService::createCalculation($pdo, (int) $snapshotId, $expected, $engineVersion, $user);
            JsonResponse::success([
                'calistirma' => $result['calistirma'],
                'idempotent' => (bool) $result['idempotent'],
                'audit' => $result['audit'],
            ], [], $result['idempotent'] ? 200 : 201);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Hesaplama olusturulamadi.');
        }
    }

    public static function listCalistirmalar(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama_adaylari.view');
        $yil = self::optionalQueryInt($request, 'yil', 2000, 2100);
        $ay = self::optionalQueryInt($request, 'ay', 1, 12);
        try {
            JsonResponse::success(['items' => MaasHesaplamaAdayService::listCalistirmalar($pdo, $subeId, $yil, $ay)]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Calistirma listesi okunamadi.');
        }
    }

    public static function calistirmaDetail(Request $request, $id)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.view');
        $detail = MaasHesaplamaAdayService::getCalistirmaDetail($pdo, (int) $id);
        if (!$detail) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Calistirma bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $detail['sube_id']);
        JsonResponse::success($detail);
    }

    public static function listAdaylar(Request $request, $calistirmaId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.view');
        $row = MaasHesaplamaAdayService::fetchCalistirma($pdo, (int) $calistirmaId);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Calistirma bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);
        JsonResponse::success(['items' => MaasHesaplamaAdayService::listAdaylar($pdo, (int) $calistirmaId)]);
    }

    public static function adayDetail(Request $request, $adayId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.view');
        $aday = MaasHesaplamaAdayService::getAday($pdo, (int) $adayId);
        if (!$aday) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Aday bulunamadi.');
        }
        $row = MaasHesaplamaAdayService::fetchCalistirma($pdo, (int) $aday['calistirma_id']);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Calistirma bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);
        JsonResponse::success($aday);
    }

    public static function adayKalemler(Request $request, $adayId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.view');
        $aday = MaasHesaplamaAdayService::getAday($pdo, (int) $adayId);
        if (!$aday) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Aday bulunamadi.');
        }
        $row = MaasHesaplamaAdayService::fetchCalistirma($pdo, (int) $aday['calistirma_id']);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Calistirma bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);
        JsonResponse::success(['items' => MaasHesaplamaAdayService::listKalemler($pdo, (int) $adayId)]);
    }

    public static function calistirmaAudit(Request $request, $calistirmaId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.view');
        $row = MaasHesaplamaAdayService::fetchCalistirma($pdo, (int) $calistirmaId);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Calistirma bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);
        JsonResponse::success(['items' => MaasHesaplamaAdayService::listAudits($pdo, (int) $calistirmaId)]);
    }

    public static function cancelCalistirma(Request $request, $calistirmaId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.manage');
        $row = MaasHesaplamaAdayService::fetchCalistirma($pdo, (int) $calistirmaId);
        if (!$row) {
            JsonResponse::error(404, 'PAYROLL_CALCULATION_NOT_FOUND', 'Calistirma bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, (int) $row['sube_id']);
        $body = $request->getJsonBody();
        $neden = trim((string) ($body['neden'] ?? ''));
        if ($neden === '') {
            self::validationError('neden', 'Iptal nedeni zorunludur.');
        }
        try {
            $result = MaasHesaplamaAdayService::cancelCalculation($pdo, (int) $calistirmaId, $neden, $user);
            JsonResponse::success([
                'calistirma' => $result['calistirma'],
                'idempotent' => (bool) $result['idempotent'],
                'audit' => $result['audit'],
            ]);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Calistirma iptal edilemedi.');
        }
    }

    public static function sgkResults(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama_adaylari.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        $personelId = self::optionalQueryInt($request, 'personel_id', 1, PHP_INT_MAX);
        try {
            JsonResponse::success([
                'items' => SgkPrimGunuService::listCanonicalResults($pdo, $subeId, $yil, $ay, $personelId),
            ]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('SGK prim gunu sonuclari okunamadi.');
        }
    }

    public static function sgkResultsExport(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama_adaylari.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        $rows = SgkPrimGunuService::listCanonicalResults($pdo, $subeId, $yil, $ay);
        CsvResponse::send(
            sprintf('sgk-kontrol-%04d-%02d-sube-%d.csv', $yil, $ay, $subeId),
            [
                'personel_id', 'sicil_no', 'ad', 'soyad', 'yil', 'ay', 'donem',
                'hesaplanan_prim_gunu', 'eksik_gun_sayisi', 'eksik_gun_kodu', 'eksik_gun_aciklamasi',
                'kaynak_surec_idleri', 'kaynak_puantaj_idleri', 'kaynak_belge_idleri',
                'ucret_modeli', 'sirket_politika_surum_id', 'sgk_odenek_durumu',
                'is_goremezlik_finans_ozeti',
                'manuel_inceleme_gerekli_mi', 'blocker_kodlari',
                'sgk_hesap_hash', 'katalog_surumu', 'kaynak_manifest_hash',
                'snapshot_id', 'snapshot_revision_no', 'source_hash'
            ],
            $rows
        );
    }

    public static function legalCatalog(Request $request)
    {
        self::authOnly($request, 'maas_hesaplama_adaylari.view');
        $items = [];
        foreach (MaasHesaplamaLegalParameterCatalog::all() as $code => $meta) {
            $items[] = array_merge(['parametre_kodu' => $code], $meta);
        }
        JsonResponse::success(['items' => $items, 'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION]);
    }

    public static function listDevirler(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama_adaylari.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        JsonResponse::success(['items' => PersonelBordroDevirService::listForSube($pdo, $subeId, $yil, $ay)]);
    }

    public static function upsertDevir(Request $request)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.manage');
        $body = $request->getJsonBody();
        $personelId = isset($body['personel_id']) ? (int) $body['personel_id'] : 0;
        $subeId = isset($body['sube_id']) ? (int) $body['sube_id'] : 0;
        if ($personelId < 1 || $subeId < 1) {
            self::validationError('personel_id', 'personel_id ve sube_id zorunludur.');
        }
        self::assertSnapshotScope($user, $request, $subeId);
        $yil = self::readBodyInt($body, 'yil', 2000, 2100);
        $ay = self::readBodyInt($body, 'ay', 1, 12);
        if (!isset($body['onceki_kumulatif_gelir_vergisi_matrahi']) || !isset($body['onceki_kumulatif_gelir_vergisi'])) {
            self::validationError('onceki_kumulatif_gelir_vergisi_matrahi', 'Devir matrah/vergi zorunludur.');
        }
        try {
            $row = PersonelBordroDevirService::upsert($pdo, [
                'personel_id' => $personelId,
                'sube_id' => $subeId,
                'yil' => $yil,
                'ay' => $ay,
                'onceki_kumulatif_gelir_vergisi_matrahi' => (string) $body['onceki_kumulatif_gelir_vergisi_matrahi'],
                'onceki_kumulatif_gelir_vergisi' => (string) $body['onceki_kumulatif_gelir_vergisi'],
                'onceki_kumulatif_sgk_matrahi' => $body['onceki_kumulatif_sgk_matrahi'] ?? null,
                'devir_kaynagi' => $body['devir_kaynagi'] ?? 'MANUEL',
                'aciklama' => $body['aciklama'] ?? null,
            ], $user);
            JsonResponse::success(['devir' => $row], [], 201);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Devir kaydi olusturulamadi.');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: PDO, 1: array<string, mixed>, 2: int} */
    private static function context(Request $request, $permission)
    {
        [$pdo, $user] = self::authOnly($request, $permission);
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Maas hesaplama icin aktif sube secilmelidir.');
        }

        return [$pdo, $user, (int) $scope];
    }

    /** @return array{0: PDO, 1: array<string, mixed>, 2: int} */
    private static function contextFromBody(Request $request, $permission)
    {
        [$pdo, $user] = self::authOnly($request, $permission);
        $body = $request->getJsonBody();
        $subeId = isset($body['sube_id']) ? (int) $body['sube_id'] : 0;
        if ($subeId < 1) {
            self::validationError('sube_id', 'sube_id zorunludur.');
        }
        SubeScope::assertPersonelAccess($user, $request, $subeId);

        return [$pdo, $user, $subeId];
    }

    /** @return array{0: PDO, 1: array<string, mixed>} */
    private static function authOnly(Request $request, $permission)
    {
        $user = AuthMiddleware::authenticate($request, true);
        if (!RolePermissions::has($user, $permission)) {
            JsonResponse::error(403, 'PAYROLL_ACCESS_FORBIDDEN', 'Maas hesaplama merkezine erisim yetkiniz yok.');
        }
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        return [$pdo, $user];
    }

    /** @param array<string, mixed> $user */
    private static function assertSnapshotScope(array $user, Request $request, $subeId)
    {
        // JsonResponse::forbidden exit ile sonlanir; ID tahminiyle scope bypass edilemez.
        SubeScope::assertPersonelAccess($user, $request, (int) $subeId);
    }

    private static function domainError(MaasHesaplamaException $e)
    {
        $details = $e->getDetails();
        if (count($details) > 0) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code($e->getHttpStatus());
            }
            echo json_encode([
                'data' => null,
                'meta' => ['details' => $details],
                'errors' => [[
                    'code' => $e->getCodeString(),
                    'message' => $e->getMessage(),
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
    }

    private static function readQueryInt(Request $request, $field, $min, $max)
    {
        $value = $request->getQuery($field);
        if ($value === null || $value === '') {
            self::validationError($field, ucfirst((string) $field) . ' parametresi zorunludur.');
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit((string) $value))) {
            self::validationError($field, ucfirst((string) $field) . ' gecerli bir tam sayi olmalidir.');
        }
        $parsed = (int) $value;
        if ($parsed < $min || $parsed > $max) {
            self::validationError($field, ucfirst((string) $field) . ' ' . $min . '-' . $max . ' araliginda olmalidir.');
        }

        return $parsed;
    }

    private static function optionalQueryInt(Request $request, $field, $min, $max)
    {
        $value = $request->getQuery($field);
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = (int) $value;
        if ($parsed < $min || $parsed > $max) {
            self::validationError($field, ucfirst((string) $field) . ' ' . $min . '-' . $max . ' araliginda olmalidir.');
        }

        return $parsed;
    }

    /** @param array<string, mixed> $body */
    private static function readBodyInt(array $body, $field, $min, $max)
    {
        $value = $body[$field] ?? null;
        if ($value === null || $value === '' || (!is_int($value) && !(is_string($value) && ctype_digit((string) $value)))) {
            self::validationError($field, ucfirst((string) $field) . ' zorunludur.');
        }
        $parsed = (int) $value;
        if ($parsed < $min || $parsed > $max) {
            self::validationError($field, ucfirst((string) $field) . ' ' . $min . '-' . $max . ' araliginda olmalidir.');
        }

        return $parsed;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(400, 'VALIDATION_ERROR', $message, $field);
    }
}
