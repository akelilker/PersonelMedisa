<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

class DonemKapanisPreflightItemsService
{
    private const MAX_LIMIT = 250;

    /**
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     * @return array{items: array<int, array<string, mixed>>, page: int, limit: int, total: int, total_pages: int, has_next_page: bool, has_prev_page: bool}
     */
    public static function listItems(PDO $pdo, $subeId, $yil, $ay, $code, $severity, $page, $limit, array $filters = [], $restrictAmirId = null)
    {
        $page = max(1, (int) $page);
        $limit = max(1, min(self::MAX_LIMIT, (int) $limit));
        [$donem, $ayBaslangic, $ayBitis] = BildirimDonemContextService::monthBounds($yil, $ay);
        $code = strtoupper(trim((string) $code));
        $severity = strtoupper(trim((string) $severity));

        $allItems = self::fetchAllForCode($pdo, (int) $subeId, $donem, $ayBaslangic, $ayBitis, $code, $filters, $restrictAmirId);
        if ($severity !== '') {
            $allItems = array_values(array_filter($allItems, static function (array $item) use ($severity) {
                return strtoupper((string) ($item['severity'] ?? '')) === $severity;
            }));
        }

        $total = count($allItems);
        $offset = ($page - 1) * $limit;
        $items = array_slice($allItems, $offset, $limit);

        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $limit)),
            'has_next_page' => $page * $limit < $total,
            'has_prev_page' => $page > 1,
        ];
    }

    /**
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function exportRows(PDO $pdo, $subeId, $yil, $ay, array $filters = [], $restrictAmirId = null)
    {
        [$donem, $ayBaslangic, $ayBitis] = BildirimDonemContextService::monthBounds($yil, $ay);
        $preflight = DonemKapanisPreflightService::evaluate($pdo, (int) $subeId, (int) $yil, (int) $ay, $filters, $restrictAmirId);
        $rows = [];
        foreach (array_merge($preflight['blockers'], $preflight['warnings'], $preflight['infos']) as $issue) {
            $code = (string) $issue['code'];
            $items = self::fetchAllForCode($pdo, (int) $subeId, $donem, $ayBaslangic, $ayBitis, $code, $filters, $restrictAmirId);
            foreach ($items as $item) {
                $rows[] = [
                    'code' => $code,
                    'severity' => (string) $issue['severity'],
                    'domain' => (string) $issue['domain'],
                    'title' => (string) $issue['title'],
                    'record_id' => $item['record_id'] ?? null,
                    'personel_id' => $item['personel_id'] ?? null,
                    'tarih' => $item['tarih'] ?? null,
                    'state' => $item['state'] ?? null,
                    'detail' => $item['detail'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     * @return array<int, array<string, mixed>>
     */
    private static function fetchAllForCode(PDO $pdo, $subeId, $donem, $ayBaslangic, $ayBitis, $code, array $filters, $restrictAmirId)
    {
        switch ($code) {
            case 'NOTIF_DRAFT_OR_CORRECTION':
                return self::draftBildirimItems($pdo, $subeId, $ayBaslangic, $ayBitis, $filters, $restrictAmirId);
            case 'PUANTAJ_CONTROL_PENDING':
                return self::puantajControlItems($pdo, $subeId, $ayBaslangic, $ayBitis, $filters);
            case 'CANDIDATE_HAZIR_PENDING':
                return self::candidateItems($pdo, $subeId, $donem, 'HAZIR', $filters);
            case 'CANDIDATE_INCELEME_PENDING':
                return self::candidateItems($pdo, $subeId, $donem, 'INCELEME_GEREKLI', $filters);
            case 'FINANCE_SALARY_MISSING':
                return self::salaryMissingItems($pdo, $subeId, $filters);
            case 'PUANTAJ_DAY_ROW_MISSING':
                return self::puantajMissingItems($pdo, $subeId, $ayBaslangic, $ayBitis, $filters);
            case 'PUANTAJ_MANUAL_NO_NOTE':
                return self::manualNoNoteItems($pdo, $subeId, $ayBaslangic, $ayBitis, $filters);
            default:
                return [];
        }
    }

    /** @param array<string, mixed> $filters */
    private static function draftBildirimItems(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters, $restrictAmirId)
    {
        $amirId = $restrictAmirId !== null ? (int) $restrictAmirId : null;
        $rows = BildirimDonemContextService::fetchBildirimler($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $filters);
        $items = [];
        foreach ($rows as $row) {
            $state = strtoupper((string) $row['state']);
            if ($state !== 'TASLAK' && $state !== 'DUZELTME_ISTENDI') {
                continue;
            }
            $items[] = [
                'record_id' => (int) $row['id'],
                'personel_id' => (int) $row['personel_id'],
                'tarih' => (string) $row['tarih'],
                'state' => $state,
                'detail' => 'Bildirim ' . $state,
                'severity' => DonemKapanisPreflightService::SEVERITY_BLOCKER,
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $filters */
    private static function puantajControlItems(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters)
    {
        $where = [
            'p.sube_id = :sube_id',
            'gp.tarih BETWEEN :bas AND :bit',
            "gp.kontrol_durumu = 'BEKLIYOR'",
        ];
        $params = ['sube_id' => $subeId, 'bas' => $ayBaslangic, 'bit' => $ayBitis];
        self::appendPersonelFilters($where, $params, $filters, 'p', 'gp');
        $stmt = $pdo->prepare(
            'SELECT gp.id, gp.personel_id, gp.tarih, gp.kontrol_durumu
             FROM gunluk_puantaj gp INNER JOIN personeller p ON p.id = gp.personel_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY gp.tarih ASC, gp.id ASC'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'record_id' => (int) $row['id'],
                'personel_id' => (int) $row['personel_id'],
                'tarih' => (string) $row['tarih'],
                'state' => (string) $row['kontrol_durumu'],
                'detail' => 'Kontrol bekliyor',
                'severity' => DonemKapanisPreflightService::SEVERITY_BLOCKER,
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $filters */
    private static function candidateItems(PDO $pdo, $subeId, $donem, $state, array $filters)
    {
        $where = ['a.sube_id = :sube_id', 'a.ay = :ay', 'a.state = :state'];
        $params = ['sube_id' => $subeId, 'ay' => $donem, 'state' => $state];
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = 'a.personel_id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }
        $stmt = $pdo->prepare(
            'SELECT a.id, a.personel_id, a.tarih, a.state, a.conflict_code
             FROM onayli_bildirim_puantaj_etki_adaylari a
             WHERE ' . implode(' AND ', $where) . ' ORDER BY a.tarih ASC, a.id ASC'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'record_id' => (int) $row['id'],
                'personel_id' => (int) $row['personel_id'],
                'tarih' => (string) $row['tarih'],
                'state' => (string) $row['state'],
                'detail' => (string) ($row['conflict_code'] ?? ''),
                'severity' => DonemKapanisPreflightService::SEVERITY_BLOCKER,
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $filters */
    private static function salaryMissingItems(PDO $pdo, $subeId, array $filters)
    {
        $where = ['sube_id = :sube_id', "aktif_durum = 'AKTIF'", '(maas_tutari IS NULL OR maas_tutari <= 0)'];
        $params = ['sube_id' => $subeId];
        if (isset($filters['departman_id']) && (int) $filters['departman_id'] > 0) {
            $where[] = 'departman_id = :departman_id';
            $params['departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = 'id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC');
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'record_id' => (int) $row['id'],
                'personel_id' => (int) $row['id'],
                'tarih' => null,
                'state' => 'MAAS_EKSIK',
                'detail' => 'Maas bilgisi eksik',
                'severity' => DonemKapanisPreflightService::SEVERITY_WARNING,
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $filters */
    private static function puantajMissingItems(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters)
    {
        $where = ['p.sube_id = :sube_id', "p.aktif_durum = 'AKTIF'"];
        $params = ['sube_id' => $subeId, 'bas' => $ayBaslangic, 'bit' => $ayBitis];
        self::appendPersonelFilters($where, $params, $filters, 'p', 'p');
        $stmt = $pdo->prepare(
            'SELECT p.id FROM personeller p
             WHERE ' . implode(' AND ', $where) . '
               AND NOT EXISTS (
                 SELECT 1 FROM gunluk_puantaj gp
                 WHERE gp.personel_id = p.id AND gp.tarih BETWEEN :bas AND :bit
               )
             ORDER BY p.id ASC'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'record_id' => (int) $row['id'],
                'personel_id' => (int) $row['id'],
                'tarih' => null,
                'state' => 'PUANTAJ_YOK',
                'detail' => 'Donemde puantaj satiri yok',
                'severity' => DonemKapanisPreflightService::SEVERITY_WARNING,
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $filters */
    private static function manualNoNoteItems(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters)
    {
        $where = [
            'p.sube_id = :sube_id',
            'gp.tarih BETWEEN :bas AND :bit',
            "gp.kaynak = 'MANUEL'",
            '(gp.aciklama IS NULL OR TRIM(gp.aciklama) = \'\')',
        ];
        $params = ['sube_id' => $subeId, 'bas' => $ayBaslangic, 'bit' => $ayBitis];
        self::appendPersonelFilters($where, $params, $filters, 'p', 'gp');
        $stmt = $pdo->prepare(
            'SELECT gp.id, gp.personel_id, gp.tarih FROM gunluk_puantaj gp
             INNER JOIN personeller p ON p.id = gp.personel_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY gp.tarih ASC, gp.id ASC'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'record_id' => (int) $row['id'],
                'personel_id' => (int) $row['personel_id'],
                'tarih' => (string) $row['tarih'],
                'state' => 'ACIKLAMASIZ',
                'detail' => 'Manuel puantaj aciklamasi yok',
                'severity' => DonemKapanisPreflightService::SEVERITY_WARNING,
            ];
        }

        return $items;
    }

    /**
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param array<string, mixed> $filters
     */
    private static function appendPersonelFilters(array &$where, array &$params, array $filters, $personelAlias, $puantajAlias)
    {
        if (isset($filters['departman_id']) && (int) $filters['departman_id'] > 0) {
            $where[] = $personelAlias . '.departman_id = :departman_id';
            $params['departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = $puantajAlias . '.personel_id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }
    }
}
