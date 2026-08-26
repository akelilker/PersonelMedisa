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
use Medisa\Api\Services\Personel\TestFixturePersonelClassificationService;
use PDO;

/**
 * Canonical HTTP owner for persisted TEST_FIXTURE classification.
 * Exactly one route: POST /personeller/{id}/test-fixture-classification
 */
class TestFixturePersonelClassificationController
{
    public static function classify(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.test_fixture.classify');

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

        // Client cannot assert classification without machine-verifiable evidence_kodu.
        if (array_key_exists('force', $body)
            || array_key_exists('skip_evidence', $body)
            || array_key_exists('is_test', $body)
            || array_key_exists('sinif', $body) && !array_key_exists('evidence_kodu', $body)
        ) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'Classification eligibility bypass alanlari yasak.');
        }

        $evidenceKodu = isset($body['evidence_kodu']) ? strtoupper(trim((string) $body['evidence_kodu'])) : '';
        if ($evidenceKodu === '') {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'evidence_kodu zorunlu.', 'evidence_kodu');
        }

        // Explicit client "classification=TEST_FIXTURE" alone is not evidence.
        if (array_key_exists('classification', $body)
            && !in_array($evidenceKodu, TestFixturePersonelClassificationService::allowedHttpEvidenceKodlari(), true)
        ) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'Client classification assertion evidence degildir.', 'classification');
        }

        $evidenceRef = array_key_exists('evidence_ref', $body) ? $body['evidence_ref'] : null;
        $aciklama = array_key_exists('aciklama', $body) ? $body['aciklama'] : null;

        try {
            $result = TestFixturePersonelClassificationService::classifyViaHttp(
                $pdo,
                $id,
                $evidenceKodu,
                $user,
                $evidenceRef,
                $aciklama
            );
            $status = ($result['status'] ?? '') === TestFixturePersonelClassificationService::CODE_ALREADY_CORRECT
                ? 200
                : 201;
            JsonResponse::success($result, [], $status);
        } catch (TestFixturePersonelArchiveException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Test fixture classification basarisiz.');
        }
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
