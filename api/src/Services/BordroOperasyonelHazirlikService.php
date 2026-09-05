<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use Medisa\Api\Services\Bildirim\BugunPersonelDurumuService;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
use PDO;

/**
 * Read-only operational payroll-preparation projection for Bordro Hazırlık Merkezi.
 *
 * Reuses:
 * - period person set: MaasHesaplamaSnapshotService::resolvePersonnelSet (incl. mid-period exit)
 * - day status precedence: BugunPersonelDurumuService (PR #263 / Bugün owner)
 * - period state: PuantajDonemPeriodService
 * - open puantaj kontrol / etki-aday blockers already known to DonemKapanis
 *
 * No new persistence. No invented risk classes.
 */
class BordroOperasyonelHazirlikService
{
    public const CONTRACT_VERSION = 'S96_BORDRO_OPERASYONEL_HAZIRLIK_V1';

    public const PROBLEM_HENUZ = 'HENUZ_DEGERLENDIRILMEDI';
    public const PROBLEM_EKSIK_GIRIS = 'EKSIK_GIRIS';
    public const PROBLEM_PUANTAJ_KONTROL = 'PUANTAJ_KONTROL_BEKLIYOR';
    public const PROBLEM_ETKI_HAZIR = 'CANDIDATE_HAZIR_PENDING';
    public const PROBLEM_ETKI_INCELEME = 'CANDIDATE_INCELEME_PENDING';

