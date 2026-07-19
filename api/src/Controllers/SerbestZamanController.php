<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\PuantajDonemKilidiService;
use PDO;
use PDOException;
use Throwable;

/**
 * Serbest zaman event store owner (S79-D).
 *
 * Permissions (existing RolePermissions — no new keys):
 * - GET → puantaj.view
 * - POST writes → puantaj.muhurle
 *
 * Period lock is NOT a write blocker; donem_* columns are audit metadata only.
 * Active OLUSUM uniqueness: serbest_zaman_aktif_olusumlar guard table.
 */
class SerbestZamanController
{
    private const EVENT_TIPLERI = [
        'SERBEST_ZAMAN_OLUSUM',
        'SERBEST_ZAMAN_KULLANIM',
        'SERBEST_ZAMAN_DUZELTME',
        'SERBEST_ZAMAN_IPTAL',
    ];
    private const HEDEF_TIPLERI = ['SERBEST_ZAMAN_OLUSUM', 'SERBEST_ZAMAN_KULLANIM'];
    private const SERVER_OWNED_FIELDS = [
        'id',
        'personel_id',
        'dakika',
        'event_tarihi',
        'son_kullanim_tarihi',
        'created_by',
        'created_at',
        'sube_id',
        'donem_yil',
        'donem_ay',
        'donem_kilitli_miydi',
        'kaynak_snapshot_id',
        'kaynak_odeme_tercihi_id',
        'event_tipi',
    ];
    private const SERVER_OWNED_WRITE_COMMON = [
        'id',
        'created_by',
        'created_at',
        'sube_id',
        'donem_yil',
        'donem_ay',
        'donem_kilitli_miydi',
        'event_tipi',
        'dakika',
        'yeni_dakika',
        'son_kullanim_tarihi',
        'kaynak_snapshot_id',
        'kaynak_odeme_tercihi_id',
    ];

    public static function listEvents(Request $request): void
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');

        $personelId = self::parsePositiveInt($request->getQuery('personel_id'), 'personel_id', true);
        $pdo = Connection::get();
        self::assertSchemaReady($pdo);

        $personel = self::loadPersonel($pdo, $personelId);
        if ($personel === null) {
            JsonResponse::error(404, 'NOT_FOUND', 'personel bulunamadi.');
        }
        self::assertPersonelScope($user, $request, (int) $personel['sube_id']);

        $stmt = $pdo->prepare(
            'SELECT * FROM serbest_zaman_events
             WHERE personel_id = :pid
             ORDER BY event_tarihi ASC, id ASC'
        );
        $stmt->execute(['pid' => $personelId]);
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = self::mapEvent($row);
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function bakiye(Request $request): void
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');

