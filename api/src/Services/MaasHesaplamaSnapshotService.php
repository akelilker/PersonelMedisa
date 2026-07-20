<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;
use PDOException;

/**
 * S77-C: Muhurlenmis puantaj doneminden maas hesaplama girdilerini
 * degismez snapshot olarak donduran domain owner.
 *
 * Snapshot bir hesap sonucu degildir; SGK/vergi/brut-net donusumu icermez.
 */
class MaasHesaplamaSnapshotService
{
    public const SCHEMA_VERSION = 'S77C_PAYROLL_INPUT_PREFLIGHT_V1';
    public const CONTRACT_VERSION = 'S77C_PAYROLL_INPUT_SNAPSHOT_V1';

    public const SEVERITY_BLOCKER = 'BLOCKER';
    public const SEVERITY_WARNING = 'WARNING';
    public const SEVERITY_INFO = 'INFO';

    public const AUDIT_PREFLIGHT_BLOCKED = 'PREFLIGHT_BLOCKED';
    public const AUDIT_SNAPSHOT_CREATE = 'SNAPSHOT_CREATE';
    public const AUDIT_SNAPSHOT_CREATE_IDEMPOTENT = 'SNAPSHOT_CREATE_IDEMPOTENT';
    public const AUDIT_SNAPSHOT_CANCEL = 'SNAPSHOT_CANCEL';
    public const AUDIT_REVISION_REQUEST_BLOCKED = 'REVISION_REQUEST_BLOCKED';

    private const IZIN_SUREC_TURLERI = ['IZIN', 'DEVAMSIZLIK', 'RAPOR', 'IS_KAZASI', 'ISTEN_AYRILMA'];
    private const CANDIDATE_FINAL_STATES = ['UYGULANDI', 'YOK_SAYILDI'];
    private const CANDIDATE_PENDING_STATES = ['HAZIR', 'INCELEME_GEREKLI'];

    // ------------------------------------------------------------------
    // Preflight
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function buildPreflight(PDO $pdo, $subeId, $yil, $ay)
    {
        $resolution = self::resolveSources($pdo, (int) $subeId, (int) $yil, (int) $ay);

        return self::formatPreflight($pdo, $resolution);
    }

    /**
     * Kaynak cozumlemenin tamami. Preflight ve create ayni fonksiyonu kullanir;
     * create bunu transaction icinde yeniden calistirir (TOCTOU korumasi).
     *
     * @return array<string, mixed>
     */
    public static function resolveSources(PDO $pdo, $subeId, $yil, $ay, $forUpdate = false)
    {
        [$donem, $donemBaslangic, $donemBitis] = BildirimDonemContextService::monthBounds($yil, $ay);
        $items = [];

        $muhur = self::findSeal($pdo, $subeId, $yil, $ay, $forUpdate);
        if (!$muhur) {
            $items[] = self::issue(self::SEVERITY_BLOCKER, 'PERIOD_NOT_SEALED', 'Donem muhurlenmemis; snapshot olusturulamaz.', 'muhur', null, null, []);
        } elseif ((string) $muhur['durum'] !== 'MUHURLENDI') {
            $items[] = self::issue(self::SEVERITY_BLOCKER, 'PERIOD_SEAL_INVALID', 'Muhur durumu gecersiz: ' . (string) $muhur['durum'], 'muhur', (int) $muhur['id'], null, []);
        } else {
            $items[] = self::issue(self::SEVERITY_INFO, 'PERIOD_SEALED', 'Donem muhurlu.', 'muhur', (int) $muhur['id'], null, [
                'muhurlenen_kayit_sayisi' => (int) $muhur['muhurlenen_kayit_sayisi'],
            ]);
        }

        $personeller = self::resolvePersonnelSet($pdo, $subeId, $donemBaslangic, $donemBitis, $muhur ? (int) $muhur['id'] : null, $items);
        $salaries = self::resolveSalarySegments($pdo, $personeller, $items);
        $attendance = $muhur ? self::resolveSealedAttendance($pdo, $muhur, $items) : ['rows' => [], 'by_personel' => []];
        $izinler = self::resolveLeaveSources($pdo, $personeller, $donemBaslangic, $donemBitis);
        $finance = self::resolveFinanceInputs($pdo, $subeId, $donem, $donemBaslangic, $donemBitis, $personeller, $muhur, $items);
        $legal = self::resolveLegalParameters($pdo, $donemBaslangic, $donemBitis, $items);

        $scopeFingerprint = PersonelBordroKapsamService::scopeFingerprintForPeriod(
            $pdo,
            $subeId,
            $donemBaslangic,
            $donemBitis
        );
        $hashes = self::buildSourceFingerprint(
            $muhur,
            $personeller,
            $salaries,
            $attendance,
            $izinler,
            $finance,
            $legal,
            $scopeFingerprint
        );

        $existing = self::findActiveSnapshot($pdo, $subeId, $yil, $ay, $forUpdate);
        if ($existing) {
            $items[] = self::issue(self::SEVERITY_INFO, 'EXISTING_SNAPSHOT_FOUND', 'Bu donem icin aktif snapshot mevcut.', 'snapshot', (int) $existing['id'], null, [
                'revision_no' => (int) $existing['revision_no'],
                'source_hash' => (string) $existing['source_hash'],
            ]);
            if ((string) $existing['source_hash'] !== $hashes['source_hash']) {
                $items[] = self::issue(self::SEVERITY_BLOCKER, 'EXISTING_ACTIVE_SNAPSHOT_SOURCE_CHANGED', 'Aktif snapshot sonrasi kaynaklar degisti; explicit revision karari gerekir.', 'snapshot', (int) $existing['id'], null, [
                    'snapshot_source_hash' => (string) $existing['source_hash'],
                    'guncel_source_hash' => $hashes['source_hash'],
                ]);
            }
        }

        $items = array_merge($items, self::buildInfoItems($personeller, $salaries, $attendance, $finance, $legal));

        $blockerCount = self::countBySeverity($items, self::SEVERITY_BLOCKER);
        $warningCount = self::countBySeverity($items, self::SEVERITY_WARNING);

        $preflightHash = self::hashCanonical([
            'schema_version' => self::SCHEMA_VERSION,
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'muhur_id' => $muhur ? (int) $muhur['id'] : null,
            'blockers' => self::codeCounts($items, self::SEVERITY_BLOCKER),
            'warnings' => self::codeCounts($items, self::SEVERITY_WARNING),
            'source_hash' => $hashes['source_hash'],
            'existing_snapshot_id' => $existing ? (int) $existing['id'] : null,
        ]);

        return [
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => $donem,
            'donem_baslangic' => $donemBaslangic,
            'donem_bitis' => $donemBitis,
            'muhur' => $muhur,
            'personeller' => $personeller,
            'salaries' => $salaries,
            'attendance' => $attendance,
            'izinler' => $izinler,
            'finance' => $finance,
            'legal' => $legal,
            'items' => $items,
            'hashes' => $hashes,
            'existing_snapshot' => $existing,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
            'preflight_hash' => $preflightHash,
        ];
    }

    /** @param array<string, mixed> $resolution @return array<string, mixed> */
    public static function formatPreflight(PDO $pdo, array $resolution)
    {
        $muhur = $resolution['muhur'];
        $existing = $resolution['existing_snapshot'];
        $infoCount = self::countBySeverity($resolution['items'], self::SEVERITY_INFO);

        return [
            'sube' => self::fetchSube($pdo, (int) $resolution['sube_id']),
            'yil' => (int) $resolution['yil'],
            'ay' => (int) $resolution['ay'],
            'donem' => (string) $resolution['donem'],
            'donem_baslangic' => (string) $resolution['donem_baslangic'],
            'donem_bitis' => (string) $resolution['donem_bitis'],
            'muhur' => $muhur ? [
                'id' => (int) $muhur['id'],
                'durum' => (string) $muhur['durum'],
                'muhurlenen_kayit_sayisi' => (int) $muhur['muhurlenen_kayit_sayisi'],
                'created_at' => (string) $muhur['created_at'],
            ] : null,
            'snapshot_olusturulabilir_mi' => $muhur !== null && (int) $resolution['blocker_count'] === 0 && !$existing,
            'blocker_count' => (int) $resolution['blocker_count'],
            'warning_count' => (int) $resolution['warning_count'],
            'info_count' => $infoCount,
            'items' => array_values($resolution['items']),
            'personel_summary' => self::buildPersonnelSummary($resolution),
            'source_summary' => self::buildSourceSummary($resolution),
            'existing_snapshot' => $existing ? [
                'id' => (int) $existing['id'],
                'state' => (string) $existing['state'],
                'revision_no' => (int) $existing['revision_no'],
                'source_hash' => (string) $existing['source_hash'],
                'snapshot_hash' => (string) $existing['snapshot_hash'],
                'created_at' => (string) $existing['created_at'],
                'source_changed' => (string) $existing['source_hash'] !== $resolution['hashes']['source_hash'],
            ] : null,
            'preflight_hash' => (string) $resolution['preflight_hash'],
            'source_hash' => (string) $resolution['hashes']['source_hash'],
            'hashes' => $resolution['hashes'],
            'schema_version' => self::SCHEMA_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => gmdate('c'),
        ];
    }

    // ------------------------------------------------------------------
    // Kaynak cozumleme
    // ------------------------------------------------------------------

