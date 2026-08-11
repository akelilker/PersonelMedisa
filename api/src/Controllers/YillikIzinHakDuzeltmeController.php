<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Izin\YillikIzinBakiyeService;
use Medisa\Api\Services\Izin\YillikIzinHakDuzeltmeException;
use Medisa\Api\Services\Izin\YillikIzinHakDuzeltmeLedgerService;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use PDO;

/**
 * S2B yıllık izin bakiye + hak düzeltme ledger endpoints.
 * Thin controller: authz + scope + service delegate.
 */
class YillikIzinHakDuzeltmeController
{
    public static function bakiye(Request $request, $personelId)
    {
        [$pdo, $user, $personelId] = self::readContext($request, $personelId);
        $ref = $request->getQuery('referans_tarih', null);
        try {
            JsonResponse::success(YillikIzinBakiyeService::assemble($pdo, $personelId, $ref));
        } catch (YillikIzinHakDuzeltmeException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Yillik izin bakiyesi hesaplanamadi.');
        }
    }

    public static function list(Request $request, $personelId)
    {
        [$pdo, $user, $personelId] = self::readContext($request, $personelId);
        try {
            JsonResponse::success([
                'items' => YillikIzinHakDuzeltmeLedgerService::listByPersonel($pdo, $personelId),
                'manual_net' => YillikIzinHakDuzeltmeLedgerService::netSum($pdo, $personelId),
            ]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Yillik izin hak duzeltmeleri listelenemedi.');
        }
    }

    public static function create(Request $request, $personelId)
    {
        [$pdo, $user, $personelId] = self::manageContext($request, $personelId);
        PersonelArchiveGate::assertBusinessWriteAllowed($pdo, $personelId);
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            JsonResponse::badRequest('Gecersiz istek govdesi.', 'VALIDATION_ERROR', 'body');
        }
        try {
            $row = YillikIzinHakDuzeltmeLedgerService::create($pdo, $personelId, $body, $user);
            JsonResponse::success($row, [], 201);
        } catch (YillikIzinHakDuzeltmeException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Yillik izin hak duzeltmesi olusturulamadi.');
        }
    }

    public static function tersKayit(Request $request, $personelId, $duzeltmeId)
    {
        [$pdo, $user, $personelId] = self::manageContext($request, $personelId);
        PersonelArchiveGate::assertBusinessWriteAllowed($pdo, $personelId);
        $body = $request->getJsonBody();
        $aciklama = is_array($body) && array_key_exists('aciklama', $body) ? $body['aciklama'] : null;
        try {
            $row = YillikIzinHakDuzeltmeLedgerService::reverse(
                $pdo,
                $personelId,
                $duzeltmeId,
                $user,
                $aciklama
            );
            JsonResponse::success($row, [], 201);
        } catch (YillikIzinHakDuzeltmeException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Ters kayit olusturulamadi.');
        }
    }

    /** @return array{0: PDO, 1: array<string, mixed>, 2: int} */
    private static function readContext(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'personeller.detail.view',
            'personeller.view',
            'personeller.view.sube',
        ]);

        return self::loadPersonelScoped($request, $user, $personelId);
    }

    /** @return array{0: PDO, 1: array<string, mixed>, 2: int} */
    private static function manageContext(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'yillik_izin_hak_duzeltme.manage');

        return self::loadPersonelScoped($request, $user, $personelId);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0: PDO, 1: array<string, mixed>, 2: int}
     */
    private static function loadPersonelScoped(Request $request, array $user, $personelId)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound('Personel bulunamadi.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->prepare('SELECT id, sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$personel) {
            JsonResponse::notFound('Personel bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        return [$pdo, $user, $personelId];
    }

    private static function error(YillikIzinHakDuzeltmeException $e)
    {
        JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
    }
}
