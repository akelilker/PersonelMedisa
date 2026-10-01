<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * PERSONEL read of own RAPOR (health) surecler — not IS_KAZASI.
 */
class SelfRaporReadService
{
    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public static function listForPersonel(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        $stmt = $pdo->prepare(
            "SELECT id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, state, aciklama
             FROM surecler
             WHERE personel_id = :pid
               AND surec_turu = 'RAPOR'
               AND (alt_tur IS NULL OR alt_tur = '' OR alt_tur = 'Raporlu_Hastalik')
               AND state <> 'IPTAL'
             ORDER BY baslangic_tarihi DESC, id DESC
             LIMIT 50"
        );
        $stmt->execute(['pid' => $personelId]);
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id' => (int) $row['id'],
                'surec_turu' => (string) $row['surec_turu'],
                'alt_tur' => $row['alt_tur'] !== null ? (string) $row['alt_tur'] : null,
                'baslangic_tarihi' => (string) $row['baslangic_tarihi'],
                'bitis_tarihi' => $row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null,
                'state' => (string) $row['state'],
                'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            ];
        }

        return ['items' => $items];
    }
}
