<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class RaporlarController
{
    /** @var array<string, string> */
    private static $allowedTips = [
        'personel-ozet' => 'personel-ozet',
        'devamsizlik' => 'devamsizlik',
        'izin' => 'izin',
        'bildirim' => 'bildirim',
    ];

    public static function show(Request $request, $tip)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $tip = (string) $tip;

        if (!isset(self::$allowedTips[$tip])) {
            JsonResponse::badRequest('Desteklenmeyen rapor tipi.', 'UNSUPPORTED_REPORT');
        }

        $scope = SubeScope::resolveScope($user, $request);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            $where[] = 'p.sube_id = :scope_sube_id';
            $params['scope_sube_id'] = $scope;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT p.id AS personel_id, CONCAT(p.ad, ' ', p.soyad) AS ad_soyad, p.sicil_no,
                   s.ad AS sube, d.ad AS bolum
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            WHERE $whereSql
            ORDER BY p.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::mapReportRow($tip, $row);
        }

        JsonResponse::success(
            ['items' => $items],
            [
                'page' => 1,
                'limit' => count($items),
                'total' => count($items),
                'total_pages' => 1,
            ]
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapReportRow($tip, array $row)
    {
        $base = [
            'personel_id' => (int) $row['personel_id'],
            'ad_soyad' => (string) $row['ad_soyad'],
            'sicil_no' => $row['sicil_no'],
            'sube' => $row['sube'],
            'bolum' => $row['bolum'],
        ];

        if ($tip === 'devamsizlik') {
            $base['devamsizlik_gun'] = 0;
        } elseif ($tip === 'personel-ozet') {
            $base['toplam_calisma_gunu'] = 0;
        }

        return $base;
    }
}
