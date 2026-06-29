<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use PDO;

class SureclerController
{
    public static function list(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 20) ?: 20)));
        $personelId = (int) ($request->getQuery('personel_id', 0) ?: 0);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['1=1'];
        $params = [];

        if ($personelId > 0) {
            $where[] = 'personel_id = :personel_id';
            $params['personel_id'] = $personelId;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM surecler WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "
            SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                   ucretli_mi, aciklama, state
            FROM surecler
            WHERE $whereSql
            ORDER BY id DESC
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
            $items[] = self::mapSurecRow($row);
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

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapSurecRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'surec_turu' => (string) $row['surec_turu'],
            'alt_tur' => $row['alt_tur'] !== null ? (string) $row['alt_tur'] : null,
            'baslangic_tarihi' => (string) $row['baslangic_tarihi'],
            'bitis_tarihi' => $row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null,
            'ucretli_mi' => (bool) ((int) ($row['ucretli_mi'] ?? 0)),
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'state' => (string) $row['state'],
        ];
    }
}
