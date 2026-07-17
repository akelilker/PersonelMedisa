<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\PersonelUcretException;
use Medisa\Api\Services\PersonelUcretService;
use PDO;

class PersonelUcretController
{
    public static function list(Request $request, $personelId)
    {
        [$pdo, $user, $personelId] = self::context($request, $personelId, 'personeller.ucret.view');
        JsonResponse::success(['items' => PersonelUcretService::listSalaryHistory($pdo, $personelId)]);
    }

    public static function aktif(Request $request, $personelId)
    {
        [$pdo, $user, $personelId] = self::context($request, $personelId, 'personeller.ucret.view');
        $date = (string) ($request->getQuery('tarih', date('Y-m-d')) ?: date('Y-m-d'));
        try {
            JsonResponse::success(PersonelUcretService::resolveSalaryForDate($pdo, $personelId, $date));
        } catch (PersonelUcretException $e) {
            self::error($e);
        }
    }

    public static function create(Request $request, $personelId)
    {
        [$pdo, $user, $personelId] = self::context($request, $personelId, 'personeller.ucret.manage');
        try {
            $record = PersonelUcretService::createSalaryRecord(
                $pdo,
                $personelId,
                $request->getJsonBody(),
                $user,
                self::requestHash($request, $user, $personelId)
            );
            JsonResponse::success($record, [], 201);
        } catch (PersonelUcretException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Ucret kaydi olusturulamadi.');
        }
    }

    public static function update(Request $request, $personelId, $recordId)
    {
        [$pdo, $user, $personelId] = self::context($request, $personelId, 'personeller.ucret.manage');
        self::assertRecordOwner($pdo, $recordId, $personelId);
        try {
            $record = PersonelUcretService::updateFutureSalaryRecord(
                $pdo,
                (int) $recordId,
                $request->getJsonBody(),
                $user,
                self::requestHash($request, $user, $personelId)
            );
            JsonResponse::success($record);
        } catch (PersonelUcretException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Ucret kaydi guncellenemedi.');
        }
    }

    public static function iptal(Request $request, $personelId, $recordId)
    {
        [$pdo, $user, $personelId] = self::context($request, $personelId, 'personeller.ucret.manage');
        self::assertRecordOwner($pdo, $recordId, $personelId);
        try {
            $record = PersonelUcretService::cancelSalaryRecord(
                $pdo,
                (int) $recordId,
                $user,
                self::requestHash($request, $user, $personelId)
            );
            JsonResponse::success($record);
        } catch (PersonelUcretException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Ucret kaydi iptal edilemedi.');
        }
    }

    /** @return array{0: PDO, 1: array<string, mixed>, 2: int} */
    private static function context(Request $request, $personelId, $permission)
    {
        $user = AuthMiddleware::authenticate($request, true);
        if (!RolePermissions::has($user, $permission)) {
            JsonResponse::error(403, 'SALARY_ACCESS_FORBIDDEN', 'Ucret bilgisine erisim yetkiniz yok.');
        }
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::error(404, 'SALARY_RECORD_NOT_FOUND', 'Personel bulunamadi.');
        }
        try {
            $pdo = Connection::get();
            $stmt = $pdo->prepare('SELECT id, sube_id FROM personeller WHERE id = :id');
            $stmt->execute(['id' => $personelId]);
            $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
        if (!$personel) {
            JsonResponse::error(404, 'SALARY_RECORD_NOT_FOUND', 'Personel bulunamadi.');
        }
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        return [$pdo, $user, $personelId];
    }

    private static function assertRecordOwner(PDO $pdo, $recordId, $personelId)
    {
        $stmt = $pdo->prepare('SELECT personel_id FROM personel_ucret_gecmisi WHERE id = :id');
        $stmt->execute(['id' => (int) $recordId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || (int) $owner !== (int) $personelId) {
            JsonResponse::error(404, 'SALARY_RECORD_NOT_FOUND', 'Ucret kaydi bulunamadi.');
        }
    }

    private static function error(PersonelUcretException $e)
    {
        JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
    }

    /** @param array<string, mixed> $user */
    private static function requestHash(Request $request, array $user, $personelId)
    {
        $canonical = [
            'actor_id' => (int) ($user['id'] ?? 0),
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'personel_id' => (int) $personelId,
            'request_id' => (string) $request->getHeader('x-request-id', ''),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
