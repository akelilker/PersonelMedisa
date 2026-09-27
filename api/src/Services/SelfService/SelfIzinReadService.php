<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use Medisa\Api\Services\Izin\YillikIzinBakiyeService;
use Medisa\Api\Services\Izin\YillikIzinHakDuzeltmeException;
use Medisa\Api\Services\Izin\YillikIzinKullanimService;
use PDO;

/**
 * PERSONEL self read of existing IZIN surecler. No parallel leave table.
 * personel_id is the bound self id only.
 */
class SelfIzinReadService
{
    /**
     * @return array<string, mixed>
     */
    public static function listForPersonel(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        $today = self::istanbulToday();
        $bakiye = null;
        try {
            $bakiye = YillikIzinBakiyeService::assemble($pdo, $personelId, $today);
        } catch (YillikIzinHakDuzeltmeException $e) {
            $bakiye = null;
        }

        $stmt = $pdo->prepare(
            "SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, aciklama, state
             FROM surecler
             WHERE personel_id = :pid
               AND surec_turu = 'IZIN'
             ORDER BY baslangic_tarihi DESC, id DESC"
        );
        $stmt->execute(['pid' => $personelId]);

        $aktif = null;
        $gecmis = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['personel_id'] !== $personelId) {
                continue;
            }
            $mapped = self::mapRow($pdo, $personelId, $row, $today);
            if ($mapped['aktif_mi'] && $aktif === null) {
                $aktif = $mapped;
                continue;
            }
            $gecmis[] = $mapped;
        }

        return [
            'personel_id' => $personelId,
            'bakiye' => $bakiye,
            'aktif' => $aktif,
            'gecmis' => $gecmis,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(PDO $pdo, $personelId, array $row, $today)
    {
        $bas = (string) $row['baslangic_tarihi'];
        $bit = $row['bitis_tarihi'] !== null && trim((string) $row['bitis_tarihi']) !== ''
            ? (string) $row['bitis_tarihi']
            : null;
        $state = strtoupper((string) $row['state']);
        $windowEnd = $bit !== null ? $bit : $bas;
        $aktif = !in_array($state, ['IPTAL', 'TAMAMLANDI'], true)
            && $bas <= $today
            && $windowEnd >= $today;
        $summary = YillikIzinKullanimService::summarizeWindow($pdo, $personelId, $bas, $windowEnd, $today);

        return [
            'id' => (int) $row['id'],
            'izin_turu' => $row['alt_tur'] !== null && trim((string) $row['alt_tur']) !== ''
                ? (string) $row['alt_tur']
                : 'IZIN',
            'baslangic' => $bas,
            'bitis' => $bit,
            'gun' => $summary['gun'],
            'aciklama' => $row['aciklama'] !== null && trim((string) $row['aciklama']) !== ''
                ? (string) $row['aciklama']
                : null,
            'durum' => $state,
            'aktif_mi' => $aktif,
            'ise_donus' => $aktif ? $summary['ise_donus_tarihi'] : null,
            'bitime_kalan_gun' => $aktif ? $summary['bitime_kalan_gun'] : null,
        ];
    }

    private static function istanbulToday()
    {
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        } catch (\Throwable $e) {
            return (new \DateTimeImmutable('now'))->format('Y-m-d');
        }
    }
}
