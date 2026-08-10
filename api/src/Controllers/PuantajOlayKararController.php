<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Attendance\PuantajOlayKararService;
use PDO;
use RuntimeException;
use Throwable;

class PuantajOlayKararController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.olay_karar.view');

        $personelId = self::positiveInt($request->getQuery('personel_id'), true);
        $from = trim((string) $request->getQuery('from', ''));
        $to = trim((string) $request->getQuery('to', ''));
        if ($from === '' || $to === '') {
            JsonResponse::badRequest('from ve to zorunludur.');
        }

        $pdo = Connection::get();
        self::assertSchema($pdo);
        self::assertPersonelScope($user, $request, $pdo, $personelId);

        $items = PuantajOlayKararService::listForPeriod($pdo, $personelId, $from, $to);
        JsonResponse::success(['items' => $items]);
    }

    public static function upsert(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.olay_karar.decide');

        $payload = $request->getJsonBody();
        if (!is_array($payload)) {
            JsonResponse::badRequest('Gecersiz payload.');
        }

        $personelId = self::positiveInt($payload['personel_id'] ?? null, true);
        $pdo = Connection::get();
        self::assertSchema($pdo);
        self::assertPersonelScope($user, $request, $pdo, $personelId);

        try {
            $row = PuantajOlayKararService::upsertDecision($pdo, $user, $payload);
            JsonResponse::success(['item' => $row]);
        } catch (RuntimeException $e) {
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            JsonResponse::serverError('Olay karari kaydedilemedi.');
        }
    }

    private static function assertSchema(PDO $pdo)
    {
        if (!PuantajOlayKararService::tableExists($pdo)) {
            JsonResponse::error(503, 'SCHEMA_NOT_READY', 'puantaj_olay_kararlari tablosu hazir degil.');
        }
        if (!PuantajOlayKararService::auditTableExists($pdo)) {
            JsonResponse::error(503, 'SCHEMA_NOT_READY', 'puantaj_olay_karar_auditleri tablosu hazir degil.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertPersonelScope(array $user, Request $request, PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $subeId = $stmt->fetchColumn();
        if ($subeId === false) {
            JsonResponse::notFound('Personel bulunamadi.');
        }
        SubeScope::assertPersonelAccess($user, $request, (int) $subeId);
    }

    /** @param mixed $value */
    private static function positiveInt($value, $required = false)
    {
        $id = (int) $value;
        if ($id < 1) {
            if ($required) {
                JsonResponse::badRequest('Gecersiz personel_id.');
            }

            return 0;
        }

        return $id;
    }
}
