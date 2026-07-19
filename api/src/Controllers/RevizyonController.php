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
use PDOException;
use Throwable;

/**
 * S79-E: haftalik kapanis revizyon talebi owner.
 * Correction uretimi S79-F kapsaminda; onay correction_event_id = null birakir.
 */
class RevizyonController
{
    private const REVIZYON_TIPLERI = [
        'PUANTAJ_GIRIS_CIKIS_DUZELTME',
        'MOLA_DUZELTME',
        'DEVAMSIZLIK_DUZELTME',
        'SUREC_GEC_GIRIS',
        'SERBEST_ZAMAN_ETKI_DUZELTME',
        'KAPANIS_HESAP_REVIZYONU',
        'BORDRO_ETKI_NOTU',
    ];

    private const SERVER_OWNED_FIELDS = [
        'id',
        'sube_id',
        'kapanis_id',
        'snapshot_id',
        'durum',
        'talep_eden_kullanici_id',
        'talep_eden_rol',
        'talep_zamani',
        'karar_veren_kullanici_id',
        'karar_zamani',
        'karar_aciklamasi',
        'karar_notu',
        'correction_event_id',
        'created_at',
        'updated_at',
        'acik_talep_slot',
    ];

    public static function talepleri(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.view');
        self::assertSchemaReady();

        $pdo = Connection::get();
        $filters = self::parseListFilters($request);
        $rows = self::queryTalepler($pdo, $user, $request, $filters, null);

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::presentTalep($user, $row);
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function talepDetail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.view');
        self::assertSchemaReady();

        $talepId = self::parsePositiveInt($id, 'id', true);
        $pdo = Connection::get();
        $row = self::loadTalepById($pdo, $talepId, false);
        if ($row === null) {
            JsonResponse::error(404, 'NOT_FOUND', 'Revizyon talebi bulunamadi.');
        }

        self::assertCanViewTalep($user, $request, $row);
        JsonResponse::success(self::presentTalep($user, $row));
    }

    public static function createTalep(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.create');
        self::assertSchemaReady();

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            self::validationError('body', 'Gecersiz JSON body.');
        }
        self::rejectServerOwnedFields($body);
        if (array_key_exists('sube_id', $body)) {
            self::validationError('sube_id', 'sube_id istemci tarafindan belirlenemez.');
        }

        $personelId = self::parsePositiveInt($body['personel_id'] ?? null, 'personel_id', true);
        $haftaBaslangic = self::requireDate($body, 'hafta_baslangic');
        $haftaBitis = self::requireDate($body, 'hafta_bitis');
        $etkilenenTarih = self::requireDate($body, 'etkilenen_tarih');
        $kaynakTipi = self::requireTrimmedString($body, 'kaynak_tipi');
        $kaynakId = self::parsePositiveInt($body['kaynak_id'] ?? null, 'kaynak_id', true);
        $revizyonTipi = self::requireRevizyonTipi($body);
        $gerekce = self::requireTrimmedString($body, 'gerekce');
        if (mb_strlen($gerekce) > 1000) {
            self::validationError('gerekce', 'gerekce en fazla 1000 karakter olabilir.');
        }

        if ($haftaBitis !== self::addDays($haftaBaslangic, 6)) {
            self::validationError('hafta_bitis', 'hafta_bitis hafta_baslangic + 6 gun olmalidir.');
        }
        if ($etkilenenTarih < $haftaBaslangic || $etkilenenTarih > $haftaBitis) {
            self::validationError('etkilenen_tarih', 'etkilenen_tarih hafta araliginda olmalidir.');
        }

        $bordroEtkiVarMi = self::optionalBool($body, 'bordro_etki_var_mi', false);
        $bordroEtkiNotu = self::optionalNullableString($body, 'bordro_etki_notu', 1000);
        $oncekiDegerJson = self::encodeScalarJson($body['onceki_deger'] ?? null, 'onceki_deger');
        $talepEdilenDegerJson = self::encodeScalarJson($body['talep_edilen_deger'] ?? null, 'talep_edilen_deger');

        $pdo = Connection::get();
        $personel = self::loadPersonel($pdo, $personelId);
        self::assertCreateScope($user, $request, $personel, $bordroEtkiVarMi, $bordroEtkiNotu);
        self::assertKaynakExists($pdo, $kaynakTipi, $kaynakId, $personelId, $etkilenenTarih, $haftaBaslangic, $haftaBitis);

