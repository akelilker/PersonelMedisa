<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\PersonelBordroKapsamService;
use PDO;

/**
 * S84-R2 personel bordro kapsam HTTP owner'i.
 */
class PersonelBordroKapsamController
{
    public static function list(Request $request, $personelId)
    {
        [$pdo, $user, $personel] = self::context($request, (int) $personelId, 'personel_bordro_kapsam.view');
        JsonResponse::success([
            'items' => PersonelBordroKapsamService::listForPersonel($pdo, (int) $personelId),
            'contract_version' => PersonelBordroKapsamService::CONTRACT_VERSION,
        ]);
    }

    public static function dryRun(Request $request, $personelId)
    {
        [$pdo, $user, $personel] = self::context($request, (int) $personelId, 'personel_bordro_kapsam.view');
        $body = is_array($request->getJsonBody()) ? $request->getJsonBody() : [];
        $body['personel_id'] = (int) $personelId;
        JsonResponse::success(PersonelBordroKapsamService::dryRun($pdo, $body, $user));
    }

    public static function create(Request $request, $personelId)
    {
        [$pdo, $user, $personel] = self::context($request, (int) $personelId, 'personel_bordro_kapsam.manage');
        $body = is_array($request->getJsonBody()) ? $request->getJsonBody() : [];
        $body['personel_id'] = (int) $personelId;
        if (strtoupper((string) ($user['rol'] ?? '')) === 'MUHASEBE') {
            unset($body['direkt_onayla']);
        }
        $row = PersonelBordroKapsamService::create($pdo, $body, $user);
        JsonResponse::success($row, [], 201);
    }

    public static function submit(Request $request, $personelId, $kapsamId)
    {
        [$pdo, $user] = self::context($request, (int) $personelId, 'personel_bordro_kapsam.manage');
        self::assertKapsamBelongs($pdo, (int) $kapsamId, (int) $personelId);
        JsonResponse::success(PersonelBordroKapsamService::submit($pdo, (int) $kapsamId, $user));
    }

    public static function approve(Request $request, $personelId, $kapsamId)
    {
        [$pdo, $user] = self::context($request, (int) $personelId, 'personel_bordro_kapsam.approve');
        self::assertKapsamBelongs($pdo, (int) $kapsamId, (int) $personelId);
        JsonResponse::success(PersonelBordroKapsamService::approve($pdo, (int) $kapsamId, $user));
    }

    public static function cancel(Request $request, $personelId, $kapsamId)
    {
        [$pdo, $user] = self::context($request, (int) $personelId, 'personel_bordro_kapsam.manage');
        self::assertKapsamBelongs($pdo, (int) $kapsamId, (int) $personelId);
        $body = is_array($request->getJsonBody()) ? $request->getJsonBody() : [];
        $neden = (string) ($body['neden'] ?? $body['iptal_nedeni'] ?? '');
        JsonResponse::success(PersonelBordroKapsamService::cancel($pdo, (int) $kapsamId, $neden, $user));
    }

    /**
     * @return array{0: PDO, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function context(Request $request, $personelId, $permission)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, $permission);
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT id, sube_id, sicil_no, ad, soyad FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$personel) {
            JsonResponse::error(404, 'PERSONEL_NOT_FOUND', 'Personel bulunamadi.');
        }
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        return [$pdo, $user, $personel];
    }

    private static function assertKapsamBelongs(PDO $pdo, $kapsamId, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id FROM personel_bordro_kapsamlari WHERE id = :id AND personel_id = :pid LIMIT 1');
        $stmt->execute(['id' => (int) $kapsamId, 'pid' => (int) $personelId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::error(404, 'NOT_FOUND', 'Kapsam kaydi bulunamadi.');
        }
    }
}
