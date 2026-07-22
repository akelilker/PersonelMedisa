<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * S85-B authoritative, side-effect-free SGK prim gunu owner'i.
 *
 * Bu sinif kod/kural tahmini yapmaz. Surec eslemeleri, katalog ve belge
 * kararlari dis owner tarafindan surumlu girdiler olarak verilmelidir.
 */
final class SgkPrimGunuEngine
{
    public const ENGINE_VERSION = 'S85B_SGK_PRIM_GUNU_ENGINE_V1';
    public const CONTRACT_VERSION = 'S85B_SGK_PRIM_GUNU_CONTRACT_V1';

    private const UCRET_MODELLERI = ['MAKTU_AYLIK', 'GUNLUK', 'SAATLIK', 'DIGER'];
    private const RAPOR_TURLERI = ['HASTALIK', 'IS_KAZASI', 'MESLEK_HASTALIGI', 'ANALIK'];
    private const DAHIL_TURLER = ['YILLIK_IZIN'];
    private const DUSUREN_TURLER = [
        'UCRETSIZ_IZIN',
        'MAZERETSIZ_DEVAMSIZLIK',
        'PUANTAJ_EKSIK_GUN',
        'IS_KAZASI',
        'MESLEK_HASTALIGI',
        'ANALIK',
    ];

