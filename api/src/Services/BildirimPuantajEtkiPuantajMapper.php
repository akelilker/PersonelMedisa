<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

/**
 * Shared canonical puantaj field mapping for bildirim etki aday apply paths.
 */
class BildirimPuantajEtkiPuantajMapper
{
  public const KAYNAK = 'BILDIRIM_ETKI_ADAYI';
  public const KAYNAK_REVIZYON = 'BILDIRIM_ETKI_REVIZYON';

  /** @var array<int, string> */
  public static $manualKararTurleri = [
    'DEVAMSIZLIK_GUN',
    'GEC_KALMA_DAKIKA',
    'ERKEN_CIKIS_DAKIKA',
    'GOREVDE_CALISILMIS_GUN',
  ];

  public static function isManualKararTuru($value)
  {
    return in_array(strtoupper(trim((string) $value)), self::$manualKararTurleri, true);
  }

  /**
   * Resolves the stored canonical payload first, then the narrowly-scoped S74 legacy fallback.
   *
   * @param array<string, mixed> $aday
   * @return array{ok: bool, payload?: array<string, mixed>, source?: string, code?: string, message?: string}
   */
  public static function resolveEffectiveEtkiPayload(array $aday)
  {
    $etkiTuru = strtoupper(trim((string) ($aday['etki_turu'] ?? '')));
    $miktar = $aday['etki_miktari'] ?? null;
    $birim = strtoupper(trim((string) ($aday['etki_birimi'] ?? '')));

    if ($miktar !== null && $miktar !== '' && $birim !== '') {
      $canonical = self::validateDeterministicPayload($etkiTuru, $miktar, $birim);
      if (($canonical['ok'] ?? false) === true) {
        return [
          'ok' => true,
          'payload' => $canonical['payload'],
          'source' => 'CANONICAL_COLUMNS',
        ];
      }

      return $canonical;
    }

    if (strtoupper(trim((string) ($aday['projection_version'] ?? ''))) !== 'S74_V1'
      || strtoupper(trim((string) ($aday['state'] ?? ''))) !== 'INCELEME_GEREKLI'
      || strtoupper(trim((string) ($aday['conflict_code'] ?? ''))) !== 'MEVCUT_PUANTAJ_VAR'
      || $miktar !== null) {
      return self::unsupportedPayload();
    }

    $snapshot = self::decodeSnapshot($aday['source_snapshot'] ?? null);
    $storedHash = trim((string) ($aday['source_hash'] ?? ''));
    if ($snapshot === null || $storedHash === '') {
      return self::sourceIntegrityFailed();
    }

    $recomputedHash = BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot);
    if (!hash_equals($storedHash, $recomputedHash)) {
      return self::sourceIntegrityFailed();
    }

    if (!self::legacyIdentityMatches($aday, $snapshot)) {
      return self::sourceIntegrityFailed();
    }

    $bildirimTuru = strtoupper(trim((string) ($snapshot['bildirim_turu'] ?? '')));
    $snapshotDakika = self::identityMinute($snapshot['bildirim_dakika'] ?? null);
    if ($bildirimTuru === 'GEC_GELDI' && $etkiTuru === 'GEC_KALMA_DAKIKA') {
      return self::legacyMinutePayload($etkiTuru, $snapshotDakika);
    }
    if ($bildirimTuru === 'ERKEN_CIKTI' && $etkiTuru === 'ERKEN_CIKIS_DAKIKA') {
      return self::legacyMinutePayload($etkiTuru, $snapshotDakika);
    }
    if ($bildirimTuru === 'GELMEDI' && $etkiTuru === 'DEVAMSIZLIK_GUN' && $snapshotDakika === null) {
      return [
        'ok' => true,
        'payload' => ['etki_turu' => $etkiTuru, 'etki_miktari' => 1, 'etki_birimi' => 'GUN'],
        'source' => 'S74_SOURCE_SNAPSHOT',
      ];
    }

