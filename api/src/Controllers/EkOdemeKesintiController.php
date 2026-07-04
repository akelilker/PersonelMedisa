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

class EkOdemeKesintiController
{
    private const MAX_LIMIT = 250;

    /** @var array<int, string> */
    private static $allowedKalemTurleri = [
        'PRIM',
        'BONUS',
        'IKRAMIYE',
        'TESVIK',
        'EKSTRA_PRIM',
        'CEZA',
        'AVANS',
        'BES',
        'DIGER_KESINTI',
        'MAAS',
        'MESAI',
    ];

    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'finans.view');
        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->getQuery('limit', 20) ?: 20)));

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertFinansTableReady($pdo);

        $where = ['1=1'];
        $params = [];

        SubeScope::appendSubeFilter($where, $params, $scope, $allowedSubeIds, 'p.sube_id');

        self::appendListFilters($request, $where, $params);

        $whereSql = implode(' AND ', $where);
        $fromSql = '
            FROM ek_odeme_kesinti fk
            INNER JOIN personeller p ON p.id = fk.personel_id
            WHERE ' . $whereSql;

        try {
            $total = self::countRows($pdo, $fromSql, $params);
            $offset = ($page - 1) * $limit;
            $sql = '
                SELECT fk.*
                ' . $fromSql . '
                ORDER BY fk.donem DESC, fk.id DESC
                LIMIT :limit OFFSET :offset
            ';
            $stmt = $pdo->prepare($sql);
            self::bindParams($stmt, $params);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            JsonResponse::serverError('Finans kalemleri listelenemedi.');
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

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'finans.view');
        $kalemId = self::parsePositiveInt($id);
        if ($kalemId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertFinansTableReady($pdo);

        $row = self::fetchRowWithPersonel($pdo, $kalemId);
        if (!$row) {
            JsonResponse::notFound('Finans kalemi bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $row['sube_id']);
        JsonResponse::success(self::mapRow($row));
    }

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'finans.create');
        $body = $request->getJsonBody();
        $payload = self::normalizeCreatePayload($body);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertFinansTableReady($pdo);

        $personel = self::fetchPersonel($pdo, $payload['personel_id']);
        if (!$personel) {
            self::validationError('personel_id', 'Personel bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO ek_odeme_kesinti (
                    personel_id, donem, kalem_turu, tutar, gun_sayisi, aciklama, state, created_by, updated_by
                ) VALUES (
                    :personel_id, :donem, :kalem_turu, :tutar, :gun_sayisi, :aciklama, :state, :created_by, :updated_by
                )
            ');
            $stmt->execute([
                'personel_id' => $payload['personel_id'],
                'donem' => $payload['donem'],
                'kalem_turu' => $payload['kalem_turu'],
                'tutar' => $payload['tutar'],
                'gun_sayisi' => $payload['gun_sayisi'],
                'aciklama' => $payload['aciklama'],
                'state' => 'AKTIF',
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
        RolePermissions::assert($user, 'finans.update');
        $kalemId = self::parsePositiveInt($id);
        if ($kalemId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        $body = $request->getJsonBody();
        $payload = self::normalizeUpdatePayload($body);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertFinansTableReady($pdo);

        $existing = self::fetchRowWithPersonel($pdo, $kalemId);
        if (!$existing) {
            JsonResponse::notFound('Finans kalemi bulunamadi.');
        }

        if ((string) $existing['state'] === 'IPTAL') {
            JsonResponse::error(409, 'CONFLICT', 'Iptal edilmis finans kalemi guncellenemez.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $existing['sube_id']);

        $targetPersonelId = $payload['personel_id'] ?? (int) $existing['personel_id'];
        if ($targetPersonelId !== (int) $existing['personel_id']) {
            $personel = self::fetchPersonel($pdo, $targetPersonelId);
            if (!$personel) {
                self::validationError('personel_id', 'Personel bulunamadi.');
            }
            SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);
        }

        $fields = [];
        $params = ['id' => $kalemId, 'updated_by' => self::userId($user)];

        if ($payload['personel_id'] !== null) {
            $fields[] = 'personel_id = :personel_id';
            $params['personel_id'] = $payload['personel_id'];
        }
        if ($payload['donem'] !== null) {
            $fields[] = 'donem = :donem';
            $params['donem'] = $payload['donem'];
        }
        if ($payload['kalem_turu'] !== null) {
            $fields[] = 'kalem_turu = :kalem_turu';
            $params['kalem_turu'] = $payload['kalem_turu'];
        }
        if ($payload['tutar'] !== null) {
            $fields[] = 'tutar = :tutar';
            $params['tutar'] = $payload['tutar'];
        }
        if (array_key_exists('gun_sayisi', $payload)) {
            $fields[] = 'gun_sayisi = :gun_sayisi';
            $params['gun_sayisi'] = $payload['gun_sayisi'];
        }
        if (array_key_exists('aciklama', $payload)) {
            $fields[] = 'aciklama = :aciklama';
            $params['aciklama'] = $payload['aciklama'];
        }

        if (count($fields) === 0) {
            JsonResponse::success(self::mapRow($existing));
        }

        $fields[] = 'updated_by = :updated_by';

        try {
            $sql = 'UPDATE ek_odeme_kesinti SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = self::fetchRowById($pdo, $kalemId);
            if (!$row) {
                JsonResponse::serverError('Kayit guncellenemedi.');
            }

            JsonResponse::success(self::mapRow($row));
        } catch (\PDOException $e) {
            JsonResponse::serverError('Kayit guncellenemedi.');
        }
    }

    public static function cancel(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'finans.cancel');
        $kalemId = self::parsePositiveInt($id);
        if ($kalemId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertFinansTableReady($pdo);

        $existing = self::fetchRowWithPersonel($pdo, $kalemId);
        if (!$existing) {
            JsonResponse::notFound('Finans kalemi bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $existing['sube_id']);

        if ((string) $existing['state'] === 'IPTAL') {
            JsonResponse::success(self::mapRow($existing));
        }

        try {
            $stmt = $pdo->prepare('
                UPDATE ek_odeme_kesinti
                SET state = :state, updated_by = :updated_by
                WHERE id = :id
            ');
            $stmt->execute([
                'state' => 'IPTAL',
                'updated_by' => self::userId($user),
                'id' => $kalemId,
            ]);
            $row = self::fetchRowById($pdo, $kalemId);
            if (!$row) {
                JsonResponse::serverError('Kayit iptal edilemedi.');
            }

            JsonResponse::success(self::mapRow($row));
        } catch (\PDOException $e) {
            JsonResponse::serverError('Kayit iptal edilemedi.');
        }
    }

    public static function assertFinansTableReady(PDO $pdo)
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'ek_odeme_kesinti'");
            if (!$stmt || !$stmt->fetch()) {
                JsonResponse::error(
                    503,
                    'FINANS_SCHEMA_MISSING',
                    'Finans tablosu bulunamadi veya migration uygulanmadi.'
                );
            }
        } catch (\PDOException $e) {
            JsonResponse::error(
                503,
                'FINANS_SCHEMA_MISSING',
                'Finans tablosu bulunamadi veya migration uygulanmadi.'
            );
        }
    }

    /** @return array<int, string> */
    public static function allowedKalemTurleri()
    {
        return self::$allowedKalemTurleri;
    }

    /** @param array<int, string> $where @param array<string, mixed> $params */
    private static function appendListFilters(Request $request, array &$where, array &$params)
    {
        $personelId = (int) ($request->getQuery('personel_id', 0) ?: 0);
        if ($personelId > 0) {
            $where[] = 'fk.personel_id = :personel_id';
            $params['personel_id'] = $personelId;
        }

        $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
        if ($departmanId > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }

        $kalemTuru = strtoupper(trim((string) $request->getQuery('kalem_turu', '')));
        if ($kalemTuru !== '') {
            if (!in_array($kalemTuru, self::$allowedKalemTurleri, true)) {
                self::validationError('kalem_turu', 'Kalem turu gecerli degil.');
            }
            $where[] = 'fk.kalem_turu = :kalem_turu';
            $params['kalem_turu'] = $kalemTuru;
        }

        $state = strtoupper(trim((string) $request->getQuery('state', '')));
        if ($state !== '') {
            $where[] = 'fk.state = :state';
            $params['state'] = $state;
        }

        $donem = trim((string) $request->getQuery('donem', ''));
        if ($donem !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $donem)) {
                self::validationError('donem', 'Donem YYYY-MM formatinda olmali.');
            }
            $where[] = 'fk.donem = :donem';
            $params['donem'] = $donem;
        } else {
            self::appendDonemRangeFromDates($request, $where, $params, 'fk');
        }
    }

    /** @param array<int, string> $where @param array<string, mixed> $params */
    public static function appendDonemRangeFromDates(Request $request, array &$where, array &$params, $alias)
    {
        $baslangic = trim((string) $request->getQuery('baslangic_tarihi', ''));
        $bitis = trim((string) $request->getQuery('bitis_tarihi', ''));

        if ($baslangic === '' || $bitis === '') {
            return;
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

        $startMonth = substr($baslangic, 0, 7);
        $endMonth = substr($bitis, 0, 7);
        if ($startMonth === $endMonth) {
            $where[] = $alias . '.donem = :finans_donem';
            $params['finans_donem'] = $startMonth;
        } else {
            $where[] = $alias . '.donem BETWEEN :finans_donem_bas AND :finans_donem_bit';
            $params['finans_donem_bas'] = $startMonth;
            $params['finans_donem_bit'] = $endMonth;
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeCreatePayload(array $body)
    {
        $personelId = self::requirePositiveInt($body, 'personel_id', 'Personel secilmelidir.');
        $donem = self::requireDonem($body);
        $kalemTuru = self::requireKalemTuru($body);
        $tutar = self::requireTutar($body);

        return [
            'personel_id' => $personelId,
            'donem' => $donem,
            'kalem_turu' => $kalemTuru,
            'tutar' => $tutar,
            'gun_sayisi' => self::optionalGunSayisi($body),
            'aciklama' => self::optionalAciklama($body),
        ];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeUpdatePayload(array $body)
    {
        $payload = [
            'personel_id' => null,
            'donem' => null,
            'kalem_turu' => null,
            'tutar' => null,
            'gun_sayisi' => null,
            'aciklama' => null,
        ];

        if (array_key_exists('personel_id', $body)) {
            $payload['personel_id'] = self::requirePositiveInt($body, 'personel_id', 'Personel secilmelidir.');
        }
        if (array_key_exists('donem', $body)) {
            $payload['donem'] = self::requireDonem($body);
        }
        if (array_key_exists('kalem_turu', $body)) {
            $payload['kalem_turu'] = self::requireKalemTuru($body);
        }
        if (array_key_exists('tutar', $body)) {
            $payload['tutar'] = self::requireTutar($body);
        }
        if (array_key_exists('gun_sayisi', $body)) {
            $payload['gun_sayisi'] = self::optionalGunSayisi($body);
        }
        if (array_key_exists('aciklama', $body)) {
            $payload['aciklama'] = self::optionalAciklama($body);
        }

        return $payload;
    }

    /** @param array<string, mixed> $body */
    private static function requireDonem(array $body)
    {
        if (!array_key_exists('donem', $body) || trim((string) $body['donem']) === '') {
            self::validationError('donem', 'Donem YYYY-MM formatinda olmali.');
        }

        $donem = trim((string) $body['donem']);
        if (!preg_match('/^\d{4}-\d{2}$/', $donem)) {
            self::validationError('donem', 'Donem YYYY-MM formatinda olmali.');
        }

        return $donem;
    }

    /** @param array<string, mixed> $body */
    private static function requireKalemTuru(array $body)
    {
        if (!array_key_exists('kalem_turu', $body) || trim((string) $body['kalem_turu']) === '') {
            self::validationError('kalem_turu', 'Kalem turu secilmelidir.');
        }

        $kalemTuru = strtoupper(trim((string) $body['kalem_turu']));
        if (!in_array($kalemTuru, self::$allowedKalemTurleri, true)) {
            self::validationError('kalem_turu', 'Kalem turu gecerli degil.');
        }

        return $kalemTuru;
    }

    /** @param array<string, mixed> $body */
    private static function requireTutar(array $body)
    {
        if (!array_key_exists('tutar', $body)) {
            self::validationError('tutar', 'Tutar gecerli olmali.');
        }

        if (!is_numeric($body['tutar'])) {
            self::validationError('tutar', 'Tutar gecerli olmali.');
        }

        $tutar = (float) $body['tutar'];
        if ($tutar <= 0) {
            self::validationError('tutar', 'Tutar sifirdan buyuk olmali.');
        }

        return $tutar;
    }

    /** @param array<string, mixed> $body */
    private static function optionalGunSayisi(array $body)
    {
        if (!array_key_exists('gun_sayisi', $body) || $body['gun_sayisi'] === null || $body['gun_sayisi'] === '') {
            return null;
        }

        if (!is_numeric($body['gun_sayisi'])) {
            self::validationError('gun_sayisi', 'Gun sayisi gecerli olmali.');
        }

        $gunSayisi = (int) $body['gun_sayisi'];
        if ($gunSayisi < 0) {
            self::validationError('gun_sayisi', 'Gun sayisi gecerli olmali.');
        }

        return $gunSayisi;
    }

    /** @param array<string, mixed> $body */
    private static function optionalAciklama(array $body)
    {
        if (!array_key_exists('aciklama', $body) || $body['aciklama'] === null) {
            return null;
        }

        $aciklama = trim((string) $body['aciklama']);

        return $aciklama === '' ? null : $aciklama;
    }

    /** @return array<string, mixed>|false */
    private static function fetchPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id, sube_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    private static function fetchRowById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM ek_odeme_kesinti WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    private static function fetchRowWithPersonel(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('
            SELECT fk.*, p.sube_id
            FROM ek_odeme_kesinti fk
            INNER JOIN personeller p ON p.id = fk.personel_id
            WHERE fk.id = :id
            LIMIT 1
        ');
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
            'donem' => (string) $row['donem'],
            'kalem_turu' => (string) $row['kalem_turu'],
            'tutar' => (float) $row['tutar'],
            'gun_sayisi' => $row['gun_sayisi'] !== null ? (int) $row['gun_sayisi'] : null,
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'state' => (string) $row['state'],
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
        if (!is_numeric($value)) {
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
