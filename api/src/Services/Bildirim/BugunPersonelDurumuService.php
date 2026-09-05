<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Bildirim;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Services\Organizasyon\SubeReadModel;
use Medisa\Api\Services\PuantajDonemPeriodService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * IK / GENEL morning operations read model: branch → unit → person drill-down.
 * Person state requires evidence: exception row, attendance/puantaj proof, or unit completion.
 * No-open-row is NOT GELDI before completion. No QR synthesis.
 * Completions from gunluk_bildirim_tamamlamalari.
 */
class BugunPersonelDurumuService
{
    public const WORKDAY_START = '08:30';
    public const ON_TIME_DEADLINE = '09:30';
    /** Monday local deadline for prior Sunday mesai review. */
    public const SUNDAY_REVIEW_DEADLINE = '12:00';
    public const TIMEZONE = 'Europe/Istanbul';

    public const COMPLETION_TAMAMLANDI = 'TAMAMLANDI';
    public const COMPLETION_BEKLENIYOR = 'BEKLENIYOR';
    public const COMPLETION_SURESI_GECTI = 'SURESI_GECTI';
    public const COMPLETION_GEC_BILDIRILDI = 'GEC_BILDIRILDI';

    /** Read-model-only: no persistence enum. */
    public const DURUM_HENUZ_DEGERLENDIRILMEDI = 'HENUZ_DEGERLENDIRILMEDI';

    /** @var array<int, string> */
    private static $exceptionTurleri = [
        'GELMEDI',
        'GEC_GELDI',
        'ERKEN_CIKTI',
        'IZINLI',
        'RAPORLU',
        'GOREVDE',
        'DIGER',
    ];

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function build(PDO $pdo, array $user, Request $request, $tarih, DateTimeImmutable $now = null)
    {
        $tarih = (string) $tarih;
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = $now !== null ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
        $activeSube = OrgScope::resolveActiveSubeId($user, $request);

        $rows = self::fetchRosterRows($pdo, $user, $activeSube, $tarih);
        $amirByBirim = self::fetchAmirIdsByBirim($pdo, $rows);
        $completions = self::fetchCompletionsBySubeAmir($pdo, $tarih);
        $surecExceptionByPersonel = self::fetchCoveringSurecExceptionTurByPersonel($pdo, $rows, $tarih);

        $branches = [];
        foreach ($rows as $row) {
            $subeId = (int) ($row['sube_id'] ?? 0);
            if ($subeId < 1) {
                continue;
            }
            if (!isset($branches[$subeId])) {
                $branches[$subeId] = [
                    'sube_id' => $subeId,
                    'sube_adi' => self::resolveSubeAdi($pdo, $subeId, isset($row['sube_ad']) ? (string) $row['sube_ad'] : ''),
                    'units' => [],
                ];
            }

            $birimId = (int) ($row['birim_id'] ?? 0);
            $unitKey = $birimId > 0 ? (string) $birimId : 'none';
            if (!isset($branches[$subeId]['units'][$unitKey])) {
                $branches[$subeId]['units'][$unitKey] = [
                    'birim_id' => $birimId > 0 ? $birimId : null,
                    'birim_adi' => $birimId > 0
                        ? trim((string) ($row['birim_adi'] ?? ''))
                        : 'Birimsiz',
                    'bolum_id' => isset($row['bolum_id']) && $row['bolum_id'] !== null ? (int) $row['bolum_id'] : null,
                    'bolum_adi' => isset($row['bolum_adi']) ? trim((string) $row['bolum_adi']) : null,
                    'rows' => [],
                    'amir_user_ids' => $birimId > 0 && isset($amirByBirim[$birimId])
                        ? $amirByBirim[$birimId]
                        : self::fallbackAmirIdsFromRows($rows, $subeId, $birimId),
                ];
            }
            $branches[$subeId]['units'][$unitKey]['rows'][] = $row;
        }

        $branchSummaries = [];
        $attentionCount = 0;
        $incompleteUnitsAttention = 0;

        foreach ($branches as $branch) {
            $unitSummaries = [];
            $branchCounts = self::emptyCounts();
            $unitsTotal = 0;
            $unitsCompleted = 0;

            foreach ($branch['units'] as $unit) {
                $unitsTotal++;
                $completion = self::resolveUnitCompletion(
                    (int) $branch['sube_id'],
                    isset($unit['amir_user_ids']) && is_array($unit['amir_user_ids']) ? $unit['amir_user_ids'] : [],
                    $completions,
                    $tarih,
                    $now
                );
                if ($completion['tamamlandi_mi']) {
                    $unitsCompleted++;
                } else {
                    $incompleteUnitsAttention++;
                }

                $personeller = [];
                $eksikGiris = 0;
                foreach ($unit['rows'] as $rawRow) {
                    $personelId = (int) ($rawRow['personel_id'] ?? 0);
                    $surecException = $personelId > 0 && isset($surecExceptionByPersonel[$personelId])
                        ? $surecExceptionByPersonel[$personelId]
                        : null;
                    $personeller[] = self::mapPersonelRow(
                        $rawRow,
                        (bool) $completion['tamamlandi_mi'],
                        $surecException
                    );
                    $effectiveException = self::effectiveExceptionTur(
                        isset($rawRow['bildirim_turu']) ? $rawRow['bildirim_turu'] : null,
                        $surecException
                    );
                    if (self::isMissingEntryEvidence(
                        $effectiveException,
                        isset($rawRow['puantaj_giris']) ? $rawRow['puantaj_giris'] : null
                    )) {
                        $eksikGiris++;
                    }
                }
                $completion['eksik_giris'] = $eksikGiris;
                $counts = self::buildStatusCounts($personeller);
                foreach ($counts as $key => $value) {
                    if ($key === 'toplam') {
                        $branchCounts['toplam'] += $value;
                    } else {
                        $branchCounts[$key] += $value;
                    }
                }

                $unitSummaries[] = [
                    'birim_id' => $unit['birim_id'],
                    'birim_adi' => $unit['birim_adi'] !== '' ? $unit['birim_adi'] : 'Birim',
                    'bolum_id' => $unit['bolum_id'],
                    'bolum_adi' => $unit['bolum_adi'],
                    'counts' => $counts,
                    'bildirim' => $completion,
                    'personeller' => $personeller,
                ];
            }

            usort($unitSummaries, function ($a, $b) {
                return strcmp((string) $a['birim_adi'], (string) $b['birim_adi']);
            });

            $attentionCount += (int) $branchCounts['gelmedi']
                + (int) $branchCounts['gec_geldi']
                + (int) $branchCounts['henuz_degerlendirilmedi'];

            $periodWritable = true;
            if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $tarih, $tm)) {
                try {
                    $periodWritable = !PuantajDonemPeriodService::isWriteLocked(
                        $pdo,
                        (int) $branch['sube_id'],
                        (int) $tm[1],
                        (int) $tm[2]
                    );
                } catch (\Throwable $e) {
                    $periodWritable = true;
                }
            }

