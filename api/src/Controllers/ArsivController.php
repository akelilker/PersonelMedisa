<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Retention\ArchiveAccessService;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use Throwable;

class ArsivController
{
    public static function listPasifPersoneller(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'arsiv.view');

        try {
            $pdo = Connection::get();
        } catch (Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 25) ?: 25)));
        $search = strtolower(trim((string) $request->getQuery('search', '')));
        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        $where = ["p.aktif_durum = 'PASIF'"];
        $params = [];
        if ($scope !== null) {
            $where[] = 'p.sube_id = :scope_sube_id';
            $params['scope_sube_id'] = $scope;
        } elseif (count($allowedSubeIds) > 0) {
            $placeholders = [];
            foreach ($allowedSubeIds as $index => $subeId) {
                $key = 'allowed_sube_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $subeId;
            }
            $where[] = 'p.sube_id IN (' . implode(', ', $placeholders) . ')';
        }
        if ($search !== '') {
            $where[] = '(LOWER(p.ad) LIKE :search_ad OR LOWER(p.soyad) LIKE :search_soyad OR p.tc_kimlik_no LIKE :search_tc)';
            $like = '%' . $search . '%';
            $params['search_ad'] = $like;
            $params['search_soyad'] = $like;
            $params['search_tc'] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "
            SELECT p.id, p.ad, p.soyad, p.tc_kimlik_no, p.sicil_no, p.aktif_durum, p.sube_id,
                   p.ise_giris_tarihi, s.ad AS sube_adi
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad'],
                'soyad' => $row['soyad'] !== null && $row['soyad'] !== '' ? (string) $row['soyad'] : null,
                'tc_kimlik_no' => $row['tc_kimlik_no'] !== null && $row['tc_kimlik_no'] !== '' ? (string) $row['tc_kimlik_no'] : null,
                'sicil_no' => $row['sicil_no'],
                'aktif_durum' => (string) $row['aktif_durum'],
                'sube_id' => (int) $row['sube_id'],
                'sube_adi' => $row['sube_adi'],
                'ise_giris_tarihi' => $row['ise_giris_tarihi'],
                'arsiv_modu' => true,
                'policy_note' => RetentionCategories::POLICY_NOTE,
            ];
        }

        PersonelArchiveGate::maybeWriteListAudit($pdo, $user, $items, '/arsiv/personeller');

        JsonResponse::success(
            ['items' => $items, 'policy_note' => RetentionCategories::POLICY_NOTE],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / max(1, $limit))),
            ]
        );
    }

    public static function detailPasifPersonel(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'arsiv.view');

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $sql = "
            SELECT p.id, p.ad, p.soyad, p.tc_kimlik_no, p.sicil_no, p.aktif_durum, p.sube_id,
                   p.ise_giris_tarihi, p.telefon, p.dogum_tarihi,
                   s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            WHERE p.id = :id
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::notFound();
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $row['sube_id']);

        if (strtoupper((string) $row['aktif_durum']) !== 'PASIF') {
            JsonResponse::badRequest('Bu endpoint yalnizca pasif (arsiv) personeller icindir.', 'NOT_ARCHIVE');
        }

        ArchiveAccessService::writeAccessAudit(
            $pdo,
            $user,
            ArchiveAccessService::ACTION_VIEW,
            'personel',
            $personelId,
            $personelId,
            '/arsiv/personeller/{id}',
            null
        );

        $markers = PersonelArchiveGate::buildArchiveMarkers($pdo, $row);
        JsonResponse::success([
            'id' => (int) $row['id'],
            'ad' => (string) $row['ad'],
            'soyad' => $row['soyad'] !== null && $row['soyad'] !== '' ? (string) $row['soyad'] : null,
            'tc_kimlik_no' => $row['tc_kimlik_no'] !== null && $row['tc_kimlik_no'] !== '' ? (string) $row['tc_kimlik_no'] : null,
            'sicil_no' => $row['sicil_no'],
            'aktif_durum' => (string) $row['aktif_durum'],
            'sube_id' => (int) $row['sube_id'],
            'sube_adi' => $row['sube_adi'],
            'departman_adi' => $row['departman_adi'],
            'gorev_adi' => $row['gorev_adi'],
            'ise_giris_tarihi' => $row['ise_giris_tarihi'],
            'telefon' => $row['telefon'],
            'dogum_tarihi' => $row['dogum_tarihi'],
            'read_only' => true,
            'arsiv_modu' => true,
            'policy_note' => RetentionCategories::POLICY_NOTE,
            'retention_summary' => $markers['retention_summary'],
            'legal_hold_active' => $markers['legal_hold_active'],
        ]);
    }
}
