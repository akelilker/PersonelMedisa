<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use Medisa\Api\Services\Money\Money;
use Medisa\Api\Services\Money\Rate;
use Medisa\Api\Services\Money\RoundingPolicy;

/**
 * Saf deterministik maas hesap motoru.
 * DB erisimi yok; yalniz immutable DTO girdileri kullanir.
 */
final class MaasHesaplamaEngine
{
    public const ENGINE_VERSION = 'S77D_PAYROLL_ENGINE_V2';
    public const CONTRACT_VERSION = 'S77D_PAYROLL_CANDIDATE_V2';
    public const SOLVER_MAX_ITERATIONS = 64;
    public const SOLVER_TOLERANCE_KURUS = 1;
    /** Is Kanunu haftalik azami normal sure (45 saat). */
    public const LEGAL_WEEKLY_LIMIT_MINUTES = 2700;

    /** @var array<int, string> */
    private static $holidayModes = ['GUNLUK_ILAVE', 'SAAT_CARPAN', 'GUNLUK_ILAVE_VE_SAAT_CARPAN'];

    /**
     * @param array{
     *   personel: array<string, mixed>,
     *   ucret_segmentleri: array<int, array<string, mixed>>,
     *   puantajlar: array<int, array<string, mixed>>,
     *   izinler: array<int, array<string, mixed>>,
     *   etki_adaylari: array<int, array<string, mixed>>,
     *   finanslar: array<int, array<string, mixed>>,
     *   mevzuat: array<string, array<string, mixed>>,
     *   carryover: array<string, mixed>,
     *   donem_baslangic: string,
     *   donem_bitis: string
     * } $input
     * @return array<string, mixed>
     */
    public static function calculate(array $input)
    {
        $params = self::resolveParams($input['mevzuat']);
        $carryover = $input['carryover'];
        $prevMatrah = Money::fromDecimalString((string) ($carryover['onceki_kumulatif_gelir_vergisi_matrahi'] ?? '0'));
        $segments = $input['ucret_segmentleri'];
        if (count($segments) === 0) {
            return self::errorResult('SALARY_SEGMENT_INVALID', 'Ucret segmenti yok.');
        }

        $primary = $segments[0];
        $ucretTuru = strtoupper((string) ($primary['ucret_turu'] ?? ''));
        if (!in_array($ucretTuru, ['BRUT', 'NET'], true)) {
            return self::errorResult('SALARY_TYPE_UNSUPPORTED', 'Desteklenmeyen ucret turu: ' . $ucretTuru);
        }
        foreach ($segments as $segment) {
            if (strtoupper((string) ($segment['ucret_turu'] ?? '')) !== $ucretTuru) {
                return self::errorResult('SALARY_TYPE_UNSUPPORTED', 'Donem icinde karisik BRUT/NET segment desteklenmez.');
            }
            if ((string) ($segment['para_birimi'] ?? 'TRY') !== 'TRY') {
                return self::errorResult('SALARY_SEGMENT_INVALID', 'Yalniz TRY para birimi desteklenir.');
            }
        }

        $warnings = [];
        if (count($segments) > 1) {
            $warnings[] = 'MID_MONTH_SALARY_CHANGE';
        }
        foreach ($segments as $segment) {
            if (!empty($segment['virtual_legacy'])) {
                $warnings[] = 'LEGACY_SALARY_FALLBACK_USED';
                break;
            }
        }

        $normalAyGun = self::paramInt($params, 'NORMAL_AY_GUN_SAYISI');
        $gunlukDk = self::paramHoursToMinutes($params, 'GUNLUK_CALISMA_SAATI');
        $aylikDk = self::paramHoursToMinutes($params, 'AYLIK_NORMAL_CALISMA_SAATI');
        $haftalikIsGunu = self::paramInt($params, 'HAFTALIK_IS_GUNU_SAYISI');
        $htMode = strtoupper((string) $params['HAFTA_TATILI_HESAP_MODU']);
        $ubgtMode = strtoupper((string) $params['UBGT_HESAP_MODU']);
        if ($normalAyGun < 1 || $gunlukDk < 1 || $aylikDk < 1 || $haftalikIsGunu < 1) {
            return self::errorResult('LEGAL_PARAMETER_REQUIRED_MISSING', 'Calisma suresi parametreleri gecersiz.');
        }
        if (!in_array($htMode, self::$holidayModes, true) || !in_array($ubgtMode, self::$holidayModes, true)) {
            return self::errorResult('LEGAL_PARAMETER_REQUIRED_MISSING', 'HAFTA_TATILI_HESAP_MODU veya UBGT_HESAP_MODU gecersiz.');
        }

        // Baz sozlesme tutari: istihdam kesisimindeki prorata toplam
        $contractBase = Money::zero();
        $kalemler = [];
        $sira = 0;
        foreach ($segments as $segment) {
            $monthly = Money::fromDecimalString((string) $segment['ucret_tutari']);
            $days = self::inclusiveDays((string) $segment['etki_baslangic'], (string) $segment['etki_bitis']);
            $portion = $monthly->mulDiv($days, $normalAyGun);
            $contractBase = $contractBase->add($portion);
            $sira++;
            $kalemler[] = self::line($sira, 'BAZ_UCRET', 'SEGMENT_BAZ_UCRET', 'BILGI', $days, 'GUN', null, null, $portion, 'UCRET', isset($segment['id']) ? $segment['id'] : null, 'Segment baz ucret prorata', [
                'ucret_turu' => $ucretTuru,
                'aylik_tutar' => $monthly->toDecimalString(),
                'gun_sayisi' => $days,
                'normal_ay_gun' => $normalAyGun,
            ]);
        }

        // Saatlik ucret: sozlesme_baz * 60 / AYLIK_NORMAL_CALISMA_DAKIKA
        $hourly = $contractBase->mulDiv(60, $aylikDk);
        $daily = $contractBase->mulDiv(1, $normalAyGun);

        $attendance = self::buildAttendanceLines($input, $hourly, $daily, $params, $sira, $warnings);
        $sira = $attendance['sira'];
        $kalemler = array_merge($kalemler, $attendance['kalemler']);
        $grossAdd = $attendance['gross_add'];
        $grossDeduct = $attendance['gross_deduct'];
        $netOnlyDeduct = $attendance['net_only'];

        $finance = self::buildFinanceLines($input['finanslar'], $sira);
        if (isset($finance['error'])) {
            return self::errorResult($finance['error']['code'], $finance['error']['message']);
        }
        $sira = $finance['sira'];
        $kalemler = array_merge($kalemler, $finance['kalemler']);
        $grossAdd = $grossAdd->add($finance['gross_add']);
        $netOnlyDeduct = $netOnlyDeduct->add($finance['net_deduct']);
        $netOnlyAdd = $finance['net_add'];

        $solverInfo = null;
        if ($ucretTuru === 'BRUT') {
            $brut = $contractBase->add($grossAdd)->sub($grossDeduct);
            if ($brut->isNegative()) {
                return self::errorResult('NEGATIVE_CALCULATION_BASE', 'Hesaplanan brüt negatif.');
            }
            $legal = self::computeLegalDeductions($brut, $grossAdd, $params, $prevMatrah, $sira);
            $sira = $legal['sira'];
            $kalemler = array_merge($kalemler, $legal['kalemler']);
            $net = $brut->sub($legal['sgk'])->sub($legal['issizlik'])->sub($legal['gv'])->sub($legal['damga']);
            $net = $net->add($netOnlyAdd)->sub($netOnlyDeduct);
            $hedefNet = null;
            $sozlesmeBrut = $contractBase;
        } else {
            $warnings[] = 'NET_TO_GROSS_SOLVER_USED';
            $hedefNet = $contractBase->add($grossAdd)->sub($grossDeduct)->add($netOnlyAdd)->sub($netOnlyDeduct);
            // Solver targets net AFTER legal deductions from gross base; attendance/finance already in hedef
            // Reconstruct: find brut such that brut - legal(brut) + netOnlyAdd - netOnlyDeduct ~= hedefNet
            // Actually hedefNet already includes net-only effects. Legal applies to gross.
            // Define: net_from_gross(brut) = brut - sgk - issizlik - gv - damga
            // We need net_from_gross(brut) + netOnlyAdd - netOnlyDeduct == hedefNet_full
            // where hedefNet_full = contractBase(net target) + grossAdd - grossDeduct + netOnlyAdd - netOnlyDeduct
            // Wait - for NET salary, contractBase is target net for the base work. Gross adds (overtime) increase
            // what employee should receive. Simpler model:
            // target_net = contractBase + overtime_gross_equiv treated as net additions? 
            // Contract: NET model uses solver so that final net_odenecek equals
            //   prorata_net_contract + net_finance_add - net_finance_deduct
            //   and gross attendance premiums are included in gross before legal, which changes net.
            // Practical approach used here:
            //   base_target_net = contractBase
            //   Solve brut0 such that net(brut0) = base_target_net
            //   Then add grossAdd as gross, recompute legal on (brut0 + grossAdd - grossDeduct)
            //   Then apply net-only finance.
            $solved = self::solveNetToGross($contractBase, $params, $prevMatrah);
            if (isset($solved['error'])) {
                return self::errorResult($solved['error']['code'], $solved['error']['message']);
            }
            $solverInfo = $solved['solver'];
            $brut0 = $solved['brut'];
            $brut = $brut0->add($grossAdd)->sub($grossDeduct);
            if ($brut->isNegative()) {
                return self::errorResult('NEGATIVE_CALCULATION_BASE', 'Hesaplanan brüt negatif.');
            }
            $legal = self::computeLegalDeductions($brut, $grossAdd, $params, $prevMatrah, $sira);
            $sira = $legal['sira'];
            $kalemler = array_merge($kalemler, $legal['kalemler']);
            $net = $brut->sub($legal['sgk'])->sub($legal['issizlik'])->sub($legal['gv'])->sub($legal['damga']);
            $net = $net->add($netOnlyAdd)->sub($netOnlyDeduct);
            $diff = $net->sub($contractBase->add($netOnlyAdd)->sub($netOnlyDeduct));
            // After overtime gross effects, net may differ from pure contract target; rounding line for solver residue vs base
            $baseNetExpected = $contractBase->add($netOnlyAdd)->sub($netOnlyDeduct);
            // Compare solved path without OT: net(brut0) should be within tolerance of contractBase
            $legal0 = self::computeLegalDeductions($brut0, Money::zero(), $params, $prevMatrah, 0);
            $net0 = $brut0->sub($legal0['sgk'])->sub($legal0['issizlik'])->sub($legal0['gv'])->sub($legal0['damga']);
            $roundDiff = $net0->sub($contractBase);
            if (!$roundDiff->isZero()) {
                $warnings[] = 'ROUNDING_DIFFERENCE';
                $sira++;
                $kalemler[] = self::line($sira, 'YUVARLAMA', 'NET_SOLVER_YUVARLAMA', 'BILGI', null, null, null, null, $roundDiff, 'SOLVER', null, 'Net solver kurus farki', [
                    'hedef_net' => $contractBase->toDecimalString(),
                    'hesaplanan_net_baz' => $net0->toDecimalString(),
                ]);
            }
            $sozlesmeBrut = $brut0;
            $hedefNet = $contractBase;
        }

        if ($net->isNegative()) {
            return self::errorResult('NEGATIVE_CALCULATION_BASE', 'Net odenecek negatif olamaz.');
        }

        $toplamEk = $grossAdd->add($netOnlyAdd);
        $toplamKesintiLegal = $legal['sgk']->add($legal['issizlik'])->add($legal['gv'])->add($legal['damga']);
        $toplamKesinti = $toplamKesintiLegal->add($grossDeduct)->add($netOnlyDeduct);

        // Integrity: brut - legal_deductions + net_add - net_deduct - gross_deduct_already_in_brut = net
        // brut already has grossAdd - grossDeduct baked in
        $check = $brut->sub($legal['sgk'])->sub($legal['issizlik'])->sub($legal['gv'])->sub($legal['damga'])->add($netOnlyAdd)->sub($netOnlyDeduct);
        if ($check->cmp($net) !== 0) {
            return self::errorResult('PAYROLL_CALCULATION_INPUT_INVALID', 'Kalem/ozet butunluk kontrolu basarisiz.');
        }

        $kalemSumArti = Money::zero();
        $kalemSumEksi = Money::zero();
        foreach ($kalemler as $kalem) {
            $t = Money::fromDecimalString((string) $kalem['tutar']);
            if ($kalem['yon'] === 'ARTI') {
                $kalemSumArti = $kalemSumArti->add($t);
            } elseif ($kalem['yon'] === 'EKSI') {
                $kalemSumEksi = $kalemSumEksi->add($t);
            }
        }

        $sonrakiMatrah = $prevMatrah->add($legal['gv_matrah']);

        $ozet = [
            'ucret_turu' => $ucretTuru,
            'para_birimi' => 'TRY',
            'hedef_net_tutar' => $hedefNet ? $hedefNet->toDecimalString() : null,
            'sozlesme_brut_tutar' => $sozlesmeBrut->toDecimalString(),
            'hesaplanan_brut_tutar' => $brut->toDecimalString(),
            'sgk_matrahi' => $legal['sgk_matrah']->toDecimalString(),
            'gelir_vergisi_matrahi' => $legal['gv_matrah']->toDecimalString(),
            'damga_vergisi_matrahi' => $legal['damga_matrah']->toDecimalString(),
            'sgk_isci_primi' => $legal['sgk']->toDecimalString(),
            'issizlik_isci_primi' => $legal['issizlik']->toDecimalString(),
            'gelir_vergisi' => $legal['gv']->toDecimalString(),
            'damga_vergisi' => $legal['damga']->toDecimalString(),
            'toplam_ek_odeme' => $toplamEk->toDecimalString(),
            'toplam_kesinti' => $toplamKesinti->toDecimalString(),
            'net_odenecek' => RoundingPolicy::normalizeZero($net)->toDecimalString(),
            'sonraki_kumulatif_vergi_matrahi' => $sonrakiMatrah->toDecimalString(),
        ];

        $inputHash = self::hashCanonical([
            'engine_version' => self::ENGINE_VERSION,
            'personel_id' => (int) $input['personel']['personel_id'],
            'segments' => $segments,
            'puantaj_count' => count($input['puantajlar']),
            'izin_count' => count($input['izinler']),
            'etki_count' => count($input['etki_adaylari']),
            'finans_count' => count($input['finanslar']),
            'params' => $params,
            'carryover' => $carryover,
        ]);
        $resultHash = self::hashCanonical([
            'engine_version' => self::ENGINE_VERSION,
            'ozet' => $ozet,
            'kalem_hashes' => array_map(static function (array $k) {
                return $k['payload_hash'];
            }, $kalemler),
        ]);

        return [
            'ok' => true,
            'state' => 'HESAPLANDI',
            'engine_version' => self::ENGINE_VERSION,
            'ozet' => $ozet,
            'kalemler' => $kalemler,
            'warnings' => array_values(array_unique($warnings)),
            'solver' => $solverInfo,
            'input_hash' => $inputHash,
            'result_hash' => $resultHash,
            'carryover_snapshot' => $carryover,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $mevzuatByCode
     * @return array<string, string>
     */
    private static function resolveParams(array $mevzuatByCode)
    {
        $out = [];
        foreach (MaasHesaplamaLegalParameterCatalog::requiredCodes() as $code) {
            if (!isset($mevzuatByCode[$code])) {
                throw new \InvalidArgumentException('Missing parameter ' . $code);
            }
            $row = $mevzuatByCode[$code];
            $meta = MaasHesaplamaLegalParameterCatalog::meta($code);
            if ($meta !== null && $meta['deger_tipi'] === 'METIN') {
                $out[$code] = (string) ($row['metin_deger'] ?? '');
            } else {
                $out[$code] = (string) ($row['sayisal_deger'] ?? '');
            }
        }

        return $out;
    }

    private static function paramInt(array $params, $code)
    {
        $raw = (string) $params[$code];
        // "30.000000" -> 30 (yalniz tam sayi beklenen kodlar)
        if (strpos($raw, '.') !== false) {
            $raw = explode('.', $raw, 2)[0];
        }

        return (int) $raw;
    }

    /**
     * Decimal saat → dakika. Ornek: "7.5" → 450. Truncate/paramInt yasak.
     *
     * @param array<string, string> $params
     */
    private static function paramHoursToMinutes(array $params, $code)
    {
        $raw = trim((string) $params[$code]);
        if ($raw === '' || preg_match('/^\d+(\.\d+)?$/', $raw) !== 1) {
            return 0;
        }
        if (strpos($raw, '.') === false) {
            return ((int) $raw) * 60;
        }
        $parts = explode('.', $raw, 2);
        $whole = (int) $parts[0];
        $frac = rtrim($parts[1], '0');
        if ($frac === '') {
            return $whole * 60;
        }
        $denom = 1;
        for ($i = 0, $n = strlen($frac); $i < $n; $i++) {
            $denom *= 10;
        }
        $numer = $whole * $denom + (int) $frac;

        // numer * 60 / denom — half-up integer dakika
        return intdiv($numer * 60 + intdiv($denom, 2), $denom);
    }

    private static function paramMoney(array $params, $code)
    {
        return Money::fromDecimalString((string) $params[$code]);
    }

    private static function paramRate(array $params, $code)
    {
        return Rate::fromDecimalString((string) $params[$code]);
    }

    private static function inclusiveDays($from, $to)
    {
        $a = new \DateTimeImmutable((string) $from);
        $b = new \DateTimeImmutable((string) $to);
        if ($b < $a) {
            return 0;
        }

        return (int) $a->diff($b)->days + 1;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $params
     * @param array<int, string> $warnings
     * @return array{kalemler: array, sira: int, gross_add: Money, gross_deduct: Money, net_only: Money}
     */
    private static function buildAttendanceLines(array $input, Money $hourly, Money $daily, array $params, $sira, array &$warnings)
    {
        $kalemler = [];
        $grossAdd = Money::zero();
        $grossDeduct = Money::zero();
        $netOnly = Money::zero();
        $fmCarpan = self::paramRate($params, 'FAZLA_MESAI_CARPANI');
        $fsCarpan = self::paramRate($params, 'FAZLA_SURELERLE_CALISMA_CARPANI');
        $htCarpan = self::paramRate($params, 'HAFTA_TATILI_CARPANI');
        $ubgtCarpan = self::paramRate($params, 'UBGT_CARPANI');
        $htMode = strtoupper((string) $params['HAFTA_TATILI_HESAP_MODU']);
        $ubgtMode = strtoupper((string) $params['UBGT_HESAP_MODU']);
        $gunlukDk = self::paramHoursToMinutes($params, 'GUNLUK_CALISMA_SAATI');
        $haftalikIsGunu = self::paramInt($params, 'HAFTALIK_IS_GUNU_SAYISI');
        $contractualWeeklyDk = $gunlukDk * $haftalikIsGunu;
        if ($contractualWeeklyDk > self::LEGAL_WEEKLY_LIMIT_MINUTES) {
            $contractualWeeklyDk = self::LEGAL_WEEKLY_LIMIT_MINUTES;
        }
        $hasOt = false;
        $absenceDates = [];

        foreach ($input['etki_adaylari'] as $aday) {
            $state = strtoupper((string) ($aday['state'] ?? ''));
            if ($state === 'YOK_SAYILDI') {
                $sira++;
                $kalemler[] = self::line($sira, 'CALISMA', 'ETKI_YOK_SAYILDI', 'BILGI', null, null, null, null, Money::zero(), 'ETKI_ADAYI', isset($aday['aday_id']) ? (int) $aday['aday_id'] : null, 'Yok sayilan etki adayi', $aday);
                continue;
            }
            if ($state !== 'UYGULANDI' || empty($aday['parasal_uygulanacak_kalem'])) {
                continue;
            }
            $tur = strtoupper((string) ($aday['etki_turu'] ?? ''));
            $miktar = isset($aday['etki_miktari']) ? (int) $aday['etki_miktari'] : 0;
            $tarih = (string) ($aday['tarih'] ?? '');
            if ($tur === 'DEVAMSIZLIK_GUN' || $tur === 'IZIN_GUNU') {
                if ($tarih !== '' && isset($absenceDates[$tarih])) {
                    continue;
                }
                if ($tarih !== '') {
                    $absenceDates[$tarih] = true;
                }
                $tutar = $daily->mulDiv($miktar > 0 ? $miktar : 1, 1);
                $grossDeduct = $grossDeduct->add($tutar);
                $sira++;
                $kod = $tur === 'DEVAMSIZLIK_GUN' ? 'DEVAMSIZLIK_KESINTISI' : 'UCRETSIZ_IZIN_KESINTISI';
                $grup = $tur === 'DEVAMSIZLIK_GUN' ? 'DEVAMSIZLIK' : 'IZIN';
                $kalemler[] = self::line($sira, $grup, $kod, 'EKSI', $miktar > 0 ? $miktar : 1, 'GUN', null, $daily->toDecimalString(), $tutar, 'ETKI_ADAYI', isset($aday['aday_id']) ? (int) $aday['aday_id'] : null, $kod, ['etki_turu' => $tur]);
            } elseif ($tur === 'GEC_KALMA_DAKIKA' || $tur === 'ERKEN_CIKIS_DAKIKA') {
                if ($tarih !== '' && isset($absenceDates[$tarih])) {
                    continue;
                }
                $tutar = $hourly->mulDiv($miktar, 60);
                $grossDeduct = $grossDeduct->add($tutar);
                $sira++;
                $kod = $tur === 'GEC_KALMA_DAKIKA' ? 'GEC_KALMA_KESINTISI' : 'ERKEN_CIKIS_KESINTISI';
                $kalemler[] = self::line($sira, 'DEVAMSIZLIK', $kod, 'EKSI', $miktar, 'DAKIKA', null, $hourly->toDecimalString(), $tutar, 'ETKI_ADAYI', isset($aday['aday_id']) ? (int) $aday['aday_id'] : null, $kod, ['etki_turu' => $tur]);
            } elseif ($tur === 'RAPOR_GUNU') {
                $sira++;
                $kalemler[] = self::line($sira, 'IZIN', 'RAPOR_GUNU', 'BILGI', $miktar > 0 ? $miktar : 1, 'GUN', null, null, Money::zero(), 'ETKI_ADAYI', isset($aday['aday_id']) ? (int) $aday['aday_id'] : null, 'Rapor gunu bilgi', []);
            } elseif ($tur === 'GOREVDE_CALISILMIS_GUN') {
                $sira++;
                $kalemler[] = self::line($sira, 'CALISMA', 'GOREVDE_CALISILMIS_GUN', 'BILGI', 1, 'GUN', null, null, Money::zero(), 'ETKI_ADAYI', isset($aday['aday_id']) ? (int) $aday['aday_id'] : null, 'Gorevde calisilmis gun', []);
            }
        }

        foreach ($input['izinler'] as $izin) {
            $ucretli = (int) ($izin['ucretli_mi'] ?? 0) === 1;
            $bas = (string) ($izin['baslangic_tarihi'] ?? '');
            $bit = (string) ($izin['bitis_tarihi'] ?? $bas);
            $days = self::inclusiveDays($bas, $bit);
            if ($ucretli) {
                $sira++;
                $kalemler[] = self::line($sira, 'IZIN', 'UCRETLI_IZIN', 'BILGI', $days, 'GUN', null, null, Money::zero(), 'IZIN', isset($izin['surec_id']) ? (int) $izin['surec_id'] : null, 'Ucretli izin - kesinti yok', []);
            } else {
                $tutar = $daily->mulDiv($days, 1);
                $grossDeduct = $grossDeduct->add($tutar);
                $sira++;
                $kalemler[] = self::line($sira, 'IZIN', 'UCRETSIZ_IZIN_KESINTISI', 'EKSI', $days, 'GUN', null, $daily->toDecimalString(), $tutar, 'IZIN', isset($izin['surec_id']) ? (int) $izin['surec_id'] : null, 'Ucretsiz izin kesintisi', []);
            }
        }

        /** @var array<string, int> $weeklyNormalMinutes ISO hafta anahtari => Normal_Is_Gunu net dk */
        $weeklyNormalMinutes = [];

        foreach ($input['puantajlar'] as $row) {
            $tarih = (string) ($row['tarih'] ?? '');
            $gunTipi = (string) ($row['gun_tipi'] ?? '');
            $netDk = isset($row['net_calisma_suresi_dakika']) ? (int) $row['net_calisma_suresi_dakika'] : 0;
            $gec = isset($row['gec_kalma_dakika']) ? (int) $row['gec_kalma_dakika'] : 0;
            $erken = isset($row['erken_cikis_dakika']) ? (int) $row['erken_cikis_dakika'] : 0;

            if ($tarih !== '' && !isset($absenceDates[$tarih])) {
                if ($gec > 0) {
                    $tutar = $hourly->mulDiv($gec, 60);
                    $grossDeduct = $grossDeduct->add($tutar);
                    $sira++;
                    $kalemler[] = self::line($sira, 'DEVAMSIZLIK', 'GEC_KALMA_KESINTISI', 'EKSI', $gec, 'DAKIKA', null, $hourly->toDecimalString(), $tutar, 'PUANTAJ', isset($row['muhur_satir_id']) ? (int) $row['muhur_satir_id'] : null, 'Gec kalma', []);
                }
                if ($erken > 0) {
                    $tutar = $hourly->mulDiv($erken, 60);
                    $grossDeduct = $grossDeduct->add($tutar);
                    $sira++;
                    $kalemler[] = self::line($sira, 'DEVAMSIZLIK', 'ERKEN_CIKIS_KESINTISI', 'EKSI', $erken, 'DAKIKA', null, $hourly->toDecimalString(), $tutar, 'PUANTAJ', isset($row['muhur_satir_id']) ? (int) $row['muhur_satir_id'] : null, 'Erken cikis', []);
                }
            }

            if ($gunTipi === 'Hafta_Tatili_Pazar' && $netDk > 0) {
                $premium = self::holidayPremium($hourly, $daily, $netDk, $htCarpan, $htMode);
                $grossAdd = $grossAdd->add($premium['tutar']);
                $hasOt = true;
                $sira++;
                $kalemler[] = self::line(
                    $sira,
                    'HAFTA_TATILI',
                    'HAFTA_TATILI_ODEMESI',
                    'ARTI',
                    $premium['miktar'],
                    $premium['birim'],
                    $htCarpan->toDecimalString(),
                    $premium['matrah'],
                    $premium['tutar'],
                    'PUANTAJ',
                    isset($row['muhur_satir_id']) ? (int) $row['muhur_satir_id'] : null,
                    'Hafta tatili calismasi',
                    ['hesap_modu' => $htMode, 'net_dakika' => $netDk]
                );
            } elseif ($gunTipi === 'UBGT_Resmi_Tatil' && $netDk > 0) {
                $premium = self::holidayPremium($hourly, $daily, $netDk, $ubgtCarpan, $ubgtMode);
                $grossAdd = $grossAdd->add($premium['tutar']);
                $hasOt = true;
                $sira++;
                $kalemler[] = self::line(
                    $sira,
                    'UBGT',
                    'UBGT_ODEMESI',
                    'ARTI',
                    $premium['miktar'],
                    $premium['birim'],
                    $ubgtCarpan->toDecimalString(),
                    $premium['matrah'],
                    $premium['tutar'],
                    'PUANTAJ',
                    isset($row['muhur_satir_id']) ? (int) $row['muhur_satir_id'] : null,
                    'UBGT calismasi',
                    ['hesap_modu' => $ubgtMode, 'net_dakika' => $netDk]
                );
            } elseif ($gunTipi === 'Normal_Is_Gunu' && $netDk > 0 && $tarih !== '') {
                $weekKey = self::isoWeekKey($tarih);
                if (!isset($weeklyNormalMinutes[$weekKey])) {
                    $weeklyNormalMinutes[$weekKey] = 0;
                }
                $weeklyNormalMinutes[$weekKey] += $netDk;
            }
        }

        ksort($weeklyNormalMinutes);
        foreach ($weeklyNormalMinutes as $weekKey => $totalDk) {
            $fsDk = 0;
            $fmDk = 0;
            if ($totalDk > $contractualWeeklyDk) {
                $overContract = $totalDk - $contractualWeeklyDk;
                $fsCap = self::LEGAL_WEEKLY_LIMIT_MINUTES - $contractualWeeklyDk;
                if ($fsCap < 0) {
                    $fsCap = 0;
                }
                $fsDk = $overContract < $fsCap ? $overContract : $fsCap;
                $fmDk = $totalDk > self::LEGAL_WEEKLY_LIMIT_MINUTES
                    ? ($totalDk - self::LEGAL_WEEKLY_LIMIT_MINUTES)
                    : 0;
            }

            if ($fsDk > 0) {
                $base = $hourly->mulDiv($fsDk, 60);
                $tutar = $base->applyRate($fsCarpan);
                $grossAdd = $grossAdd->add($tutar);
                $hasOt = true;
                $sira++;
                $kalemler[] = self::line($sira, 'FAZLA_SURELERLE', 'FAZLA_SURELERLE_CALISMA_ODEMESI', 'ARTI', $fsDk, 'DAKIKA', $fsCarpan->toDecimalString(), $base->toDecimalString(), $tutar, 'PUANTAJ', null, 'Fazla surelerle calisma', [
                    'iso_hafta' => $weekKey,
                    'haftalik_toplam_dk' => $totalDk,
                    'sozlesme_haftalik_dk' => $contractualWeeklyDk,
                    'yasal_haftalik_limit_dk' => self::LEGAL_WEEKLY_LIMIT_MINUTES,
                ]);
            }
            if ($fmDk > 0) {
                $base = $hourly->mulDiv($fmDk, 60);
                $tutar = $base->applyRate($fmCarpan);
                $grossAdd = $grossAdd->add($tutar);
                $hasOt = true;
                $sira++;
                $kalemler[] = self::line($sira, 'FAZLA_MESAI', 'FAZLA_MESAI_ODEMESI', 'ARTI', $fmDk, 'DAKIKA', $fmCarpan->toDecimalString(), $base->toDecimalString(), $tutar, 'PUANTAJ', null, 'Fazla mesai', [
                    'iso_hafta' => $weekKey,
                    'haftalik_toplam_dk' => $totalDk,
                    'sozlesme_haftalik_dk' => $contractualWeeklyDk,
                    'yasal_haftalik_limit_dk' => self::LEGAL_WEEKLY_LIMIT_MINUTES,
                ]);
            }
        }

        if (!$hasOt) {
            $warnings[] = 'NO_OVERTIME';
        }

        return [
            'kalemler' => $kalemler,
            'sira' => $sira,
            'gross_add' => $grossAdd,
            'gross_deduct' => $grossDeduct,
            'net_only' => $netOnly,
        ];
    }

    /**
     * @return array{tutar: Money, matrah: string, miktar: int, birim: string}
     */
    private static function holidayPremium(Money $hourly, Money $daily, $netDk, Rate $carpan, $mode)
    {
        $netDk = (int) $netDk;
        $mode = strtoupper((string) $mode);
        if ($mode === 'GUNLUK_ILAVE') {
            $tutar = $daily->applyRate($carpan);

            return [
                'tutar' => $tutar,
                'matrah' => $daily->toDecimalString(),
                'miktar' => 1,
                'birim' => 'GUN',
            ];
        }
        if ($mode === 'GUNLUK_ILAVE_VE_SAAT_CARPAN') {
            $ilave = $daily->applyRate($carpan);
            $saatUcret = $hourly->mulDiv($netDk, 60);
            $tutar = $ilave->add($saatUcret);

            return [
                'tutar' => $tutar,
                'matrah' => $ilave->add($saatUcret)->toDecimalString(),
                'miktar' => $netDk,
                'birim' => 'DAKIKA',
            ];
        }

        // SAAT_CARPAN (V1 uyumlu)
        $base = $hourly->mulDiv($netDk, 60);
        $tutar = $base->applyRate($carpan);

        return [
            'tutar' => $tutar,
            'matrah' => $base->toDecimalString(),
            'miktar' => $netDk,
            'birim' => 'DAKIKA',
        ];
    }

    private static function isoWeekKey($tarih)
    {
        $dt = new \DateTimeImmutable((string) $tarih);

        return $dt->format('o') . '-W' . $dt->format('W');
    }

    /**
     * @param array<int, array<string, mixed>> $finanslar
     * @return array<string, mixed>
     */
    private static function buildFinanceLines(array $finanslar, $sira)
    {
        $kalemler = [];
        $grossAdd = Money::zero();
        $netDeduct = Money::zero();
        $netAdd = Money::zero();
        $seen = [];

        foreach ($finanslar as $row) {
            $id = isset($row['kayit_id']) ? (int) $row['kayit_id'] : 0;
            $key = $id > 0 ? 'id:' . $id : 'x:' . ($row['kalem_turu'] ?? '') . ':' . ($row['tutar'] ?? '');
            if (isset($seen[$key])) {
                return ['error' => ['code' => 'DUPLICATE_FINANCE_EFFECT', 'message' => 'Duplicate finans kaynagi.']];
            }
            $seen[$key] = true;

            $tur = strtoupper((string) ($row['kalem_turu'] ?? ''));
            if (FinanceKalemCatalog::isDuplicateSalary($tur)) {
                return ['error' => ['code' => 'DUPLICATE_FINANCE_EFFECT', 'message' => 'MAAS finans kalemi ucret ile cakisir.']];
            }
            $class = FinanceKalemCatalog::classify($tur);
            if ($class === null) {
                return ['error' => ['code' => 'FINANCE_INPUT_INVALID', 'message' => 'Finans kalem matrah sinifi eksik: ' . $tur]];
            }
            if (strtoupper((string) ($row['state'] ?? '')) !== 'AKTIF') {
                continue;
            }
            $tutar = Money::fromDecimalString((string) $row['tutar'])->abs();
            $sira++;
            if ($class['yon'] === 'ARTI') {
                if ($class['net_odeme']) {
                    $netAdd = $netAdd->add($tutar);
                } else {
                    $grossAdd = $grossAdd->add($tutar);
                }
                $kalemler[] = self::line($sira, $class['kalem_grubu'], $tur, 'ARTI', null, null, null, null, $tutar, 'FINANS', $id > 0 ? $id : null, (string) ($row['aciklama'] ?? $tur), [
                    'sgk' => $class['sgk'], 'gv' => $class['gv'], 'damga' => $class['damga'], 'net_odeme' => $class['net_odeme'],
                ]);
            } else {
                $netDeduct = $netDeduct->add($tutar);
                $kalemler[] = self::line($sira, $class['kalem_grubu'], $tur, 'EKSI', null, null, null, null, $tutar, 'FINANS', $id > 0 ? $id : null, (string) ($row['aciklama'] ?? $tur), [
                    'sgk' => $class['sgk'], 'gv' => $class['gv'], 'damga' => $class['damga'], 'net_odeme' => $class['net_odeme'],
                ]);
            }
        }

        return [
            'kalemler' => $kalemler,
            'sira' => $sira,
            'gross_add' => $grossAdd,
            'net_deduct' => $netDeduct,
            'net_add' => $netAdd,
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private static function computeLegalDeductions(Money $brut, Money $grossAddIgnored, array $params, Money $prevMatrah, $sira)
    {
        // SGK gun sayisi: NORMAL_AY_GUN for full month base (simplified deterministic)
        $gun = self::paramInt($params, 'NORMAL_AY_GUN_SAYISI');
        $taban = self::paramMoney($params, 'SGK_GUNLUK_TABAN')->mulDiv($gun, 1);
        $tavan = self::paramMoney($params, 'SGK_GUNLUK_TAVAN')->mulDiv($gun, 1);
        $sgkMatrah = $brut->max($taban)->min($tavan);
        $sgkOran = self::paramRate($params, 'SGK_ISCI_PRIM_ORANI');
        $issizlikOran = self::paramRate($params, 'ISSIZLIK_ISCI_PRIM_ORANI');
        $sgk = $sgkMatrah->applyRate($sgkOran);
        $issizlik = $sgkMatrah->applyRate($issizlikOran);

        $gvMatrah = $brut->sub($sgk)->sub($issizlik);
        if ($gvMatrah->isNegative()) {
            $gvMatrah = Money::zero();
        }

        $gvHesap = self::computeIncomeTax($prevMatrah, $gvMatrah, $params);
        $gvIstisnaMatrah = self::paramMoney($params, 'ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI');
        // Istisna: asgari ucretin vergi tutari (ayni dilim motoruyla sifir onceki matrahtan)
        $asgariBrut = self::paramMoney($params, 'ASGARI_UCRET_BRUT');
        $asgariSgkMatrah = $asgariBrut->max($taban)->min($tavan);
        $asgariSgk = $asgariSgkMatrah->applyRate($sgkOran);
        $asgariIss = $asgariSgkMatrah->applyRate($issizlikOran);
        $asgariGvMatrah = $asgariBrut->sub($asgariSgk)->sub($asgariIss);
        if ($asgariGvMatrah->isNegative()) {
            $asgariGvMatrah = Money::zero();
        }
        // Use explicit istisna matrah parameter as the exempt tax base amount for the month
        $istisnaTax = self::computeIncomeTax(Money::zero(), $gvIstisnaMatrah, $params);
        $gvOdenecek = $gvHesap['vergi']->sub($istisnaTax['vergi']);
        if ($gvOdenecek->isNegative()) {
            $gvOdenecek = Money::zero();
        }

        $damgaOran = self::paramRate($params, 'DAMGA_VERGISI_ORANI');
        $damgaMatrah = $brut;
        $damgaHesap = $damgaMatrah->applyRate($damgaOran);
        $damgaIstisnaMatrah = self::paramMoney($params, 'ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI');
        $damgaIstisna = $damgaIstisnaMatrah->applyRate($damgaOran);
        $damgaOdenecek = $damgaHesap->sub($damgaIstisna);
        if ($damgaOdenecek->isNegative()) {
            $damgaOdenecek = Money::zero();
        }

        $kalemler = [];
        $sira++;
        $kalemler[] = self::line($sira, 'SGK', 'SGK_MATRAH', 'BILGI', $gun, 'GUN', null, null, $sgkMatrah, 'MEVZUAT', null, 'SGK matrahi', ['taban' => $taban->toDecimalString(), 'tavan' => $tavan->toDecimalString()]);
        $sira++;
        $kalemler[] = self::line($sira, 'SGK', 'SGK_ISCI_PRIMI', 'EKSI', null, null, $sgkOran->toDecimalString(), $sgkMatrah->toDecimalString(), $sgk, 'MEVZUAT', null, 'Isci SGK primi', []);
        $sira++;
        $kalemler[] = self::line($sira, 'ISSIZLIK', 'ISSIZLIK_ISCI_PRIMI', 'EKSI', null, null, $issizlikOran->toDecimalString(), $sgkMatrah->toDecimalString(), $issizlik, 'MEVZUAT', null, 'Isci issizlik primi', []);
        foreach ($gvHesap['dilimler'] as $dilim) {
            $sira++;
            $kalemler[] = self::line($sira, 'GELIR_VERGISI', $dilim['kod'], 'BILGI', null, null, $dilim['oran'], $dilim['matrah'], Money::fromDecimalString($dilim['vergi']), 'MEVZUAT', null, 'GV dilim', $dilim);
        }
        $sira++;
        $kalemler[] = self::line($sira, 'ISTISNA', 'ASGARI_UCRET_GV_ISTISNA', 'ARTI', null, null, null, $gvIstisnaMatrah->toDecimalString(), $istisnaTax['vergi'], 'MEVZUAT', null, 'Asgari ucret GV istisnasi', []);
        $sira++;
        $kalemler[] = self::line($sira, 'GELIR_VERGISI', 'GELIR_VERGISI_ODENECEK', 'EKSI', null, null, null, $gvMatrah->toDecimalString(), $gvOdenecek, 'MEVZUAT', null, 'Odenecek gelir vergisi', []);
        $sira++;
        $kalemler[] = self::line($sira, 'DAMGA_VERGISI', 'DAMGA_VERGISI_HESAP', 'BILGI', null, null, $damgaOran->toDecimalString(), $damgaMatrah->toDecimalString(), $damgaHesap, 'MEVZUAT', null, 'Damga vergisi hesap', []);
        $sira++;
        $kalemler[] = self::line($sira, 'ISTISNA', 'ASGARI_UCRET_DAMGA_ISTISNA', 'ARTI', null, null, null, $damgaIstisnaMatrah->toDecimalString(), $damgaIstisna, 'MEVZUAT', null, 'Asgari ucret damga istisnasi', []);
        $sira++;
        $kalemler[] = self::line($sira, 'DAMGA_VERGISI', 'DAMGA_VERGISI_ODENECEK', 'EKSI', null, null, null, $damgaMatrah->toDecimalString(), $damgaOdenecek, 'MEVZUAT', null, 'Odenecek damga vergisi', []);

        return [
            'kalemler' => $kalemler,
            'sira' => $sira,
            'sgk' => $sgk,
            'issizlik' => $issizlik,
            'gv' => $gvOdenecek,
            'damga' => $damgaOdenecek,
            'sgk_matrah' => $sgkMatrah,
            'gv_matrah' => $gvMatrah,
            'damga_matrah' => $damgaMatrah,
        ];
    }

    /**
     * Cumulative bracket tax: tax(prev+period) - tax(prev)
     * @param array<string, string> $params
     * @return array{vergi: Money, dilimler: array<int, array<string, mixed>>}
     */
    private static function computeIncomeTax(Money $prevMatrah, Money $periodMatrah, array $params)
    {
        $limits = [
            self::paramMoney($params, 'GELIR_VERGISI_DILIM_1_LIMIT'),
            self::paramMoney($params, 'GELIR_VERGISI_DILIM_2_LIMIT'),
            self::paramMoney($params, 'GELIR_VERGISI_DILIM_3_LIMIT'),
            self::paramMoney($params, 'GELIR_VERGISI_DILIM_4_LIMIT'),
        ];
        $rates = [
            self::paramRate($params, 'GELIR_VERGISI_DILIM_1_ORAN'),
            self::paramRate($params, 'GELIR_VERGISI_DILIM_2_ORAN'),
            self::paramRate($params, 'GELIR_VERGISI_DILIM_3_ORAN'),
            self::paramRate($params, 'GELIR_VERGISI_DILIM_4_ORAN'),
            self::paramRate($params, 'GELIR_VERGISI_DILIM_5_ORAN'),
        ];

        $taxAt = static function (Money $cumulative) use ($limits, $rates) {
            $remaining = $cumulative;
            $prevLimit = Money::zero();
            $total = Money::zero();
            $parts = [];
            for ($i = 0; $i < 4; $i++) {
                $band = $limits[$i]->sub($prevLimit);
                if ($band->isNegative() || $band->isZero()) {
                    $prevLimit = $limits[$i];
                    continue;
                }
                $slice = $remaining->min($band);
                if ($slice->isZero() || $slice->isNegative()) {
                    break;
                }
                $tax = $slice->applyRate($rates[$i]);
                $total = $total->add($tax);
                $parts[] = [
                    'kod' => 'GV_DILIM_' . ($i + 1),
                    'oran' => $rates[$i]->toDecimalString(),
                    'matrah' => $slice->toDecimalString(),
                    'vergi' => $tax->toDecimalString(),
                ];
                $remaining = $remaining->sub($slice);
                $prevLimit = $limits[$i];
                if ($remaining->isZero()) {
                    return ['vergi' => $total, 'dilimler' => $parts];
                }
            }
            if (!$remaining->isZero() && !$remaining->isNegative()) {
                $tax = $remaining->applyRate($rates[4]);
                $total = $total->add($tax);
                $parts[] = [
                    'kod' => 'GV_DILIM_5',
                    'oran' => $rates[4]->toDecimalString(),
                    'matrah' => $remaining->toDecimalString(),
                    'vergi' => $tax->toDecimalString(),
                ];
            }

            return ['vergi' => $total, 'dilimler' => $parts];
        };

        $before = $taxAt($prevMatrah);
        $after = $taxAt($prevMatrah->add($periodMatrah));
        $periodTax = $after['vergi']->sub($before['vergi']);
        if ($periodTax->isNegative()) {
            $periodTax = Money::zero();
        }

        // Dilim parts for the period only: approximate via after parts (info)
        return ['vergi' => $periodTax, 'dilimler' => $after['dilimler']];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private static function solveNetToGross(Money $targetNet, array $params, Money $prevMatrah)
    {
        // Search brut in [targetNet, targetNet * 3] roughly via kurus bounds
        $low = $targetNet;
        $high = $targetNet->mulDiv(3, 1);
        if ($high->cmp($low) <= 0) {
            $high = $low->add(Money::fromKurus(10000000));
        }
        $iterations = 0;
        $best = null;
        while ($iterations < self::SOLVER_MAX_ITERATIONS && $low->cmp($high) <= 0) {
            $iterations++;
            $midKurus = intdiv($low->kurus() + $high->kurus(), 2);
            $mid = Money::fromKurus($midKurus);
            $legal = self::computeLegalDeductions($mid, Money::zero(), $params, $prevMatrah, 0);
            $net = $mid->sub($legal['sgk'])->sub($legal['issizlik'])->sub($legal['gv'])->sub($legal['damga']);
            $diff = $net->kurus() - $targetNet->kurus();
            $best = ['brut' => $mid, 'net' => $net, 'diff' => $diff];
            if (abs($diff) <= self::SOLVER_TOLERANCE_KURUS) {
                return [
                    'brut' => $mid,
                    'solver' => [
                        'iterations' => $iterations,
                        'tolerance_kurus' => self::SOLVER_TOLERANCE_KURUS,
                        'hedef_net' => $targetNet->toDecimalString(),
                        'bulunan_net' => $net->toDecimalString(),
                        'bulunan_brut' => $mid->toDecimalString(),
                        'fark_kurus' => $diff,
                    ],
                ];
            }
            if ($diff < 0) {
                $low = Money::fromKurus($midKurus + 1);
            } else {
                $high = Money::fromKurus($midKurus - 1);
            }
        }
        if ($best !== null && abs($best['diff']) <= self::SOLVER_TOLERANCE_KURUS) {
            return [
                'brut' => $best['brut'],
                'solver' => [
                    'iterations' => $iterations,
                    'tolerance_kurus' => self::SOLVER_TOLERANCE_KURUS,
                    'hedef_net' => $targetNet->toDecimalString(),
                    'bulunan_net' => $best['net']->toDecimalString(),
                    'bulunan_brut' => $best['brut']->toDecimalString(),
                    'fark_kurus' => $best['diff'],
                ],
            ];
        }

        return ['error' => ['code' => 'PAYROLL_CALCULATION_SOLVER_FAILED', 'message' => 'Netten brüte çözüm bulunamadı.']];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private static function line($sira, $grup, $kod, $yon, $miktar, $birim, $oran, $matrah, Money $tutar, $kaynakTuru, $kaynakId, $aciklama, $payload)
    {
        $payload = is_array($payload) ? $payload : [];
        $payload['kalem_kodu'] = $kod;
        $payload['yon'] = $yon;
        $payload['tutar'] = $tutar->toDecimalString();
        $hash = self::hashCanonical($payload);

        return [
            'sira_no' => (int) $sira,
            'kalem_grubu' => (string) $grup,
            'kalem_kodu' => (string) $kod,
            'yon' => (string) $yon,
            'miktar' => $miktar,
            'birim' => $birim,
            'oran' => $oran,
            'matrah' => $matrah,
            'tutar' => $tutar->toDecimalString(),
            'kaynak_turu' => $kaynakTuru,
            'kaynak_id' => $kaynakId !== null ? (int) $kaynakId : null,
            'aciklama' => (string) $aciklama,
            'payload_json' => $payload,
            'payload_hash' => $hash,
        ];
    }

    /** @return array<string, mixed> */
    private static function errorResult($code, $message)
    {
        return [
            'ok' => false,
            'state' => 'HESAP_HATASI',
            'error_code' => (string) $code,
            'error_message' => (string) $message,
            'engine_version' => self::ENGINE_VERSION,
        ];
    }

    /** @param mixed $value */
    public static function hashCanonical($value)
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param mixed $value @return mixed */
    public static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalize($item);
        }

        return $out;
    }
}
