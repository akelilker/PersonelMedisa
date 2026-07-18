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

class ZimmetlerController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.detail.view');

        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 20) ?: 20)));

        $rawPersonelId = $request->getQuery('personel_id', null);
        $personelId = null;
        if ($rawPersonelId !== null && $rawPersonelId !== '') {
            $personelId = self::parsePositiveInt($rawPersonelId);
            if ($personelId === null) {
                self::validationError('personel_id', 'Personel secimi gecersiz.');
            }
        }

        $zimmetDurumu = null;
        $rawDurum = $request->getQuery('zimmet_durumu', null);
        if ($rawDurum !== null && trim((string) $rawDurum) !== '') {
            $zimmetDurumu = strtoupper(trim((string) $rawDurum));
            if (!in_array($zimmetDurumu, self::validZimmetDurumlari(), true)) {
                self::validationError('zimmet_durumu', 'Zimmet durumu gecersiz.');
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
            $where[] = 'z.personel_id = :personel_id';
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

        if ($zimmetDurumu !== null) {
            $where[] = 'z.zimmet_durumu = :zimmet_durumu';
            $params['zimmet_durumu'] = $zimmetDurumu;
        }

        $whereSql = implode(' AND ', $where);
        try {
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) AS total
                FROM zimmetler z
                INNER JOIN personeller p ON p.id = z.personel_id
                WHERE $whereSql
            ");
            $countStmt->execute($params);
            $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $offset = ($page - 1) * $limit;
            $sql = "
                SELECT z.id, z.personel_id, z.urun_turu, z.teslim_tarihi, z.teslim_eden,
                       z.aciklama, z.teslim_durumu, z.zimmet_durumu, z.iade_tarihi
                FROM zimmetler z
                INNER JOIN personeller p ON p.id = z.personel_id
                WHERE $whereSql
                ORDER BY z.teslim_tarihi DESC, z.id DESC
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
            JsonResponse::serverError('Zimmetler listelenemedi.');
        }

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapZimmetRow($row);
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
        // UI gates create with personeller.update; no zimmet.* permission exists.
        RolePermissions::assert($user, 'personeller.update');

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            self::validationError('body', 'Zimmet govdesi gecersiz.');
        }

        $payload = self::normalizeAndValidateCreatePayload($body);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $personel = self::fetchPersonelForScope($pdo, $payload['personel_id']);
        if (!$personel) {
            self::validationError('personel_id', 'Personel bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        if (strtoupper((string) $personel['aktif_durum']) === 'PASIF') {
            self::validationError('personel_id', 'Pasif personele zimmet kaydi eklenemez.');
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO zimmetler (
                    personel_id, urun_turu, teslim_tarihi, teslim_eden,
                    aciklama, teslim_durumu, zimmet_durumu, iade_tarihi
                ) VALUES (
                    :personel_id, :urun_turu, :teslim_tarihi, :teslim_eden,
                    :aciklama, :teslim_durumu, \'AKTIF\', NULL
                )
            ');
            $stmt->execute([
                'personel_id' => $payload['personel_id'],
                'urun_turu' => $payload['urun_turu'],
                'teslim_tarihi' => $payload['teslim_tarihi'],
                'teslim_eden' => $payload['teslim_eden'],
                'aciklama' => $payload['aciklama'],
                'teslim_durumu' => $payload['teslim_durumu'],
            ]);

            $insertId = (int) $pdo->lastInsertId();
            if ($insertId <= 0) {
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            $row = self::fetchZimmetRowById($pdo, $insertId);
            if (!$row) {
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            JsonResponse::success(self::mapZimmetRow($row), [], 201);
        } catch (\PDOException $e) {
            JsonResponse::serverError('Kayit olusturulamadi.');
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeAndValidateCreatePayload(array $body)
    {
        $personelId = self::requirePositiveInt($body, 'personel_id', 'Personel secilmelidir.');

        $urunTuru = self::requireNonEmptyString($body, 'urun_turu', 'Urun turu zorunludur.', 32);
        $urunTuru = strtoupper($urunTuru);
        if (!in_array($urunTuru, self::validUrunTurleri(), true)) {
            self::validationError('urun_turu', 'Urun turu gecerli degil.');
        }

        $teslimTarihi = self::requireNonEmptyString($body, 'teslim_tarihi', 'Teslim tarihi zorunludur.', 32);
        if (!self::isValidDateString($teslimTarihi)) {
            self::validationError('teslim_tarihi', 'Teslim tarihi gecerli olmalidir.');
        }

        $teslimEden = self::requireNonEmptyString($body, 'teslim_eden', 'Teslim eden bilgisi zorunludur.', 120);

        $teslimDurumu = self::requireNonEmptyString($body, 'teslim_durumu', 'Teslim durumu zorunludur.', 32);
        $teslimDurumu = strtoupper($teslimDurumu);
        if (!in_array($teslimDurumu, self::validTeslimDurumlari(), true)) {
            self::validationError('teslim_durumu', 'Teslim durumu gecerli degil.');
        }

        $aciklama = null;
        if (array_key_exists('aciklama', $body) && $body['aciklama'] !== null && $body['aciklama'] !== '') {
            if (!is_string($body['aciklama'])) {
                self::validationError('aciklama', 'Aciklama metin olmalidir.');
            }
            $trimmed = trim($body['aciklama']);
            $aciklama = $trimmed === '' ? null : $trimmed;
        }

        // Client must not set server-owned state/iade fields.
        // Ignored if present (allowlist write path never reads them).

        return [
            'personel_id' => $personelId,
            'urun_turu' => $urunTuru,
            'teslim_tarihi' => $teslimTarihi,
            'teslim_eden' => $teslimEden,
            'teslim_durumu' => $teslimDurumu,
            'aciklama' => $aciklama,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonelForScope(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id, sube_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function fetchZimmetRowById(PDO $pdo, $zimmetId)
    {
        $stmt = $pdo->prepare('
            SELECT id, personel_id, urun_turu, teslim_tarihi, teslim_eden,
                   aciklama, teslim_durumu, zimmet_durumu, iade_tarihi
            FROM zimmetler
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => (int) $zimmetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapZimmetRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'urun_turu' => (string) $row['urun_turu'],
            'teslim_tarihi' => (string) $row['teslim_tarihi'],
            'teslim_eden' => $row['teslim_eden'] !== null && $row['teslim_eden'] !== ''
                ? (string) $row['teslim_eden']
                : null,
            'aciklama' => $row['aciklama'] !== null && $row['aciklama'] !== ''
                ? (string) $row['aciklama']
                : null,
            'teslim_durumu' => (string) $row['teslim_durumu'],
            'zimmet_durumu' => (string) $row['zimmet_durumu'],
            'iade_tarihi' => $row['iade_tarihi'] !== null ? (string) $row['iade_tarihi'] : null,
        ];
    }

    /** @return array<int, string> */
    private static function validUrunTurleri()
    {
        return ['AYAKKABI', 'KASK', 'KULAKLIK', 'MASKE', 'TELEFON', 'DIGER'];
    }

    /** @return array<int, string> */
    private static function validTeslimDurumlari()
    {
        return ['YENI', 'IKINCI_EL', 'ARIZALI'];
    }

    /** @return array<int, string> */
    private static function validZimmetDurumlari()
    {
        return ['AKTIF', 'IADE_EDILDI'];
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
    private static function requireNonEmptyString(array $body, $field, $message, $maxLength)
    {
        if (!array_key_exists($field, $body)) {
            self::validationError((string) $field, $message);
        }

        if (!is_string($body[$field])) {
            self::validationError((string) $field, $message);
        }

        $trimmed = trim($body[$field]);
        if ($trimmed === '') {
            self::validationError((string) $field, $message);
        }

        if (self::utf8Length($trimmed) > (int) $maxLength) {
            self::validationError((string) $field, $message);
        }

        return $trimmed;
    }

    private static function parsePositiveInt($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private static function isValidDateString($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $parts = explode('-', $value);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    private static function utf8Length($value)
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen((string) $value, 'UTF-8');
        }

        return strlen((string) $value);
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }
}
