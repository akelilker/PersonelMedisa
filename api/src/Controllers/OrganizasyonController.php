<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use PDO;

/**
 * Company / branch hierarchy endpoints.
 *
 * Kept out of YonetimController on purpose: that class is already 1800+ lines
 * and mixes users, actor identities and monthly closing. Every rule here lives
 * in OrganizasyonService, so the legacy flat branch endpoints delegate to the
 * same owner and cannot drift from the nested ones.
 */
class OrganizasyonController
{
    /**
     * Read-only readiness. Deliberately separates "does the schema exist" from
     * "is production data mapped", because the UI must fall back to the legacy
     * flat branch list in the second case instead of rendering empty companies.
     */
    public static function readiness(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertRead($user);
        $pdo = self::pdo();

        $report = OrganizasyonSchema::report($pdo);
        JsonResponse::success([
            'schema_ready' => $report['schema_ready'],
            'data_ready' => $report['data_ready'],
            'structures' => $report['structures'],
            'counts' => $report['counts'],
            'blockers' => $report['blockers'],
        ]);
    }

    public static function sirketler(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertRead($user);

        self::run(function (PDO $pdo) {
            JsonResponse::success(['items' => OrganizasyonService::listSirketler($pdo)]);
        });
    }

    public static function sirketOlustur(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);
        $body = $request->getJsonBody();

        self::run(function (PDO $pdo) use ($body) {
            JsonResponse::success(OrganizasyonService::createSirket($pdo, $body), [], 201);
        });
    }

    public static function sirketDetay(Request $request, $sirketId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertRead($user);

        self::run(function (PDO $pdo) use ($sirketId) {
            JsonResponse::success(OrganizasyonService::readSirket($pdo, $sirketId));
        });
    }

    public static function sirketGuncelle(Request $request, $sirketId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);
        $body = $request->getJsonBody();

        self::run(function (PDO $pdo) use ($sirketId, $body) {
            JsonResponse::success(OrganizasyonService::updateSirket($pdo, $sirketId, $body));
        });
    }

    public static function sirketSil(Request $request, $sirketId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);

        self::run(function (PDO $pdo) use ($sirketId) {
            JsonResponse::success(OrganizasyonService::deleteSirket($pdo, $sirketId));
        });
    }

    // ----------------------------------------------------------- sgk employers

    /**
     * Management read of the SGK employer catalog. The create form needs PASIF
     * rows and the owning company, which /referans/sgk-isverenler deliberately
     * does not expose; both reads stay on the same owner (OrganizasyonService).
     */
    public static function sgkIsverenleri(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertRead($user);

        self::run(function (PDO $pdo) {
            JsonResponse::success(['items' => OrganizasyonService::listSgkIsverenleri($pdo)]);
        });
    }

    public static function sgkIsverenOlustur(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);
        $body = $request->getJsonBody();

        self::run(function (PDO $pdo) use ($body) {
            JsonResponse::success(OrganizasyonService::createSgkIsveren($pdo, $body), [], 201);
        });
    }

    public static function sgkIsverenGuncelle(Request $request, $sgkIsverenId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);
        $body = $request->getJsonBody();

        self::run(function (PDO $pdo) use ($sgkIsverenId, $body) {
            JsonResponse::success(OrganizasyonService::updateSgkIsveren($pdo, $sgkIsverenId, $body));
        });
    }

    public static function sgkIsverenSil(Request $request, $sgkIsverenId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);

        self::run(function (PDO $pdo) use ($sgkIsverenId) {
            JsonResponse::success(OrganizasyonService::deleteSgkIsveren($pdo, $sgkIsverenId));
        });
    }

    public static function sirketSubeleri(Request $request, $sirketId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertRead($user);

        self::run(function (PDO $pdo) use ($sirketId) {
            $sirket = OrganizasyonService::readSirket($pdo, $sirketId);
            JsonResponse::success([
                'sirket' => $sirket,
                'items' => OrganizasyonService::listSubeler($pdo, (int) $sirket['id']),
            ]);
        });
    }

    public static function sirketSubeOlustur(Request $request, $sirketId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);
        $body = $request->getJsonBody();
        $auditContext = OrganizasyonAuditContext::fromRequest($request, $user);

        self::run(function (PDO $pdo) use ($sirketId, $body, $auditContext) {
            $sirket = OrganizasyonService::readSirket($pdo, $sirketId);
            JsonResponse::success(
                OrganizasyonService::createSube($pdo, $body, (int) $sirket['id'], $auditContext),
                [],
                201
            );
        });
    }

    public static function sirketSubeDetay(Request $request, $sirketId, $subeId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertRead($user);

        self::run(function (PDO $pdo) use ($sirketId, $subeId) {
            $sirket = OrganizasyonService::readSirket($pdo, $sirketId);
            JsonResponse::success(OrganizasyonService::readSube($pdo, $subeId, (int) $sirket['id']));
        });
    }

    public static function sirketSubeGuncelle(Request $request, $sirketId, $subeId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);
        $body = $request->getJsonBody();

        self::run(function (PDO $pdo) use ($sirketId, $subeId, $body) {
            $sirket = OrganizasyonService::readSirket($pdo, $sirketId);
            JsonResponse::success(
                OrganizasyonService::updateSube($pdo, $subeId, $body, (int) $sirket['id'])
            );
        });
    }

    public static function sirketSubeSil(Request $request, $sirketId, $subeId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertManage($user);

        self::run(function (PDO $pdo) use ($sirketId, $subeId) {
            $sirket = OrganizasyonService::readSirket($pdo, $sirketId);
            JsonResponse::success(OrganizasyonService::deleteSube($pdo, $subeId, (int) $sirket['id']));
        });
    }

    /** @param array<string, mixed> $user */
    private static function assertRead(array $user)
    {
        RolePermissions::assertAny($user, [
            'yonetim-paneli.view',
            'yonetim-paneli.manage',
            'aylik-ozet.view',
            'personeller.create',
            'personeller.update',
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function assertManage(array $user)
    {
        RolePermissions::assert($user, 'yonetim-paneli.manage');
    }

    private static function pdo()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    /**
     * Domain failures carry their own status/code, so a missing hierarchy schema
     * surfaces as an explainable 409 instead of a driver-level 500.
     */
    private static function run(callable $handler)
    {
        $pdo = self::pdo();
        try {
            $handler($pdo);
        } catch (OrganizasyonException $e) {
            JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
        }
    }
}
