<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class BildirimlerController
{
    private const MAX_LIMIT = 250;

    /** @var array<int, string> */
    private static $allowedBildirimTurleri = [
        'GELMEDI',
        'GEC_GELDI',
        'ERKEN_CIKTI',
        'IZINLI',
        'RAPORLU',
        'GOREVDE',
        'DIGER',
    ];

    /** @var array<int, string> */
    private static $editableStates = [
        'TASLAK',
        'DUZELTME_ISTENDI',
    ];

    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'bildirimler.view');
        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->getQuery('limit', 8) ?: 8)));

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (!self::isTableReady($pdo)) {
            JsonResponse::success(
                ['items' => []],
                [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'total_pages' => 1,
                    'has_next_page' => false,
                    'has_prev_page' => false,
                ]
            );
        }

        $where = ['1=1'];
        $params = [];

        SubeScope::appendSubeFilter($where, $params, $scope, $allowedSubeIds, 'gb.sube_id');
        self::appendListFilters($request, $where, $params);

        $whereSql = implode(' AND ', $where);
        $fromSql = '
            FROM gunluk_bildirimler gb
            WHERE ' . $whereSql;

        try {
            $total = self::countRows($pdo, $fromSql, $params);
            $offset = ($page - 1) * $limit;
            $sql = '
                SELECT gb.*
                ' . $fromSql . '
                ORDER BY gb.tarih DESC, gb.id DESC
                LIMIT :limit OFFSET :offset
            ';
            $stmt = $pdo->prepare($sql);
            self::bindParams($stmt, $params);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            JsonResponse::serverError('Bildirimler listelenemedi.');
        }

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapRow($row);
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

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'gunluk_bildirim.create');
        $body = $request->getJsonBody();
        $payload = self::normalizeCreatePayload($body);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertTableReady($pdo);

        $personel = self::fetchPersonel($pdo, $payload['personel_id']);
        if (!$personel) {
            self::validationError('personel_id', 'Personel bulunamadi.');
        }
        if ((string) $personel['aktif_durum'] !== 'AKTIF') {
            self::validationError('personel_id', 'Personel aktif degil.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO gunluk_bildirimler (
                    personel_id, tarih, sube_id, departman_id, bildirim_turu, alt_tur,
                    baslangic_saati, bitis_saati, dakika, aciklama, state,
                    created_by, updated_by
                ) VALUES (
                    :personel_id, :tarih, :sube_id, :departman_id, :bildirim_turu, :alt_tur,
                    :baslangic_saati, :bitis_saati, :dakika, :aciklama, :state,
                    :created_by, :updated_by
                )
            ');
            $stmt->execute([
                'personel_id' => $payload['personel_id'],
                'tarih' => $payload['tarih'],
                'sube_id' => (int) $personel['sube_id'],
                'departman_id' => $personel['departman_id'] !== null ? (int) $personel['departman_id'] : null,
                'bildirim_turu' => $payload['bildirim_turu'],
                'alt_tur' => $payload['alt_tur'],
                'baslangic_saati' => $payload['baslangic_saati'],
                'bitis_saati' => $payload['bitis_saati'],
                'dakika' => $payload['dakika'],
                'aciklama' => $payload['aciklama'],
                'state' => 'TASLAK',
                'created_by' => self::userId($user),
                'updated_by' => self::userId($user),
            ]);
            $insertId = (int) $pdo->lastInsertId();
            $row = self::fetchRowById($pdo, $insertId);
            if (!$row) {
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            JsonResponse::success(self::mapRow($row), [], 201);
        } catch (\PDOException $e) {
            JsonResponse::serverError('Kayit olusturulamadi.');
        }
    }

    public static function update(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'gunluk_bildirim.update_own_open');
        $bildirimId = self::parsePositiveInt($id);
        if ($bildirimId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        $body = $request->getJsonBody();
        $payload = self::normalizeUpdatePayload($body);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertTableReady($pdo);

        $existing = self::fetchRowById($pdo, $bildirimId);
        if (!$existing) {
            JsonResponse::notFound('Bildirim bulunamadi.');
        }

        self::assertOwnership($user, $existing);
        SubeScope::assertPersonelAccess($user, $request, (int) $existing['sube_id']);
        self::assertEditableState($existing);

        $fields = [];
        $params = ['id' => $bildirimId, 'updated_by' => self::userId($user)];

        if ($payload['bildirim_turu'] !== null) {
            $fields[] = 'bildirim_turu = :bildirim_turu';
            $params['bildirim_turu'] = $payload['bildirim_turu'];
        }
        if (array_key_exists('alt_tur', $payload)) {
            $fields[] = 'alt_tur = :alt_tur';
            $params['alt_tur'] = $payload['alt_tur'];
        }
        if (array_key_exists('baslangic_saati', $payload)) {
            $fields[] = 'baslangic_saati = :baslangic_saati';
            $params['baslangic_saati'] = $payload['baslangic_saati'];
        }
        if (array_key_exists('bitis_saati', $payload)) {
            $fields[] = 'bitis_saati = :bitis_saati';
            $params['bitis_saati'] = $payload['bitis_saati'];
        }
        if (array_key_exists('dakika', $payload)) {
            $fields[] = 'dakika = :dakika';
            $params['dakika'] = $payload['dakika'];
        }
        if (array_key_exists('aciklama', $payload)) {
            $fields[] = 'aciklama = :aciklama';
            $params['aciklama'] = $payload['aciklama'];
        }

        if (count($fields) === 0) {
            JsonResponse::success(self::mapRow($existing));
        }

        $nextTur = $payload['bildirim_turu'] ?? (string) $existing['bildirim_turu'];
        $nextAciklama = array_key_exists('aciklama', $payload)
            ? $payload['aciklama']
            : ($existing['aciklama'] !== null ? (string) $existing['aciklama'] : null);
        self::assertDigereAciklama($nextTur, $nextAciklama);

        $fields[] = 'updated_by = :updated_by';

        try {
            $sql = 'UPDATE gunluk_bildirimler SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = self::fetchRowById($pdo, $bildirimId);
            if (!$row) {
                JsonResponse::serverError('Kayit guncellenemedi.');
            }

            JsonResponse::success(self::mapRow($row));
        } catch (\PDOException $e) {
            JsonResponse::serverError('Kayit guncellenemedi.');
        }
    }

    public static function submit(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'gunluk_bildirim.submit');
        $bildirimId = self::parsePositiveInt($id);
        if ($bildirimId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertTableReady($pdo);

        $existing = self::fetchRowById($pdo, $bildirimId);
        if (!$existing) {
            JsonResponse::notFound('Bildirim bulunamadi.');
        }

        self::assertOwnership($user, $existing);
        SubeScope::assertPersonelAccess($user, $request, (int) $existing['sube_id']);

        $state = (string) $existing['state'];
        if ($state === 'GONDERILDI') {
            JsonResponse::success(self::mapRow($existing));
        }
        if ($state === 'IPTAL') {
            JsonResponse::error(409, 'CONFLICT', 'Iptal edilmis bildirim gonderilemez.');
        }
        if (!in_array($state, self::$editableStates, true)) {
            JsonResponse::error(409, 'CONFLICT', 'Bu durumdaki bildirim gonderilemez.');
        }

        try {
            $stmt = $pdo->prepare('
                UPDATE gunluk_bildirimler
                SET state = :state, submitted_at = NOW(), updated_by = :updated_by
                WHERE id = :id
            ');
            $stmt->execute([
                'state' => 'GONDERILDI',
                'updated_by' => self::userId($user),
                'id' => $bildirimId,
            ]);
            $row = self::fetchRowById($pdo, $bildirimId);
            if (!$row) {
                JsonResponse::serverError('Kayit gonderilemedi.');
            }

            JsonResponse::success(self::mapRow($row));
        } catch (\PDOException $e) {
            JsonResponse::serverError('Kayit gonderilemedi.');
        }
    }

    public static function requestCorrection(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'gunluk_bildirim.request_correction');
        $bildirimId = self::parsePositiveInt($id);
        if ($bildirimId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        $body = $request->getJsonBody();
        $reason = isset($body['correction_reason']) ? trim((string) $body['correction_reason']) : '';
        if ($reason === '') {
            self::validationError('correction_reason', 'Duzeltme nedeni zorunludur.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertTableReady($pdo);

        $existing = self::fetchRowById($pdo, $bildirimId);
        if (!$existing) {
            JsonResponse::notFound('Bildirim bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $existing['sube_id']);

        if ((string) $existing['state'] !== 'GONDERILDI') {
            JsonResponse::error(409, 'CONFLICT', 'Yalnizca gonderilmis bildirimler icin duzeltme istenebilir.');
        }

        try {
            $stmt = $pdo->prepare('
                UPDATE gunluk_bildirimler
                SET state = :state,
                    correction_requested_by = :correction_requested_by,
                    correction_reason = :correction_reason,
                    updated_by = :updated_by
                WHERE id = :id
            ');
            $stmt->execute([
                'state' => 'DUZELTME_ISTENDI',
                'correction_requested_by' => self::userId($user),
                'correction_reason' => $reason,
                'updated_by' => self::userId($user),
                'id' => $bildirimId,
            ]);
            $row = self::fetchRowById($pdo, $bildirimId);
            if (!$row) {
                JsonResponse::serverError('Duzeltme talebi kaydedilemedi.');
            }

            JsonResponse::success(self::mapRow($row));
        } catch (\PDOException $e) {
            JsonResponse::serverError('Duzeltme talebi kaydedilemedi.');
        }
    }

    public static function cancel(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'gunluk_bildirim.update_own_open');
        $bildirimId = self::parsePositiveInt($id);
        if ($bildirimId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertTableReady($pdo);

        $existing = self::fetchRowById($pdo, $bildirimId);
        if (!$existing) {
            JsonResponse::notFound('Bildirim bulunamadi.');
        }

        self::assertOwnership($user, $existing);
        SubeScope::assertPersonelAccess($user, $request, (int) $existing['sube_id']);

        $state = (string) $existing['state'];
        if ($state === 'IPTAL') {
            JsonResponse::success(self::mapRow($existing));
        }
        if (!in_array($state, self::$editableStates, true)) {
            JsonResponse::error(409, 'CONFLICT', 'Bu durumdaki bildirim iptal edilemez.');
        }

        try {
            $stmt = $pdo->prepare('
                UPDATE gunluk_bildirimler
                SET state = :state, updated_by = :updated_by
                WHERE id = :id
            ');
            $stmt->execute([
                'state' => 'IPTAL',
                'updated_by' => self::userId($user),
                'id' => $bildirimId,
            ]);
            $row = self::fetchRowById($pdo, $bildirimId);
            if (!$row) {
                JsonResponse::serverError('Kayit iptal edilemedi.');
            }

            JsonResponse::success(self::mapRow($row));
        } catch (\PDOException $e) {
            JsonResponse::serverError('Kayit iptal edilemedi.');
        }
    }

    public static function isTableReady(PDO $pdo)
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'gunluk_bildirimler'");

            return $stmt && (bool) $stmt->fetch();
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function assertTableReady(PDO $pdo)
    {
        if (!self::isTableReady($pdo)) {
            JsonResponse::error(
                503,
                'BILDIRIM_SCHEMA_MISSING',
                'Gunluk bildirim tablosu bulunamadi veya migration uygulanmadi.'
            );
        }
    }

    /** @param array<int, string> $where @param array<string, mixed> $params */
    private static function appendListFilters(Request $request, array &$where, array &$params)
    {
        $personelId = self::parsePositiveInt($request->getQuery('personel_id'));
        if ($personelId !== null) {
            $where[] = 'gb.personel_id = :personel_id';
            $params['personel_id'] = $personelId;
        }

        $tarih = trim((string) $request->getQuery('tarih', ''));
        if ($tarih !== '') {
            if (!self::isValidDate($tarih)) {
                self::validationError('tarih', 'Gecersiz tarih.');
            }
            $where[] = 'gb.tarih = :tarih';
            $params['tarih'] = $tarih;
        } else {
            $baslangic = trim((string) $request->getQuery('baslangic_tarihi', ''));
            $bitis = trim((string) $request->getQuery('bitis_tarihi', ''));
            if ($baslangic !== '' || $bitis !== '') {
                if ($baslangic === '' || $bitis === '') {
                    self::validationError('baslangic_tarihi', 'Baslangic ve bitis tarihi birlikte verilmelidir.');
                }
                if (!self::isValidDate($baslangic)) {
                    self::validationError('baslangic_tarihi', 'Gecersiz baslangic tarihi.');
                }
                if (!self::isValidDate($bitis)) {
                    self::validationError('bitis_tarihi', 'Gecersiz bitis tarihi.');
                }
                if ($baslangic > $bitis) {
                    self::validationError('baslangic_tarihi', 'Baslangic tarihi bitis tarihinden buyuk olamaz.');
                }
                $where[] = 'gb.tarih BETWEEN :baslangic_tarihi AND :bitis_tarihi';
                $params['baslangic_tarihi'] = $baslangic;
                $params['bitis_tarihi'] = $bitis;
            }
        }

        $state = strtoupper(trim((string) $request->getQuery('state', '')));
        if ($state !== '') {
            $where[] = 'gb.state = :state';
            $params['state'] = $state;
        }

        $bildirimTuru = strtoupper(trim((string) $request->getQuery('bildirim_turu', '')));
        if ($bildirimTuru !== '') {
            if (!in_array($bildirimTuru, self::$allowedBildirimTurleri, true)) {
                self::validationError('bildirim_turu', 'Bildirim turu gecerli degil.');
            }
            $where[] = 'gb.bildirim_turu = :bildirim_turu';
            $params['bildirim_turu'] = $bildirimTuru;
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeCreatePayload(array $body)
    {
        $personelId = self::requirePositiveInt($body, 'personel_id', 'Personel secilmelidir.');
        $tarih = self::requireDate($body, 'tarih', 'Tarih zorunludur.');
        $bildirimTuru = self::requireBildirimTuru($body);
        $aciklama = self::optionalAciklama($body);
        self::assertDigereAciklama($bildirimTuru, $aciklama);

        return [
            'personel_id' => $personelId,
            'tarih' => $tarih,
            'bildirim_turu' => $bildirimTuru,
            'alt_tur' => self::optionalString($body, 'alt_tur'),
            'baslangic_saati' => self::optionalString($body, 'baslangic_saati'),
            'bitis_saati' => self::optionalString($body, 'bitis_saati'),
            'dakika' => self::optionalDakika($body),
            'aciklama' => $aciklama,
        ];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeUpdatePayload(array $body)
    {
        $payload = [
            'bildirim_turu' => null,
            'alt_tur' => null,
            'baslangic_saati' => null,
            'bitis_saati' => null,
            'dakika' => null,
            'aciklama' => null,
        ];

        if (array_key_exists('bildirim_turu', $body)) {
            $payload['bildirim_turu'] = self::requireBildirimTuru($body);
        }
        if (array_key_exists('alt_tur', $body)) {
            $payload['alt_tur'] = self::optionalString($body, 'alt_tur');
        }
        if (array_key_exists('baslangic_saati', $body)) {
            $payload['baslangic_saati'] = self::optionalString($body, 'baslangic_saati');
        }
        if (array_key_exists('bitis_saati', $body)) {
            $payload['bitis_saati'] = self::optionalString($body, 'bitis_saati');
        }
        if (array_key_exists('dakika', $body)) {
            $payload['dakika'] = self::optionalDakika($body);
        }
        if (array_key_exists('aciklama', $body)) {
            $payload['aciklama'] = self::optionalAciklama($body);
        }

        return $payload;
    }

    /** @param array<string, mixed> $body */
    private static function requireBildirimTuru(array $body)
    {
        if (!array_key_exists('bildirim_turu', $body) || trim((string) $body['bildirim_turu']) === '') {
            self::validationError('bildirim_turu', 'Bildirim turu secilmelidir.');
        }

        $bildirimTuru = strtoupper(trim((string) $body['bildirim_turu']));
        if (!in_array($bildirimTuru, self::$allowedBildirimTurleri, true)) {
            self::validationError('bildirim_turu', 'Bildirim turu gecerli degil.');
        }

        return $bildirimTuru;
    }

    private static function assertDigereAciklama($bildirimTuru, $aciklama)
    {
        if ((string) $bildirimTuru === 'DIGER' && ($aciklama === null || trim((string) $aciklama) === '')) {
            self::validationError('aciklama', 'DIGER turu icin aciklama zorunludur.');
        }
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $row */
    private static function assertOwnership(array $user, array $row)
    {
        $currentUserId = self::userId($user);
        $createdBy = isset($row['created_by']) ? (int) $row['created_by'] : 0;
        if ($currentUserId === null || $createdBy !== $currentUserId) {
            JsonResponse::forbidden();
        }
    }

    /** @param array<string, mixed> $row */
    private static function assertEditableState(array $row)
    {
        $state = (string) $row['state'];
        if (in_array($state, ['GONDERILDI', 'HAFTALIK_MUTABAKATA_ALINDI', 'IPTAL'], true)) {
            JsonResponse::error(409, 'CONFLICT', 'Bu durumdaki bildirim guncellenemez.');
        }
        if (!in_array($state, self::$editableStates, true)) {
            JsonResponse::error(409, 'CONFLICT', 'Bu durumdaki bildirim guncellenemez.');
        }
    }

    /** @return array<string, mixed>|false */
    private static function fetchPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('
            SELECT id, sube_id, departman_id, aktif_durum
            FROM personeller
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $personelId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    private static function fetchRowById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM gunluk_bildirimler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $params */
    private static function countRows(PDO $pdo, $fromSql, array $params)
    {
        $sql = 'SELECT COUNT(*) AS total ' . $fromSql;
        $stmt = $pdo->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0);
    }

    /** @param array<string, mixed> $params */
    private static function bindParams(\PDOStatement $stmt, array $params)
    {
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'],
            'sube_id' => (int) $row['sube_id'],
            'departman_id' => $row['departman_id'] !== null ? (int) $row['departman_id'] : null,
            'bildirim_turu' => (string) $row['bildirim_turu'],
            'alt_tur' => $row['alt_tur'] !== null ? (string) $row['alt_tur'] : null,
            'baslangic_saati' => $row['baslangic_saati'] !== null ? (string) $row['baslangic_saati'] : null,
            'bitis_saati' => $row['bitis_saati'] !== null ? (string) $row['bitis_saati'] : null,
            'dakika' => $row['dakika'] !== null ? (int) $row['dakika'] : null,
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'state' => (string) $row['state'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'submitted_at' => $row['submitted_at'] !== null ? (string) $row['submitted_at'] : null,
            'correction_requested_by' => $row['correction_requested_by'] !== null ? (int) $row['correction_requested_by'] : null,
            'correction_reason' => $row['correction_reason'] !== null ? (string) $row['correction_reason'] : null,
            'haftalik_mutabakat_id' => $row['haftalik_mutabakat_id'] !== null ? (int) $row['haftalik_mutabakat_id'] : null,
            'okundu_mi' => (bool) ((int) ($row['okundu_mi'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $user */
    private static function userId(array $user)
    {
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /** @param mixed $value */
    private static function parsePositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    /** @param array<string, mixed> $body */
    private static function requirePositiveInt(array $body, $field, $message)
    {
        if (!array_key_exists($field, $body) || !is_numeric($body[$field])) {
            self::validationError($field, $message);
        }

        $parsed = (int) $body[$field];
        if ($parsed <= 0) {
            self::validationError($field, $message);
        }

        return $parsed;
    }

    /** @param array<string, mixed> $body */
    private static function requireDate(array $body, $field, $message)
    {
        if (!array_key_exists($field, $body) || trim((string) $body[$field]) === '') {
            self::validationError($field, $message);
        }

        $value = trim((string) $body[$field]);
        if (!self::isValidDate($value)) {
            self::validationError($field, $message);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalString(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }

        $value = trim((string) $body[$field]);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalAciklama(array $body)
    {
        return self::optionalString($body, 'aciklama');
    }

    /** @param array<string, mixed> $body */
    private static function optionalDakika(array $body)
    {
        if (!array_key_exists('dakika', $body) || $body['dakika'] === null || $body['dakika'] === '') {
            return null;
        }

        if (!is_numeric($body['dakika'])) {
            self::validationError('dakika', 'Dakika gecerli olmali.');
        }

        $dakika = (int) $body['dakika'];
        if ($dakika < 0) {
            self::validationError('dakika', 'Dakika gecerli olmali.');
        }

        return $dakika;
    }

    private static function isValidDate($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }
}
