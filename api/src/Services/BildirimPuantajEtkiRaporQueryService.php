<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

class BildirimPuantajEtkiRaporQueryService
{
    private const TABLE = 'onayli_bildirim_puantaj_etki_adaylari';
    private const RESOLUTION_TABLE = 'bildirim_puantaj_etki_cakisma_cozumleri';
    private const MAX_LIMIT = 250;

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   page: int,
     *   limit: int,
     *   total: int,
     *   total_pages: int,
     *   has_next_page: bool,
     *   has_prev_page: bool
     * }
     */
    public static function query(PDO $pdo, array $filters, $page, $limit, $restrictAmirId = null)
    {
        $page = max(1, (int) $page);
        $limit = max(1, min(self::MAX_LIMIT, (int) $limit));
        [$whereSql, $params, $joinSql] = self::buildFilterClause($filters, $restrictAmirId);

        $total = self::countRows($pdo, $joinSql, $whereSql, $params);
        $offset = ($page - 1) * $limit;
        $rows = self::fetchRows($pdo, $joinSql, $whereSql, $params, $limit, $offset);

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::mapRow($pdo, $row);
        }

        return [
            'items' => $items,
            'summary' => self::buildSummary($pdo, $joinSql, $whereSql, $params),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $limit)),
            'has_next_page' => $page * $limit < $total,
            'has_prev_page' => $page > 1,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function exportRows(PDO $pdo, array $filters, $restrictAmirId = null)
    {
        [$whereSql, $params, $joinSql] = self::buildFilterClause($filters, $restrictAmirId);
        $rows = self::fetchRows($pdo, $joinSql, $whereSql, $params, null, null);
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::mapRow($pdo, $row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private static function buildFilterClause(array $filters, $restrictAmirId)
    {
        $where = ['a.sube_id = :sube_id', 'a.ay = :donem'];
        $params = [
            'sube_id' => (int) $filters['sube_id'],
            'donem' => (string) $filters['donem'],
        ];

        if ($restrictAmirId !== null) {
            $where[] = 'a.birim_amiri_user_id = :restrict_amir_id';
            $params['restrict_amir_id'] = (int) $restrictAmirId;
        }

        if (isset($filters['departman_id']) && (int) $filters['departman_id'] > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = 'a.personel_id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }
        if (isset($filters['state']) && trim((string) $filters['state']) !== '') {
            $where[] = 'a.state = :state';
            $params['state'] = strtoupper(trim((string) $filters['state']));
        }
        if (isset($filters['conflict_code']) && trim((string) $filters['conflict_code']) !== '') {
            $where[] = 'a.conflict_code = :conflict_code';
            $params['conflict_code'] = strtoupper(trim((string) $filters['conflict_code']));
        }
        if (isset($filters['etki_turu']) && trim((string) $filters['etki_turu']) !== '') {
            $where[] = 'a.etki_turu = :etki_turu';
            $params['etki_turu'] = strtoupper(trim((string) $filters['etki_turu']));
        }
        if (isset($filters['uygulama_modu']) && trim((string) $filters['uygulama_modu']) !== '') {
            $where[] = 'a.uygulama_modu = :uygulama_modu';
            $params['uygulama_modu'] = strtoupper(trim((string) $filters['uygulama_modu']));
        }
        if (isset($filters['projection_version']) && trim((string) $filters['projection_version']) !== '') {
            $where[] = 'a.projection_version = :projection_version';
            $params['projection_version'] = trim((string) $filters['projection_version']);
        }
        if (isset($filters['karar_veren_user_id']) && (int) $filters['karar_veren_user_id'] > 0) {
            $where[] = 'a.karar_veren_user_id = :karar_veren_user_id';
            $params['karar_veren_user_id'] = (int) $filters['karar_veren_user_id'];
        }
        if (isset($filters['karar_turu']) && trim((string) $filters['karar_turu']) !== '') {
            $where[] = '(c.karar_turu = :karar_turu OR a.manuel_karar_turu = :karar_turu)';
            $params['karar_turu'] = strtoupper(trim((string) $filters['karar_turu']));
        }

        $joinSql = '
            FROM ' . self::TABLE . ' a
            INNER JOIN personeller p ON p.id = a.personel_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN ' . self::RESOLUTION_TABLE . ' c ON c.aday_id = a.id
        ';

        return [implode(' AND ', $where), $params, $joinSql];
    }

    /** @param array<string, mixed> $params */
    private static function countRows(PDO $pdo, $joinSql, $whereSql, array $params)
    {
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT a.id) ' . $joinSql . ' WHERE ' . $whereSql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRows(PDO $pdo, $joinSql, $whereSql, array $params, $limit, $offset)
    {
        $sql = '
            SELECT DISTINCT a.*,
                   p.ad AS personel_ad,
                   p.soyad AS personel_soyad,
                   p.sicil_no AS personel_sicil_no,
                   d.ad AS departman_ad,
                   c.karar_turu AS cakisma_karar_turu
            ' . $joinSql . '
            WHERE ' . $whereSql . '
            ORDER BY a.tarih ASC, a.id ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $pdo->prepare($sql);
        self::bindParams($stmt, $params);
        if ($limit !== null) {
            $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function buildSummary(PDO $pdo, $joinSql, $whereSql, array $params)
    {
        $stmt = $pdo->prepare('
            SELECT
                COUNT(DISTINCT a.id) AS toplam,
                SUM(CASE WHEN a.state = \'HAZIR\' THEN 1 ELSE 0 END) AS hazir,
                SUM(CASE WHEN a.state = \'INCELEME_GEREKLI\' THEN 1 ELSE 0 END) AS inceleme_gerekli,
                SUM(CASE WHEN a.state = \'UYGULANDI\' THEN 1 ELSE 0 END) AS uygulandi,
                SUM(CASE WHEN a.state = \'YOK_SAYILDI\' THEN 1 ELSE 0 END) AS yok_sayildi,
                SUM(CASE WHEN a.state = \'UYGULANDI\' AND a.uygulama_modu = \'OTOMATIK\' THEN 1 ELSE 0 END) AS otomatik,
                SUM(CASE WHEN a.state = \'UYGULANDI\' AND a.uygulama_modu = \'MANUEL\' THEN 1 ELSE 0 END) AS manuel,
                SUM(CASE WHEN c.karar_turu = \'MEVCUT_PUANTAJI_KORU\' THEN 1 ELSE 0 END) AS koru,
                SUM(CASE WHEN c.karar_turu = \'ADAY_ETKISIYLE_REVIZE_ET\' THEN 1 ELSE 0 END) AS revize
            ' . $joinSql . '
            WHERE ' . $whereSql
        );
        self::bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $minuteTotals = self::computeEffectiveMinuteTotals($pdo, $joinSql, $whereSql, $params);

        return [
            'toplam' => (int) ($row['toplam'] ?? 0),
            'hazir' => (int) ($row['hazir'] ?? 0),
            'inceleme_gerekli' => (int) ($row['inceleme_gerekli'] ?? 0),
            'uygulandi' => (int) ($row['uygulandi'] ?? 0),
            'yok_sayildi' => (int) ($row['yok_sayildi'] ?? 0),
            'otomatik' => (int) ($row['otomatik'] ?? 0),
            'manuel' => (int) ($row['manuel'] ?? 0),
            'koru' => (int) ($row['koru'] ?? 0),
            'revize' => (int) ($row['revize'] ?? 0),
            'toplam_gecikme_dakika' => $minuteTotals['toplam_gecikme_dakika'],
            'toplam_erken_cikis_dakika' => $minuteTotals['toplam_erken_cikis_dakika'],
            'toplam_devamsizlik_gun' => $minuteTotals['toplam_devamsizlik_gun'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{toplam_gecikme_dakika: int, toplam_erken_cikis_dakika: int, toplam_devamsizlik_gun: int}
     */
    private static function computeEffectiveMinuteTotals(PDO $pdo, $joinSql, $whereSql, array $params)
    {
        $stmt = $pdo->prepare('SELECT DISTINCT a.* ' . $joinSql . ' WHERE ' . $whereSql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        $gecikme = 0;
        $erken = 0;
        $devamsizlik = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $effective = BildirimPuantajEtkiPuantajMapper::withEffectiveEtkiPayload($row);
            $tur = strtoupper(trim((string) ($effective['etki_turu'] ?? '')));
            $miktar = $effective['etki_miktari'] !== null && $effective['etki_miktari'] !== ''
                ? (int) $effective['etki_miktari']
                : 0;
            if ($tur === 'GEC_KALMA_DAKIKA') {
                $gecikme += $miktar;
            } elseif ($tur === 'ERKEN_CIKIS_DAKIKA') {
                $erken += $miktar;
            } elseif ($tur === 'DEVAMSIZLIK_GUN') {
                $devamsizlik += $miktar > 0 ? $miktar : 1;
            }
        }

        return [
            'toplam_gecikme_dakika' => $gecikme,
            'toplam_erken_cikis_dakika' => $erken,
            'toplam_devamsizlik_gun' => $devamsizlik,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function mapRow(PDO $pdo, array $row)
    {
        $effective = BildirimPuantajEtkiPuantajMapper::withEffectiveEtkiPayload($row);
        $kararTuru = self::resolveKararTuru($row);

        return [
            'aday_id' => (int) $effective['id'],
            'personel_id' => (int) $effective['personel_id'],
            'personel' => self::maskeliPersonelOzet($row),
            'departman' => $row['departman_ad'] !== null ? (string) $row['departman_ad'] : null,
            'tarih' => (string) $effective['tarih'],
            'bildirim_turu' => (string) $effective['bildirim_turu'],
            'etki_turu' => (string) $effective['etki_turu'],
            'etki_miktari' => $effective['etki_miktari'] !== null ? (int) $effective['etki_miktari'] : null,
            'etki_birimi' => $effective['etki_birimi'] !== null ? (string) $effective['etki_birimi'] : null,
            'state' => (string) $effective['state'],
            'conflict_code' => $effective['conflict_code'] !== null ? (string) $effective['conflict_code'] : null,
            'mevcut_puantaj_id' => $effective['mevcut_puantaj_id'] !== null ? (int) $effective['mevcut_puantaj_id'] : null,
            'uygulanan_puantaj_id' => $effective['uygulanan_puantaj_id'] !== null ? (int) $effective['uygulanan_puantaj_id'] : null,
            'uygulama_modu' => (string) ($effective['uygulama_modu'] ?? 'OTOMATIK'),
            'karar_turu' => $kararTuru,
            'karar_veren_user_id' => $effective['karar_veren_user_id'] !== null ? (int) $effective['karar_veren_user_id'] : null,
            'karar_zamani' => $effective['karar_zamani'] !== null ? (string) $effective['karar_zamani'] : null,
            'projection_version' => $effective['projection_version'] !== null ? (string) $effective['projection_version'] : null,
            'source_integrity' => self::resolveSourceIntegrity($effective),
            'audit_integrity' => self::resolveAuditIntegrity($pdo, $effective),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function maskeliPersonelOzet(array $row)
    {
        $ad = trim((string) ($row['personel_ad'] ?? ''));
        $soyad = trim((string) ($row['personel_soyad'] ?? ''));

        return [
            'personel_id' => (int) $row['personel_id'],
            'sicil_no' => (string) ($row['personel_sicil_no'] ?? ''),
            'maskeli_ad_soyad' => self::maskName($ad, $soyad),
        ];
    }

    private static function maskName($ad, $soyad)
    {
        $maskedAd = $ad !== '' ? mb_substr($ad, 0, 1) . '***' : '***';
        $maskedSoyad = $soyad !== '' ? mb_substr($soyad, 0, 1) . '***' : '***';

        return trim($maskedAd . ' ' . $maskedSoyad);
    }

    /** @param array<string, mixed> $row */
    private static function resolveKararTuru(array $row)
    {
        if ($row['cakisma_karar_turu'] !== null && trim((string) $row['cakisma_karar_turu']) !== '') {
            return (string) $row['cakisma_karar_turu'];
        }
        if ($row['manuel_karar_turu'] !== null && trim((string) $row['manuel_karar_turu']) !== '') {
            return (string) $row['manuel_karar_turu'];
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private static function resolveSourceIntegrity(array $row)
    {
        $resolved = BildirimPuantajEtkiPuantajMapper::resolveEffectiveEtkiPayload($row);
        if (($resolved['ok'] ?? false) === true) {
            return 'OK';
        }

        return (string) ($resolved['code'] ?? 'FAILED');
    }

    /** @param array<string, mixed> $row */
    private static function resolveAuditIntegrity(PDO $pdo, array $row)
    {
        $state = strtoupper(trim((string) ($row['state'] ?? '')));
        if ($state !== 'UYGULANDI') {
            return 'NA';
        }

        $puantajId = isset($row['uygulanan_puantaj_id']) ? (int) $row['uygulanan_puantaj_id'] : 0;
        $hash = trim((string) ($row['uygulama_hash'] ?? ''));
        if ($puantajId < 1 || $hash === '') {
            return 'FAILED';
        }

        $stmt = $pdo->prepare('SELECT * FROM gunluk_puantaj WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $puantajId]);
        $puantaj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$puantaj) {
            return 'FAILED';
        }

        $snapshot = BildirimPuantajEtkiApplyService::buildApplySnapshot((int) $row['id'], $puantaj);
        $recomputed = BildirimPuantajEtkiApplyService::computeUygulamaHash($row, $snapshot);

        return hash_equals($hash, $recomputed) ? 'OK' : 'FAILED';
    }

    /** @param array<string, mixed> $params */
    private static function bindParams(\PDOStatement $stmt, array $params)
    {
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
    }
}
