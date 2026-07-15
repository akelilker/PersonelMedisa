<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

/**
 * Canonical apply engine for onayli bildirim → gunluk_puantaj.
 * Controller owns auth/permission/tx boundaries; this service owns mapping + mutation.
 */
class BildirimPuantajEtkiApplyService
{
    public const SNAPSHOT_SCHEMA_VERSION = 'S74_APPLY_V1';
    public const KAYNAK = 'BILDIRIM_ETKI_ADAYI';

    /** @var array<int, string> */
    private static $applyableEtkiTurleri = [
        'DEVAMSIZLIK_GUN',
        'GEC_KALMA_DAKIKA',
        'ERKEN_CIKIS_DAKIKA',
        'IZIN_GUNU',
        'RAPOR_GUNU',
        'GOREVDE_CALISILMIS_GUN',
    ];

    /**
     * @param array<string, mixed> $aday Locked FOR UPDATE row
     * @return array{
     *   status: string,
     *   code?: string,
     *   message?: string,
     *   aday?: array<string, mixed>,
     *   idempotent?: bool
     * }
     */
    public static function apply(PDO $pdo, array $aday, $expectedState, $kararVerenUserId)
    {
        $adayId = (int) ($aday['id'] ?? 0);
        $currentState = BildirimPuantajEtkiDecisionPolicy::normalizeState((string) ($aday['state'] ?? ''));

        if ($currentState === 'UYGULANDI') {
            $uygulamaModu = strtoupper(trim((string) ($aday['uygulama_modu'] ?? 'OTOMATIK')));
            if ($uygulamaModu === BildirimPuantajEtkiManualApplyService::UYGULAMA_MODU) {
                return self::conflict('STATE_CONFLICT', 'Manuel uygulanmis puantaj etki adayi otomatik uygulanamaz.');
            }

            return self::evaluateIdempotent($pdo, $aday);
        }

        if ($currentState === 'YOK_SAYILDI') {
            return self::conflict('STATE_CONFLICT', 'Yok sayilmis puantaj etki adayi uygulanamaz.');
        }

        if ($currentState === 'INCELEME_GEREKLI') {
            return self::conflict('STATE_CONFLICT', 'Inceleme gerekli puantaj etki adayi uygulanamaz.');
        }

        if (!BildirimPuantajEtkiDecisionPolicy::isApplyAllowed($currentState)) {
            return self::conflict('STATE_CONFLICT', 'Puantaj etki adayi uygulanamaz.');
        }

        if (!BildirimPuantajEtkiDecisionPolicy::validateExpectedState($currentState, $expectedState)['valid']) {
            return [
                'status' => 'stale',
                'code' => 'STATE_STALE',
                'message' => 'Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin.',
            ];
        }

        $subeId = (int) ($aday['sube_id'] ?? 0);
        $tarih = (string) ($aday['tarih'] ?? '');
        $personelId = (int) ($aday['personel_id'] ?? 0);
        if ($subeId < 1 || $personelId < 1 || $tarih === '') {
            return self::validation('APPLY_INVALID_ADAY', 'Puantaj etki adayi uygulanamaz durumda.');
        }

        if (self::findMonthlySeal($pdo, $subeId, $tarih)) {
            return self::conflict('PERIOD_LOCKED', 'Bu donem muhurlenmis, puantaj kaydi olusturulamaz.');
        }

        $mapping = self::buildPuantajValuesFromAday($aday);
        if (($mapping['ok'] ?? false) !== true) {
            return self::conflict(
                (string) ($mapping['code'] ?? 'APPLY_UNSUPPORTED'),
                (string) ($mapping['message'] ?? 'Puantaj etki adayi otomatik uygulanamaz.')
            );
        }

        /** @var array<string, mixed> $values */
        $values = $mapping['values'];

        $existing = self::findPuantajRow($pdo, $personelId, $tarih);
        if ($existing) {
            return self::conflict('PUANTAJ_OLUSTU', 'Bu personel ve tarih icin gunluk puantaj kaydi zaten var.');
        }

        $oncekiSnapshot = self::buildApplySnapshot($adayId, null);
        $kararZamani = gmdate('Y-m-d H:i:s');

        try {
            self::insertPuantajRow($pdo, $values);
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return self::conflict('PUANTAJ_OLUSTU', 'Bu personel ve tarih icin gunluk puantaj kaydi zaten var.');
            }
            throw $e;
        }