    /**
     * Sube + istihdam kesisimi + muhur satiri olan personeller.
     *
     * Istihdam semantigi (unit testli):
     * - ise_giris_tarihi inclusive ilk calisma gunudur.
     * - ISTEN_AYRILMA surecinin baslangic_tarihi inclusive son istihdam gunudur.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function resolvePersonnelSet(PDO $pdo, $subeId, $donemBaslangic, $donemBitis, $muhurId, array &$items)
    {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.ad, p.soyad, p.tc_kimlik_no, p.sicil_no, p.ise_giris_tarihi,
                    p.aktif_durum, p.sube_id, p.departman_id, p.gorev_id, p.personel_tipi_id,
                    p.ucret_tipi_id, p.prim_kurali_id, p.bagli_amir_id,
                    d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi,
                    (SELECT MIN(s.baslangic_tarihi) FROM surecler s
                      WHERE s.personel_id = p.id AND s.surec_turu = 'ISTEN_AYRILMA' AND s.state = 'AKTIF') AS cikis_tarihi
             FROM personeller p
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             LEFT JOIN gorevler g ON g.id = p.gorev_id
             LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
             WHERE p.sube_id = :sube_id
             ORDER BY p.id ASC"
        );
        $stmt->execute(['sube_id' => $subeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sealedPersonelIds = [];
        if ($muhurId !== null) {
            $sealStmt = $pdo->prepare(
                'SELECT DISTINCT personel_id FROM puantaj_aylik_muhur_satirlari WHERE muhur_id = :muhur_id'
            );
            $sealStmt->execute(['muhur_id' => $muhurId]);
            foreach ($sealStmt->fetchAll(PDO::FETCH_ASSOC) as $sealRow) {
                $sealedPersonelIds[(int) $sealRow['personel_id']] = true;
            }
        }

        $excludedIds = PersonelBordroKapsamService::listExcludedPersonelIds(
            $pdo,
            $subeId,
            $donemBaslangic,
            $donemBitis
        );

        $personeller = [];
        foreach ($rows as $row) {
            $personelId = (int) $row['id'];
            $iseGiris = (string) $row['ise_giris_tarihi'];
            $cikis = $row['cikis_tarihi'] !== null ? (string) $row['cikis_tarihi'] : null;
            $adSoyad = trim((string) $row['ad'] . ' ' . (string) $row['soyad']);

            if ($cikis !== null && $cikis < $iseGiris) {
                $items[] = self::issue(self::SEVERITY_BLOCKER, 'EMPLOYMENT_DATE_INVALID', 'Istihdam tarih araligi gecersiz (cikis giristen once).', 'personel', $personelId, $personelId, [
                    'ise_giris_tarihi' => $iseGiris,
                    'cikis_tarihi' => $cikis,
                ], $adSoyad);
                continue;
            }

            $intersects = $iseGiris <= $donemBitis && ($cikis === null || $cikis >= $donemBaslangic);
            $hasSealedRows = isset($sealedPersonelIds[$personelId]);
            if (!$intersects && !$hasSealedRows) {
                continue;
            }

            // S84-R2: ONAYLANDI HARIC kapsam, muhur satiri olsa bile yeni snapshot setine alinmaz.
            if (isset($excludedIds[$personelId])) {
                $items[] = self::issue(
                    self::SEVERITY_INFO,
                    'PAYROLL_SCOPE_EXCLUDED',
                    'Personel donemde bordro kapsaminda HARIC; yeni snapshot setine alinmadi.',
                    'personel',
                    $personelId,
                    $personelId,
                    [
                        'sicil_no' => $row['sicil_no'] !== null ? (string) $row['sicil_no'] : null,
                        'muhurlu_kayit_var_mi' => $hasSealedRows,
                    ],
                    $adSoyad
                );
                continue;
            }

            $kesisimBaslangic = max($iseGiris, $donemBaslangic);
            $kesisimBitis = $cikis !== null ? min($cikis, $donemBitis) : $donemBitis;
            if ($kesisimBaslangic > $kesisimBitis) {
                $kesisimBaslangic = $donemBaslangic;
                $kesisimBitis = $donemBitis;
            }

            if ($iseGiris > $donemBaslangic && $iseGiris <= $donemBitis) {
                $items[] = self::issue(self::SEVERITY_WARNING, 'PERSONNEL_ENTRY_WITHIN_PERIOD', 'Personel donem icinde ise girdi; yalniz kesisim araligi hesaplanacak.', 'personel', $personelId, $personelId, [
                    'ise_giris_tarihi' => $iseGiris,
                ], $adSoyad);
            }
            if ($cikis !== null && $cikis >= $donemBaslangic && $cikis < $donemBitis) {
                $items[] = self::issue(self::SEVERITY_WARNING, 'PERSONNEL_EXIT_WITHIN_PERIOD', 'Personel donem icinde isten ayrildi; yalniz kesisim araligi hesaplanacak.', 'personel', $personelId, $personelId, [
                    'cikis_tarihi' => $cikis,
                ], $adSoyad);
            }

            $personeller[$personelId] = [
                'personel_id' => $personelId,
                'ad' => (string) $row['ad'],
                'soyad' => (string) $row['soyad'],
                'ad_soyad' => $adSoyad,
                'tc_kimlik_no_masked' => self::maskTc((string) $row['tc_kimlik_no']),
                'sicil_no' => $row['sicil_no'] !== null ? (string) $row['sicil_no'] : null,
                'sube_id' => (int) $row['sube_id'],
                'departman_id' => $row['departman_id'] !== null ? (int) $row['departman_id'] : null,
                'departman_adi' => $row['departman_adi'] !== null ? (string) $row['departman_adi'] : null,
                'gorev_id' => $row['gorev_id'] !== null ? (int) $row['gorev_id'] : null,
                'gorev_adi' => $row['gorev_adi'] !== null ? (string) $row['gorev_adi'] : null,
                'personel_tipi_id' => $row['personel_tipi_id'] !== null ? (int) $row['personel_tipi_id'] : null,
                'personel_tipi_adi' => $row['personel_tipi_adi'] !== null ? (string) $row['personel_tipi_adi'] : null,
                'ucret_tipi_id' => $row['ucret_tipi_id'] !== null ? (int) $row['ucret_tipi_id'] : null,
                'prim_kurali_id' => $row['prim_kurali_id'] !== null ? (int) $row['prim_kurali_id'] : null,
                'bagli_amir_id' => $row['bagli_amir_id'] !== null ? (int) $row['bagli_amir_id'] : null,
                'aktif_durum' => (string) $row['aktif_durum'],
                'ise_giris_tarihi' => $iseGiris,
                'cikis_tarihi' => $cikis,
                'istihdam_baslangic' => $kesisimBaslangic,
                'istihdam_bitis' => $kesisimBitis,
                'muhurlu_kayit_var_mi' => $hasSealedRows,
            ];
        }

        if (count($personeller) === 0) {
            $items[] = self::issue(self::SEVERITY_BLOCKER, 'PAYROLL_PERSONNEL_SET_EMPTY', 'Donemde bordroya girecek personel bulunamadi.', 'personel', null, null, []);
        }

        return $personeller;
    }

    /**
     * Her personel icin istihdam kesisim araligini kapsayan ucret segmentleri.
     * Yalniz donem sonu ucreti almak yasaktir; tum kesisen segmentler kopyalanir.
     *
     * @param array<int, array<string, mixed>> $personeller
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<int, array<string, mixed>>> personel_id => segment listesi
     */
    public static function resolveSalarySegments(PDO $pdo, array $personeller, array &$items)
    {
        $salaries = [];
        foreach ($personeller as $personelId => $personel) {
            $bas = (string) $personel['istihdam_baslangic'];
            $bit = (string) $personel['istihdam_bitis'];
            $adSoyad = (string) $personel['ad_soyad'];

            $stmt = $pdo->prepare(
                "SELECT id, personel_id, ucret_tutari, ucret_turu, para_birimi,
                        gecerlilik_baslangic, gecerlilik_bitis, state, kaynak, aciklama, revision_no
                 FROM personel_ucret_gecmisi
                 WHERE personel_id = :personel_id AND state = 'AKTIF'
                   AND gecerlilik_baslangic <= :bit
                   AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :bas)
                 ORDER BY gecerlilik_baslangic ASC, id ASC"
            );
            $stmt->execute(['personel_id' => $personelId, 'bas' => $bas, 'bit' => $bit]);
            $segments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($segments) === 0) {
                $legacy = self::resolveLegacySalary($pdo, $personelId, $bas, $bit);
                if ($legacy !== null) {
                    $segments = [$legacy];
                    $items[] = self::issue(self::SEVERITY_WARNING, 'LEGACY_SALARY_FALLBACK_USED', 'Ucret gecmisi yok; legacy personel maasi fallback kullanildi.', 'ucret', null, $personelId, [
                        'legacy_tutar' => (string) $legacy['ucret_tutari'],
                    ], $adSoyad);
                } else {
                    $items[] = self::issue(self::SEVERITY_BLOCKER, 'SALARY_MISSING', 'Istihdam kesisim araligini kapsayan ucret kaydi yok.', 'ucret', null, $personelId, [
                        'aralik' => $bas . '..' . $bit,
                    ], $adSoyad);
                    $salaries[$personelId] = [];
                    continue;
                }
            }

            $coverage = self::checkSalaryCoverage($segments, $bas, $bit);
            foreach ($coverage['overlaps'] as $overlap) {
                $items[] = self::issue(self::SEVERITY_BLOCKER, 'SALARY_OVERLAP_DATA_ERROR', 'Ucret segmentleri cakisiyor.', 'ucret', $overlap['record_id'], $personelId, $overlap, $adSoyad);
            }
            foreach ($coverage['gaps'] as $gap) {
                $items[] = self::issue(self::SEVERITY_BLOCKER, 'SALARY_COVERAGE_GAP', 'Istihdam kesisim araliginda ucret kapsamayan gun var.', 'ucret', null, $personelId, $gap, $adSoyad);
            }