        $personelId = self::parsePositiveInt($request->getQuery('personel_id'), 'personel_id', true);
        $referans = $request->getQuery('referans_tarih');
        if ($referans !== null && $referans !== '') {
            if (!is_string($referans) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($referans))) {
                JsonResponse::badRequest('referans_tarih YYYY-MM-DD formatinda olmalidir.', 'INVALID_QUERY', 'referans_tarih');
            }
            $referans = trim($referans);
        } else {
            $referans = date('Y-m-d');
        }

        $pdo = Connection::get();
        self::assertSchemaReady($pdo);

        $personel = self::loadPersonel($pdo, $personelId);
        if ($personel === null) {
            JsonResponse::error(404, 'NOT_FOUND', 'personel bulunamadi.');
        }
        self::assertPersonelScope($user, $request, (int) $personel['sube_id']);

        $events = self::loadPersonelEvents($pdo, $personelId);
        JsonResponse::success(self::hesaplaBakiye($personelId, $events, $referans));
    }

    public static function olusum(Request $request): void
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.muhurle');

        $body = $request->getJsonBody();
        foreach (array_merge(self::SERVER_OWNED_FIELDS, ['islem_anahtari', 'hedef_event_id', 'hedef_event_tipi', 'yeni_dakika', 'aciklama']) as $field) {
            if (array_key_exists($field, $body)) {
                self::validationError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }

        $odemeTercihiId = isset($body['odeme_tercihi_id'])
            ? self::parsePositiveInt($body['odeme_tercihi_id'], 'odeme_tercihi_id', false)
            : null;
        $snapshotId = isset($body['snapshot_id'])
            ? self::parsePositiveInt($body['snapshot_id'], 'snapshot_id', false)
            : null;
        if ($odemeTercihiId === null && $snapshotId === null) {
            self::validationError('odeme_tercihi_id', 'odeme_tercihi_id veya snapshot_id zorunludur.');
        }

        $pdo = Connection::get();
        self::assertSchemaReady($pdo);

        $pdo->beginTransaction();
        try {
            $tercih = self::loadTercihForOlusum($pdo, $odemeTercihiId, $snapshotId, true);
            if ($tercih === null) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                JsonResponse::error(409, 'NOT_PERSISTED', 'Odeme tercihi persist edilmemis; olusum eventi uretilemez.');
            }

            $personel = self::loadPersonel($pdo, (int) $tercih['personel_id'], true);
            if ($personel === null) {
                self::rollbackNotFound($pdo, 'personel bulunamadi.');
            }
            self::assertPersonelScope($user, $request, (int) $personel['sube_id']);

            if ((string) $tercih['odeme_tipi'] !== 'SERBEST_ZAMAN') {
                self::rollbackConflict($pdo, 'NOT_ELIGIBLE', 'Odeme tercihi SERBEST_ZAMAN degil; olusum eventi uretilemez.');
            }

            $guard = $pdo->prepare(
                'SELECT olusum_event_id FROM serbest_zaman_aktif_olusumlar
                 WHERE odeme_tercihi_id = :tid FOR UPDATE'
            );
            $guard->execute(['tid' => (int) $tercih['id']]);
            if ($guard->fetch(PDO::FETCH_ASSOC) !== false) {
                self::rollbackConflict($pdo, 'ALREADY_EXISTS', 'Bu odeme tercihi icin aktif serbest zaman olusumu zaten mevcut.');
            }

            $fm = (int) $tercih['fazla_calisma_dakika'];
            $dakika = (int) round($fm * 1.5);
            if ($dakika <= 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                JsonResponse::error(422, 'ZERO_DAKIKA', 'Fazla calisma dakikasi sifir; olusum eventi uretilemez.', 'dakika');
            }

            $eventTarihi = self::extractEventTarihi(
                isset($tercih['secim_zamani']) ? (string) $tercih['secim_zamani'] : null
            );
            $sonKullanim = self::hesaplaSonKullanimTarihi($eventTarihi);
            $donem = self::resolveDonemMeta($pdo, (int) $personel['sube_id'], $eventTarihi);
            $userId = (int) ($user['id'] ?? 0);

            $ins = $pdo->prepare(
                'INSERT INTO serbest_zaman_events
                  (personel_id, event_tipi, dakika, event_tarihi, son_kullanim_tarihi,
                   kaynak_snapshot_id, kaynak_odeme_tercihi_id, aciklama,
                   donem_yil, donem_ay, donem_kilitli_miydi, created_by)
                 VALUES
                  (:personel_id, \'SERBEST_ZAMAN_OLUSUM\', :dakika, :event_tarihi, :son_kullanim,
                   :snapshot_id, :tercih_id, :aciklama,
                   :donem_yil, :donem_ay, :donem_kilitli, :created_by)'
            );
            $ins->execute([
                'personel_id' => (int) $tercih['personel_id'],
                'dakika' => $dakika,
                'event_tarihi' => $eventTarihi,
                'son_kullanim' => $sonKullanim,
                'snapshot_id' => (int) $tercih['snapshot_id'],
                'tercih_id' => (int) $tercih['id'],
                'aciklama' => 'FM snapshot ' . (int) $tercih['snapshot_id'] . ' serbest zaman olusumu',
                'donem_yil' => $donem['yil'],
                'donem_ay' => $donem['ay'],
                'donem_kilitli' => $donem['kilitli'] ? 1 : 0,
                'created_by' => $userId > 0 ? $userId : null,
            ]);
            $eventId = (int) $pdo->lastInsertId();

            $gIns = $pdo->prepare(
                'INSERT INTO serbest_zaman_aktif_olusumlar (odeme_tercihi_id, olusum_event_id)
                 VALUES (:tid, :eid)'
            );
            try {
                $gIns->execute(['tid' => (int) $tercih['id'], 'eid' => $eventId]);
            } catch (PDOException $e) {
                $info = $e->errorInfo ?? [];
                if (isset($info[1]) && (int) $info[1] === 1062) {
                    self::rollbackConflict($pdo, 'ALREADY_EXISTS', 'Bu odeme tercihi icin aktif serbest zaman olusumu zaten mevcut.');
                }
                throw $e;
            }

            $pdo->commit();
            $row = self::loadEventById($pdo, $eventId);
            JsonResponse::success(self::mapEvent($row));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function kullanim(Request $request): void
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.muhurle');

        $body = $request->getJsonBody();
        foreach (self::SERVER_OWNED_WRITE_COMMON as $field) {
            if ($field === 'dakika') {
                continue;
            }
            if (array_key_exists($field, $body)) {
                self::validationError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }
        if (array_key_exists('sube_id', $body)) {
            self::validationError('sube_id', 'sube_id istemci tarafindan belirlenemez.');
        }

        $personelId = self::parsePositiveInt($body['personel_id'] ?? null, 'personel_id', false);
        $dakika = self::parsePositiveInt($body['dakika'] ?? null, 'dakika', false);
        $eventTarihi = self::requireEventTarihi($body['event_tarihi'] ?? null);
        $islemAnahtari = self::requireIslemAnahtari($body['islem_anahtari'] ?? null);
        $aciklama = self::optionalAciklama($body['aciklama'] ?? null);

        $pdo = Connection::get();
        self::assertSchemaReady($pdo);

        $pdo->beginTransaction();
        try {
            $personel = self::loadPersonel($pdo, $personelId, true);
            if ($personel === null) {
                self::rollbackNotFound($pdo, 'personel bulunamadi.');
            }
            self::assertPersonelScope($user, $request, (int) $personel['sube_id']);

            $existing = self::loadByIslemAnahtari($pdo, $personelId, $islemAnahtari, true);
            if ($existing !== null) {
                $canonical = [
                    'event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
                    'dakika' => $dakika,
                    'event_tarihi' => $eventTarihi,
                    'aciklama' => $aciklama,
                ];
                if (!self::idempotentMatchKullanim($existing, $canonical)) {
                    self::rollbackConflict($pdo, 'IDEMPOTENCY_CONFLICT', 'Ayni islem_anahtari farkli payload ile kullanilmis.');
                }
                $pdo->commit();
                JsonResponse::success(self::mapEvent($existing));
            }

            $events = self::loadPersonelEvents($pdo, $personelId);
            $bakiye = self::hesaplaBakiye($personelId, $events, $eventTarihi);
            if ($bakiye['kalan_dakika'] <= 0) {
                self::rollbackConflict($pdo, 'NO_ELIGIBLE_BALANCE', 'Kullanilabilir serbest zaman bakiyesi yok.');
            }
            if ($dakika > $bakiye['kalan_dakika']) {
                self::rollbackConflict($pdo, 'INSUFFICIENT_BALANCE', 'Kullanim miktari mevcut bakiyeyi asiyor.');
            }

            $donem = self::resolveDonemMeta($pdo, (int) $personel['sube_id'], $eventTarihi);
            $userId = (int) ($user['id'] ?? 0);
            $ins = $pdo->prepare(
                'INSERT INTO serbest_zaman_events
                  (personel_id, event_tipi, dakika, event_tarihi, islem_anahtari, aciklama,
                   donem_yil, donem_ay, donem_kilitli_miydi, created_by)
                 VALUES
                  (:personel_id, \'SERBEST_ZAMAN_KULLANIM\', :dakika, :event_tarihi, :islem,
                   :aciklama, :donem_yil, :donem_ay, :donem_kilitli, :created_by)'
            );
            try {
                $ins->execute([
                    'personel_id' => $personelId,
                    'dakika' => $dakika,
                    'event_tarihi' => $eventTarihi,
                    'islem' => $islemAnahtari,
                    'aciklama' => $aciklama,
                    'donem_yil' => $donem['yil'],
                    'donem_ay' => $donem['ay'],
                    'donem_kilitli' => $donem['kilitli'] ? 1 : 0,
                    'created_by' => $userId > 0 ? $userId : null,
                ]);
            } catch (PDOException $e) {
                $info = $e->errorInfo ?? [];
                if (isset($info[1]) && (int) $info[1] === 1062) {
                    self::rollbackConflict($pdo, 'IDEMPOTENCY_CONFLICT', 'Ayni islem_anahtari farkli payload ile kullanilmis.');
                }
                throw $e;
            }

            $eventId = (int) $pdo->lastInsertId();
            $pdo->commit();
            JsonResponse::success(self::mapEvent(self::loadEventById($pdo, $eventId)));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function iptal(Request $request): void
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.muhurle');

        $body = $request->getJsonBody();
        foreach (['sube_id', 'created_by', 'created_at', 'donem_yil', 'donem_ay', 'donem_kilitli_miydi', 'event_tipi', 'dakika', 'yeni_dakika'] as $field) {
            if (array_key_exists($field, $body)) {
                self::validationError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }

        $personelId = self::parsePositiveInt($body['personel_id'] ?? null, 'personel_id', false);
        $hedefEventId = self::parsePositiveInt($body['hedef_event_id'] ?? null, 'hedef_event_id', false);
        $hedefTipi = $body['hedef_event_tipi'] ?? null;
        if (!is_string($hedefTipi) || !in_array($hedefTipi, self::HEDEF_TIPLERI, true)) {
            self::validationError('hedef_event_tipi', 'hedef_event_tipi OLUSUM veya KULLANIM olmalidir.');
        }
        $eventTarihi = self::requireEventTarihi($body['event_tarihi'] ?? null);
        $islemAnahtari = self::requireIslemAnahtari($body['islem_anahtari'] ?? null);
        $aciklama = self::optionalAciklama($body['aciklama'] ?? null);

        $pdo = Connection::get();
        self::assertSchemaReady($pdo);

        $pdo->beginTransaction();
        try {
            $personel = self::loadPersonel($pdo, $personelId, true);
            if ($personel === null) {
                self::rollbackNotFound($pdo, 'personel bulunamadi.');
            }
            self::assertPersonelScope($user, $request, (int) $personel['sube_id']);

            $existing = self::loadByIslemAnahtari($pdo, $personelId, $islemAnahtari, true);
            if ($existing !== null) {
                if (
                    (string) $existing['event_tipi'] !== 'SERBEST_ZAMAN_IPTAL'
                    || (int) $existing['hedef_event_id'] !== $hedefEventId
                    || (string) $existing['hedef_event_tipi'] !== $hedefTipi
                    || (string) $existing['event_tarihi'] !== $eventTarihi
                ) {
                    self::rollbackConflict($pdo, 'IDEMPOTENCY_CONFLICT', 'Ayni islem_anahtari farkli payload ile kullanilmis.');
                }
                $pdo->commit();
                JsonResponse::success(self::mapEvent($existing));
            }

            $hedef = self::loadEventById($pdo, $hedefEventId, true);
            if ($hedef === null) {
                self::rollbackNotFound($pdo, 'Hedef event bulunamadi.');
            }
            if ((string) $hedef['event_tipi'] !== $hedefTipi) {
                self::rollbackConflict($pdo, 'UNSUPPORTED_TARGET_EVENT', 'Hedef event tipi desteklenmiyor.');
            }
            if ((int) $hedef['personel_id'] !== $personelId) {
                self::rollbackConflict($pdo, 'TARGET_PERSONEL_MISMATCH', 'Hedef event baska personele ait.');
            }

            $iptalCheck = $pdo->prepare(
                'SELECT id FROM serbest_zaman_events
                 WHERE event_tipi = \'SERBEST_ZAMAN_IPTAL\' AND hedef_event_id = :hid
                 LIMIT 1 FOR UPDATE'
            );
            $iptalCheck->execute(['hid' => $hedefEventId]);
            if ($iptalCheck->fetch(PDO::FETCH_ASSOC) !== false) {
                self::rollbackConflict($pdo, 'ALREADY_CANCELLED', 'Hedef event zaten iptal edilmis.');
            }

            $donem = self::resolveDonemMeta($pdo, (int) $personel['sube_id'], $eventTarihi);
            $userId = (int) ($user['id'] ?? 0);
            $ins = $pdo->prepare(
                'INSERT INTO serbest_zaman_events
                  (personel_id, event_tipi, event_tarihi, hedef_event_id, hedef_event_tipi,
                   islem_anahtari, aciklama, donem_yil, donem_ay, donem_kilitli_miydi, created_by)
                 VALUES
                  (:personel_id, \'SERBEST_ZAMAN_IPTAL\', :event_tarihi, :hedef_id, :hedef_tipi,
                   :islem, :aciklama, :donem_yil, :donem_ay, :donem_kilitli, :created_by)'
            );
            try {
                $ins->execute([
                    'personel_id' => $personelId,
                    'event_tarihi' => $eventTarihi,
                    'hedef_id' => $hedefEventId,
                    'hedef_tipi' => $hedefTipi,
                    'islem' => $islemAnahtari,
                    'aciklama' => $aciklama,
                    'donem_yil' => $donem['yil'],
                    'donem_ay' => $donem['ay'],
                    'donem_kilitli' => $donem['kilitli'] ? 1 : 0,
                    'created_by' => $userId > 0 ? $userId : null,
                ]);
            } catch (PDOException $e) {
                $info = $e->errorInfo ?? [];
                if (isset($info[1]) && (int) $info[1] === 1062) {
                    self::rollbackConflict($pdo, 'ALREADY_CANCELLED', 'Hedef event zaten iptal edilmis.');
                }
                throw $e;
            }

            // Capture before any DELETE — MariaDB/PDO lastInsertId resets to 0 after DELETE.
            $eventId = (int) $pdo->lastInsertId();

            if ($hedefTipi === 'SERBEST_ZAMAN_OLUSUM') {
                $del = $pdo->prepare(
                    'DELETE FROM serbest_zaman_aktif_olusumlar WHERE olusum_event_id = :eid'
                );
                $del->execute(['eid' => $hedefEventId]);
            }

            $pdo->commit();
            JsonResponse::success(self::mapEvent(self::loadEventById($pdo, $eventId)));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function duzeltme(Request $request): void
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.muhurle');

        $body = $request->getJsonBody();
        foreach (['sube_id', 'created_by', 'created_at', 'donem_yil', 'donem_ay', 'donem_kilitli_miydi', 'event_tipi', 'dakika'] as $field) {
            if (array_key_exists($field, $body)) {
                self::validationError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }

        $personelId = self::parsePositiveInt($body['personel_id'] ?? null, 'personel_id', false);
        $hedefEventId = self::parsePositiveInt($body['hedef_event_id'] ?? null, 'hedef_event_id', false);
        $hedefTipi = $body['hedef_event_tipi'] ?? null;
        if (!is_string($hedefTipi) || !in_array($hedefTipi, self::HEDEF_TIPLERI, true)) {
            self::validationError('hedef_event_tipi', 'hedef_event_tipi OLUSUM veya KULLANIM olmalidir.');
        }
        $yeniDakika = self::parsePositiveInt($body['yeni_dakika'] ?? null, 'yeni_dakika', false);
        $eventTarihi = self::requireEventTarihi($body['event_tarihi'] ?? null);
        $islemAnahtari = self::requireIslemAnahtari($body['islem_anahtari'] ?? null);
        $aciklama = self::requireAciklama($body['aciklama'] ?? null);

        $pdo = Connection::get();
        self::assertSchemaReady($pdo);

        $pdo->beginTransaction();
        try {
            $personel = self::loadPersonel($pdo, $personelId, true);
            if ($personel === null) {
                self::rollbackNotFound($pdo, 'personel bulunamadi.');
            }
            self::assertPersonelScope($user, $request, (int) $personel['sube_id']);

            $existing = self::loadByIslemAnahtari($pdo, $personelId, $islemAnahtari, true);
            if ($existing !== null) {
                if (
                    (string) $existing['event_tipi'] !== 'SERBEST_ZAMAN_DUZELTME'
                    || (int) $existing['hedef_event_id'] !== $hedefEventId
                    || (string) $existing['hedef_event_tipi'] !== $hedefTipi
                    || (int) $existing['yeni_dakika'] !== $yeniDakika
                    || (string) $existing['event_tarihi'] !== $eventTarihi
                    || trim((string) ($existing['aciklama'] ?? '')) !== $aciklama
                ) {
                    self::rollbackConflict($pdo, 'IDEMPOTENCY_CONFLICT', 'Ayni islem_anahtari farkli payload ile kullanilmis.');
                }
                $pdo->commit();
                JsonResponse::success(self::mapEvent($existing));
            }

            $hedef = self::loadEventById($pdo, $hedefEventId, true);
            if ($hedef === null) {
                self::rollbackNotFound($pdo, 'Hedef event bulunamadi.');
            }
            if ((string) $hedef['event_tipi'] !== $hedefTipi) {
                self::rollbackConflict($pdo, 'UNSUPPORTED_TARGET_EVENT', 'Hedef event tipi desteklenmiyor.');
            }
            if ((int) $hedef['personel_id'] !== $personelId) {
                self::rollbackConflict($pdo, 'TARGET_PERSONEL_MISMATCH', 'Hedef event baska personele ait.');
            }

            $iptalCheck = $pdo->prepare(
                'SELECT id FROM serbest_zaman_events
                 WHERE event_tipi = \'SERBEST_ZAMAN_IPTAL\' AND hedef_event_id = :hid
                 LIMIT 1'
            );
            $iptalCheck->execute(['hid' => $hedefEventId]);
            if ($iptalCheck->fetch(PDO::FETCH_ASSOC) !== false) {
                self::rollbackConflict($pdo, 'TARGET_ALREADY_CANCELLED', 'Iptal edilmis hedef duzeltilemez.');
            }

            $events = self::loadPersonelEvents($pdo, $personelId);
            $simulated = [
                'id' => PHP_INT_MAX,
                'personel_id' => $personelId,
                'event_tipi' => 'SERBEST_ZAMAN_DUZELTME',
                'hedef_event_id' => $hedefEventId,
                'hedef_event_tipi' => $hedefTipi,
                'yeni_dakika' => $yeniDakika,
                'event_tarihi' => $eventTarihi,
            ];
            $bakiye = self::hesaplaBakiye($personelId, array_merge($events, [$simulated]), $eventTarihi);
            if ($hedefTipi === 'SERBEST_ZAMAN_KULLANIM') {
                $kullanilabilir = $bakiye['toplam_hak_dakika'] - $bakiye['suresi_dolan_dakika'];
                if ($bakiye['kullanilan_dakika'] > $kullanilabilir) {
                    self::rollbackConflict($pdo, 'INSUFFICIENT_BALANCE', 'Kullanim duzeltmesi bakiyeyi asiyor.');
                }
            }

            $donem = self::resolveDonemMeta($pdo, (int) $personel['sube_id'], $eventTarihi);
            $userId = (int) ($user['id'] ?? 0);
            $ins = $pdo->prepare(
                'INSERT INTO serbest_zaman_events
                  (personel_id, event_tipi, yeni_dakika, event_tarihi, hedef_event_id, hedef_event_tipi,
                   islem_anahtari, aciklama, donem_yil, donem_ay, donem_kilitli_miydi, created_by)
                 VALUES
                  (:personel_id, \'SERBEST_ZAMAN_DUZELTME\', :yeni_dakika, :event_tarihi, :hedef_id, :hedef_tipi,
                   :islem, :aciklama, :donem_yil, :donem_ay, :donem_kilitli, :created_by)'
            );
            try {
                $ins->execute([
                    'personel_id' => $personelId,
                    'yeni_dakika' => $yeniDakika,
                    'event_tarihi' => $eventTarihi,
                    'hedef_id' => $hedefEventId,
                    'hedef_tipi' => $hedefTipi,
                    'islem' => $islemAnahtari,
                    'aciklama' => $aciklama,
                    'donem_yil' => $donem['yil'],
                    'donem_ay' => $donem['ay'],
                    'donem_kilitli' => $donem['kilitli'] ? 1 : 0,
                    'created_by' => $userId > 0 ? $userId : null,
                ]);
            } catch (PDOException $e) {
                $info = $e->errorInfo ?? [];
                if (isset($info[1]) && (int) $info[1] === 1062) {
                    self::rollbackConflict($pdo, 'IDEMPOTENCY_CONFLICT', 'Ayni islem_anahtari farkli payload ile kullanilmis.');
                }
                throw $e;
            }

            $eventId = (int) $pdo->lastInsertId();
            $pdo->commit();
            JsonResponse::success(self::mapEvent(self::loadEventById($pdo, $eventId)));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<array<string, mixed>> $events @return array<string, mixed> */
    private static function hesaplaBakiye(int $personelId, array $events, string $referans): array
    {
        $iptalHedefIds = [];
        foreach ($events as $event) {
            if (($event['event_tipi'] ?? '') === 'SERBEST_ZAMAN_IPTAL' && (int) ($event['personel_id'] ?? 0) === $personelId) {
                $iptalHedefIds[(int) $event['hedef_event_id']] = true;
            }
        }

        $overrides = [];
        $duzeltmeler = [];
        foreach ($events as $event) {
            if (($event['event_tipi'] ?? '') === 'SERBEST_ZAMAN_DUZELTME' && (int) ($event['personel_id'] ?? 0) === $personelId) {
                $duzeltmeler[] = $event;
            }
        }
        usort($duzeltmeler, static function ($a, $b) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        foreach ($duzeltmeler as $event) {
            $hid = (int) $event['hedef_event_id'];
            if (!isset($iptalHedefIds[$hid])) {
                $overrides[$hid] = (int) $event['yeni_dakika'];
            }
        }

        $toplamHak = 0;
        $suresiDolan = 0;
        $aktifOlusum = 0;
        foreach ($events as $event) {
            if (($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_OLUSUM' || (int) ($event['personel_id'] ?? 0) !== $personelId) {
                continue;
            }
            $eid = isset($event['id']) ? (int) $event['id'] : null;
            if ($eid !== null && isset($iptalHedefIds[$eid])) {
                continue;
            }
            $dakika = ($eid !== null && isset($overrides[$eid])) ? $overrides[$eid] : (int) ($event['dakika'] ?? 0);
            $aktifOlusum += 1;
            $toplamHak += $dakika;
            $son = (string) ($event['son_kullanim_tarihi'] ?? '');
            if ($son !== '' && $referans > $son) {
                $suresiDolan += $dakika;
            }
        }

        $kullanilan = 0;
        foreach ($events as $event) {
            if (($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_KULLANIM' || (int) ($event['personel_id'] ?? 0) !== $personelId) {
                continue;
            }
            $eid = isset($event['id']) ? (int) $event['id'] : null;
            if ($eid !== null && isset($iptalHedefIds[$eid])) {
                continue;
            }
            $dakika = ($eid !== null && isset($overrides[$eid])) ? $overrides[$eid] : (int) ($event['dakika'] ?? 0);
            $kullanilan += $dakika;
        }

        $kalan = max($toplamHak - $suresiDolan - $kullanilan, 0);

        return [
            'personel_id' => $personelId,
            'toplam_hak_dakika' => $toplamHak,
            'kullanilan_dakika' => $kullanilan,
            'kalan_dakika' => $kalan,
            'suresi_dolan_dakika' => $suresiDolan,
            'event_sayisi' => $aktifOlusum,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function loadPersonelEvents(PDO $pdo, int $personelId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM serbest_zaman_events WHERE personel_id = :pid ORDER BY id ASC'
        );
        $stmt->execute(['pid' => $personelId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    private static function loadEventById(PDO $pdo, int $id, bool $forUpdate = false)
    {
        $sql = 'SELECT * FROM serbest_zaman_events WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private static function loadByIslemAnahtari(PDO $pdo, int $personelId, string $anahtar, bool $forUpdate = false)
    {
        $sql = 'SELECT * FROM serbest_zaman_events
                WHERE personel_id = :pid AND islem_anahtari = :anahtar LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['pid' => $personelId, 'anahtar' => $anahtar]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $canonical */
    private static function idempotentMatchKullanim(array $existing, array $canonical): bool
    {
        if ((string) ($existing['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_KULLANIM') {
            return false;
        }
        if ((int) ($existing['dakika'] ?? 0) !== (int) $canonical['dakika']) {
            return false;
        }
        if ((string) ($existing['event_tarihi'] ?? '') !== (string) $canonical['event_tarihi']) {
            return false;
        }
        $a = isset($existing['aciklama']) && $existing['aciklama'] !== null ? trim((string) $existing['aciklama']) : '';
        $b = $canonical['aciklama'] !== null ? trim((string) $canonical['aciklama']) : '';

        return $a === $b;
    }

    /** @return array<string, mixed>|null */
    private static function loadTercihForOlusum(PDO $pdo, ?int $odemeTercihiId, ?int $snapshotId, bool $forUpdate)
    {
        if ($odemeTercihiId !== null) {
            $sql = 'SELECT * FROM fazla_calisma_odeme_tercihleri WHERE id = :id LIMIT 1';
            if ($forUpdate) {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $odemeTercihiId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        }

        $sql = 'SELECT * FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = :sid LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sid' => $snapshotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private static function loadPersonel(PDO $pdo, int $personelId, bool $forUpdate = false)
    {
        $sql = 'SELECT id, sube_id FROM personeller WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $user */
    private static function assertPersonelScope(array $user, Request $request, int $subeId): void
    {
        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0 && !RolePermissions::has($user, 'personeller.view')) {
            JsonResponse::forbidden('Sube baglami olmadan serbest zaman erisilemez.');
        }
        SubeScope::assertPersonelAccess($user, $request, $subeId);
    }

    /** @return array{yil: int|null, ay: int|null, kilitli: bool} */
    private static function resolveDonemMeta(PDO $pdo, int $subeId, string $eventTarihi): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $eventTarihi, $m)) {
            return ['yil' => null, 'ay' => null, 'kilitli' => false];
        }
        $yil = (int) $m[1];
        $ay = (int) $m[2];
        $kilitli = false;
        try {
            if (self::tableExists($pdo, 'puantaj_aylik_muhurleri')) {
                $kilitli = PuantajDonemKilidiService::isSealed($pdo, [
                    'sube_id' => $subeId,
                    'yil' => $yil,
                    'ay' => $ay,
                ]);
            }
        } catch (Throwable $e) {
            $kilitli = false;
        }

        return ['yil' => $yil, 'ay' => $ay, 'kilitli' => $kilitli];
    }

    private static function extractEventTarihi(?string $secimZamani): string
    {
        if ($secimZamani !== null && $secimZamani !== '') {
            $part = substr(trim($secimZamani), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $part) === 1) {
                return $part;
            }
        }

        return date('Y-m-d');
    }

    private static function hesaplaSonKullanimTarihi(string $eventTarihi): string
    {
        try {
            $dt = new \DateTimeImmutable($eventTarihi);
            $target = $dt->modify('+6 months');
            $day = (int) $dt->format('d');
            $lastDay = (int) $target->format('t');
            $normalizedDay = min($day, $lastDay);

            return $target->format('Y-m-') . str_pad((string) $normalizedDay, 2, '0', STR_PAD_LEFT);
        } catch (Throwable $e) {
            return $eventTarihi;
        }
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed> */
    private static function mapEvent($row): array
    {
        if (!is_array($row)) {
            JsonResponse::error(500, 'INTERNAL_ERROR', 'Serbest zaman event okunamadi.');
        }
        $tip = (string) $row['event_tipi'];
        $base = [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'event_tipi' => $tip,
            'event_tarihi' => (string) $row['event_tarihi'],
            'donem_yil' => isset($row['donem_yil']) && $row['donem_yil'] !== null ? (int) $row['donem_yil'] : null,
            'donem_ay' => isset($row['donem_ay']) && $row['donem_ay'] !== null ? (int) $row['donem_ay'] : null,
            'donem_kilitli_miydi' => ((int) ($row['donem_kilitli_miydi'] ?? 0)) === 1,
        ];
        if (isset($row['aciklama']) && $row['aciklama'] !== null && $row['aciklama'] !== '') {
            $base['aciklama'] = (string) $row['aciklama'];
        }
        if ($tip === 'SERBEST_ZAMAN_OLUSUM') {
            $base['dakika'] = (int) $row['dakika'];
            $base['son_kullanim_tarihi'] = (string) $row['son_kullanim_tarihi'];
            $base['kaynak_snapshot_id'] = (int) $row['kaynak_snapshot_id'];
            $base['kaynak_odeme_tercihi_id'] = (int) $row['kaynak_odeme_tercihi_id'];
        } elseif ($tip === 'SERBEST_ZAMAN_KULLANIM') {
            $base['dakika'] = (int) $row['dakika'];
            $base['islem_anahtari'] = (string) $row['islem_anahtari'];
        } elseif ($tip === 'SERBEST_ZAMAN_IPTAL') {
            $base['hedef_event_id'] = (int) $row['hedef_event_id'];
            $base['hedef_event_tipi'] = (string) $row['hedef_event_tipi'];
            $base['islem_anahtari'] = (string) $row['islem_anahtari'];
        } elseif ($tip === 'SERBEST_ZAMAN_DUZELTME') {
            $base['hedef_event_id'] = (int) $row['hedef_event_id'];
            $base['hedef_event_tipi'] = (string) $row['hedef_event_tipi'];
            $base['yeni_dakika'] = (int) $row['yeni_dakika'];
            $base['islem_anahtari'] = (string) $row['islem_anahtari'];
            $base['aciklama'] = (string) ($row['aciklama'] ?? '');
        }

        return $base;
    }

    private static function assertSchemaReady(PDO $pdo): void
    {
        foreach (['serbest_zaman_events', 'serbest_zaman_aktif_olusumlar', 'fazla_calisma_odeme_tercihleri'] as $table) {
            if (!self::tableExists($pdo, $table)) {
                JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Serbest zaman semasi hazir degil.');
            }
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private static function requireEventTarihi($value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            self::validationError('event_tarihi', 'event_tarihi YYYY-MM-DD formatinda olmalidir.');
        }

        return trim($value);
    }

    private static function requireIslemAnahtari($value): string
    {
        if (!is_string($value)) {
            self::validationError('islem_anahtari', 'islem_anahtari zorunludur.');
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 64) {
            self::validationError('islem_anahtari', 'islem_anahtari zorunludur ve en fazla 64 karakter olabilir.');
        }

        return $trimmed;
    }

    private static function requireAciklama($value): string
    {
        if (!is_string($value)) {
            self::validationError('aciklama', 'aciklama zorunludur.');
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 500) {
            self::validationError('aciklama', 'aciklama zorunludur ve en fazla 500 karakter olabilir.');
        }

        return $trimmed;
    }

    private static function optionalAciklama($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            self::validationError('aciklama', 'aciklama metin olmalidir.');
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > 500) {
            self::validationError('aciklama', 'aciklama en fazla 500 karakter olabilir.');
        }

        return $trimmed;
    }

    private static function parsePositiveInt($value, string $field, bool $fromQuery): int
    {
        if ($value === null || $value === '') {
            if ($fromQuery) {
                JsonResponse::badRequest($field . ' zorunludur ve pozitif tam sayi olmalidir.', 'INVALID_QUERY', $field);
            }
            self::validationError($field, $field . ' zorunludur ve pozitif tam sayi olmalidir.');
        }
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $parsed = (int) trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $parsed = (int) $value;
        } else {
            if ($fromQuery) {
                JsonResponse::badRequest($field . ' zorunludur ve pozitif tam sayi olmalidir.', 'INVALID_QUERY', $field);
            }
            self::validationError($field, $field . ' zorunludur ve pozitif tam sayi olmalidir.');
        }
        if ($parsed < 1) {
            if ($fromQuery) {
                JsonResponse::badRequest($field . ' zorunludur ve pozitif tam sayi olmalidir.', 'INVALID_QUERY', $field);
            }
            self::validationError($field, $field . ' zorunludur ve pozitif tam sayi olmalidir.');
        }

        return $parsed;
    }

    private static function validationError(string $field, string $message): void
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    private static function rollbackConflict(PDO $pdo, string $code, string $message): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(409, $code, $message);
    }

    private static function rollbackNotFound(PDO $pdo, string $message): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(404, 'NOT_FOUND', $message);
    }
}
