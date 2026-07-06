<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class PersonellerController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
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
            $items[] = self::mapPersonelRow($row);
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

        JsonResponse::success(self::mapPersonelRow($row));
    }

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user);

        $body = $request->getJsonBody();
        $payload = self::normalizeAndValidateCreatePayload($body);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertCreateSubeScope($user, $request, $payload['sube_id']);
        self::validateCreateReferences($pdo, $payload);
        self::assertTcAvailable($pdo, $payload['tc_kimlik_no']);

        $pdo->beginTransaction();
        try {
            $insertId = self::insertPersonel($pdo, $payload);
            $row = self::fetchPersonelRowById($pdo, $insertId);
            if (!$row) {
                $pdo->rollBack();
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            $pdo->commit();
            JsonResponse::success(self::mapPersonelRow($row), [], 201);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (self::isDuplicateTcException($e)) {
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
        $payload = self::normalizeAndValidateUpdatePayload($body);

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

        try {
            self::updatePersonelRow($pdo, $personelId, $payload);
            $row = self::fetchPersonelRowById($pdo, $personelId);
            if (!$row) {
                JsonResponse::serverError('Kayit guncellenemedi.');
            }

            JsonResponse::success(self::mapPersonelRow($row));
        } catch (\PDOException $e) {
            if (self::isDuplicateTcException($e)) {
                self::duplicateTcResponse();
            }

            JsonResponse::serverError('Kayit guncellenemedi.');
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeAndValidateUpdatePayload(array $body)
    {
        $payload = [];

        if (array_key_exists('effective_date', $body) && $body['effective_date'] !== null && trim((string) $body['effective_date']) !== '') {
            $effectiveDate = trim((string) $body['effective_date']);
            if (!self::isValidDateString($effectiveDate)) {
                self::validationError('effective_date', 'Gecerli bir tarih olmalidir.');
            }
        }

        if (array_key_exists('tc_kimlik_no', $body)) {
            $tcKimlikNo = trim((string) $body['tc_kimlik_no']);
            if (!preg_match('/^\d{11}$/', $tcKimlikNo)) {
                self::validationError('tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
            }
            $payload['tc_kimlik_no'] = $tcKimlikNo;
        }

        foreach (['ad', 'soyad', 'telefon', 'acil_durum_kisi', 'acil_durum_telefon', 'sicil_no'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::requireTrimmedString($body, $field, 'Gecersiz deger.');
            }
        }

        foreach (['dogum_tarihi', 'ise_giris_tarihi'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::requireValidDate($body, $field, 'Gecerli bir tarih olmalidir.');
            }
        }

        foreach (['dogum_yeri', 'kan_grubu'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::optionalTrimmedString($body, $field);
            }
        }

        if (array_key_exists('kan_grubu', $payload) && $payload['kan_grubu'] !== null && !in_array($payload['kan_grubu'], self::validKanGruplari(), true)) {
            self::validationError('kan_grubu', 'Gecersiz kan grubu.');
        }

        if (array_key_exists('sube_id', $body)) {
            $payload['sube_id'] = self::requirePositiveInt($body, 'sube_id', 'Sube secilmelidir.');
        }

        foreach (['departman_id', 'gorev_id', 'bagli_amir_id', 'personel_tipi_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::optionalPositiveInt($body, $field);
            }
        }

        foreach (['ucret_tipi_id', 'prim_kurali_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = self::optionalPositiveInt($body, $field);
                if ($value !== null && !in_array($value, [1, 2, 3], true)) {
                    self::validationError($field, 'Gecersiz deger.');
                }
                $payload[$field] = $value;
            }
        }

        if (array_key_exists('net_maas_tutari', $body) || array_key_exists('maas_tutari', $body)) {
            $payload['maas_tutari'] = self::resolveMaasTutariFromBody($body);
        }

        if (array_key_exists('aktif_durum', $body)) {
            $aktifDurum = strtoupper(trim((string) $body['aktif_durum']));
            if (!in_array($aktifDurum, ['AKTIF', 'PASIF'], true)) {
                self::validationError('aktif_durum', 'Aktif durum AKTIF veya PASIF olmalidir.');
            }
            $payload['aktif_durum'] = $aktifDurum;
        }

        return $payload;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function normalizeAndValidateCreatePayload(array $body)
    {
        if (!array_key_exists('tc_kimlik_no', $body) || trim((string) $body['tc_kimlik_no']) === '') {
            self::validationError('tc_kimlik_no', 'T.C. Kimlik No zorunludur.');
        }

        $tcKimlikNo = trim((string) $body['tc_kimlik_no']);
        if (!preg_match('/^\d{11}$/', $tcKimlikNo)) {
            self::validationError('tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
        }

        $ad = self::requireTrimmedString($body, 'ad', 'Ad zorunludur.');
        $soyad = self::requireTrimmedString($body, 'soyad', 'Soyad zorunludur.');

        $dogumTarihi = self::requireValidDate($body, 'dogum_tarihi', 'Dogum tarihi zorunludur.');
        $telefon = self::requireTrimmedString($body, 'telefon', 'Telefon zorunludur.');
        $acilDurumKisi = self::requireTrimmedString($body, 'acil_durum_kisi', 'Acil durum kisi zorunludur.');
        $acilDurumTelefon = self::requireTrimmedString($body, 'acil_durum_telefon', 'Acil durum telefonu zorunludur.');
        $sicilNo = self::requireTrimmedString($body, 'sicil_no', 'Sicil no zorunludur.');
        $iseGirisTarihi = self::requireValidDate($body, 'ise_giris_tarihi', 'Ise giris tarihi zorunludur.');

        $subeId = self::requirePositiveInt($body, 'sube_id', 'Sube secilmelidir.');
        $departmanId = self::requirePositiveInt($body, 'departman_id', 'Departman secilmelidir.');
        $gorevId = self::requirePositiveInt($body, 'gorev_id', 'Gorev secilmelidir.');
        $personelTipiId = self::requirePositiveInt($body, 'personel_tipi_id', 'Personel tipi secilmelidir.');

        if (!array_key_exists('aktif_durum', $body)) {
            self::validationError('aktif_durum', 'Aktif durum zorunludur.');
        }
        $aktifDurum = strtoupper(trim((string) $body['aktif_durum']));
        if (!in_array($aktifDurum, ['AKTIF', 'PASIF'], true)) {
            self::validationError('aktif_durum', 'Aktif durum AKTIF veya PASIF olmalidir.');
        }

        $dogumYeri = self::optionalTrimmedString($body, 'dogum_yeri');
        $kanGrubu = self::optionalTrimmedString($body, 'kan_grubu');
        if ($kanGrubu !== null && !in_array($kanGrubu, self::validKanGruplari(), true)) {
            self::validationError('kan_grubu', 'Gecersiz kan grubu.');
        }

        $bagliAmirId = self::optionalPositiveInt($body, 'bagli_amir_id');
        $ucretTipiId = self::optionalPositiveInt($body, 'ucret_tipi_id');
        if ($ucretTipiId !== null && !in_array($ucretTipiId, [1, 2, 3], true)) {
            self::validationError('ucret_tipi_id', 'Gecersiz ucret tipi.');
        }

        $primKuraliId = self::optionalPositiveInt($body, 'prim_kurali_id');
        if ($primKuraliId !== null && !in_array($primKuraliId, [1, 2, 3], true)) {
            self::validationError('prim_kurali_id', 'Gecersiz prim kurali.');
        }

        $maasTutari = self::resolveMaasTutariFromBody($body);

        return [
            'tc_kimlik_no' => $tcKimlikNo,
            'ad' => $ad,
            'soyad' => $soyad,
            'dogum_tarihi' => $dogumTarihi,
            'telefon' => $telefon,
            'acil_durum_kisi' => $acilDurumKisi,
            'acil_durum_telefon' => $acilDurumTelefon,
            'sicil_no' => $sicilNo,
            'ise_giris_tarihi' => $iseGirisTarihi,
            'sube_id' => $subeId,
            'departman_id' => $departmanId,
            'gorev_id' => $gorevId,
            'personel_tipi_id' => $personelTipiId,
            'aktif_durum' => $aktifDurum,
            'dogum_yeri' => $dogumYeri,
            'kan_grubu' => $kanGrubu,
            'bagli_amir_id' => $bagliAmirId,
            'ucret_tipi_id' => $ucretTipiId,
            'maas_tutari' => $maasTutari,
            'prim_kurali_id' => $primKuraliId,
        ];
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
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc_kimlik_no LIMIT 1');
        $stmt->execute(['tc_kimlik_no' => (string) $tcKimlikNo]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
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
    private static function insertPersonel(PDO $pdo, array $payload)
    {
        $sql = '
            INSERT INTO personeller (
                tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
                sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id,
                bagli_amir_id, aktif_durum, dogum_yeri, kan_grubu, ucret_tipi_id, maas_tutari, prim_kurali_id
            ) VALUES (
                :tc_kimlik_no, :ad, :soyad, :dogum_tarihi, :telefon, :acil_durum_kisi, :acil_durum_telefon,
                :sicil_no, :ise_giris_tarihi, :sube_id, :departman_id, :gorev_id, :personel_tipi_id,
                :bagli_amir_id, :aktif_durum, :dogum_yeri, :kan_grubu, :ucret_tipi_id, :maas_tutari, :prim_kurali_id
            )
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'tc_kimlik_no' => $payload['tc_kimlik_no'],
            'ad' => $payload['ad'],
            'soyad' => $payload['soyad'],
            'dogum_tarihi' => $payload['dogum_tarihi'],
            'telefon' => $payload['telefon'],
            'acil_durum_kisi' => $payload['acil_durum_kisi'],
            'acil_durum_telefon' => $payload['acil_durum_telefon'],
            'sicil_no' => $payload['sicil_no'],
            'ise_giris_tarihi' => $payload['ise_giris_tarihi'],
            'sube_id' => $payload['sube_id'],
            'departman_id' => $payload['departman_id'],
            'gorev_id' => $payload['gorev_id'],
            'personel_tipi_id' => $payload['personel_tipi_id'],
            'bagli_amir_id' => $payload['bagli_amir_id'],
            'aktif_durum' => $payload['aktif_durum'],
            'dogum_yeri' => $payload['dogum_yeri'],
            'kan_grubu' => $payload['kan_grubu'],
            'ucret_tipi_id' => $payload['ucret_tipi_id'],
            'maas_tutari' => $payload['maas_tutari'],
            'prim_kurali_id' => $payload['prim_kurali_id'],
        ]);

        return (int) $pdo->lastInsertId();
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
    private static function requireValidDate(array $body, $field, $missingMessage)
    {
        if (!array_key_exists($field, $body) || trim((string) $body[$field]) === '') {
            self::validationError((string) $field, $missingMessage);
        }

        $value = trim((string) $body[$field]);
        if (!self::isValidDateString($value)) {
            self::validationError((string) $field, 'Gecerli bir tarih olmalidir.');
        }

        return $value;
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

    /** @param array<string, mixed> $body */
    private static function optionalPositiveInt(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }

        $value = self::parsePositiveInt($body[$field]);
        if ($value === null) {
            self::validationError((string) $field, 'Gecersiz deger.');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalNonNegativeNumber(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }

        if (!is_numeric($body[$field])) {
            self::validationError((string) $field, 'Maas tutari sayisal olmalidir.');
        }

        $value = (float) $body[$field];
        if ($value < 0) {
            self::validationError((string) $field, 'Maas tutari sifirdan kucuk olamaz.');
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

    private static function isValidDateString($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    /** @return array<int, string> */
    private static function validKanGruplari()
    {
        return ['A Rh+', 'A Rh-', 'B Rh+', 'B Rh-', 'AB Rh+', 'AB Rh-', '0 Rh+', '0 Rh-'];
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    /** @param array<string, mixed> $body */
    private static function resolveMaasTutariFromBody(array $body)
    {
        if (array_key_exists('net_maas_tutari', $body)) {
            return self::optionalNonNegativeNumber($body, 'net_maas_tutari');
        }

        if (array_key_exists('maas_tutari', $body)) {
            return self::optionalNonNegativeNumber($body, 'maas_tutari');
        }

        return null;
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
    private static function mapPersonelRow(array $row)
    {
        $ucretTipiId = $row['ucret_tipi_id'] !== null ? (int) $row['ucret_tipi_id'] : null;
        $primKuraliId = $row['prim_kurali_id'] !== null ? (int) $row['prim_kurali_id'] : null;
        $ucretTipiAdlari = [1 => 'Aylik', 2 => 'Gunluk', 3 => 'Saatlik'];
        $primKuraliAdlari = [1 => 'Devamsizlik Primi Yok', 2 => 'Tam Prim', 3 => 'Kismi Prim'];
        $maasTutari = $row['maas_tutari'] !== null ? (float) $row['maas_tutari'] : null;

        return [
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
    }
}