            $salaries[$personelId] = array_map(static function (array $segment) use ($bas, $bit) {
                return [
                    'id' => $segment['id'] !== null ? (int) $segment['id'] : null,
                    'personel_id' => (int) $segment['personel_id'],
                    'ucret_tutari' => (string) $segment['ucret_tutari'],
                    'ucret_turu' => (string) $segment['ucret_turu'],
                    'para_birimi' => (string) $segment['para_birimi'],
                    'gecerlilik_baslangic' => (string) $segment['gecerlilik_baslangic'],
                    'gecerlilik_bitis' => $segment['gecerlilik_bitis'] !== null ? (string) $segment['gecerlilik_bitis'] : null,
                    'kaynak' => (string) $segment['kaynak'],
                    'revision_no' => isset($segment['revision_no']) ? (int) $segment['revision_no'] : null,
                    'virtual_legacy' => !empty($segment['virtual']),
                    'etki_baslangic' => max((string) $segment['gecerlilik_baslangic'], $bas),
                    'etki_bitis' => $segment['gecerlilik_bitis'] !== null ? min((string) $segment['gecerlilik_bitis'], $bit) : $bit,
                ];
            }, $segments);
        }

        return $salaries;
    }

    /**
     * Ucret segmentlerinin [bas, bit] araligini bosluksuz ve cakismasiz kapsadigini kontrol eder.
     * Tarih araliklari inclusive'dir.
     *
     * @param array<int, array<string, mixed>> $segments gecerlilik_baslangic'e gore sirali
     * @return array{gaps: array<int, array<string, mixed>>, overlaps: array<int, array<string, mixed>>}
     */
    public static function checkSalaryCoverage(array $segments, $bas, $bit)
    {
        $gaps = [];
        $overlaps = [];
        $cursor = $bas;

        foreach (array_values($segments) as $index => $segment) {
            $segBas = (string) $segment['gecerlilik_baslangic'];
            $segBit = $segment['gecerlilik_bitis'] !== null ? (string) $segment['gecerlilik_bitis'] : null;

            if ($index > 0) {
                $prev = array_values($segments)[$index - 1];
                $prevBit = $prev['gecerlilik_bitis'] !== null ? (string) $prev['gecerlilik_bitis'] : null;
                if ($prevBit === null || $segBas <= $prevBit) {
                    $overlaps[] = [
                        'record_id' => isset($segment['id']) && $segment['id'] !== null ? (int) $segment['id'] : null,
                        'onceki_bitis' => $prevBit,
                        'yeni_baslangic' => $segBas,
                    ];
                    continue;
                }
            }

            if ($segBas > $cursor) {
                $gaps[] = ['bosluk_baslangic' => $cursor, 'bosluk_bitis' => self::dayBefore($segBas)];
            }

            $coveredUntil = $segBit === null ? $bit : min($segBit, $bit);
            if ($coveredUntil >= $cursor) {
                $cursor = self::dayAfter($coveredUntil);
            }
            if ($segBit === null || $segBit >= $bit) {
                break;
            }
        }

        if ($cursor <= $bit) {
            $gaps[] = ['bosluk_baslangic' => $cursor, 'bosluk_bitis' => $bit];
        }

        return ['gaps' => $gaps, 'overlaps' => $overlaps];
    }

    /**
     * Muhurlu gunluk puantaj kaynagi: S76'nin dondurdugu muhur satirlari.
     *
     * @param array<string, mixed> $muhur
     * @param array<int, array<string, mixed>> $items
     * @return array{rows: array<int, array<string, mixed>>, by_personel: array<int, int>}
     */
    public static function resolveSealedAttendance(PDO $pdo, array $muhur, array &$items)
    {
        $muhurId = (int) $muhur['id'];
        $stmt = $pdo->prepare(
            'SELECT * FROM puantaj_aylik_muhur_satirlari
             WHERE muhur_id = :muhur_id
             ORDER BY personel_id ASC, tarih ASC, id ASC'
        );
        $stmt->execute(['muhur_id' => $muhurId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $expected = (int) $muhur['muhurlenen_kayit_sayisi'];
        if ($expected > 0 && count($rows) === 0) {
            $items[] = self::issue(self::SEVERITY_BLOCKER, 'PUANTAJ_SOURCE_MISSING', 'Muhur kayit sayisi > 0 ancak muhur satiri bulunamadi.', 'puantaj', $muhurId, null, [
                'beklenen' => $expected,
            ]);
        } elseif (count($rows) !== $expected) {
            $items[] = self::issue(self::SEVERITY_BLOCKER, 'PUANTAJ_SOURCE_INCONSISTENT', 'Muhur satir sayisi muhurlenen_kayit_sayisi ile uyusmuyor.', 'puantaj', $muhurId, null, [
                'beklenen' => $expected,
                'bulunan' => count($rows),
            ]);
        }

        $byPersonel = [];
        foreach ($rows as $row) {
            $personelId = (int) $row['personel_id'];
            $byPersonel[$personelId] = ($byPersonel[$personelId] ?? 0) + 1;
        }

        return ['rows' => $rows, 'by_personel' => $byPersonel];
    }

    /**
     * Donemle kesisen aktif izin/devamsizlik/rapor surecleri (IZIN kaynagi).
     *
     * @param array<int, array<string, mixed>> $personeller
     * @return array<int, array<string, mixed>>
     */
    public static function resolveLeaveSources(PDO $pdo, array $personeller, $donemBaslangic, $donemBitis)
    {
        if (count($personeller) === 0) {
            return [];
        }
        $ids = array_keys($personeller);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $turPlaceholders = implode(', ', array_fill(0, count(self::IZIN_SUREC_TURLERI), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                    ucretli_mi, aciklama, state, created_at
             FROM surecler
             WHERE personel_id IN ($placeholders)
               AND surec_turu IN ($turPlaceholders)
               AND state = 'AKTIF'
               AND baslangic_tarihi <= ?
               AND (bitis_tarihi IS NULL OR bitis_tarihi >= ?)
             ORDER BY personel_id ASC, baslangic_tarihi ASC, id ASC"
        );
        $stmt->execute(array_merge($ids, self::IZIN_SUREC_TURLERI, [$donemBitis, $donemBaslangic]));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Kesinlesmis finansal etkiler: cozumlenmis etki adaylari + aktif ek odeme/kesinti.
     *
     * @param array<int, array<string, mixed>> $personeller
     * @param array<string, mixed>|null $muhur
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function resolveFinanceInputs(PDO $pdo, $subeId, $donem, $donemBaslangic, $donemBitis, array $personeller, $muhur, array &$items)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM onayli_bildirim_puantaj_etki_adaylari
             WHERE sube_id = :sube_id AND ay = :ay
             ORDER BY id ASC'
        );
        $stmt->execute(['sube_id' => $subeId, 'ay' => $donem]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $finalCandidates = [];
        $excludedIds = PersonelBordroKapsamService::listExcludedPersonelIds(
            $pdo,
            $subeId,
            $donemBaslangic,
            $donemBitis
        );
        foreach ($candidates as $candidate) {
            $state = strtoupper((string) $candidate['state']);
            $personelId = (int) $candidate['personel_id'];
            if (isset($excludedIds[$personelId])) {
                continue;
            }
            $adSoyad = isset($personeller[$personelId]) ? (string) $personeller[$personelId]['ad_soyad'] : null;
            if (in_array($state, self::CANDIDATE_PENDING_STATES, true)) {
                $code = $candidate['conflict_code'] !== null && $candidate['conflict_code'] !== ''
                    ? 'FINANCE_CONFLICT_UNRESOLVED'
                    : 'UNRESOLVED_IMPACT_CANDIDATE';
                $items[] = self::issue(self::SEVERITY_BLOCKER, $code, 'Cozulmemis etki adayi var; snapshot olusturulamaz.', 'etki_adayi', (int) $candidate['id'], $personelId, [
                    'state' => $state,
                    'conflict_code' => $candidate['conflict_code'],
                ], $adSoyad);
                continue;
            }
            if (in_array($state, self::CANDIDATE_FINAL_STATES, true)) {
                $finalCandidates[] = $candidate;
            }
        }

        $resolutions = [];
        if (count($finalCandidates) > 0) {
            $candidateIds = array_map(static function (array $candidate) {
                return (int) $candidate['id'];
            }, $finalCandidates);
            $placeholders = implode(', ', array_fill(0, count($candidateIds), '?'));
            $resStmt = $pdo->prepare(
                "SELECT id, aday_id, conflict_class, karar_turu, gerekce, sonuc_hash, karar_veren_user_id, karar_zamani
                 FROM bildirim_puantaj_etki_cakisma_cozumleri
                 WHERE aday_id IN ($placeholders)
                 ORDER BY id ASC"
            );
            $resStmt->execute($candidateIds);
            foreach ($resStmt->fetchAll(PDO::FETCH_ASSOC) as $resolutionRow) {
                $resolutions[(int) $resolutionRow['aday_id']] = $resolutionRow;
            }
        }

        $finansStmt = $pdo->prepare(
            "SELECT fk.id, fk.personel_id, fk.donem, fk.kalem_turu, fk.tutar, fk.gun_sayisi,
                    fk.aciklama, fk.state, fk.created_by, fk.created_at, fk.updated_at
             FROM ek_odeme_kesinti fk
             INNER JOIN personeller p ON p.id = fk.personel_id
             WHERE p.sube_id = :sube_id AND fk.donem = :donem AND fk.state = 'AKTIF'
             ORDER BY fk.id ASC"
        );
        $finansStmt->execute(['sube_id' => $subeId, 'donem' => $donem]);
        $finansRows = array_values(array_filter(
            $finansStmt->fetchAll(PDO::FETCH_ASSOC),
            static function (array $row) use ($excludedIds) {
                return !isset($excludedIds[(int) $row['personel_id']]);
            }
        ));

        $duplicateKeys = [];
        foreach ($finansRows as $finansRow) {
            $key = implode('|', [
                (int) $finansRow['personel_id'],
                (string) $finansRow['kalem_turu'],
                (string) $finansRow['tutar'],
                trim((string) ($finansRow['aciklama'] ?? '')),
            ]);
            $duplicateKeys[$key][] = (int) $finansRow['id'];
        }
        foreach ($duplicateKeys as $duplicateIds) {
            if (count($duplicateIds) > 1) {
                $items[] = self::issue(self::SEVERITY_BLOCKER, 'FINANCE_DUPLICATE_SOURCE', 'Ayni finans kaynagi birden fazla kez kayitli.', 'finans', $duplicateIds[0], null, [
                    'kayit_idler' => $duplicateIds,
                ]);
            }
        }

        $muhurCreatedAt = $muhur ? (string) $muhur['created_at'] : null;
        $afterSealIds = [];
        foreach ($finansRows as $finansRow) {
            if ($muhurCreatedAt !== null && (string) $finansRow['created_at'] > $muhurCreatedAt) {
                $afterSealIds[] = (int) $finansRow['id'];
            }
        }
        if (count($afterSealIds) > 0) {
            $items[] = self::issue(self::SEVERITY_WARNING, 'FINANCE_RECORD_CREATED_AFTER_SEAL', 'Muhur sonrasi olusturulmus finans kaydi var; snapshot cutoff kapsaminda dahil edildi.', 'finans', null, null, [
                'kayit_idler' => $afterSealIds,
                'dahil_edildi' => true,
            ]);
        }

        $appliedCount = 0;
        foreach ($finalCandidates as $candidate) {
            if (strtoupper((string) $candidate['state']) === 'UYGULANDI') {
                $appliedCount++;
            }
        }
        if (count($finansRows) === 0 && $appliedCount === 0) {
            $items[] = self::issue(self::SEVERITY_WARNING, 'NO_FINANCE_EFFECT', 'Donemde finansal etki kaydi yok.', 'finans', null, null, []);
        }

        return [
            'candidates' => $finalCandidates,
            'resolutions' => $resolutions,
            'finans_rows' => $finansRows,
            'after_seal_ids' => $afterSealIds,
        ];
    }

    /**
     * Donemle kesisen aktif mevzuat parametreleri. Gercek deger seed edilmez.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function resolveLegalParameters(PDO $pdo, $donemBaslangic, $donemBitis, array &$items)
    {
        $stmt = $pdo->prepare(
            "SELECT id, parametre_kodu, deger_tipi, sayisal_deger, metin_deger, birim,
                    gecerlilik_baslangic, gecerlilik_bitis, kaynak_referansi, revision_no
             FROM mevzuat_parametreleri
             WHERE state = 'AKTIF'
               AND gecerlilik_baslangic <= :bit
               AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :bas)
             ORDER BY parametre_kodu ASC, gecerlilik_baslangic ASC, id ASC"
        );
        $stmt->execute(['bas' => $donemBaslangic, 'bit' => $donemBitis]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) $row['parametre_kodu']][] = $row;
        }
        foreach ($byCode as $code => $codeRows) {
            for ($i = 1; $i < count($codeRows); $i++) {
                $prev = $codeRows[$i - 1];
                $curr = $codeRows[$i];
                $prevBit = $prev['gecerlilik_bitis'] !== null ? (string) $prev['gecerlilik_bitis'] : null;
                if ($prevBit === null || (string) $curr['gecerlilik_baslangic'] <= $prevBit) {
                    $items[] = self::issue(self::SEVERITY_BLOCKER, 'LEGAL_PARAMETER_OVERLAP_DATA_ERROR', 'Ayni mevzuat parametre kodunda cakisan kayitlar var.', 'mevzuat', (int) $curr['id'], null, [
                        'parametre_kodu' => $code,
                    ]);
                }
            }
        }

        if (count($rows) === 0) {
            $items[] = self::issue(self::SEVERITY_WARNING, 'LEGAL_PARAMETER_SET_EMPTY', 'Mevzuat parametresi bulunamadi; gercek hesap fazinda blocker olacaktir.', 'mevzuat', null, null, []);
        } else {
            $coveredCodes = 0;
            foreach ($byCode as $codeRows) {
                $coverage = self::checkSalaryCoverage(array_map(static function (array $row) {
                    return [
                        'id' => $row['id'],
                        'gecerlilik_baslangic' => $row['gecerlilik_baslangic'],
                        'gecerlilik_bitis' => $row['gecerlilik_bitis'],
                    ];
                }, $codeRows), $donemBaslangic, $donemBitis);
                if (count($coverage['gaps']) === 0 && count($coverage['overlaps']) === 0) {
                    $coveredCodes++;
                }
            }
            if ($coveredCodes < count($byCode)) {
                $items[] = self::issue(self::SEVERITY_WARNING, 'LEGAL_PARAMETER_COVERAGE_PARTIAL', 'Bazi mevzuat parametreleri donemin tamamini kapsamiyor.', 'mevzuat', null, null, [
                    'tam_kapsanan' => $coveredCodes,
                    'toplam_kod' => count($byCode),
                ]);
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Hash / fingerprint
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $muhur
     * @param array<int, array<string, mixed>> $personeller
     * @param array<int, array<int, array<string, mixed>>> $salaries
     * @param array{rows: array<int, array<string, mixed>>, by_personel: array<int, int>} $attendance
     * @param array<int, array<string, mixed>> $izinler
     * @param array<string, mixed> $finance
     * @param array<int, array<string, mixed>> $legal
     * @return array<string, string>
     */
    public static function buildSourceFingerprint(
        $muhur,
        array $personeller,
        array $salaries,
        array $attendance,
        array $izinler,
        array $finance,
        array $legal,
        $scopeFingerprint = null
    ) {
        $muhurHash = self::hashCanonical($muhur ? self::muhurPayload($muhur) : null);
        $personelSetHash = self::hashCanonical(array_map([self::class, 'personelPayload'], array_values($personeller)));
        $salaryHash = self::hashCanonical(array_values(array_map('array_values', $salaries)));
        $puantajHash = self::hashCanonical(array_map([self::class, 'attendancePayload'], $attendance['rows']));
        $izinHash = self::hashCanonical(array_map([self::class, 'leavePayload'], $izinler));
        $financeHash = self::hashCanonical([
            'candidates' => array_map([self::class, 'candidatePayloadStatic'], $finance['candidates'] ?? []),
            'finans' => array_map([self::class, 'financePayloadStatic'], $finance['finans_rows'] ?? []),
        ]);
        $legalHash = self::hashCanonical(array_map([self::class, 'legalPayloadStatic'], $legal));
        $emptyScopeHash = PersonelBordroKapsamService::emptyScopeFingerprint();
        $scopeHash = $scopeFingerprint !== null && $scopeFingerprint !== ''
            ? (string) $scopeFingerprint
            : $emptyScopeHash;

        // Bos kapsamda eski source_hash formulunu koru (mevcut snapshotlarla silent mismatch olmasin).
        $sourcePayload = [
            'contract_version' => self::CONTRACT_VERSION,
            'muhur_hash' => $muhurHash,
            'personel_set_hash' => $personelSetHash,
            'salary_source_hash' => $salaryHash,
            'puantaj_source_hash' => $puantajHash,
            'izin_source_hash' => $izinHash,
            'finance_source_hash' => $financeHash,
            'legal_parameter_hash' => $legalHash,
        ];
        if ($scopeHash !== $emptyScopeHash) {
            $sourcePayload['payroll_scope_hash'] = $scopeHash;
        }
        $sourceHash = self::hashCanonical($sourcePayload);

        return [
            'muhur_hash' => $muhurHash,
            'personel_set_hash' => $personelSetHash,
            'salary_source_hash' => $salaryHash,
            'puantaj_source_hash' => $puantajHash,
            'izin_source_hash' => $izinHash,
            'finance_source_hash' => $financeHash,
            'legal_parameter_hash' => $legalHash,
            'payroll_scope_hash' => $scopeHash,
            'source_hash' => $sourceHash,
        ];
    }

    /** @param mixed $value */
    public static function hashCanonical($value)
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Deterministic canonical yapi: assoc key'ler sirali, listeler sirasini korur.
     *
     * @param mixed $value
     * @return mixed
     */
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

    // ------------------------------------------------------------------
    // Snapshot create / cancel / read
    // ------------------------------------------------------------------

    /**
     * Snapshot olusturma; transaction icinde kaynaklar yeniden cozulur.
     *
     * @param array<string, mixed> $user
     * @return array{snapshot: array<string, mixed>, idempotent: bool, audit: array<string, mixed>|null}
     */
    public static function createSnapshot(PDO $pdo, $subeId, $yil, $ay, $expectedPreflightHash, array $user)
    {
        $attempts = 0;
        $maxAttempts = 4;
        while (true) {
            $attempts++;
            try {
                return self::createSnapshotOnce($pdo, $subeId, $yil, $ay, $expectedPreflightHash, $user);
            } catch (PDOException $e) {
                if ($attempts >= $maxAttempts || !self::isRetryableConcurrencyError($e)) {
                    throw $e;
                }
                usleep(25000 * $attempts);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{snapshot: array<string, mixed>, idempotent: bool, audit: array<string, mixed>|null}
     */
    private static function createSnapshotOnce(PDO $pdo, $subeId, $yil, $ay, $expectedPreflightHash, array $user)
    {
        $subeId = (int) $subeId;
        $yil = (int) $yil;
        $ay = (int) $ay;
        $expectedPreflightHash = (string) $expectedPreflightHash;

        $pdo->beginTransaction();
        try {
            PuantajDonemKilidiService::acquire($pdo, $subeId, $yil, $ay);
            $resolution = self::resolveSources($pdo, $subeId, $yil, $ay, true);
            $requestHash = self::requestHash($user, $subeId, $yil, $ay, 'create', ['expected_preflight_hash' => $expectedPreflightHash]);

            if (!$resolution['muhur']) {
                $audit = self::writeAudit($pdo, null, $subeId, $yil, $ay, null, self::AUDIT_PREFLIGHT_BLOCKED, 'BLOCKED', $user, $requestHash, $resolution);
                $pdo->commit();
                throw new MaasHesaplamaException('PAYROLL_PERIOD_NOT_SEALED', 'Donem muhurlenmemis; snapshot olusturulamaz.', 409, [
                    'audit' => self::mapAuditSummary($audit),
                    'blocker_codes' => array_keys(self::codeCounts($resolution['items'], self::SEVERITY_BLOCKER)),
                ]);
            }

            $muhurId = (int) $resolution['muhur']['id'];

            // Source-changed durumu asagida ozel PAYROLL_SNAPSHOT_SOURCE_CHANGED olarak ele alinir.
            $genericBlockerCount = 0;
            foreach ($resolution['items'] as $item) {
                if ((string) $item['severity'] === self::SEVERITY_BLOCKER
                    && (string) $item['code'] !== 'EXISTING_ACTIVE_SNAPSHOT_SOURCE_CHANGED') {
                    $genericBlockerCount++;
                }
            }

            if ($genericBlockerCount > 0) {
                $audit = self::writeAudit($pdo, null, $subeId, $yil, $ay, $muhurId, self::AUDIT_PREFLIGHT_BLOCKED, 'BLOCKED', $user, $requestHash, $resolution);
                $pdo->commit();
                throw new MaasHesaplamaException('PAYROLL_PREFLIGHT_BLOCKED', 'Preflight blocker iceriyor; snapshot olusturulamaz.', 409, [
                    'audit' => self::mapAuditSummary($audit),
                    'blocker_codes' => array_keys(self::codeCounts($resolution['items'], self::SEVERITY_BLOCKER)),
                ]);
            }

            // Idempotency stale kontrolunden once degerlendirilir: ayni source set
            // ile paralel/tekrar create her zaman ayni kanonik snapshot'i dondurur.
            $existing = $resolution['existing_snapshot'];
            if ($existing) {
                if ((string) $existing['source_hash'] === $resolution['hashes']['source_hash']) {
                    $audit = self::writeAudit($pdo, (int) $existing['id'], $subeId, $yil, $ay, $muhurId, self::AUDIT_SNAPSHOT_CREATE_IDEMPOTENT, 'EXISTING', $user, $requestHash, $resolution);
                    $pdo->commit();

                    return [
                        'snapshot' => self::mapSnapshotRow($existing),
                        'idempotent' => true,
                        'audit' => self::mapAuditSummary($audit),
                    ];
                }

                $audit = self::writeAudit($pdo, (int) $existing['id'], $subeId, $yil, $ay, $muhurId, self::AUDIT_REVISION_REQUEST_BLOCKED, 'CONFLICT', $user, $requestHash, $resolution);
                $pdo->commit();
                throw new MaasHesaplamaException('PAYROLL_SNAPSHOT_SOURCE_CHANGED', 'Aktif snapshot sonrasi kaynaklar degisti; once iptal edip explicit revision olusturun.', 409, [
                    'audit' => self::mapAuditSummary($audit),
                    'snapshot_id' => (int) $existing['id'],
                ]);
            }

            if ($expectedPreflightHash === '' || !hash_equals((string) $resolution['preflight_hash'], $expectedPreflightHash)) {
                $audit = self::writeAudit($pdo, null, $subeId, $yil, $ay, $muhurId, self::AUDIT_PREFLIGHT_BLOCKED, 'CONFLICT', $user, $requestHash, $resolution);
                $pdo->commit();
                throw new MaasHesaplamaException('PAYROLL_PREFLIGHT_STALE', 'Preflight sonucu guncel degil; yeniden preflight alin.', 409, [
                    'audit' => self::mapAuditSummary($audit),
                    'guncel_preflight_hash' => (string) $resolution['preflight_hash'],
                ]);
            }

            $snapshot = self::persistSnapshot($pdo, $resolution, $user);
            $audit = self::writeAudit($pdo, (int) $snapshot['id'], $subeId, $yil, $ay, $muhurId, self::AUDIT_SNAPSHOT_CREATE, 'CREATED', $user, $requestHash, $resolution, (string) $snapshot['snapshot_hash']);
            $pdo->commit();

            return [
                'snapshot' => $snapshot,
                'idempotent' => false,
                'audit' => self::mapAuditSummary($audit),
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof MaasHesaplamaException) {
                throw $e;
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000'
                && stripos($e->getMessage(), 'Duplicate') !== false) {
                throw new MaasHesaplamaException('PAYROLL_SNAPSHOT_ALREADY_EXISTS', 'Ayni donem icin aktif snapshot zaten var.', 409);
            }
            throw $e;
        }
    }

    private static function isRetryableConcurrencyError(PDOException $e)
    {
        $sqlState = (string) $e->getCode();
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        $message = $e->getMessage();

        return $sqlState === '40001'
            || $driverCode === 1213
            || $driverCode === 1205
            || stripos($message, 'Deadlock') !== false
            || stripos($message, 'Lock wait timeout') !== false;
    }

    /**
     * Root + personel + girdi satirlarini olusturur. Tum hash ve countlar
     * insert oncesi hesaplanir; root satiri sonradan UPDATE edilmez.
     *
     * @param array<string, mixed> $resolution
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function persistSnapshot(PDO $pdo, array $resolution, array $user)
    {
        $subeId = (int) $resolution['sube_id'];
        $yil = (int) $resolution['yil'];
        $ay = (int) $resolution['ay'];
        $muhurId = (int) $resolution['muhur']['id'];

        $revisionStmt = $pdo->prepare(
            'SELECT MAX(revision_no) AS max_revision,
                    (SELECT id FROM maas_hesaplama_donem_snapshotlari
                      WHERE sube_id = :sube_id2 AND yil = :yil2 AND ay = :ay2
                      ORDER BY revision_no DESC, id DESC LIMIT 1) AS last_id
             FROM maas_hesaplama_donem_snapshotlari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay'
        );
        $revisionStmt->execute([
            'sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay,
            'sube_id2' => $subeId, 'yil2' => $yil, 'ay2' => $ay,
        ]);
        $revisionRow = $revisionStmt->fetch(PDO::FETCH_ASSOC) ?: ['max_revision' => null, 'last_id' => null];
        $revisionNo = ((int) ($revisionRow['max_revision'] ?? 0)) + 1;
        $parentSnapshotId = $revisionRow['last_id'] !== null ? (int) $revisionRow['last_id'] : null;

        // Girdi payloadlarini hazirla
        $personelEntries = [];
        $girdiEntries = [];

        $girdiEntries[] = self::girdiEntry('MUHUR', 'puantaj_aylik_muhurleri', $muhurId, null, null, null, null, self::muhurPayload($resolution['muhur']));

        foreach ($resolution['personeller'] as $personelId => $personel) {
            $personelPayload = self::personelPayload($personel);
            $segments = $resolution['salaries'][$personelId] ?? [];
            $personelEntries[$personelId] = [
                'personel_id' => $personelId,
                'payload' => $personelPayload,
                'hash' => self::hashCanonical($personelPayload),
                'istihdam_baslangic' => (string) $personel['istihdam_baslangic'],
                'istihdam_bitis' => (string) $personel['istihdam_bitis'],
                'ucret_segment_sayisi' => count($segments),
                'puantaj_kayit_sayisi' => (int) ($resolution['attendance']['by_personel'][$personelId] ?? 0),
                'finans_kalem_sayisi' => 0,
            ];

            foreach ($segments as $segment) {
                $girdiEntries[] = self::girdiEntry(
                    'UCRET',
                    !empty($segment['virtual_legacy']) ? 'personeller' : 'personel_ucret_gecmisi',
                    $segment['id'],
                    $segment['revision_no'],
                    $segment['etki_baslangic'],
                    $segment['etki_bitis'],
                    $personelId,
                    $segment
                );
            }
        }

        foreach ($resolution['attendance']['rows'] as $row) {
            $personelId = (int) $row['personel_id'];
            $girdiEntries[] = self::girdiEntry(
                'PUANTAJ',
                'puantaj_aylik_muhur_satirlari',
                (int) $row['id'],
                null,
                (string) $row['tarih'],
                (string) $row['tarih'],
                isset($personelEntries[$personelId]) ? $personelId : null,
                self::attendancePayload($row)
            );
        }

        foreach ($resolution['izinler'] as $izin) {
            $personelId = (int) $izin['personel_id'];
            $girdiEntries[] = self::girdiEntry(
                'IZIN',
                'surecler',
                (int) $izin['id'],
                null,
                (string) $izin['baslangic_tarihi'],
                $izin['bitis_tarihi'] !== null ? (string) $izin['bitis_tarihi'] : null,
                isset($personelEntries[$personelId]) ? $personelId : null,
                self::leavePayload($izin)
            );
        }

        foreach ($resolution['finance']['candidates'] as $candidate) {
            $personelId = (int) $candidate['personel_id'];
            $payload = self::candidatePayloadStatic($candidate);
            $resolutionRow = $resolution['finance']['resolutions'][(int) $candidate['id']] ?? null;
            if ($resolutionRow !== null) {
                $payload['cakisma_cozumu'] = [
                    'id' => (int) $resolutionRow['id'],
                    'conflict_class' => (string) $resolutionRow['conflict_class'],
                    'karar_turu' => (string) $resolutionRow['karar_turu'],
                    'sonuc_hash' => (string) $resolutionRow['sonuc_hash'],
                    'karar_veren_user_id' => (int) $resolutionRow['karar_veren_user_id'],
                    'karar_zamani' => (string) $resolutionRow['karar_zamani'],
                ];
            }
            $girdiEntries[] = self::girdiEntry(
                'ETKI_ADAYI',
                'onayli_bildirim_puantaj_etki_adaylari',
                (int) $candidate['id'],
                null,
                (string) $candidate['tarih'],
                (string) $candidate['tarih'],
                isset($personelEntries[$personelId]) ? $personelId : null,
                $payload
            );
        }

        foreach ($resolution['finance']['finans_rows'] as $finansRow) {
            $personelId = (int) $finansRow['personel_id'];
            $payload = self::financePayloadStatic($finansRow);
            $payload['muhur_sonrasi'] = in_array((int) $finansRow['id'], $resolution['finance']['after_seal_ids'], true);
            if (isset($personelEntries[$personelId])) {
                $personelEntries[$personelId]['finans_kalem_sayisi']++;
            }
            $girdiEntries[] = self::girdiEntry(
                'FINANS',
                'ek_odeme_kesinti',
                (int) $finansRow['id'],
                null,
                null,
                null,
                isset($personelEntries[$personelId]) ? $personelId : null,
                $payload
            );
        }

        foreach ($resolution['legal'] as $legalRow) {
            $girdiEntries[] = self::girdiEntry(
                'MEVZUAT',
                'mevzuat_parametreleri',
                (int) $legalRow['id'],
                isset($legalRow['revision_no']) ? (int) $legalRow['revision_no'] : null,
                (string) $legalRow['gecerlilik_baslangic'],
                $legalRow['gecerlilik_bitis'] !== null ? (string) $legalRow['gecerlilik_bitis'] : null,
                null,
                self::legalPayloadStatic($legalRow)
            );
        }

        // sira_no'lari kaynak_turu bazinda ata ve hashleri hesapla
        $siraByTur = [];
        foreach ($girdiEntries as $index => $entry) {
            $tur = $entry['kaynak_turu'];
            $siraByTur[$tur] = ($siraByTur[$tur] ?? 0) + 1;
            $girdiEntries[$index]['sira_no'] = $siraByTur[$tur];
            $girdiEntries[$index]['payload_hash'] = self::hashCanonical($entry['payload']);
        }

        $personelHashes = array_map(static function (array $entry) {
            return $entry['hash'];
        }, array_values($personelEntries));
        $girdiHashes = array_map(static function (array $entry) {
            return $entry['payload_hash'];
        }, $girdiEntries);

        $snapshotHash = self::hashCanonical([
            'contract_version' => self::CONTRACT_VERSION,
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => (string) $resolution['donem'],
            'muhur_id' => $muhurId,
            'revision_no' => $revisionNo,
            'source_hash' => $resolution['hashes']['source_hash'],
            'preflight_hash' => (string) $resolution['preflight_hash'],
            'personel_hashes' => $personelHashes,
            'girdi_hashes' => $girdiHashes,
        ]);

        $cutoffAt = gmdate('Y-m-d H:i:s');
        $insertRoot = $pdo->prepare(
            'INSERT INTO maas_hesaplama_donem_snapshotlari (
                sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id,
                revision_no, parent_snapshot_id, state, contract_version, cutoff_at,
                preflight_hash, source_hash, snapshot_hash,
                personel_sayisi, girdi_sayisi, blocker_count, warning_count, created_by
             ) VALUES (
                :sube_id, :yil, :ay, :donem, :donem_baslangic, :donem_bitis, :muhur_id,
                :revision_no, :parent_snapshot_id, \'OLUSTURULDU\', :contract_version, :cutoff_at,
                :preflight_hash, :source_hash, :snapshot_hash,
                :personel_sayisi, :girdi_sayisi, :blocker_count, :warning_count, :created_by
             )'
        );
        $insertRoot->execute([
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => (string) $resolution['donem'],
            'donem_baslangic' => (string) $resolution['donem_baslangic'],
            'donem_bitis' => (string) $resolution['donem_bitis'],
            'muhur_id' => $muhurId,
            'revision_no' => $revisionNo,
            'parent_snapshot_id' => $parentSnapshotId,
            'contract_version' => self::CONTRACT_VERSION,
            'cutoff_at' => $cutoffAt,
            'preflight_hash' => (string) $resolution['preflight_hash'],
            'source_hash' => (string) $resolution['hashes']['source_hash'],
            'snapshot_hash' => $snapshotHash,
            'personel_sayisi' => count($personelEntries),
            'girdi_sayisi' => count($girdiEntries),
            'blocker_count' => 0,
            'warning_count' => (int) $resolution['warning_count'],
            'created_by' => self::actorId($user),
        ]);
        $snapshotId = (int) $pdo->lastInsertId();

        $personelSnapshotIds = [];
        $insertPersonel = $pdo->prepare(
            'INSERT INTO maas_hesaplama_personel_snapshotlari (
                donem_snapshot_id, personel_id, personel_snapshot_json, personel_snapshot_hash,
                istihdam_baslangic, istihdam_bitis, ucret_segment_sayisi, puantaj_kayit_sayisi, finans_kalem_sayisi
             ) VALUES (
                :donem_snapshot_id, :personel_id, :personel_snapshot_json, :personel_snapshot_hash,
                :istihdam_baslangic, :istihdam_bitis, :ucret_segment_sayisi, :puantaj_kayit_sayisi, :finans_kalem_sayisi
             )'
        );
        foreach ($personelEntries as $personelId => $entry) {
            $insertPersonel->execute([
                'donem_snapshot_id' => $snapshotId,
                'personel_id' => $personelId,
                'personel_snapshot_json' => json_encode(self::canonicalize($entry['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'personel_snapshot_hash' => $entry['hash'],
                'istihdam_baslangic' => $entry['istihdam_baslangic'],
                'istihdam_bitis' => $entry['istihdam_bitis'],
                'ucret_segment_sayisi' => $entry['ucret_segment_sayisi'],
                'puantaj_kayit_sayisi' => $entry['puantaj_kayit_sayisi'],
                'finans_kalem_sayisi' => $entry['finans_kalem_sayisi'],
            ]);
            $personelSnapshotIds[$personelId] = (int) $pdo->lastInsertId();
        }

        $insertGirdi = $pdo->prepare(
            'INSERT INTO maas_hesaplama_girdi_snapshotlari (
                donem_snapshot_id, personel_snapshot_id, kaynak_turu, kaynak_tablo, kaynak_id,
                kaynak_revision, etki_baslangic, etki_bitis, sira_no, payload_json, payload_hash
             ) VALUES (
                :donem_snapshot_id, :personel_snapshot_id, :kaynak_turu, :kaynak_tablo, :kaynak_id,
                :kaynak_revision, :etki_baslangic, :etki_bitis, :sira_no, :payload_json, :payload_hash
             )'
        );
        foreach ($girdiEntries as $entry) {
            $insertGirdi->execute([
                'donem_snapshot_id' => $snapshotId,
                'personel_snapshot_id' => $entry['personel_id'] !== null ? ($personelSnapshotIds[$entry['personel_id']] ?? null) : null,
                'kaynak_turu' => $entry['kaynak_turu'],
                'kaynak_tablo' => $entry['kaynak_tablo'],
                'kaynak_id' => $entry['kaynak_id'],
                'kaynak_revision' => $entry['kaynak_revision'],
                'etki_baslangic' => $entry['etki_baslangic'],
                'etki_bitis' => $entry['etki_bitis'],
                'sira_no' => $entry['sira_no'],
                'payload_json' => json_encode(self::canonicalize($entry['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload_hash' => $entry['payload_hash'],
            ]);
        }

        // Count dogrulamasi: kismi snapshot kesinlikle kalmamali
        $personelCount = (int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_personel_snapshotlari WHERE donem_snapshot_id = ' . $snapshotId)->fetchColumn();
        $girdiCount = (int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = ' . $snapshotId)->fetchColumn();
        if ($personelCount !== count($personelEntries) || $girdiCount !== count($girdiEntries)) {
            throw new MaasHesaplamaException('PAYROLL_SOURCE_INCONSISTENT', 'Snapshot count dogrulamasi basarisiz; islem geri alindi.', 500);
        }

        $row = self::fetchSnapshotRow($pdo, $snapshotId);
        if (!$row) {
            throw new MaasHesaplamaException('PAYROLL_SOURCE_INCONSISTENT', 'Snapshot satiri okunamadi.', 500);
        }

        return self::mapSnapshotRow($row);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{snapshot: array<string, mixed>, idempotent: bool, audit: array<string, mixed>|null}
     */
    public static function cancelSnapshot(PDO $pdo, $snapshotId, $neden, array $user)
    {
        $attempts = 0;
        $maxAttempts = 4;
        while (true) {
            $attempts++;
            try {
                return self::cancelSnapshotOnce($pdo, $snapshotId, $neden, $user);
            } catch (PDOException $e) {
                if ($attempts >= $maxAttempts || !self::isRetryableConcurrencyError($e)) {
                    throw $e;
                }
                usleep(25000 * $attempts);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{snapshot: array<string, mixed>, idempotent: bool, audit: array<string, mixed>|null}
     */
    private static function cancelSnapshotOnce(PDO $pdo, $snapshotId, $neden, array $user)
    {
        $snapshotId = (int) $snapshotId;
        $neden = trim((string) $neden);
        if ($neden === '') {
            throw new MaasHesaplamaException('VALIDATION_ERROR', 'Iptal nedeni zorunludur.', 400);
        }

        $pdo->beginTransaction();
        try {
            // Oncelikle donem kimligini ogren (kilitsiz), sonra create ile ayni
            // donem kilidini al; en son snapshot satirini FOR UPDATE ile kilitle.
            // Bu sira create+cancel yarisiinda deadlock'u engeller.
            $row = self::fetchSnapshotRow($pdo, $snapshotId, false);
            if (!$row) {
                $pdo->rollBack();
                throw new MaasHesaplamaException('PAYROLL_SNAPSHOT_NOT_FOUND', 'Snapshot bulunamadi.', 404);
            }

            PuantajDonemKilidiService::acquire($pdo, (int) $row['sube_id'], (int) $row['yil'], (int) $row['ay']);
            $row = self::fetchSnapshotRow($pdo, $snapshotId, true);
            if (!$row) {
                $pdo->rollBack();
                throw new MaasHesaplamaException('PAYROLL_SNAPSHOT_NOT_FOUND', 'Snapshot bulunamadi.', 404);
            }
            $requestHash = self::requestHash($user, (int) $row['sube_id'], (int) $row['yil'], (int) $row['ay'], 'cancel', [
                'snapshot_id' => $snapshotId,
                'neden' => $neden,
            ]);

            if ((string) $row['state'] === 'IPTAL') {
                $audit = self::writeCancelAudit($pdo, $row, $user, $requestHash, $neden);
                $pdo->commit();

                return [
                    'snapshot' => self::mapSnapshotRow($row),
                    'idempotent' => true,
                    'audit' => self::mapAuditSummary($audit),
                ];
            }

            $update = $pdo->prepare(
                "UPDATE maas_hesaplama_donem_snapshotlari
                 SET state = 'IPTAL', iptal_edildi_by = :actor_id, iptal_edildi_at = CURRENT_TIMESTAMP, iptal_nedeni = :neden
                 WHERE id = :id"
            );
            $update->execute([
                'actor_id' => self::actorId($user),
                'neden' => $neden,
                'id' => $snapshotId,
            ]);

            $after = self::fetchSnapshotRow($pdo, $snapshotId);
            $audit = self::writeCancelAudit($pdo, $after ?: $row, $user, $requestHash, $neden);
            $pdo->commit();

            return [
                'snapshot' => self::mapSnapshotRow($after ?: $row),
                'idempotent' => false,
                'audit' => self::mapAuditSummary($audit),
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof MaasHesaplamaException) {
                throw $e;
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public static function getSnapshotDetail(PDO $pdo, $snapshotId, $includePayloads = false)
    {
        $row = self::fetchSnapshotRow($pdo, (int) $snapshotId);
        if (!$row) {
            return null;
        }

        $personelStmt = $pdo->prepare(
            'SELECT * FROM maas_hesaplama_personel_snapshotlari WHERE donem_snapshot_id = :id ORDER BY personel_id ASC'
        );
        $personelStmt->execute(['id' => (int) $snapshotId]);
        $personelRows = $personelStmt->fetchAll(PDO::FETCH_ASSOC);

        $girdiStmt = $pdo->prepare(
            'SELECT kaynak_turu, COUNT(*) AS adet FROM maas_hesaplama_girdi_snapshotlari
             WHERE donem_snapshot_id = :id GROUP BY kaynak_turu ORDER BY kaynak_turu ASC'
        );
        $girdiStmt->execute(['id' => (int) $snapshotId]);
        $girdiOzeti = [];
        foreach ($girdiStmt->fetchAll(PDO::FETCH_ASSOC) as $girdiRow) {
            $girdiOzeti[(string) $girdiRow['kaynak_turu']] = (int) $girdiRow['adet'];
        }

        $verification = self::verifySnapshotHash($pdo, $row);

        $personeller = array_map(static function (array $personelRow) use ($includePayloads) {
            $decoded = json_decode((string) $personelRow['personel_snapshot_json'], true);
            $out = [
                'id' => (int) $personelRow['id'],
                'personel_id' => (int) $personelRow['personel_id'],
                'personel_snapshot_hash' => (string) $personelRow['personel_snapshot_hash'],
                'istihdam_baslangic' => (string) $personelRow['istihdam_baslangic'],
                'istihdam_bitis' => $personelRow['istihdam_bitis'] !== null ? (string) $personelRow['istihdam_bitis'] : null,
                'ucret_segment_sayisi' => (int) $personelRow['ucret_segment_sayisi'],
                'puantaj_kayit_sayisi' => (int) $personelRow['puantaj_kayit_sayisi'],
                'finans_kalem_sayisi' => (int) $personelRow['finans_kalem_sayisi'],
                'ad_soyad' => is_array($decoded) && isset($decoded['ad_soyad']) ? (string) $decoded['ad_soyad'] : null,
            ];
            if ($includePayloads) {
                $out['personel_snapshot'] = $decoded;
            }

            return $out;
        }, $personelRows);

        return array_merge(self::mapSnapshotRow($row), [
            'personeller' => $personeller,
            'girdi_ozeti' => $girdiOzeti,
            'hash_dogrulama' => $verification,
        ]);
    }

    /**
     * Snapshot hash'ini saklanan child satirlarindan yeniden hesaplayip dogrular.
     *
     * @param array<string, mixed> $row
     * @return array{beklenen: string, hesaplanan: string, dogrulandi: bool}
     */
    public static function verifySnapshotHash(PDO $pdo, array $row)
    {
        $snapshotId = (int) $row['id'];
        $personelStmt = $pdo->prepare(
            'SELECT personel_snapshot_hash FROM maas_hesaplama_personel_snapshotlari
             WHERE donem_snapshot_id = :id ORDER BY personel_id ASC'
        );
        $personelStmt->execute(['id' => $snapshotId]);
        $personelHashes = array_map(static function (array $r) {
            return (string) $r['personel_snapshot_hash'];
        }, $personelStmt->fetchAll(PDO::FETCH_ASSOC));

        // Girdi satirlari snapshot_hash hesabındaki sirayla insert edilir ve immutable'dir;
        // id sirasi deterministik olarak ayni siralamayi verir (SQLite/MariaDB uyumlu).
        $girdiStmt = $pdo->prepare(
            'SELECT payload_hash FROM maas_hesaplama_girdi_snapshotlari
             WHERE donem_snapshot_id = :id
             ORDER BY id ASC'
        );
        $girdiStmt->execute(['id' => $snapshotId]);
        $girdiHashes = array_map(static function (array $r) {
            return (string) $r['payload_hash'];
        }, $girdiStmt->fetchAll(PDO::FETCH_ASSOC));

        $computed = self::hashCanonical([
            'contract_version' => (string) $row['contract_version'],
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'donem' => (string) $row['donem'],
            'muhur_id' => (int) $row['muhur_id'],
            'revision_no' => (int) $row['revision_no'],
            'source_hash' => (string) $row['source_hash'],
            'preflight_hash' => (string) $row['preflight_hash'],
            'personel_hashes' => $personelHashes,
            'girdi_hashes' => $girdiHashes,
        ]);

        return [
            'beklenen' => (string) $row['snapshot_hash'],
            'hesaplanan' => $computed,
            'dogrulandi' => hash_equals((string) $row['snapshot_hash'], $computed),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function listSnapshots(PDO $pdo, $subeId, $yil = null, $ay = null)
    {
        $where = ['sube_id = :sube_id'];
        $params = ['sube_id' => (int) $subeId];
        if ($yil !== null) {
            $where[] = 'yil = :yil';
            $params['yil'] = (int) $yil;
        }
        if ($ay !== null) {
            $where[] = 'ay = :ay';
            $params['ay'] = (int) $ay;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM maas_hesaplama_donem_snapshotlari
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY yil DESC, ay DESC, revision_no DESC, id DESC'
        );
        $stmt->execute($params);

        return array_map([self::class, 'mapSnapshotRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    public static function listAudits(PDO $pdo, $subeId, $yil, $ay, $snapshotId = null)
    {
        $where = ['sube_id = :sube_id', 'yil = :yil', 'ay = :ay'];
        $params = ['sube_id' => (int) $subeId, 'yil' => (int) $yil, 'ay' => (int) $ay];
        if ($snapshotId !== null) {
            $where[] = 'donem_snapshot_id = :snapshot_id';
            $params['snapshot_id'] = (int) $snapshotId;
        }
        $stmt = $pdo->prepare(
            'SELECT id, donem_snapshot_id, sube_id, yil, ay, muhur_id, aksiyon, sonuc,
                    actor_id, actor_rol, request_hash, preflight_hash, source_hash, result_hash,
                    blocker_count, warning_count, created_at
             FROM maas_hesaplama_snapshot_auditleri
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY created_at DESC, id DESC
             LIMIT 100'
        );
        $stmt->execute($params);

        return array_map(static function (array $row) {
            return [
                'id' => (int) $row['id'],
                'donem_snapshot_id' => $row['donem_snapshot_id'] !== null ? (int) $row['donem_snapshot_id'] : null,
                'sube_id' => (int) $row['sube_id'],
                'yil' => (int) $row['yil'],
                'ay' => (int) $row['ay'],
                'muhur_id' => $row['muhur_id'] !== null ? (int) $row['muhur_id'] : null,
                'aksiyon' => (string) $row['aksiyon'],
                'sonuc' => (string) $row['sonuc'],
                'actor_id' => (int) $row['actor_id'],
                'actor_rol' => $row['actor_rol'] !== null ? (string) $row['actor_rol'] : null,
                'request_hash' => (string) $row['request_hash'],
                'preflight_hash' => (string) $row['preflight_hash'],
                'source_hash' => $row['source_hash'] !== null ? (string) $row['source_hash'] : null,
                'result_hash' => (string) $row['result_hash'],
                'blocker_count' => (int) $row['blocker_count'],
                'warning_count' => (int) $row['warning_count'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public static function fetchSnapshotRow(PDO $pdo, $snapshotId, $forUpdate = false)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM maas_hesaplama_donem_snapshotlari WHERE id = :id LIMIT 1' . ($forUpdate ? self::forUpdate($pdo) : '')
        );
        $stmt->execute(['id' => (int) $snapshotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function mapSnapshotRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'snapshot_id' => (int) $row['id'],
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'donem' => (string) $row['donem'],
            'donem_baslangic' => (string) $row['donem_baslangic'],
            'donem_bitis' => (string) $row['donem_bitis'],
            'muhur_id' => (int) $row['muhur_id'],
            'revision_no' => (int) $row['revision_no'],
            'parent_snapshot_id' => $row['parent_snapshot_id'] !== null ? (int) $row['parent_snapshot_id'] : null,
            'state' => (string) $row['state'],
            'contract_version' => (string) $row['contract_version'],
            'cutoff_at' => (string) $row['cutoff_at'],
            'preflight_hash' => (string) $row['preflight_hash'],
            'source_hash' => (string) $row['source_hash'],
            'snapshot_hash' => (string) $row['snapshot_hash'],
            'personel_sayisi' => (int) $row['personel_sayisi'],
            'girdi_sayisi' => (int) $row['girdi_sayisi'],
            'blocker_count' => (int) $row['blocker_count'],
            'warning_count' => (int) $row['warning_count'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'iptal_edildi_by' => $row['iptal_edildi_by'] !== null ? (int) $row['iptal_edildi_by'] : null,
            'iptal_edildi_at' => $row['iptal_edildi_at'] !== null ? (string) $row['iptal_edildi_at'] : null,
            'iptal_nedeni' => $row['iptal_nedeni'] !== null ? (string) $row['iptal_nedeni'] : null,
        ];
    }

    // ------------------------------------------------------------------
    // Audit
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     */
    public static function requestHash(array $user, $subeId, $yil, $ay, $action, array $payload)
    {
        ksort($payload);

        return self::hashCanonical([
            'actor_id' => (int) ($user['id'] ?? 0),
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'action' => (string) $action,
            'payload' => $payload,
        ]);
    }

    /**
     * Idempotent audit: ayni (sube, yil, ay, aksiyon, request_hash) icin tek satir.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $resolution
     * @return array<string, mixed>|null
     */
    private static function writeAudit(PDO $pdo, $snapshotId, $subeId, $yil, $ay, $muhurId, $aksiyon, $sonuc, array $user, $requestHash, array $resolution, $snapshotHash = null)
    {
        $existing = self::findAuditByIdempotency($pdo, $subeId, $yil, $ay, $aksiyon, $requestHash);
        if ($existing) {
            return $existing;
        }

        $resultHash = self::hashCanonical([
            'aksiyon' => (string) $aksiyon,
            'sonuc' => (string) $sonuc,
            'snapshot_id' => $snapshotId !== null ? (int) $snapshotId : null,
            'preflight_hash' => (string) $resolution['preflight_hash'],
            'source_hash' => (string) $resolution['hashes']['source_hash'],
            'snapshot_hash' => $snapshotHash,
            'blocker_count' => (int) $resolution['blocker_count'],
            'warning_count' => (int) $resolution['warning_count'],
        ]);

        // Audit snapshot'inda tam ucret payload'i tutulmaz; teknik delil yeterlidir.
        $auditSnapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'donem' => (string) $resolution['donem'],
            'muhur_id' => $muhurId !== null ? (int) $muhurId : null,
            'blocker_codes' => self::codeCounts($resolution['items'], self::SEVERITY_BLOCKER),
            'warning_codes' => self::codeCounts($resolution['items'], self::SEVERITY_WARNING),
            'personel_sayisi' => count($resolution['personeller']),
            'hashes' => $resolution['hashes'],
            'preflight_hash' => (string) $resolution['preflight_hash'],
        ];

        return self::insertAuditRow($pdo, [
            'donem_snapshot_id' => $snapshotId !== null ? (int) $snapshotId : null,
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'muhur_id' => $muhurId !== null ? (int) $muhurId : null,
            'aksiyon' => (string) $aksiyon,
            'sonuc' => (string) $sonuc,
            'actor_id' => (int) ($user['id'] ?? 0),
            'actor_rol' => isset($user['rol']) ? (string) $user['rol'] : null,
            'request_hash' => (string) $requestHash,
            'preflight_hash' => (string) $resolution['preflight_hash'],
            'source_hash' => (string) $resolution['hashes']['source_hash'],
            'result_hash' => $resultHash,
            'blocker_count' => (int) $resolution['blocker_count'],
            'warning_count' => (int) $resolution['warning_count'],
            'snapshot_json' => json_encode(self::canonicalize($auditSnapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string, mixed> $row snapshot satiri
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private static function writeCancelAudit(PDO $pdo, array $row, array $user, $requestHash, $neden)
    {
        $subeId = (int) $row['sube_id'];
        $yil = (int) $row['yil'];
        $ay = (int) $row['ay'];
        $existing = self::findAuditByIdempotency($pdo, $subeId, $yil, $ay, self::AUDIT_SNAPSHOT_CANCEL, $requestHash);
        if ($existing) {
            return $existing;
        }

        $resultHash = self::hashCanonical([
            'aksiyon' => self::AUDIT_SNAPSHOT_CANCEL,
            'sonuc' => 'CANCELLED',
            'snapshot_id' => (int) $row['id'],
            'snapshot_hash' => (string) $row['snapshot_hash'],
            'neden' => (string) $neden,
        ]);

        return self::insertAuditRow($pdo, [
            'donem_snapshot_id' => (int) $row['id'],
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'muhur_id' => (int) $row['muhur_id'],
            'aksiyon' => self::AUDIT_SNAPSHOT_CANCEL,
            'sonuc' => 'CANCELLED',
            'actor_id' => (int) ($user['id'] ?? 0),
            'actor_rol' => isset($user['rol']) ? (string) $user['rol'] : null,
            'request_hash' => (string) $requestHash,
            'preflight_hash' => (string) $row['preflight_hash'],
            'source_hash' => (string) $row['source_hash'],
            'result_hash' => $resultHash,
            'blocker_count' => 0,
            'warning_count' => 0,
            'snapshot_json' => json_encode([
                'schema_version' => self::SCHEMA_VERSION,
                'snapshot_id' => (int) $row['id'],
                'revision_no' => (int) $row['revision_no'],
                'neden' => (string) $neden,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @param array<string, mixed> $fields @return array<string, mixed>|null */
    private static function insertAuditRow(PDO $pdo, array $fields)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO maas_hesaplama_snapshot_auditleri (
                donem_snapshot_id, sube_id, yil, ay, muhur_id, aksiyon, sonuc,
                actor_id, actor_rol, request_hash, preflight_hash, source_hash, result_hash,
                blocker_count, warning_count, snapshot_json
             ) VALUES (
                :donem_snapshot_id, :sube_id, :yil, :ay, :muhur_id, :aksiyon, :sonuc,
                :actor_id, :actor_rol, :request_hash, :preflight_hash, :source_hash, :result_hash,
                :blocker_count, :warning_count, :snapshot_json
             )'
        );
        try {
            $stmt->execute($fields);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return self::findAuditByIdempotency($pdo, (int) $fields['sube_id'], (int) $fields['yil'], (int) $fields['ay'], (string) $fields['aksiyon'], (string) $fields['request_hash']);
            }
            throw $e;
        }

        return self::findAuditByIdempotency($pdo, (int) $fields['sube_id'], (int) $fields['yil'], (int) $fields['ay'], (string) $fields['aksiyon'], (string) $fields['request_hash']);
    }

    /** @return array<string, mixed>|null */
    private static function findAuditByIdempotency(PDO $pdo, $subeId, $yil, $ay, $aksiyon, $requestHash)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM maas_hesaplama_snapshot_auditleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND aksiyon = :aksiyon AND request_hash = :request_hash
             LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'aksiyon' => (string) $aksiyon,
            'request_hash' => (string) $requestHash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed>|null $audit @return array<string, mixed>|null */
    public static function mapAuditSummary($audit)
    {
        if (!is_array($audit)) {
            return null;
        }

        return [
            'id' => (int) ($audit['id'] ?? 0),
            'aksiyon' => (string) ($audit['aksiyon'] ?? ''),
            'sonuc' => (string) ($audit['sonuc'] ?? ''),
            'donem_snapshot_id' => isset($audit['donem_snapshot_id']) && $audit['donem_snapshot_id'] !== null ? (int) $audit['donem_snapshot_id'] : null,
            'request_hash' => (string) ($audit['request_hash'] ?? ''),
            'preflight_hash' => (string) ($audit['preflight_hash'] ?? ''),
            'result_hash' => (string) ($audit['result_hash'] ?? ''),
            'blocker_count' => (int) ($audit['blocker_count'] ?? 0),
            'warning_count' => (int) ($audit['warning_count'] ?? 0),
            'created_at' => (string) ($audit['created_at'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // Payload builders
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $muhur @return array<string, mixed> */
    private static function muhurPayload(array $muhur)
    {
        return [
            'id' => (int) $muhur['id'],
            'sube_id' => (int) $muhur['sube_id'],
            'yil' => (int) $muhur['yil'],
            'ay' => (int) $muhur['ay'],
            'donem' => (string) $muhur['donem'],
            'durum' => (string) $muhur['durum'],
            'muhurlenen_kayit_sayisi' => (int) $muhur['muhurlenen_kayit_sayisi'],
            'created_by' => $muhur['created_by'] !== null ? (int) $muhur['created_by'] : null,
            'created_at' => self::normalizeTimestamp($muhur['created_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $personel @return array<string, mixed> */
    private static function personelPayload(array $personel)
    {
        $payload = $personel;
        unset($payload['muhurlu_kayit_var_mi']);

        return $payload;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function attendancePayload(array $row)
    {
        return [
            'muhur_satir_id' => (int) $row['id'],
            'muhur_id' => (int) $row['muhur_id'],
            'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'],
            'gun_tipi' => $row['gun_tipi'] !== null ? (string) $row['gun_tipi'] : null,
            'hareket_durumu' => $row['hareket_durumu'] !== null ? (string) $row['hareket_durumu'] : null,
            'dayanak' => $row['dayanak'] !== null ? (string) $row['dayanak'] : null,
            'durumu_bildirdi_mi' => $row['durumu_bildirdi_mi'] !== null ? (int) $row['durumu_bildirdi_mi'] : null,
            'durum_bildirim_aciklamasi' => $row['durum_bildirim_aciklamasi'] !== null ? (string) $row['durum_bildirim_aciklamasi'] : null,
            'hesap_etkisi' => $row['hesap_etkisi'] !== null ? (string) $row['hesap_etkisi'] : null,
            'beklenen_giris_saati' => $row['beklenen_giris_saati'] !== null ? (string) $row['beklenen_giris_saati'] : null,
            'beklenen_cikis_saati' => $row['beklenen_cikis_saati'] !== null ? (string) $row['beklenen_cikis_saati'] : null,
            'giris_saati' => $row['giris_saati'] !== null ? (string) $row['giris_saati'] : null,
            'cikis_saati' => $row['cikis_saati'] !== null ? (string) $row['cikis_saati'] : null,
            'gec_kalma_dakika' => isset($row['gec_kalma_dakika']) && $row['gec_kalma_dakika'] !== null ? (int) $row['gec_kalma_dakika'] : null,
            'erken_cikis_dakika' => isset($row['erken_cikis_dakika']) && $row['erken_cikis_dakika'] !== null ? (int) $row['erken_cikis_dakika'] : null,
            'gercek_mola_dakika' => $row['gercek_mola_dakika'] !== null ? (int) $row['gercek_mola_dakika'] : null,
            'hesaplanan_mola_dakika' => $row['hesaplanan_mola_dakika'] !== null ? (int) $row['hesaplanan_mola_dakika'] : null,
            'net_calisma_suresi_dakika' => $row['net_calisma_suresi_dakika'] !== null ? (int) $row['net_calisma_suresi_dakika'] : null,
            'gunluk_brut_sure_dakika' => $row['gunluk_brut_sure_dakika'] !== null ? (int) $row['gunluk_brut_sure_dakika'] : null,
            'hafta_tatili_hak_kazandi_mi' => $row['hafta_tatili_hak_kazandi_mi'] !== null ? (int) $row['hafta_tatili_hak_kazandi_mi'] : null,
            'kontrol_durumu' => (string) $row['kontrol_durumu'],
            'kaynak' => $row['kaynak'] !== null ? (string) $row['kaynak'] : null,
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'created_at' => self::normalizeTimestamp($row['created_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $izin @return array<string, mixed> */
    private static function leavePayload(array $izin)
    {
        return [
            'surec_id' => (int) $izin['id'],
            'personel_id' => (int) $izin['personel_id'],
            'surec_turu' => (string) $izin['surec_turu'],
            'alt_tur' => $izin['alt_tur'] !== null ? (string) $izin['alt_tur'] : null,
            'baslangic_tarihi' => (string) $izin['baslangic_tarihi'],
            'bitis_tarihi' => $izin['bitis_tarihi'] !== null ? (string) $izin['bitis_tarihi'] : null,
            'ucretli_mi' => (int) $izin['ucretli_mi'],
            'aciklama' => $izin['aciklama'] !== null ? (string) $izin['aciklama'] : null,
            'state' => (string) $izin['state'],
            'created_at' => self::normalizeTimestamp($izin['created_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $candidate @return array<string, mixed> */
    public static function candidatePayloadStatic(array $candidate)
    {
        $state = strtoupper((string) $candidate['state']);

        return [
            'aday_id' => (int) $candidate['id'],
            'personel_id' => (int) $candidate['personel_id'],
            'tarih' => (string) $candidate['tarih'],
            'bildirim_turu' => (string) $candidate['bildirim_turu'],
            'bildirim_alt_tur' => $candidate['bildirim_alt_tur'] !== null ? (string) $candidate['bildirim_alt_tur'] : null,
            'etki_turu' => (string) $candidate['etki_turu'],
            'etki_miktari' => $candidate['etki_miktari'] !== null ? (int) $candidate['etki_miktari'] : null,
            'etki_birimi' => $candidate['etki_birimi'] !== null ? (string) $candidate['etki_birimi'] : null,
            'state' => $state,
            'conflict_code' => $candidate['conflict_code'] !== null ? (string) $candidate['conflict_code'] : null,
            'source_hash' => $candidate['source_hash'] !== null ? (string) $candidate['source_hash'] : null,
            'mevcut_puantaj_id' => $candidate['mevcut_puantaj_id'] !== null ? (int) $candidate['mevcut_puantaj_id'] : null,
            'parasal_uygulanacak_kalem' => $state === 'UYGULANDI',
            'karar_delili' => $state === 'YOK_SAYILDI' ? 'YOK_SAYILDI' : null,
            'updated_at' => self::normalizeTimestamp($candidate['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function financePayloadStatic(array $row)
    {
        return [
            'kayit_id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'donem' => (string) $row['donem'],
            'kalem_turu' => (string) $row['kalem_turu'],
            'tutar' => (string) $row['tutar'],
            'gun_sayisi' => $row['gun_sayisi'] !== null ? (int) $row['gun_sayisi'] : null,
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'state' => (string) $row['state'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => self::normalizeTimestamp($row['created_at'] ?? null),
            'updated_at' => self::normalizeTimestamp($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function legalPayloadStatic(array $row)
    {
        return [
            'parametre_id' => (int) $row['id'],
            'parametre_kodu' => (string) $row['parametre_kodu'],
            'deger_tipi' => (string) $row['deger_tipi'],
            'sayisal_deger' => $row['sayisal_deger'] !== null ? (string) $row['sayisal_deger'] : null,
            'metin_deger' => $row['metin_deger'] !== null ? (string) $row['metin_deger'] : null,
            'birim' => $row['birim'] !== null ? (string) $row['birim'] : null,
            'gecerlilik_baslangic' => (string) $row['gecerlilik_baslangic'],
            'gecerlilik_bitis' => $row['gecerlilik_bitis'] !== null ? (string) $row['gecerlilik_bitis'] : null,
            'kaynak_referansi' => $row['kaynak_referansi'] !== null ? (string) $row['kaynak_referansi'] : null,
            'revision_no' => isset($row['revision_no']) ? (int) $row['revision_no'] : null,
        ];
    }

    // ------------------------------------------------------------------
    // Yardimcilar
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function girdiEntry($kaynakTuru, $kaynakTablo, $kaynakId, $kaynakRevision, $etkiBaslangic, $etkiBitis, $personelId, array $payload)
    {
        return [
            'kaynak_turu' => (string) $kaynakTuru,
            'kaynak_tablo' => (string) $kaynakTablo,
            'kaynak_id' => $kaynakId !== null ? (int) $kaynakId : null,
            'kaynak_revision' => $kaynakRevision !== null ? (int) $kaynakRevision : null,
            'etki_baslangic' => $etkiBaslangic,
            'etki_bitis' => $etkiBitis,
            'personel_id' => $personelId,
            'payload' => $payload,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function resolveLegacySalary(PDO $pdo, $personelId, $bas, $bit)
    {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM personel_ucret_gecmisi WHERE personel_id = :personel_id');
        $countStmt->execute(['personel_id' => (int) $personelId]);
        if ((int) $countStmt->fetchColumn() > 0) {
            return null;
        }
        $legacyStmt = $pdo->prepare('SELECT id, maas_tutari, ise_giris_tarihi FROM personeller WHERE id = :id LIMIT 1');
        $legacyStmt->execute(['id' => (int) $personelId]);
        $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$legacy || $legacy['maas_tutari'] === null || (float) $legacy['maas_tutari'] <= 0) {
            return null;
        }
        $start = !empty($legacy['ise_giris_tarihi']) ? (string) $legacy['ise_giris_tarihi'] : '1900-01-01';
        if ($start > $bit) {
            return null;
        }

        return [
            'id' => null,
            'personel_id' => (int) $personelId,
            'ucret_tutari' => (string) $legacy['maas_tutari'],
            'ucret_turu' => 'NET',
            'para_birimi' => 'TRY',
            'gecerlilik_baslangic' => $start,
            'gecerlilik_bitis' => null,
            'state' => 'AKTIF',
            'kaynak' => 'PERSONEL_KAYDI_MIGRASYON',
            'aciklama' => null,
            'revision_no' => null,
            'virtual' => true,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function findSeal(PDO $pdo, $subeId, $yil, $ay, $forUpdate = false)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM puantaj_aylik_muhurleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay LIMIT 1' . ($forUpdate ? self::forUpdate($pdo) : '')
        );
        $stmt->execute(['sube_id' => (int) $subeId, 'yil' => (int) $yil, 'ay' => (int) $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function findActiveSnapshot(PDO $pdo, $subeId, $yil, $ay, $forUpdate = false)
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM maas_hesaplama_donem_snapshotlari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay AND state = 'OLUSTURULDU'
             LIMIT 1" . ($forUpdate ? self::forUpdate($pdo) : '')
        );
        $stmt->execute(['sube_id' => (int) $subeId, 'yil' => (int) $yil, 'ay' => (int) $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $resolution @return array<int, array<string, mixed>> */
    private static function buildPersonnelSummary(array $resolution)
    {
        $summary = [];
        foreach ($resolution['personeller'] as $personelId => $personel) {
            $segments = $resolution['salaries'][$personelId] ?? [];
            $finansCount = 0;
            foreach ($resolution['finance']['finans_rows'] as $finansRow) {
                if ((int) $finansRow['personel_id'] === $personelId) {
                    $finansCount++;
                }
            }
            $blockerCount = 0;
            $warningCount = 0;
            foreach ($resolution['items'] as $item) {
                if ((int) ($item['personel_id'] ?? 0) === $personelId) {
                    if ($item['severity'] === self::SEVERITY_BLOCKER) {
                        $blockerCount++;
                    } elseif ($item['severity'] === self::SEVERITY_WARNING) {
                        $warningCount++;
                    }
                }
            }
            $summary[] = [
                'personel_id' => $personelId,
                'ad_soyad' => (string) $personel['ad_soyad'],
                'istihdam_baslangic' => (string) $personel['istihdam_baslangic'],
                'istihdam_bitis' => (string) $personel['istihdam_bitis'],
                'ucret_segment_sayisi' => count($segments),
                'puantaj_kayit_sayisi' => (int) ($resolution['attendance']['by_personel'][$personelId] ?? 0),
                'finans_kalem_sayisi' => $finansCount,
                'blocker_count' => $blockerCount,
                'warning_count' => $warningCount,
                'hazir_mi' => $blockerCount === 0,
            ];
        }

        return $summary;
    }

    /** @param array<string, mixed> $resolution @return array<string, int> */
    private static function buildSourceSummary(array $resolution)
    {
        $segmentCount = 0;
        foreach ($resolution['salaries'] as $segments) {
            $segmentCount += count($segments);
        }

        return [
            'personel_sayisi' => count($resolution['personeller']),
            'ucret_segment_sayisi' => $segmentCount,
            'puantaj_kayit_sayisi' => count($resolution['attendance']['rows']),
            'izin_kayit_sayisi' => count($resolution['izinler']),
            'etki_aday_sayisi' => count($resolution['finance']['candidates']),
            'finans_kalem_sayisi' => count($resolution['finance']['finans_rows']),
            'mevzuat_parametre_sayisi' => count($resolution['legal']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $personeller
     * @param array<int, array<int, array<string, mixed>>> $salaries
     * @param array{rows: array<int, array<string, mixed>>} $attendance
     * @param array<string, mixed> $finance
     * @param array<int, array<string, mixed>> $legal
     * @return array<int, array<string, mixed>>
     */
    private static function buildInfoItems(array $personeller, array $salaries, array $attendance, array $finance, array $legal)
    {
        $segmentCount = 0;
        foreach ($salaries as $segments) {
            $segmentCount += count($segments);
        }

        return [
            self::issue(self::SEVERITY_INFO, 'PERSONNEL_COUNT', 'Bordro kumesindeki personel sayisi.', 'personel', null, null, ['adet' => count($personeller)]),
            self::issue(self::SEVERITY_INFO, 'SALARY_SEGMENT_COUNT', 'Cozumlenen ucret segmenti sayisi.', 'ucret', null, null, ['adet' => $segmentCount]),
            self::issue(self::SEVERITY_INFO, 'PUANTAJ_COUNT', 'Muhurlu puantaj kaydi sayisi.', 'puantaj', null, null, ['adet' => count($attendance['rows'])]),
            self::issue(self::SEVERITY_INFO, 'FINANCE_ITEM_COUNT', 'Finans kalemi sayisi.', 'finans', null, null, ['adet' => count($finance['finans_rows'] ?? [])]),
            self::issue(self::SEVERITY_INFO, 'LEGAL_PARAMETER_COUNT', 'Mevzuat parametresi sayisi.', 'mevzuat', null, null, ['adet' => count($legal)]),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private static function issue($severity, $code, $message, $recordType, $recordId, $personelId, array $metadata, $personelAdi = null)
    {
        return [
            'severity' => (string) $severity,
            'code' => (string) $code,
            'message' => (string) $message,
            'record_type' => (string) $recordType,
            'record_id' => $recordId !== null ? (int) $recordId : null,
            'personel_id' => $personelId !== null ? (int) $personelId : null,
            'personel_adi' => $personelAdi !== null ? (string) $personelAdi : null,
            'metadata' => $metadata,
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function countBySeverity(array $items, $severity)
    {
        $count = 0;
        foreach ($items as $item) {
            if ((string) $item['severity'] === (string) $severity) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<int, array<string, mixed>> $items @return array<string, int> */
    private static function codeCounts(array $items, $severity)
    {
        $out = [];
        foreach ($items as $item) {
            if ((string) $item['severity'] !== (string) $severity) {
                continue;
            }
            $code = (string) $item['code'];
            $out[$code] = ($out[$code] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    private static function fetchSube(PDO $pdo, $subeId)
    {
        $stmt = $pdo->prepare('SELECT id, kod, ad FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['id' => (int) $subeId, 'kod' => null, 'ad' => null];
        }

        return ['id' => (int) $row['id'], 'kod' => (string) $row['kod'], 'ad' => (string) $row['ad']];
    }

    public static function maskTc($tc)
    {
        $tc = (string) $tc;
        if (strlen($tc) < 5) {
            return str_repeat('*', strlen($tc));
        }

        return substr($tc, 0, 3) . str_repeat('*', strlen($tc) - 5) . substr($tc, -2);
    }

    public static function dayBefore($date)
    {
        return (new \DateTimeImmutable((string) $date))->modify('-1 day')->format('Y-m-d');
    }

    public static function dayAfter($date)
    {
        return (new \DateTimeImmutable((string) $date))->modify('+1 day')->format('Y-m-d');
    }

    /** @param array<string, mixed> $user */
    private static function actorId(array $user)
    {
        $id = isset($user['id']) ? (int) $user['id'] : 0;

        return $id > 0 ? $id : null;
    }

    private static function forUpdate(PDO $pdo)
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    /** @param mixed $value */
    private static function normalizeTimestamp($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $raw, $matches)) {
            return str_replace('T', ' ', $matches[1]);
        }

        return $raw;
    }
}
