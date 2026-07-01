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

class YonetimController
{
    public static function subeler(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'yonetim-paneli.view',
            'aylik-ozet.view',
            'personeller.create',
            'personeller.update',
        ]);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->query(
            'SELECT s.id, s.kod, s.ad, s.durum, GROUP_CONCAT(sd.departman_id) AS departman_ids
             FROM subeler s
             LEFT JOIN sube_departmanlar sd ON sd.sube_id = s.id
             GROUP BY s.id, s.kod, s.ad, s.durum
             ORDER BY s.id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $departmanIds = [];
            if (!empty($row['departman_ids'])) {
                foreach (explode(',', (string) $row['departman_ids']) as $id) {
                    $departmanIds[] = (int) $id;
                }
            }
            $items[] = [
                'id' => (int) $row['id'],
                'kod' => (string) $row['kod'],
                'ad' => (string) $row['ad'],
                'durum' => (string) $row['durum'],
                'departman_ids' => $departmanIds,
                'departman_adlari' => [],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function aylikOzet(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik-ozet.view');

        $ay = trim((string) $request->getQuery('ay', date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            JsonResponse::badRequest('Gecersiz ay parametresi.', 'VALIDATION_ERROR', 'ay');
        }

        $subeId = (int) ($request->getQuery('sube_id', 0) ?: 0);
        $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
        $sadeceRevizeli = filter_var($request->getQuery('sadece_revizeli', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['ay = :ay'];
        $params = ['ay' => $ay];
        if ($subeId > 0) {
            $where[] = 'sube_id = :sube_id';
            $params['sube_id'] = $subeId;
        }
        if ($departmanId > 0) {
            $where[] = 'departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }
        if ($sadeceRevizeli) {
            $where[] = 'revize_var_mi = 1';
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT * FROM aylik_ozet_satirlari WHERE $whereSql ORDER BY personel_id ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $summary = [
            'toplam_personel' => 0,
            'toplam_devamsizlik_gun' => 0,
            'toplam_gec_kalma' => 0,
            'toplam_izinli_gelmedi' => 0,
            'toplam_izinsiz_gelmedi' => 0,
            'toplam_raporlu' => 0,
            'toplam_tesvik_tutari' => 0,
            'toplam_ceza_kesinti_tutari' => 0,
        ];
        $pending = 0;

        foreach ($rows as $row) {
            $item = [
                'personel_id' => (int) $row['personel_id'],
                'ad_soyad' => (string) $row['ad_soyad'],
                'sicil_no' => $row['sicil_no'],
                'sube' => (string) $row['sube'],
                'bolum' => (string) $row['bolum'],
                'bagli_amir_adi' => (string) ($row['bagli_amir_adi'] ?: '-'),
                'devamsizlik_gun' => (int) $row['devamsizlik_gun'],
                'gec_kalma_adet' => (int) $row['gec_kalma_adet'],
                'izinli_gelmedi' => (int) $row['izinli_gelmedi'],
                'izinsiz_gelmedi' => (int) $row['izinsiz_gelmedi'],
                'raporlu' => (int) $row['raporlu'],
                'tesvik_tutari' => (float) $row['tesvik_tutari'],
                'ceza_kesinti_tutari' => (float) $row['ceza_kesinti_tutari'],
                'bolum_onay_durumu' => (string) $row['bolum_onay_durumu'],
                'revize_var_mi' => (bool) $row['revize_var_mi'],
                'son_islem' => (string) ($row['son_islem'] ?: '-'),
                'kapanis_durumu' => (string) $row['kapanis_durumu'],
            ];
            $items[] = $item;

            $summary['toplam_personel']++;
            $summary['toplam_devamsizlik_gun'] += (int) $row['devamsizlik_gun'];
            $summary['toplam_gec_kalma'] += (int) $row['gec_kalma_adet'];
            $summary['toplam_izinli_gelmedi'] += (int) $row['izinli_gelmedi'];
            $summary['toplam_izinsiz_gelmedi'] += (int) $row['izinsiz_gelmedi'];
            $summary['toplam_raporlu'] += (int) $row['raporlu'];
            $summary['toplam_tesvik_tutari'] += (float) $row['tesvik_tutari'];
            $summary['toplam_ceza_kesinti_tutari'] += (float) $row['ceza_kesinti_tutari'];

            if ($row['bolum_onay_durumu'] === 'BOLUM_ONAYINDA') {
                $pending++;
            }
        }

        $stateStmt = $pdo->prepare('SELECT state FROM aylik_kapanis_state WHERE ay = :ay LIMIT 1');
        $stateStmt->execute(['ay' => $ay]);
        $stateRow = $stateStmt->fetch(PDO::FETCH_ASSOC);
        $state = $stateRow ? (string) $stateRow['state'] : 'BOLUM_ONAYINDA';

        JsonResponse::success([
            'ay' => $ay,
            'state' => $state,
            'summary' => $summary,
            'items' => $items,
            'pending_bolum_onayi' => $pending,
        ]);
    }

    /** @var array<int, string> */
    private static $validRoles = [
        'GENEL_YONETICI',
        'BOLUM_YONETICISI',
        'MUHASEBE',
        'BIRIM_AMIRI',
    ];

    public static function kullanicilar(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertKullaniciYonetimi($user);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->query(
            'SELECT id, username, ad_soyad, rol, durum
             FROM users
             ORDER BY id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int) $row['id'];
        }
        $subeIdsByUser = self::loadSubeIdsByUserIds($pdo, $userIds);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $items[] = self::mapKullaniciRow($row, $subeIdsByUser[$id] ?? []);
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function kullaniciOlustur(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertKullaniciYonetimi($user);

        $body = $request->getJsonBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $adSoyad = trim((string) ($body['ad_soyad'] ?? ''));
        $rol = strtoupper(trim((string) ($body['rol'] ?? '')));
        $durum = strtoupper(trim((string) ($body['durum'] ?? 'AKTIF')));
        $subeIds = self::parseSubeIds(isset($body['sube_ids']) ? $body['sube_ids'] : []);
        $varsayilanSubeId = self::parseOptionalInt($body['varsayilan_sube_id'] ?? null);

        if ($username === '') {
            JsonResponse::badRequest('Kullanici adi zorunludur.', 'VALIDATION_ERROR', 'username');
        }
        if ($password === '') {
            JsonResponse::badRequest('Sifre zorunludur.', 'VALIDATION_ERROR', 'password');
        }
        if ($adSoyad === '') {
            JsonResponse::badRequest('Ad soyad zorunludur.', 'VALIDATION_ERROR', 'ad_soyad');
        }
        if (!self::isValidRole($rol)) {
            JsonResponse::badRequest('Gecersiz rol.', 'VALIDATION_ERROR', 'rol');
        }
        if ($durum !== 'AKTIF' && $durum !== 'PASIF') {
            JsonResponse::badRequest('Gecersiz durum.', 'VALIDATION_ERROR', 'durum');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (self::usernameExists($pdo, $username)) {
            JsonResponse::error(409, 'DUPLICATE_USERNAME', 'Bu kullanici adi zaten kayitli.', 'username');
        }

        self::assertSubeIdsExist($pdo, $subeIds);
        self::assertVarsayilanSubeInScope($varsayilanSubeId, $subeIds);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, ad_soyad, rol, durum)
                 VALUES (:username, :password_hash, :ad_soyad, :rol, :durum)'
            );
            $stmt->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'ad_soyad' => $adSoyad,
                'rol' => $rol,
                'durum' => $durum,
            ]);
            $userId = (int) $pdo->lastInsertId();
            self::replaceUserSubeler($pdo, $userId, $subeIds);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            JsonResponse::serverError('Kullanici kaydi olusturulamadi.');
        }

        $created = self::findKullaniciById($pdo, $userId);
        if ($created === null) {
            JsonResponse::serverError('Kullanici kaydi olusturulamadi.');
        }

        JsonResponse::success($created);
    }

    public static function kullaniciGuncelle(Request $request, $kullaniciId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertKullaniciYonetimi($user);

        $kullaniciId = (int) $kullaniciId;
        if ($kullaniciId <= 0) {
            JsonResponse::badRequest('Gecersiz kullanici id.', 'VALIDATION_ERROR', 'id');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $existing = self::findKullaniciRowById($pdo, $kullaniciId);
        if ($existing === null) {
            JsonResponse::notFound('Kullanici bulunamadi.');
        }

        $body = $request->getJsonBody();
        $username = array_key_exists('username', $body)
            ? trim((string) $body['username'])
            : (string) $existing['username'];
        $password = array_key_exists('password', $body) ? (string) $body['password'] : '';
        $adSoyad = array_key_exists('ad_soyad', $body)
            ? trim((string) $body['ad_soyad'])
            : (string) $existing['ad_soyad'];
        $rol = array_key_exists('rol', $body)
            ? strtoupper(trim((string) $body['rol']))
            : (string) $existing['rol'];
        $durum = array_key_exists('durum', $body)
            ? strtoupper(trim((string) $body['durum']))
            : (string) $existing['durum'];
        $subeIds = array_key_exists('sube_ids', $body)
            ? self::parseSubeIds($body['sube_ids'])
            : null;
        $varsayilanSubeId = array_key_exists('varsayilan_sube_id', $body)
            ? self::parseOptionalInt($body['varsayilan_sube_id'])
            : null;

        if ($username === '') {
            JsonResponse::badRequest('Kullanici adi zorunludur.', 'VALIDATION_ERROR', 'username');
        }
        if ($adSoyad === '') {
            JsonResponse::badRequest('Ad soyad zorunludur.', 'VALIDATION_ERROR', 'ad_soyad');
        }
        if (!self::isValidRole($rol)) {
            JsonResponse::badRequest('Gecersiz rol.', 'VALIDATION_ERROR', 'rol');
        }
        if ($durum !== 'AKTIF' && $durum !== 'PASIF') {
            JsonResponse::badRequest('Gecersiz durum.', 'VALIDATION_ERROR', 'durum');
        }
        if ($username !== (string) $existing['username'] && self::usernameExists($pdo, $username, $kullaniciId)) {
            JsonResponse::error(409, 'DUPLICATE_USERNAME', 'Bu kullanici adi zaten kayitli.', 'username');
        }

        if ($subeIds !== null) {
            self::assertSubeIdsExist($pdo, $subeIds);
            self::assertVarsayilanSubeInScope($varsayilanSubeId, $subeIds);
        } elseif ($varsayilanSubeId !== null) {
            $currentSubeIds = self::loadSubeIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
            self::assertVarsayilanSubeInScope($varsayilanSubeId, $currentSubeIds);
        }

        $pdo->beginTransaction();
        try {
            $params = [
                'id' => $kullaniciId,
                'username' => $username,
                'ad_soyad' => $adSoyad,
                'rol' => $rol,
                'durum' => $durum,
            ];
            $sql = 'UPDATE users SET username = :username, ad_soyad = :ad_soyad, rol = :rol, durum = :durum';
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $sql .= ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($subeIds !== null) {
                self::replaceUserSubeler($pdo, $kullaniciId, $subeIds);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            JsonResponse::serverError('Kullanici kaydi guncellenemedi.');
        }

        $updated = self::findKullaniciById($pdo, $kullaniciId);
        if ($updated === null) {
            JsonResponse::serverError('Kullanici kaydi guncellenemedi.');
        }

        JsonResponse::success($updated);
    }

    /** @param array<string, mixed> $user */
    private static function assertKullaniciYonetimi(array $user)
    {
        RolePermissions::assert($user, 'yonetim-paneli.manage');
    }

  /** @param array<string, mixed> $row @param array<int, int> $subeIds @return array<string, mixed> */
    private static function mapKullaniciRow(array $row, array $subeIds)
    {
        $varsayilanSubeId = count($subeIds) > 0 ? $subeIds[0] : null;
        $rol = (string) $row['rol'];

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'ad_soyad' => (string) $row['ad_soyad'],
            'rol' => $rol,
            'durum' => (string) $row['durum'],
            'sube_ids' => $subeIds,
            'varsayilan_sube_id' => $varsayilanSubeId,
            'telefon' => null,
            'personel_id' => null,
            'personel_ad_soyad' => null,
            'kullanici_tipi' => $rol === 'GENEL_YONETICI' ? 'HARICI' : 'IC_PERSONEL',
            'notlar' => null,
        ];
    }

    /** @param array<int, int> $userIds @return array<int, array<int, int>> */
    private static function loadSubeIdsByUserIds(PDO $pdo, array $userIds)
    {
        if (count($userIds) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT user_id, sube_id FROM user_subeler WHERE user_id IN ($placeholders) ORDER BY user_id ASC, sube_id ASC"
        );
        $stmt->execute($userIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['user_id'];
            if (!isset($map[$userId])) {
                $map[$userId] = [];
            }
            $map[$userId][] = (int) $row['sube_id'];
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    private static function findKullaniciRowById(PDO $pdo, $userId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, username, ad_soyad, rol, durum FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function findKullaniciById(PDO $pdo, $userId)
    {
        $row = self::findKullaniciRowById($pdo, $userId);
        if ($row === null) {
            return null;
        }

        $subeIds = self::loadSubeIdsByUserIds($pdo, [(int) $userId])[(int) $userId] ?? [];

        return self::mapKullaniciRow($row, $subeIds);
    }

    private static function usernameExists(PDO $pdo, $username, $excludeUserId = null)
    {
        $sql = 'SELECT id FROM users WHERE username = :username';
        $params = ['username' => $username];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeUserId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param mixed $value @return array<int, int> */
    private static function parseSubeIds($value)
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $parsed = (int) $item;
            if ($parsed > 0) {
                $ids[] = $parsed;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param mixed $value */
    private static function parseOptionalInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private static function isValidRole($rol)
    {
        return in_array($rol, self::$validRoles, true);
    }

    /** @param array<int, int> $subeIds */
    private static function assertSubeIdsExist(PDO $pdo, array $subeIds)
    {
        if (count($subeIds) === 0) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($subeIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM subeler WHERE id IN ($placeholders)");
        $stmt->execute($subeIds);
        $total = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($total !== count($subeIds)) {
            JsonResponse::badRequest('Gecersiz sube secimi.', 'VALIDATION_ERROR', 'sube_ids');
        }
    }

    /** @param array<int, int> $subeIds */
    private static function assertVarsayilanSubeInScope($varsayilanSubeId, array $subeIds)
    {
        if ($varsayilanSubeId === null) {
            return;
        }

        if (!in_array($varsayilanSubeId, $subeIds, true)) {
            JsonResponse::badRequest('Varsayilan sube yetki verilen subeler icinde olmalidir.', 'VALIDATION_ERROR', 'varsayilan_sube_id');
        }
    }

    /** @param array<int, int> $subeIds */
    private static function replaceUserSubeler(PDO $pdo, $userId, array $subeIds)
    {
        $delete = $pdo->prepare('DELETE FROM user_subeler WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);

        if (count($subeIds) === 0) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO user_subeler (user_id, sube_id) VALUES (:user_id, :sube_id)');
        foreach ($subeIds as $subeId) {
            $insert->execute([
                'user_id' => $userId,
                'sube_id' => $subeId,
            ]);
        }
    }
}
