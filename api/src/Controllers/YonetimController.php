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
    private const SUBE_DELETE_BLOCKED_MESSAGE = 'Şubede Kayıtlı Personel Gözükmektedir. Kayıtlı Personel Varken Silme İşlemi Yapılamaz.';

    public static function subeler(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeListeleme($user);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $items = self::loadSubeItems($pdo);

        JsonResponse::success(['items' => $items]);
    }

    public static function subeOlustur(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeYonetimi($user);

        $body = $request->getJsonBody();
        $kod = trim((string) ($body['kod'] ?? ''));
        $ad = trim((string) ($body['ad'] ?? ''));
        $durum = strtoupper(trim((string) ($body['durum'] ?? 'AKTIF')));
        $departmanIds = self::parseDepartmanIds(isset($body['departman_ids']) ? $body['departman_ids'] : []);

        if ($kod === '') {
            JsonResponse::badRequest('Sube kodu zorunludur.', 'VALIDATION_ERROR', 'kod');
        }
        if ($ad === '') {
            JsonResponse::badRequest('Sube adi zorunludur.', 'VALIDATION_ERROR', 'ad');
        }
        if ($durum !== 'AKTIF' && $durum !== 'PASIF') {
            JsonResponse::badRequest('Gecersiz durum.', 'VALIDATION_ERROR', 'durum');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertDepartmanIdsExist($pdo, $departmanIds);
        self::assertSubeKodUnique($pdo, $kod);
        self::assertSubeAdUnique($pdo, $ad);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO subeler (kod, ad, durum) VALUES (:kod, :ad, :durum)'
            );
            $stmt->execute([
                'kod' => $kod,
                'ad' => $ad,
                'durum' => $durum,
            ]);
            $subeId = (int) $pdo->lastInsertId();
            self::replaceSubeDepartmanlar($pdo, $subeId, $departmanIds);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            JsonResponse::serverError('Sube kaydi olusturulamadi.');
        }

        $created = self::findSubeItemById($pdo, $subeId);
        if ($created === null) {
            JsonResponse::serverError('Sube kaydi olusturulamadi.');
        }

        JsonResponse::success($created);
    }

    public static function subeGuncelle(Request $request, $subeId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeYonetimi($user);

        $subeId = (int) $subeId;
        if ($subeId <= 0) {
            JsonResponse::badRequest('Gecersiz sube id.', 'VALIDATION_ERROR', 'id');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $existing = self::findSubeRowById($pdo, $subeId);
        if ($existing === null) {
            JsonResponse::notFound('Sube bulunamadi.');
        }

        $body = $request->getJsonBody();
        $kod = array_key_exists('kod', $body)
            ? trim((string) $body['kod'])
            : (string) $existing['kod'];
        $ad = array_key_exists('ad', $body)
            ? trim((string) $body['ad'])
            : (string) $existing['ad'];
        $durum = array_key_exists('durum', $body)
            ? strtoupper(trim((string) $body['durum']))
            : (string) $existing['durum'];
        $departmanIds = array_key_exists('departman_ids', $body)
            ? self::parseDepartmanIds($body['departman_ids'])
            : null;

        if ($kod === '') {
            JsonResponse::badRequest('Sube kodu zorunludur.', 'VALIDATION_ERROR', 'kod');
        }
        if ($ad === '') {
            JsonResponse::badRequest('Sube adi zorunludur.', 'VALIDATION_ERROR', 'ad');
        }
        if ($durum !== 'AKTIF' && $durum !== 'PASIF') {
            JsonResponse::badRequest('Gecersiz durum.', 'VALIDATION_ERROR', 'durum');
        }

        if ($departmanIds !== null) {
            self::assertDepartmanIdsExist($pdo, $departmanIds);
        }
        if ($kod !== (string) $existing['kod']) {
            self::assertSubeKodUnique($pdo, $kod, $subeId);
        }
        if ($ad !== (string) $existing['ad']) {
            self::assertSubeAdUnique($pdo, $ad, $subeId);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE subeler SET kod = :kod, ad = :ad, durum = :durum WHERE id = :id'
            );
            $stmt->execute([
                'id' => $subeId,
                'kod' => $kod,
                'ad' => $ad,
                'durum' => $durum,
            ]);

            if ($departmanIds !== null) {
                self::replaceSubeDepartmanlar($pdo, $subeId, $departmanIds);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            JsonResponse::serverError('Sube kaydi guncellenemedi.');
        }

        $updated = self::findSubeItemById($pdo, $subeId);
        if ($updated === null) {
            JsonResponse::serverError('Sube kaydi guncellenemedi.');
        }

        JsonResponse::success($updated);
    }

    public static function subeSil(Request $request, $subeId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeYonetimi($user);

        $subeId = (int) $subeId;
        if ($subeId <= 0) {
            JsonResponse::badRequest('Gecersiz sube id.', 'VALIDATION_ERROR', 'id');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $existing = self::findSubeRowById($pdo, $subeId);
        if ($existing === null) {
            JsonResponse::notFound('Sube bulunamadi.');
        }

        $personelStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM personeller WHERE sube_id = :sube_id');
        $personelStmt->execute(['sube_id' => $subeId]);
        $personelCount = (int) ($personelStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($personelCount > 0) {
            JsonResponse::error(409, 'SUBE_HAS_PERSONEL', self::SUBE_DELETE_BLOCKED_MESSAGE);
        }

        $pdo->beginTransaction();
        try {
            $deleteDepartmanlar = $pdo->prepare('DELETE FROM sube_departmanlar WHERE sube_id = :sube_id');
            $deleteDepartmanlar->execute(['sube_id' => $subeId]);

            $deleteSube = $pdo->prepare('DELETE FROM subeler WHERE id = :id');
            $deleteSube->execute(['id' => $subeId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            JsonResponse::serverError('Sube kaydi silinemedi.');
        }

        JsonResponse::success(['id' => $subeId, 'deleted' => true]);
    }

    /** @param array<string, mixed> $user */
    private static function assertSubeListeleme(array $user)
    {
        RolePermissions::assertAny($user, [
            'yonetim-paneli.view',
            'aylik-ozet.view',
            'personeller.create',
            'personeller.update',
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function assertSubeYonetimi(array $user)
    {
        RolePermissions::assert($user, 'yonetim-paneli.manage');
    }

    /** @return array<int, array<string, mixed>> */
    private static function loadSubeItems(PDO $pdo)
    {
        $stmt = $pdo->query(
            'SELECT s.id, s.kod, s.ad, s.durum,
                    GROUP_CONCAT(sd.departman_id ORDER BY sd.departman_id ASC) AS departman_ids,
                    GROUP_CONCAT(d.ad ORDER BY sd.departman_id ASC) AS departman_adlari
             FROM subeler s
             LEFT JOIN sube_departmanlar sd ON sd.sube_id = s.id
             LEFT JOIN departmanlar d ON d.id = sd.departman_id
             GROUP BY s.id, s.kod, s.ad, s.durum
             ORDER BY s.id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::mapSubeRow($row);
        }

        return $items;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapSubeRow(array $row)
    {
        $departmanIds = [];
        $departmanAdlari = [];
        if (!empty($row['departman_ids'])) {
            foreach (explode(',', (string) $row['departman_ids']) as $id) {
                $departmanIds[] = (int) $id;
            }
        }
        if (!empty($row['departman_adlari'])) {
            foreach (explode(',', (string) $row['departman_adlari']) as $ad) {
                $departmanAdlari[] = (string) $ad;
            }
        }

        return [
            'id' => (int) $row['id'],
            'kod' => (string) $row['kod'],
            'ad' => (string) $row['ad'],
            'durum' => (string) $row['durum'],
            'departman_ids' => $departmanIds,
            'departman_adlari' => $departmanAdlari,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function findSubeRowById(PDO $pdo, $subeId)
    {
        $stmt = $pdo->prepare('SELECT id, kod, ad, durum FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function findSubeItemById(PDO $pdo, $subeId)
    {
        $stmt = $pdo->prepare(
            'SELECT s.id, s.kod, s.ad, s.durum,
                    GROUP_CONCAT(sd.departman_id ORDER BY sd.departman_id ASC) AS departman_ids,
                    GROUP_CONCAT(d.ad ORDER BY sd.departman_id ASC) AS departman_adlari
             FROM subeler s
             LEFT JOIN sube_departmanlar sd ON sd.sube_id = s.id
             LEFT JOIN departmanlar d ON d.id = sd.departman_id
             WHERE s.id = :id
             GROUP BY s.id, s.kod, s.ad, s.durum
             LIMIT 1'
        );
        $stmt->execute(['id' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return self::mapSubeRow($row);
    }

    /** @param mixed $value @return array<int, int> */
    private static function parseDepartmanIds($value)
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

    /** @param array<int, int> $departmanIds */
    private static function assertDepartmanIdsExist(PDO $pdo, array $departmanIds)
    {
        if (count($departmanIds) === 0) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($departmanIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM departmanlar WHERE id IN ($placeholders)");
        $stmt->execute($departmanIds);
        $total = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($total !== count($departmanIds)) {
            JsonResponse::badRequest('Gecersiz departman secimi.', 'VALIDATION_ERROR', 'departman_ids');
        }
    }

    private static function assertSubeKodUnique(PDO $pdo, $kod, $excludeSubeId = null)
    {
        $sql = 'SELECT id FROM subeler WHERE kod = :kod';
        $params = ['kod' => $kod];
        if ($excludeSubeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeSubeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::error(409, 'DUPLICATE_SUBE_KOD', 'Bu sube kodu zaten kayitli.', 'kod');
        }
    }

    private static function assertSubeAdUnique(PDO $pdo, $ad, $excludeSubeId = null)
    {
        $sql = 'SELECT id FROM subeler WHERE ad = :ad';
        $params = ['ad' => $ad];
        if ($excludeSubeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeSubeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::error(409, 'DUPLICATE_SUBE_AD', 'Bu sube adi zaten kayitli.', 'ad');
        }
    }

    /** @param array<int, int> $departmanIds */
    private static function replaceSubeDepartmanlar(PDO $pdo, $subeId, array $departmanIds)
    {
        $delete = $pdo->prepare('DELETE FROM sube_departmanlar WHERE sube_id = :sube_id');
        $delete->execute(['sube_id' => $subeId]);

        if (count($departmanIds) === 0) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (:sube_id, :departman_id)'
        );
        foreach ($departmanIds as $departmanId) {
            $insert->execute([
                'sube_id' => $subeId,
                'departman_id' => $departmanId,
            ]);
        }
    }

    public static function aylikOzet(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik-ozet.view');

        $filters = self::parseAylikOzetFilters($request, false);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        JsonResponse::success(self::buildAylikOzetPayload($pdo, $filters, $user));
    }

    public static function aylikOzetBolumOnay(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, ['aylik-ozet.review', 'aylik-ozet.executive_ack']);

        $filters = self::parseAylikOzetFilters($request, true);
        self::assertAylikWriteSubeScope($user, $filters['sube_id']);
        self::assertAylikSubeAccess($user, $filters['sube_id']);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = self::buildAylikOzetWhereClause($filters, $user);
        $params = $where['params'];
        $params['son_islem'] = 'Bolum yoneticisi toplu onay verdi';

        $pdo->beginTransaction();
        try {
            $updateSql = 'UPDATE aylik_ozet_satirlari
                SET bolum_onay_durumu = \'BOLUM_ONAYLANDI\',
                    revize_var_mi = 0,
                    son_islem = :son_islem
                WHERE ' . $where['sql'] . ' AND kapanis_durumu <> \'KAPANDI\'';
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($params);

            self::syncAylikKapanisState($pdo, $filters['ay']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Aylik ozet onay islemi tamamlanamadi.');
        }

        JsonResponse::success(self::buildAylikOzetPayload($pdo, $filters, $user));
    }

    public static function aylikOzetAyKapat(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik-ozet.executive_ack');

        $filters = self::parseAylikOzetFilters($request, true);
        self::assertAylikWriteSubeScope($user, $filters['sube_id']);
        self::assertAylikSubeAccess($user, $filters['sube_id']);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = self::buildAylikOzetWhereClause($filters, $user);
        $params = $where['params'];
        $params['son_islem'] = 'Genel yonetici ust onay verdi';

        $pdo->beginTransaction();
        try {
            $updateSql = 'UPDATE aylik_ozet_satirlari
                SET kapanis_durumu = \'KAPANDI\',
                    son_islem = :son_islem
                WHERE ' . $where['sql'];
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($params);

            self::syncAylikKapanisState($pdo, $filters['ay']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Aylik ozet kapanis islemi tamamlanamadi.');
        }

        JsonResponse::success(self::buildAylikOzetPayload($pdo, $filters, $user));
    }

    /**
     * @return array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool}
     */
    private static function parseAylikOzetFilters(Request $request, $fromBody)
    {
        if ($fromBody) {
            $body = $request->getJsonBody();
            $ay = trim((string) (isset($body['ay']) ? $body['ay'] : ''));
            $subeId = (int) (isset($body['sube_id']) ? $body['sube_id'] : 0);
            $departmanId = (int) (isset($body['departman_id']) ? $body['departman_id'] : 0);
            $sadeceRevizeli = filter_var(isset($body['sadece_revizeli']) ? $body['sadece_revizeli'] : false, FILTER_VALIDATE_BOOLEAN);
        } else {
            $ay = trim((string) $request->getQuery('ay', date('Y-m')));
            $subeId = (int) ($request->getQuery('sube_id', 0) ?: 0);
            $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
            $sadeceRevizeli = filter_var($request->getQuery('sadece_revizeli', false), FILTER_VALIDATE_BOOLEAN);
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            JsonResponse::badRequest('Gecersiz ay parametresi.', 'VALIDATION_ERROR', 'ay');
        }

        return [
            'ay' => $ay,
            'sube_id' => $subeId > 0 ? $subeId : 0,
            'departman_id' => $departmanId > 0 ? $departmanId : 0,
            'sadece_revizeli' => $sadeceRevizeli,
        ];
    }

    /** @param array<string, mixed> $user */
    private static function assertAylikSubeAccess(array $user, $subeId)
    {
        $subeId = (int) $subeId;
        if ($subeId <= 0) {
            return;
        }

        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) > 0 && !in_array($subeId, $allowed, true)) {
            JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertAylikWriteSubeScope(array $user, $subeId)
    {
        $subeId = (int) $subeId;
        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) > 0 && $subeId <= 0) {
            JsonResponse::badRequest('Sube secimi zorunludur.', 'VALIDATION_ERROR', 'sube_id');
        }
    }

    /**
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     * @return array{sql: string, params: array<string, mixed>}
     */
    private static function buildAylikOzetWhereClause(array $filters, array $user)
    {
        $where = ['ay = :ay'];
        $params = ['ay' => $filters['ay']];
        if ($filters['sube_id'] > 0) {
            $where[] = 'sube_id = :sube_id';
            $params['sube_id'] = $filters['sube_id'];
        } else {
            $allowedSubeIds = SubeScope::allowedSubeIds($user);
            if (count($allowedSubeIds) > 0) {
                $placeholders = [];
                foreach ($allowedSubeIds as $index => $subeId) {
                    $key = 'allowed_sube_id_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $subeId;
                }
                $where[] = 'sube_id IN (' . implode(', ', $placeholders) . ')';
            }
        }
        if ($filters['departman_id'] > 0) {
            $where[] = 'departman_id = :departman_id';
            $params['departman_id'] = $filters['departman_id'];
        }
        if ($filters['sadece_revizeli']) {
            $where[] = 'revize_var_mi = 1';
        }

        return [
            'sql' => implode(' AND ', $where),
            'params' => $params,
        ];
    }

    /**
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     */
    private static function buildAylikOzetPayload(PDO $pdo, array $filters, array $user)
    {
        $where = self::buildAylikOzetWhereClause($filters, $user);
        $stmt = $pdo->prepare(
            'SELECT * FROM aylik_ozet_satirlari WHERE ' . $where['sql'] . ' ORDER BY personel_id ASC'
        );
        $stmt->execute($where['params']);
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
        $stateStmt->execute(['ay' => $filters['ay']]);
        $stateRow = $stateStmt->fetch(PDO::FETCH_ASSOC);
        $state = $stateRow ? (string) $stateRow['state'] : 'BOLUM_ONAYINDA';

        return [
            'ay' => $filters['ay'],
            'state' => $state,
            'summary' => $summary,
            'items' => $items,
            'pending_bolum_onayi' => $pending,
        ];
    }

    private static function syncAylikKapanisState(PDO $pdo, $ay)
    {
        $stmt = $pdo->prepare(
            'SELECT bolum_onay_durumu, kapanis_durumu FROM aylik_ozet_satirlari WHERE ay = :ay'
        );
        $stmt->execute(['ay' => $ay]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) {
            return;
        }

        $allClosed = true;
        $hasRevize = false;
        $hasPending = false;

        foreach ($rows as $row) {
            if ((string) $row['kapanis_durumu'] !== 'KAPANDI') {
                $allClosed = false;
            }
            if ((string) $row['bolum_onay_durumu'] === 'REVIZE_ISTENDI') {
                $hasRevize = true;
            }
            if ((string) $row['bolum_onay_durumu'] === 'BOLUM_ONAYINDA') {
                $hasPending = true;
            }
        }

        $state = 'BOLUM_ONAYINDA';
        if ($allClosed) {
            $state = 'KAPANDI';
        } elseif ($hasRevize) {
            $state = 'REVIZE_ISTENDI';
        } elseif (!$hasPending) {
            $state = 'BOLUM_ONAYLANDI';
        }

        $existing = $pdo->prepare('SELECT id FROM aylik_kapanis_state WHERE ay = :ay LIMIT 1');
        $existing->execute(['ay' => $ay]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingRow) {
            $update = $pdo->prepare('UPDATE aylik_kapanis_state SET state = :state WHERE ay = :ay');
            $update->execute(['state' => $state, 'ay' => $ay]);
            return;
        }

        $insert = $pdo->prepare('INSERT INTO aylik_kapanis_state (ay, state) VALUES (:ay, :state)');
        $insert->execute(['ay' => $ay, 'state' => $state]);
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
        if ($varsayilanSubeId !== null) {
            $subeIds = self::normalizeSubeIdsWithVarsayilan($subeIds, $varsayilanSubeId);
        }

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

        $created = self::findKullaniciById($pdo, $userId, $varsayilanSubeId);
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
            if ($varsayilanSubeId !== null) {
                $subeIds = self::normalizeSubeIdsWithVarsayilan($subeIds, $varsayilanSubeId);
            }
        } elseif (array_key_exists('varsayilan_sube_id', $body) && $varsayilanSubeId !== null) {
            $currentSubeIds = self::loadSubeIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
            self::assertVarsayilanSubeInScope($varsayilanSubeId, $currentSubeIds);
            $subeIds = self::normalizeSubeIdsWithVarsayilan($currentSubeIds, $varsayilanSubeId);
        }

        $responseVarsayilanSubeId = $varsayilanSubeId;
        if ($responseVarsayilanSubeId === null && $subeIds !== null && count($subeIds) > 0) {
            $responseVarsayilanSubeId = $subeIds[0];
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

        $updated = self::findKullaniciById($pdo, $kullaniciId, $responseVarsayilanSubeId);
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
    private static function mapKullaniciRow(array $row, array $subeIds, $varsayilanSubeId = null)
    {
        $resolvedVarsayilan = null;
        $orderedSubeIds = $subeIds;

        if ($varsayilanSubeId !== null && in_array($varsayilanSubeId, $subeIds, true)) {
            $resolvedVarsayilan = $varsayilanSubeId;
            $orderedSubeIds = self::normalizeSubeIdsWithVarsayilan($subeIds, $varsayilanSubeId);
        } elseif (count($subeIds) > 0) {
            $resolvedVarsayilan = $subeIds[0];
            $orderedSubeIds = $subeIds;
        }

        $rol = (string) $row['rol'];

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'ad_soyad' => (string) $row['ad_soyad'],
            'rol' => $rol,
            'durum' => (string) $row['durum'],
            'sube_ids' => $orderedSubeIds,
            'varsayilan_sube_id' => $resolvedVarsayilan,
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
    private static function findKullaniciById(PDO $pdo, $userId, $varsayilanSubeId = null)
    {
        $row = self::findKullaniciRowById($pdo, $userId);
        if ($row === null) {
            return null;
        }

        $subeIds = self::loadSubeIdsByUserIds($pdo, [(int) $userId])[(int) $userId] ?? [];

        return self::mapKullaniciRow($row, $subeIds, $varsayilanSubeId);
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

    /** @param array<int, int> $subeIds @param int|null $varsayilanSubeId @return array<int, int> */
    private static function normalizeSubeIdsWithVarsayilan(array $subeIds, $varsayilanSubeId)
    {
        if ($varsayilanSubeId === null || count($subeIds) === 0) {
            return $subeIds;
        }

        $others = [];
        foreach ($subeIds as $subeId) {
            if ((int) $subeId !== (int) $varsayilanSubeId) {
                $others[] = (int) $subeId;
            }
        }

        return array_merge([(int) $varsayilanSubeId], $others);
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
