<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\SelfService\DuyuruService;
use Medisa\Api\Services\SelfService\PersonelAvansTalepService;
use Medisa\Api\Services\SelfService\PersonelBordroOkumaService;
use Medisa\Api\Services\SelfService\PersonelGeriBildirimService;
use Medisa\Api\Services\SelfService\PersonelProfilFotoService;
use Medisa\Api\Services\SelfService\PersonelSelfProductException;
use Medisa\Api\Services\SelfService\SelfIzinReadService;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use Medisa\Api\Services\SelfService\SelfRaporReadService;
use Medisa\Api\Services\SelfService\SelfRequestInboxNotifier;
use PDO;

/**
 * PERSONEL self-product reads/writes that are not the attendance inbox.
 */
class PersonelSelfProductController
{
    public static function izinler(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.yillik_izin.view');
        try {
            JsonResponse::success(SelfIzinReadService::listForPersonel(self::pdo(), (int) $ctx['personel_id']));
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Izinler yuklenemedi.');
        }
    }

    public static function avansList(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        try {
            JsonResponse::success(PersonelAvansTalepService::listForPersonel(self::pdo(), (int) $ctx['personel_id']));
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Avans talepleri yuklenemedi.');
        }
    }

    public static function avansCreate(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        $body = self::body($request);
        unset($body['personel_id'], $body['durum'], $body['sonuc']);
        try {
            $pdo = self::pdo();
            $created = PersonelAvansTalepService::create($pdo, (int) $ctx['personel_id'], $body);
            try {
                SelfRequestInboxNotifier::notifyAvansRequest($pdo, $ctx, $created);
            } catch (\Throwable $notifyError) {
                // best-effort inbox
            }
            JsonResponse::success($created, [], 201);
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Avans talebi olusturulamadi.');
        }
    }

    public static function geriBildirimList(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        try {
            JsonResponse::success(PersonelGeriBildirimService::listForPersonel(self::pdo(), (int) $ctx['personel_id']));
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Geri bildirimler yuklenemedi.');
        }
    }

    public static function geriBildirimCreate(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        $body = self::body($request);
        unset($body['personel_id'], $body['durum'], $body['sonuc']);
        try {
            JsonResponse::success(
                PersonelGeriBildirimService::create(self::pdo(), (int) $ctx['personel_id'], $body),
                [],
                201
            );
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Geri bildirim olusturulamadi.');
        }
    }

    public static function bordrolar(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.view');
        $ctx = SelfPersonelContext::resolveForSelfService($user, self::pdo(), true);
        try {
            JsonResponse::success(
                PersonelBordroOkumaService::listForPersonel(
                    self::pdo(),
                    (int) $ctx['personel_id'],
                    (int) $user['id']
                )
            );
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bordro listesi yuklenemedi.');
        }
    }

    public static function bordroOkudum(Request $request, $calistirmaId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.view');
        $ctx = SelfPersonelContext::resolveForSelfService($user, self::pdo(), true);
        try {
            JsonResponse::success(
                PersonelBordroOkumaService::acknowledge(
                    self::pdo(),
                    (int) $ctx['personel_id'],
                    (int) $user['id'],
                    $calistirmaId
                )
            );
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bordro okundu isaretlenemedi.');
        }
    }

    public static function raporlar(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        try {
            JsonResponse::success(SelfRaporReadService::listForPersonel(self::pdo(), (int) $ctx['personel_id']));
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Raporlar yuklenemedi.');
        }
    }

    public static function duyurular(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        try {
            JsonResponse::success(DuyuruService::listForContext(self::pdo(), $ctx));
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Duyurular yuklenemedi.');
        }
    }

    public static function duyuruOkundu(Request $request, $id)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        try {
            JsonResponse::success(DuyuruService::markRead(self::pdo(), $ctx, $id));
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Duyuru okundu isaretlenemedi.');
        }
    }

    public static function duyuruCreate(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'duyurular.manage');
        $body = self::body($request);
        try {
            JsonResponse::success(DuyuruService::create(self::pdo(), $user, $body), [], 201);
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Duyuru olusturulamadi.');
        }
    }

    public static function selfProfilFoto(Request $request)
    {
        $ctx = self::selfContext($request, 'self_service.view');
        self::profilFoto($request, (int) $ctx['personel_id']);
    }

    public static function managedProfilFoto(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.update');
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound('Personel bulunamadi.');
        }
        $pdo = self::pdo();
        $stmt = $pdo->prepare('SELECT id, sube_id, bolum_id, birim_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($personel)) {
            JsonResponse::notFound('Personel bulunamadi.');
        }
        SubeScope::assertPersonelAccess($user, $request, $personel, $pdo);
        self::profilFoto($request, $personelId);
    }

    private static function profilFoto(Request $request, $personelId)
    {
        try {
            $pdo = self::pdo();
            if ($request->getMethod() === 'PUT') {
                $body = self::body($request);
                JsonResponse::success(PersonelProfilFotoService::upsert($pdo, $personelId, $body));
            }
            JsonResponse::success(PersonelProfilFotoService::read($pdo, $personelId));
        } catch (PersonelSelfProductException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Profil fotografi islenemedi.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function selfContext(Request $request, $permission)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, $permission);

        return SelfPersonelContext::resolveForSelfService($user, self::pdo(), true);
    }

    /** @return PDO */
    private static function pdo()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Request $request)
    {
        $body = $request->getJsonBody();

        return is_array($body) ? $body : [];
    }
}
