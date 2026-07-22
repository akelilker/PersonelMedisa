<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeBase64Guard;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeContracts;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeKayitRepository;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;
use PDO;
use RuntimeException;

class PersonelBelgelerController
{
    private const BELGE_DURUMU_ALT_TUR = 'BELGE_DURUMU';

    public static function belgeDurumu(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReadPermission($user);
        $pdo = self::getConnection();
        $personel = self::fetchPersonelForScope($pdo, $personelId);
        self::assertPersonelReadable($user, $request, $personel);

        JsonResponse::success(['items' => self::fetchBelgeDurumuItems($pdo, (int) $personel['id'])]);
    }

    public static function updateBelgeDurumu(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWritePermission($user);

        $pdo = self::getConnection();
        $personel = self::fetchPersonelForScope($pdo, $personelId);
        self::assertPersonelReadable($user, $request, $personel);

        if (strtoupper((string) $personel['aktif_durum']) === 'PASIF') {
            self::validationError('personel_id', 'Pasif personelin belge durumu güncellenemez.');
        }

        $items = self::normalizeBelgeDurumuPayload($request->getJsonBody());

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE surecler
                SET state = 'IPTAL'
                WHERE personel_id = :personel_id
                  AND surec_turu = 'BELGE'
                  AND alt_tur = :alt_tur
                  AND state = 'AKTIF'
            ");
            $stmt->execute([
                'personel_id' => (int) $personel['id'],
                'alt_tur' => self::BELGE_DURUMU_ALT_TUR,
            ]);

            self::insertSurecRow($pdo, [
                'personel_id' => (int) $personel['id'],
                'surec_turu' => 'BELGE',
                'alt_tur' => self::BELGE_DURUMU_ALT_TUR,
                'baslangic_tarihi' => date('Y-m-d'),
                'bitis_tarihi' => null,
                'aciklama' => self::encodeMetadata([
                    '_personel_belge_durumu' => true,
                    'items' => $items,
                ]),
                'state' => 'AKTIF',
            ]);

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Belge durumu kaydedilemedi.');
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function listKayitlari(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReadPermission($user);
        $pdo = self::getConnection();
        $personel = self::fetchPersonelForScope($pdo, $personelId);
        self::assertPersonelReadable($user, $request, $personel);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 50) ?: 50)));
        $state = strtoupper(trim((string) $request->getQuery('state', 'tum')));
        if ($state === '') {
            $state = 'tum';
        }
        if (!in_array($state, ['AKTIF', 'IPTAL', 'TUM'], true)) {
            self::validationError('state', 'Gecersiz durum filtresi.');
        }

        $where = [
            "personel_id = :personel_id",
            "surec_turu = 'BELGE'",
            "(alt_tur IS NULL OR alt_tur <> :status_alt_tur)",
        ];
        $params = [
            'personel_id' => (int) $personel['id'],
            'status_alt_tur' => self::BELGE_DURUMU_ALT_TUR,
        ];
        if ($state !== 'TUM') {
            $where[] = 'state = :state';
            $params['state'] = $state;
        }

        $whereSql = implode(' AND ', $where);
        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM surecler WHERE $whereSql");
            $countStmt->execute($params);
            $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $offset = ($page - 1) * $limit;
            $stmt = $pdo->prepare("
                SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                       aciklama, state, created_at, updated_at
                FROM surecler
                WHERE $whereSql
                ORDER BY id DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            JsonResponse::serverError('Belge kayitlari listelenemedi.');
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $surecIds = [];
        foreach ($rows as $row) {
            $surecIds[] = (int) $row['id'];
        }
        $versionMap = self::fetchActiveVersionsBySurecIds($pdo, $surecIds);
        $yukleyenMap = self::fetchYukleyenMap($pdo, $versionMap);
        $items = [];
        foreach ($rows as $row) {
            $surecId = (int) $row['id'];
            $items[] = self::mapBelgeKaydiRow(
                $pdo,
                $row,
                $user,
                $versionMap[$surecId] ?? null,
                $yukleyenMap
            );
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

    public static function createKaydi(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWritePermission($user);

        $pdo = self::getConnection();
        $personel = self::fetchPersonelForScope($pdo, $personelId);
        self::assertPersonelReadable($user, $request, $personel);

        if (strtoupper((string) $personel['aktif_durum']) === 'PASIF') {
            self::validationError('personel_id', 'Pasif personele belge kaydı eklenemez.');
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            self::validationError('body', 'Istek govdesi gecersiz.');
        }

        $payload = self::normalizeAndValidateCreatePayload($body);
        self::assertDateRangeValid($payload['baslangic_tarihi'], $payload['bitis_tarihi']);

        if ($payload['belge_no'] !== null
            && PersonelBelgeKayitRepository::countActiveDuplicate(
                $pdo,
                (int) $personel['id'],
                $payload['kayit_tipi'],
                $payload['belge_no'],
                $payload['ad']
            ) > 0) {
            JsonResponse::error(422, 'PERSONEL_BELGE_AKTIF_CAKISMA', 'Ayni tip ve belge numarasi ile aktif kayit zaten var.', 'belge_no');
        }

        $filePayload = self::parseOptionalFilePayload($body);
        $hasFile = $filePayload !== null;
        if ($hasFile) {
            self::assertSchemaForFileOps($pdo);
        }

        $dbBaslangic = $payload['baslangic_tarihi'] !== null ? $payload['baslangic_tarihi'] : date('Y-m-d');
        $userId = self::userId($user);
        $orphanStorageKey = null;

        $pdo->beginTransaction();
        try {
            $insertId = self::insertSurecRow($pdo, [
                'personel_id' => (int) $personel['id'],
                'surec_turu' => 'BELGE',
                'alt_tur' => $payload['kayit_tipi'],
                'baslangic_tarihi' => $dbBaslangic,
                'bitis_tarihi' => $payload['bitis_tarihi'],
                'aciklama' => self::encodeMetadata(array_merge(
                    ['_personel_belge_kaydi' => true],
                    $payload
                )),
                'state' => 'AKTIF',
            ]);

            $surumId = null;
            $auditSha = null;
            $auditByte = null;
            $auditMime = null;

            if ($hasFile && $filePayload !== null) {
                $stored = PersonelBelgeStorageService::writeNewVersion($filePayload['bytes'], $filePayload['extension']);
                $orphanStorageKey = $stored['storage_key'];
                $surumId = PersonelBelgeKayitRepository::insertVersion($pdo, [
                    'surec_id' => $insertId,
                    'personel_id' => (int) $personel['id'],
                    'surum_no' => 1,
                    'aktif_mi' => true,
                    'storage_key' => $stored['storage_key'],
                    'orijinal_dosya_adi' => $filePayload['original_name'],
                    'mime_type' => $filePayload['mime'],
                    'uzanti' => $filePayload['extension'],
                    'byte_boyutu' => $stored['byte_boyutu'],
                    'sha256' => $stored['sha256'],
                    'yukleyen_kullanici_id' => $userId,
                ]);
                $auditSha = $stored['sha256'];
                $auditByte = $stored['byte_boyutu'];
                $auditMime = $filePayload['mime'];
                $orphanStorageKey = null;
            }

            PersonelBelgeKayitRepository::insertAudit(
                $pdo,
                $insertId,
                (int) $personel['id'],
                PersonelBelgeContracts::AUDIT_CREATED,
                null,
                $payload,
                $userId,
                null,
                $surumId,
                $auditSha,
                $auditByte,
                $auditMime
            );

            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($orphanStorageKey !== null) {
                PersonelBelgeStorageService::deleteKey($orphanStorageKey);
            }
            self::respondStorageError($e);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($orphanStorageKey !== null) {
                PersonelBelgeStorageService::deleteKey($orphanStorageKey);
            }
            JsonResponse::serverError('Belge kaydı oluşturulamadı.');
        }

        $row = self::fetchBelgeKaydiRowById($pdo, $insertId);
        if (!$row) {
            JsonResponse::serverError('Belge kaydı oluşturulamadı.');
        }

        JsonResponse::success(self::mapBelgeKaydiRow($pdo, $row, $user), [], 201);
    }

    public static function getKaydi(Request $request, $kayitId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReadPermission($user);

        $kayitId = self::parsePositiveInt($kayitId);
        if ($kayitId === null) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $pdo = self::getConnection();
        $row = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$row) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, (int) $row['personel_id']);
        self::assertPersonelReadable($user, $request, $personel);

        JsonResponse::success(self::mapBelgeKaydiRow($pdo, $row, $user));
    }

    public static function updateKaydi(Request $request, $kayitId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWritePermission($user);

        $kayitId = self::parsePositiveInt($kayitId);
        if ($kayitId === null) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            self::validationError('body', 'Guncelleme govdesi gecersiz.');
        }

        $pdo = self::getConnection();
        $row = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$row) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, (int) $row['personel_id']);
        self::assertPersonelReadable($user, $request, $personel);

        if (strtoupper((string) $personel['aktif_durum']) === 'PASIF') {
            self::validationError('personel_id', 'Pasif personelin belge kaydi guncellenemez.');
        }

        if (strtoupper((string) ($row['state'] ?? '')) === 'IPTAL') {
            JsonResponse::error(409, 'CONFLICT', 'Iptal edilmis belge kaydi guncellenemez.');
        }

        $existingMeta = self::extractKayitMetadata($row);
        $payload = self::normalizeAndValidateUpdatePayload($body, $existingMeta);
        self::assertDateRangeValid($payload['baslangic_tarihi'], $payload['bitis_tarihi']);

        if ($payload['belge_no'] !== null
            && PersonelBelgeKayitRepository::countActiveDuplicate(
                $pdo,
                (int) $row['personel_id'],
                $payload['kayit_tipi'],
                $payload['belge_no'],
                $payload['ad'],
                $kayitId
            ) > 0) {
            JsonResponse::error(422, 'PERSONEL_BELGE_AKTIF_CAKISMA', 'Ayni tip ve belge numarasi ile aktif kayit zaten var.', 'belge_no');
        }

        $userId = self::userId($user);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE surecler
                SET alt_tur = :alt_tur,
                    baslangic_tarihi = :baslangic_tarihi,
                    bitis_tarihi = :bitis_tarihi,
                    aciklama = :aciklama
                WHERE id = :id
                  AND state = \'AKTIF\'
            ');
            $stmt->execute([
                'alt_tur' => $payload['kayit_tipi'],
                'baslangic_tarihi' => $payload['baslangic_tarihi'] !== null
                    ? $payload['baslangic_tarihi']
                    : (string) $row['baslangic_tarihi'],
                'bitis_tarihi' => $payload['bitis_tarihi'],
                'aciklama' => self::encodeMetadata(array_merge(
                    ['_personel_belge_kaydi' => true],
                    $payload
                )),
                'id' => $kayitId,
            ]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                JsonResponse::error(409, 'CONFLICT', 'Belge kaydi guncellenemedi.');
            }

            PersonelBelgeKayitRepository::insertAudit(
                $pdo,
                $kayitId,
                (int) $row['personel_id'],
                PersonelBelgeContracts::AUDIT_METADATA_UPDATED,
                $existingMeta,
                $payload,
                $userId,
                null,
                null,
                null,
                null,
                null
            );

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Belge kaydı guncellenemedi.');
        }

        $updated = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$updated) {
            JsonResponse::serverError('Belge kaydı guncellenemedi.');
        }

        JsonResponse::success(self::mapBelgeKaydiRow($pdo, $updated, $user));
    }

    public static function replaceDosya(Request $request, $kayitId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWritePermission($user);

        $kayitId = self::parsePositiveInt($kayitId);
        if ($kayitId === null) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            self::validationError('body', 'Istek govdesi gecersiz.');
        }

        $pdo = self::getConnection();
        self::assertSchemaForFileOps($pdo);

        $row = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$row) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, (int) $row['personel_id']);
        self::assertPersonelReadable($user, $request, $personel);

        if (strtoupper((string) $personel['aktif_durum']) === 'PASIF') {
            self::validationError('personel_id', 'Pasif personelin belge dosyasi degistirilemez.');
        }

        if (strtoupper((string) ($row['state'] ?? '')) === 'IPTAL') {
            JsonResponse::error(409, 'CONFLICT', 'Iptal edilmis belge kaydinin dosyasi degistirilemez.');
        }

        $filePayload = self::parseRequiredFilePayload($body);
        $existingMeta = self::extractKayitMetadata($row);
        $userId = self::userId($user);
        $orphanStorageKey = null;

        $pdo->beginTransaction();
        try {
            // Serialize concurrent replaces on the same belge (exactly one aktif version).
            $locked = PersonelBelgeKayitRepository::lockSurecRowForUpdate($pdo, $kayitId);
            if ($locked === null) {
                $pdo->rollBack();
                JsonResponse::notFound('Belge kaydi bulunamadi.');
            }
            if (strtoupper((string) ($locked['state'] ?? '')) === 'IPTAL') {
                $pdo->rollBack();
                JsonResponse::error(409, 'CONFLICT', 'Iptal edilmis belge kaydinin dosyasi degistirilemez.');
            }

            $stored = PersonelBelgeStorageService::writeNewVersion($filePayload['bytes'], $filePayload['extension']);
            $orphanStorageKey = $stored['storage_key'];
            $surumNo = PersonelBelgeKayitRepository::nextSurumNo($pdo, $kayitId);
            $surumId = PersonelBelgeKayitRepository::insertVersion($pdo, [
                'surec_id' => $kayitId,
                'personel_id' => (int) $row['personel_id'],
                'surum_no' => $surumNo,
                'aktif_mi' => true,
                'storage_key' => $stored['storage_key'],
                'orijinal_dosya_adi' => $filePayload['original_name'],
                'mime_type' => $filePayload['mime'],
                'uzanti' => $filePayload['extension'],
                'byte_boyutu' => $stored['byte_boyutu'],
                'sha256' => $stored['sha256'],
                'yukleyen_kullanici_id' => $userId,
            ]);
            $orphanStorageKey = null;

            PersonelBelgeKayitRepository::insertAudit(
                $pdo,
                $kayitId,
                (int) $row['personel_id'],
                PersonelBelgeContracts::AUDIT_FILE_REPLACED,
                $existingMeta,
                $existingMeta,
                $userId,
                null,
                $surumId,
                $stored['sha256'],
                $stored['byte_boyutu'],
                $filePayload['mime']
            );

            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($orphanStorageKey !== null) {
                PersonelBelgeStorageService::deleteKey($orphanStorageKey);
            }
            self::respondStorageError($e);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($orphanStorageKey !== null) {
                PersonelBelgeStorageService::deleteKey($orphanStorageKey);
            }
            JsonResponse::serverError('Belge dosyasi degistirilemedi.');
        }

        $updated = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$updated) {
            JsonResponse::serverError('Belge dosyasi degistirilemedi.');
        }

        JsonResponse::success(self::mapBelgeKaydiRow($pdo, $updated, $user));
    }

    public static function cancelKaydi(Request $request, $kayitId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertCancelPermission($user);

        $kayitId = self::parsePositiveInt($kayitId);
        if ($kayitId === null) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            self::validationError('body', 'Istek govdesi gecersiz.');
        }

        $iptalNedeni = isset($body['iptal_nedeni']) ? trim((string) $body['iptal_nedeni']) : '';
        if ($iptalNedeni === '') {
            self::validationError('iptal_nedeni', 'Iptal nedeni zorunludur.');
        }

        $pdo = self::getConnection();
        $row = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$row) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, (int) $row['personel_id']);
        self::assertPersonelReadable($user, $request, $personel);

        $state = strtoupper((string) ($row['state'] ?? ''));
        if ($state === 'IPTAL') {
            JsonResponse::success(self::mapBelgeKaydiRow($pdo, $row, $user));
        }

        $existingMeta = self::extractKayitMetadata($row);
        $userId = self::userId($user);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE surecler SET state = 'IPTAL' WHERE id = :id AND state = 'AKTIF'");
            $stmt->execute(['id' => $kayitId]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                JsonResponse::error(409, 'CONFLICT', 'Belge kaydi iptal edilemedi.');
            }

            PersonelBelgeKayitRepository::insertAudit(
                $pdo,
                $kayitId,
                (int) $row['personel_id'],
                PersonelBelgeContracts::AUDIT_CANCELLED,
                $existingMeta,
                $existingMeta,
                $userId,
                $iptalNedeni,
                null,
                null,
                null,
                null
            );

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Belge kaydı iptal edilemedi.');
        }

        $updated = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$updated) {
            JsonResponse::serverError('Belge kaydı iptal edilemedi.');
        }

        JsonResponse::success(self::mapBelgeKaydiRow($pdo, $updated, $user));
    }

    public static function indir(Request $request, $kayitId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReadPermission($user);

        $kayitId = self::parsePositiveInt($kayitId);
        if ($kayitId === null) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $pdo = self::getConnection();
        self::assertSchemaForFileOps($pdo);

        $row = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$row) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, (int) $row['personel_id']);
        self::assertPersonelReadable($user, $request, $personel);

        $surumId = self::parsePositiveInt($request->getQuery('surum_id'));
        $version = null;
        if ($surumId !== null) {
            $version = PersonelBelgeKayitRepository::fetchVersionById($pdo, $surumId, $kayitId);
            if (!$version) {
                JsonResponse::notFound('Belge surumu bulunamadi.');
            }
        } else {
            $version = PersonelBelgeKayitRepository::fetchActiveVersion($pdo, $kayitId);
            if (!$version) {
                JsonResponse::notFound('Aktif belge dosyasi bulunamadi.');
            }
        }

        try {
            $path = PersonelBelgeStorageService::resolvePath((string) $version['storage_key']);
        } catch (RuntimeException $e) {
            self::respondStorageError($e, 404);
        }

        $filename = self::sanitizeDownloadFilename((string) $version['orijinal_dosya_adi']);
        $mime = (string) $version['mime_type'];
        $size = (int) $version['byte_boyutu'];

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            if ($size > 0) {
                header('Content-Length: ' . $size);
            }
            http_response_code(200);
        }

        readfile($path);
        exit;
    }

    public static function gecmis(Request $request, $kayitId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReadPermission($user);

        $kayitId = self::parsePositiveInt($kayitId);
        if ($kayitId === null) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $pdo = self::getConnection();
        $row = self::fetchBelgeKaydiRowById($pdo, $kayitId);
        if (!$row) {
            JsonResponse::notFound('Belge kaydi bulunamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, (int) $row['personel_id']);
        self::assertPersonelReadable($user, $request, $personel);

        $versions = PersonelBelgeKayitRepository::listVersions($pdo, $kayitId);
        $yukleyenMap = self::fetchYukleyenMap($pdo, $versions);
        $mappedVersions = [];
        foreach ($versions as $version) {
            $mappedVersions[] = self::mapVersionPublic($version, $yukleyenMap);
        }

        $audits = [];
        foreach (PersonelBelgeKayitRepository::listAudits($pdo, $kayitId) as $audit) {
            $audits[] = self::mapAuditPublic($audit);
        }

        JsonResponse::success([
            'surumler' => $mappedVersions,
            'auditler' => $audits,
        ]);
    }

    public static function belgeTakip(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReadPermission($user);

        $pdo = self::getConnection();
        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        $departmanId = self::parsePositiveInt($request->getQuery('departman_id'));
        $personelId = self::parsePositiveInt($request->getQuery('personel_id'));
        $kayitTipi = strtoupper(trim((string) $request->getQuery('kayit_tipi', '')));
        $takipDurumu = strtoupper(trim((string) $request->getQuery('takip_durumu', '')));
        $bitisBaslangic = self::optionalQueryDate($request, 'bitis_baslangic');
        $bitisBitis = self::optionalQueryDate($request, 'bitis_bitis');
        $personelAktiflik = strtolower(trim((string) $request->getQuery('personel_aktiflik', 'aktif')));
        if (!in_array($personelAktiflik, ['aktif', 'pasif', 'tum'], true)) {
            self::validationError('personel_aktiflik', 'Gecersiz personel aktiflik filtresi.');
        }

        if ($kayitTipi !== '' && !PersonelBelgeContracts::isValidKayitTipi($kayitTipi)) {
            self::validationError('kayit_tipi', 'Kayit tipi gecerli degil.');
        }

        $validTakip = [
            PersonelBelgeContracts::STATUS_AKTIF,
            PersonelBelgeContracts::STATUS_SURESI_YAKLASIYOR,
            PersonelBelgeContracts::STATUS_SURESI_DOLDU,
            PersonelBelgeContracts::STATUS_BELGE_DOSYASI_EKSIK,
            PersonelBelgeContracts::STATUS_IPTAL,
        ];
        if ($takipDurumu !== '' && !in_array($takipDurumu, $validTakip, true)) {
            self::validationError('takip_durumu', 'Gecersiz takip durumu filtresi.');
        }

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 50) ?: 50)));

        $where = [
            "s.surec_turu = 'BELGE'",
            "s.state = 'AKTIF'",
            '(s.alt_tur IS NULL OR s.alt_tur <> :status_alt_tur)',
        ];
        $params = ['status_alt_tur' => self::BELGE_DURUMU_ALT_TUR];

        SubeScope::appendSubeFilter($where, $params, $scope, $allowedSubeIds, 'p.sube_id', 'belge_takip');

        if ($departmanId !== null) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }
        if ($personelId !== null) {
            $where[] = 'p.id = :personel_id';
            $params['personel_id'] = $personelId;
        }
        if ($kayitTipi !== '') {
            $where[] = 's.alt_tur = :kayit_tipi';
            $params['kayit_tipi'] = $kayitTipi;
        }
        if ($bitisBaslangic !== null) {
            $where[] = 's.bitis_tarihi IS NOT NULL AND s.bitis_tarihi >= :bitis_baslangic';
            $params['bitis_baslangic'] = $bitisBaslangic;
        }
        if ($bitisBitis !== null) {
            $where[] = 's.bitis_tarihi IS NOT NULL AND s.bitis_tarihi <= :bitis_bitis';
            $params['bitis_bitis'] = $bitisBitis;
        }
        if ($personelAktiflik === 'aktif') {
            $where[] = "p.aktif_durum = 'AKTIF'";
        } elseif ($personelAktiflik === 'pasif') {
            $where[] = "p.aktif_durum = 'PASIF'";
        }

        $whereSql = implode(' AND ', $where);

        try {
            $stmt = $pdo->prepare("
                SELECT s.id, s.personel_id, s.surec_turu, s.alt_tur, s.baslangic_tarihi, s.bitis_tarihi,
                       s.aciklama, s.state, s.created_at, s.updated_at,
                       p.ad AS personel_ad, p.soyad AS personel_soyad, p.sube_id, p.departman_id, p.aktif_durum
                FROM surecler s
                INNER JOIN personeller p ON p.id = s.personel_id
                WHERE $whereSql
                ORDER BY s.id DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            JsonResponse::serverError('Belge takip listesi olusturulamadi.');
        }

        $surecIds = array_map(static function ($row) {
            return (int) $row['id'];
        }, $rows);
        $versionMap = self::fetchActiveVersionsBySurecIds($pdo, $surecIds);
        $yukleyenMap = self::fetchYukleyenMap($pdo, $versionMap);

        $summary = [
            'toplam_aktif' => 0,
            'suresi_yaklasiyor' => 0,
            'suresi_doldu' => 0,
            'dosyasi_eksik' => 0,
            'belgesi_hic_bulunmayan_personel' => self::countPersonelWithoutBelge($pdo, $scope, $allowedSubeIds, $personelAktiflik, $departmanId, $personelId),
        ];

        $items = [];
        foreach ($rows as $row) {
            $surecId = (int) $row['id'];
            $mapped = self::mapBelgeKaydiRow($pdo, $row, $user, $versionMap[$surecId] ?? null, $yukleyenMap);
            $takip = (string) $mapped['takip_durumu'];
            if ($takipDurumu !== '' && $takip !== $takipDurumu) {
                continue;
            }

            self::incrementTakipSummary($summary, $takip);

            $items[] = array_merge($mapped, [
                'personel_ad' => (string) ($row['personel_ad'] ?? ''),
                'personel_soyad' => (string) ($row['personel_soyad'] ?? ''),
                'sube_id' => (int) $row['sube_id'],
                'departman_id' => $row['departman_id'] !== null ? (int) $row['departman_id'] : null,
            ]);
        }

        $total = count($items);
        $offset = ($page - 1) * $limit;
        $pagedItems = array_slice($items, $offset, $limit);

        JsonResponse::success(
            [
                'summary' => $summary,
                'items' => $pagedItems,
            ],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ]
        );
    }
    private static function getConnection()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonelForScope(PDO $pdo, $personelId)
    {
        $id = self::parsePositiveInt($personelId);
        if ($id === null) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id, sube_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed>|null $personel */
    private static function assertPersonelReadable(array $user, Request $request, $personel)
    {
        if (!$personel) {
            JsonResponse::notFound('Personel bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);
    }

    /** @param array<string, mixed> $user */
    private static function assertReadPermission(array $user)
    {
        RolePermissions::assertAny($user, [
            'personeller.detail.view',
            'surecler.view',
            'surecler.view.sube',
            'surecler.detail.view',
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function assertWritePermission(array $user)
    {
        RolePermissions::assertAny($user, ['surecler.create', 'surecler.update']);
    }

    /** @param array<string, mixed> $user */
    private static function assertCancelPermission(array $user)
    {
        RolePermissions::assertAny($user, ['surecler.cancel', 'surecler.update']);
    }

    /** @param array<string, mixed> $user */
    private static function canSeeFullBelgeNo(array $user)
    {
        return RolePermissions::has($user, 'surecler.create')
            || RolePermissions::has($user, 'surecler.update');
    }

    /** @param array<string, mixed> $user */
    private static function userId(array $user)
    {
        $id = isset($user['id']) ? (int) $user['id'] : 0;

        return $id > 0 ? $id : null;
    }

    private static function assertSchemaForFileOps(PDO $pdo)
    {
        if (!PersonelBelgeKayitRepository::tableExists($pdo, 'personel_belge_dosya_surumleri')
            || !PersonelBelgeKayitRepository::tableExists($pdo, 'personel_belge_auditleri')) {
            JsonResponse::error(503, 'PERSONEL_BELGE_SCHEMA_EKSIK', 'Belge dosya altyapisi henuz hazir degil.');
        }
    }

    private static function respondStorageError(RuntimeException $e, $notFoundStatus = null)
    {
        $code = $e->getMessage();
        $map = [
            'PERSONEL_BELGE_SCHEMA_EKSIK' => [503, 'PERSONEL_BELGE_SCHEMA_EKSIK', 'Belge dosya altyapisi henuz hazir degil.'],
            'PERSONEL_BELGE_DOSYA_BOS' => [422, 'PERSONEL_BELGE_DOSYA_BOS', 'Dosya icerigi bos olamaz.'],
            'PERSONEL_BELGE_DOSYA_BOYUTU_ASILDI' => [413, 'PERSONEL_BELGE_DOSYA_BOYUTU_ASILDI', 'Dosya boyutu limiti asildi.', ['limit_byte' => PersonelBelgeContracts::MAX_DECODED_BYTES]],
            'PERSONEL_BELGE_DOSYA_BULUNAMADI' => [$notFoundStatus ?? 404, 'PERSONEL_BELGE_DOSYA_BULUNAMADI', 'Belge dosyasi bulunamadi.'],
            'PERSONEL_BELGE_STORAGE_HATASI' => [500, 'PERSONEL_BELGE_STORAGE_HATASI', 'Belge dosyasi kaydedilemedi.'],
            'PERSONEL_BELGE_STORAGE_KEY_GECERSIZ' => [500, 'PERSONEL_BELGE_STORAGE_KEY_GECERSIZ', 'Belge dosyasi bulunamadi.'],
            'PERSONEL_BELGE_PATH_GECERSIZ' => [500, 'PERSONEL_BELGE_PATH_GECERSIZ', 'Belge dosyasi bulunamadi.'],
        ];

        if (isset($map[$code])) {
            $entry = $map[$code];
            JsonResponse::error($entry[0], $entry[1], $entry[2], null, $entry[3] ?? []);
        }

        JsonResponse::serverError('Belge dosyasi islenemedi.');
    }

    /** @return array<int, array<string, string>> */
    private static function fetchBelgeDurumuItems(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare("
            SELECT aciklama
            FROM surecler
            WHERE personel_id = :personel_id
              AND surec_turu = 'BELGE'
              AND alt_tur = :alt_tur
              AND state = 'AKTIF'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'alt_tur' => self::BELGE_DURUMU_ALT_TUR,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $metadata = is_array($row) ? self::decodeMetadata($row['aciklama'] ?? null) : null;
        $rawItems = is_array($metadata) && isset($metadata['items']) && is_array($metadata['items'])
            ? $metadata['items']
            : [];

        $byTur = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $tur = isset($item['belge_turu']) ? (string) $item['belge_turu'] : '';
            $durum = isset($item['durum']) ? (string) $item['durum'] : '';
            if (in_array($tur, self::validBelgeTurleri(), true) && in_array($durum, ['VAR', 'YOK'], true)) {
                $byTur[$tur] = $durum;
            }
        }

        $items = [];
        foreach (self::validBelgeTurleri() as $belgeTuru) {
            $items[] = [
                'belge_turu' => $belgeTuru,
                'durum' => isset($byTur[$belgeTuru]) ? $byTur[$belgeTuru] : 'YOK',
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $body @return array<int, array<string, string>> */
    private static function normalizeBelgeDurumuPayload(array $body)
    {
        if (!isset($body['items']) || !is_array($body['items'])) {
            self::validationError('items', 'Belge durumu items alanı zorunludur.');
        }

        $byTur = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item)) {
                self::validationError('items', 'Belge durumu satırı geçerli değil.');
            }
            $tur = isset($item['belge_turu']) ? trim((string) $item['belge_turu']) : '';
            $durum = isset($item['durum']) ? trim((string) $item['durum']) : '';
            if (!in_array($tur, self::validBelgeTurleri(), true)) {
                self::validationError('belge_turu', 'Belge türü geçerli değil.');
            }
            if (!in_array($durum, ['VAR', 'YOK'], true)) {
                self::validationError('durum', 'Belge durumu geçerli değil.');
            }
            $byTur[$tur] = $durum;
        }

        $items = [];
        foreach (self::validBelgeTurleri() as $belgeTuru) {
            $items[] = [
                'belge_turu' => $belgeTuru,
                'durum' => isset($byTur[$belgeTuru]) ? $byTur[$belgeTuru] : 'YOK',
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeAndValidateCreatePayload(array $body)
    {
        $kayitTipi = strtoupper(self::requireTrimmedString($body, 'kayit_tipi', 'Kayıt tipi zorunludur.'));
        if (!PersonelBelgeContracts::isValidKayitTipi($kayitTipi)) {
            self::validationError('kayit_tipi', 'Kayıt tipi geçerli değil.');
        }

        $ad = self::requireTrimmedString($body, 'ad', 'Ad alanı zorunludur.');
        $baslangicTarihi = self::optionalDate($body, 'baslangic_tarihi');
        $bitisTarihi = self::optionalDate($body, 'bitis_tarihi');

        return [
            'kayit_tipi' => $kayitTipi,
            'ad' => $ad,
            'veren_kurum' => self::optionalTrimmedString($body, 'veren_kurum'),
            'belge_no' => self::optionalTrimmedString($body, 'belge_no'),
            'baslangic_tarihi' => $baslangicTarihi,
            'bitis_tarihi' => $bitisTarihi,
            'ek_ref' => self::optionalTrimmedString($body, 'ek_ref'),
            'aciklama' => self::optionalTrimmedString($body, 'aciklama'),
        ];
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $existing @return array<string, mixed> */
    private static function normalizeAndValidateUpdatePayload(array $body, array $existing)
    {
        $kayitTipi = array_key_exists('kayit_tipi', $body)
            ? strtoupper(trim((string) $body['kayit_tipi']))
            : (string) ($existing['kayit_tipi'] ?? '');
        if ($kayitTipi === '' || !PersonelBelgeContracts::isValidKayitTipi($kayitTipi)) {
            self::validationError('kayit_tipi', 'Kayit tipi gecerli degil.');
        }

        $ad = array_key_exists('ad', $body)
            ? trim((string) $body['ad'])
            : (string) ($existing['ad'] ?? '');
        if ($ad === '') {
            self::validationError('ad', 'Ad alani zorunludur.');
        }

        $baslangicTarihi = array_key_exists('baslangic_tarihi', $body)
            ? self::optionalDateValue($body['baslangic_tarihi'], 'baslangic_tarihi')
            : ($existing['baslangic_tarihi'] ?? null);
        $bitisTarihi = array_key_exists('bitis_tarihi', $body)
            ? self::optionalDateValue($body['bitis_tarihi'], 'bitis_tarihi')
            : ($existing['bitis_tarihi'] ?? null);

        return [
            'kayit_tipi' => $kayitTipi,
            'ad' => $ad,
            'veren_kurum' => array_key_exists('veren_kurum', $body)
                ? self::optionalTrimmedString($body, 'veren_kurum')
                : ($existing['veren_kurum'] ?? null),
            'belge_no' => array_key_exists('belge_no', $body)
                ? self::optionalTrimmedString($body, 'belge_no')
                : ($existing['belge_no'] ?? null),
            'baslangic_tarihi' => $baslangicTarihi,
            'bitis_tarihi' => $bitisTarihi,
            'ek_ref' => array_key_exists('ek_ref', $body)
                ? self::optionalTrimmedString($body, 'ek_ref')
                : ($existing['ek_ref'] ?? null),
            'aciklama' => array_key_exists('aciklama', $body)
                ? self::optionalTrimmedString($body, 'aciklama')
                : ($existing['aciklama'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function extractKayitMetadata(array $row)
    {
        $metadata = self::decodeMetadata($row['aciklama'] ?? null);
        if (is_array($metadata) && !empty($metadata['_personel_belge_kaydi'])) {
            $kayitTipi = isset($metadata['kayit_tipi'])
                ? strtoupper((string) $metadata['kayit_tipi'])
                : strtoupper((string) ($row['alt_tur'] ?? ''));
            if (!PersonelBelgeContracts::isValidKayitTipi($kayitTipi)) {
                $kayitTipi = PersonelBelgeContracts::isValidKayitTipi((string) ($row['alt_tur'] ?? ''))
                    ? strtoupper((string) $row['alt_tur'])
                    : 'DIGER';
            }

            return [
                'kayit_tipi' => $kayitTipi,
                'ad' => (string) ($metadata['ad'] ?? 'Belge / Sertifika'),
                'veren_kurum' => self::nullableStringFromMetadata($metadata, 'veren_kurum'),
                'belge_no' => self::nullableStringFromMetadata($metadata, 'belge_no'),
                'baslangic_tarihi' => self::nullableStringFromMetadata($metadata, 'baslangic_tarihi')
                    ?? ($row['baslangic_tarihi'] !== null ? (string) $row['baslangic_tarihi'] : null),
                'bitis_tarihi' => self::nullableStringFromMetadata($metadata, 'bitis_tarihi')
                    ?? ($row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null),
                'ek_ref' => self::nullableStringFromMetadata($metadata, 'ek_ref'),
                'aciklama' => self::nullableStringFromMetadata($metadata, 'aciklama'),
            ];
        }

        $altTur = strtoupper((string) ($row['alt_tur'] ?? ''));
        $kayitTipi = PersonelBelgeContracts::isValidKayitTipi($altTur) ? $altTur : 'DIGER';
        $plainAciklama = $row['aciklama'] !== null ? trim((string) $row['aciklama']) : '';

        return [
            'kayit_tipi' => $kayitTipi,
            'ad' => $plainAciklama !== '' ? $plainAciklama : 'Belge / Sertifika',
            'veren_kurum' => null,
            'belge_no' => null,
            'baslangic_tarihi' => $row['baslangic_tarihi'] !== null ? (string) $row['baslangic_tarihi'] : null,
            'bitis_tarihi' => $row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null,
            'ek_ref' => null,
            'aciklama' => $plainAciklama !== '' ? $plainAciklama : null,
        ];
    }

    /** @param array<string, mixed> $body @return array<string, mixed>|null */
    private static function parseOptionalFilePayload(array $body)
    {
        $hasAny = array_key_exists('dosya_icerik_base64', $body)
            || array_key_exists('dosya_adi', $body)
            || array_key_exists('dosya_mime', $body);
        if (!$hasAny) {
            return null;
        }

        return self::parseRequiredFilePayload($body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function parseRequiredFilePayload(array $body)
    {
        $encoded = self::requireTrimmedString($body, 'dosya_icerik_base64', 'Dosya icerigi zorunludur.');
        $originalName = self::requireTrimmedString($body, 'dosya_adi', 'Dosya adi zorunludur.');
        $claimedMime = self::optionalTrimmedString($body, 'dosya_mime') ?? 'application/octet-stream';

        $decoded = PersonelBelgeBase64Guard::decode($encoded);
        if (empty($decoded['ok'])) {
            JsonResponse::error(
                (int) $decoded['http'],
                (string) $decoded['code'],
                (string) $decoded['message'],
                'dosya_icerik_base64',
                $decoded['meta'] ?? []
            );
        }

        $validated = PersonelBelgeContracts::validateFilenameAndMime($originalName, $claimedMime);
        if (empty($validated['ok'])) {
            JsonResponse::error(422, (string) $validated['code'], (string) $validated['message'], 'dosya_adi');
        }

        $bytes = (string) $decoded['bytes'];
        $extension = (string) $validated['extension'];
        if (!PersonelBelgeContracts::validateContentMagic($bytes, $extension)) {
            JsonResponse::error(422, 'PERSONEL_BELGE_ICERIK_GECERSIZ', 'Dosya icerigi uzantisi ile uyusmuyor.', 'dosya_icerik_base64');
        }

        return [
            'bytes' => $bytes,
            'original_name' => $originalName,
            'mime' => (string) $validated['mime'],
            'extension' => $extension,
        ];
    }

    private static function assertDateRangeValid($baslangic, $bitis)
    {
        if ($baslangic === null || $bitis === null) {
            return;
        }
        if ($bitis < $baslangic) {
            self::validationError('bitis_tarihi', 'Bitis tarihi baslangic tarihinden once olamaz.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function insertSurecRow(PDO $pdo, array $payload)
    {
        $stmt = $pdo->prepare('
            INSERT INTO surecler (
                personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                ucretli_mi, aciklama, state
            ) VALUES (
                :personel_id, :surec_turu, :alt_tur, :baslangic_tarihi, :bitis_tarihi,
                0, :aciklama, :state
            )
        ');
        $stmt->execute([
            'personel_id' => $payload['personel_id'],
            'surec_turu' => $payload['surec_turu'],
            'alt_tur' => $payload['alt_tur'],
            'baslangic_tarihi' => $payload['baslangic_tarihi'],
            'bitis_tarihi' => $payload['bitis_tarihi'],
            'aciklama' => $payload['aciklama'],
            'state' => $payload['state'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    private static function fetchBelgeKaydiRowById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare("
            SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                   aciklama, state, created_at, updated_at
            FROM surecler
            WHERE id = :id
              AND surec_turu = 'BELGE'
              AND (alt_tur IS NULL OR alt_tur <> :status_alt_tur)
            LIMIT 1
        ");
        $stmt->execute([
            'id' => (int) $id,
            'status_alt_tur' => self::BELGE_DURUMU_ALT_TUR,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $activeVersion
     * @param array<int, array<string, mixed>>|null $yukleyenMap
     * @return array<string, mixed>
     */
    private static function mapBelgeKaydiRow(PDO $pdo, array $row, array $user, $activeVersion = null, $yukleyenMap = null)
    {
        $meta = self::extractKayitMetadata($row);
        $surecId = (int) $row['id'];

        if ($activeVersion === null) {
            $activeVersion = PersonelBelgeKayitRepository::fetchActiveVersion($pdo, $surecId);
        }
        if ($yukleyenMap === null && $activeVersion !== null) {
            $yukleyenMap = self::fetchYukleyenMap($pdo, [$activeVersion]);
        }

        $lifecycle = strtoupper((string) ($row['state'] ?? 'AKTIF'));
        $durum = $lifecycle === 'IPTAL' ? 'IPTAL' : 'AKTIF';
        $bitisTarihi = $meta['bitis_tarihi'];
        $hasFile = $activeVersion !== null;
        $belgeNo = $meta['belge_no'];

        $mapped = [
            'id' => $surecId,
            'personel_id' => (int) $row['personel_id'],
            'kayit_tipi' => (string) $meta['kayit_tipi'],
            'ad' => (string) $meta['ad'],
            'veren_kurum' => $meta['veren_kurum'],
            'belge_no_masked' => PersonelBelgeContracts::maskBelgeNo($belgeNo),
            'baslangic_tarihi' => $meta['baslangic_tarihi'],
            'bitis_tarihi' => $bitisTarihi,
            'durum' => $durum,
            'takip_durumu' => PersonelBelgeContracts::deriveTakipDurumu($lifecycle, $bitisTarihi, $hasFile),
            'gecerlilik_durumu' => PersonelBelgeContracts::computeGecerlilikDurumu($bitisTarihi),
            'ek_ref' => $meta['ek_ref'],
            'aciklama' => $meta['aciklama'],
            'dosya' => self::mapDosyaInfo($activeVersion),
            'yukleyen' => self::mapYukleyenInfo($activeVersion, $yukleyenMap),
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];

        if (self::canSeeFullBelgeNo($user)) {
            $mapped['belge_no'] = $belgeNo;
        }

        return $mapped;
    }

    /** @param array<string, mixed>|null $version @return array<string, mixed> */
    private static function mapDosyaInfo($version)
    {
        if ($version === null) {
            return [
                'var_mi' => false,
                'surum_no' => null,
                'orijinal_dosya_adi' => null,
                'mime_type' => null,
                'byte_boyutu' => null,
                'sha256' => null,
                'created_at' => null,
            ];
        }

        return [
            'var_mi' => true,
            'surum_no' => (int) $version['surum_no'],
            'orijinal_dosya_adi' => (string) $version['orijinal_dosya_adi'],
            'mime_type' => (string) $version['mime_type'],
            'byte_boyutu' => (int) $version['byte_boyutu'],
            'sha256' => (string) $version['sha256'],
            'created_at' => $version['created_at'] !== null ? (string) $version['created_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $version
     * @param array<int, array<string, mixed>>|null $yukleyenMap
     * @return array<string, mixed>|null
     */
    private static function mapYukleyenInfo($version, $yukleyenMap)
    {
        if ($version === null || empty($version['yukleyen_kullanici_id'])) {
            return null;
        }

        $userId = (int) $version['yukleyen_kullanici_id'];
        $adSoyad = null;
        if (is_array($yukleyenMap) && isset($yukleyenMap[$userId])) {
            $adSoyad = (string) ($yukleyenMap[$userId]['ad_soyad'] ?? '');
        }

        return [
            'kullanici_id' => $userId,
            'ad_soyad' => $adSoyad,
            'created_at' => $version['created_at'] !== null ? (string) $version['created_at'] : null,
        ];
    }

    /** @param array<string, mixed> $version @param array<int, array<string, mixed>>|null $yukleyenMap @return array<string, mixed> */
    private static function mapVersionPublic(array $version, $yukleyenMap)
    {
        return [
            'id' => (int) $version['id'],
            'surum_no' => (int) $version['surum_no'],
            'aktif_mi' => !empty($version['aktif_mi']),
            'orijinal_dosya_adi' => (string) $version['orijinal_dosya_adi'],
            'mime_type' => (string) $version['mime_type'],
            'uzanti' => (string) $version['uzanti'],
            'byte_boyutu' => (int) $version['byte_boyutu'],
            'sha256' => (string) $version['sha256'],
            'yukleyen' => self::mapYukleyenInfo($version, $yukleyenMap),
            'created_at' => $version['created_at'] !== null ? (string) $version['created_at'] : null,
        ];
    }

    /** @param array<string, mixed> $audit @return array<string, mixed> */
    private static function mapAuditPublic(array $audit)
    {
        return [
            'id' => (int) $audit['id'],
            'islem_turu' => (string) $audit['islem_turu'],
            'belge_surum_id' => $audit['belge_surum_id'] !== null ? (int) $audit['belge_surum_id'] : null,
            'onceki_metadata' => self::decodeAuditMetadata($audit['onceki_metadata_json'] ?? null),
            'yeni_metadata' => self::decodeAuditMetadata($audit['yeni_metadata_json'] ?? null),
            'yapan_kullanici_id' => $audit['yapan_kullanici_id'] !== null ? (int) $audit['yapan_kullanici_id'] : null,
            'gerekce' => $audit['gerekce'] !== null ? (string) $audit['gerekce'] : null,
            'dosya_sha256' => $audit['dosya_sha256'] !== null ? (string) $audit['dosya_sha256'] : null,
            'dosya_byte' => $audit['dosya_byte'] !== null ? (int) $audit['dosya_byte'] : null,
            'dosya_mime' => $audit['dosya_mime'] !== null ? (string) $audit['dosya_mime'] : null,
            'created_at' => $audit['created_at'] !== null ? (string) $audit['created_at'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function decodeAuditMetadata($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        unset($decoded['dosya_icerik_base64'], $decoded['storage_key'], $decoded['absolute_path'], $decoded['bytes']);
        if (isset($decoded['belge_no']) && is_string($decoded['belge_no'])) {
            $decoded['belge_no_masked'] = PersonelBelgeContracts::maskBelgeNo($decoded['belge_no']);
            unset($decoded['belge_no']);
        }

        return $decoded;
    }

    /** @param array<int, int> $surecIds @return array<int, array<string, mixed>> */
    private static function fetchActiveVersionsBySurecIds(PDO $pdo, array $surecIds)
    {
        if (count($surecIds) === 0
            || !PersonelBelgeKayitRepository::tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($surecIds) as $index => $surecId) {
            $key = 'surec_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $surecId;
        }

        $sql = '
            SELECT id, surec_id, personel_id, surum_no, aktif_mi, orijinal_dosya_adi,
                   mime_type, uzanti, byte_boyutu, sha256, yukleyen_kullanici_id, created_at
            FROM personel_belge_dosya_surumleri
            WHERE surec_id IN (' . implode(', ', $placeholders) . ')
              AND aktif_mi = 1
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['surec_id']] = $row;
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>>|array<int, array<string, mixed>> $versions
     * @return array<int, array<string, mixed>>
     */
    private static function fetchYukleyenMap(PDO $pdo, $versions)
    {
        $ids = [];
        foreach ($versions as $version) {
            if (!is_array($version) || empty($version['yukleyen_kullanici_id'])) {
                continue;
            }
            $ids[] = (int) $version['yukleyen_kullanici_id'];
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) === 0) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $key = 'uid_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $pdo->prepare('SELECT id, ad_soyad FROM users WHERE id IN (' . implode(', ', $placeholders) . ')');
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    /** @param array<int, int> $allowedSubeIds */
    private static function countPersonelWithoutBelge(
        PDO $pdo,
        $scope,
        array $allowedSubeIds,
        $personelAktiflik,
        $departmanId,
        $personelId
    ) {
        $where = ['1=1'];
        $params = [];

        SubeScope::appendSubeFilter($where, $params, $scope, $allowedSubeIds, 'p.sube_id', 'belge_yok');

        if ($departmanId !== null) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }
        if ($personelId !== null) {
            $where[] = 'p.id = :personel_id';
            $params['personel_id'] = $personelId;
        }
        if ($personelAktiflik === 'aktif') {
            $where[] = "p.aktif_durum = 'AKTIF'";
        } elseif ($personelAktiflik === 'pasif') {
            $where[] = "p.aktif_durum = 'PASIF'";
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM personeller p
            WHERE $whereSql
              AND NOT EXISTS (
                SELECT 1
                FROM surecler s
                WHERE s.personel_id = p.id
                  AND s.surec_turu = 'BELGE'
                  AND s.state = 'AKTIF'
                  AND (s.alt_tur IS NULL OR s.alt_tur <> :status_alt_tur)
              )
        ");
        $params['status_alt_tur'] = self::BELGE_DURUMU_ALT_TUR;
        $stmt->execute($params);

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    /** @param array<string, int> $summary */
    private static function incrementTakipSummary(array &$summary, $takipDurumu)
    {
        if ($takipDurumu === PersonelBelgeContracts::STATUS_AKTIF) {
            $summary['toplam_aktif']++;
        } elseif ($takipDurumu === PersonelBelgeContracts::STATUS_SURESI_YAKLASIYOR) {
            $summary['suresi_yaklasiyor']++;
        } elseif ($takipDurumu === PersonelBelgeContracts::STATUS_SURESI_DOLDU) {
            $summary['suresi_doldu']++;
        } elseif ($takipDurumu === PersonelBelgeContracts::STATUS_BELGE_DOSYASI_EKSIK) {
            $summary['dosyasi_eksik']++;
        }
    }

    private static function sanitizeDownloadFilename($filename)
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $filename);

        return $safe !== '' ? $safe : 'belge';
    }

    private static function optionalQueryDate(Request $request, $field)
    {
        $value = trim((string) $request->getQuery($field, ''));
        if ($value === '') {
            return null;
        }
        if (!PersonelBelgeContracts::isValidDate($value)) {
            self::validationError((string) $field, 'Gecerli bir tarih olmalidir.');
        }

        return $value;
    }

    /** @param mixed $value */
    private static function optionalDateValue($value, $field)
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        if (!PersonelBelgeContracts::isValidDate($trimmed)) {
            self::validationError((string) $field, 'Gecerli bir tarih olmalidir.');
        }

        return $trimmed;
    }

    /** @param array<string, mixed> $metadata */
    private static function nullableStringFromMetadata(array $metadata, $key)
    {
        if (!array_key_exists($key, $metadata) || $metadata[$key] === null) {
            return null;
        }

        $value = trim((string) $metadata[$key]);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $metadata */
    private static function encodeMetadata(array $metadata)
    {
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    /** @return array<string, mixed>|null */
    private static function decodeMetadata($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function isValidDateString($value)
    {
        return PersonelBelgeContracts::isValidDate(is_string($value) ? $value : null);
    }

    /** @param array<string, mixed> $body */
    private static function requireTrimmedString(array $body, $field, $message)
    {
        if (!array_key_exists($field, $body)) {
            self::validationError((string) $field, $message);
        }

        $value = trim((string) $body[$field]);
        if ($value === '') {
            self::validationError((string) $field, $message);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalTrimmedString(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }

        $value = trim((string) $body[$field]);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalDate(array $body, $field)
    {
        $value = self::optionalTrimmedString($body, $field);
        if ($value === null) {
            return null;
        }
        if (!self::isValidDateString($value)) {
            self::validationError((string) $field, 'Gecerli bir tarih olmalidir.');
        }

        return $value;
    }

    /** @param mixed $value */
    private static function parsePositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && trim($value) !== $value) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;
        if ($parsed <= 0 || (string) $parsed !== trim((string) $value)) {
            return null;
        }

        return $parsed;
    }

    /** @return array<int, string> */
    private static function validBelgeTurleri()
    {
        return ['KIMLIK', 'ADRES_BEYANI', 'IS_GIRIS_EVRAKLARI', 'BANKA_IBAN'];
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }
}
