<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\MaasHesaplamaSnapshotService;
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
