<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

class BildirimPuantajEtkiProjectionService
{
    public const PROJECTION_VERSION = 'S74_V1';
    public const SOURCE_PRIORITY_BILDIRIM = 'ONAYLI_GUNLUK_BILDIRIM';

    /** @var array<int, string> */
    public static $allowedBildirimTurleri = [
        'GELMEDI',
        'GEC_GELDI',
        'ERKEN_CIKTI',
        'IZINLI',
        'RAPORLU',
        'GOREVDE',
        'DIGER',
    ];

    /** @var array<int, string> */
    public static $allowedEtkiTurleri = [
        'DEVAMSIZLIK_GUN',
        'GEC_KALMA_DAKIKA',
        'ERKEN_CIKIS_DAKIKA',
        'IZIN_GUNU',
        'RAPOR_GUNU',
        'GOREVDE_CALISILMIS_GUN',
        'MANUEL_INCELEME',
    ];

    /** @var array<int, string> */
    public static $allowedStates = [
        'HAZIR',
        'INCELEME_GEREKLI',
        'UYGULANDI',
        'YOK_SAYILDI',
    ];

    /** @var array<int, string> */
    public static $absenceLikeBildirimTurleri = [
        'GELMEDI',
        'IZINLI',
        'RAPORLU',
        'GOREVDE',
    ];

    /**
     * @param array<int, array<string, mixed>> $dayRows
     * @return array<int, string|null> bildirim_id => conflict_code
     */
    public static function evaluateDayCompatibility(array $dayRows)
    {
        $conflicts = [];
        foreach ($dayRows as $row) {
            $conflicts[(int) $row['id']] = null;
        }

        if (count($dayRows) <= 1) {
            return $conflicts;
        }

        $typeCounts = [];
        foreach ($dayRows as $row) {
            $tur = strtoupper(trim((string) $row['bildirim_turu']));
            if (!isset($typeCounts[$tur])) {
                $typeCounts[$tur] = 0;
            }
            $typeCounts[$tur] += 1;
        }

        foreach ($dayRows as $row) {
            $id = (int) $row['id'];
            $tur = strtoupper(trim((string) $row['bildirim_turu']));

            if ($tur === 'DIGER') {
                continue;
            }

            if (($typeCounts[$tur] ?? 0) > 1) {
                $conflicts[$id] = 'COKLU_BILDIRIM_CELISKISI';
                continue;
            }

            $otherNonDiger = [];
            foreach ($dayRows as $peer) {
                $peerTur = strtoupper(trim((string) $peer['bildirim_turu']));
                if ((int) $peer['id'] === $id || $peerTur === 'DIGER') {
                    continue;
                }
                $otherNonDiger[] = $peerTur;
            }

            if (count($otherNonDiger) === 0) {
                continue;
            }

            if (count($otherNonDiger) === 1) {
                $other = $otherNonDiger[0];
                if (($tur === 'GEC_GELDI' && $other === 'ERKEN_CIKTI')
                    || ($tur === 'ERKEN_CIKTI' && $other === 'GEC_GELDI')) {
                    continue;
                }
            }

            $conflicts[$id] = 'COKLU_BILDIRIM_CELISKISI';
        }

        return $conflicts;
    }