    /**
     * @return array<string, mixed>
     */
    public static function build(PDO $pdo, $subeId, $yil, $ay)
    {
        $subeId = (int) $subeId;
        $yil = (int) $yil;
        $ay = (int) $ay;
        $donem = sprintf('%04d-%02d', $yil, $ay);
        $donemBaslangic = sprintf('%04d-%02d-01', $yil, $ay);
        $donemBitis = date('Y-m-t', strtotime($donemBaslangic));
        $tz = new DateTimeZone(BugunPersonelDurumuService::TIMEZONE);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $asOf = $today < $donemBitis ? $today : $donemBitis;

        $periodState = PuantajDonemPeriodService::resolvePeriodState($pdo, $subeId, $yil, $ay);
        $writeLocked = PuantajDonemPeriodService::isWriteLocked($pdo, $subeId, $yil, $ay);
        $readOnly = $writeLocked;

        // Same employment ∩ period (+ mid-period exit) semantics as snapshot person set,
        // without SGK/salary blockers that would hide people from IK's control list.
        $personeller = self::resolveOperationalPersonnelSet($pdo, $subeId, $donemBaslangic, $donemBitis);

        $personelIds = array_map('intval', array_keys($personeller));
        $bildirimByPersonDate = self::loadBildirimler($pdo, $personelIds, $donemBaslangic, $asOf);
        $puantajByPersonDate = self::loadPuantaj($pdo, $personelIds, $donemBaslangic, $asOf);
        $surecler = self::loadSurecler($pdo, $personelIds, $donemBaslangic, $asOf);
        $completions = self::loadCompletions($pdo, $subeId, $donemBaslangic, $asOf);
        $bekleyenPuantaj = self::loadBekleyenPuantajCounts($pdo, $personelIds, $donemBaslangic, $donemBitis);
        $etkiByPerson = self::loadEtkiAdayCounts($pdo, $subeId, $donem, $personelIds);

        $dayTotals = self::emptyDayTotals();
        $personRows = [];
        $kontrolGereken = [];
        $hazirCount = 0;
        $kontrolCount = 0;

        foreach ($personeller as $personelId => $personel) {
            $personelId = (int) $personelId;
            $istihdamBas = (string) ($personel['istihdam_baslangic'] ?? $donemBaslangic);
            $istihdamBit = (string) ($personel['istihdam_bitis'] ?? $donemBitis);
            $dayFrom = max($donemBaslangic, $istihdamBas);
            $dayTo = min($asOf, $istihdamBit);
            $adayCounts = self::emptyDayTotals();
            $problems = [];
            $problemGunler = [];

            if ($dayFrom <= $dayTo) {
                $cursor = $dayFrom;
                while ($cursor <= $dayTo) {
                    $gb = $bildirimByPersonDate[$personelId][$cursor] ?? null;
                    $gp = $puantajByPersonDate[$personelId][$cursor] ?? null;
                    $surecTur = self::coveringSurecExceptionTur($surecler, $personelId, $cursor);
                    $exceptionTur = BugunPersonelDurumuService::effectiveExceptionTur(
                        $gb['bildirim_turu'] ?? null,
                        $surecTur
                    );
                    $puantajGiris = $gp['giris_saati'] ?? null;
                    $amirId = (int) ($personel['bagli_amir_id'] ?? 0);
                    $unitCompleted = $amirId > 0 && isset($completions[$subeId . ':' . $amirId . ':' . $cursor]);
                    $storedLate = null;
                    if (isset($gb['dakika']) && (int) $gb['dakika'] > 0) {
                        $storedLate = (int) $gb['dakika'];
                    } elseif (isset($gp['gec_kalma_dakika']) && (int) $gp['gec_kalma_dakika'] > 0) {
                        $storedLate = (int) $gp['gec_kalma_dakika'];
                    }

                    $durum = BugunPersonelDurumuService::resolvePersonDurum(
                        $exceptionTur,
                        $puantajGiris,
                        $unitCompleted,
                        $storedLate
                    );
                    self::bumpDayTotal($adayCounts, $durum);
                    self::bumpDayTotal($dayTotals, $durum);

                    $eksikGiris = BugunPersonelDurumuService::isMissingEntryEvidence($exceptionTur, $puantajGiris);
                    if ($eksikGiris && !$unitCompleted) {
                        $adayCounts['eksik_giris']++;
                        $dayTotals['eksik_giris']++;
                    }

                    $aciklama = isset($gb['aciklama']) ? trim((string) $gb['aciklama']) : '';
                    if ($durum === 'GELMEDI' && $aciklama === '') {
                        $adayCounts['aciklanmamis']++;
                        $dayTotals['aciklanmamis']++;
                    }

                    $ot = isset($gp['fazla_calisma_dakika']) ? (int) $gp['fazla_calisma_dakika'] : 0;
                    if ($ot > 0) {
                        $adayCounts['fazla_mesai_dakika'] += $ot;
                        $dayTotals['fazla_mesai_dakika'] += $ot;
                    }

                    if ($durum === BugunPersonelDurumuService::DURUM_HENUZ_DEGERLENDIRILMEDI) {
                        $problems[self::PROBLEM_HENUZ] = true;
                        $problemGunler[] = [
                            'tarih' => $cursor,
                            'kod' => self::PROBLEM_HENUZ,
                        ];
                    } elseif ($eksikGiris && !$unitCompleted) {
                        $problems[self::PROBLEM_EKSIK_GIRIS] = true;
                        $problemGunler[] = [
                            'tarih' => $cursor,
                            'kod' => self::PROBLEM_EKSIK_GIRIS,
                        ];
                    }

                    $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
                }
            }

            $bekleyen = (int) ($bekleyenPuantaj[$personelId] ?? 0);
            if ($bekleyen > 0) {
                $problems[self::PROBLEM_PUANTAJ_KONTROL] = true;
                $adayCounts['puantaj_kontrol_bekleyen'] = $bekleyen;
                $dayTotals['puantaj_kontrol_bekleyen'] += $bekleyen;
            }

            $etkiHazir = (int) ($etkiByPerson[$personelId]['HAZIR'] ?? 0);
            $etkiInceleme = (int) ($etkiByPerson[$personelId]['INCELEME_GEREKLI'] ?? 0);
            if ($etkiHazir > 0) {
                $problems[self::PROBLEM_ETKI_HAZIR] = true;
            }
            if ($etkiInceleme > 0) {
                $problems[self::PROBLEM_ETKI_INCELEME] = true;
            }

            $problemCodes = array_keys($problems);
            $operasyonelHazir = count($problemCodes) === 0;
            if ($operasyonelHazir) {
                $hazirCount++;
            } else {
                $kontrolCount++;
            }

            $adSoyad = (string) ($personel['ad_soyad'] ?? PersonelCalisanKapsamService::formatAdSoyad(
                $personel['ad'] ?? '',
                $personel['soyad'] ?? null
            ));
            $row = [
                'personel_id' => $personelId,
                'ad_soyad' => $adSoyad,
                'sicil_no' => $personel['sicil_no'] ?? null,
                'aktif_durum' => $personel['aktif_durum'] ?? null,
                'cikis_tarihi' => $personel['cikis_tarihi'] ?? null,
                'istihdam_baslangic' => $istihdamBas,
                'istihdam_bitis' => $istihdamBit,
                'operasyonel_hazir' => $operasyonelHazir,
                'problem_kodlari' => $problemCodes,
                'gun_ozeti' => [
                    'henuz_degerlendirilmedi' => (int) $adayCounts['henuz_degerlendirilmedi'],
                    'eksik_giris' => (int) $adayCounts['eksik_giris'],
                    'aciklanmamis' => (int) $adayCounts['aciklanmamis'],
                    'izinli' => (int) $adayCounts['izinli'],
                    'raporlu' => (int) $adayCounts['raporlu'],
                    'gelmedi' => (int) $adayCounts['gelmedi'],
                    'gec_geldi' => (int) $adayCounts['gec_geldi'],
                    'erken_cikti' => (int) $adayCounts['erken_cikti'],
                    'fazla_mesai_dakika' => (int) $adayCounts['fazla_mesai_dakika'],
                    'puantaj_kontrol_bekleyen' => (int) $adayCounts['puantaj_kontrol_bekleyen'],
                ],
                'etki_aday' => [
                    'HAZIR' => $etkiHazir,
                    'INCELEME_GEREKLI' => $etkiInceleme,
                ],
                'action_links' => [
                    'puantaj' => '/puantaj?personel_id=' . $personelId,
                    'bugun' => 'bugun_personel_durumu',
                    'surec' => '/surecler?personel_id=' . $personelId,
                ],
                'ornek_problem_gunleri' => array_slice($problemGunler, 0, 5),
            ];
            $personRows[] = $row;
            if (!$operasyonelHazir) {
                $kontrolGereken[] = $row;
            }
        }

        usort($kontrolGereken, static function (array $a, array $b) {
            return strcmp((string) $a['ad_soyad'], (string) $b['ad_soyad']);
        });
        usort($personRows, static function (array $a, array $b) {
            return strcmp((string) $a['ad_soyad'], (string) $b['ad_soyad']);
        });

        return [
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => $donem,
            'donem_baslangic' => $donemBaslangic,
            'donem_bitis' => $donemBitis,
            'as_of' => $asOf,
            'period_state' => $periodState,
            'period_writable' => !$writeLocked,
            'read_only' => $readOnly,
            'ozet' => [
                'toplam_personel' => count($personRows),
                'bordroya_hazir_personel' => $hazirCount,
                'kontrol_gereken_personel' => $kontrolCount,
                'henuz_degerlendirilmedi_gun' => (int) $dayTotals['henuz_degerlendirilmedi'],
                'eksik_giris_gun' => (int) $dayTotals['eksik_giris'],
                'aciklanmamis_gun' => (int) $dayTotals['aciklanmamis'],
                'izinli_gun' => (int) $dayTotals['izinli'],
                'raporlu_gun' => (int) $dayTotals['raporlu'],
                'devamsizlik_gun' => (int) $dayTotals['gelmedi'],
                'gec_gelme_gun' => (int) $dayTotals['gec_geldi'],
                'erken_cikma_gun' => (int) $dayTotals['erken_cikti'],
                'fazla_mesai_dakika' => (int) $dayTotals['fazla_mesai_dakika'],
                'puantaj_kontrol_bekleyen' => (int) $dayTotals['puantaj_kontrol_bekleyen'],
            ],
            'ready_criteria' => [
                'henuz_degerlendirilmedi_yok',
                'cozulmemis_eksik_giris_yok',
                'puantaj_kontrol_bekleyen_yok',
                'etki_aday_hazir_inceleme_yok',
            ],
            'kontrol_gerekenler' => $kontrolGereken,
            'personel_satirlari' => $personRows,
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * Pure readiness check used by focused PHP tests.
     *
     * @param array<int, string> $problemKodlari
     */
    public static function isOperasyonelHazir(array $problemKodlari)
    {
        return count($problemKodlari) === 0;
    }

    /**
     * Period roster: IC personel whose employment intersects the month (PASIF / mid-period
     * exit retained). Mirrors MaasHesaplamaSnapshotService employment window + HARIC exclusion
     * without payroll SGK blockers.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolveOperationalPersonnelSet(PDO $pdo, $subeId, $donemBaslangic, $donemBitis)
    {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.ad, p.soyad, p.sicil_no, p.ise_giris_tarihi, p.aktif_durum, p.bagli_amir_id,
                    (SELECT MIN(s.baslangic_tarihi) FROM surecler s
                      WHERE s.personel_id = p.id AND s.surec_turu = 'ISTEN_AYRILMA' AND s.state = 'AKTIF') AS cikis_tarihi
             FROM personeller p
             WHERE p.sube_id = :sube_id
             AND " . PersonelCalisanKapsamService::sqlIcPersonelPredicate($pdo, 'p') . '
             ORDER BY p.ad ASC, p.soyad ASC, p.id ASC'
        );
        $stmt->execute(['sube_id' => (int) $subeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $excludedIds = [];
        if (class_exists(PersonelBordroKapsamService::class)) {
            try {
                $excludedIds = PersonelBordroKapsamService::listExcludedPersonelIds(
                    $pdo,
                    (int) $subeId,
                    (string) $donemBaslangic,
                    (string) $donemBitis
                );
            } catch (\Throwable $e) {
                $excludedIds = [];
            }
        }

        $personeller = [];
        foreach ($rows as $row) {
            $personelId = (int) $row['id'];
            $iseGiris = (string) $row['ise_giris_tarihi'];
            $cikis = $row['cikis_tarihi'] !== null ? (string) $row['cikis_tarihi'] : null;
            if ($cikis !== null && $cikis < $iseGiris) {
                continue;
            }
            $intersects = $iseGiris <= $donemBitis && ($cikis === null || $cikis >= $donemBaslangic);
            if (!$intersects) {
                continue;
            }
            if (isset($excludedIds[$personelId])) {
                continue;
            }
            $kesisimBaslangic = max($iseGiris, (string) $donemBaslangic);
            $kesisimBitis = $cikis !== null ? min($cikis, (string) $donemBitis) : (string) $donemBitis;
            if ($kesisimBaslangic > $kesisimBitis) {
                continue;
            }
            $personeller[$personelId] = [
                'personel_id' => $personelId,
                'ad' => (string) $row['ad'],
                'soyad' => (string) ($row['soyad'] ?? ''),
                'ad_soyad' => PersonelCalisanKapsamService::formatAdSoyad($row['ad'] ?? '', $row['soyad'] ?? null),
                'sicil_no' => $row['sicil_no'] !== null ? (string) $row['sicil_no'] : null,
                'aktif_durum' => (string) ($row['aktif_durum'] ?? ''),
                'bagli_amir_id' => $row['bagli_amir_id'] !== null ? (int) $row['bagli_amir_id'] : null,
                'cikis_tarihi' => $cikis,
                'istihdam_baslangic' => $kesisimBaslangic,
                'istihdam_bitis' => $kesisimBitis,
            ];
        }

        return $personeller;
    }

    /**
     * @param array<string, int> $totals
     * @param string $durum
     */
    private static function bumpDayTotal(array &$totals, $durum)
    {
        $durum = strtoupper(trim((string) $durum));
        $map = [
            'GELDI' => 'geldi',
            'GEC_GELDI' => 'gec_geldi',
            'GELMEDI' => 'gelmedi',
            'IZINLI' => 'izinli',
            'RAPORLU' => 'raporlu',
            'GOREVDE' => 'gorevde',
            'ERKEN_CIKTI' => 'erken_cikti',
            'HENUZ_DEGERLENDIRILMEDI' => 'henuz_degerlendirilmedi',
            'DIGER' => 'diger',
        ];
        $key = $map[$durum] ?? null;
        if ($key !== null) {
            $totals[$key]++;
        }
    }

    /** @return array<string, int> */
    private static function emptyDayTotals()
    {
        return [
            'geldi' => 0,
            'gec_geldi' => 0,
            'gelmedi' => 0,
            'izinli' => 0,
            'raporlu' => 0,
            'gorevde' => 0,
            'erken_cikti' => 0,
            'henuz_degerlendirilmedi' => 0,
            'diger' => 0,
            'eksik_giris' => 0,
            'aciklanmamis' => 0,
            'fazla_mesai_dakika' => 0,
            'puantaj_kontrol_bekleyen' => 0,
        ];
    }

    /**
     * @param array<int, int> $personelIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    private static function loadBildirimler(PDO $pdo, array $personelIds, $bas, $bit)
    {
        if ($personelIds === [] || !self::tableExists($pdo, 'gunluk_bildirimler')) {
            return [];
        }
        $in = self::inClause($personelIds, 'gb');
        $stmt = $pdo->prepare(
            'SELECT personel_id, tarih, bildirim_turu, dakika, aciklama, baslangic_saati, bitis_saati, id
             FROM gunluk_bildirimler
             WHERE personel_id IN (' . $in['sql'] . ')
               AND tarih BETWEEN :bas AND :bit
               AND state <> \'IPTAL\'
             ORDER BY id ASC'
        );
        $stmt->execute(array_merge($in['params'], ['bas' => $bas, 'bit' => $bit]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int) $row['personel_id'];
            $tarih = (string) $row['tarih'];
            // latest id wins (same as Bugün subquery ORDER BY id DESC LIMIT 1)
            $out[$pid][$tarih] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, int> $personelIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    private static function loadPuantaj(PDO $pdo, array $personelIds, $bas, $bit)
    {
        if ($personelIds === [] || !self::tableExists($pdo, 'gunluk_puantaj')) {
            return [];
        }
        $in = self::inClause($personelIds, 'gp');
        $fmCol = self::columnExists($pdo, 'gunluk_puantaj', 'fazla_calisma_dakika')
            ? 'fazla_calisma_dakika'
            : '0 AS fazla_calisma_dakika';
        $gecCol = self::columnExists($pdo, 'gunluk_puantaj', 'gec_kalma_dakika')
            ? 'gec_kalma_dakika'
            : 'NULL AS gec_kalma_dakika';
        $erkenCol = self::columnExists($pdo, 'gunluk_puantaj', 'erken_cikis_dakika')
            ? 'erken_cikis_dakika'
            : 'NULL AS erken_cikis_dakika';
        $kontrolCol = self::columnExists($pdo, 'gunluk_puantaj', 'kontrol_durumu')
            ? 'kontrol_durumu'
            : 'NULL AS kontrol_durumu';
        $stmt = $pdo->prepare(
            "SELECT personel_id, tarih, giris_saati, cikis_saati, {$fmCol}, {$gecCol}, {$erkenCol}, {$kontrolCol}
             FROM gunluk_puantaj
             WHERE personel_id IN ({$in['sql']})
               AND tarih BETWEEN :bas AND :bit"
        );
        $stmt->execute(array_merge($in['params'], ['bas' => $bas, 'bit' => $bit]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['personel_id']][(string) $row['tarih']] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, int> $personelIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function loadSurecler(PDO $pdo, array $personelIds, $bas, $bit)
    {
        if ($personelIds === [] || !self::tableExists($pdo, 'surecler')) {
            return [];
        }
        $in = self::inClause($personelIds, 'sc');
        $stmt = $pdo->prepare(
            "SELECT personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, id
             FROM surecler
             WHERE state = 'AKTIF'
               AND personel_id IN ({$in['sql']})
               AND baslangic_tarihi <= :bit
               AND (bitis_tarihi IS NULL OR bitis_tarihi >= :bas)
               AND surec_turu IN ('IZIN', 'RAPOR', 'IS_KAZASI', 'DEVAMSIZLIK')
             ORDER BY id DESC"
        );
        $stmt->execute(array_merge($in['params'], ['bas' => $bas, 'bit' => $bit]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['personel_id']][] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $surecler
     * @return string|null
     */
    private static function coveringSurecExceptionTur(array $surecler, $personelId, $tarih)
    {
        foreach ($surecler[(int) $personelId] ?? [] as $row) {
            $bas = (string) $row['baslangic_tarihi'];
            $bit = $row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null;
            if ($bas <= $tarih && ($bit === null || $bit >= $tarih)) {
                return BugunPersonelDurumuService::mapSurecToBugunExceptionTur(
                    $row['surec_turu'] ?? null,
                    $row['alt_tur'] ?? null
                );
            }
        }

        return null;
    }

    /**
     * @return array<string, true> keyed sube:amir:tarih
     */
    private static function loadCompletions(PDO $pdo, $subeId, $bas, $bit)
    {
        if (!self::tableExists($pdo, 'gunluk_bildirim_tamamlamalari')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT sube_id, birim_amiri_user_id, tarih
             FROM gunluk_bildirim_tamamlamalari
             WHERE sube_id = :sube_id AND tarih BETWEEN :bas AND :bit'
        );
        $stmt->execute(['sube_id' => (int) $subeId, 'bas' => $bas, 'bit' => $bit]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = ((int) $row['sube_id']) . ':' . ((int) $row['birim_amiri_user_id']) . ':' . (string) $row['tarih'];
            $out[$key] = true;
        }

        return $out;
    }

    /**
     * @param array<int, int> $personelIds
     * @return array<int, int>
     */
    private static function loadBekleyenPuantajCounts(PDO $pdo, array $personelIds, $bas, $bit)
    {
        if ($personelIds === [] || !self::tableExists($pdo, 'gunluk_puantaj')) {
            return [];
        }
        if (!self::columnExists($pdo, 'gunluk_puantaj', 'kontrol_durumu')) {
            return [];
        }
        $in = self::inClause($personelIds, 'kb');
        $stmt = $pdo->prepare(
            "SELECT personel_id, COUNT(*) AS cnt
             FROM gunluk_puantaj
             WHERE personel_id IN ({$in['sql']})
               AND tarih BETWEEN :bas AND :bit
               AND kontrol_durumu = 'BEKLIYOR'
             GROUP BY personel_id"
        );
        $stmt->execute(array_merge($in['params'], ['bas' => $bas, 'bit' => $bit]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['personel_id']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * @param array<int, int> $personelIds
     * @return array<int, array<string, int>>
     */
    private static function loadEtkiAdayCounts(PDO $pdo, $subeId, $donem, array $personelIds)
    {
        if ($personelIds === [] || !self::tableExists($pdo, 'onayli_bildirim_puantaj_etki_adaylari')) {
            return [];
        }
        $in = self::inClause($personelIds, 'ea');
        $stmt = $pdo->prepare(
            "SELECT personel_id, state, COUNT(*) AS cnt
             FROM onayli_bildirim_puantaj_etki_adaylari
             WHERE sube_id = :sube_id
               AND ay = :ay
               AND personel_id IN ({$in['sql']})
               AND state IN ('HAZIR', 'INCELEME_GEREKLI')
             GROUP BY personel_id, state"
        );
        $stmt->execute(array_merge($in['params'], [
            'sube_id' => (int) $subeId,
            'ay' => (string) $donem,
        ]));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int) $row['personel_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['HAZIR' => 0, 'INCELEME_GEREKLI' => 0];
            }
            $out[$pid][(string) $row['state']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array{sql: string, params: array<string, int>}
     */
    private static function inClause(array $ids, $prefix)
    {
        $placeholders = [];
        $params = [];
        $i = 0;
        foreach ($ids as $id) {
            $key = $prefix . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $id;
            $i++;
        }

        return ['sql' => implode(', ', $placeholders), 'params' => $params];
    }

    private static function tableExists(PDO $pdo, $table)
    {
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t LIMIT 1"
                );
                $stmt->execute(['t' => $table]);

                return (bool) $stmt->fetchColumn();
            }
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1'
            );
            $stmt->execute(['t' => $table]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function columnExists(PDO $pdo, $table, $column)
    {
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
                if (!$stmt) {
                    return false;
                }
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (strcasecmp((string) ($row['name'] ?? ''), (string) $column) === 0) {
                        return true;
                    }
                }

                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
