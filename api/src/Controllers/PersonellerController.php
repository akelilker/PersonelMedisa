<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelImportApplyService;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelImportHistoryService;
use Medisa\Api\Services\Personel\PersonelImportReferenceCatalogService;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\PersonelUcretException;
use Medisa\Api\Services\PersonelUcretService;
use PDO;

class PersonellerController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'personeller.view',
            'personeller.view.sube',
        ]);
        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 10) ?: 10)));
        $search = strtolower(trim((string) $request->getQuery('search', '')));
        $aktiflik = (string) $request->getQuery('aktiflik', 'tum');
        $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
        $personelTipiId = (int) ($request->getQuery('personel_tipi_id', 0) ?: 0);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['1=1'];
        $params = [];

        if ($scope !== null) {
            $where[] = 'p.sube_id = :scope_sube_id';
            $params['scope_sube_id'] = $scope;
        } elseif (count($allowedSubeIds) > 0) {
            $placeholders = [];
            foreach ($allowedSubeIds as $index => $subeId) {
                $key = 'allowed_sube_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $subeId;
            }
            $where[] = 'p.sube_id IN (' . implode(', ', $placeholders) . ')';
        }

        if ($aktiflik === 'aktif') {
            $where[] = "p.aktif_durum = 'AKTIF'";
        } elseif ($aktiflik === 'pasif') {
            $where[] = "p.aktif_durum = 'PASIF'";
        }

        if ($departmanId > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }

        if ($personelTipiId > 0) {
            $where[] = 'p.personel_tipi_id = :personel_tipi_id';
            $params['personel_tipi_id'] = $personelTipiId;
        }

        if ($search !== '') {
            $where[] = '(LOWER(p.ad) LIKE :search_ad OR LOWER(p.soyad) LIKE :search_soyad OR p.tc_kimlik_no LIKE :search_tc)';
            $searchLike = '%' . $search . '%';
            $params['search_ad'] = $searchLike;
            $params['search_soyad'] = $searchLike;
            $params['search_tc'] = $searchLike;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "
            SELECT p.*, s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
            WHERE $whereSql
            ORDER BY p.id ASC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapPersonelRow($row, $user);
        }

        JsonResponse::success(
            ['items' => $items],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ]
        );
    }

    public static function detail(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.detail.view');
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            JsonResponse::notFound();
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $exists['sube_id']);

        $sql = "
            SELECT p.*, s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
            WHERE p.id = :id
            LIMIT 1
        ";
        $detailStmt = $pdo->prepare($sql);
        $detailStmt->execute(['id' => $personelId]);
        $row = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::notFound();
        }

        JsonResponse::success(self::mapPersonelRow($row, $user));
    }

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user);

        $body = $request->getJsonBody();
        $hasSalary = self::hasSalaryField($body);
        if ($hasSalary && !RolePermissions::has($user, 'personeller.ucret.manage')) {
            JsonResponse::error(403, 'SALARY_ACCESS_FORBIDDEN', 'Ucret bilgisi yonetme yetkiniz yok.');
        }
        try {
            $payload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload($body);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(422, $e->getCodeString(), $e->getMessage(), $e->getField());
        }
        if ($hasSalary && ($payload['maas_tutari'] === null || (float) $payload['maas_tutari'] <= 0)) {
            JsonResponse::error(400, 'SALARY_AMOUNT_INVALID', 'Ücret tutarı sıfırdan büyük olmalıdır.', 'maas_tutari');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertCreateSubeScope($user, $request, $payload['sube_id']);
        try {
            PersonelCreateService::validateCreateReferences($pdo, $payload);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(422, $e->getCodeString(), $e->getMessage(), $e->getField());
        }
        self::assertTcAvailable($pdo, $payload['tc_kimlik_no']);

        $pdo->beginTransaction();
        try {
            $insertId = PersonelCreateService::insertPersonel($pdo, $payload);
            if ($hasSalary && $payload['maas_tutari'] !== null) {
                PersonelUcretService::createSalaryRecord($pdo, $insertId, [
                    'ucret_tutari' => $payload['maas_tutari'],
                    'ucret_turu' => 'NET',
                    'para_birimi' => 'TRY',
                    'gecerlilik_baslangic' => $payload['ise_giris_tarihi'],
                    'kaynak' => 'MANUEL',
                ], $user);
            }
            $row = self::fetchPersonelRowById($pdo, $insertId);
            if (!$row) {
                $pdo->rollBack();
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            $pdo->commit();
            JsonResponse::success(self::mapPersonelRow($row, $user), [], 201);
        } catch (PersonelUcretException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (PersonelCreateService::isDuplicateTcException($e) || self::isDuplicateTcException($e)) {
                self::duplicateTcResponse();
            }

            JsonResponse::serverError('Kayit olusturulamadi.');
        }
    }

    public static function update(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user);

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        $body = $request->getJsonBody();
        $hasSalary = self::hasSalaryField($body);
        if ($hasSalary && !RolePermissions::has($user, 'personeller.ucret.manage')) {
            JsonResponse::error(403, 'SALARY_ACCESS_FORBIDDEN', 'Ucret bilgisi yonetme yetkiniz yok.');
        }
        try {
            $payload = PersonelCanonicalValidator::normalizeAndValidateUpdatePayload($body);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(422, $e->getCodeString(), $e->getMessage(), $e->getField());
        }
        if ($hasSalary && (!array_key_exists('maas_tutari', $payload) || $payload['maas_tutari'] === null || (float) $payload['maas_tutari'] <= 0)) {
            JsonResponse::error(400, 'SALARY_AMOUNT_INVALID', 'Ücret tutarı sıfırdan büyük olmalıdır.', 'maas_tutari');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $current = self::fetchPersonelRowById($pdo, $personelId);
        if (!$current) {
            JsonResponse::notFound();
        }

        self::assertUpdateSubeScope($user, $request, (int) $current['sube_id'], $payload);
        self::assertAktifDurumNotChanged($current, $payload);
        self::validateUpdateReferences($pdo, $payload);

        if (array_key_exists('tc_kimlik_no', $payload)) {
            self::assertTcAvailableForUpdate($pdo, $payload['tc_kimlik_no'], $personelId);
        }

        $salaryChanged = $hasSalary
            && array_key_exists('maas_tutari', $payload)
            && (float) $payload['maas_tutari'] !== (float) ($current['maas_tutari'] ?? 0);
        $salaryAmount = $payload['maas_tutari'] ?? null;
        unset($payload['maas_tutari']);

        $pdo->beginTransaction();
        try {
            self::updatePersonelRow($pdo, $personelId, $payload);
            if ($salaryChanged) {
                PersonelUcretService::createSalaryRecord($pdo, $personelId, [
                    'ucret_tutari' => $salaryAmount,
                    'ucret_turu' => 'NET',
                    'para_birimi' => 'TRY',
                    'gecerlilik_baslangic' => isset($body['effective_date']) && trim((string) $body['effective_date']) !== ''
                        ? trim((string) $body['effective_date'])
                        : date('Y-m-d'),
                    'kaynak' => 'MANUEL',
                ], $user);
            }
            $row = self::fetchPersonelRowById($pdo, $personelId);
            if (!$row) {
                $pdo->rollBack();
                JsonResponse::serverError('Kayit guncellenemedi.');
            }

            $pdo->commit();
            JsonResponse::success(self::mapPersonelRow($row, $user));
        } catch (PersonelUcretException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (self::isDuplicateTcException($e)) {
                self::duplicateTcResponse();
            }

            JsonResponse::serverError('Kayit guncellenemedi.');
        }
    }

    public static function importTemplate(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.create');

        $csv = PersonelImportDryRunService::buildTemplateCsv();
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="personel-import-sablon.csv"');
            http_response_code(200);
        }
        echo $csv;
        exit;
    }

    public static function importReferencesCsv(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.create');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $activeSube = $request->getHeader('x-active-sube-id');

        try {
            $result = PersonelImportReferenceCatalogService::buildExport($pdo, $user, $activeSube);
        } catch (PersonelImportException $e) {
            $message = $e->getMessage();
            if (preg_match('/SQLSTATE|stack|trace|mysqli|PDO/i', $message)) {
                $message = 'Personel import referans paketi hazirlanamadi.';
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $message);
        }

        $csv = (string) $result['csv'];
        if (
            preg_match('/\btc_kimlik_no\b/i', $csv)
            || preg_match('/idempotency_key/i', $csv)
            || preg_match('/\d{11}/', $csv)
        ) {
            JsonResponse::serverError('Personel import referans response scrub hatasi.');
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header(
                'Content-Disposition: attachment; filename="'
                . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $result['filename'])
                . '"'
            );
            header(PersonelImportReferenceCatalogService::SHA_HEADER . ': ' . $result['sha256']);
            header('ETag: "' . $result['sha256'] . '"');
            http_response_code(200);
        }
        echo $csv;
        exit;
    }

    public static function importDryRun(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.create');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $csvContent = self::readImportCsvContent($request);
        $activeSube = $request->getHeader('x-active-sube-id');

        try {
            $result = PersonelImportDryRunService::dryRun($pdo, $csvContent, $user, $activeSube);
        } catch (PersonelImportException $e) {
            $message = $e->getMessage();
            if (preg_match('/\d{11}/', $message)) {
                $message = 'Personel import dogrulama hatasi.';
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $message);
        }

        JsonResponse::success($result);
    }

    public static function importApply(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $csvContent = self::readImportCsvContent($request);
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        // Multipart form fields may carry apply metadata alongside file upload.
        foreach (['manifest_hash', 'idempotency_key', 'confirmation', 'onay'] as $field) {
            if ((!isset($body[$field]) || $body[$field] === '') && isset($_POST[$field])) {
                $body[$field] = $_POST[$field];
            }
        }
        $activeSube = $request->getHeader('x-active-sube-id');

        try {
            $result = PersonelImportApplyService::apply($pdo, $csvContent, $user, $body, $activeSube);
        } catch (PersonelImportException $e) {
            $message = $e->getMessage();
            if (preg_match('/\d{11}/', $message)) {
                $message = 'Personel import apply hatasi.';
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $message);
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && preg_match('/"tc_kimlik_no"\s*:/', $encoded)) {
            JsonResponse::serverError('Personel import response scrub hatasi.');
        }

        JsonResponse::success($result, [], 201);
    }

    public static function importRunsList(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);
        $query = [
            'cursor' => $request->getQuery('cursor'),
            'limit' => $request->getQuery('limit'),
            'status' => $request->getQuery('status'),
            'date_from' => $request->getQuery('date_from'),
            'date_to' => $request->getQuery('date_to'),
        ];

        try {
            $result = PersonelImportHistoryService::listRuns(
                $pdo,
                $user,
                $query,
                $scope,
                $allowedSubeIds
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && (
            preg_match('/"tc_kimlik_no"\s*:/', $encoded)
            || preg_match('/\btc_sha256\b/', $encoded)
            || preg_match('/"idempotency_key"\s*:/', $encoded)
        )) {
            JsonResponse::serverError('Personel import history response scrub hatasi.');
        }

        JsonResponse::success(
            ['items' => $result['items']],
            ['next_cursor' => $result['next_cursor']]
        );
    }

    public static function importRunDetail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        try {
            $result = PersonelImportHistoryService::getRun(
                $pdo,
                $user,
                $id,
                $scope,
                $allowedSubeIds
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && (
            preg_match('/"tc_kimlik_no"\s*:/', $encoded)
            || preg_match('/\btc_sha256\b/', $encoded)
            || preg_match('/"idempotency_key"\s*:/', $encoded)
        )) {
            JsonResponse::serverError('Personel import history response scrub hatasi.');
        }

        JsonResponse::success($result);
    }

    public static function importRunEvidenceCsv(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        try {
            $result = PersonelImportHistoryService::buildEvidenceCsv(
                $pdo,
                $user,
                $id,
                $scope,
                $allowedSubeIds
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header(
                'Content-Disposition: attachment; filename="'
                . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $result['filename'])
                . '"'
            );
            http_response_code(200);
        }
        echo $result['csv'];
        exit;
    }

    /** @return string */
    private static function readImportCsvContent(Request $request)
    {
        if (isset($_FILES['file']) && is_array($_FILES['file'])) {
            $file = $_FILES['file'];
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi yuklenemedi.');
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size > PersonelImportDryRunService::MAX_BYTES) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_BOYUTU', 'CSV dosyasi en fazla 2 MB olabilir.');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi yuklenemedi.');
            }
            $content = file_get_contents($tmp);
            if ($content === false) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi okunamadi.');
            }

            return $content;
        }

        $body = $request->getJsonBody();
        if (isset($body['csv']) && is_string($body['csv'])) {
            return $body['csv'];
        }
        if (isset($body['csv_text']) && is_string($body['csv_text'])) {
            return $body['csv_text'];
        }

        $contentType = strtolower((string) $request->getHeader('content-type', ''));
        if (strpos($contentType, 'text/csv') !== false || strpos($contentType, 'text/plain') !== false) {
            return $request->getRawBody();
        }

        JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi veya csv alani zorunludur.');
    }

    /** @param array<string, mixed> $user */
    private static function assertWriteRole(array $user)
    {
        $allowedRoles = ['GENEL_YONETICI', 'BOLUM_YONETICISI', 'MUHASEBE'];
        if (!in_array((string) ($user['rol'] ?? ''), $allowedRoles, true)) {
            JsonResponse::forbidden();
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertCreateSubeScope(array $user, Request $request, $subeId)
    {
        $subeId = (int) $subeId;
        $headerSube = self::parseHeaderPositiveInt($request->getHeader('x-active-sube-id'));
        if ($headerSube !== null && $headerSube !== $subeId) {
            JsonResponse::forbidden();
        }

        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0) {
            return;
        }

        if (!in_array($subeId, $allowed, true)) {
            JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     */
    private static function assertUpdateSubeScope(array $user, Request $request, $currentSubeId, array $payload)
    {
        $currentSubeId = (int) $currentSubeId;
        SubeScope::assertPersonelAccess($user, $request, $currentSubeId);

        if (!array_key_exists('sube_id', $payload)) {
            return;
        }

        $targetSubeId = (int) $payload['sube_id'];
        self::assertCreateSubeScope($user, $request, $targetSubeId);

        if ($targetSubeId !== $currentSubeId) {
            JsonResponse::forbidden();
        }
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $payload */
    private static function assertAktifDurumNotChanged(array $current, array $payload)
    {
        if (!array_key_exists('aktif_durum', $payload)) {
            return;
        }

        if ((string) $payload['aktif_durum'] !== (string) $current['aktif_durum']) {
            self::validationError('aktif_durum', 'Aktif durum bu endpoint ile degistirilemez.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateCreateReferences(PDO $pdo, array $payload)
    {
        if (!self::existsActiveRecord($pdo, 'subeler', (int) $payload['sube_id'])) {
            self::validationError('sube_id', 'Gecersiz sube.');
        }
        if (!self::existsActiveRecord($pdo, 'departmanlar', (int) $payload['departman_id'])) {
            self::validationError('departman_id', 'Gecersiz departman.');
        }
        if (!self::existsActiveRecord($pdo, 'gorevler', (int) $payload['gorev_id'])) {
            self::validationError('gorev_id', 'Gecersiz gorev.');
        }
        if (!self::existsActiveRecord($pdo, 'personel_tipleri', (int) $payload['personel_tipi_id'])) {
            self::validationError('personel_tipi_id', 'Gecersiz personel tipi.');
        }

        $bagliAmirId = $payload['bagli_amir_id'];
        if ($bagliAmirId !== null) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => (int) $bagliAmirId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                self::validationError('bagli_amir_id', 'Gecersiz bagli amir.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateUpdateReferences(PDO $pdo, array $payload)
    {
        if (array_key_exists('sube_id', $payload) && !self::existsActiveRecord($pdo, 'subeler', (int) $payload['sube_id'])) {
            self::validationError('sube_id', 'Gecersiz sube.');
        }
        if (array_key_exists('departman_id', $payload) && $payload['departman_id'] !== null && !self::existsActiveRecord($pdo, 'departmanlar', (int) $payload['departman_id'])) {
            self::validationError('departman_id', 'Gecersiz departman.');
        }
        if (array_key_exists('gorev_id', $payload) && $payload['gorev_id'] !== null && !self::existsActiveRecord($pdo, 'gorevler', (int) $payload['gorev_id'])) {
            self::validationError('gorev_id', 'Gecersiz gorev.');
        }
        if (array_key_exists('personel_tipi_id', $payload) && $payload['personel_tipi_id'] !== null && !self::existsActiveRecord($pdo, 'personel_tipleri', (int) $payload['personel_tipi_id'])) {
            self::validationError('personel_tipi_id', 'Gecersiz personel tipi.');
        }

        if (array_key_exists('bagli_amir_id', $payload) && $payload['bagli_amir_id'] !== null) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => (int) $payload['bagli_amir_id']]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                self::validationError('bagli_amir_id', 'Gecersiz bagli amir.');
            }
        }
    }

    private static function assertTcAvailable(PDO $pdo, $tcKimlikNo)
    {
        if (PersonelCreateService::tcExists($pdo, (string) $tcKimlikNo)) {
            self::duplicateTcResponse();
        }
    }

    private static function assertTcAvailableForUpdate(PDO $pdo, $tcKimlikNo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc_kimlik_no AND id <> :id LIMIT 1');
        $stmt->execute([
            'tc_kimlik_no' => (string) $tcKimlikNo,
            'id' => (int) $personelId,
        ]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            self::duplicateTcResponse();
        }
    }

    /** @param array<string, mixed> $payload */
    private static function updatePersonelRow(PDO $pdo, $personelId, array $payload)
    {
        if (count($payload) === 0) {
            return;
        }

        $allowedColumns = [
            'tc_kimlik_no',
            'ad',
            'soyad',
            'dogum_tarihi',
            'telefon',
            'acil_durum_kisi',
            'acil_durum_telefon',
            'sicil_no',
            'ise_giris_tarihi',
            'sube_id',
            'departman_id',
            'gorev_id',
            'personel_tipi_id',
            'bagli_amir_id',
            'aktif_durum',
            'dogum_yeri',
            'kan_grubu',
            'ucret_tipi_id',
            'maas_tutari',
            'prim_kurali_id',
        ];

        $set = [];
        $params = ['id' => (int) $personelId];
        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }

            $set[] = $column . ' = :' . $column;
            $params[$column] = $payload[$column];
        }

        if (count($set) === 0) {
            return;
        }

        $stmt = $pdo->prepare('UPDATE personeller SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonelRowById(PDO $pdo, $personelId)
    {
        $sql = "
            SELECT p.*, s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
            WHERE p.id = :id
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function existsActiveRecord(PDO $pdo, $table, $id)
    {
        $allowedTables = ['subeler', 'departmanlar', 'gorevler', 'personel_tipleri'];
        if (!in_array($table, $allowedTables, true)) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT id FROM $table WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => (int) $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function duplicateTcResponse()
    {
        JsonResponse::error(409, 'DUPLICATE_TC_KIMLIK_NO', 'Bu T.C. Kimlik No ile kayıt açılamaz.', 'tc_kimlik_no');
    }

    private static function isDuplicateTcException(\PDOException $e)
    {
        if ($e->getCode() !== '23000') {
            return false;
        }

        $errorInfo = $e->errorInfo;
        if (!is_array($errorInfo) || !isset($errorInfo[1]) || (int) $errorInfo[1] !== 1062) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return strpos($message, 'uq_personeller_tc') !== false || strpos($message, 'tc_kimlik_no') !== false;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    /** @param mixed $value */
    private static function parseHeaderPositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;
        return $parsed > 0 ? $parsed : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapPersonelRow(array $row, array $user)
    {
        $ucretTipiId = $row['ucret_tipi_id'] !== null ? (int) $row['ucret_tipi_id'] : null;
        $primKuraliId = $row['prim_kurali_id'] !== null ? (int) $row['prim_kurali_id'] : null;
        $ucretTipiAdlari = [1 => 'Aylik', 2 => 'Gunluk', 3 => 'Saatlik'];
        $primKuraliAdlari = [1 => 'Devamsizlik Primi Yok', 2 => 'Tam Prim', 3 => 'Kismi Prim'];
        $maasTutari = $row['maas_tutari'] !== null ? (float) $row['maas_tutari'] : null;

        $mapped = [
            'id' => (int) $row['id'],
            'tc_kimlik_no' => (string) $row['tc_kimlik_no'],
            'ad' => (string) $row['ad'],
            'soyad' => (string) $row['soyad'],
            'aktif_durum' => (string) $row['aktif_durum'],
            'sube_id' => (int) $row['sube_id'],
            'telefon' => $row['telefon'],
            'dogum_tarihi' => $row['dogum_tarihi'],
            'sicil_no' => $row['sicil_no'],
            'dogum_yeri' => $row['dogum_yeri'],
            'kan_grubu' => $row['kan_grubu'],
            'ise_giris_tarihi' => $row['ise_giris_tarihi'],
            'acil_durum_kisi' => $row['acil_durum_kisi'],
            'acil_durum_telefon' => $row['acil_durum_telefon'],
            'departman_id' => $row['departman_id'] !== null ? (int) $row['departman_id'] : null,
            'gorev_id' => $row['gorev_id'] !== null ? (int) $row['gorev_id'] : null,
            'personel_tipi_id' => $row['personel_tipi_id'] !== null ? (int) $row['personel_tipi_id'] : null,
            'bagli_amir_id' => $row['bagli_amir_id'] !== null ? (int) $row['bagli_amir_id'] : null,
            'sube_adi' => $row['sube_adi'],
            'departman_adi' => $row['departman_adi'],
            'gorev_adi' => $row['gorev_adi'],
            'personel_tipi_adi' => $row['personel_tipi_adi'],
            'referans_adlari' => [
                'sube' => $row['sube_adi'],
                'departman' => $row['departman_adi'],
                'gorev' => $row['gorev_adi'],
                'personel_tipi' => $row['personel_tipi_adi'],
            ],
            'ucret_tipi_id' => $ucretTipiId,
            'maas_tutari' => $maasTutari,
            'net_maas_tutari' => $maasTutari,
            'prim_kurali_id' => $primKuraliId,
            'ucret_tipi_adi' => $ucretTipiId !== null && isset($ucretTipiAdlari[$ucretTipiId])
                ? $ucretTipiAdlari[$ucretTipiId]
                : null,
            'prim_kurali_adi' => $primKuraliId !== null && isset($primKuraliAdlari[$primKuraliId])
                ? $primKuraliAdlari[$primKuraliId]
                : null,
        ];

        if (!RolePermissions::has($user, 'personeller.ucret.view')) {
            unset($mapped['maas_tutari'], $mapped['net_maas_tutari'], $mapped['brut_maas_tutari']);
        }

        return $mapped;
    }

    /** @param array<string, mixed> $body */
    private static function hasSalaryField(array $body)
    {
        return array_key_exists('maas_tutari', $body)
            || array_key_exists('net_maas_tutari', $body)
            || array_key_exists('brut_maas_tutari', $body);
    }
}
