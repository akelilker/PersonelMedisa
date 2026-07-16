<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

/**
 * Shared notification-chain period context queries for preflight and reporting.
 */
class BildirimDonemContextService
{
    /** @return array{0: string, 1: string, 2: string} */
    public static function monthBounds($yil, $ay)
    {
        $yil = (int) $yil;
        $ay = (int) $ay;
        $donem = sprintf('%04d-%02d', $yil, $ay);
        $ayBaslangic = sprintf('%04d-%02d-01', $yil, $ay);
        $ayBitis = (new \DateTimeImmutable($ayBaslangic))->modify('last day of this month')->format('Y-m-d');

        return [$donem, $ayBaslangic, $ayBitis];
    }

    /** @return array<int, array{hafta_baslangic: string, hafta_bitis: string}> */
    public static function listWeeksIntersectingMonth($ayBaslangic, $ayBitis)
    {
        $start = new \DateTimeImmutable($ayBaslangic);
        $end = new \DateTimeImmutable($ayBitis);
        $day = (int) $start->format('N');
        $monday = $start->modify('-' . ($day - 1) . ' days');

        $weeks = [];
        while ($monday <= $end) {
            $weeks[] = [
                'hafta_baslangic' => $monday->format('Y-m-d'),
                'hafta_bitis' => $monday->modify('+6 days')->format('Y-m-d'),
            ];
            $monday = $monday->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     * @return array<int, int>
     */
    public static function listAmirsWithBildirimActivity(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters = [])
    {
        $where = [
            'gb.sube_id = :sube_id',
            'gb.tarih BETWEEN :ay_baslangic AND :ay_bitis',
            "gb.state <> 'IPTAL'",
            'gb.created_by IS NOT NULL',
        ];
        $params = [
            'sube_id' => (int) $subeId,
            'ay_baslangic' => $ayBaslangic,
            'ay_bitis' => $ayBitis,
        ];
        self::appendBildirimFilters($where, $params, $filters, 'gb');

        $sql = 'SELECT DISTINCT gb.created_by AS amir_id
            FROM gunluk_bildirimler gb
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY gb.created_by ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['amir_id'];
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function fetchBildirimler(PDO $pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, array $filters = [])
    {
        $where = [
            'gb.sube_id = :sube_id',
            'gb.tarih BETWEEN :ay_baslangic AND :ay_bitis',
        ];
        $params = [
            'sube_id' => (int) $subeId,
            'ay_baslangic' => $ayBaslangic,
            'ay_bitis' => $ayBitis,
        ];
        if ($amirId !== null) {
            $where[] = 'gb.created_by = :created_by';
            $params['created_by'] = (int) $amirId;
        }
        self::appendBildirimFilters($where, $params, $filters, 'gb');

        $stmt = $pdo->prepare(
            'SELECT gb.id, gb.tarih, gb.state, gb.haftalik_mutabakat_id, gb.created_by, gb.personel_id
             FROM gunluk_bildirimler gb
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     * @return array{counts: array<string, int>, haftalar: array<int, array<string, mixed>>}
     */
    public static function buildMonthContext(PDO $pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, array $filters = [])
    {
        $rows = self::fetchBildirimler($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $filters);
        $weeks = self::listWeeksIntersectingMonth($ayBaslangic, $ayBitis);
        $weekStarts = array_map(static function (array $week) {
            return $week['hafta_baslangic'];
        }, $weeks);
        $mutabakatMap = $amirId !== null
            ? self::fetchMutabakatMap($pdo, $subeId, $amirId, $weekStarts)
            : [];

        $counts = [
            'toplam_bildirim' => 0,
            'mutabakata_alinan' => 0,
            'mutabakatli_hafta' => 0,
            'eksik_hafta' => 0,
            'taslak' => 0,
            'duzeltme_istendi' => 0,
            'gonderildi' => 0,
        ];
        $stateMap = [
            'TASLAK' => 'taslak',
            'GONDERILDI' => 'gonderildi',
            'DUZELTME_ISTENDI' => 'duzeltme_istendi',
            'HAFTALIK_MUTABAKATA_ALINDI' => 'mutabakata_alinan',
        ];

        foreach ($rows as $row) {
            $state = strtoupper(trim((string) $row['state']));
            if ($state === 'IPTAL') {
                continue;
            }
            $counts['toplam_bildirim']++;
            if (isset($stateMap[$state])) {
                $counts[$stateMap[$state]]++;
            }
        }

        $haftalar = [];
        foreach ($weeks as $week) {
            $weekStart = $week['hafta_baslangic'];
            $weekEnd = $week['hafta_bitis'];
            $weekRows = array_filter($rows, static function (array $row) use ($weekStart, $weekEnd, $ayBaslangic, $ayBitis) {
                $tarih = (string) $row['tarih'];
                if ($tarih < $ayBaslangic || $tarih > $ayBitis) {
                    return false;
                }

                return $tarih >= $weekStart && $tarih <= $weekEnd && strtoupper((string) $row['state']) !== 'IPTAL';
            });

            $bildirimSayisi = count($weekRows);
            $mutabakat = $mutabakatMap[$weekStart] ?? null;
            $eksikMi = $bildirimSayisi > 0 && $mutabakat === null;
            if ($eksikMi) {
                $counts['eksik_hafta']++;
            } elseif ($mutabakat !== null) {
                $counts['mutabakatli_hafta']++;
            }

            $haftalar[] = [
                'hafta_baslangic' => $weekStart,
                'hafta_bitis' => $weekEnd,
                'mutabakat_id' => $mutabakat ? (int) $mutabakat['id'] : null,
                'state' => $mutabakat ? (string) $mutabakat['state'] : null,
                'bildirim_sayisi' => $bildirimSayisi,
                'eksik_mi' => $eksikMi,
            ];
        }

        return ['counts' => $counts, 'haftalar' => $haftalar];
    }

    public static function fetchAylikOnay(PDO $pdo, $subeId, $amirId, $ay)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM aylik_bildirim_onaylari
             WHERE sube_id = :sube_id AND birim_amiri_user_id = :amir_id AND ay = :ay
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'amir_id' => (int) $amirId,
            'ay' => (string) $ay,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function fetchGyOnay(PDO $pdo, $subeId, $amirId, $ay)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM genel_yonetici_bildirim_onaylari
             WHERE sube_id = :sube_id AND birim_amiri_user_id = :amir_id AND ay = :ay
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'amir_id' => (int) $amirId,
            'ay' => (string) $ay,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function countEligibleGySources(PDO $pdo, $gyOnayId)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT gb.id) AS cnt
             FROM genel_yonetici_bildirim_onaylari gy
             INNER JOIN aylik_bildirim_onaylari abo ON abo.id = gy.aylik_bildirim_onayi_id
             INNER JOIN gunluk_bildirimler gb ON gb.sube_id = gy.sube_id
               AND gb.created_by = gy.birim_amiri_user_id
               AND gb.tarih BETWEEN abo.ay_baslangic AND abo.ay_bitis
               AND gb.state = :state
             WHERE gy.id = :gy_id'
        );
        $stmt->execute(['gy_id' => (int) $gyOnayId, 'state' => 'HAFTALIK_MUTABAKATA_ALINDI']);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<int, string> $weekStarts */
    private static function fetchMutabakatMap(PDO $pdo, $subeId, $amirId, array $weekStarts)
    {
        if (count($weekStarts) === 0) {
            return [];
        }

        $placeholders = [];
        $params = [
            'sube_id' => (int) $subeId,
            'birim_amiri_user_id' => (int) $amirId,
        ];
        foreach ($weekStarts as $index => $weekStart) {
            $key = 'week_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $weekStart;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM haftalik_bildirim_mutabakatlari
             WHERE sube_id = :sube_id AND birim_amiri_user_id = :birim_amiri_user_id
               AND hafta_baslangic IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['hafta_baslangic']] = $row;
        }

        return $map;
    }

    /**
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     */
    private static function appendBildirimFilters(array &$where, array &$params, array $filters, $alias)
    {
        if (isset($filters['departman_id']) && $filters['departman_id'] !== null && (int) $filters['departman_id'] > 0) {
            $where[] = $alias . '.departman_id = :filter_departman_id';
            $params['filter_departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && $filters['personel_id'] !== null && (int) $filters['personel_id'] > 0) {
            $where[] = $alias . '.personel_id = :filter_personel_id';
            $params['filter_personel_id'] = (int) $filters['personel_id'];
        }
    }
}