        $kapanis = self::findClosedKapanisForPersonel($pdo, $personel, $haftaBaslangic, $haftaBitis);
        if ($kapanis === null) {
            self::conflict('PERIOD_NOT_CLOSED', 'Ilgili haftalik kapanis bulunamadi veya kapanmamis.');
        }

        $snapshotId = (int) $kapanis['snapshot_id'];
        $kapanisId = (int) $kapanis['kapanis_id'];
        $subeId = (int) $personel['sube_id'];
        $now = self::nowSql();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO haftalik_kapanis_revizyon_talepleri
                  (personel_id, sube_id, kapanis_id, snapshot_id, hafta_baslangic, hafta_bitis, etkilenen_tarih,
                   kaynak_tipi, kaynak_id, revizyon_tipi, onceki_deger, talep_edilen_deger, gerekce,
                   bordro_etki_var_mi, bordro_etki_notu, durum, talep_eden_kullanici_id, talep_eden_rol,
                   talep_zamani, karar_veren_kullanici_id, karar_zamani, karar_aciklamasi, correction_event_id)
                 VALUES
                  (:personel_id, :sube_id, :kapanis_id, :snapshot_id, :hafta_baslangic, :hafta_bitis, :etkilenen_tarih,
                   :kaynak_tipi, :kaynak_id, :revizyon_tipi, :onceki_deger, :talep_edilen_deger, :gerekce,
                   :bordro_etki_var_mi, :bordro_etki_notu, \'TASLAK\', :talep_eden_kullanici_id, :talep_eden_rol,
                   :talep_zamani, NULL, NULL, NULL, NULL)'
            );
            $stmt->execute([
                'personel_id' => $personelId,
                'sube_id' => $subeId,
                'kapanis_id' => $kapanisId,
                'snapshot_id' => $snapshotId,
                'hafta_baslangic' => $haftaBaslangic,
                'hafta_bitis' => $haftaBitis,
                'etkilenen_tarih' => $etkilenenTarih,
                'kaynak_tipi' => $kaynakTipi,
                'kaynak_id' => $kaynakId,
                'revizyon_tipi' => $revizyonTipi,
                'onceki_deger' => $oncekiDegerJson,
                'talep_edilen_deger' => $talepEdilenDegerJson,
                'gerekce' => $gerekce,
                'bordro_etki_var_mi' => $bordroEtkiVarMi ? 1 : 0,
                'bordro_etki_notu' => $bordroEtkiNotu,
                'talep_eden_kullanici_id' => (int) $user['id'],
                'talep_eden_rol' => (string) $user['rol'],
                'talep_zamani' => $now,
            ]);
            $talepId = (int) $pdo->lastInsertId();
            self::appendGecmis($pdo, $talepId, null, 'TASLAK', 'OLUSTUR', null, (int) $user['id'], $now);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (self::isDuplicateKey($e)) {
                self::conflict('ALREADY_EXISTS', 'Ayni acik kaynak icin revizyon talebi zaten mevcut.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $row = self::loadTalepById($pdo, $talepId, false);
        JsonResponse::success(self::presentTalep($user, $row), [], 201);
    }

    public static function gonder(Request $request, $id)
    {
        self::transitionAction($request, $id, 'GONDER', 'revizyon.submit', 'TASLAK', 'ONAY_BEKLIYOR', false, true);
    }

    public static function onay(Request $request, $id)
    {
        self::transitionAction($request, $id, 'ONAY', 'revizyon.approve', 'ONAY_BEKLIYOR', 'ONAYLANDI', false, false);
    }

    public static function red(Request $request, $id)
    {
        self::transitionAction($request, $id, 'RED', 'revizyon.reject', 'ONAY_BEKLIYOR', 'REDDEDILDI', true, false);
    }

    public static function iptal(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.cancel');
        self::assertSchemaReady();

        $talepId = self::parsePositiveInt($id, 'id', true);
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        self::rejectUnknownTransitionBody($body, false);

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $row = self::loadTalepById($pdo, $talepId, true);
            if ($row === null) {
                $pdo->rollBack();
                JsonResponse::error(404, 'NOT_FOUND', 'Revizyon talebi bulunamadi.');
            }

            self::assertCanViewTalep($user, $request, $row);
            self::assertOwnershipForCancel($user, $row);

            $from = (string) $row['durum'];
            if ($from !== 'TASLAK' && $from !== 'ONAY_BEKLIYOR') {
                $pdo->rollBack();
                self::conflict('STATE_CONFLICT', 'Bu durumdan iptal yapilamaz.');
            }

            $kararNotu = self::optionalNullableString($body, 'karar_notu', 1000);
            $now = self::nowSql();
            self::updateDurum($pdo, $talepId, 'IPTAL', (int) $user['id'], $now, $kararNotu, true);
            self::appendGecmis($pdo, $talepId, $from, 'IPTAL', 'IPTAL', $kararNotu, (int) $user['id'], $now);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fresh = self::loadTalepById($pdo, $talepId, false);
        JsonResponse::success(self::presentTalep($user, $fresh));
    }

    /** Correction list stub remains until S79-F. */
    public static function corrections(Request $request)
    {
        AuthMiddleware::authenticate($request, true);
        JsonResponse::success(['items' => []]);
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function transitionAction(
        Request $request,
        $id,
        string $aksiyon,
        string $permission,
        string $expectedFrom,
        string $to,
        bool $requireKararNotu,
        bool $requireOwnership
    ): void {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, $permission);
        self::assertSchemaReady();

        $talepId = self::parsePositiveInt($id, 'id', true);
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        self::rejectUnknownTransitionBody($body, $aksiyon !== 'GONDER');

        $kararNotu = self::optionalNullableString($body, 'karar_notu', 1000);
        if ($requireKararNotu && ($kararNotu === null || trim($kararNotu) === '')) {
            self::validationError('karar_notu', 'Red aciklamasi zorunludur.');
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $row = self::loadTalepById($pdo, $talepId, true);
            if ($row === null) {
                $pdo->rollBack();
                JsonResponse::error(404, 'NOT_FOUND', 'Revizyon talebi bulunamadi.');
            }

            self::assertCanViewTalep($user, $request, $row);
            if ($requireOwnership) {
                self::assertOwnershipForSubmit($user, $row);
            }

            $from = (string) $row['durum'];
            if ($from !== $expectedFrom) {
                $pdo->rollBack();
                self::conflict('STATE_CONFLICT', 'Gecersiz durum gecisi.');
            }

            $now = self::nowSql();
            $setKarar = $aksiyon === 'ONAY' || $aksiyon === 'RED';
            self::updateDurum($pdo, $talepId, $to, (int) $user['id'], $now, $kararNotu, $setKarar);
            self::appendGecmis($pdo, $talepId, $from, $to, $aksiyon, $kararNotu, (int) $user['id'], $now);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fresh = self::loadTalepById($pdo, $talepId, false);
        JsonResponse::success(self::presentTalep($user, $fresh));
    }

    /** @param array<string, mixed> $body */
    private static function rejectUnknownTransitionBody(array $body, bool $allowKararNotu): void
    {
        foreach (array_keys($body) as $key) {
            if ($allowKararNotu && $key === 'karar_notu') {
                continue;
            }
            if ($key === 'karar_notu' && !$allowKararNotu) {
                // gonder body must be empty / {}
                self::validationError($key, 'Bu aksiyon icin ekstra alan kabul edilmez.');
            }
            if ($key !== 'karar_notu') {
                self::validationError((string) $key, 'Bu aksiyon icin ekstra alan kabul edilmez.');
            }
        }
        if (!$allowKararNotu && array_key_exists('karar_notu', $body) && $body['karar_notu'] !== null) {
            self::validationError('karar_notu', 'Bu aksiyon icin ekstra alan kabul edilmez.');
        }
    }

    /** @param array<string, mixed> $body */
    private static function rejectServerOwnedFields(array $body): void
    {
        foreach (self::SERVER_OWNED_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                self::validationError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentTalep(array $user, array $row): array
    {
        $payload = [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'hafta_baslangic' => (string) $row['hafta_baslangic'],
            'hafta_bitis' => (string) $row['hafta_bitis'],
            'etkilenen_tarih' => (string) $row['etkilenen_tarih'],
            'kaynak_tipi' => (string) $row['kaynak_tipi'],
            'kaynak_id' => (int) $row['kaynak_id'],
            'revizyon_tipi' => (string) $row['revizyon_tipi'],
            'onceki_deger' => self::decodeScalarJson($row['onceki_deger'] ?? null),
            'talep_edilen_deger' => self::decodeScalarJson($row['talep_edilen_deger'] ?? null),
            'gerekce' => (string) $row['gerekce'],
            'talep_eden_kullanici_id' => (int) $row['talep_eden_kullanici_id'],
            'talep_zamani' => self::toIsoDatetime((string) $row['talep_zamani']),
            'durum' => (string) $row['durum'],
            'karar_veren_kullanici_id' => $row['karar_veren_kullanici_id'] !== null
                ? (int) $row['karar_veren_kullanici_id']
                : null,
            'karar_zamani' => $row['karar_zamani'] !== null
                ? self::toIsoDatetime((string) $row['karar_zamani'])
                : null,
            'karar_notu' => $row['karar_aciklamasi'] !== null ? (string) $row['karar_aciklamasi'] : null,
            'bordro_etki_var_mi' => ((int) ($row['bordro_etki_var_mi'] ?? 0)) === 1,
            'bordro_etki_notu' => $row['bordro_etki_notu'] !== null ? (string) $row['bordro_etki_notu'] : null,
            'correction_event_id' => $row['correction_event_id'] !== null
                ? (int) $row['correction_event_id']
                : null,
        ];

        if ((string) ($user['rol'] ?? '') === 'BIRIM_AMIRI') {
            $payload['bordro_etki_notu'] = null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $user
     * @param array{personel_id?:int|null,durum?:string|null,hafta_baslangic?:string|null,hafta_bitis?:string|null} $filters
     * @return array<int, array<string, mixed>>
     */
    private static function queryTalepler(PDO $pdo, array $user, Request $request, array $filters, $forceId)
    {
        $where = [];
        $params = [];

        if ($forceId !== null) {
            $where[] = 't.id = :force_id';
            $params['force_id'] = (int) $forceId;
        }
        if ($filters['personel_id'] !== null) {
            $where[] = 't.personel_id = :filter_personel_id';
            $params['filter_personel_id'] = (int) $filters['personel_id'];
        }
        if ($filters['durum'] !== null) {
            $where[] = 't.durum = :filter_durum';
            $params['filter_durum'] = $filters['durum'];
        }
        if ($filters['hafta_baslangic'] !== null) {
            $where[] = 't.hafta_baslangic = :filter_hafta_baslangic';
            $params['filter_hafta_baslangic'] = $filters['hafta_baslangic'];
        }
        if ($filters['hafta_bitis'] !== null) {
            $where[] = 't.hafta_bitis = :filter_hafta_bitis';
            $params['filter_hafta_bitis'] = $filters['hafta_bitis'];
        }

        $rol = (string) ($user['rol'] ?? '');
        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0 && $rol !== 'GENEL_YONETICI') {
            return [];
        }
        if (count($allowed) > 0) {
            $placeholders = [];
            foreach (array_values($allowed) as $i => $subeId) {
                $key = 'allowed_sube_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $subeId;
            }
            $where[] = 't.sube_id IN (' . implode(', ', $placeholders) . ')';
        }

        $scope = SubeScope::resolveScope($user, $request);
        if ($scope !== null) {
            $where[] = 't.sube_id = :active_sube_id';
            $params['active_sube_id'] = (int) $scope;
        }

        if ($rol === 'BOLUM_YONETICISI') {
            $departmanIds = self::loadUserDepartmanIds($pdo, (int) $user['id']);
            if (count($departmanIds) === 0) {
                return [];
            }
            $placeholders = [];
            foreach (array_values($departmanIds) as $i => $departmanId) {
                $key = 'dep_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $departmanId;
            }
            $where[] = 'p.departman_id IN (' . implode(', ', $placeholders) . ')';
        } elseif ($rol === 'MUHASEBE') {
            $where[] = 't.bordro_etki_var_mi = 1';
        } elseif ($rol === 'BIRIM_AMIRI') {
            // Sube filter above; BA sees personel in allowed subeler.
        } elseif ($rol === 'PATRON') {
            return [];
        }

        $sql = 'SELECT t.*
                FROM haftalik_kapanis_revizyon_talepleri t
                INNER JOIN personeller p ON p.id = t.personel_id';
        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.talep_zamani DESC, t.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{personel_id:?int,durum:?string,hafta_baslangic:?string,hafta_bitis:?string} */
    private static function parseListFilters(Request $request): array
    {
        $personelId = $request->getQuery('personel_id');
        $durum = $request->getQuery('durum');
        $haftaBaslangic = $request->getQuery('hafta_baslangic');
        $haftaBitis = $request->getQuery('hafta_bitis');

        if ($request->getQuery('sube_id') !== null && $request->getQuery('sube_id') !== '') {
            // Active sube comes from header/scope helpers; query sube_id as filter field is rejected
            // only when used as body-like domain override. Header-based SubeScope still applies.
        }

        return [
            'personel_id' => $personelId !== null && $personelId !== ''
                ? self::parsePositiveInt($personelId, 'personel_id', true)
                : null,
            'durum' => $durum !== null && $durum !== '' ? self::requireDurumFilter((string) $durum) : null,
            'hafta_baslangic' => $haftaBaslangic !== null && $haftaBaslangic !== ''
                ? self::normalizeDate((string) $haftaBaslangic, 'hafta_baslangic')
                : null,
            'hafta_bitis' => $haftaBitis !== null && $haftaBitis !== ''
                ? self::normalizeDate((string) $haftaBitis, 'hafta_bitis')
                : null,
        ];
    }

    private static function requireDurumFilter(string $durum): string
    {
        $allowed = ['TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'REDDEDILDI', 'IPTAL'];
        if (!in_array($durum, $allowed, true)) {
            self::validationError('durum', 'durum gecersiz.');
        }

        return $durum;
    }

    /** @return array<string, mixed>|null */
    private static function loadTalepById(PDO $pdo, int $id, bool $forUpdate)
    {
        $sql = 'SELECT * FROM haftalik_kapanis_revizyon_talepleri WHERE id = :id';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private static function assertCanViewTalep(array $user, Request $request, array $row): void
    {
        $rol = (string) ($user['rol'] ?? '');
        if ($rol === 'PATRON') {
            JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $row['sube_id']);

        if ($rol === 'GENEL_YONETICI') {
            return;
        }

        $pdo = Connection::get();
        $personel = self::loadPersonel($pdo, (int) $row['personel_id']);

        if ($rol === 'BOLUM_YONETICISI') {
            $departmanIds = self::loadUserDepartmanIds($pdo, (int) $user['id']);
            $depId = $personel['departman_id'] !== null ? (int) $personel['departman_id'] : null;
            if ($depId === null || !in_array($depId, $departmanIds, true)) {
                JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
            }
            return;
        }

        if ($rol === 'MUHASEBE') {
            if (((int) ($row['bordro_etki_var_mi'] ?? 0)) !== 1) {
                JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
            }
            return;
        }

        if ($rol === 'BIRIM_AMIRI') {
            return;
        }

        JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $personel
     */
    private static function assertCreateScope(
        array $user,
        Request $request,
        array $personel,
        bool $bordroEtkiVarMi,
        $bordroEtkiNotu
    ): void {
        $rol = (string) ($user['rol'] ?? '');
        if ($rol === 'PATRON') {
            JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        if ($rol === 'GENEL_YONETICI') {
            return;
        }

        if ($rol === 'BOLUM_YONETICISI') {
            $departmanIds = self::loadUserDepartmanIds(Connection::get(), (int) $user['id']);
            $depId = $personel['departman_id'] !== null ? (int) $personel['departman_id'] : null;
            if ($depId === null || !in_array($depId, $departmanIds, true)) {
                JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
            }
            return;
        }

        if ($rol === 'MUHASEBE') {
            $hasNote = is_string($bordroEtkiNotu) && trim($bordroEtkiNotu) !== '';
            if (!$bordroEtkiVarMi && !$hasNote) {
                JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
            }
            return;
        }

        if ($rol === 'BIRIM_AMIRI') {
            return;
        }

        JsonResponse::error(403, 'REVISION_SCOPE_DENIED', 'Revizyon talebi kapsam disi.');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private static function assertOwnershipForSubmit(array $user, array $row): void
    {
        if ((string) ($user['rol'] ?? '') === 'GENEL_YONETICI') {
            return;
        }
        if ((int) $row['talep_eden_kullanici_id'] !== (int) $user['id']) {
            JsonResponse::error(403, 'REVISION_OWNER_DENIED', 'Bu revizyon talebi size ait degil.');
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private static function assertOwnershipForCancel(array $user, array $row): void
    {
        if ((string) ($user['rol'] ?? '') === 'GENEL_YONETICI') {
            return;
        }
        if ((int) $row['talep_eden_kullanici_id'] !== (int) $user['id']) {
            JsonResponse::error(403, 'REVISION_OWNER_DENIED', 'Bu revizyon talebi size ait degil.');
        }
    }

    /**
     * @param array<string, mixed> $personel
     * @return array{kapanis_id:int,snapshot_id:int}|null
     */
    private static function findClosedKapanisForPersonel(
        PDO $pdo,
        array $personel,
        string $haftaBaslangic,
        string $haftaBitis
    ) {
        $stmt = $pdo->prepare(
            'SELECT hk.id AS kapanis_id, hks.id AS snapshot_id
             FROM haftalik_kapanislar hk
             INNER JOIN haftalik_kapanis_satirlari hks ON hks.kapanis_id = hk.id
             WHERE hk.sube_id = :sube_id
               AND hk.hafta_baslangic = :hafta_baslangic
               AND hk.hafta_bitis = :hafta_bitis
               AND hk.state = \'KAPANDI\'
               AND hks.personel_id = :personel_id
               AND hks.state = \'KAPANDI\'
             ORDER BY hk.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => (int) $personel['sube_id'],
            'hafta_baslangic' => $haftaBaslangic,
            'hafta_bitis' => $haftaBitis,
            'personel_id' => (int) $personel['id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'kapanis_id' => (int) $row['kapanis_id'],
            'snapshot_id' => (int) $row['snapshot_id'],
        ];
    }

    private static function assertKaynakExists(
        PDO $pdo,
        string $kaynakTipi,
        int $kaynakId,
        int $personelId,
        string $etkilenenTarih,
        string $haftaBaslangic,
        string $haftaBitis
    ): void {
        $tipi = strtoupper(trim($kaynakTipi));
        if ($tipi === 'PUANTAJ') {
            $stmt = $pdo->prepare(
                'SELECT id FROM gunluk_puantaj
                 WHERE id = :id AND personel_id = :personel_id AND tarih = :tarih
                 LIMIT 1'
            );
            $stmt->execute([
                'id' => $kaynakId,
                'personel_id' => $personelId,
                'tarih' => $etkilenenTarih,
            ]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                JsonResponse::error(404, 'TARGET_NOT_FOUND', 'Revize edilecek kaynak bulunamadi.');
            }
            return;
        }

        if ($tipi === 'HAFTALIK_KAPANIS_SATIR' || $tipi === 'KAPANIS_SATIR') {
            $stmt = $pdo->prepare(
                'SELECT id FROM haftalik_kapanis_satirlari
                 WHERE id = :id AND personel_id = :personel_id
                   AND hafta_baslangic = :hafta_baslangic AND hafta_bitis = :hafta_bitis
                 LIMIT 1'
            );
            $stmt->execute([
                'id' => $kaynakId,
                'personel_id' => $personelId,
                'hafta_baslangic' => $haftaBaslangic,
                'hafta_bitis' => $haftaBitis,
            ]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                JsonResponse::error(404, 'TARGET_NOT_FOUND', 'Revize edilecek kaynak bulunamadi.');
            }
            return;
        }

        if ($tipi === 'SERBEST_ZAMAN') {
            $stmt = $pdo->prepare(
                'SELECT id FROM serbest_zaman_events
                 WHERE id = :id AND personel_id = :personel_id AND event_tarihi = :tarih
                 LIMIT 1'
            );
            try {
                $stmt->execute([
                    'id' => $kaynakId,
                    'personel_id' => $personelId,
                    'tarih' => $etkilenenTarih,
                ]);
            } catch (PDOException $e) {
                JsonResponse::error(404, 'TARGET_NOT_FOUND', 'Revize edilecek kaynak bulunamadi.');
            }
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                JsonResponse::error(404, 'TARGET_NOT_FOUND', 'Revize edilecek kaynak bulunamadi.');
            }
            return;
        }

        if ($tipi === 'SUREC') {
            $stmt = $pdo->prepare(
                'SELECT id FROM surecler WHERE id = :id AND personel_id = :personel_id LIMIT 1'
            );
            $stmt->execute(['id' => $kaynakId, 'personel_id' => $personelId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                JsonResponse::error(404, 'TARGET_NOT_FOUND', 'Revize edilecek kaynak bulunamadi.');
            }
            return;
        }

        JsonResponse::error(404, 'TARGET_NOT_FOUND', 'Revize edilecek kaynak bulunamadi.');
    }

    /** @return array<string, mixed> */
    private static function loadPersonel(PDO $pdo, int $personelId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, sube_id, departman_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::error(404, 'TARGET_NOT_FOUND', 'Personel bulunamadi.');
        }

        return $row;
    }

    /** @return array<int, int> */
    private static function loadUserDepartmanIds(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT sd.departman_id
             FROM user_subeler us
             INNER JOIN sube_departmanlar sd ON sd.sube_id = us.sube_id
             WHERE us.user_id = :user_id
             ORDER BY sd.departman_id ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['departman_id'];
        }

        return $ids;
    }

    private static function updateDurum(
        PDO $pdo,
        int $talepId,
        string $to,
        int $userId,
        string $now,
        $kararNotu,
        bool $setKarar
    ): void {
        if ($setKarar) {
            $stmt = $pdo->prepare(
                'UPDATE haftalik_kapanis_revizyon_talepleri
                 SET durum = :durum,
                     karar_veren_kullanici_id = :karar_veren,
                     karar_zamani = :karar_zamani,
                     karar_aciklamasi = :karar_aciklamasi,
                     correction_event_id = NULL
                 WHERE id = :id'
            );
            $stmt->execute([
                'durum' => $to,
                'karar_veren' => $userId,
                'karar_zamani' => $now,
                'karar_aciklamasi' => $kararNotu,
                'id' => $talepId,
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE haftalik_kapanis_revizyon_talepleri
             SET durum = :durum,
                 karar_veren_kullanici_id = CASE WHEN :set_karar_iptal = 1 THEN :karar_veren ELSE karar_veren_kullanici_id END,
                 karar_zamani = CASE WHEN :set_karar_iptal2 = 1 THEN :karar_zamani ELSE karar_zamani END,
                 karar_aciklamasi = CASE WHEN :set_karar_iptal3 = 1 THEN :karar_aciklamasi ELSE karar_aciklamasi END
             WHERE id = :id'
        );
        $isIptal = $to === 'IPTAL' ? 1 : 0;
        $stmt->execute([
            'durum' => $to,
            'set_karar_iptal' => $isIptal,
            'set_karar_iptal2' => $isIptal,
            'set_karar_iptal3' => $isIptal,
            'karar_veren' => $userId,
            'karar_zamani' => $now,
            'karar_aciklamasi' => $kararNotu,
            'id' => $talepId,
        ]);
    }

    private static function appendGecmis(
        PDO $pdo,
        int $talepId,
        $oncekiDurum,
        string $yeniDurum,
        string $aksiyon,
        $aciklama,
        int $userId,
        string $now
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO haftalik_kapanis_revizyon_talebi_gecmisi
              (revizyon_talebi_id, onceki_durum, yeni_durum, aksiyon, aciklama, islem_yapan_kullanici_id, islem_zamani)
             VALUES
              (:talep_id, :onceki_durum, :yeni_durum, :aksiyon, :aciklama, :user_id, :islem_zamani)'
        );
        $stmt->execute([
            'talep_id' => $talepId,
            'onceki_durum' => $oncekiDurum,
            'yeni_durum' => $yeniDurum,
            'aksiyon' => $aksiyon,
            'aciklama' => $aciklama,
            'user_id' => $userId,
            'islem_zamani' => $now,
        ]);
    }

    private static function assertSchemaReady(): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->query("SHOW TABLES LIKE 'haftalik_kapanis_revizyon_talepleri'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_NUM)) {
            JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Revizyon talebi semasi hazir degil.');
        }
    }

    /** @param array<string, mixed> $body */
    private static function requireRevizyonTipi(array $body): string
    {
        $tipi = isset($body['revizyon_tipi']) ? strtoupper(trim((string) $body['revizyon_tipi'])) : '';
        if (!in_array($tipi, self::REVIZYON_TIPLERI, true)) {
            self::validationError('revizyon_tipi', 'revizyon_tipi gecersiz.');
        }

        return $tipi;
    }

    /** @param array<string, mixed> $body */
    private static function requireDate(array $body, string $field): string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            self::validationError($field, $field . ' zorunludur.');
        }

        return self::normalizeDate((string) $body[$field], $field);
    }

    private static function normalizeDate(string $value, string $field): string
    {
        $trimmed = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            self::validationError($field, $field . ' YYYY-MM-DD formatinda olmalidir.');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
        if (!$dt || $dt->format('Y-m-d') !== $trimmed) {
            self::validationError($field, $field . ' gecersiz tarih.');
        }

        return $trimmed;
    }

    /** @param array<string, mixed> $body */
    private static function requireTrimmedString(array $body, string $field): string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            self::validationError($field, $field . ' zorunludur.');
        }
        if (!is_string($body[$field]) && !is_numeric($body[$field])) {
            self::validationError($field, $field . ' metin olmalidir.');
        }
        $value = trim((string) $body[$field]);
        if ($value === '') {
            self::validationError($field, $field . ' zorunludur.');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalNullableString(array $body, string $field, int $maxLen)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        if (!is_string($body[$field]) && !is_numeric($body[$field])) {
            self::validationError($field, $field . ' metin olmalidir.');
        }
        $value = trim((string) $body[$field]);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLen) {
            self::validationError($field, $field . ' en fazla ' . $maxLen . ' karakter olabilir.');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalBool(array $body, string $field, bool $fallback): bool
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return $fallback;
        }
        if (is_bool($body[$field])) {
            return $body[$field];
        }
        if ($body[$field] === 1 || $body[$field] === '1' || $body[$field] === 'true') {
            return true;
        }
        if ($body[$field] === 0 || $body[$field] === '0' || $body[$field] === 'false') {
            return false;
        }
        self::validationError($field, $field . ' boolean olmalidir.');

        return $fallback;
    }

    private static function encodeScalarJson($value, string $field)
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        self::validationError($field, $field . ' skaler tip olmalidir.');

        return null;
    }

    private static function decodeScalarJson($raw)
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) || is_int($raw) || is_float($raw) || is_bool($raw)) {
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (is_string($decoded) || is_int($decoded) || is_float($decoded) || is_bool($decoded) || $decoded === null) {
                        return $decoded;
                    }
                }
                return $raw;
            }
            return $raw;
        }

        return null;
    }

    private static function parsePositiveInt($value, string $field, bool $required): int
    {
        if ($value === null || $value === '') {
            if ($required) {
                self::validationError($field, $field . ' zorunludur.');
            }
            return 0;
        }
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $parsed = (int) trim($value);
        } else {
            self::validationError($field, $field . ' pozitif tam sayi olmalidir.');
            return 0;
        }
        if ($parsed < 1) {
            self::validationError($field, $field . ' pozitif tam sayi olmalidir.');
        }

        return $parsed;
    }

    private static function addDays(string $date, int $days): string
    {
        $dt = new \DateTimeImmutable($date);

        return $dt->modify('+' . $days . ' day')->format('Y-m-d');
    }

    private static function nowSql(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function toIsoDatetime(string $value): string
    {
        $normalized = str_replace(' ', 'T', $value);
        if (substr($normalized, -1) !== 'Z' && strpos($normalized, '+') === false) {
            $normalized .= 'Z';
        }

        return $normalized;
    }

    private static function isDuplicateKey(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return strpos($msg, '1062') !== false || stripos($msg, 'Duplicate') !== false;
    }

    private static function validationError(string $field, string $message): void
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    private static function conflict(string $code, string $message): void
    {
        JsonResponse::error(409, $code, $message);
    }
}
