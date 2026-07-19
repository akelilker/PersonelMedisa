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
        $fromSql = self::enrichmentFromSql() . '
            WHERE ' . $whereSql;

        try {
            $total = self::countRows($pdo, $fromSql, $params);
            $offset = ($page - 1) * $limit;
            $sql = '
                SELECT ' . self::enrichmentSelectSql() . '
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

    public static function birimAmiriSecenekleri(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'bildirimler.view');
        $subeId = SubeScope::resolveScope($user, $request);
        if ($subeId === null) {
            JsonResponse::badRequest('Birim amiri secenekleri icin sube secilmelidir.', 'VALIDATION_ERROR', 'sube_id');
        }

        try {
            $pdo = Connection::get();
            $stmt = $pdo->prepare('
                SELECT u.id AS user_id, u.ad_soyad, us.sube_id
                FROM users u
                INNER JOIN user_subeler us ON us.user_id = u.id
                WHERE u.rol = :rol
                  AND u.durum = :durum
                  AND us.sube_id = :sube_id
                ORDER BY u.ad_soyad ASC, u.id ASC
            ');
            $stmt->execute([
                'rol' => 'BIRIM_AMIRI',
                'durum' => 'AKTIF',
                'sube_id' => (int) $subeId,
            ]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Birim amiri secenekleri yuklenemedi.');
        }

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'user_id' => (int) $row['user_id'],
                'ad_soyad' => (string) $row['ad_soyad'],
                'sube_id' => (int) $row['sube_id'],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'bildirimler.detail.view');
        $bildirimId = self::parsePositiveInt($id);
        if ($bildirimId === null) {
            JsonResponse::notFound('Kayit bulunamadi.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (!self::isTableReady($pdo)) {
            JsonResponse::notFound('Bildirim bulunamadi.');
        }

        $row = self::fetchRowById($pdo, $bildirimId);
        if (!$row) {
            JsonResponse::notFound('Bildirim bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $row['sube_id']);
        JsonResponse::success(self::mapRow($row));
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
        $iseGiris = isset($personel['ise_giris_tarihi']) ? (string) $personel['ise_giris_tarihi'] : '';
        if ($iseGiris !== '' && $iseGiris > $payload['tarih']) {
            self::validationError('tarih', 'Bildirim tarihi ise giris tarihinden once olamaz.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        $rol = strtoupper(trim((string) ($user['rol'] ?? '')));
        $currentUserId = self::userId($user);
        if ($rol === 'BIRIM_AMIRI') {
            $bagliAmirId = isset($personel['bagli_amir_id']) && $personel['bagli_amir_id'] !== null
                ? (int) $personel['bagli_amir_id']
                : null;
            if ($bagliAmirId !== null && $currentUserId !== null && $bagliAmirId !== $currentUserId) {
                JsonResponse::forbidden('Bu personel sizin sorumluluk kapsamınızda değil.');
            }
        }

        if (self::hasOpenDuplicate($pdo, $payload['personel_id'], $payload['tarih'], $payload['bildirim_turu'])) {
            JsonResponse::error(409, 'CONFLICT', 'Bu personel/tarih/olay için açık bildirim zaten var.');
        }

        // GEC_GELDI / ERKEN_CIKTI: baslangic = beklenen saat, bitis = gerceklesen saat.
        // Her iki HH:MM de verilmisse dakika sunucuda abs fark olarak hesaplanir.
        $dakika = self::resolveDakikaForCreate($payload);

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
                'dakika' => $dakika,
                'aciklama' => $payload['aciklama'],
                'state' => 'TASLAK',
                'created_by' => $currentUserId,
                'updated_by' => $currentUserId,
            ]);
            $insertId = (int) $pdo->lastInsertId();
            $row = self::fetchRowById($pdo, $insertId);
            if (!$row) {
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            JsonResponse::success(self::mapRow($row), [], 201);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                JsonResponse::error(409, 'CONFLICT', 'Bu personel/tarih/olay için açık bildirim zaten var.');
            }
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
        if (array_key_exists('aciklama', $payload)) {
            $fields[] = 'aciklama = :aciklama';
            $params['aciklama'] = $payload['aciklama'];
        }

        if (count($fields) === 0 && !array_key_exists('dakika', $payload) && $payload['bildirim_turu'] === null) {
            JsonResponse::success(self::mapRow($existing));
        }

        $nextTur = $payload['bildirim_turu'] ?? (string) $existing['bildirim_turu'];
        $nextBaslangic = array_key_exists('baslangic_saati', $payload)
            ? $payload['baslangic_saati']
            : ($existing['baslangic_saati'] !== null ? (string) $existing['baslangic_saati'] : null);
        $nextBitis = array_key_exists('bitis_saati', $payload)
            ? $payload['bitis_saati']
            : ($existing['bitis_saati'] !== null ? (string) $existing['bitis_saati'] : null);
        $computedDakika = self::computeDakikaFromTimes($nextTur, $nextBaslangic, $nextBitis);
        if ($computedDakika !== null) {
            $fields[] = 'dakika = :dakika';
            $params['dakika'] = $computedDakika;
        } elseif (array_key_exists('dakika', $payload)) {
            $fields[] = 'dakika = :dakika';
            $params['dakika'] = $payload['dakika'];
        }

        if (count($fields) === 0) {
            JsonResponse::success(self::mapRow($existing));
        }

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
        if ($state === 'HAFTALIK_MUTABAKATA_ALINDI') {
            JsonResponse::error(
                409,
                'CONFLICT',
                'Bu kayıt haftalık mutabakata alındığı için doğrudan değiştirilemez.'
            );
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

    public static function gunlukOzet(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'bildirimler.view');

        $tarih = trim((string) $request->getQuery('tarih', ''));
        if ($tarih === '' || !self::isValidDate($tarih)) {
            self::validationError('tarih', 'Tarih YYYY-MM-DD formatinda zorunludur.');
        }

        $subeId = SubeScope::resolveScope($user, $request);
        if ($subeId === null) {
            self::validationError('sube_id', 'Gunluk ozet icin aktif sube secilmelidir.');
        }
        $subeId = (int) $subeId;

        $rol = strtoupper(trim((string) ($user['rol'] ?? '')));
        $currentUserId = self::userId($user);
        if ($rol === 'BIRIM_AMIRI') {
            $amirId = $currentUserId;
        } else {
            $amirId = self::parsePositiveInt($request->getQuery('birim_amiri_user_id'));
            if ($amirId === null) {
                self::validationError('birim_amiri_user_id', 'Birim amiri secimi zorunludur.');
            }
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (!self::isTableReady($pdo)) {
            JsonResponse::error(
                503,
                'BILDIRIM_SCHEMA_MISSING',
                'Gunluk bildirim tablosu bulunamadi veya migration uygulanmadi.'
            );
        }

        $subeAdi = self::fetchSubeAdi($pdo, $subeId);
        $amirAdi = self::fetchUserAdSoyad($pdo, $amirId);

        $personeller = self::fetchGunlukRoster($pdo, $subeId, $amirId, $tarih);
        $tamamlama = self::isCompletionTableReady($pdo)
            ? self::fetchTamamlama($pdo, $subeId, $amirId, $tarih)
            : null;

        $bildirimGirilen = 0;
        $sorunlu = 0;
        $taslak = 0;
        $gonderildi = 0;
        $duzeltme = 0;
        $sorunluTurler = ['GELMEDI', 'GEC_GELDI', 'ERKEN_CIKTI', 'IZINLI', 'RAPORLU'];

        foreach ($personeller as &$row) {
            $state = $row['bildirim_state'];
            $tur = $row['bildirim_turu'];
            if ($state !== null && $state !== 'IPTAL') {
                $bildirimGirilen++;
                if ($state === 'TASLAK') {
                    $taslak++;
                } elseif ($state === 'GONDERILDI') {
                    $gonderildi++;
                } elseif ($state === 'DUZELTME_ISTENDI') {
                    $duzeltme++;
                }
                if ($state === 'DUZELTME_ISTENDI' || ($tur !== null && in_array($tur, $sorunluTurler, true))) {
                    $sorunlu++;
                }
            }
            $row['durum_label'] = self::durumLabel($state);
        }
        unset($row);

        $toplam = count($personeller);
        // Exception-only model: eksik = taslak + duzeltme bekleyen (herkese GELDI yazilmaz).
        $eksik = $taslak + $duzeltme;
        $tamamlandiMi = is_array($tamamlama);

        JsonResponse::success([
            'tarih' => $tarih,
            'sube_id' => $subeId,
            'sube_adi' => $subeAdi,
            'birim_amiri_user_id' => $amirId,
            'birim_amiri_adi' => $amirAdi,
            'ozet' => [
                'toplam_personel' => $toplam,
                'bildirim_girilen' => $bildirimGirilen,
                'eksik_bildirim' => $eksik,
                'sorunlu_personel' => $sorunlu,
                'taslak' => $taslak,
                'gonderildi' => $gonderildi,
                'duzeltme_istendi' => $duzeltme,
                'tamamlandi_mi' => $tamamlandiMi,
            ],
            'tamamlama' => $tamamlama,
            'personeller' => $personeller,
        ]);
    }

    public static function gunlukTamamlamaGet(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'bildirimler.view');

        $tarih = trim((string) $request->getQuery('tarih', ''));
        if ($tarih === '' || !self::isValidDate($tarih)) {
            self::validationError('tarih', 'Tarih YYYY-MM-DD formatinda zorunludur.');
        }

        $subeId = SubeScope::resolveScope($user, $request);
        if ($subeId === null) {
            self::validationError('sube_id', 'Gunluk tamamlama icin aktif sube secilmelidir.');
        }
        $subeId = (int) $subeId;

        $rol = strtoupper(trim((string) ($user['rol'] ?? '')));
        $currentUserId = self::userId($user);
        if ($rol === 'BIRIM_AMIRI') {
            $amirId = $currentUserId;
        } else {
            $amirId = self::parsePositiveInt($request->getQuery('birim_amiri_user_id'));
            if ($amirId === null) {
                self::validationError('birim_amiri_user_id', 'Birim amiri secimi zorunludur.');
            }
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertCompletionTableReady($pdo);
        $tamamlama = self::fetchTamamlama($pdo, $subeId, $amirId, $tarih);
        JsonResponse::success([
            'tarih' => $tarih,
            'sube_id' => $subeId,
            'birim_amiri_user_id' => $amirId,
            'tamamlama' => $tamamlama,
        ]);
    }

    public static function gunlukTamamlamaCreate(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'gunluk_bildirim.complete_day');

        $body = $request->getJsonBody();
        $tarih = self::requireDate($body, 'tarih', 'Tarih zorunludur.');
        $notMetni = self::optionalString($body, 'not_metni');

        $subeId = SubeScope::resolveScope($user, $request);
        if ($subeId === null) {
            self::validationError('sube_id', 'Gunluk tamamlama icin aktif sube secilmelidir.');
        }
        $subeId = (int) $subeId;
        $amirId = self::userId($user);
        if ($amirId === null) {
            JsonResponse::unauthorized();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertTableReady($pdo);
        self::assertCompletionTableReady($pdo);

        $existing = self::fetchTamamlama($pdo, $subeId, $amirId, $tarih);
        if ($existing) {
            JsonResponse::success($existing);
        }

        $openBlockers = self::countOpenBlockersForAmirDay($pdo, $subeId, $amirId, $tarih);
        if ($openBlockers['taslak'] > 0 || $openBlockers['duzeltme'] > 0) {
            JsonResponse::error(
                409,
                'CONFLICT',
                'Taslak veya duzeltme bekleyen bildirim varken gun tamamlanamaz.'
            );
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO gunluk_bildirim_tamamlamalari (
                    sube_id, birim_amiri_user_id, tarih, state,
                    tamamlayan_user_id, tamamlandi_at, not_metni
                ) VALUES (
                    :sube_id, :birim_amiri_user_id, :tarih, :state,
                    :tamamlayan_user_id, NOW(), :not_metni
                )
            ');
            $stmt->execute([
                'sube_id' => $subeId,
                'birim_amiri_user_id' => $amirId,
                'tarih' => $tarih,
                'state' => 'TAMAMLANDI',
                'tamamlayan_user_id' => $amirId,
                'not_metni' => $notMetni,
            ]);
            $id = (int) $pdo->lastInsertId();
            $row = self::fetchTamamlamaById($pdo, $id);
            if (!$row) {
                JsonResponse::serverError('Gunluk tamamlama kaydedilemedi.');
            }
            JsonResponse::success($row, [], 201);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $again = self::fetchTamamlama($pdo, $subeId, $amirId, $tarih);
                if ($again) {
                    JsonResponse::success($again);
                }
                JsonResponse::error(409, 'CONFLICT', 'Bu gun icin tamamlama zaten mevcut.');
            }
            JsonResponse::serverError('Gunluk tamamlama kaydedilemedi.');
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
        if ($state === 'HAFTALIK_MUTABAKATA_ALINDI') {
            JsonResponse::error(
                409,
                'CONFLICT',
                'Bu kayıt haftalık mutabakata alındığı için doğrudan değiştirilemez.'
            );
        }
        if (in_array($state, ['GONDERILDI', 'IPTAL'], true)) {
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
            SELECT id, sube_id, departman_id, aktif_durum, ise_giris_tarihi, bagli_amir_id
            FROM personeller
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $personelId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function enrichmentSelectSql()
    {
        return '
            gb.*,
            TRIM(CONCAT(COALESCE(p.ad, \'\'), \' \', COALESCE(p.soyad, \'\'))) AS personel_ad_soyad,
            p.sicil_no AS sicil_no,
            g.ad AS gorev_adi,
            d.ad AS departman_adi,
            s.ad AS sube_adi,
            p.bagli_amir_id AS amir_user_id
        ';
    }

    private static function enrichmentFromSql()
    {
        return '
            FROM gunluk_bildirimler gb
            LEFT JOIN personeller p ON p.id = gb.personel_id
            LEFT JOIN departmanlar d ON d.id = gb.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN subeler s ON s.id = gb.sube_id
        ';
    }

    /** @return array<string, mixed>|false */
    private static function fetchRowById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('
            SELECT ' . self::enrichmentSelectSql() . '
            ' . self::enrichmentFromSql() . '
            WHERE gb.id = :id
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
        $mapped = [
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

        if (array_key_exists('personel_ad_soyad', $row) && $row['personel_ad_soyad'] !== null && trim((string) $row['personel_ad_soyad']) !== '') {
            $mapped['personel_ad_soyad'] = trim((string) $row['personel_ad_soyad']);
        }
        if (array_key_exists('sicil_no', $row) && $row['sicil_no'] !== null) {
            $mapped['sicil_no'] = (string) $row['sicil_no'];
        }
        if (array_key_exists('gorev_adi', $row) && $row['gorev_adi'] !== null) {
            $mapped['gorev_adi'] = (string) $row['gorev_adi'];
        }
        if (array_key_exists('departman_adi', $row) && $row['departman_adi'] !== null) {
            $mapped['departman_adi'] = (string) $row['departman_adi'];
        }
        if (array_key_exists('sube_adi', $row) && $row['sube_adi'] !== null) {
            $mapped['sube_adi'] = (string) $row['sube_adi'];
        }
        if (array_key_exists('amir_user_id', $row) && $row['amir_user_id'] !== null) {
            $mapped['amir_user_id'] = (int) $row['amir_user_id'];
        }

        return $mapped;
    }

    private static function hasOpenDuplicate(PDO $pdo, $personelId, $tarih, $bildirimTuru)
    {
        $stmt = $pdo->prepare('
            SELECT 1
            FROM gunluk_bildirimler
            WHERE personel_id = :personel_id
              AND tarih = :tarih
              AND bildirim_turu = :bildirim_turu
              AND state <> :iptal
            LIMIT 1
        ');
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'tarih' => $tarih,
            'bildirim_turu' => $bildirimTuru,
            'iptal' => 'IPTAL',
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $payload */
    private static function resolveDakikaForCreate(array $payload)
    {
        $computed = self::computeDakikaFromTimes(
            $payload['bildirim_turu'],
            $payload['baslangic_saati'] ?? null,
            $payload['bitis_saati'] ?? null
        );
        if ($computed !== null) {
            return $computed;
        }

        return $payload['dakika'] ?? null;
    }

    private static function computeDakikaFromTimes($bildirimTuru, $baslangic, $bitis)
    {
        $tur = strtoupper(trim((string) $bildirimTuru));
        if ($tur !== 'GEC_GELDI' && $tur !== 'ERKEN_CIKTI') {
            return null;
        }
        if ($baslangic === null || $bitis === null) {
            return null;
        }
        $start = self::parseHhMm((string) $baslangic);
        $end = self::parseHhMm((string) $bitis);
        if ($start === null || $end === null) {
            return null;
        }

        return abs($end - $start);
    }

    private static function parseHhMm($value)
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $value), $m)) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }

    public static function isCompletionTableReady(PDO $pdo)
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'gunluk_bildirim_tamamlamalari'");

            return $stmt && (bool) $stmt->fetch();
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function assertCompletionTableReady(PDO $pdo)
    {
        if (!self::isCompletionTableReady($pdo)) {
            JsonResponse::error(
                503,
                'BILDIRIM_COMPLETION_SCHEMA_MISSING',
                'Gunluk bildirim tamamlama tablosu bulunamadi. Migration 032 uygulanmalidir.'
            );
        }
    }

    private static function fetchSubeAdi(PDO $pdo, $subeId)
    {
        $stmt = $pdo->prepare('SELECT ad FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $subeId]);
        $ad = $stmt->fetchColumn();

        return $ad !== false ? (string) $ad : '';
    }

    private static function fetchUserAdSoyad(PDO $pdo, $userId)
    {
        $stmt = $pdo->prepare('SELECT ad_soyad FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $userId]);
        $ad = $stmt->fetchColumn();

        return $ad !== false ? (string) $ad : '';
    }

    /** @return array<int, array<string, mixed>> */
    private static function fetchGunlukRoster(PDO $pdo, $subeId, $amirId, $tarih)
    {
        $sql = '
            SELECT
                p.id AS personel_id,
                TRIM(CONCAT(COALESCE(p.ad, \'\'), \' \', COALESCE(p.soyad, \'\'))) AS ad_soyad,
                p.sicil_no AS sicil_no,
                g.ad AS gorev_adi,
                d.ad AS departman_adi,
                gb.id AS bildirim_id,
                gb.bildirim_turu AS bildirim_turu,
                gb.state AS bildirim_state,
                COALESCE(gb.updated_at, gb.submitted_at, gb.created_at) AS son_islem_at
            FROM personeller p
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN gunluk_bildirimler gb ON gb.id = (
                SELECT gb2.id
                FROM gunluk_bildirimler gb2
                WHERE gb2.personel_id = p.id
                  AND gb2.tarih = :tarih
                  AND gb2.state <> :iptal
                ORDER BY gb2.id DESC
                LIMIT 1
            )
            WHERE p.sube_id = :sube_id
              AND p.aktif_durum = :aktif
              AND p.ise_giris_tarihi <= :tarih_giris
              AND (p.bagli_amir_id = :amir_id OR p.bagli_amir_id IS NULL)
            ORDER BY p.ad ASC, p.soyad ASC, p.id ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'tarih' => $tarih,
            'iptal' => 'IPTAL',
            'sube_id' => (int) $subeId,
            'aktif' => 'AKTIF',
            'tarih_giris' => $tarih,
            'amir_id' => (int) $amirId,
        ]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'personel_id' => (int) $row['personel_id'],
                'ad_soyad' => trim((string) $row['ad_soyad']),
                'sicil_no' => $row['sicil_no'] !== null ? (string) $row['sicil_no'] : null,
                'gorev_adi' => $row['gorev_adi'] !== null ? (string) $row['gorev_adi'] : null,
                'departman_adi' => $row['departman_adi'] !== null ? (string) $row['departman_adi'] : null,
                'bildirim_id' => $row['bildirim_id'] !== null ? (int) $row['bildirim_id'] : null,
                'bildirim_turu' => $row['bildirim_turu'] !== null ? (string) $row['bildirim_turu'] : null,
                'bildirim_state' => $row['bildirim_state'] !== null ? (string) $row['bildirim_state'] : null,
                'son_islem_at' => $row['son_islem_at'] !== null ? (string) $row['son_islem_at'] : null,
                'durum_label' => null,
            ];
        }

        if (count($items) === 0) {
            $fallback = $pdo->prepare('
                SELECT
                    p.id AS personel_id,
                    TRIM(CONCAT(COALESCE(p.ad, \'\'), \' \', COALESCE(p.soyad, \'\'))) AS ad_soyad,
                    p.sicil_no AS sicil_no,
                    g.ad AS gorev_adi,
                    d.ad AS departman_adi,
                    gb.id AS bildirim_id,
                    gb.bildirim_turu AS bildirim_turu,
                    gb.state AS bildirim_state,
                    COALESCE(gb.updated_at, gb.submitted_at, gb.created_at) AS son_islem_at
                FROM personeller p
                LEFT JOIN departmanlar d ON d.id = p.departman_id
                LEFT JOIN gorevler g ON g.id = p.gorev_id
                LEFT JOIN gunluk_bildirimler gb ON gb.id = (
                    SELECT gb2.id
                    FROM gunluk_bildirimler gb2
                    WHERE gb2.personel_id = p.id
                      AND gb2.tarih = :tarih
                      AND gb2.state <> :iptal
                    ORDER BY gb2.id DESC
                    LIMIT 1
                )
                WHERE p.sube_id = :sube_id
                  AND p.aktif_durum = :aktif
                  AND p.ise_giris_tarihi <= :tarih_giris
                ORDER BY p.ad ASC, p.soyad ASC, p.id ASC
            ');
            $fallback->execute([
                'tarih' => $tarih,
                'iptal' => 'IPTAL',
                'sube_id' => (int) $subeId,
                'aktif' => 'AKTIF',
                'tarih_giris' => $tarih,
            ]);
            foreach ($fallback->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'personel_id' => (int) $row['personel_id'],
                    'ad_soyad' => trim((string) $row['ad_soyad']),
                    'sicil_no' => $row['sicil_no'] !== null ? (string) $row['sicil_no'] : null,
                    'gorev_adi' => $row['gorev_adi'] !== null ? (string) $row['gorev_adi'] : null,
                    'departman_adi' => $row['departman_adi'] !== null ? (string) $row['departman_adi'] : null,
                    'bildirim_id' => $row['bildirim_id'] !== null ? (int) $row['bildirim_id'] : null,
                    'bildirim_turu' => $row['bildirim_turu'] !== null ? (string) $row['bildirim_turu'] : null,
                    'bildirim_state' => $row['bildirim_state'] !== null ? (string) $row['bildirim_state'] : null,
                    'son_islem_at' => $row['son_islem_at'] !== null ? (string) $row['son_islem_at'] : null,
                    'durum_label' => null,
                ];
            }
        }

        return $items;
    }

    private static function durumLabel($state)
    {
        if ($state === null || $state === '') {
            return 'Bildirim yok';
        }
        $map = [
            'TASLAK' => 'Taslak',
            'GONDERILDI' => 'Gönderildi',
            'DUZELTME_ISTENDI' => 'Düzeltme İstendi',
            'HAFTALIK_MUTABAKATA_ALINDI' => 'Mutabakata Alındı',
            'IPTAL' => 'İptal',
        ];

        return isset($map[$state]) ? $map[$state] : (string) $state;
    }

    /** @return array<string, mixed>|null */
    private static function fetchTamamlama(PDO $pdo, $subeId, $amirId, $tarih)
    {
        if (!self::isCompletionTableReady($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare('
            SELECT id, tamamlandi_at, tamamlayan_user_id, state
            FROM gunluk_bildirim_tamamlamalari
            WHERE sube_id = :sube_id
              AND birim_amiri_user_id = :amir_id
              AND tarih = :tarih
            LIMIT 1
        ');
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'amir_id' => (int) $amirId,
            'tarih' => $tarih,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'tamamlandi_at' => $row['tamamlandi_at'] !== null ? (string) $row['tamamlandi_at'] : null,
            'tamamlayan_user_id' => (int) $row['tamamlayan_user_id'],
            'state' => (string) $row['state'],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function fetchTamamlamaById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('
            SELECT id, tamamlandi_at, tamamlayan_user_id, state
            FROM gunluk_bildirim_tamamlamalari
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'tamamlandi_at' => $row['tamamlandi_at'] !== null ? (string) $row['tamamlandi_at'] : null,
            'tamamlayan_user_id' => (int) $row['tamamlayan_user_id'],
            'state' => (string) $row['state'],
        ];
    }

    /** @return array{taslak:int, duzeltme:int} */
    private static function countOpenBlockersForAmirDay(PDO $pdo, $subeId, $amirId, $tarih)
    {
        $stmt = $pdo->prepare('
            SELECT
                SUM(CASE WHEN state = :taslak THEN 1 ELSE 0 END) AS taslak,
                SUM(CASE WHEN state = :duzeltme THEN 1 ELSE 0 END) AS duzeltme
            FROM gunluk_bildirimler
            WHERE sube_id = :sube_id
              AND created_by = :amir_id
              AND tarih = :tarih
        ');
        $stmt->execute([
            'taslak' => 'TASLAK',
            'duzeltme' => 'DUZELTME_ISTENDI',
            'sube_id' => (int) $subeId,
            'amir_id' => (int) $amirId,
            'tarih' => $tarih,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'taslak' => (int) ($row['taslak'] ?? 0),
            'duzeltme' => (int) ($row['duzeltme'] ?? 0),
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