            $branchSummaries[] = [
                'sube_id' => $branch['sube_id'],
                'sube_adi' => $branch['sube_adi'],
                'counts' => $branchCounts,
                'period_writable' => $periodWritable,
                'birim_bildirim' => [
                    'tamamlanan' => $unitsCompleted,
                    'toplam' => $unitsTotal,
                ],
                'units' => $unitSummaries,
            ];
        }

        usort($branchSummaries, function ($a, $b) {
            return strcmp((string) $a['sube_adi'], (string) $b['sube_adi']);
        });

        $attentionCount += $incompleteUnitsAttention;

        return [
            'tarih' => $tarih,
            'timezone' => self::TIMEZONE,
            'workday_start' => self::WORKDAY_START,
            'on_time_deadline' => self::ON_TIME_DEADLINE,
            'server_now' => $now->format('Y-m-d H:i:s'),
            'attention_count' => $attentionCount,
            'branches' => $branchSummaries,
        ];
    }

    /**
     * Map covering resmi surec → Bugün exception turu (read-only overlay).
     * Saatli GEC/ERKEN surec türleri buradan türetilmez; günlük bildirim owner’ı kullanır.
     *
     * @param mixed $surecTuru
     * @param mixed $altTur
     * @return string|null
     */
    public static function mapSurecToBugunExceptionTur($surecTuru, $altTur = null)
    {
        $tur = strtoupper(trim((string) $surecTuru));
        if ($tur === 'IZIN') {
            return 'IZINLI';
        }
        if ($tur === 'RAPOR' || $tur === 'IS_KAZASI') {
            return 'RAPORLU';
        }
        if ($tur === 'DEVAMSIZLIK') {
            $alt = strtoupper(trim((string) $altTur));
            if ($alt === '' || $alt === 'IZINSIZ_GELMEDI') {
                return 'GELMEDI';
            }
        }

        return null;
    }

    /**
     * Bildirim exception wins; otherwise covering resmi surec overlay.
     *
     * @param mixed $bildirimTuru
     * @param string|null $surecExceptionTur
     * @return string|null
     */
    public static function effectiveExceptionTur($bildirimTuru, $surecExceptionTur = null)
    {
        $tur = strtoupper(trim((string) $bildirimTuru));
        if (in_array($tur, self::$exceptionTurleri, true)) {
            return $tur;
        }
        $overlay = strtoupper(trim((string) $surecExceptionTur));
        if (in_array($overlay, self::$exceptionTurleri, true)) {
            return $overlay;
        }

        return null;
    }

    /**
     * Exception row → that status.
     * Else attendance giris proof → GELDI / GEC_GELDI by 08:30.
     * Else unit completion (exception-only implicit present) → GELDI.
     * Else → HENUZ_DEGERLENDIRILMEDI (never auto-GELMEDI).
     *
     * @param string|null $bildirimTuru
     * @param string|null $attendanceGirisSaati
     * @param bool $unitCompleted
     * @param int|null $storedDakika
     */
    public static function resolvePersonDurum(
        $bildirimTuru,
        $attendanceGirisSaati = null,
        $unitCompleted = false,
        $storedDakika = null
    ) {
        $tur = strtoupper(trim((string) $bildirimTuru));
        if (in_array($tur, self::$exceptionTurleri, true)) {
            return $tur;
        }

        $giris = self::nullableString($attendanceGirisSaati);
        if ($giris !== null) {
            $late = self::resolveLateMinutes($giris, $storedDakika);

            return ($late !== null && $late > 0) ? 'GEC_GELDI' : 'GELDI';
        }

        if ($unitCompleted) {
            return 'GELDI';
        }

        return self::DURUM_HENUZ_DEGERLENDIRILMEDI;
    }

    /**
     * Live missing-entry evidence (ignores unit completion).
     * Missing entry ≠ GELMEDI — person may be on-site without giriş/QR.
     *
     * @param string|null $bildirimTuru
     * @param string|null $attendanceGirisSaati
     */
    public static function isMissingEntryEvidence($bildirimTuru, $attendanceGirisSaati = null)
    {
        $tur = strtoupper(trim((string) $bildirimTuru));
        if (in_array($tur, self::$exceptionTurleri, true)) {
            return false;
        }

        return self::nullableString($attendanceGirisSaati) === null;
    }

    /**
     * Real attendance/mesai proof from puantaj giriş (no invented GELDI).
     *
     * @param string|null $attendanceGirisSaati
     */
    public static function hasAttendanceProof($attendanceGirisSaati)
    {
        return self::nullableString($attendanceGirisSaati) !== null;
    }

    /**
     * @param string $tarih YYYY-MM-DD
     */
    public static function isSundayDate($tarih)
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $tarih, $tz);
        if (!$day instanceof DateTimeImmutable) {
            return false;
        }

        return (int) $day->format('w') === 0;
    }

    /**
     * On-time deadline for the notification date (Europe/Istanbul).
     * Normal day: same-day 09:30:00 inclusive.
     * Sunday: next Monday 12:00:00 inclusive (normal 09:30 does not apply).
     *
     * @param string $tarih YYYY-MM-DD
     */
    public static function deadlineDateTime($tarih)
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $tarih, $tz);
        if ($day instanceof DateTimeImmutable && (int) $day->format('w') === 0) {
            return $day->modify('next monday')->setTime(12, 0, 0);
        }

        return new DateTimeImmutable(
            (string) $tarih . ' ' . self::ON_TIME_DEADLINE . ':00',
            $tz
        );
    }

    /**
     * @param string|null $bildirimTuru
     * @deprecated Prefer resolvePersonDurum — kept for exception extraction only.
     */
    public static function deriveDurum($bildirimTuru)
    {
        $tur = strtoupper(trim((string) $bildirimTuru));
        if (in_array($tur, self::$exceptionTurleri, true)) {
            return $tur;
        }

        return self::DURUM_HENUZ_DEGERLENDIRILMEDI;
    }

    /**
     * Count invariant helper for tests / callers.
     *
     * @param array<string, int> $counts
     */
    public static function countsSatisfyInvariant(array $counts)
    {
        $sum = (int) ($counts['geldi'] ?? 0)
            + (int) ($counts['gec_geldi'] ?? 0)
            + (int) ($counts['gelmedi'] ?? 0)
            + (int) ($counts['izinli'] ?? 0)
            + (int) ($counts['raporlu'] ?? 0)
            + (int) ($counts['gorevde'] ?? 0)
            + (int) ($counts['erken_cikti'] ?? 0)
            + (int) ($counts['henuz_degerlendirilmedi'] ?? 0);

        return $sum === (int) ($counts['toplam'] ?? 0);
    }

    /**
     * Late minutes vs canonical workday start 08:30 (Europe/Istanbul clock).
     *
     * @param string|null $girisSaati HH:MM
     * @param int|null $storedDakika
     * @return int|null
     */
    public static function resolveLateMinutes($girisSaati, $storedDakika = null)
    {
        if ($storedDakika !== null && (int) $storedDakika > 0) {
            return (int) $storedDakika;
        }
        $entry = self::parseHhMmToMinutes($girisSaati);
        if ($entry === null) {
            return null;
        }
        $start = self::parseHhMmToMinutes(self::WORKDAY_START);
        if ($start === null) {
            return null;
        }
        $diff = $entry - $start;

        return $diff > 0 ? $diff : null;
    }

    /**
     * @param string|null $tamamlandiAt
     * @return string
     */
    public static function classifyCompletionStatus($tamamlandiAt, $tarih, DateTimeImmutable $now)
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = $now->setTimezone($tz);
        $deadline = self::deadlineDateTime($tarih);
        if ($tamamlandiAt !== null && trim((string) $tamamlandiAt) !== '') {
            $completed = self::parseServerDateTime((string) $tamamlandiAt);
            if ($completed !== null && $completed <= $deadline) {
                return self::COMPLETION_TAMAMLANDI;
            }

            return self::COMPLETION_GEC_BILDIRILDI;
        }

        if ($now > $deadline) {
            return self::COMPLETION_SURESI_GECTI;
        }

        return self::COMPLETION_BEKLENIYOR;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    public static function parseHhMmToMinutes($value)
    {
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim((string) $value), $m)) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }

    /**
     * @return DateTimeImmutable|null
     */
    private static function parseServerDateTime($value)
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $raw = trim((string) $value);
        $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', DATE_ATOM];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $raw, $tz);
            if ($parsed instanceof DateTimeImmutable) {
                return $parsed->setTimezone($tz);
            }
        }
        try {
            return (new DateTimeImmutable($raw))->setTimezone($tz);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param int|null $activeSube
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRosterRows(PDO $pdo, array $user, $activeSube, $tarih)
    {
        $where = [
            "p.aktif_durum = 'AKTIF'",
            'p.ise_giris_tarihi <= :bpd_tarih_giris',
        ];
        $params = ['bpd_tarih_giris' => $tarih];
        OrgScope::appendPersonelOrgFilter($where, $params, $user, $activeSube, 'p', 'bpd', $pdo);

        $nameExpr = self::isSqlite($pdo)
            ? "TRIM(COALESCE(p.ad, '') || ' ' || COALESCE(p.soyad, ''))"
            : "TRIM(CONCAT(COALESCE(p.ad, ''), ' ', COALESCE(p.soyad, '')))";

            $bildirimSelect = '
                NULL AS bildirim_id,
                NULL AS bildirim_state,
                NULL AS bildirim_created_by,
                NULL AS bildirim_turu,
                NULL AS dakika,
                NULL AS baslangic_saati,
                NULL AS bitis_saati,
                NULL AS aciklama,
                NULL AS alt_tur';
        $bildirimJoin = '';
        if (self::hasTable($pdo, 'gunluk_bildirimler')) {
            $params['bpd_tarih_gb'] = $tarih;
            $params['bpd_iptal'] = 'IPTAL';
            $bildirimSelect = '
                gb.id AS bildirim_id,
                gb.state AS bildirim_state,
                gb.created_by AS bildirim_created_by,
                gb.bildirim_turu AS bildirim_turu,
                gb.dakika AS dakika,
                gb.baslangic_saati AS baslangic_saati,
                gb.bitis_saati AS bitis_saati,
                gb.aciklama AS aciklama,
                gb.alt_tur AS alt_tur';
            $bildirimJoin = '
            LEFT JOIN gunluk_bildirimler gb ON gb.id = (
                SELECT gb2.id
                FROM gunluk_bildirimler gb2
                WHERE gb2.personel_id = p.id
                  AND gb2.tarih = :bpd_tarih_gb
                  AND gb2.state <> :bpd_iptal
                ORDER BY gb2.id DESC
                LIMIT 1
            )';
        }

        $puantajSelect = '
                NULL AS puantaj_giris,
                NULL AS puantaj_cikis,
                NULL AS puantaj_gec,
                NULL AS puantaj_erken';
        $puantajJoin = '';
        if (self::hasTable($pdo, 'gunluk_puantaj')) {
            $params['bpd_tarih_gp'] = $tarih;
            $gecCol = self::hasColumn($pdo, 'gunluk_puantaj', 'gec_kalma_dakika')
                ? 'gp.gec_kalma_dakika'
                : 'NULL';
            $erkenCol = self::hasColumn($pdo, 'gunluk_puantaj', 'erken_cikis_dakika')
                ? 'gp.erken_cikis_dakika'
                : 'NULL';
            $puantajSelect = '
                gp.giris_saati AS puantaj_giris,
                gp.cikis_saati AS puantaj_cikis,
                ' . $gecCol . ' AS puantaj_gec,
                ' . $erkenCol . ' AS puantaj_erken';
            $puantajJoin = '
            LEFT JOIN gunluk_puantaj gp ON gp.personel_id = p.id AND gp.tarih = :bpd_tarih_gp';
        }

        $birimJoin = '';
        $birimSelect = 'NULL AS birim_adi';
        if (self::hasTable($pdo, 'birimler')) {
            $birimJoin = 'LEFT JOIN birimler bi ON bi.id = p.birim_id';
            $birimSelect = 'bi.ad AS birim_adi';
        }

        $bolumJoin = '';
        $bolumSelect = 'NULL AS bolum_adi';
        if (self::hasTable($pdo, 'bolumler')) {
            $bolumJoin = 'LEFT JOIN bolumler bo ON bo.id = p.bolum_id';
            $bolumSelect = 'bo.ad AS bolum_adi';
        }

        $subeJoin = '';
        $subeSelect = 'NULL AS sube_ad';
        if (self::hasTable($pdo, 'subeler')) {
            $subeJoin = 'LEFT JOIN subeler s ON s.id = p.sube_id';
            $subeSelect = 's.ad AS sube_ad';
        }

        $sql = '
            SELECT
                p.id AS personel_id,
                ' . $nameExpr . ' AS ad_soyad,
                p.sube_id AS sube_id,
                p.bolum_id AS bolum_id,
                p.birim_id AS birim_id,
                p.bagli_amir_id AS bagli_amir_id,
                ' . $subeSelect . ',
                ' . $bolumSelect . ',
                ' . $birimSelect . ',
                ' . $bildirimSelect . ',
                ' . $puantajSelect . '
            FROM personeller p
            ' . $subeJoin . '
            ' . $bolumJoin . '
            ' . $birimJoin . '
            ' . $bildirimJoin . '
            ' . $puantajJoin . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.sube_id ASC, p.birim_id ASC, p.ad ASC, p.soyad ASC, p.id ASC
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, int>>
     */
    private static function fetchAmirIdsByBirim(PDO $pdo, array $rows)
    {
        $birimIds = [];
        foreach ($rows as $row) {
            $birimId = (int) ($row['birim_id'] ?? 0);
            if ($birimId > 0) {
                $birimIds[$birimId] = true;
            }
        }
        if (count($birimIds) === 0 || !self::hasTable($pdo, 'user_birimler') || !self::hasTable($pdo, 'users')) {
            return [];
        }

        $ids = array_keys($birimIds);
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'ub' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $sql = '
            SELECT ub.birim_id, ub.user_id
            FROM user_birimler ub
            INNER JOIN users u ON u.id = ub.user_id
            WHERE ub.birim_id IN (' . implode(', ', $placeholders) . ')
              AND UPPER(u.rol) = \'BIRIM_AMIRI\'
              AND UPPER(COALESCE(u.durum, \'AKTIF\')) = \'AKTIF\'
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $birimId = (int) $row['birim_id'];
            $userId = (int) $row['user_id'];
            if (!isset($map[$birimId])) {
                $map[$birimId] = [];
            }
            $map[$birimId][$userId] = $userId;
        }
        foreach ($map as $birimId => $set) {
            $map[$birimId] = array_values($set);
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private static function fallbackAmirIdsFromRows(array $rows, $subeId, $birimId)
    {
        $set = [];
        foreach ($rows as $row) {
            if ((int) ($row['sube_id'] ?? 0) !== (int) $subeId) {
                continue;
            }
            if ((int) ($row['birim_id'] ?? 0) !== (int) $birimId) {
                continue;
            }
            $amirId = (int) ($row['bagli_amir_id'] ?? 0);
            if ($amirId > 0) {
                $set[$amirId] = $amirId;
            }
        }

        return array_values($set);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function fetchCompletionsBySubeAmir(PDO $pdo, $tarih)
    {
        if (!self::hasTable($pdo, 'gunluk_bildirim_tamamlamalari')) {
            return [];
        }

        $sql = '
            SELECT id, sube_id, birim_amiri_user_id, tamamlandi_at, tamamlayan_user_id, state
            FROM gunluk_bildirim_tamamlamalari
            WHERE tarih = :tarih
            ORDER BY id ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tarih' => $tarih]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = ((int) $row['sube_id']) . ':' . ((int) $row['birim_amiri_user_id']);
            if (!isset($map[$key])) {
                $map[$key] = [
                    'id' => (int) $row['id'],
                    'sube_id' => (int) $row['sube_id'],
                    'birim_amiri_user_id' => (int) $row['birim_amiri_user_id'],
                    'tamamlandi_at' => $row['tamamlandi_at'] !== null ? (string) $row['tamamlandi_at'] : null,
                    'tamamlayan_user_id' => (int) $row['tamamlayan_user_id'],
                    'state' => (string) $row['state'],
                ];
            }
        }

        return $map;
    }

    /**
     * @param array<int, int> $amirIds
     * @param array<string, array<string, mixed>> $completions
     * @return array<string, mixed>
     */
    private static function resolveUnitCompletion($subeId, array $amirIds, array $completions, $tarih, DateTimeImmutable $now)
    {
        $found = null;
        foreach ($amirIds as $amirId) {
            $key = ((int) $subeId) . ':' . ((int) $amirId);
            if (isset($completions[$key])) {
                $found = $completions[$key];
                break;
            }
        }

        $tamamlandiAt = $found !== null ? $found['tamamlandi_at'] : null;
        $status = self::classifyCompletionStatus($tamamlandiAt, $tarih, $now);
        $timeLabel = null;
        if ($tamamlandiAt !== null) {
            $parsed = self::parseServerDateTime($tamamlandiAt);
            if ($parsed !== null) {
                $timeLabel = $parsed->format('H:i');
            }
        }

        return [
            'status' => $status,
            'status_label' => self::completionStatusLabel($status, $timeLabel, $tarih),
            'tamamlandi_mi' => $found !== null,
            'tamamlandi_at' => $tamamlandiAt,
            'tamamlayan_user_id' => $found !== null ? $found['tamamlayan_user_id'] : null,
            'completion_id' => $found !== null ? $found['id'] : null,
            'eksik_giris' => 0,
        ];
    }

    /**
     * @param string|null $timeLabel
     * @param string|null $tarih
     */
    public static function completionStatusLabel($status, $timeLabel = null, $tarih = null)
    {
        $isSunday = $tarih !== null && self::isSundayDate($tarih);
        if ($isSunday) {
            if ($status === self::COMPLETION_TAMAMLANDI) {
                return $timeLabel !== null
                    ? ('Zamanında Pazar Kontrolü · ' . $timeLabel)
                    : 'Zamanında Pazar Kontrolü';
            }
            if ($status === self::COMPLETION_GEC_BILDIRILDI) {
                return $timeLabel !== null
                    ? ('Geç Bildirildi (Pazar mesaisi) · ' . $timeLabel)
                    : 'Geç Bildirildi (Pazar mesaisi)';
            }
            if ($status === self::COMPLETION_SURESI_GECTI) {
                return 'Pazar Mesaisi Bildirimi Süresi Geçti';
            }

            return 'Kontrol bekliyor';
        }

        if ($status === self::COMPLETION_TAMAMLANDI) {
            return $timeLabel !== null ? ('Tamamlandı · ' . $timeLabel) : 'Tamamlandı';
        }
        if ($status === self::COMPLETION_GEC_BILDIRILDI) {
            return $timeLabel !== null ? ('Geç Bildirildi · ' . $timeLabel) : 'Geç Bildirildi';
        }
        if ($status === self::COMPLETION_SURESI_GECTI) {
            return 'Süresi Geçti';
        }

        return 'Bekleniyor';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $tarih
     * @return array<int, string> personel_id → Bugün exception turu
     */
    private static function fetchCoveringSurecExceptionTurByPersonel(PDO $pdo, array $rows, $tarih)
    {
        if (!self::hasTable($pdo, 'surecler')) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['personel_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (count($ids) === 0) {
            return [];
        }

        $placeholders = [];
        $params = ['bpd_surec_tarih' => (string) $tarih, 'bpd_surec_state' => 'AKTIF'];
        $i = 0;
        foreach ($ids as $id) {
            $key = 'bpd_sp' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
            $i++;
        }

        $sql = '
            SELECT personel_id, surec_turu, alt_tur
            FROM surecler
            WHERE state = :bpd_surec_state
              AND personel_id IN (' . implode(', ', $placeholders) . ')
              AND baslangic_tarihi <= :bpd_surec_tarih
              AND (bitis_tarihi IS NULL OR bitis_tarihi >= :bpd_surec_tarih)
              AND surec_turu IN (\'IZIN\', \'RAPOR\', \'IS_KAZASI\', \'DEVAMSIZLIK\')
            ORDER BY id DESC
        ';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $personelId = (int) ($row['personel_id'] ?? 0);
            if ($personelId < 1 || isset($map[$personelId])) {
                continue;
            }
            $mapped = self::mapSurecToBugunExceptionTur(
                isset($row['surec_turu']) ? $row['surec_turu'] : null,
                isset($row['alt_tur']) ? $row['alt_tur'] : null
            );
            if ($mapped !== null) {
                $map[$personelId] = $mapped;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @param bool $unitCompleted
     * @param string|null $surecExceptionTur
     * @return array<string, mixed>
     */
    private static function mapPersonelRow(array $row, $unitCompleted = false, $surecExceptionTur = null)
    {
        $dakika = self::nullableInt(isset($row['dakika']) ? $row['dakika'] : null);
        $puantajGec = self::nullableInt(isset($row['puantaj_gec']) ? $row['puantaj_gec'] : null);
        $puantajErken = self::nullableInt(isset($row['puantaj_erken']) ? $row['puantaj_erken'] : null);

        $bildirimTur = isset($row['bildirim_turu']) ? $row['bildirim_turu'] : null;
        $exceptionTur = self::effectiveExceptionTur($bildirimTur, $surecExceptionTur);
        $exceptionGiris = self::nullableString(isset($row['baslangic_saati']) ? $row['baslangic_saati'] : null);
        $puantajGiris = self::nullableString(isset($row['puantaj_giris']) ? $row['puantaj_giris'] : null);

        // Attendance proof for present/late when no exception row exists.
        $attendanceGiris = $puantajGiris;
        $storedForLate = $dakika !== null && $dakika > 0 ? $dakika : $puantajGec;

        $durum = self::resolvePersonDurum(
            $exceptionTur,
            $attendanceGiris,
            (bool) $unitCompleted,
            $storedForLate
        );

        $giris = $exceptionGiris !== null ? $exceptionGiris : $puantajGiris;
        $cikis = self::nullableString(isset($row['bitis_saati']) ? $row['bitis_saati'] : null);
        if ($cikis === null) {
            $cikis = self::nullableString(isset($row['puantaj_cikis']) ? $row['puantaj_cikis'] : null);
        }

        $gec = null;
        $erken = null;
        if ($durum === 'GEC_GELDI') {
            $gec = self::resolveLateMinutes($giris, $storedForLate);
        } elseif ($durum === 'ERKEN_CIKTI') {
            $erken = $dakika !== null && $dakika > 0 ? $dakika : $puantajErken;
            if ($erken !== null && $erken <= 0) {
                $erken = null;
            }
        }

        $detail = self::personDetailLine($durum, $giris, $gec, isset($row['aciklama']) ? $row['aciklama'] : null, isset($row['alt_tur']) ? $row['alt_tur'] : null);

        $hasBildirimException = $bildirimTur !== null
            && in_array(strtoupper(trim((string) $bildirimTur)), self::$exceptionTurleri, true);
        $hasSurecException = $surecExceptionTur !== null
            && in_array(strtoupper(trim((string) $surecExceptionTur)), self::$exceptionTurleri, true);
        $hasAttendance = $puantajGiris !== null && trim((string) $puantajGiris) !== '';
        $evidence = 'UNASSESSED';
        if ($hasBildirimException) {
            $evidence = 'EXCEPTION';
        } elseif ($hasSurecException) {
            $evidence = 'RESMI_SUREC';
        } elseif ($hasAttendance) {
            $evidence = 'ATTENDANCE';
        } elseif ($unitCompleted) {
            $evidence = 'COMPLETION';
        }

        return [
            'personel_id' => (int) $row['personel_id'],
            'ad_soyad' => trim((string) $row['ad_soyad']),
            'bildirim_id' => isset($row['bildirim_id']) && $row['bildirim_id'] !== null
                ? (int) $row['bildirim_id']
                : null,
            'bildirim_state' => self::nullableString(isset($row['bildirim_state']) ? $row['bildirim_state'] : null),
            'created_by' => isset($row['bildirim_created_by']) && $row['bildirim_created_by'] !== null
                ? (int) $row['bildirim_created_by']
                : null,
            'durum' => $durum,
            'durum_label' => self::durumLabel($durum),
            'gec_kalma_dakika' => $gec,
            'erken_cikis_dakika' => $erken,
            'giris_saati' => $giris,
            'cikis_saati' => $cikis,
            'aciklama' => self::nullableString(isset($row['aciklama']) ? $row['aciklama'] : null),
            'alt_tur' => self::nullableString(isset($row['alt_tur']) ? $row['alt_tur'] : null),
            'detail_line' => $detail,
            'evidence' => $evidence,
            'group' => self::statusGroup($durum),
        ];
    }

    private static function personDetailLine($durum, $giris, $gec, $aciklama, $altTur)
    {
        $durum = strtoupper(trim((string) $durum));
        if ($durum === 'GEC_GELDI') {
            $parts = [];
            if ($giris !== null) {
                $parts[] = $giris;
            }
            if ($gec !== null && $gec > 0) {
                $parts[] = $gec . ' dk geç';
            }

            return count($parts) > 0 ? implode(' · ', $parts) : 'Geç Geldi';
        }
        if ($durum === 'GELMEDI') {
            $note = self::nullableString($aciklama);
            if ($note !== null) {
                return 'Giriş yok · ' . $note;
            }

            return 'Giriş yok · Açıklama yok';
        }
        if ($durum === 'IZINLI') {
            $alt = self::nullableString($altTur);
            $note = self::nullableString($aciklama);
            if ($alt !== null) {
                return $alt;
            }
            if ($note !== null) {
                return $note;
            }

            return 'İzinli';
        }
        if ($durum === 'RAPORLU') {
            return self::nullableString($aciklama) !== null ? (string) self::nullableString($aciklama) : 'Raporlu';
        }
        if ($durum === 'GOREVDE') {
            return self::nullableString($aciklama) !== null ? (string) self::nullableString($aciklama) : 'Görevde';
        }
        if ($durum === 'ERKEN_CIKTI') {
            return $giris !== null ? (string) $giris : 'Erken Çıktı';
        }
        if ($durum === 'GELDI') {
            return $giris !== null ? (string) $giris : 'Geldi';
        }
        if ($durum === self::DURUM_HENUZ_DEGERLENDIRILMEDI) {
            return 'Henüz değerlendirilmedi';
        }

        return self::durumLabel($durum);
    }

    private static function statusGroup($durum)
    {
        $durum = strtoupper(trim((string) $durum));
        if ($durum === self::DURUM_HENUZ_DEGERLENDIRILMEDI) {
            return 'PENDING';
        }
        if (in_array($durum, ['IZINLI', 'RAPORLU', 'GOREVDE'], true)) {
            return 'PLANNED';
        }
        if (in_array($durum, ['GELMEDI'], true)) {
            return 'ATTENTION';
        }
        if (in_array($durum, ['GELDI', 'GEC_GELDI', 'ERKEN_CIKTI'], true)) {
            return 'ACTUAL';
        }

        return 'ACTUAL';
    }

    /**
     * Exclusive primary-state buckets (ERKEN_CIKTI is its own primary exception; not double-counted with GELDI).
     * DIGER folds into geldi display bucket (no dedicated morning key).
     *
     * @param array<int, array<string, mixed>> $personeller
     * @return array<string, int>
     */
    private static function buildStatusCounts(array $personeller)
    {
        $counts = self::emptyCounts();
        $counts['toplam'] = count($personeller);
        foreach ($personeller as $person) {
            $durum = strtoupper(trim((string) ($person['durum'] ?? '')));
            if ($durum === 'GELMEDI') {
                $counts['gelmedi']++;
            } elseif ($durum === 'GEC_GELDI') {
                $counts['gec_geldi']++;
            } elseif ($durum === 'IZINLI') {
                $counts['izinli']++;
            } elseif ($durum === 'RAPORLU') {
                $counts['raporlu']++;
            } elseif ($durum === 'GOREVDE') {
                $counts['gorevde']++;
            } elseif ($durum === 'ERKEN_CIKTI') {
                $counts['erken_cikti']++;
            } elseif ($durum === self::DURUM_HENUZ_DEGERLENDIRILMEDI) {
                $counts['henuz_degerlendirilmedi']++;
            } elseif ($durum === 'GELDI' || $durum === 'DIGER') {
                $counts['geldi']++;
            } else {
                $counts['henuz_degerlendirilmedi']++;
            }
        }

        return $counts;
    }

    /** @return array<string, int> */
    private static function emptyCounts()
    {
        return [
            'toplam' => 0,
            'geldi' => 0,
            'gec_geldi' => 0,
            'gelmedi' => 0,
            'izinli' => 0,
            'raporlu' => 0,
            'gorevde' => 0,
            'erken_cikti' => 0,
            'henuz_degerlendirilmedi' => 0,
        ];
    }

    private static function durumLabel($durum)
    {
        $map = [
            'GELDI' => 'Geldi',
            'GELMEDI' => 'Gelmedi',
            'GEC_GELDI' => 'Geç Geldi',
            'ERKEN_CIKTI' => 'Erken Çıktı',
            'IZINLI' => 'İzinli',
            'RAPORLU' => 'Raporlu',
            'GOREVDE' => 'Görevde',
            'DIGER' => 'Diğer',
            self::DURUM_HENUZ_DEGERLENDIRILMEDI => 'Henüz Değerlendirilmedi',
        ];
        $key = strtoupper(trim((string) $durum));

        return isset($map[$key]) ? $map[$key] : $key;
    }

    private static function resolveSubeAdi(PDO $pdo, $subeId, $fallback)
    {
        $mapped = SubeReadModel::findById($pdo, (int) $subeId);
        if ($mapped !== null && isset($mapped['tam_ad']) && trim((string) $mapped['tam_ad']) !== '') {
            return (string) $mapped['tam_ad'];
        }
        $fallback = trim((string) $fallback);

        return $fallback !== '' ? $fallback : ('Şube ' . (int) $subeId);
    }

    /** @return int|null */
    private static function nullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** @return string|null */
    private static function nullableString($value)
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function hasTable(PDO $pdo, $table)
    {
        $table = (string) $table;
        try {
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            if ($stmt && $stmt->fetch()) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :n LIMIT 1");
            $stmt->execute(['n' => $table]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function hasColumn(PDO $pdo, $table, $column)
    {
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $pdo->quote((string) $column));
            if ($stmt && $stmt->fetch()) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            if (!$stmt) {
                return false;
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (isset($col['name']) && (string) $col['name'] === (string) $column) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
        }

        return false;
    }

    private static function isSqlite(PDO $pdo)
    {
        try {
            return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
