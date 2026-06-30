<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class SureclerController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 20) ?: 20)));
        $rawPersonelId = $request->getQuery('personel_id', null);
        $personelId = null;
        if ($rawPersonelId !== null) {
            $personelId = self::parsePositiveInt($rawPersonelId);
            if ($personelId === null) {
                self::validationError('personel_id', 'Personel secimi gecersiz.');
            }
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['1=1'];
        $params = [];

        if ($personelId !== null) {
            $personel = self::fetchPersonelForScope($pdo, $personelId);
            if (!$personel) {
                JsonResponse::notFound('Personel bulunamadi.');
            }

            SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);
            $where[] = 'sc.personel_id = :personel_id';
            $params['personel_id'] = $personelId;
        } elseif ($scope !== null) {
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

        $whereSql = implode(' AND ', $where);
        try {
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) AS total
                FROM surecler sc
                INNER JOIN personeller p ON p.id = sc.personel_id
                WHERE $whereSql
            ");
            $countStmt->execute($params);
            $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $offset = ($page - 1) * $limit;
            $sql = "
                SELECT sc.id, sc.personel_id, sc.surec_turu, sc.alt_tur, sc.baslangic_tarihi, sc.bitis_tarihi,
                       sc.ucretli_mi, sc.aciklama, sc.state
                FROM surecler sc
                INNER JOIN personeller p ON p.id = sc.personel_id
                WHERE $whereSql
                ORDER BY sc.id DESC
                LIMIT :limit OFFSET :offset
            ";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            JsonResponse::serverError('Surecler listelenemedi.');
        }

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapSurecRow($row);
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

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertCreateRole($user);

        $body = $request->getJsonBody();
        $payload = self::normalizeAndValidateCreatePayload($body);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, $payload['personel_id']);
        if (!$personel) {
            self::validationError('personel_id', 'Personel bulunamadı.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        if (strtoupper((string) $personel['aktif_durum']) === 'PASIF') {
            self::validationError('personel_id', 'Pasif personele süreç kaydı eklenemez.');
        }

        $pdo->beginTransaction();
        try {
            $insertId = self::insertSurec($pdo, $payload);
            if ($payload['surec_turu'] === 'ISTEN_AYRILMA') {
                self::deactivatePersonel($pdo, $payload['personel_id']);
            }

            $row = self::fetchSurecRowById($pdo, $insertId);
            if (!$row) {
                $pdo->rollBack();
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            $pdo->commit();
            JsonResponse::success(self::mapSurecRow($row), [], 201);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            JsonResponse::serverError('Kayit olusturulamadi.');
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeAndValidateCreatePayload(array $body)
    {
        $personelId = self::requirePositiveInt($body, 'personel_id', 'Personel seçilmelidir.');

        if (!array_key_exists('surec_turu', $body) || trim((string) $body['surec_turu']) === '') {
            self::validationError('surec_turu', 'Süreç türü seçilmelidir.');
        }

        $surecTuru = strtoupper(trim((string) $body['surec_turu']));
        if (!in_array($surecTuru, self::validSurecTurleri(), true)) {
            self::validationError('surec_turu', 'Süreç türü geçerli değil.');
        }

        if (!array_key_exists('baslangic_tarihi', $body) || trim((string) $body['baslangic_tarihi']) === '') {
            self::validationError('baslangic_tarihi', 'Başlangıç tarihi geçerli olmalıdır.');
        }

        $baslangicTarihi = trim((string) $body['baslangic_tarihi']);
        if (!self::isValidDateString($baslangicTarihi)) {
            self::validationError('baslangic_tarihi', 'Başlangıç tarihi geçerli olmalıdır.');
        }

        $bitisTarihi = null;
        if (array_key_exists('bitis_tarihi', $body) && $body['bitis_tarihi'] !== null && trim((string) $body['bitis_tarihi']) !== '') {
            $bitisTarihi = trim((string) $body['bitis_tarihi']);
            if (!self::isValidDateString($bitisTarihi)) {
                self::validationError('bitis_tarihi', 'Bitiş tarihi geçerli olmalıdır.');
            }
        }

        $ucretliMi = false;
        if (array_key_exists('ucretli_mi', $body) && $body['ucretli_mi'] !== null) {
            $ucretliMi = self::normalizeBoolean($body['ucretli_mi']);
        }

        return [
            'personel_id' => $personelId,
            'surec_turu' => $surecTuru,
            'alt_tur' => self::optionalTrimmedString($body, 'alt_tur'),
            'baslangic_tarihi' => $baslangicTarihi,
            'bitis_tarihi' => $bitisTarihi,
            'ucretli_mi' => $ucretliMi,
            'aciklama' => self::optionalTrimmedString($body, 'aciklama'),
        ];
    }

    /** @param array<string, mixed> $user */
    private static function assertCreateRole(array $user)
    {
        $allowedRoles = ['GENEL_YONETICI', 'BOLUM_YONETICISI', 'MUHASEBE'];
        if (!in_array((string) ($user['rol'] ?? ''), $allowedRoles, true)) {
            JsonResponse::forbidden();
        }
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonelForScope(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id, sube_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $payload */
    private static function insertSurec(PDO $pdo, array $payload)
    {
        $sql = '
            INSERT INTO surecler (
                personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                ucretli_mi, aciklama, state
            ) VALUES (
                :personel_id, :surec_turu, :alt_tur, :baslangic_tarihi, :bitis_tarihi,
                :ucretli_mi, :aciklama, :state
            )
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'personel_id' => $payload['personel_id'],
            'surec_turu' => $payload['surec_turu'],
            'alt_tur' => $payload['alt_tur'],
            'baslangic_tarihi' => $payload['baslangic_tarihi'],
            'bitis_tarihi' => $payload['bitis_tarihi'],
            'ucretli_mi' => $payload['ucretli_mi'] ? 1 : 0,
            'aciklama' => $payload['aciklama'],
            'state' => 'AKTIF',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function deactivatePersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = :id");
        $stmt->execute(['id' => (int) $personelId]);
    }

    /** @return array<string, mixed>|null */
    private static function fetchSurecRowById(PDO $pdo, $surecId)
    {
        $stmt = $pdo->prepare('
            SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                   ucretli_mi, aciklama, state
            FROM surecler
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => (int) $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $body */
    private static function requirePositiveInt(array $body, $field, $message)
    {
        if (!array_key_exists($field, $body)) {
            self::validationError((string) $field, $message);
        }

        $value = self::parsePositiveInt($body[$field]);
        if ($value === null) {
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

    /** @param mixed $value */
    private static function normalizeBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    private static function isValidDateString($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    /** @return array<int, string> */
    private static function validSurecTurleri()
    {
        return [
            'IZIN',
            'DEVAMSIZLIK',
            'RAPOR',
            'IS_KAZASI',
            'DISIPLIN',
            'BELGE',
            'POZISYON_DEGISTI',
            'ISTEN_AYRILMA',
            'GOREV_DEGISIKLIGI',
            'UCRET_DEGISIKLIGI',
            'ORG_DEGISIKLIK',
        ];
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapSurecRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'surec_turu' => (string) $row['surec_turu'],
            'alt_tur' => $row['alt_tur'] !== null ? (string) $row['alt_tur'] : null,
            'baslangic_tarihi' => (string) $row['baslangic_tarihi'],
            'bitis_tarihi' => $row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null,
            'ucretli_mi' => (bool) ((int) ($row['ucretli_mi'] ?? 0)),
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'state' => (string) $row['state'],
        ];
    }
}
