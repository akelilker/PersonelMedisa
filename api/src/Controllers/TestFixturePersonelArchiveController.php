<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Auth\SubeScope;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Personel\TestFixturePersonelArchiveException;
use Medisa\Api\Services\Personel\TestFixturePersonelArchiveService;
use PDO;

/**
 * Canonical HTTP owner for TEST_FIXTURE_ARCHIVE lifecycle.
 * Exactly one route: POST /personeller/{id}/test-fixture-archive
 */
class TestFixturePersonelArchiveController
{
    public static function archive(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.test_fixture.archive');

        $id = self::parsePositiveInt($personelId);
        if ($id === null) {
            JsonResponse::notFound('Personel bulunamadi.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $personel = self::fetchPersonel($pdo, $id);
        if ($personel === null) {
            JsonResponse::notFound('Personel bulunamadi.');
        }
        SubeScope::assertPersonelAccess($user, $request, $personel);

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        // Client cannot bypass eligibility via payload flags.
        if (array_key_exists('force', $body)
            || array_key_exists('skip_classification', $body)
            || array_key_exists('skip_bound_user_check', $body)
            || array_key_exists('isten_cikis_tarihi', $body)
            || array_key_exists('termination_date', $body)
        ) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'Archive payload eligibility bypass alanlari yasak.');
        }

        try {
            $result = TestFixturePersonelArchiveService::archive($pdo, $id, $user, null);
            $status = ($result['status'] ?? '') === TestFixturePersonelArchiveService::CODE_ALREADY_CORRECT
                ? 200
                : 201;
            JsonResponse::success($result, [], $status);
        } catch (TestFixturePersonelArchiveException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Test fixture archive basarisiz.');
        }
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, sube_id, bolum_id, birim_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param mixed $value */
    private static function parsePositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
