<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\CsvResponse;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\DonemKapanisPreflightItemsService;
use Medisa\Api\Services\DonemKapanisPreflightService;
use PDO;

class DonemKapanisController
{
    private const MAX_LIMIT = 250;
    private const AUDIT_MAX_LIMIT = 100;

    public static function summary(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_kapanis.view');

        [$subeId, $yil, $ay, $filters, $restrictAmirId] = self::resolveContext($user, $request);
        $pdo = self::connection();
        self::assertAuditTableReady($pdo);

        $payload = DonemKapanisPreflightService::evaluate(
            $pdo,
            $subeId,
            $yil,
            $ay,
            $filters,
            $restrictAmirId
        );

        JsonResponse::success($payload);
    }

    public static function items(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_kapanis.view');

        [$subeId, $yil, $ay, $filters, $restrictAmirId] = self::resolveContext($user, $request);
        $code = trim((string) $request->getQuery('code', ''));
        if ($code === '') {
            self::validationError('code', 'Issue kodu secilmelidir.');
        }

        $severity = trim((string) $request->getQuery('severity', ''));
        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->getQuery('limit', 20) ?: 20)));

        $pdo = self::connection();
        self::assertAuditTableReady($pdo);

        $result = DonemKapanisPreflightItemsService::listItems(
            $pdo,
            $subeId,
            $yil,
            $ay,
            $code,
            $severity,
            $page,
            $limit,
            $filters,
            $restrictAmirId
        );

        JsonResponse::success(
            ['items' => $result['items']],
            [
                'page' => $result['page'],
                'limit' => $result['limit'],
                'total' => $result['total'],
                'total_pages' => $result['total_pages'],
                'has_next_page' => $result['has_next_page'],
                'has_prev_page' => $result['has_prev_page'],
            ]
        );
    }

    public static function exportCsv(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_kapanis.export');

        [$subeId, $yil, $ay, $filters, $restrictAmirId] = self::resolveContext($user, $request);
        $pdo = self::connection();
        self::assertAuditTableReady($pdo);

        $rows = DonemKapanisPreflightItemsService::exportRows(
            $pdo,
            $subeId,
            $yil,
            $ay,
            $filters,
            $restrictAmirId
        );

        $filename = sprintf('donem-kapanis-preflight-%04d-%02d-sube-%d.csv', $yil, $ay, $subeId);
        CsvResponse::send($filename, [
            'code',
            'severity',
            'domain',
            'title',
            'record_id',
            'personel_id',
            'tarih',
            'state',
            'detail',
        ], $rows);
    }

    public static function listAudits(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_kapanis.view');

        [$subeId, $yil, $ay] = self::resolvePeriod($user, $request);
        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(self::AUDIT_MAX_LIMIT, (int) ($request->getQuery('limit', 20) ?: 20)));

        $pdo = self::connection();
        self::assertAuditTableReady($pdo);

        $params = [
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
        ];
        $where = 'sube_id = :sube_id AND yil = :yil AND ay = :ay';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM donem_kapanis_auditleri WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $stmt = $pdo->prepare(
            'SELECT id, sube_id, yil, ay, action, result_state, muhur_id,
                    blocker_count, warning_count, preflight_hash, request_hash, result_hash,
                    actor_user_id, created_at
             FROM donem_kapanis_auditleri
             WHERE ' . $where . '
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapAuditRow($row);
        }

        JsonResponse::success(
            ['items' => $items],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
                'has_next_page' => $page * $limit < $total,
                'has_prev_page' => $page > 1,
            ]
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0: int, 1: int, 2: int, 3: array<string, mixed>, 4: int|null}
     */
    private static function resolveContext(array $user, Request $request)
    {
        [$subeId, $yil, $ay] = self::resolvePeriod($user, $request);

        return [
            $subeId,
            $yil,
            $ay,
            self::parseOptionalFilters($request),
            self::resolveRestrictAmirId($user),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0: int, 1: int, 2: int}
     */
    private static function resolvePeriod(array $user, Request $request)
    {
        $subeId = self::requireScope($user, $request);
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);

        return [$subeId, $yil, $ay];
    }

    /** @return array<string, mixed> */
    private static function parseOptionalFilters(Request $request)
    {
        $filters = [];
        $departmanId = self::positiveInt($request->getQuery('departman_id'));
        if ($departmanId !== null) {
            $filters['departman_id'] = $departmanId;
        }
        $personelId = self::positiveInt($request->getQuery('personel_id'));
        if ($personelId !== null) {
            $filters['personel_id'] = $personelId;
        }

        return $filters;
    }

    /** @param array<string, mixed> $user */
    private static function requireScope(array $user, Request $request)
    {
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Donem kapanis icin aktif sube secilmelidir.');
        }

        return (int) $scope;
    }

    /** @param array<string, mixed> $user */
    private static function resolveRestrictAmirId(array $user)
    {
        return SubeScope::restrictBirimAmiriUserId($user);
    }

    /** @param array<string, mixed> $user */
    private static function userId(array $user)
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) {
            JsonResponse::unauthorized();
        }

        return $id;
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

    private static function positiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 && (string) $id === trim((string) $value) ? $id : null;
    }

    /** @param array<string, mixed> $row */
    private static function mapAuditRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'action' => (string) $row['action'],
            'result_state' => (string) $row['result_state'],
            'muhur_id' => $row['muhur_id'] !== null ? (int) $row['muhur_id'] : null,
            'blocker_count' => (int) $row['blocker_count'],
            'warning_count' => (int) $row['warning_count'],
            'preflight_hash' => (string) $row['preflight_hash'],
            'request_hash' => (string) $row['request_hash'],
            'result_hash' => (string) $row['result_hash'],
            'actor_user_id' => (int) $row['actor_user_id'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function connection()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    private static function assertAuditTableReady(PDO $pdo)
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'donem_kapanis_auditleri'");
        if (!$stmt || !$stmt->fetch()) {
            JsonResponse::serverError('Donem kapanis audit migration uygulanmadi.');
        }
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }
}
