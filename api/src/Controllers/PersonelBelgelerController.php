<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class PersonelBelgelerController
{
    private const BELGE_DURUMU_ALT_TUR = 'BELGE_DURUMU';

    public static function belgeDurumu(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $pdo = self::getConnection();
        $personel = self::fetchPersonelForScope($pdo, $personelId);
        self::assertPersonelReadable($user, $request, $personel);

        JsonResponse::success(['items' => self::fetchBelgeDurumuItems($pdo, (int) $personel['id'])]);
    }

    public static function updateBelgeDurumu(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user);

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

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapBelgeKaydiRow($row);
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
        self::assertWriteRole($user);

        $pdo = self::getConnection();
        $personel = self::fetchPersonelForScope($pdo, $personelId);
        self::assertPersonelReadable($user, $request, $personel);

        if (strtoupper((string) $personel['aktif_durum']) === 'PASIF') {
            self::validationError('personel_id', 'Pasif personele belge kaydı eklenemez.');
        }

        $payload = self::normalizeAndValidateCreatePayload($request->getJsonBody());
        $dbBaslangic = $payload['baslangic_tarihi'] !== null ? $payload['baslangic_tarihi'] : date('Y-m-d');

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

            $row = self::fetchBelgeKaydiRowById($pdo, $insertId);
            if (!$row) {
                JsonResponse::serverError('Belge kaydı oluşturulamadı.');
            }
        } catch (\PDOException $e) {
            JsonResponse::serverError('Belge kaydı oluşturulamadı.');
        }

        JsonResponse::success(self::mapBelgeKaydiRow($row), [], 201);
    }

    public static function cancelKaydi(Request $request, $kayitId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user);

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

        try {
            $stmt = $pdo->prepare("UPDATE surecler SET state = 'IPTAL' WHERE id = :id");
            $stmt->execute(['id' => $kayitId]);
            $row = self::fetchBelgeKaydiRowById($pdo, $kayitId);
            if (!$row) {
                JsonResponse::serverError('Belge kaydı iptal edilemedi.');
            }
        } catch (\PDOException $e) {
            JsonResponse::serverError('Belge kaydı iptal edilemedi.');
        }

        JsonResponse::success(self::mapBelgeKaydiRow($row));
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
    private static function assertWriteRole(array $user)
    {
        $allowedRoles = ['GENEL_YONETICI', 'BOLUM_YONETICISI', 'MUHASEBE'];
        if (!in_array((string) ($user['rol'] ?? ''), $allowedRoles, true)) {
            JsonResponse::forbidden();
        }
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
        if (!in_array($kayitTipi, self::validKayitTipleri(), true)) {
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

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapBelgeKaydiRow(array $row)
    {
        $metadata = self::decodeMetadata($row['aciklama'] ?? null);
        $isMetadata = is_array($metadata) && !empty($metadata['_personel_belge_kaydi']);

        $kayitTipi = $isMetadata && isset($metadata['kayit_tipi'])
            ? strtoupper((string) $metadata['kayit_tipi'])
            : strtoupper((string) ($row['alt_tur'] ?? ''));
        if (!in_array($kayitTipi, self::validKayitTipleri(), true)) {
            $kayitTipi = 'SERTIFIKA';
        }

        $bitisTarihi = $isMetadata
            ? self::nullableStringFromMetadata($metadata, 'bitis_tarihi')
            : ($row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null);

        $plainAciklama = $row['aciklama'] !== null ? (string) $row['aciklama'] : null;

        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'kayit_tipi' => $kayitTipi,
            'ad' => $isMetadata
                ? (string) ($metadata['ad'] ?? 'Belge / Sertifika')
                : ($plainAciklama !== null && trim($plainAciklama) !== '' ? $plainAciklama : 'Belge / Sertifika'),
            'veren_kurum' => $isMetadata ? self::nullableStringFromMetadata($metadata, 'veren_kurum') : null,
            'belge_no' => $isMetadata ? self::nullableStringFromMetadata($metadata, 'belge_no') : null,
            'baslangic_tarihi' => $isMetadata
                ? self::nullableStringFromMetadata($metadata, 'baslangic_tarihi')
                : (string) $row['baslangic_tarihi'],
            'bitis_tarihi' => $bitisTarihi,
            'durum' => (string) ($row['state'] ?? 'AKTIF') === 'IPTAL' ? 'IPTAL' : 'AKTIF',
            'gecerlilik_durumu' => self::computeGecerlilikDurumu($bitisTarihi),
            'ek_ref' => $isMetadata ? self::nullableStringFromMetadata($metadata, 'ek_ref') : null,
            'aciklama' => $isMetadata ? self::nullableStringFromMetadata($metadata, 'aciklama') : $plainAciklama,
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
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

    private static function computeGecerlilikDurumu($bitisTarihi)
    {
        if (!is_string($bitisTarihi) || trim($bitisTarihi) === '' || !self::isValidDateString($bitisTarihi)) {
            return 'GECERLI';
        }

        $today = strtotime(date('Y-m-d') . ' 00:00:00 UTC');
        $bitis = strtotime($bitisTarihi . ' 00:00:00 UTC');
        if ($today === false || $bitis === false) {
            return 'GECERLI';
        }

        $diffDays = (int) round(($bitis - $today) / 86400);
        if ($diffDays < 0) {
            return 'SURESI_DOLMUS';
        }
        if ($diffDays <= 30) {
            return 'YAKINDA_DOLUYOR';
        }

        return 'GECERLI';
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

    private static function isValidDateString($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
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

    /** @return array<int, string> */
    private static function validKayitTipleri()
    {
        return ['EGITIM', 'SERTIFIKA', 'EHLIYET', 'YETKINLIK'];
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }
}