    /**
     * @param array<string, mixed> $bildirim
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function projectCandidate(array $bildirim, array $context)
    {
        $tur = strtoupper(trim((string) ($bildirim['bildirim_turu'] ?? '')));
        $etkiTuru = self::mapBildirimTuruToEtkiTuru($tur);
        if ($etkiTuru === null) {
            return self::inceleme('MANUEL_INCELEME', 'DIGER_MANUEL_INCELEME', ['bildirim_turu' => $tur]);
        }

        $dakika = self::normalizeDakika($bildirim['dakika'] ?? null);
        $hasPuantaj = (bool) ($context['has_puantaj_row'] ?? false);
        $multiConflictCode = trim((string) ($context['multi_conflict_code'] ?? ''));
        $resmiSurecler = is_array($context['resmi_surecler'] ?? null) ? $context['resmi_surecler'] : [];

        if ($tur === 'DIGER') {
            return self::inceleme('MANUEL_INCELEME', 'DIGER_MANUEL_INCELEME', ['reason' => 'DIGER bildirimi otomatik etki uretmez']);
        }

        if ($hasPuantaj) {
            return self::inceleme($etkiTuru, 'MEVCUT_PUANTAJ_VAR', ['reason' => 'Mevcut gunluk_puantaj satiri var']);
        }

        if ($multiConflictCode !== '') {
            return self::inceleme($etkiTuru, $multiConflictCode, ['reason' => 'Ayni gun coklu bildirim celiskisi']);
        }

        if ($tur === 'GEC_GELDI' || $tur === 'ERKEN_CIKTI') {
            if ($dakika === null || $dakika <= 0) {
                return self::inceleme($etkiTuru, 'DAKIKA_EKSIK', ['bildirim_turu' => $tur]);
            }

            if (self::hasAbsenceLikePeer($context, $tur)) {
                return self::inceleme($etkiTuru, 'COKLU_BILDIRIM_CELISKISI', ['reason' => 'Absence-like bildirim ile cakisiyor']);
            }

            return self::hazir($etkiTuru, $dakika, 'DAKIKA');
        }

        if ($tur === 'GELMEDI') {
            if (self::hasResmiIzin($resmiSurecler) || self::hasResmiRapor($resmiSurecler) || self::hasResmiGorev($resmiSurecler)) {
                return self::inceleme($etkiTuru, 'COKLU_BILDIRIM_CELISKISI', ['reason' => 'Resmi surec ile celisen devamsizlik bildirimi']);
            }

            return self::hazir($etkiTuru, 1, 'GUN');
        }

        if ($tur === 'IZINLI') {
            $izinResult = self::resolveIzinSurecleri($resmiSurecler);
            if ($izinResult['status'] === 'none') {
                return self::inceleme($etkiTuru, 'IZIN_SURECI_YOK', ['reason' => 'Resmi izin sureci bulunamadi']);
            }
            if ($izinResult['status'] === 'multiple') {
                return self::inceleme($etkiTuru, 'COKLU_RESMI_SUREC', ['reason' => 'Birden fazla eslesen izin sureci']);
            }

            $matchedSurec = $izinResult['surec'];
            if ($matchedSurec !== null && !(bool) ($matchedSurec['ucretli_mi'] ?? false)) {
                return self::inceleme(
                    $etkiTuru,
                    'UCRETSIZ_IZIN_DESTEKLENMIYOR',
                    ['reason' => 'Ucretsiz izin sureci otomatik HAZIR aday uretimine dahil degil']
                );
            }

            return self::hazir($etkiTuru, 1, 'GUN', $matchedSurec);
        }

        if ($tur === 'RAPORLU') {
            $raporResult = self::resolveRaporSurecleri($resmiSurecler);
            if ($raporResult['status'] === 'none') {
                return self::inceleme($etkiTuru, 'RAPOR_SURECI_YOK', ['reason' => 'Resmi rapor sureci bulunamadi']);
            }
            if ($raporResult['status'] === 'multiple') {
                return self::inceleme($etkiTuru, 'COKLU_RESMI_SUREC', ['reason' => 'Birden fazla eslesen rapor sureci']);
            }

            return self::hazir($etkiTuru, 1, 'GUN', $raporResult['surec']);
        }

        if ($tur === 'GOREVDE') {
            if (self::hasResmiIzin($resmiSurecler) || self::hasResmiRapor($resmiSurecler)) {
                return self::inceleme($etkiTuru, 'COKLU_BILDIRIM_CELISKISI', ['reason' => 'Resmi absence sureci ile celisen gorev bildirimi']);
            }
            if (self::hasAbsenceLikePeer($context, $tur)) {
                return self::inceleme($etkiTuru, 'COKLU_BILDIRIM_CELISKISI', ['reason' => 'Absence-like bildirim ile cakisiyor']);
            }

            return self::hazir($etkiTuru, 1, 'GUN');
        }

        return self::inceleme('MANUEL_INCELEME', 'DIGER_MANUEL_INCELEME', ['bildirim_turu' => $tur]);
    }

    /**
     * @param array<string, mixed> $bildirim
     * @param array<string, mixed> $chain
     * @param array<string, mixed>|null $puantajOzet
     * @param array<string, mixed>|null $matchedSurec
     * @return array<string, mixed>
     */
    public static function buildSourceSnapshot(
        array $bildirim,
        array $chain,
        $puantajOzet,
        $matchedSurec
    ) {
        return [
            'gunluk_bildirim_id' => (int) $bildirim['id'],
            'bildirim_turu' => (string) $bildirim['bildirim_turu'],
            'bildirim_alt_tur' => $bildirim['alt_tur'] !== null ? (string) $bildirim['alt_tur'] : null,
            'personel_id' => (int) $bildirim['personel_id'],
            'tarih' => (string) $bildirim['tarih'],
            'bildirim_dakika' => self::normalizeDakika($bildirim['dakika'] ?? null),
            'bildirim_aciklama' => $bildirim['aciklama'] !== null ? (string) $bildirim['aciklama'] : null,
            'gunluk_state' => (string) $bildirim['state'],
            'haftalik_mutabakat_id' => $bildirim['haftalik_mutabakat_id'] !== null
                ? (int) $bildirim['haftalik_mutabakat_id']
                : null,
            'aylik_bildirim_onayi_id' => (int) $chain['aylik_bildirim_onayi_id'],
            'genel_yonetici_bildirim_onayi_id' => (int) $chain['genel_yonetici_bildirim_onayi_id'],
            'sube_id' => (int) $bildirim['sube_id'],
            'birim_amiri_user_id' => (int) $chain['birim_amiri_user_id'],
            'bildirim_created_at' => (string) $bildirim['created_at'],
            'bildirim_updated_at' => (string) $bildirim['updated_at'],
            'resmi_surec_ozeti' => $matchedSurec,
            'puantaj_ozeti' => $puantajOzet,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function computeSourceHash(array $snapshot)
    {
        return hash('sha256', self::canonicalJson($snapshot));
    }

    /** @param array<string, mixed> $data */
    public static function canonicalJson(array $data)
    {
        return json_encode(self::sortKeysRecursive($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function mapBildirimTuruToEtkiTuru($tur)
    {
        $tur = strtoupper(trim((string) $tur));
        switch ($tur) {
            case 'GELMEDI':
                return 'DEVAMSIZLIK_GUN';
            case 'GEC_GELDI':
                return 'GEC_KALMA_DAKIKA';
            case 'ERKEN_CIKTI':
                return 'ERKEN_CIKIS_DAKIKA';
            case 'IZINLI':
                return 'IZIN_GUNU';
            case 'RAPORLU':
                return 'RAPOR_GUNU';
            case 'GOREVDE':
                return 'GOREVDE_CALISILMIS_GUN';
            case 'DIGER':
                return 'MANUEL_INCELEME';
            default:
                return null;
        }
    }

    /** @param array<int, array<string, mixed>> $surecler */
    public static function hasResmiIzin(array $surecler)
    {
        foreach ($surecler as $surec) {
            if (self::isIzinSureci((string) ($surec['surec_turu'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $surecler */
    public static function hasResmiRapor(array $surecler)
    {
        foreach ($surecler as $surec) {
            if (self::isRaporSureci((string) ($surec['surec_turu'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $surecler */
    public static function hasResmiGorev(array $surecler)
    {
        foreach ($surecler as $surec) {
            if (self::isGorevSureci((string) ($surec['surec_turu'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    public static function isIzinSureci($surecTuru)
    {
        $tur = strtoupper(trim((string) $surecTuru));

        return $tur === 'IZIN' || strpos($tur, 'IZIN') !== false;
    }

    public static function isRaporSureci($surecTuru)
    {
        $tur = strtoupper(trim((string) $surecTuru));

        return $tur === 'RAPOR' || $tur === 'IS_KAZASI' || strpos($tur, 'RAPOR') !== false;
    }

    public static function isGorevSureci($surecTuru)
    {
        $tur = strtoupper(trim((string) $surecTuru));

        return strpos($tur, 'GOREV') !== false;
    }

    /** @param array<string, mixed> $context */
    private static function hasAbsenceLikePeer(array $context, $currentTur)
    {
        $currentTur = strtoupper(trim((string) $currentTur));
        $peers = is_array($context['day_bildirim_turleri'] ?? null) ? $context['day_bildirim_turleri'] : [];
        foreach ($peers as $peerTur) {
            $peerTur = strtoupper(trim((string) $peerTur));
            if ($peerTur === $currentTur || $peerTur === 'DIGER') {
                continue;
            }
            if (in_array($peerTur, self::$absenceLikeBildirimTurleri, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $surecler @return array{status: string, surec: array<string, mixed>|null} */
    private static function resolveIzinSurecleri(array $surecler)
    {
        $matches = [];
        foreach ($surecler as $surec) {
            if (!self::isIzinSureci((string) ($surec['surec_turu'] ?? ''))) {
                continue;
            }
            $matches[] = self::mapSurecOzet($surec);
        }

        if (count($matches) === 0) {
            return ['status' => 'none', 'surec' => null];
        }
        if (count($matches) > 1) {
            return ['status' => 'multiple', 'surec' => null];
        }

        return ['status' => 'single', 'surec' => $matches[0]];
    }

    /** @param array<int, array<string, mixed>> $surecler @return array{status: string, surec: array<string, mixed>|null} */
    private static function resolveRaporSurecleri(array $surecler)
    {
        $matches = [];
        foreach ($surecler as $surec) {
            if (!self::isRaporSureci((string) ($surec['surec_turu'] ?? ''))) {
                continue;
            }
            $matches[] = self::mapSurecOzet($surec);
        }

        if (count($matches) === 0) {
            return ['status' => 'none', 'surec' => null];
        }
        if (count($matches) > 1) {
            return ['status' => 'multiple', 'surec' => null];
        }

        return ['status' => 'single', 'surec' => $matches[0]];
    }

    /** @param array<string, mixed> $surec @return array<string, mixed> */
    private static function mapSurecOzet(array $surec)
    {
        return [
            'id' => (int) $surec['id'],
            'surec_turu' => (string) $surec['surec_turu'],
            'alt_tur' => $surec['alt_tur'] !== null ? (string) $surec['alt_tur'] : null,
            'baslangic_tarihi' => (string) $surec['baslangic_tarihi'],
            'bitis_tarihi' => $surec['bitis_tarihi'] !== null ? (string) $surec['bitis_tarihi'] : null,
            'ucretli_mi' => (bool) ((int) ($surec['ucretli_mi'] ?? 0)),
            'state' => (string) ($surec['state'] ?? 'AKTIF'),
        ];
    }

    /** @param array<string, mixed>|null $matchedSurec @return array<string, mixed> */
    private static function hazir($etkiTuru, $miktar, $birim, $matchedSurec = null)
    {
        $result = [
            'etki_turu' => (string) $etkiTuru,
            'etki_miktari' => (int) $miktar,
            'etki_birimi' => (string) $birim,
            'state' => 'HAZIR',
            'conflict_code' => null,
            'conflict_detail' => null,
        ];
        if ($matchedSurec !== null) {
            $result['matched_surec'] = $matchedSurec;
        }

        return $result;
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private static function inceleme($etkiTuru, $code, array $details)
    {
        return [
            'etki_turu' => (string) $etkiTuru,
            'etki_miktari' => null,
            'etki_birimi' => null,
            'state' => 'INCELEME_GEREKLI',
            'conflict_code' => (string) $code,
            'conflict_detail' => $details,
        ];
    }

    /** @param mixed $value */
    private static function normalizeDakika($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $dakika = (int) $value;

        return $dakika >= 0 ? $dakika : null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function sortKeysRecursive(array $data)
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeysRecursive($value);
            }
        }

        return $data;
    }
}
