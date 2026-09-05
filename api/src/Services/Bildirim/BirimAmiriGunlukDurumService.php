<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Bildirim;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use PDO;

/**
 * BIRIM_AMIRI operational home: today's unit roster from OrgScope + gunluk_bildirimler.
 * Person status uses the same evidence gates as BugunPersonelDurumuService (PR #255).
 * Does not reuse the legacy bagli-amir / sube-wide roster fallback. Fail-closed on empty units.
 */
class BirimAmiriGunlukDurumService
{
    /**
     * @deprecated Prefer BugunPersonelDurumuService::resolvePersonDurum
     * @param string|null $bildirimTuru
     */
    public static function deriveDurum($bildirimTuru)
    {
        return BugunPersonelDurumuService::resolvePersonDurum($bildirimTuru, null, false);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public static function buildOzetCounts(array $rows)
    {
        $geldi = 0;
        $gelmedi = 0;
        $gecGeldi = 0;
        $izinli = 0;
        $raporlu = 0;
        $erkenCikti = 0;
        $gorevde = 0;
        $henuz = 0;

        foreach ($rows as $row) {
            $durum = strtoupper(trim((string) ($row['durum'] ?? '')));
            if ($durum === 'GELMEDI') {
                $gelmedi++;
            } elseif ($durum === 'GEC_GELDI') {
                $gecGeldi++;
            } elseif ($durum === 'IZINLI') {
                $izinli++;
            } elseif ($durum === 'RAPORLU') {
                $raporlu++;
            } elseif ($durum === 'ERKEN_CIKTI') {
                $erkenCikti++;
            } elseif ($durum === 'GOREVDE') {
                $gorevde++;
            } elseif ($durum === BugunPersonelDurumuService::DURUM_HENUZ_DEGERLENDIRILMEDI) {
                $henuz++;
            } elseif ($durum === 'GELDI' || $durum === 'DIGER') {
                $geldi++;
            } else {
                $henuz++;
            }
        }

        return [
            'toplam_personel' => count($rows),
            'geldi' => $geldi,
            'gelmedi' => $gelmedi,
            'gec_geldi' => $gecGeldi,
            'izinli' => $izinli,
            'raporlu' => $raporlu,
            'izinli_raporlu' => $izinli + $raporlu,
            'erken_cikti' => $erkenCikti,
            'gorevde' => $gorevde,
            'henuz_degerlendirilmedi' => $henuz,
        ];
    }

    /**
     * Count invariant: exclusive primary buckets sum to toplam.
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

        return $sum === (int) ($counts['toplam_personel'] ?? 0);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function build(PDO $pdo, array $user, Request $request, $tarih)
    {
        $tarih = (string) $tarih;
        $amirId = isset($user['id']) ? (int) $user['id'] : 0;
        $activeSube = OrgScope::resolveActiveSubeId($user, $request);

        $where = [
            "p.aktif_durum = 'AKTIF'",
            'p.ise_giris_tarihi <= :bagd_tarih_giris',
        ];
        $params = ['bagd_tarih_giris' => $tarih];
        OrgScope::appendPersonelOrgFilter($where, $params, $user, $activeSube, 'p', 'bagd', $pdo);

        $nameExpr = self::isSqlite($pdo)
            ? "TRIM(COALESCE(p.ad, '') || ' ' || COALESCE(p.soyad, ''))"
            : "TRIM(CONCAT(COALESCE(p.ad, ''), ' ', COALESCE(p.soyad, '')))";

        $bildirimJoin = '';
        $bildirimSelect = '
                NULL AS bildirim_turu,
                NULL AS dakika,
                NULL AS baslangic_saati,
                NULL AS bitis_saati';
        if (self::hasTable($pdo, 'gunluk_bildirimler')) {
            $params['bagd_tarih_gb'] = $tarih;
            $params['bagd_iptal'] = 'IPTAL';
            $bildirimSelect = '
                gb.bildirim_turu AS bildirim_turu,
                gb.dakika AS dakika,
                gb.baslangic_saati AS baslangic_saati,
                gb.bitis_saati AS bitis_saati';
            $bildirimJoin = '
            LEFT JOIN gunluk_bildirimler gb ON gb.id = (
                SELECT gb2.id
                FROM gunluk_bildirimler gb2
                WHERE gb2.personel_id = p.id
                  AND gb2.tarih = :bagd_tarih_gb
                  AND gb2.state <> :bagd_iptal
                ORDER BY gb2.id DESC
                LIMIT 1
            )';
        }

        $puantajJoin = '';
        $puantajSelect = '
                NULL AS puantaj_giris,
                NULL AS puantaj_cikis,
                NULL AS puantaj_gec,
                NULL AS puantaj_erken';
        if (self::hasTable($pdo, 'gunluk_puantaj')) {
            $params['bagd_tarih_gp'] = $tarih;
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
            LEFT JOIN gunluk_puantaj gp ON gp.personel_id = p.id AND gp.tarih = :bagd_tarih_gp';
        }

        $sql = '
            SELECT
                p.id AS personel_id,
                ' . $nameExpr . ' AS ad_soyad,
                ' . $bildirimSelect . ',
                ' . $puantajSelect . '
            FROM personeller p
            ' . $bildirimJoin . '
            ' . $puantajJoin . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.ad ASC, p.soyad ASC, p.id ASC
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $tamamlama = self::fetchTamamlama($pdo, $amirId, $tarih, $activeSube);
        $unitCompleted = is_array($tamamlama);

        $personeller = [];
        $eksikGiris = 0;
        $attendanceProofCount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $personeller[] = self::mapPersonelRow($row, $unitCompleted);
            $puantajGiris = isset($row['puantaj_giris']) ? $row['puantaj_giris'] : null;
            if (BugunPersonelDurumuService::isMissingEntryEvidence(
                isset($row['bildirim_turu']) ? $row['bildirim_turu'] : null,
                $puantajGiris
            )) {
                $eksikGiris++;
            }
            if (BugunPersonelDurumuService::hasAttendanceProof($puantajGiris)) {
                $attendanceProofCount++;
            }
        }

        $ozet = self::buildOzetCounts($personeller);
        $ozet['eksik_giris'] = $eksikGiris;
        $ozet['attendance_proof_count'] = $attendanceProofCount;

        $tz = new \DateTimeZone(BugunPersonelDurumuService::TIMEZONE);
        $now = new \DateTimeImmutable('now', $tz);
        $tamamlandiAt = $unitCompleted && isset($tamamlama['tamamlandi_at'])
            ? $tamamlama['tamamlandi_at']
            : null;
        $status = BugunPersonelDurumuService::classifyCompletionStatus($tamamlandiAt, $tarih, $now);
        $timeLabel = null;
        if ($tamamlandiAt !== null) {
            $parsed = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $tamamlandiAt,
                $tz
            );
            if ($parsed instanceof \DateTimeImmutable) {
                $timeLabel = $parsed->format('H:i');
            }
        }

        return [
            'tarih' => $tarih,
            'ozet' => $ozet,
            'tamamlandi_mi' => $unitCompleted,
            'tamamlama' => $tamamlama,
            'bildirim' => [
                'status' => $status,
                'status_label' => BugunPersonelDurumuService::completionStatusLabel($status, $timeLabel, $tarih),
                'eksik_giris' => $eksikGiris,
                'tamamlandi_mi' => $unitCompleted,
                'tamamlandi_at' => $tamamlandiAt,
            ],
            'personeller' => $personeller,
            'pazar_mesai_prompt' => self::buildPazarMesaiPrompt(
                $pdo,
                $user,
                $request,
                $tarih,
                $amirId,
                $activeSube
            ),
        ];
    }

    /**
     * Monday operational home: prompt when prior Sunday had real attendance and no completion.
     *
     * @param array<string, mixed> $user
     * @param int|null $activeSube
     * @return array<string, mixed>|null
     */
    private static function buildPazarMesaiPrompt(
        PDO $pdo,
        array $user,
        Request $request,
        $tarih,
        $amirId,
        $activeSube
    ) {
        $tz = new \DateTimeZone(BugunPersonelDurumuService::TIMEZONE);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $tarih, $tz);
        if (!$day instanceof \DateTimeImmutable || (int) $day->format('N') !== 1) {
            return null;
        }

        $sunday = $day->modify('-1 day')->format('Y-m-d');
        $sundayPayload = self::buildSundaySnapshot($pdo, $user, $request, $sunday, $amirId, $activeSube);
        $attendanceCount = (int) ($sundayPayload['attendance_proof_count'] ?? 0);
        $sundayCompleted = (bool) ($sundayPayload['tamamlandi_mi'] ?? false);
        if ($attendanceCount < 1 || $sundayCompleted) {
            return null;
        }

        return [
            'show' => true,
            'sunday_tarih' => $sunday,
            'attendance_count' => $attendanceCount,
            'message' => 'Dün Mesaiye Gelen ' . $attendanceCount
                . ' Personel Var. Bildirimi Tamamlamak İster misiniz?',
        ];
    }

