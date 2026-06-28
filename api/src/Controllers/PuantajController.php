<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class PuantajController
{
    public static function detail(Request $request, $personelId, $tarih)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $personelId = (int) $personelId;
        $tarih = rawurldecode((string) $tarih);

        if ($personelId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
            JsonResponse::badRequest('Gecersiz puantaj parametreleri.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $personelStmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
        $personelStmt->execute(['id' => $personelId]);
        $personel = $personelStmt->fetch(PDO::FETCH_ASSOC);
        if (!$personel) {
            JsonResponse::notFound('Personel bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        $stmt = $pdo->prepare(
            'SELECT * FROM gunluk_puantaj WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1'
        );
        $stmt->execute([
            'personel_id' => $personelId,
            'tarih' => $tarih,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            JsonResponse::success(null);
        }

        JsonResponse::success(self::mapRow($row));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapRow(array $row)
    {
        return [
            'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'],
            'gun_tipi' => $row['gun_tipi'],
            'hareket_durumu' => $row['hareket_durumu'],
            'dayanak' => $row['dayanak'],
            'hesap_etkisi' => $row['hesap_etkisi'],
            'giris_saati' => $row['giris_saati'],
            'cikis_saati' => $row['cikis_saati'],
            'kontrol_durumu' => $row['kontrol_durumu'] ?: 'BEKLIYOR',
            'compliance_uyarilari' => [],
        ];
    }
}