    /** @return array<string, mixed> */
    public static function calculate(array $input)
    {
        $blockers = [];
        $daily = [];
        $sourceProcessIds = [];
        $sourceAttendanceIds = [];
        $sourceDocumentIds = [];
        $policySummary = [];

        $periodStart = self::date((string) ($input['donem_baslangic'] ?? ''));
        $periodEnd = self::date((string) ($input['donem_bitis'] ?? ''));
        if ($periodStart === null || $periodEnd === null || $periodEnd < $periodStart) {
            $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Bildirim donemi gecersiz.', null, null, null);

            return self::blockedResult($input, $blockers, $daily, $policySummary);
        }

        $personel = is_array($input['personel'] ?? null) ? $input['personel'] : [];
        $employmentStart = self::date((string) ($personel['istihdam_baslangic'] ?? $personel['ise_giris_tarihi'] ?? ''));
        $employmentEnd = self::date((string) ($personel['istihdam_bitis'] ?? $personel['cikis_tarihi'] ?? $periodEnd->format('Y-m-d')));
        if ($employmentStart === null || $employmentEnd === null || $employmentEnd < $employmentStart) {
            $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Istihdam tarih araligi gecersiz.', null, null, null);

            return self::blockedResult($input, $blockers, $daily, $policySummary);
        }
        $employmentStart = $employmentStart > $periodStart ? $employmentStart : $periodStart;
        $employmentEnd = $employmentEnd < $periodEnd ? $employmentEnd : $periodEnd;
        if ($employmentEnd < $employmentStart) {
            $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Personelin bildirim donemiyle istihdam kesişimi yok.', null, null, null);

            return self::blockedResult($input, $blockers, $daily, $policySummary);
        }

        $ucretModeli = strtoupper(trim((string) ($personel['ucret_modeli'] ?? $input['ucret_modeli'] ?? '')));
        if (!in_array($ucretModeli, self::UCRET_MODELLERI, true)) {
            $ucretModeli = 'BELIRSIZ';
            $blockers[] = self::issue('UCRET_MODELI_BELIRSIZ', 'Personelin ucret modeli belirlenemedi.', null, null, null);
        }
        if (trim((string) ($personel['sigortalilik_statusu'] ?? '')) === '') {
            $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Personelin sigortalilik statusu belirlenmedi.', null, null, null);
        }
        if (!in_array((string) ($personel['sozlesme_turu'] ?? ''), ['TAM_SURELI', 'KISMI_SURELI', 'DIGER'], true)) {
            $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Personelin canonical sozlesme turu belirlenmedi.', null, null, null);
        }
        if (!in_array((string) ($input['bildirim_donem_tipi'] ?? ''), ['AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14'], true)) {
            $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Sirketin SGK bildirim donem tipi belirlenmedi.', null, null, null);
        }
        if (!empty($input['sirket_politikasi_gerekli_mi'])
            && preg_match('/^[0-9a-f]{64}$/', (string) ($input['sirket_politika_hash'] ?? '')) !== 1) {
            $blockers[] = self::issue('SGK_ODENEK_MAHSUP_POLITIKASI_EKSIK', 'Raporlu donem icin onayli SGK odenek/mahsup politikasi yok.', null, null, null);
        }

        $catalog = is_array($input['katalog'] ?? null) ? $input['katalog'] : [];
        $catalogReady = (string) ($catalog['state'] ?? '') === 'ONAYLANDI'
            && (string) ($catalog['tamlik_durumu'] ?? '') === 'DOGRULANMIS_TAM'
            && preg_match('/^[0-9a-f]{64}$/', (string) ($catalog['manifest_hash'] ?? '')) === 1;
        if (!$catalogReady) {
            $blockers[] = self::issue('SGK_KATALOG_SURUMU_GECERSIZ', 'Onayli ve tamligi dogrulanmis SGK kod katalog surumu yok.', null, null, null);
        }
        $codes = is_array($catalog['kodlar'] ?? null) ? $catalog['kodlar'] : [];
        $conflicts = is_array($catalog['cakismalar'] ?? null) ? $catalog['cakismalar'] : [];

        $attendanceByDate = [];
        foreach (is_array($input['puantajlar'] ?? null) ? $input['puantajlar'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = (string) ($row['tarih'] ?? '');
            if (self::date($date) === null) {
                continue;
            }
            if (isset($attendanceByDate[$date])) {
                $blockers[] = self::issue('SGK_KAYNAK_SUREC_CELISKILI', 'Ayni tarih icin birden fazla canonical puantaj kaydi var.', $date, $date, null);
                continue;
            }
            $attendanceByDate[$date] = $row;
            if (isset($row['muhur_satir_id'])) {
                $sourceAttendanceIds[(int) $row['muhur_satir_id']] = true;
            }
        }

        $processes = [];
        foreach (is_array($input['surecler'] ?? null) ? $input['surecler'] : [] as $process) {
            if (!is_array($process)) {
                continue;
            }
            $from = self::date((string) ($process['baslangic_tarihi'] ?? ''));
            $to = self::date((string) ($process['bitis_tarihi'] ?? $process['baslangic_tarihi'] ?? ''));
            if ($from === null || $to === null || $to < $from || $to < $employmentStart || $from > $employmentEnd) {
                continue;
            }
            $canonical = strtoupper(trim((string) ($process['canonical_surec_turu'] ?? '')));
            if ($canonical === '' || $canonical === 'DIGER_MANUEL_INCELEME') {
                $blockers[] = self::issue(
                    'RAPOR_TURU_BELIRSIZ',
                    'Surec, surumlu SGK taksonomisine kesin olarak eslenemedi.',
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                    isset($process['surec_id']) ? (int) $process['surec_id'] : null
                );
            }
            $process['__from'] = $from;
            $process['__to'] = $to;
            $process['__canonical'] = $canonical;
            $processes[] = $process;
            if (isset($process['surec_id'])) {
                $sourceProcessIds[(int) $process['surec_id']] = true;
            }
            foreach (is_array($process['kaynak_belge_idleri'] ?? null) ? $process['kaynak_belge_idleri'] : [] as $documentId) {
                $sourceDocumentIds[(int) $documentId] = true;
            }
        }

        $missingDates = [];
        $missingCodes = [];
        $reportSeen = false;
        foreach (self::dateRange($employmentStart, $employmentEnd) as $date) {
            $dateString = $date->format('Y-m-d');
            $attendance = $attendanceByDate[$dateString] ?? null;
            $dayProcesses = [];
            foreach ($processes as $process) {
                if ($process['__from'] <= $date && $process['__to'] >= $date) {
                    $dayProcesses[] = $process;
                }
            }

            $decision = [
                'tarih' => $dateString,
                'karar' => 'DAHIL',
                'neden' => 'FIILI_VEYA_UCRET_ODENEN_GUN',
                'kaynak_surec_idleri' => [],
                'kaynak_puantaj_idi' => is_array($attendance) && isset($attendance['muhur_satir_id']) ? (int) $attendance['muhur_satir_id'] : null,
                'eksik_gun_kodlari' => [],
            ];

            if (count($dayProcesses) > 0) {
                $dayEffects = [];
                foreach ($dayProcesses as $process) {
                    $canonical = (string) $process['__canonical'];
                    $processId = isset($process['surec_id']) ? (int) $process['surec_id'] : null;
                    if ($processId !== null) {
                        $decision['kaynak_surec_idleri'][] = $processId;
                    }

                    $effect = strtoupper((string) ($process['prim_gunu_etkisi'] ?? ''));
                    if ($effect === 'KOSULLU') {
                        $effect = strtoupper((string) ($process['cozulmus_prim_gunu_etkisi'] ?? ''));
                    }
                    if ($effect === '' && in_array($canonical, self::DAHIL_TURLER, true)) {
                        $effect = 'DAHIL';
                    } elseif ($effect === '' && in_array($canonical, self::DUSUREN_TURLER, true)) {
                        $effect = 'DUSUR';
                    }

                    if ($canonical === 'HASTALIK') {
                        $reportSeen = true;
                        $policy = array_key_exists('ilk_iki_gun_firma_oder_mi', $process)
                            ? $process['ilk_iki_gun_firma_oder_mi']
                            : null;
                        $policySummary[] = [
                            'surec_id' => $processId,
                            'ilk_iki_gun_firma_oder_mi' => $policy,
                        ];
                        if ($policy === null) {
                            $blockers[] = self::issue(
                                'HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK',
                                'Hastalik surecinde ilk iki gun sirket karari null olamaz.',
                                $dateString,
                                $dateString,
                                $processId
                            );
                            $effect = 'MANUEL';
                        } else {
                            $reportDay = ((int) $process['__from']->diff($date)->format('%a')) + 1;
                            $effect = $reportDay <= 2 && $policy === true ? 'DAHIL' : 'DUSUR';
                        }
                    } elseif (in_array($canonical, ['IS_KAZASI', 'MESLEK_HASTALIGI', 'ANALIK'], true)) {
                        $reportSeen = true;
                        if (array_key_exists('ilk_iki_gun_firma_oder_mi', $process) && $process['ilk_iki_gun_firma_oder_mi'] !== null) {
                            $blockers[] = self::issue(
                                'SGK_KAYNAK_SUREC_CELISKILI',
                                'Ilk iki gun politikasi hastalik disindaki rapor turune uygulanamaz.',
                                $dateString,
                                $dateString,
                                $processId
                            );
                        }
                    }

                    if ($canonical === 'KISMI_SURELI_CALISMA') {
                        if ((string) ($personel['sozlesme_turu'] ?? '') !== 'KISMI_SURELI') {
                            $blockers[] = self::issue('SGK_KAYNAK_SUREC_CELISKILI', 'Kismi sureli hesap icin canonical sozlesme turu yok.', $dateString, $dateString, $processId);
                            $effect = 'MANUEL';
                        }
                        if (empty($process['sozlesme_belgesi_dogrulandi_mi'])) {
                            $blockers[] = self::issue('SGK_EKSIK_GUN_BELGESI_EKSIK', 'Kismi sureli sozlesme belgesi dogrulanmadi.', $dateString, $dateString, $processId);
                            $effect = 'MANUEL';
                        }
                    }

                    if (!in_array($effect, ['DAHIL', 'DUSUR'], true)) {
                        $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Surecin prim gunu etkisi kesinlestirilemedi.', $dateString, $dateString, $processId);
                        $effect = 'MANUEL';
                    }
                    $dayEffects[] = $effect;

                    if ($effect === 'DUSUR') {
                        $code = trim((string) ($process['eksik_gun_kodu'] ?? ''));
                        if ($code === '') {
                            $blockers[] = self::issue('SGK_EKSIK_GUN_KODU_BULUNAMADI', 'Eksik gun sureci resmi koda eslenemedi.', $dateString, $dateString, $processId);
                        } else {
                            $decision['eksik_gun_kodlari'][] = $code;
                            $missingCodes[$code] = (string) $code;
                            self::validateCodeAndDocuments($code, $codes, $process, $dateString, $blockers);
                        }
                    }
                }

                $hasDrop = in_array('DUSUR', $dayEffects, true);
                $hasInclude = in_array('DAHIL', $dayEffects, true);
                if ($hasDrop && $hasInclude && count($dayProcesses) > 1) {
                    $blockers[] = self::issue('SGK_KAYNAK_SUREC_CELISKILI', 'Ayni gun icin dahil ve dusur kararli surecler cakisti.', $dateString, $dateString, null);
                }
                if ($hasDrop) {
                    $decision['karar'] = 'EKSIK';
                    $decision['neden'] = 'CANONICAL_SUREC';
                    $missingDates[$dateString] = true;
                } elseif (in_array('MANUEL', $dayEffects, true)) {
                    $decision['karar'] = 'BELIRSIZ';
                    $decision['neden'] = 'MANUEL_INCELEME';
                }
            } else {
                self::decideFromAttendance($attendance, $dateString, $decision, $missingDates, $blockers);
            }

            $decision['kaynak_surec_idleri'] = array_values(array_unique($decision['kaynak_surec_idleri']));
            $decision['eksik_gun_kodlari'] = array_values(array_unique($decision['eksik_gun_kodlari']));
            $daily[] = $decision;
        }

        $missingCodeList = array_values($missingCodes);
        sort($missingCodeList, SORT_STRING);
        $resolvedCode = null;
        if (count($missingDates) > 0) {
            if (count($missingCodeList) === 1) {
                $resolvedCode = $missingCodeList[0];
            } elseif (count($missingCodeList) > 1) {
                $setHash = self::hashCanonical($missingCodeList);
                $conflict = $conflicts[$setHash] ?? null;
                if (!is_array($conflict) || trim((string) ($conflict['sonuc_eksik_gun_kodu'] ?? '')) === '') {
                    $blockers[] = self::issue('SGK_EKSIK_GUN_KODU_CAKISTI', 'Birden fazla eksik gun nedeni resmi birleşik koda cozumlenemedi.', $employmentStart->format('Y-m-d'), $employmentEnd->format('Y-m-d'), null);
                } else {
                    $resolvedCode = (string) $conflict['sonuc_eksik_gun_kodu'];
                }
            } else {
                $blockers[] = self::issue('SGK_EKSIK_GUN_KODU_BULUNAMADI', 'Eksik gun var ancak resmi neden kodu yok.', $employmentStart->format('Y-m-d'), $employmentEnd->format('Y-m-d'), null);
            }
        }

        $employmentDayCount = self::inclusiveDays($employmentStart, $employmentEnd);
        $paidDays = max(0, $employmentDayCount - count($missingDates));
        $fullPeriodEmployment = $employmentStart == $periodStart && $employmentEnd == $periodEnd;
        $primDay = $fullPeriodEmployment && count($missingDates) === 0 ? 30 : min(30, $paidDays);

        if ((string) ($personel['sozlesme_turu'] ?? '') === 'KISMI_SURELI') {
            $explicitPartialDay = $input['kismi_sureli_prim_gunu'] ?? null;
            if (!is_int($explicitPartialDay) || $explicitPartialDay < 0 || $explicitPartialDay > 30) {
                $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Kismi sureli prim gunu ayri owner tarafindan kesinlestirilmedi.', $employmentStart->format('Y-m-d'), $employmentEnd->format('Y-m-d'), null);
            } else {
                $primDay = $explicitPartialDay;
            }
        }

        if ($resolvedCode !== null && isset($codes[$resolvedCode])) {
            $codeMeta = $codes[$resolvedCode];
            $zeroEarnings = array_key_exists('sifir_kazanc_mi', $input) ? $input['sifir_kazanc_mi'] : null;
            if ($primDay === 0) {
                if ($zeroEarnings === null || $zeroEarnings === '') {
                    $blockers[] = self::issue(
                        'SGK_PRIM_GUNU_HESAPLANAMADI',
                        '0 prim gunu icin sifir kazanc durumu kesinlestirilmedi; tahmin uretilmedi.',
                        $employmentStart->format('Y-m-d'),
                        $employmentEnd->format('Y-m-d'),
                        null
                    );
                } elseif ($zeroEarnings === true && empty($codeMeta['sifir_gun_sifir_kazanc_kullanilabilir_mi'])) {
                    $blockers[] = self::issue(
                        'SGK_EKSIK_GUN_KODU_CAKISTI',
                        $resolvedCode . ' kodu 0 gun/0 kazanc bildiriminde kullanilamaz.',
                        $employmentStart->format('Y-m-d'),
                        $employmentEnd->format('Y-m-d'),
                        null
                    );
                }
            }
            if (!empty($codeMeta['kismi_sureli_sozlesme_gerekli_mi']) && (string) ($personel['sozlesme_turu'] ?? '') !== 'KISMI_SURELI') {
                $blockers[] = self::issue('SGK_KAYNAK_SUREC_CELISKILI', $resolvedCode . ' kodu icin kismi sureli sozlesme gerekir.', $employmentStart->format('Y-m-d'), $employmentEnd->format('Y-m-d'), null);
            }
        }

        $blockers = self::uniqueIssues($blockers);
        $manual = count($blockers) > 0;
        $effectivePrimDay = $manual ? null : $primDay;
        $dailyHash = self::hashCanonical($daily);
        $dailyLower = self::decimalOrNull($input['gunluk_alt_sinir'] ?? null);
        $dailyUpper = self::decimalOrNull($input['gunluk_ust_sinir'] ?? null);
        $periodLower = $effectivePrimDay !== null && $dailyLower !== null ? bcmul($dailyLower, (string) $effectivePrimDay, 2) : null;
        $periodUpper = $effectivePrimDay !== null && $dailyUpper !== null ? bcmul($dailyUpper, (string) $effectivePrimDay, 2) : null;
        $sourceHash = self::hashCanonical([
            'contract_version' => self::CONTRACT_VERSION,
            'personel' => $personel,
            'donem_baslangic' => $periodStart->format('Y-m-d'),
            'donem_bitis' => $periodEnd->format('Y-m-d'),
            'puantajlar' => $input['puantajlar'] ?? [],
            'surecler' => self::stripInternalProcessFields($processes),
            'katalog_surumu' => $catalog['surum_kodu'] ?? null,
            'manifest_hash' => $catalog['manifest_hash'] ?? null,
            'sirket_politika_hash' => $input['sirket_politika_hash'] ?? null,
            'is_goremezlik_finans_ozeti' => is_array($input['is_goremezlik_finans_ozeti'] ?? null) ? $input['is_goremezlik_finans_ozeti'] : [],
            'gunluk_alt_sinir' => $dailyLower,
            'gunluk_ust_sinir' => $dailyUpper,
        ]);

        $result = [
            'engine_version' => self::ENGINE_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'hesaplanan_prim_gunu' => $effectivePrimDay,
            'eksik_gun_sayisi' => count($missingDates),
            'eksik_gun_kodu' => $manual ? null : $resolvedCode,
            'eksik_gun_aciklamasi' => !$manual && $resolvedCode !== null && isset($codes[$resolvedCode])
                ? (string) ($codes[$resolvedCode]['resmi_aciklama'] ?? '') : null,
            'kaynak_surec_idleri' => array_values(array_keys($sourceProcessIds)),
            'kaynak_puantaj_idleri' => array_values(array_keys($sourceAttendanceIds)),
            'kaynak_belge_idleri' => array_values(array_keys($sourceDocumentIds)),
            'katalog_surum_id' => isset($catalog['surum_id']) ? (int) $catalog['surum_id'] : null,
            'katalog_surumu' => $catalog['surum_kodu'] ?? null,
            'kaynak_manifest_hash' => $catalog['manifest_hash'] ?? null,
            'manuel_inceleme_gerekli_mi' => $manual,
            'blocker_kodlari' => array_values(array_unique(array_map(static function (array $item) {
                return (string) $item['code'];
            }, $blockers))),
            'blocker_detaylari' => $blockers,
            'gunluk_karar_dokumu' => $daily,
            'gunluk_karar_dokumu_hash' => $dailyHash,
            'ucret_modeli' => $ucretModeli,
            'ilk_iki_gun_politika_ozeti' => $policySummary,
            'sirket_politika_surum_id' => isset($input['sirket_politika_surum_id']) ? (int) $input['sirket_politika_surum_id'] : null,
            'sirket_politika_hash' => $input['sirket_politika_hash'] ?? null,
            'sgk_odenek_durumu' => $reportSeen ? (string) ($input['sgk_odenek_durumu'] ?? 'KESINLESMEMIS') : 'UYGULANMAZ',
            'is_goremezlik_finans_ozeti' => is_array($input['is_goremezlik_finans_ozeti'] ?? null) ? $input['is_goremezlik_finans_ozeti'] : [],
            'gunluk_alt_sinir' => $dailyLower,
            'gunluk_ust_sinir' => $dailyUpper,
            'donem_alt_sinir' => $periodLower,
            'donem_ust_sinir' => $periodUpper,
            'sinir_mevzuat_surumu' => $input['sinir_mevzuat_surumu'] ?? null,
            'source_hash' => $sourceHash,
        ];
        $result['sgk_hesap_hash'] = self::hashCanonical($result);

        return $result;
    }

    /** @param array<string, mixed>|null $attendance @param array<string, mixed> $decision @param array<string, bool> $missingDates @param array<int, array<string, mixed>> $blockers */
    private static function decideFromAttendance($attendance, $date, array &$decision, array &$missingDates, array &$blockers)
    {
        if (!is_array($attendance)) {
            $decision['karar'] = 'BELIRSIZ';
            $decision['neden'] = 'CANONICAL_TAKVIM_EKSIK';
            $blockers[] = self::issue('CANONICAL_TAKVIM_EKSIK', 'Istihdam gunu icin muhurlu canonical puantaj kaydi yok.', $date, $date, null);
            return;
        }

        $gunTipi = (string) ($attendance['gun_tipi'] ?? '');
        if ($gunTipi === '') {
            $decision['karar'] = 'BELIRSIZ';
            $decision['neden'] = 'CANONICAL_TAKVIM_EKSIK';
            $blockers[] = self::issue('CANONICAL_TAKVIM_EKSIK', 'Puantaj gun_tipi bos.', $date, $date, null);
            return;
        }

        $hareket = (string) ($attendance['hareket_durumu'] ?? '');
        $dayanak = (string) ($attendance['dayanak'] ?? '');
        $worked = (int) ($attendance['net_calisma_suresi_dakika'] ?? 0) > 0
            || in_array($hareket, ['Geldi', 'Gec_Geldi', 'Erken_Cikti'], true);
        if ($worked || in_array($dayanak, ['Ucretli_Izinli', 'Yillik_Izin', 'Gorevde_Calisma'], true)) {
            return;
        }
        if ($gunTipi === 'UBGT_Resmi_Tatil') {
            return;
        }
        if ($gunTipi === 'Hafta_Tatili_Pazar') {
            if (!array_key_exists('hafta_tatili_hak_kazandi_mi', $attendance) || $attendance['hafta_tatili_hak_kazandi_mi'] === null) {
                $decision['karar'] = 'BELIRSIZ';
                $decision['neden'] = 'HAFTA_TATILI_HAKEDIS_BELIRSIZ';
                $blockers[] = self::issue('CANONICAL_TAKVIM_EKSIK', 'Hafta tatili hak edisi canonical kayitta belirli degil.', $date, $date, null);
                return;
            }
            if ((bool) $attendance['hafta_tatili_hak_kazandi_mi']) {
                return;
            }
        }

        if (in_array($dayanak, ['Raporlu_Hastalik', 'Raporlu_Is_Kazasi'], true)) {
            $decision['karar'] = 'BELIRSIZ';
            $decision['neden'] = 'RAPOR_SURECI_EKSIK';
            $blockers[] = self::issue('SGK_KAYNAK_SUREC_CELISKILI', 'Rapor dayanakli puantaj icin canonical surec bulunamadi.', $date, $date, null);
            return;
        }

        if ($hareket === 'Gelmedi' || $dayanak === 'Yok_Izinsiz') {
            $decision['karar'] = 'EKSIK';
            $decision['neden'] = 'PUANTAJ_YOKLUK';
            $missingDates[$date] = true;
            $blockers[] = self::issue('SGK_EKSIK_GUN_KODU_BULUNAMADI', 'Puantaj yoklugu canonical surec ve resmi koda bagli degil.', $date, $date, null);
            return;
        }

        $decision['karar'] = 'BELIRSIZ';
        $decision['neden'] = 'PUANTAJ_KARARI_BELIRSIZ';
        $blockers[] = self::issue('SGK_PRIM_GUNU_HESAPLANAMADI', 'Puantaj kaydi SGK gun kararina donusturulemedi.', $date, $date, null);
    }

    /** @param array<string, mixed> $codes @param array<string, mixed> $process @param array<int, array<string, mixed>> $blockers */
    private static function validateCodeAndDocuments($code, array $codes, array $process, $date, array &$blockers)
    {
        if (!isset($codes[$code]) || !is_array($codes[$code]) || empty($codes[$code]['aktif_mi'])) {
            $blockers[] = self::issue('SGK_EKSIK_GUN_KODU_BULUNAMADI', $code . ' kodu aktif katalogda bulunamadi.', $date, $date, isset($process['surec_id']) ? (int) $process['surec_id'] : null);
            return;
        }
        $requirement = (string) ($codes[$code]['belge_zorunlulugu'] ?? 'ZORUNLU');
        if ($requirement !== 'YOK' && empty($process['belge_dogrulandi_mi'])) {
            $blockers[] = self::issue('SGK_EKSIK_GUN_BELGESI_EKSIK', $code . ' kodu icin dogrulanmis ve hash uyumlu belge yok.', $date, $date, isset($process['surec_id']) ? (int) $process['surec_id'] : null);
        }
        if (!empty($process['belge_iptal_mi']) || !empty($process['belge_hash_uyusmazligi_mi'])) {
            $blockers[] = self::issue('SGK_EKSIK_GUN_BELGESI_EKSIK', 'Belge iptal edilmis veya dosya hash dogrulamasi basarisiz.', $date, $date, isset($process['surec_id']) ? (int) $process['surec_id'] : null);
        }
    }

    /** @return array<string, mixed> */
    private static function blockedResult(array $input, array $blockers, array $daily, array $policySummary)
    {
        $blockers = self::uniqueIssues($blockers);
        $dailyHash = self::hashCanonical($daily);
        $sourceHash = self::hashCanonical($input);
        $result = [
            'engine_version' => self::ENGINE_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'hesaplanan_prim_gunu' => null,
            'eksik_gun_sayisi' => null,
            'eksik_gun_kodu' => null,
            'eksik_gun_aciklamasi' => null,
            'kaynak_surec_idleri' => [],
            'kaynak_puantaj_idleri' => [],
            'kaynak_belge_idleri' => [],
            'katalog_surum_id' => null,
            'katalog_surumu' => null,
            'kaynak_manifest_hash' => null,
            'manuel_inceleme_gerekli_mi' => true,
            'blocker_kodlari' => array_values(array_unique(array_map(static function (array $item) {
                return (string) $item['code'];
            }, $blockers))),
            'blocker_detaylari' => $blockers,
            'gunluk_karar_dokumu' => $daily,
            'gunluk_karar_dokumu_hash' => $dailyHash,
            'ucret_modeli' => 'BELIRSIZ',
            'ilk_iki_gun_politika_ozeti' => $policySummary,
            'sirket_politika_surum_id' => null,
            'sirket_politika_hash' => null,
            'sgk_odenek_durumu' => 'UYGULANMAZ',
            'is_goremezlik_finans_ozeti' => [],
            'gunluk_alt_sinir' => null,
            'gunluk_ust_sinir' => null,
            'donem_alt_sinir' => null,
            'donem_ust_sinir' => null,
            'sinir_mevzuat_surumu' => null,
            'source_hash' => $sourceHash,
        ];
        $result['sgk_hesap_hash'] = self::hashCanonical($result);

        return $result;
    }

    /** @return array<string, mixed> */
    private static function issue($code, $message, $from, $to, $processId)
    {
        return [
            'severity' => 'BLOCKER',
            'code' => $code,
            'message' => $message,
            'domain' => 'SGK',
            'tarih_baslangic' => $from,
            'tarih_bitis' => $to,
            'kaynak_surec_id' => $processId,
            'kaynak_belge_id' => null,
            'cozum_onerisi' => self::resolutionHint($code),
        ];
    }

    private static function resolutionHint($code)
    {
        $hints = [
            'SGK_PRIM_GUNU_HESAPLANAMADI' => 'Canonical takvim, puantaj ve surec etkisini tamamlayin.',
            'SGK_EKSIK_GUN_KODU_BULUNAMADI' => 'Resmi katalog ve surec-kod eslemesini tamamlayin.',
            'SGK_EKSIK_GUN_KODU_CAKISTI' => 'Resmi birleşik kod kurali veya manuel karar kaydedin.',
            'SGK_KATALOG_SURUMU_GECERSIZ' => 'Tamligi dogrulanmis katalog surumunu onaylayin.',
            'SGK_EKSIK_GUN_BELGESI_EKSIK' => 'Belgeyi iliskisel olarak baglayip hash dogrulamasini tamamlayin.',
            'SGK_KAYNAK_SUREC_CELISKILI' => 'Cakisan surec ve puantaj kaynaklarini revizyonla duzeltin.',
            'RAPOR_TURU_BELIRSIZ' => 'Sureci canonical rapor taksonomisine esleyin.',
            'HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK' => 'Hastalik surecinde tri-state karari yetkili kullanici ile kesinlestirin.',
            'UCRET_MODELI_BELIRSIZ' => 'Personelin maktu/gunluk/saatlik ucret modelini kaydedin.',
            'SGK_ODENEK_MAHSUP_POLITIKASI_EKSIK' => 'Onayli mahsup politikasini veya fiili SGK tutarini kaydedin.',
            'CANONICAL_TAKVIM_EKSIK' => 'Muhurlu canonical calisma takvimini tamamlayin.',
        ];

        return $hints[$code] ?? 'Kaynak veriyi ve yetkili karari tamamlayin.';
    }

    /** @param array<int, array<string, mixed>> $issues @return array<int, array<string, mixed>> */
    private static function uniqueIssues(array $issues)
    {
        $seen = [];
        $out = [];
        foreach ($issues as $issue) {
            $key = self::hashCanonical([
                $issue['code'] ?? null,
                $issue['tarih_baslangic'] ?? null,
                $issue['tarih_bitis'] ?? null,
                $issue['kaynak_surec_id'] ?? null,
                $issue['message'] ?? null,
            ]);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $issue;
            }
        }

        return $out;
    }

    /** @return DateTimeImmutable|null */
    private static function date($value)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return array<int, DateTimeImmutable> */
    private static function dateRange(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        $period = new DatePeriod($from, new DateInterval('P1D'), $to->add(new DateInterval('P1D')));

        return iterator_to_array($period, false);
    }

    private static function inclusiveDays(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        return ((int) $from->diff($to)->format('%a')) + 1;
    }

    /** @return string|null */
    private static function decimalOrNull($value)
    {
        if ($value === null || $value === '' || !is_numeric($value) || bccomp((string) $value, '0', 2) < 0) {
            return null;
        }

        return bcadd((string) $value, '0', 2);
    }

    /** @param array<int, array<string, mixed>> $processes @return array<int, array<string, mixed>> */
    private static function stripInternalProcessFields(array $processes)
    {
        return array_map(static function (array $process) {
            unset($process['__from'], $process['__to'], $process['__canonical']);

            return $process;
        }, $processes);
    }

    /** @param mixed $value */
    public static function hashCanonical($value)
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param mixed $value @return mixed */
    public static function canonicalize($value)
    {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
