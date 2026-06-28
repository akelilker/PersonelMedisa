<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class PersonellerController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $scope = SubeScope::resolveScope($user, $request);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 10) ?: 10)));
        $search = strtolower(trim((string) $request->getQuery('search', '')));
        $aktiflik = (string) $request->getQuery('aktiflik', 'tum');
        $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
        $personelTipiId = (int) ($request->getQuery('personel_tipi_id', 0) ?: 0);

        $applyScope = $scope !== null && $limit <= 10;

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['1=1'];
        $params = [];

        if ($applyScope) {
            $where[] = 'p.sube_id = :scope_sube_id';
            $params['scope_sube_id'] = $scope;
        } elseif ($scope !== null) {
            $where[] = 'p.sube_id = :scope_sube_id';
            $params['scope_sube_id'] = $scope;
        }

        if ($aktiflik === 'aktif') {
            $where[] = "p.aktif_durum = 'AKTIF'";
        } elseif ($aktiflik === 'pasif') {
            $where[] = "p.aktif_durum = 'PASIF'";
        }

        if ($departmanId > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }

        if ($personelTipiId > 0) {
            $where[] = 'p.personel_tipi_id = :personel_tipi_id';
            $params['personel_tipi_id'] = $personelTipiId;
        }

        if ($search !== '') {
            $where[] = '(LOWER(p.ad) LIKE :search OR LOWER(p.soyad) LIKE :search OR p.tc_kimlik_no LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "
            SELECT p.*, s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
            WHERE $whereSql
            ORDER BY p.id ASC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapPersonelRow($row);
        }

        JsonResponse::success(
            ['items' => $items],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ]
        );
    }

    public static function detail(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            JsonResponse::notFound();
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $exists['sube_id']);

        $sql = "
            SELECT p.*, s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
            WHERE p.id = :id
            LIMIT 1
        ";
        $detailStmt = $pdo->prepare($sql);
        $detailStmt->execute(['id' => $personelId]);
        $row = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::notFound();
        }

        JsonResponse::success(self::mapPersonelRow($row));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapPersonelRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'tc_kimlik_no' => (string) $row['tc_kimlik_no'],
            'ad' => (string) $row['ad'],
            'soyad' => (string) $row['soyad'],
            'aktif_durum' => (string) $row['aktif_durum'],
            'sube_id' => (int) $row['sube_id'],
            'telefon' => $row['telefon'],
            'dogum_tarihi' => $row['dogum_tarihi'],
            'sicil_no' => $row['sicil_no'],
            'dogum_yeri' => $row['dogum_yeri'],
            'kan_grubu' => $row['kan_grubu'],
            'ise_giris_tarihi' => $row['ise_giris_tarihi'],
            'acil_durum_kisi' => $row['acil_durum_kisi'],
            'acil_durum_telefon' => $row['acil_durum_telefon'],
            'departman_id' => $row['departman_id'] !== null ? (int) $row['departman_id'] : null,
            'gorev_id' => $row['gorev_id'] !== null ? (int) $row['gorev_id'] : null,
            'personel_tipi_id' => $row['personel_tipi_id'] !== null ? (int) $row['personel_tipi_id'] : null,
            'bagli_amir_id' => $row['bagli_amir_id'] !== null ? (int) $row['bagli_amir_id'] : null,
            'sube_adi' => $row['sube_adi'],
            'departman_adi' => $row['departman_adi'],
            'gorev_adi' => $row['gorev_adi'],
            'personel_tipi_adi' => $row['personel_tipi_adi'],
            'referans_adlari' => [
                'sube' => $row['sube_adi'],
                'departman' => $row['departman_adi'],
                'gorev' => $row['gorev_adi'],
                'personel_tipi' => $row['personel_tipi_adi'],
            ],
        ];
    }
}
