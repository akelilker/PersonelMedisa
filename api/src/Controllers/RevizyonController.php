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
 * S79-E/S79-F: haftalik kapanis revizyon talebi + correction owner.
 * Onay correction uretmez; correction_event_id null kalir ta ki correction-uret.
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

    private const CORRECTION_TIPI_BY_REVIZYON = [
        'PUANTAJ_GIRIS_CIKIS_DUZELTME' => 'GIRIS_CIKIS_DUZELTME',
        'MOLA_DUZELTME' => 'MOLA_DUZELTME',
        'DEVAMSIZLIK_DUZELTME' => 'DEVAMSIZLIK_DUZELTME',
        'SERBEST_ZAMAN_ETKI_DUZELTME' => 'SERBEST_ZAMAN_ETKI_DUZELTME',
        'KAPANIS_HESAP_REVIZYONU' => 'KAPANIS_HESAP_REVIZYONU',
        'BORDRO_ETKI_NOTU' => 'BORDRO_ETKI_NOTU',
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

    private const CORRECTION_SERVER_OWNED_FIELDS = [
        'id',
        'revizyon_talebi_id',
        'personel_id',
        'sube_id',
        'kapanis_id',
        'snapshot_id',
        'hafta_baslangic',
        'hafta_bitis',
        'etkilenen_tarih',
        'kaynak_tipi',
        'kaynak_id',
        'correction_tipi',
        'onceki_deger',
        'yeni_deger',
        'delta_dakika',
        'delta_gun',
        'bordro_etki_var_mi',
        'bordro_etki_tipi',
        'aciklama',
        'olusturan_kullanici_id',
        'olusturma_zamani',
        'iptal_edildi_mi',
        'iptal_zamani',
        'iptal_eden_kullanici_id',
        'audit_ref',
        'snapshot_ref',
        'created_at',
        'updated_at',
        'iptal_aciklamasi',
    ];

    public static function talepleri(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.view');
        self::assertSchemaReady();
        self::rejectClientSubeQuery($request);

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
        self::rejectClientSubeQuery($request);

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
        self::rejectClientSubeQuery($request);

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
        $oncekiDegerJson = self::encodeJsonValue($body['onceki_deger'] ?? null, 'onceki_deger');
        $talepEdilenDegerJson = self::encodeJsonValue($body['talep_edilen_deger'] ?? null, 'talep_edilen_deger');

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
        self::rejectClientSubeQuery($request);

        $talepId = self::parsePositiveInt($id, 'id', true);
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        self::rejectUnknownTransitionBody($body, true);

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

    public static function corrections(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.view');
        self::assertCorrectionSchemaReady();
        self::rejectClientSubeQuery($request);

        $pdo = Connection::get();
        $filters = self::parseCorrectionListFilters($request);
        $rows = self::queryCorrections($pdo, $user, $request, $filters, null);

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::presentCorrection($user, $row);
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function correctionDetail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.view');
        self::assertCorrectionSchemaReady();
        self::rejectClientSubeQuery($request);

        $correctionId = self::parsePositiveInt($id, 'id', true);
        $pdo = Connection::get();
        $row = self::loadCorrectionById($pdo, $correctionId, false);
        if ($row === null) {
            JsonResponse::error(404, 'CORRECTION_NOT_FOUND', 'Revizyon correction bulunamadi.');
        }

        self::assertCanViewCorrection($user, $request, $row);
        JsonResponse::success(self::presentCorrection($user, $row));
    }

    public static function correctionUret(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.approve');
        self::assertCorrectionSchemaReady();
        self::rejectClientSubeQuery($request);

        $talepId = self::parsePositiveInt($id, 'id', true);
        $body = $request->getJsonBody();
        if ($body === null) {
            $body = [];
        }
        if (!is_array($body)) {
            self::correctionPayloadError('body', 'Gecersiz JSON body.');
        }
        self::rejectCorrectionProduceBody($body);

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $talep = self::loadTalepById($pdo, $talepId, true);
            if ($talep === null) {
                $pdo->rollBack();
                JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Revizyon talebi bulunamadi.');
            }

            self::assertCanViewCorrectionFromTalep($user, $request, $talep);

            if ((string) $talep['durum'] !== 'ONAYLANDI') {
                $pdo->rollBack();
                JsonResponse::error(409, 'CORRECTION_NOT_ALLOWED_FOR_STATE', 'Correction yalniz ONAYLANDI talep icin uretilebilir.');
            }

            if ($talep['correction_event_id'] !== null) {
                $pdo->rollBack();
                JsonResponse::error(409, 'CORRECTION_ALREADY_EXISTS', 'Bu revizyon talebi icin correction zaten mevcut.');
            }

            $existing = self::loadCorrectionByTalepId($pdo, $talepId, false);
            if ($existing !== null) {
                $pdo->rollBack();
                JsonResponse::error(409, 'CORRECTION_ALREADY_EXISTS', 'Bu revizyon talebi icin correction zaten mevcut.');
            }

            $personel = self::loadPersonel($pdo, (int) $talep['personel_id']);
            self::assertCorrectionTargetConsistency($pdo, $talep, $personel);

            $correctionTipi = self::mapRevizyonTipiToCorrectionTipi((string) $talep['revizyon_tipi']);
            if ($correctionTipi === null) {
                $pdo->rollBack();
                JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Bu revizyon tipi icin correction uretilemez.');
            }

            $onceki = self::decodeJsonValue($talep['onceki_deger'] ?? null);
            $yeni = self::decodeJsonValue($talep['talep_edilen_deger'] ?? null);
            $oncekiScalar = self::toCorrectionScalar($onceki);
            $yeniScalar = self::toCorrectionScalar($yeni);
            $delta = self::calculateCorrectionDelta($oncekiScalar, $yeniScalar);

            $bordroEtkiVarMi = ((int) ($talep['bordro_etki_var_mi'] ?? 0)) === 1;
            $aciklama = $talep['karar_aciklamasi'] !== null && trim((string) $talep['karar_aciklamasi']) !== ''
                ? (string) $talep['karar_aciklamasi']
                : (string) $talep['gerekce'];
            $now = self::nowSql();
            $tempAudit = 'REV-CORR-' . $talepId . '-TMP-' . bin2hex(random_bytes(8));
            $snapshotId = (int) $talep['snapshot_id'];
            $snapshotRef = 'snapshot:' . $snapshotId;

            $stmt = $pdo->prepare(
                'INSERT INTO haftalik_kapanis_revizyon_corrections
                  (revizyon_talebi_id, personel_id, sube_id, kapanis_id, snapshot_id,
                   hafta_baslangic, hafta_bitis, etkilenen_tarih, kaynak_tipi, kaynak_id,
                   correction_tipi, onceki_deger, yeni_deger, delta_dakika, delta_gun,
                   bordro_etki_var_mi, bordro_etki_tipi, aciklama, olusturan_kullanici_id,
                   olusturma_zamani, iptal_edildi_mi, iptal_zamani, iptal_eden_kullanici_id,
                   iptal_aciklamasi, audit_ref, snapshot_ref)
                 VALUES
                  (:revizyon_talebi_id, :personel_id, :sube_id, :kapanis_id, :snapshot_id,
                   :hafta_baslangic, :hafta_bitis, :etkilenen_tarih, :kaynak_tipi, :kaynak_id,
                   :correction_tipi, :onceki_deger, :yeni_deger, :delta_dakika, :delta_gun,
                   :bordro_etki_var_mi, :bordro_etki_tipi, :aciklama, :olusturan_kullanici_id,
                   :olusturma_zamani, 0, NULL, NULL, NULL, :audit_ref, :snapshot_ref)'
            );
            $stmt->execute([
                'revizyon_talebi_id' => $talepId,
                'personel_id' => (int) $talep['personel_id'],
                'sube_id' => (int) $talep['sube_id'],
                'kapanis_id' => (int) $talep['kapanis_id'],
                'snapshot_id' => $snapshotId,
                'hafta_baslangic' => (string) $talep['hafta_baslangic'],
                'hafta_bitis' => (string) $talep['hafta_bitis'],
                'etkilenen_tarih' => (string) $talep['etkilenen_tarih'],
                'kaynak_tipi' => (string) $talep['kaynak_tipi'],
                'kaynak_id' => (int) $talep['kaynak_id'],
                'correction_tipi' => $correctionTipi,
                'onceki_deger' => self::encodeJsonValue($oncekiScalar, 'onceki_deger'),
                'yeni_deger' => self::encodeJsonValue($yeniScalar, 'yeni_deger'),
                'delta_dakika' => $delta['delta_dakika'],
                'delta_gun' => $delta['delta_gun'],
                'bordro_etki_var_mi' => $bordroEtkiVarMi ? 1 : 0,
                'bordro_etki_tipi' => $bordroEtkiVarMi ? (string) $talep['revizyon_tipi'] : null,
                'aciklama' => $aciklama,
                'olusturan_kullanici_id' => (int) $user['id'],
                'olusturma_zamani' => $now,
                'audit_ref' => $tempAudit,
                'snapshot_ref' => $snapshotRef,
            ]);
            $correctionId = (int) $pdo->lastInsertId();
            $auditRef = 'REV-CORR-' . $talepId . '-' . $correctionId;

            $updAudit = $pdo->prepare(
                'UPDATE haftalik_kapanis_revizyon_corrections
                 SET audit_ref = :audit_ref
                 WHERE id = :id'
            );
            $updAudit->execute(['audit_ref' => $auditRef, 'id' => $correctionId]);

            $updTalep = $pdo->prepare(
                'UPDATE haftalik_kapanis_revizyon_talepleri
                 SET correction_event_id = :correction_id, updated_at = :updated_at
                 WHERE id = :id AND correction_event_id IS NULL'
            );
            $updTalep->execute([
                'correction_id' => $correctionId,
                'updated_at' => $now,
                'id' => $talepId,
            ]);
            if ($updTalep->rowCount() !== 1) {
                $pdo->rollBack();
                JsonResponse::error(409, 'CORRECTION_ALREADY_EXISTS', 'Bu revizyon talebi icin correction zaten mevcut.');
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (self::isDuplicateKey($e)) {
                JsonResponse::error(409, 'CORRECTION_ALREADY_EXISTS', 'Bu revizyon talebi icin correction zaten mevcut.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $row = self::loadCorrectionById($pdo, $correctionId, false);
        JsonResponse::success(self::presentCorrection($user, $row));
    }

    public static function correctionIptal(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'revizyon.approve');
        self::assertCorrectionSchemaReady();
        self::rejectClientSubeQuery($request);

        $correctionId = self::parsePositiveInt($id, 'id', true);
        $body = $request->getJsonBody();
        if ($body === null) {
            $body = [];
        }
        if (!is_array($body)) {
            self::correctionPayloadError('body', 'Gecersiz JSON body.');
        }
        self::rejectCorrectionCancelBody($body);
        $iptalAciklamasi = self::optionalNullableString($body, 'aciklama', 1000);

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $row = self::loadCorrectionById($pdo, $correctionId, true);
            if ($row === null || ((int) ($row['iptal_edildi_mi'] ?? 0)) === 1) {
                $pdo->rollBack();
                JsonResponse::error(404, 'CORRECTION_NOT_FOUND', 'Revizyon correction bulunamadi.');
            }

            self::assertCanViewCorrection($user, $request, $row);

            $now = self::nowSql();
            $stmt = $pdo->prepare(
                'UPDATE haftalik_kapanis_revizyon_corrections
                 SET iptal_edildi_mi = 1,
                     iptal_zamani = :iptal_zamani,
                     iptal_eden_kullanici_id = :iptal_eden,
                     iptal_aciklamasi = :iptal_aciklamasi,
                     updated_at = :updated_at
                 WHERE id = :id AND iptal_edildi_mi = 0'
            );
            $stmt->execute([
                'iptal_zamani' => $now,
                'iptal_eden' => (int) $user['id'],
                'iptal_aciklamasi' => $iptalAciklamasi,
                'updated_at' => $now,
                'id' => $correctionId,
            ]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                JsonResponse::error(404, 'CORRECTION_NOT_FOUND', 'Revizyon correction bulunamadi.');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fresh = self::loadCorrectionById($pdo, $correctionId, false);
        JsonResponse::success(self::presentCorrection($user, $fresh));
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
        self::rejectClientSubeQuery($request);

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
            'onceki_deger' => self::decodeJsonValue($row['onceki_deger'] ?? null),
            'talep_edilen_deger' => self::decodeJsonValue($row['talep_edilen_deger'] ?? null),
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
        $allowedKeys = ['personel_id', 'durum', 'hafta_baslangic', 'hafta_bitis'];
        foreach (array_keys($_GET) as $key) {
            $name = (string) $key;
            if (!in_array($name, $allowedKeys, true)) {
                self::validationError($name, 'Bilinmeyen query alani.');
            }
        }

        $personelId = $request->getQuery('personel_id');
        $durum = $request->getQuery('durum');
        $haftaBaslangic = $request->getQuery('hafta_baslangic');
        $haftaBitis = $request->getQuery('hafta_bitis');

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

    private static function rejectClientSubeQuery(Request $request): void
    {
        $subeId = $request->getQuery('sube_id');
        if ($subeId !== null && $subeId !== '') {
            self::validationError('sube_id', 'sube_id istemci tarafindan belirlenemez.');
        }
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

        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0 && $rol !== 'GENEL_YONETICI') {
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

        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0 && $rol !== 'GENEL_YONETICI') {
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
        string $haftaBitis,
        string $notFoundCode = 'TARGET_NOT_FOUND'
    ): void {
        $tipi = strtoupper(trim($kaynakTipi));
        $allowed = ['PUANTAJ', 'HAFTALIK_KAPANIS_SATIR', 'KAPANIS_SATIR', 'SERBEST_ZAMAN', 'SUREC'];
        if (!in_array($tipi, $allowed, true)) {
            if ($notFoundCode === 'CORRECTION_TARGET_NOT_FOUND') {
                JsonResponse::error(404, $notFoundCode, 'Kaynak tipi gecersiz.');
            }
            self::validationError('kaynak_tipi', 'kaynak_tipi gecersiz.');
        }

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
                JsonResponse::error(404, $notFoundCode, 'Revize edilecek kaynak bulunamadi.');
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
                JsonResponse::error(404, $notFoundCode, 'Revize edilecek kaynak bulunamadi.');
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
                JsonResponse::error(404, $notFoundCode, 'Revize edilecek kaynak bulunamadi.');
            }
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                JsonResponse::error(404, $notFoundCode, 'Revize edilecek kaynak bulunamadi.');
            }
            return;
        }

        if ($tipi === 'SUREC') {
            $stmt = $pdo->prepare(
                'SELECT id FROM surecler WHERE id = :id AND personel_id = :personel_id LIMIT 1'
            );
            $stmt->execute(['id' => $kaynakId, 'personel_id' => $personelId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                JsonResponse::error(404, $notFoundCode, 'Revize edilecek kaynak bulunamadi.');
            }
            return;
        }

        JsonResponse::error(404, $notFoundCode, 'Revize edilecek kaynak bulunamadi.');
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

    private static function assertCorrectionSchemaReady(): void
    {
        self::assertSchemaReady();
        $pdo = Connection::get();
        $stmt = $pdo->query("SHOW TABLES LIKE 'haftalik_kapanis_revizyon_corrections'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_NUM)) {
            JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Revizyon correction semasi hazir degil.');
        }
    }

    /** @return array{revizyon_talebi_id:?int,personel_id:?int,hafta_baslangic:?string,hafta_bitis:?string} */
    private static function parseCorrectionListFilters(Request $request): array
    {
        $allowedKeys = ['revizyon_talebi_id', 'personel_id', 'hafta_baslangic', 'hafta_bitis'];
        foreach (array_keys($_GET) as $key) {
            $name = (string) $key;
            if (!in_array($name, $allowedKeys, true)) {
                self::correctionPayloadError($name, 'Bilinmeyen query alani.');
            }
        }

        $talepId = $request->getQuery('revizyon_talebi_id');
        $personelId = $request->getQuery('personel_id');
        $haftaBaslangic = $request->getQuery('hafta_baslangic');
        $haftaBitis = $request->getQuery('hafta_bitis');

        return [
            'revizyon_talebi_id' => $talepId !== null && $talepId !== ''
                ? self::parsePositiveInt($talepId, 'revizyon_talebi_id', true)
                : null,
            'personel_id' => $personelId !== null && $personelId !== ''
                ? self::parsePositiveInt($personelId, 'personel_id', true)
                : null,
            'hafta_baslangic' => $haftaBaslangic !== null && $haftaBaslangic !== ''
                ? self::normalizeDate((string) $haftaBaslangic, 'hafta_baslangic')
                : null,
            'hafta_bitis' => $haftaBitis !== null && $haftaBitis !== ''
                ? self::normalizeDate((string) $haftaBitis, 'hafta_bitis')
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array{revizyon_talebi_id?:int|null,personel_id?:int|null,hafta_baslangic?:string|null,hafta_bitis?:string|null} $filters
     * @return array<int, array<string, mixed>>
     */
    private static function queryCorrections(PDO $pdo, array $user, Request $request, array $filters, $forceId)
    {
        $where = [];
        $params = [];

        if ($forceId !== null) {
            $where[] = 'c.id = :force_id';
            $params['force_id'] = (int) $forceId;
        }
        if ($filters['revizyon_talebi_id'] !== null) {
            $where[] = 'c.revizyon_talebi_id = :filter_talep_id';
            $params['filter_talep_id'] = (int) $filters['revizyon_talebi_id'];
        }
        if ($filters['personel_id'] !== null) {
            $where[] = 'c.personel_id = :filter_personel_id';
            $params['filter_personel_id'] = (int) $filters['personel_id'];
        }
        if ($filters['hafta_baslangic'] !== null) {
            $where[] = 'c.hafta_baslangic = :filter_hafta_baslangic';
            $params['filter_hafta_baslangic'] = $filters['hafta_baslangic'];
        }
        if ($filters['hafta_bitis'] !== null) {
            $where[] = 'c.hafta_bitis = :filter_hafta_bitis';
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
            $where[] = 'c.sube_id IN (' . implode(', ', $placeholders) . ')';
        }

        $scope = SubeScope::resolveScope($user, $request);
        if ($scope !== null) {
            $where[] = 'c.sube_id = :active_sube_id';
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
            $where[] = 'c.bordro_etki_var_mi = 1';
        } elseif ($rol === 'PATRON') {
            return [];
        }

        $sql = 'SELECT c.*
                FROM haftalik_kapanis_revizyon_corrections c
                INNER JOIN personeller p ON p.id = c.personel_id
                INNER JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = c.revizyon_talebi_id';
        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.olusturma_zamani DESC, c.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    private static function loadCorrectionById(PDO $pdo, int $id, bool $forUpdate)
    {
        $sql = 'SELECT * FROM haftalik_kapanis_revizyon_corrections WHERE id = :id';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function loadCorrectionByTalepId(PDO $pdo, int $talepId, bool $forUpdate)
    {
        $sql = 'SELECT * FROM haftalik_kapanis_revizyon_corrections WHERE revizyon_talebi_id = :talep_id';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['talep_id' => $talepId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentCorrection(array $user, array $row): array
    {
        $payload = [
            'id' => (int) $row['id'],
            'revizyon_talebi_id' => (int) $row['revizyon_talebi_id'],
            'personel_id' => (int) $row['personel_id'],
            'hafta_baslangic' => (string) $row['hafta_baslangic'],
            'hafta_bitis' => (string) $row['hafta_bitis'],
            'etkilenen_tarih' => (string) $row['etkilenen_tarih'],
            'kaynak_tipi' => (string) $row['kaynak_tipi'],
            'kaynak_id' => (int) $row['kaynak_id'],
            'correction_tipi' => (string) $row['correction_tipi'],
            'onceki_deger' => self::decodeJsonValue($row['onceki_deger'] ?? null),
            'yeni_deger' => self::decodeJsonValue($row['yeni_deger'] ?? null),
            'delta_dakika' => (int) $row['delta_dakika'],
            'delta_gun' => (int) $row['delta_gun'],
            'bordro_etki_var_mi' => ((int) ($row['bordro_etki_var_mi'] ?? 0)) === 1,
            'bordro_etki_tipi' => $row['bordro_etki_tipi'] !== null ? (string) $row['bordro_etki_tipi'] : null,
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'olusturan_kullanici_id' => (int) $row['olusturan_kullanici_id'],
            'olusturma_zamani' => self::toIsoDatetime((string) $row['olusturma_zamani']),
            'iptal_edildi_mi' => ((int) ($row['iptal_edildi_mi'] ?? 0)) === 1,
            'iptal_zamani' => $row['iptal_zamani'] !== null
                ? self::toIsoDatetime((string) $row['iptal_zamani'])
                : null,
            'iptal_eden_kullanici_id' => $row['iptal_eden_kullanici_id'] !== null
                ? (int) $row['iptal_eden_kullanici_id']
                : null,
            'audit_ref' => (string) $row['audit_ref'],
            'snapshot_ref' => $row['snapshot_ref'] !== null ? (string) $row['snapshot_ref'] : null,
        ];

        if (!RolePermissions::has($user, 'revizyon.view_finance_effect')) {
            $payload['bordro_etki_tipi'] = null;
            if ($payload['bordro_etki_var_mi'] === true) {
                $payload['aciklama'] = null;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private static function assertCanViewCorrection(array $user, Request $request, array $row): void
    {
        $talep = [
            'id' => (int) $row['revizyon_talebi_id'],
            'personel_id' => (int) $row['personel_id'],
            'sube_id' => (int) $row['sube_id'],
            'bordro_etki_var_mi' => (int) ($row['bordro_etki_var_mi'] ?? 0),
        ];
        self::assertCanViewCorrectionFromTalep($user, $request, $talep);
        self::assertCorrectionDenormalizedConsistency($row);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $talep
     */
    private static function assertCanViewCorrectionFromTalep(array $user, Request $request, array $talep): void
    {
        $rol = (string) ($user['rol'] ?? '');
        if ($rol === 'PATRON') {
            JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
        }

        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0 && $rol !== 'GENEL_YONETICI') {
            JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
        }

        $personelSubeId = (int) $talep['sube_id'];
        if (count($allowed) > 0 && !in_array($personelSubeId, $allowed, true)) {
            JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
        }

        $headerSube = $request->getHeader('x-active-sube-id');
        $activeSube = null;
        if ($headerSube !== null && $headerSube !== '') {
            $activeSube = self::parsePositiveInt($headerSube, 'x-active-sube-id', true);
            if (count($allowed) > 0 && !in_array($activeSube, $allowed, true)) {
                JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
            }
        } elseif (count($allowed) === 1) {
            $activeSube = (int) $allowed[0];
        }
        if ($activeSube !== null && $personelSubeId !== $activeSube) {
            JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
        }

        if ($rol === 'GENEL_YONETICI') {
            return;
        }

        $pdo = Connection::get();
        $personel = self::loadPersonel($pdo, (int) $talep['personel_id']);

        if ($rol === 'BOLUM_YONETICISI') {
            $departmanIds = self::loadUserDepartmanIds($pdo, (int) $user['id']);
            $depId = $personel['departman_id'] !== null ? (int) $personel['departman_id'] : null;
            if ($depId === null || !in_array($depId, $departmanIds, true)) {
                JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
            }
            return;
        }

        if ($rol === 'MUHASEBE') {
            if (((int) ($talep['bordro_etki_var_mi'] ?? 0)) !== 1) {
                JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
            }
            return;
        }

        if ($rol === 'BIRIM_AMIRI') {
            return;
        }

        JsonResponse::error(403, 'CORRECTION_SCOPE_DENIED', 'Revizyon correction kapsam disi.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertCorrectionDenormalizedConsistency(array $row): void
    {
        $pdo = Connection::get();
        $talep = self::loadTalepById($pdo, (int) $row['revizyon_talebi_id'], false);
        if ($talep === null) {
            JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Revizyon talebi bulunamadi.');
        }
        if ((int) $talep['personel_id'] !== (int) $row['personel_id']
            || (int) $talep['sube_id'] !== (int) $row['sube_id']) {
            JsonResponse::error(409, 'CORRECTION_TARGET_NOT_FOUND', 'Correction personel/sube tutarsiz.');
        }
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $personel
     */
    private static function assertCorrectionTargetConsistency(PDO $pdo, array $talep, array $personel): void
    {
        if ((int) $personel['id'] !== (int) $talep['personel_id']) {
            JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Personel bulunamadi.');
        }
        if ((int) $personel['sube_id'] !== (int) $talep['sube_id']) {
            JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Correction personel/sube tutarsiz.');
        }

        $snapshotId = (int) $talep['snapshot_id'];
        $kapanisId = (int) $talep['kapanis_id'];
        $stmt = $pdo->prepare(
            'SELECT id, kapanis_id, personel_id, state
             FROM haftalik_kapanis_satirlari
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $snapshotId]);
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot) {
            JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Snapshot bulunamadi.');
        }
        if ((int) $snapshot['kapanis_id'] !== $kapanisId
            || (int) $snapshot['personel_id'] !== (int) $talep['personel_id']) {
            JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Snapshot talep ile uyusmuyor.');
        }

        $kapanisStmt = $pdo->prepare(
            'SELECT id FROM haftalik_kapanislar WHERE id = :id LIMIT 1'
        );
        $kapanisStmt->execute(['id' => $kapanisId]);
        if (!$kapanisStmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::error(404, 'CORRECTION_TARGET_NOT_FOUND', 'Kapanis bulunamadi.');
        }

        self::assertKaynakExists(
            $pdo,
            (string) $talep['kaynak_tipi'],
            (int) $talep['kaynak_id'],
            (int) $talep['personel_id'],
            (string) $talep['etkilenen_tarih'],
            (string) $talep['hafta_baslangic'],
            (string) $talep['hafta_bitis'],
            'CORRECTION_TARGET_NOT_FOUND'
        );
    }

    private static function mapRevizyonTipiToCorrectionTipi(string $revizyonTipi)
    {
        return self::CORRECTION_TIPI_BY_REVIZYON[$revizyonTipi] ?? null;
    }

    private static function toCorrectionScalar($value)
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            if (is_float($value) && (int) $value == $value) {
                return (int) $value;
            }
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{delta_dakika:int,delta_gun:int} */
    private static function calculateCorrectionDelta($onceki, $yeni): array
    {
        if ((is_int($onceki) || is_float($onceki)) && (is_int($yeni) || is_float($yeni))) {
            return [
                'delta_dakika' => (int) $yeni - (int) $onceki,
                'delta_gun' => 0,
            ];
        }

        return ['delta_dakika' => 0, 'delta_gun' => 0];
    }

    /** @param array<string, mixed> $body */
    private static function rejectCorrectionProduceBody(array $body): void
    {
        foreach (array_keys($body) as $key) {
            self::correctionPayloadError((string) $key, 'Correction uretim body bos olmalidir.');
        }
        foreach (self::CORRECTION_SERVER_OWNED_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                self::correctionPayloadError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }
    }

    /** @param array<string, mixed> $body */
    private static function rejectCorrectionCancelBody(array $body): void
    {
        foreach (array_keys($body) as $key) {
            if ($key === 'aciklama') {
                continue;
            }
            self::correctionPayloadError((string) $key, 'Bilinmeyen correction iptal alani.');
        }
    }

    private static function correctionPayloadError(string $field, string $message): void
    {
        JsonResponse::error(400, 'INVALID_CORRECTION_PAYLOAD', $message, $field);
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

    private static function encodeJsonValue($value, string $field)
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            self::validationError($field, $field . ' JSON seri hale getirilemez.');
        }
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::validationError($field, $field . ' gecerli JSON olmalidir.');
        }

        return null;
    }

    private static function decodeJsonValue($raw)
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw) || is_int($raw) || is_float($raw) || is_bool($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        if ($raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Revizyon JSON degeri bozulmus: ' . $e->getMessage(), 0, $e);
        }
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