    /**
     * Lightweight Sunday attendance + completion snapshot (avoids recursive Monday prompt).
     *
     * @param array<string, mixed> $user
     * @param int|null $activeSube
     * @return array<string, mixed>
     */
    private static function buildSundaySnapshot(
        PDO $pdo,
        array $user,
        Request $request,
        $sunday,
        $amirId,
        $activeSube
    ) {
        $sunday = (string) $sunday;
        $where = [
            "p.aktif_durum = 'AKTIF'",
            'p.ise_giris_tarihi <= :bagd_sun_giris',
        ];
        $params = ['bagd_sun_giris' => $sunday];
        OrgScope::appendPersonelOrgFilter($where, $params, $user, $activeSube, 'p', 'bags', $pdo);

        $attendanceProofCount = 0;
        if (self::hasTable($pdo, 'gunluk_puantaj')) {
            $params['bagd_sun_gp'] = $sunday;
            $sql = '
                SELECT gp.giris_saati AS puantaj_giris
                FROM personeller p
                INNER JOIN gunluk_puantaj gp ON gp.personel_id = p.id AND gp.tarih = :bagd_sun_gp
                WHERE ' . implode(' AND ', $where) . '
                  AND gp.giris_saati IS NOT NULL
                  AND TRIM(gp.giris_saati) <> \'\'
            ';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $attendanceProofCount = count($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $tamamlama = self::fetchTamamlama($pdo, $amirId, $sunday, $activeSube);

        return [
            'attendance_proof_count' => $attendanceProofCount,
            'tamamlandi_mi' => is_array($tamamlama),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param bool $unitCompleted
     * @return array<string, mixed>
     */
    public static function mapPersonelRow(array $row, $unitCompleted = false)
    {
        $dakika = self::nullableInt(isset($row['dakika']) ? $row['dakika'] : null);
        $puantajGec = self::nullableInt(isset($row['puantaj_gec']) ? $row['puantaj_gec'] : null);
        $puantajErken = self::nullableInt(isset($row['puantaj_erken']) ? $row['puantaj_erken'] : null);
        $puantajGiris = self::nullableString(isset($row['puantaj_giris']) ? $row['puantaj_giris'] : null);
        $storedForLate = $dakika !== null && $dakika > 0 ? $dakika : $puantajGec;

        $durum = BugunPersonelDurumuService::resolvePersonDurum(
            isset($row['bildirim_turu']) ? $row['bildirim_turu'] : null,
            $puantajGiris,
            (bool) $unitCompleted,
            $storedForLate
        );

        $gec = null;
        $erken = null;
        $exceptionGiris = self::nullableString(isset($row['baslangic_saati']) ? $row['baslangic_saati'] : null);
        $giris = $exceptionGiris !== null ? $exceptionGiris : $puantajGiris;
        if ($durum === 'GEC_GELDI') {
            $gec = BugunPersonelDurumuService::resolveLateMinutes($giris, $storedForLate);
        } elseif ($durum === 'ERKEN_CIKTI') {
            $erken = $dakika !== null && $dakika > 0 ? $dakika : $puantajErken;
        }

        $cikis = self::nullableString(isset($row['bitis_saati']) ? $row['bitis_saati'] : null);
        if ($cikis === null) {
            $cikis = self::nullableString(isset($row['puantaj_cikis']) ? $row['puantaj_cikis'] : null);
        }

        return [
            'personel_id' => (int) $row['personel_id'],
            'ad_soyad' => trim((string) $row['ad_soyad']),
            'durum' => $durum,
            'durum_label' => self::durumLabel($durum),
            'gec_kalma_dakika' => $gec !== null && $gec > 0 ? $gec : null,
            'erken_cikis_dakika' => $erken !== null && $erken > 0 ? $erken : null,
            'giris_saati' => $giris,
            'cikis_saati' => $cikis,
        ];
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private static function nullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function nullableString($value)
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
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
            BugunPersonelDurumuService::DURUM_HENUZ_DEGERLENDIRILMEDI => 'Henüz Değerlendirilmedi',
        ];
        $key = strtoupper(trim((string) $durum));

        return isset($map[$key]) ? $map[$key] : $key;
    }

    /**
     * @param int|null $activeSube
     * @return array<string, mixed>|null
     */
    private static function fetchTamamlama(PDO $pdo, $amirId, $tarih, $activeSube)
    {
        if ($amirId <= 0 || !self::hasTable($pdo, 'gunluk_bildirim_tamamlamalari')) {
            return null;
        }

        $sql = '
            SELECT id, tamamlandi_at, tamamlayan_user_id, state
            FROM gunluk_bildirim_tamamlamalari
            WHERE birim_amiri_user_id = :amir_id
              AND tarih = :tarih
        ';
        $params = [
            'amir_id' => (int) $amirId,
            'tarih' => $tarih,
        ];
        if ($activeSube !== null) {
            $sql .= ' AND sube_id = :sube_id';
            $params['sube_id'] = (int) $activeSube;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'tamamlandi_at' => $row['tamamlandi_at'] !== null ? (string) $row['tamamlandi_at'] : null,
            'tamamlayan_user_id' => (int) $row['tamamlayan_user_id'],
            'state' => (string) $row['state'],
        ];
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
            // sqlite / non-mysql
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
            // sqlite
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
