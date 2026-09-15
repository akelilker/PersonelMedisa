<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Personel\TestFixturePersonelArchiveException;
use Medisa\Api\Services\Personel\TestFixturePersonelPurgeService;
use PDO;

/**
 * Canonical HTTP owner for TEST_FIXTURE personel lifecycle (not generic personel delete).
 * POST /personeller/{id}/test-fixture-purge
 *
 * mode=hard_purge (default, fail-closed hard delete): confirm=PURGE_TEST_FIXTURE
 * mode=retention_safe_tombstone (PII de-identify, no delete): confirm=TOMBSTONE_TEST_FIXTURE
 *
 * Default dry_run=true for both modes.
 */
class TestFixturePersonelPurgeController
{
    public static function purge(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.test_fixture.purge');

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
        if (array_key_exists('force', $body)
            || array_key_exists('skip_classification', $body)
            || array_key_exists('personel_ids', $body)
            || array_key_exists('isten_cikis_tarihi', $body)
        ) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'Purge payload eligibility bypass alanlari yasak.');
        }

        $mode = self::resolveMode($body);

        $dryRun = true;
        if (array_key_exists('dry_run', $body)) {
            $raw = $body['dry_run'];
            $dryRun = !($raw === false || $raw === 0 || $raw === '0' || $raw === 'false');
        }
        $confirm = array_key_exists('confirm', $body) ? $body['confirm'] : null;

        try {
            if ($mode === TestFixturePersonelPurgeService::MODE_RETENTION_SAFE_TOMBSTONE) {
                // Retention-safe destruction: de-identify only, FK evidence stays intact.
                JsonResponse::success(
                    TestFixturePersonelPurgeService::tombstone($pdo, $id, $user, $dryRun, $confirm)
                );
            }

            $result = TestFixturePersonelPurgeService::purge($pdo, $id, $user, $dryRun, $confirm);
            if (($result['purge_safe'] ?? false) !== true) {
                JsonResponse::error(
                    409,
                    (string) (($result['blockers'][0]['code'] ?? TestFixturePersonelPurgeService::CODE_SHARED_OR_UNKNOWN)),
                    'Test fixture purge fail-closed; shared/unknown/user blocker var.',
                    'dependencies',
                    $result
                );
            }
            $status = !empty($result['executed']) ? 200 : 200;
            JsonResponse::success($result, [], $status);
        } catch (TestFixturePersonelArchiveException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Test fixture purge basarisiz.');
        }
    }

    /**
     * Fixture lifecycle mode selector. Absent `mode` keeps the historical fail-closed hard purge,
     * so an existing caller never changes behaviour by omission.
     *
     * @param array<string, mixed> $body
     * @return string
     */
    private static function resolveMode(array $body)
    {
        if (!array_key_exists('mode', $body)) {
            return TestFixturePersonelPurgeService::MODE_HARD_PURGE;
        }
        $raw = strtolower(trim((string) $body['mode']));
        if ($raw === TestFixturePersonelPurgeService::MODE_RETENTION_SAFE_TOMBSTONE || $raw === 'tombstone') {
            return TestFixturePersonelPurgeService::MODE_RETENTION_SAFE_TOMBSTONE;
        }
        if ($raw === TestFixturePersonelPurgeService::MODE_HARD_PURGE || $raw === 'purge') {
            return TestFixturePersonelPurgeService::MODE_HARD_PURGE;
        }

        JsonResponse::error(422, 'VALIDATION_ERROR', 'Gecersiz test fixture mode.', 'mode');

        return TestFixturePersonelPurgeService::MODE_HARD_PURGE;
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, sube_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['bolum_id'] = null;
        $row['birim_id'] = null;

        return $row;
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
