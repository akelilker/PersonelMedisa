<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class YonetimController
{
    public static function subeler(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'yonetim-paneli.view',
            'aylik-ozet.view',
            'personeller.create',
            'personeller.update',
        ]);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->query(
            'SELECT s.id, s.kod, s.ad, s.durum, GROUP_CONCAT(sd.departman_id) AS departman_ids
             FROM subeler s
             LEFT JOIN sube_departmanlar sd ON sd.sube_id = s.id
             GROUP BY s.id, s.kod, s.ad, s.durum
             ORDER BY s.id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $departmanIds = [];
            if (!empty($row['departman_ids'])) {
                foreach (explode(',', (string) $row['departman_ids']) as $id) {
                    $departmanIds[] = (int) $id;
                }
            }
            $items[] = [
                'id' => (int) $row['id'],
                'kod' => (string) $row['kod'],
                'ad' => (string) $row['ad'],
                'durum' => (string) $row['durum'],
                'departman_ids' => $departmanIds,
                'departman_adlari' => [],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function aylikOzet(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik-ozet.view');

        $ay = trim((string) $request->getQuery('ay', date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            JsonResponse::badRequest('Gecersiz ay parametresi.', 'VALIDATION_ERROR', 'ay');
        }

        $subeId = (int) ($request->getQuery('sube_id', 0) ?: 0);
        $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
        $sadeceRevizeli = filter_var($request->getQuery('sadece_revizeli', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['ay = :ay'];
        $params = ['ay' => $ay];
        if ($subeId > 0) {
            $where[] = 'sube_id = :sube_id';
            $params['sube_id'] = $subeId;
        }
        if ($departmanId > 0) {
            $where[] = 'departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }
        if ($sadeceRevizeli) {
            $where[] = 'revize_var_mi = 1';
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT * FROM aylik_ozet_satirlari WHERE $whereSql ORDER BY personel_id ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $summary = [
            'toplam_personel' => 0,
            'toplam_devamsizlik_gun' => 0,
            'toplam_gec_kalma' => 0,
            'toplam_izinli_gelmedi' => 0,
            'toplam_izinsiz_gelmedi' => 0,
            'toplam_raporlu' => 0,
            'toplam_tesvik_tutari' => 0,
            'toplam_ceza_kesinti_tutari' => 0,
        ];
        $pending = 0;

        foreach ($rows as $row) {
            $item = [
                'personel_id' => (int) $row['personel_id'],
                'ad_soyad' => (string) $row['ad_soyad'],
                'sicil_no' => $row['sicil_no'],
                'sube' => (string) $row['sube'],
                'bolum' => (string) $row['bolum'],
                'bagli_amir_adi' => (string) ($row['bagli_amir_adi'] ?: '-'),
                'devamsizlik_gun' => (int) $row['devamsizlik_gun'],
                'gec_kalma_adet' => (int) $row['gec_kalma_adet'],
                'izinli_gelmedi' => (int) $row['izinli_gelmedi'],
                'izinsiz_gelmedi' => (int) $row['izinsiz_gelmedi'],
                'raporlu' => (int) $row['raporlu'],
                'tesvik_tutari' => (float) $row['tesvik_tutari'],
                'ceza_kesinti_tutari' => (float) $row['ceza_kesinti_tutari'],
                'bolum_onay_durumu' => (string) $row['bolum_onay_durumu'],
                'revize_var_mi' => (bool) $row['revize_var_mi'],
                'son_islem' => (string) ($row['son_islem'] ?: '-'),
                'kapanis_durumu' => (string) $row['kapanis_durumu'],
            ];
            $items[] = $item;

            $summary['toplam_personel']++;
            $summary['toplam_devamsizlik_gun'] += (int) $row['devamsizlik_gun'];
            $summary['toplam_gec_kalma'] += (int) $row['gec_kalma_adet'];
            $summary['toplam_izinli_gelmedi'] += (int) $row['izinli_gelmedi'];
            $summary['toplam_izinsiz_gelmedi'] += (int) $row['izinsiz_gelmedi'];
            $summary['toplam_raporlu'] += (int) $row['raporlu'];
            $summary['toplam_tesvik_tutari'] += (float) $row['tesvik_tutari'];
            $summary['toplam_ceza_kesinti_tutari'] += (float) $row['ceza_kesinti_tutari'];

            if ($row['bolum_onay_durumu'] === 'BOLUM_ONAYINDA') {
                $pending++;
            }
        }

        $stateStmt = $pdo->prepare('SELECT state FROM aylik_kapanis_state WHERE ay = :ay LIMIT 1');
        $stateStmt->execute(['ay' => $ay]);
        $stateRow = $stateStmt->fetch(PDO::FETCH_ASSOC);
        $state = $stateRow ? (string) $stateRow['state'] : 'BOLUM_ONAYINDA';

        JsonResponse::success([
            'ay' => $ay,
            'state' => $state,
            'summary' => $summary,
            'items' => $items,
            'pending_bolum_onayi' => $pending,
        ]);
    }
}