        $puantajId = (int) $pdo->lastInsertId();
        if ($puantajId < 1) {
            return self::validation('APPLY_INSERT_FAILED', 'Gunluk puantaj kaydi olusturulamadi.');
        }

        $inserted = self::findPuantajById($pdo, $puantajId);
        if (!$inserted) {
            return self::validation('APPLY_INSERT_FAILED', 'Gunluk puantaj kaydi olusturulamadi.');
        }

        $sonrakiSnapshot = self::buildApplySnapshot($adayId, $inserted);
        $hash = self::computeUygulamaHash($aday, $sonrakiSnapshot);

        $update = $pdo->prepare('
            UPDATE onayli_bildirim_puantaj_etki_adaylari
            SET state = :state,
                uygulama_modu = :uygulama_modu,
                manuel_karar_turu = :manuel_karar_turu,
                manuel_karar_miktari = :manuel_karar_miktari,
                karar_veren_user_id = :karar_veren_user_id,
                karar_zamani = :karar_zamani,
                uygulanan_puantaj_id = :uygulanan_puantaj_id,
                onceki_puantaj_snapshot = :onceki_puantaj_snapshot,
                sonraki_puantaj_snapshot = :sonraki_puantaj_snapshot,
                uygulama_hash = :uygulama_hash
            WHERE id = :id
        ');
        $update->execute([
            'state' => BildirimPuantajEtkiDecisionPolicy::targetStateForAction(
                BildirimPuantajEtkiDecisionPolicy::ACTION_APPLY
            ),
            'uygulama_modu' => 'OTOMATIK',
            'manuel_karar_turu' => null,
            'manuel_karar_miktari' => null,
            'karar_veren_user_id' => (int) $kararVerenUserId,
            'karar_zamani' => $kararZamani,
            'uygulanan_puantaj_id' => $puantajId,
            'onceki_puantaj_snapshot' => BildirimPuantajEtkiProjectionService::canonicalJson($oncekiSnapshot),
            'sonraki_puantaj_snapshot' => BildirimPuantajEtkiProjectionService::canonicalJson($sonrakiSnapshot),
            'uygulama_hash' => $hash,
            'id' => $adayId,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $adayId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$updated) {
            return self::validation('APPLY_UPDATE_FAILED', 'Puantaj etki adayi guncellenemedi.');
        }

        return [
            'status' => 'success',
            'aday' => $updated,
            'idempotent' => false,
        ];
    }

    /**
     * @param array<string, mixed> $aday
     * @return array{ok: bool, values?: array<string, mixed>, code?: string, message?: string}
     */
    public static function buildPuantajValuesFromAday(array $aday)
    {
        $etkiTuru = strtoupper(trim((string) ($aday['etki_turu'] ?? '')));
        $conflictCode = strtoupper(trim((string) ($aday['conflict_code'] ?? '')));
        $state = BildirimPuantajEtkiDecisionPolicy::normalizeState((string) ($aday['state'] ?? ''));

        if ($state !== 'HAZIR') {
            return [
                'ok' => false,
                'code' => 'STATE_CONFLICT',
                'message' => 'Yalniz HAZIR adaylar uygulanabilir.',
            ];
        }

        if ($conflictCode === 'UCRETSIZ_IZIN_MANUEL_INCELEME' || $etkiTuru === 'MANUEL_INCELEME') {
            return [
                'ok' => false,
                'code' => 'APPLY_UNSUPPORTED',
                'message' => 'Ucretsiz izin veya manuel inceleme adayi otomatik uygulanamaz.',
            ];
        }

        if (!in_array($etkiTuru, self::$applyableEtkiTurleri, true)) {
            return [
                'ok' => false,
                'code' => 'APPLY_UNSUPPORTED',
                'message' => 'Desteklenmeyen etki turu otomatik uygulanamaz.',
            ];
        }

        $mapped = BildirimPuantajEtkiPuantajMapper::mapEtkiToPuantajFields($aday);
        if (($mapped['ok'] ?? false) !== true) {
            return $mapped;
        }

        $personelId = (int) ($aday['personel_id'] ?? 0);
        $tarih = (string) ($aday['tarih'] ?? '');

        /** @var array<string, mixed> $fields */
        $fields = $mapped['fields'];

        return [
            'ok' => true,
            'values' => BildirimPuantajEtkiPuantajMapper::buildInsertValues($aday, $fields),
        ];
    }

    /**
     * @param array<string, mixed> $aday
     * @return array{ok: bool, fields?: array<string, mixed>, code?: string, message?: string}
     * @deprecated Use BildirimPuantajEtkiPuantajMapper::mapEtkiToPuantajFields
     */
    public static function mapEtkiToPuantajFields(array $aday)
    {
        return BildirimPuantajEtkiPuantajMapper::mapEtkiToPuantajFields($aday);
    }

    public static function resolveGunTipi($tarih)
    {
        return BildirimPuantajEtkiPuantajMapper::resolveGunTipi($tarih);
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed>|null $puantajRow
     * @return array<string, mixed>
     */
    public static function buildApplySnapshot($adayId, $puantajRow)
    {
        return [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'aday_id' => (int) $adayId,
            'puantaj' => $puantajRow === null ? null : self::canonicalPuantajPayload($puantajRow),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function canonicalPuantajPayload(array $row)
    {
        return BildirimPuantajEtkiPuantajMapper::canonicalPuantajPayload($row);
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed> $sonrakiSnapshot
     */
    public static function computeUygulamaHash(array $aday, array $sonrakiSnapshot)
    {
        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'aday_id' => (int) ($aday['id'] ?? 0),
            'personel_id' => (int) ($aday['personel_id'] ?? 0),
            'tarih' => (string) ($aday['tarih'] ?? ''),
            'etki_turu' => (string) ($aday['etki_turu'] ?? ''),
            'etki_miktari' => self::nullableInt($aday['etki_miktari'] ?? null),
            'etki_birimi' => $aday['etki_birimi'] !== null ? (string) $aday['etki_birimi'] : null,
            'puantaj' => $sonrakiSnapshot['puantaj'] ?? null,
        ];

        return hash('sha256', BildirimPuantajEtkiProjectionService::canonicalJson($payload));
    }

    /**
     * @param array<string, mixed> $aday
     * @return array{status: string, code?: string, message?: string, aday?: array<string, mixed>, idempotent?: bool}
     */
    private static function evaluateIdempotent(PDO $pdo, array $aday)
    {
        $puantajId = isset($aday['uygulanan_puantaj_id']) ? (int) $aday['uygulanan_puantaj_id'] : 0;
        $hash = trim((string) ($aday['uygulama_hash'] ?? ''));
        $sonrakiRaw = $aday['sonraki_puantaj_snapshot'] ?? null;
        $sonraki = self::decodeJson($sonrakiRaw);

        if ($puantajId < 1 || $hash === '' || !is_array($sonraki)) {
            return self::conflict('APPLY_INTEGRITY_CONFLICT', 'Uygulanmis aday butunlugu bozuk.');
        }

        $puantaj = self::findPuantajById($pdo, $puantajId);
        if (!$puantaj) {
            return self::conflict('APPLY_INTEGRITY_CONFLICT', 'Uygulanmis puantaj kaydi bulunamadi.');
        }

        $rebuiltSnapshot = self::buildApplySnapshot((int) $aday['id'], $puantaj);
        $recomputed = self::computeUygulamaHash($aday, $rebuiltSnapshot);
        if (!hash_equals($hash, $recomputed)) {
            return self::conflict('APPLY_INTEGRITY_CONFLICT', 'Uygulama hash dogrulamasi basarisiz.');
        }

        $storedPuantaj = is_array($sonraki['puantaj'] ?? null) ? $sonraki['puantaj'] : null;
        $liveCanonical = self::canonicalPuantajPayload($puantaj);
        if ($storedPuantaj === null
            || BildirimPuantajEtkiProjectionService::canonicalJson($storedPuantaj)
            !== BildirimPuantajEtkiProjectionService::canonicalJson($liveCanonical)
        ) {
            return self::conflict('APPLY_INTEGRITY_CONFLICT', 'Uygulanmis puantaj snapshot uyusmazligi.');
        }

        return [
            'status' => 'idempotent',
            'aday' => $aday,
            'idempotent' => true,
        ];
    }

    /** @return array<string, mixed>|false */
    private static function findPuantajRow(PDO $pdo, $personelId, $tarih)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM gunluk_puantaj WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'tarih' => (string) $tarih,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    private static function findPuantajById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM gunluk_puantaj WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    private static function findMonthlySeal(PDO $pdo, $subeId, $tarih)
    {
        $yil = (int) substr((string) $tarih, 0, 4);
        $ay = (int) substr((string) $tarih, 5, 2);
        $stmt = $pdo->prepare(
            'SELECT id FROM puantaj_aylik_muhurleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
             LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => $yil,
            'ay' => $ay,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $values */
    private static function insertPuantajRow(PDO $pdo, array $values)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO gunluk_puantaj
             (personel_id, tarih, state, gun_tipi, hareket_durumu, dayanak, durumu_bildirdi_mi,
              durum_bildirim_aciklamasi, hesap_etkisi, beklenen_giris_saati, beklenen_cikis_saati,
              giris_saati, cikis_saati, gec_kalma_dakika, erken_cikis_dakika, gercek_mola_dakika, hesaplanan_mola_dakika,
              net_calisma_suresi_dakika, gunluk_brut_sure_dakika, hafta_tatili_hak_kazandi_mi,
              kontrol_durumu, kaynak, aciklama, muhur_id)
             VALUES
             (:personel_id, :tarih, :state, :gun_tipi, :hareket_durumu, :dayanak, :durumu_bildirdi_mi,
              :durum_bildirim_aciklamasi, :hesap_etkisi, :beklenen_giris_saati, :beklenen_cikis_saati,
              :giris_saati, :cikis_saati, :gec_kalma_dakika, :erken_cikis_dakika, :gercek_mola_dakika, :hesaplanan_mola_dakika,
              :net_calisma_suresi_dakika, :gunluk_brut_sure_dakika, :hafta_tatili_hak_kazandi_mi,
              :kontrol_durumu, :kaynak, :aciklama, :muhur_id)'
        );
        $stmt->execute($values);
    }

    private static function isDuplicateKey(\PDOException $e)
    {
        $info = $e->errorInfo ?? [];
        if (isset($info[0]) && (string) $info[0] === '23000') {
            return true;
        }
        if (isset($info[1]) && (int) $info[1] === 1062) {
            return true;
        }

        return strpos($e->getMessage(), '1062') !== false;
    }

    /** @return mixed */
    private static function decodeJson($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param mixed $value */
    private static function nullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /** @return array{status: string, code: string, message: string} */
    private static function conflict($code, $message)
    {
        return [
            'status' => 'conflict',
            'code' => (string) $code,
            'message' => (string) $message,
        ];
    }

    /** @return array{status: string, code: string, message: string} */
    private static function validation($code, $message)
    {
        return [
            'status' => 'validation',
            'code' => (string) $code,
            'message' => (string) $message,
        ];
    }
}
