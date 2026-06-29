<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use PDO;

class ReferansController
{
    public static function departmanlar(Request $request)
    {
        self::listByTable($request, 'departmanlar');
    }

    public static function gorevler(Request $request)
    {
        self::listByTable($request, 'gorevler');
    }

    public static function personelTipleri(Request $request)
    {
        self::listByTable($request, 'personel_tipleri');
    }

    public static function bagliAmirler(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->query(
            "SELECT id, ad_soyad
             FROM users
             WHERE durum = 'AKTIF'
               AND rol IN ('GENEL_YONETICI', 'BOLUM_YONETICISI', 'BIRIM_AMIRI', 'MUHASEBE')
             ORDER BY ad_soyad ASC, id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad_soyad'],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function surecTurleri(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success([
            'items' => [
                ['key' => 'IZIN', 'label' => 'İzin'],
                ['key' => 'RAPOR', 'label' => 'Rapor'],
                ['key' => 'IS_KAZASI', 'label' => 'İş Kazası'],
                ['key' => 'DEVAMSIZLIK', 'label' => 'Devamsızlık'],
                ['key' => 'ISTEN_AYRILMA', 'label' => 'İşten Ayrılma'],
                ['key' => 'GOREV_DEGISIKLIGI', 'label' => 'Görev Değişikliği'],
                ['key' => 'UCRET_DEGISIKLIGI', 'label' => 'Ücret Değişikliği'],
            ],
        ]);
    }

    public static function ucretTipleri(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success([
            'items' => [
                ['id' => 1, 'ad' => 'Aylık'],
                ['id' => 2, 'ad' => 'Günlük'],
                ['id' => 3, 'ad' => 'Saatlik'],
            ],
        ]);
    }

    public static function primKurallari(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success([
            'items' => [
                ['id' => 1, 'ad' => 'Devamsızlık Primi Yok'],
                ['id' => 2, 'ad' => 'Tam Prim'],
                ['id' => 3, 'ad' => 'Kısmi Prim'],
            ],
        ]);
    }

    private static function listByTable(Request $request, $table)
    {
        AuthMiddleware::authenticate($request, true);

        $allowed = ['departmanlar', 'gorevler', 'personel_tipleri'];
        if (!in_array($table, $allowed, true)) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->query("SELECT id, ad FROM $table WHERE durum = 'AKTIF' ORDER BY ad ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad'],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }
}