    return self::unsupportedPayload();
  }

  /** @param array<string, mixed> $aday @return array<string, mixed> */
  public static function withEffectiveEtkiPayload(array $aday)
  {
    $resolved = self::resolveEffectiveEtkiPayload($aday);
    if (($resolved['ok'] ?? false) !== true) {
      return $aday;
    }

    /** @var array<string, mixed> $payload */
    $payload = $resolved['payload'];
    $aday['etki_turu'] = $payload['etki_turu'];
    $aday['etki_miktari'] = $payload['etki_miktari'];
    $aday['etki_birimi'] = $payload['etki_birimi'];

    return $aday;
  }

  /**
   * @return array{ok: bool, fields?: array<string, mixed>, code?: string, message?: string}
   */
  public static function mapManualKararToFields($kararTuru, $miktar)
  {
    $kararTuru = strtoupper(trim((string) $kararTuru));
    if (!self::isManualKararTuru($kararTuru)) {
      return [
        'ok' => false,
        'code' => 'VALIDATION_ERROR',
        'message' => 'Desteklenmeyen manuel karar turu.',
      ];
    }

    $miktarInt = $miktar === null || $miktar === '' ? null : (int) $miktar;

    switch ($kararTuru) {
      case 'DEVAMSIZLIK_GUN':
        if ($miktarInt !== null) {
          return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Devamsizlik karari icin etki miktari gonderilmemelidir.',
          ];
        }

        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Gelmedi',
            'dayanak' => 'Yok_Izinsiz',
            'hesap_etkisi' => 'Yevmiye_Kes',
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => null,
          ],
        ];
      case 'GEC_KALMA_DAKIKA':
        if ($miktarInt === null || $miktarInt <= 0 || $miktarInt > 1440) {
          return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Gec kalma dakikasi 1-1440 arasinda pozitif bir tam sayi olmalidir.',
          ];
        }

        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Gec_Geldi',
            'dayanak' => 'Yok_Izinsiz',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'gec_kalma_dakika' => $miktarInt,
            'erken_cikis_dakika' => null,
          ],
        ];
      case 'ERKEN_CIKIS_DAKIKA':
        if ($miktarInt === null || $miktarInt <= 0 || $miktarInt > 1440) {
          return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Erken cikis dakikasi 1-1440 arasinda pozitif bir tam sayi olmalidir.',
          ];
        }

        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Erken_Cikti',
            'dayanak' => 'Yok_Izinsiz',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => $miktarInt,
          ],
        ];
      case 'GOREVDE_CALISILMIS_GUN':
        if ($miktarInt !== null) {
          return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Gorevde calisma karari icin etki miktari gonderilmemelidir.',
          ];
        }

        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Geldi',
            'dayanak' => 'Gorevde_Calisma',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => null,
          ],
        ];
      default:
        return [
          'ok' => false,
          'code' => 'VALIDATION_ERROR',
          'message' => 'Desteklenmeyen manuel karar turu.',
        ];
    }
  }

  /**
   * @param array<string, mixed> $aday
   * @return array{ok: bool, fields?: array<string, mixed>, code?: string, message?: string}
   */
  public static function mapEtkiToPuantajFields(array $aday)
  {
    $resolved = self::resolveEffectiveEtkiPayload($aday);
    if (($resolved['ok'] ?? false) !== true) {
      return $resolved;
    }

    /** @var array<string, mixed> $payload */
    $payload = $resolved['payload'];
    $etkiTuru = (string) $payload['etki_turu'];
    $miktarInt = (int) $payload['etki_miktari'];

    switch ($etkiTuru) {
      case 'DEVAMSIZLIK_GUN':
        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Gelmedi',
            'dayanak' => 'Yok_Izinsiz',
            'hesap_etkisi' => 'Yevmiye_Kes',
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => null,
          ],
        ];
      case 'GEC_KALMA_DAKIKA':
        if ($miktarInt === null || $miktarInt <= 0) {
          return [
            'ok' => false,
            'code' => 'APPLY_UNSUPPORTED',
            'message' => 'Gec kalma dakikasi eksik; apply yapilamaz.',
          ];
        }

        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Gec_Geldi',
            'dayanak' => 'Yok_Izinsiz',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'gec_kalma_dakika' => $miktarInt,
            'erken_cikis_dakika' => null,
          ],
        ];
      case 'ERKEN_CIKIS_DAKIKA':
        if ($miktarInt === null || $miktarInt <= 0) {
          return [
            'ok' => false,
            'code' => 'APPLY_UNSUPPORTED',
            'message' => 'Erken cikis dakikasi eksik; apply yapilamaz.',
          ];
        }

        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Erken_Cikti',
            'dayanak' => 'Yok_Izinsiz',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => $miktarInt,
          ],
        ];
      case 'IZIN_GUNU':
        return self::mapIzinFields($aday);
      case 'RAPOR_GUNU':
        return self::mapRaporFields($aday);
      case 'GOREVDE_CALISILMIS_GUN':
        return [
          'ok' => true,
          'fields' => [
            'hareket_durumu' => 'Geldi',
            'dayanak' => 'Gorevde_Calisma',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => null,
          ],
        ];
      default:
        return [
          'ok' => false,
          'code' => 'APPLY_UNSUPPORTED',
          'message' => 'Desteklenmeyen etki turu otomatik uygulanamaz.',
        ];
    }
  }

  /** @return array{ok: bool, payload?: array<string, mixed>, code?: string, message?: string} */
  private static function validateDeterministicPayload($etkiTuru, $miktar, $birim)
  {
    if (!is_numeric($miktar) || (int) $miktar != $miktar) {
      return self::unsupportedPayload();
    }

    $miktarInt = (int) $miktar;
    if (($etkiTuru === 'GEC_KALMA_DAKIKA' || $etkiTuru === 'ERKEN_CIKIS_DAKIKA')
      && $miktarInt >= 1 && $miktarInt <= 1440 && $birim === 'DAKIKA') {
      return [
        'ok' => true,
        'payload' => ['etki_turu' => $etkiTuru, 'etki_miktari' => $miktarInt, 'etki_birimi' => 'DAKIKA'],
      ];
    }

    if (in_array($etkiTuru, ['DEVAMSIZLIK_GUN', 'IZIN_GUNU', 'RAPOR_GUNU', 'GOREVDE_CALISILMIS_GUN'], true)
      && $miktarInt === 1 && $birim === 'GUN') {
      return [
        'ok' => true,
        'payload' => ['etki_turu' => $etkiTuru, 'etki_miktari' => 1, 'etki_birimi' => 'GUN'],
      ];
    }

    return self::unsupportedPayload();
  }

  /** @param array<string, mixed> $aday @param array<string, mixed> $snapshot */
  private static function legacyIdentityMatches(array $aday, array $snapshot)
  {
    foreach (['gunluk_bildirim_id', 'personel_id', 'tarih', 'bildirim_turu', 'bildirim_dakika'] as $field) {
      if (!array_key_exists($field, $aday) || !array_key_exists($field, $snapshot)) {
        return false;
      }
    }

    if ((int) $aday['gunluk_bildirim_id'] < 1
      || (int) $aday['gunluk_bildirim_id'] !== (int) $snapshot['gunluk_bildirim_id']
      || (int) $aday['personel_id'] < 1
      || (int) $aday['personel_id'] !== (int) $snapshot['personel_id']
      || trim((string) $aday['tarih']) !== trim((string) $snapshot['tarih'])
      || strtoupper(trim((string) $aday['bildirim_turu'])) !== strtoupper(trim((string) $snapshot['bildirim_turu']))) {
      return false;
    }

    $rowDakika = self::identityMinute($aday['bildirim_dakika'] ?? null);
    $snapshotDakika = self::identityMinute($snapshot['bildirim_dakika'] ?? null);

    return $rowDakika === $snapshotDakika;
  }

  /** @return array{ok: bool, payload?: array<string, mixed>, source?: string, code?: string, message?: string} */
  private static function legacyMinutePayload($etkiTuru, $dakika)
  {
    if ($dakika === null || $dakika < 1 || $dakika > 1440) {
      return self::unsupportedPayload();
    }

    return [
      'ok' => true,
      'payload' => ['etki_turu' => (string) $etkiTuru, 'etki_miktari' => $dakika, 'etki_birimi' => 'DAKIKA'],
      'source' => 'S74_SOURCE_SNAPSHOT',
    ];
  }

  /** @return int|null */
  private static function identityMinute($value)
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (!is_numeric($value) || (int) $value != $value) {
      return -1;
    }

    return (int) $value;
  }

  /** @return array<string, mixed>|null */
  private static function decodeSnapshot($value)
  {
    if (is_array($value)) {
      return $value;
    }
    if ($value === null || $value === '') {
      return null;
    }

    $decoded = json_decode((string) $value, true);

    return is_array($decoded) ? $decoded : null;
  }

  /** @return array{ok: bool, code: string, message: string} */
  private static function unsupportedPayload()
  {
    return [
      'ok' => false,
      'code' => 'APPLY_UNSUPPORTED',
      'message' => 'Aday etki payloadi guvenli bicimde cozumlenemedi.',
    ];
  }

  /** @return array{ok: bool, code: string, message: string} */
  private static function sourceIntegrityFailed()
  {
    return [
      'ok' => false,
      'code' => 'SOURCE_INTEGRITY_FAILED',
      'message' => 'Aday kaynak butunlugu dogrulanamadi.',
    ];
  }

  /** @param array<string, mixed> $aday @return array{ok: bool, fields?: array<string, mixed>, code?: string, message?: string} */
  private static function mapIzinFields(array $aday)
  {
    $ucretli = $aday['ucretli_mi_snapshot'];
    if ($ucretli !== null && !(bool) ((int) $ucretli)) {
      return [
        'ok' => false,
        'code' => 'APPLY_UNSUPPORTED',
        'message' => 'Ucretsiz izin adayi otomatik uygulanamaz.',
      ];
    }

    $altTur = strtoupper(trim((string) ($aday['resmi_surec_alt_tur'] ?? '')));
    $isYillik = $altTur !== '' && (
      strpos($altTur, 'YILLIK') !== false
      || strpos($altTur, 'YILLIK_IZIN') !== false
    );

    return [
      'ok' => true,
      'fields' => [
        'hareket_durumu' => 'Gelmedi',
        'dayanak' => $isYillik ? 'Yillik_Izin' : 'Ucretli_Izinli',
        'hesap_etkisi' => 'Ucretli_Izin',
        'gec_kalma_dakika' => null,
        'erken_cikis_dakika' => null,
      ],
    ];
  }

  /** @param array<string, mixed> $aday @return array{ok: bool, fields?: array<string, mixed>, code?: string, message?: string} */
  private static function mapRaporFields(array $aday)
  {
    $surecTuru = strtoupper(trim((string) ($aday['resmi_surec_turu'] ?? '')));
    $altTur = strtoupper(trim((string) ($aday['resmi_surec_alt_tur'] ?? '')));
    $isKazasi = $surecTuru === 'IS_KAZASI'
      || strpos($altTur, 'IS_KAZASI') !== false
      || strpos($altTur, 'IS KAZASI') !== false
      || strpos($altTur, 'KAZA') !== false;

    return [
      'ok' => true,
      'fields' => [
        'hareket_durumu' => 'Gelmedi',
        'dayanak' => $isKazasi ? 'Raporlu_Is_Kazasi' : 'Raporlu_Hastalik',
        'hesap_etkisi' => 'Raporlu',
        'gec_kalma_dakika' => null,
        'erken_cikis_dakika' => null,
      ],
    ];
  }

  public static function resolveGunTipi($tarih)
  {
    $tarih = trim((string) $tarih);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
      return null;
    }

    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $tarih);
    if (!$dt || $dt->format('Y-m-d') !== $tarih) {
      return null;
    }

    if ((int) $dt->format('w') === 0) {
      return 'Hafta_Tatili_Pazar';
    }

    return null;
  }

  /**
   * @param array<string, mixed> $aday
   * @param array<string, mixed> $fields
   * @param string|null $durumAciklamasi
   * @return array<string, mixed>
   */
  public static function buildInsertValues(array $aday, array $fields, $durumAciklamasi = null)
  {
    $personelId = (int) ($aday['personel_id'] ?? 0);
    $tarih = (string) ($aday['tarih'] ?? '');
    $gunTipi = self::resolveGunTipi($tarih);
    $aciklama = $durumAciklamasi;
    if ($aciklama === null && $aday['bildirim_aciklama'] !== null) {
      $aciklama = (string) $aday['bildirim_aciklama'];
    }

    return [
      'personel_id' => $personelId,
      'tarih' => $tarih,
      'state' => 'ACIK',
      'gun_tipi' => $gunTipi,
      'hareket_durumu' => $fields['hareket_durumu'],
      'dayanak' => $fields['dayanak'],
      'durumu_bildirdi_mi' => 1,
      'durum_bildirim_aciklamasi' => $aciklama,
      'hesap_etkisi' => $fields['hesap_etkisi'],
      'beklenen_giris_saati' => null,
      'beklenen_cikis_saati' => null,
      'giris_saati' => null,
      'cikis_saati' => null,
      'gec_kalma_dakika' => $fields['gec_kalma_dakika'],
      'erken_cikis_dakika' => $fields['erken_cikis_dakika'],
      'gercek_mola_dakika' => null,
      'hesaplanan_mola_dakika' => null,
      'net_calisma_suresi_dakika' => null,
      'gunluk_brut_sure_dakika' => null,
      'hafta_tatili_hak_kazandi_mi' => null,
      'kontrol_durumu' => 'BEKLIYOR',
      'kaynak' => self::KAYNAK,
      'aciklama' => null,
      'muhur_id' => null,
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  public static function canonicalPuantajConcurrencyPayload(array $row)
  {
    $payload = self::canonicalPuantajPayload($row);
    $payload['muhur_id'] = isset($row['muhur_id']) && $row['muhur_id'] !== null ? (int) $row['muhur_id'] : null;
    $payload['updated_at'] = array_key_exists('updated_at', $row) && $row['updated_at'] !== null
      ? (string) $row['updated_at']
      : null;

    return $payload;
  }

  public static function computeCurrentPuantajHash(array $row)
  {
    return hash(
      'sha256',
      BildirimPuantajEtkiProjectionService::canonicalJson(self::canonicalPuantajConcurrencyPayload($row))
    );
  }

  /**
   * @param array<string, mixed> $aday
   * @param array<string, mixed> $existingRow
   * @return array{ok: bool, values?: array<string, mixed>, code?: string, message?: string}
   */
  public static function buildRevizeUpdateValues(array $aday, array $existingRow)
  {
    $mapped = self::mapEtkiToPuantajFields($aday);
    if (($mapped['ok'] ?? false) !== true) {
      return $mapped;
    }

    /** @var array<string, mixed> $fields */
    $fields = $mapped['fields'];
    $tarih = (string) ($aday['tarih'] ?? '');
    $gunTipi = self::resolveGunTipi($tarih);
    $aciklama = $aday['bildirim_aciklama'] !== null ? (string) $aday['bildirim_aciklama'] : null;
    $derived = self::invalidateDerivedFieldsForRevize($fields);

    return [
      'ok' => true,
      'values' => [
        'gun_tipi' => $gunTipi,
        'hareket_durumu' => $fields['hareket_durumu'],
        'dayanak' => $fields['dayanak'],
        'durumu_bildirdi_mi' => 1,
        'durum_bildirim_aciklamasi' => $aciklama,
        'hesap_etkisi' => $fields['hesap_etkisi'],
        'gec_kalma_dakika' => $fields['gec_kalma_dakika'],
        'erken_cikis_dakika' => $fields['erken_cikis_dakika'],
        'giris_saati' => $existingRow['giris_saati'] ?? null,
        'cikis_saati' => $existingRow['cikis_saati'] ?? null,
        'beklenen_giris_saati' => $existingRow['beklenen_giris_saati'] ?? null,
        'beklenen_cikis_saati' => $existingRow['beklenen_cikis_saati'] ?? null,
        'gercek_mola_dakika' => self::nullableInt($existingRow['gercek_mola_dakika'] ?? null),
        'hesaplanan_mola_dakika' => $derived['hesaplanan_mola_dakika'],
        'net_calisma_suresi_dakika' => $derived['net_calisma_suresi_dakika'],
        'gunluk_brut_sure_dakika' => $derived['gunluk_brut_sure_dakika'],
        'hafta_tatili_hak_kazandi_mi' => $derived['hafta_tatili_hak_kazandi_mi'],
        'state' => 'ACIK',
        'kontrol_durumu' => 'BEKLIYOR',
        'kaynak' => self::KAYNAK_REVIZYON,
        'aciklama' => $existingRow['aciklama'] ?? null,
        'muhur_id' => null,
      ],
    ];
  }

  /**
   * @param array<string, mixed> $effectFields
   * @return array<string, mixed>
   */
  public static function invalidateDerivedFieldsForRevize(array $effectFields)
  {
    return [
      'hesaplanan_mola_dakika' => null,
      'net_calisma_suresi_dakika' => null,
      'gunluk_brut_sure_dakika' => null,
      'hafta_tatili_hak_kazandi_mi' => null,
    ];
  }

  public static function canonicalPuantajPayload(array $row)
  {
    return [
      'id' => isset($row['id']) ? (int) $row['id'] : null,
      'personel_id' => (int) $row['personel_id'],
      'tarih' => (string) $row['tarih'],
      'state' => (string) ($row['state'] ?? 'ACIK'),
      'gun_tipi' => array_key_exists('gun_tipi', $row) && $row['gun_tipi'] !== null ? (string) $row['gun_tipi'] : null,
      'hareket_durumu' => array_key_exists('hareket_durumu', $row) && $row['hareket_durumu'] !== null ? (string) $row['hareket_durumu'] : null,
      'dayanak' => array_key_exists('dayanak', $row) && $row['dayanak'] !== null ? (string) $row['dayanak'] : null,
      'durumu_bildirdi_mi' => isset($row['durumu_bildirdi_mi'])
        ? (bool) ((int) $row['durumu_bildirdi_mi'])
        : null,
      'durum_bildirim_aciklamasi' => array_key_exists('durum_bildirim_aciklamasi', $row) && $row['durum_bildirim_aciklamasi'] !== null
        ? (string) $row['durum_bildirim_aciklamasi']
        : null,
      'hesap_etkisi' => array_key_exists('hesap_etkisi', $row) && $row['hesap_etkisi'] !== null ? (string) $row['hesap_etkisi'] : null,
      'beklenen_giris_saati' => array_key_exists('beklenen_giris_saati', $row) && $row['beklenen_giris_saati'] !== null ? (string) $row['beklenen_giris_saati'] : null,
      'beklenen_cikis_saati' => array_key_exists('beklenen_cikis_saati', $row) && $row['beklenen_cikis_saati'] !== null ? (string) $row['beklenen_cikis_saati'] : null,
      'giris_saati' => array_key_exists('giris_saati', $row) && $row['giris_saati'] !== null ? (string) $row['giris_saati'] : null,
      'cikis_saati' => array_key_exists('cikis_saati', $row) && $row['cikis_saati'] !== null ? (string) $row['cikis_saati'] : null,
      'gec_kalma_dakika' => self::nullableInt($row['gec_kalma_dakika'] ?? null),
      'erken_cikis_dakika' => self::nullableInt($row['erken_cikis_dakika'] ?? null),
      'gercek_mola_dakika' => self::nullableInt($row['gercek_mola_dakika'] ?? null),
      'hesaplanan_mola_dakika' => self::nullableInt($row['hesaplanan_mola_dakika'] ?? null),
      'net_calisma_suresi_dakika' => self::nullableInt($row['net_calisma_suresi_dakika'] ?? null),
      'gunluk_brut_sure_dakika' => self::nullableInt($row['gunluk_brut_sure_dakika'] ?? null),
      'hafta_tatili_hak_kazandi_mi' => isset($row['hafta_tatili_hak_kazandi_mi']) && $row['hafta_tatili_hak_kazandi_mi'] !== null
        ? (bool) ((int) $row['hafta_tatili_hak_kazandi_mi'])
        : null,
      'kontrol_durumu' => array_key_exists('kontrol_durumu', $row) && $row['kontrol_durumu'] !== null && $row['kontrol_durumu'] !== ''
        ? (string) $row['kontrol_durumu']
        : 'BEKLIYOR',
      'kaynak' => array_key_exists('kaynak', $row) && $row['kaynak'] !== null ? (string) $row['kaynak'] : null,
      'aciklama' => array_key_exists('aciklama', $row) && $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
      'muhur_id' => isset($row['muhur_id']) && $row['muhur_id'] !== null ? (int) $row['muhur_id'] : null,
    ];
  }

  public static function buildManualDurumAciklamasi($bildirimAciklama, $gerekce)
  {
    $kaynak = trim((string) $bildirimAciklama);
    $karar = trim((string) $gerekce);
    if ($kaynak === '') {
      return $karar !== '' ? $karar : null;
    }
    if ($karar === '') {
      return $kaynak;
    }

    return $kaynak . ' | Manuel karar: ' . $karar;
  }

  /** @param mixed $value */
  public static function nullableInt($value)
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (!is_numeric($value)) {
      return null;
    }

    return (int) $value;
  }
}
