<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Attendance\DisiplinAdayProjectionService;
use Medisa\Api\Services\Attendance\DisiplinVakaService;
use PDO;
use RuntimeException;
use Throwable;

class DisiplinVakaController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'disiplin.view');

        $pdo = Connection::get();
        self::assertSchema($pdo);

        $subeId = SubeScope::resolveScope($user, $request);
        $personelId = self::optionalPositiveInt($request->getQuery('personel_id'));
        $surecId = self::optionalPositiveInt($request->getQuery('surec_id'));
        $ay = trim((string) $request->getQuery('ay', ''));
        $openOnly = self::parseBool($request->getQuery('open_only', '1'));

        if ($surecId !== null) {
            $vaka = DisiplinVakaService::getBySurecId($pdo, $surecId);
            if ($vaka) {
                self::assertPersonelScope($user, $request, $pdo, (int) $vaka['personel_id']);
            }
            JsonResponse::success(['items' => $vaka ? [$vaka] : []]);

            return;
        }

        if ($personelId !== null) {
            self::assertPersonelScope($user, $request, $pdo, $personelId);
        }

        if ($openOnly) {
            $items = DisiplinVakaService::listOpen(
                $pdo,
                $subeId,
                $personelId,
                $ay !== '' ? $ay : null
            );
        } else {
            $items = self::listAll($pdo, $subeId, $personelId, $ay !== '' ? $ay : null);
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'disiplin.view');

        $pdo = Connection::get();
        self::assertSchema($pdo);

        $vaka = DisiplinVakaService::getById($pdo, (int) $id);
        if (!$vaka) {
            JsonResponse::notFound('Disiplin vakasi bulunamadi.');
        }

        self::assertPersonelScope($user, $request, $pdo, (int) $vaka['personel_id']);
        $audits = DisiplinVakaService::listAudits($pdo, (int) $vaka['id']);
        JsonResponse::success(['item' => $vaka, 'audits' => $audits]);
    }

    public static function generate(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'disiplin.review');

        $payload = $request->getJsonBody();
        if (!is_array($payload)) {
            $payload = [];
        }

        $ay = trim((string) ($payload['ay'] ?? $request->getQuery('ay', '')));
        $subeId = isset($payload['sube_id']) ? self::optionalPositiveInt($payload['sube_id']) : SubeScope::resolveScope($user, $request);
        $personelId = self::optionalPositiveInt($payload['personel_id'] ?? null);

        $pdo = Connection::get();
        self::assertSchema($pdo);

        if ($personelId !== null) {
            self::assertPersonelScope($user, $request, $pdo, $personelId);
        }

        try {
            $result = DisiplinAdayProjectionService::projectForMonth($pdo, $user, $ay, $subeId, $personelId);
            JsonResponse::success($result);
        } catch (RuntimeException $e) {
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            JsonResponse::serverError('Disiplin aday projeksiyonu basarisiz.');
        }
    }

    public static function ikInceleme(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'disiplin.review');

        $payload = $request->getJsonBody();
        $note = is_array($payload) && isset($payload['note']) ? (string) $payload['note'] : null;

        $pdo = Connection::get();
        self::assertSchema($pdo);
        self::assertVakaScope($user, $request, $pdo, (int) $id);

        try {
            $pdo->beginTransaction();
            $item = DisiplinVakaService::ikReview($pdo, $user, (int) $id, $note);
            $pdo->commit();
            JsonResponse::success(['item' => $item]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('IK inceleme kaydedilemedi.');
        }
    }

    public static function savunmaTalep(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'disiplin.defense_manage');

        $payload = $request->getJsonBody();
        if (!is_array($payload)) {
            JsonResponse::badRequest('Gecersiz payload.');
        }

        $pdo = Connection::get();
        self::assertSchema($pdo);
        self::assertVakaScope($user, $request, $pdo, (int) $id);

        try {
            $pdo->beginTransaction();
            $item = DisiplinVakaService::requestDefense($pdo, $user, (int) $id, $payload);
            $pdo->commit();
            JsonResponse::success(['item' => $item]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Savunma talebi kaydedilemedi.');
        }
    }

    public static function savunmaBelge(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'disiplin.defense_manage');

        $payload = $request->getJsonBody();
        if (!is_array($payload)) {
            JsonResponse::badRequest('Gecersiz payload.');
        }
        $belgeSurecId = self::optionalPositiveInt($payload['belge_surec_id'] ?? null);
        if ($belgeSurecId === null) {
            JsonResponse::badRequest('belge_surec_id zorunludur.');
        }

        $pdo = Connection::get();
        self::assertSchema($pdo);
        self::assertVakaScope($user, $request, $pdo, (int) $id);

        try {
            $pdo->beginTransaction();
            $item = DisiplinVakaService::attachDefenseBelge($pdo, $user, (int) $id, $belgeSurecId);
            $pdo->commit();
            JsonResponse::success(['item' => $item]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Savunma belgesi baglanamadi.');
        }
    }

    public static function nihaiKarar(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'disiplin.final_decision');

        $payload = $request->getJsonBody();
        if (!is_array($payload)) {
            JsonResponse::badRequest('Gecersiz payload.');
        }
        $nihaiKarar = trim((string) ($payload['nihai_karar'] ?? ''));
        $gerekce = isset($payload['gerekce']) ? trim((string) $payload['gerekce']) : null;

        $pdo = Connection::get();
        self::assertSchema($pdo);
        self::assertVakaScope($user, $request, $pdo, (int) $id);

        try {
            $pdo->beginTransaction();
            $item = DisiplinVakaService::finalDecision($pdo, $user, (int) $id, $nihaiKarar, $gerekce);
            $pdo->commit();
            JsonResponse::success(['item' => $item]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Nihai karar kaydedilemedi.');
        }
    }

    public static function islemsizKapat(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, ['disiplin.review', 'disiplin.final_decision']);

        $payload = $request->getJsonBody();
        $gerekce = is_array($payload) && isset($payload['gerekce']) ? trim((string) $payload['gerekce']) : null;

        $pdo = Connection::get();
        self::assertSchema($pdo);
        self::assertVakaScope($user, $request, $pdo, (int) $id);

        try {
            $pdo->beginTransaction();
            $item = DisiplinVakaService::closeNoAction($pdo, $user, (int) $id, $gerekce);
            $pdo->commit();
            JsonResponse::success(['item' => $item]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::badRequest($e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Islemsiz kapatma basarisiz.');
        }
    }

    private static function assertSchema(PDO $pdo)
    {
        if (!DisiplinVakaService::tableExists($pdo)) {
            JsonResponse::error(503, 'SCHEMA_NOT_READY', 'disiplin_vakalar tablosu hazir degil.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertVakaScope(array $user, Request $request, PDO $pdo, $vakaId)
    {
        $vaka = DisiplinVakaService::getById($pdo, (int) $vakaId);
        if (!$vaka) {
            JsonResponse::notFound('Disiplin vakasi bulunamadi.');
        }
        self::assertPersonelScope($user, $request, $pdo, (int) $vaka['personel_id']);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listAll(PDO $pdo, $subeId, $personelId, $ay)
    {
        $where = ['1=1'];
        $params = [];
        if ($subeId !== null && (int) $subeId > 0) {
            $where[] = 'sube_id = :sube_id';
            $params['sube_id'] = (int) $subeId;
        }
        if ($personelId !== null && (int) $personelId > 0) {
            $where[] = 'personel_id = :personel_id';
            $params['personel_id'] = (int) $personelId;
        }
        if ($ay !== null && $ay !== '') {
            $where[] = 'ay = :ay';
            $params['ay'] = (string) $ay;
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM disiplin_vakalar WHERE ' . implode(' AND ', $where) . ' ORDER BY tarih DESC, id DESC'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = DisiplinVakaService::getById($pdo, (int) $row['id']);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @param mixed $value */
    private static function optionalPositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** @param mixed $value */
    private static function parseBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));

        return !in_array($normalized, ['0', 'false', 'no', 'hayir'], true);
    }
}
