<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

/**
 * Manual apply engine for INCELEME_GEREKLI puantaj etki adaylari.
 */
class BildirimPuantajEtkiManualApplyService
{
  public const SNAPSHOT_SCHEMA_VERSION = 'S74_MANUAL_APPLY_V1';
  public const UYGULAMA_MODU = 'MANUEL';

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
  public static function apply(
    PDO $pdo,
    array $aday,
    $expectedState,
    $kararTuru,
    $etkiMiktari,
    $gerekce,
    $kararVerenUserId
  ) {
    $adayId = (int) ($aday['id'] ?? 0);
    $currentState = BildirimPuantajEtkiDecisionPolicy::normalizeState((string) ($aday['state'] ?? ''));
    $normalizedGerekce = self::normalizeGerekce($gerekce);
    $kararTuru = strtoupper(trim((string) $kararTuru));
    $normalizedMiktar = self::normalizeMiktarForKarar($kararTuru, $etkiMiktari);

    if ($currentState === 'UYGULANDI') {
      return self::evaluateIdempotent($pdo, $aday, $kararTuru, $normalizedMiktar, $normalizedGerekce);
    }

    if ($currentState === 'YOK_SAYILDI') {
      return self::conflict('STATE_CONFLICT', 'Yok sayilmis puantaj etki adayi uygulanamaz.');
    }

    if ($currentState === 'HAZIR') {
      return self::conflict('STATE_CONFLICT', 'Hazir puantaj etki adayi manuel uygulanamaz.');
    }

    if (!BildirimPuantajEtkiDecisionPolicy::isManualApplyAllowed($currentState)) {
      return self::conflict('STATE_CONFLICT', 'Puantaj etki adayi manuel uygulanamaz.');
    }

    if (!BildirimPuantajEtkiDecisionPolicy::validateExpectedState($currentState, $expectedState)['valid']) {
      return [
        'status' => 'stale',
        'code' => 'STATE_STALE',
        'message' => 'Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin.',
      ];
    }

    $integrity = self::verifySourceIntegrity($aday);
    if (($integrity['ok'] ?? false) !== true) {
      return self::conflict(
        (string) ($integrity['code'] ?? 'SOURCE_INTEGRITY_FAILED'),
        (string) ($integrity['message'] ?? 'Aday kaynak butunlugu dogrulanamadi.')
      );
    }

    $subeId = (int) ($aday['sube_id'] ?? 0);
    $tarih = (string) ($aday['tarih'] ?? '');
    $personelId = (int) ($aday['personel_id'] ?? 0);
    if ($subeId < 1 || $personelId < 1 || $tarih === '') {
      return self::validation('VALIDATION_ERROR', 'Puantaj etki adayi uygulanamaz durumda.');
    }

    if (self::findMonthlySeal($pdo, $subeId, $tarih)) {
      return self::conflict('PERIOD_LOCKED', 'Bu donem muhurlenmis, puantaj kaydi olusturulamaz.');
    }

    $mapping = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields($kararTuru, $normalizedMiktar);
    if (($mapping['ok'] ?? false) !== true) {
      return self::validation(
        (string) ($mapping['code'] ?? 'VALIDATION_ERROR'),
        (string) ($mapping['message'] ?? 'Manuel karar dogrulanamadi.')
      );
    }

    /** @var array<string, mixed> $fields */
    $fields = $mapping['fields'];

    $existing = self::findPuantajRow($pdo, $personelId, $tarih);
    if ($existing) {
      return self::conflict('PUANTAJ_OLUSTU', 'Bu personel ve tarih icin gunluk puantaj kaydi zaten var.');
    }

    $durumAciklamasi = BildirimPuantajEtkiPuantajMapper::buildManualDurumAciklamasi(
      $aday['bildirim_aciklama'] ?? null,
      $normalizedGerekce
    );
    $values = BildirimPuantajEtkiPuantajMapper::buildInsertValues($aday, $fields, $durumAciklamasi);
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

    $sonrakiSnapshot = self::buildManualSnapshot(
      $aday,
      $inserted,
      $kararTuru,
      $normalizedMiktar,
      $normalizedGerekce
    );
    $hash = self::computeManualHash($aday, $sonrakiSnapshot, $kararTuru, $normalizedMiktar, $normalizedGerekce);

    $update = $pdo->prepare('
            UPDATE onayli_bildirim_puantaj_etki_adaylari
            SET state = :state,
                uygulama_modu = :uygulama_modu,
                manuel_karar_turu = :manuel_karar_turu,
                manuel_karar_miktari = :manuel_karar_miktari,
                karar_veren_user_id = :karar_veren_user_id,
                karar_zamani = :karar_zamani,
                karar_gerekcesi = :karar_gerekcesi,
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
      'uygulama_modu' => self::UYGULAMA_MODU,
      'manuel_karar_turu' => $kararTuru,
      'manuel_karar_miktari' => $normalizedMiktar,
      'karar_veren_user_id' => (int) $kararVerenUserId,
      'karar_zamani' => $kararZamani,
      'karar_gerekcesi' => $normalizedGerekce,
      'uygulanan_puantaj_id' => $puantajId,
      'onceki_puantaj_snapshot' => null,
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
   * @return array{ok: bool, code?: string, message?: string}
   */
  public static function verifySourceIntegrity(array $aday)
  {
    $storedHash = trim((string) ($aday['source_hash'] ?? ''));
    $snapshotRaw = $aday['source_snapshot'] ?? null;
    $snapshot = self::decodeJson($snapshotRaw);
    if ($storedHash === '' || !is_array($snapshot)) {
      return [
        'ok' => false,
        'code' => 'SOURCE_INTEGRITY_FAILED',
        'message' => 'Aday kaynak butunlugu dogrulanamadi.',
      ];
    }

    $recomputed = BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot);
    if (!hash_equals($storedHash, $recomputed)) {
      return [
        'ok' => false,
        'code' => 'SOURCE_INTEGRITY_FAILED',
        'message' => 'Aday kaynak butunlugu dogrulanamadi.',
      ];
    }

    return ['ok' => true];
  }

  /**
   * @param array<string, mixed> $aday
   * @param array<string, mixed> $puantajRow
   * @return array<string, mixed>
   */
  public static function buildManualSnapshot(
    array $aday,
    array $puantajRow,
    $kararTuru,
    $miktar,
    $gerekce
  ) {
    return [
      'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
      'aday_id' => (int) ($aday['id'] ?? 0),
      'source_hash' => (string) ($aday['source_hash'] ?? ''),
      'personel_id' => (int) ($aday['personel_id'] ?? 0),
      'sube_id' => (int) ($aday['sube_id'] ?? 0),
      'tarih' => (string) ($aday['tarih'] ?? ''),
      'uygulama_modu' => self::UYGULAMA_MODU,
      'manuel_karar_turu' => strtoupper(trim((string) $kararTuru)),
      'manuel_karar_miktari' => $miktar,
      'karar_gerekcesi' => self::normalizeGerekce($gerekce),
      'puantaj' => BildirimPuantajEtkiPuantajMapper::canonicalPuantajPayload($puantajRow),
    ];
  }

  /**
   * @param array<string, mixed> $aday
   * @param array<string, mixed> $sonrakiSnapshot
   */
  public static function computeManualHash(
    array $aday,
    array $sonrakiSnapshot,
    $kararTuru,
    $miktar,
    $gerekce
  ) {
    $payload = [
      'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
      'aday_id' => (int) ($aday['id'] ?? 0),
      'source_hash' => (string) ($aday['source_hash'] ?? ''),
      'personel_id' => (int) ($aday['personel_id'] ?? 0),
      'sube_id' => (int) ($aday['sube_id'] ?? 0),
      'tarih' => (string) ($aday['tarih'] ?? ''),
      'uygulama_modu' => self::UYGULAMA_MODU,
      'manuel_karar_turu' => strtoupper(trim((string) $kararTuru)),
      'manuel_karar_miktari' => $miktar,
      'karar_gerekcesi' => self::normalizeGerekce($gerekce),
      'puantaj' => $sonrakiSnapshot['puantaj'] ?? null,
    ];

    return hash('sha256', BildirimPuantajEtkiProjectionService::canonicalJson($payload));
  }

  public static function normalizeGerekce($value)
  {
    return trim((string) $value);
  }

  /** @param mixed $miktar */
  public static function normalizeMiktarForKarar($kararTuru, $miktar)
  {
    $kararTuru = strtoupper(trim((string) $kararTuru));
    if ($kararTuru === 'GEC_KALMA_DAKIKA' || $kararTuru === 'ERKEN_CIKIS_DAKIKA') {
      if ($miktar === null || $miktar === '') {
        return null;
      }

      return (int) $miktar;
    }

    return null;
  }

  /**
   * @param array<string, mixed> $aday
   * @return array{status: string, code?: string, message?: string, aday?: array<string, mixed>, idempotent?: bool}
   */
  private static function evaluateIdempotent(
    PDO $pdo,
    array $aday,
    $kararTuru,
    $miktar,
    $gerekce
  ) {
    $uygulamaModu = strtoupper(trim((string) ($aday['uygulama_modu'] ?? 'OTOMATIK')));
    if ($uygulamaModu !== self::UYGULAMA_MODU) {
      return self::conflict('STATE_CONFLICT', 'Otomatik uygulanmis puantaj etki adayi manuel uygulanamaz.');
    }

    $storedKarar = strtoupper(trim((string) ($aday['manuel_karar_turu'] ?? '')));
    $storedMiktar = $aday['manuel_karar_miktari'] !== null && $aday['manuel_karar_miktari'] !== ''
      ? (int) $aday['manuel_karar_miktari']
      : null;
    $storedGerekce = self::normalizeGerekce($aday['karar_gerekcesi'] ?? '');

  if ($storedKarar !== strtoupper(trim((string) $kararTuru))
      || $storedMiktar !== $miktar
      || $storedGerekce !== $gerekce) {
      return self::conflict(
        'MANUAL_DECISION_CONFLICT',
        'Bu aday daha once farkli bir manuel kararla uygulanmis.'
      );
    }

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

    $rebuiltSnapshot = self::buildManualSnapshot($aday, $puantaj, $kararTuru, $miktar, $gerekce);
    $recomputed = self::computeManualHash($aday, $rebuiltSnapshot, $kararTuru, $miktar, $gerekce);
    if (!hash_equals($hash, $recomputed)) {
      return self::conflict('APPLY_INTEGRITY_CONFLICT', 'Uygulama hash dogrulamasi basarisiz.');
    }

    $storedPuantaj = is_array($sonraki['puantaj'] ?? null) ? $sonraki['puantaj'] : null;
    $liveCanonical = BildirimPuantajEtkiPuantajMapper::canonicalPuantajPayload($puantaj);
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
