<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use PDO;

/**
 * Eski (onboarding kuralı öncesi) mükerrer hesap raporu — SALT OKUMA (t900 B).
 *
 * Yeni hesaplarda aynı kişi engeli YetkiKimlikPolitikasi::mevcutHesap ile uygulanır;
 * bu rapor yalnız kural öncesi kalmış kayıtları listeler. Hiçbir satır değiştirilmez,
 * birleştirilmez veya kilitlenmez. T.C. kimlik numarası çıktıya konmaz.
 */
final class MukerrerHesapRaporuService
{
    /**
     * @return array{
     *   ready: bool,
     *   read_only: true,
     *   gruplar: array<int, array{kaynak: string, personel_ids: array<int, int>, hesaplar: array<int, array{id: int, username: string, durum: string, personel_id: int|null}>}>,
     *   totals: array<string, int>
     * }
     */
    public static function scan(PDO $pdo): array
    {
        $gruplar = [];
        foreach (self::personelKaydiGruplari($pdo) as $g) {
            $gruplar[] = $g;
        }
        foreach (self::tcGruplari($pdo) as $g) {
            $gruplar[] = $g;
        }
        foreach (self::kimlikGruplari($pdo) as $g) {
            $gruplar[] = $g;
        }

        $totals = ['grup' => count($gruplar), 'PERSONEL_KAYDI' => 0, 'TC_ESLESME' => 0, 'KIMLIK' => 0];
        foreach ($gruplar as $g) {
            $totals[$g['kaynak']] += 1;
        }

        return ['ready' => true, 'read_only' => true, 'gruplar' => $gruplar, 'totals' => $totals];
    }

    /** Aynı personel kaydına bağlı birden fazla kullanıcı. */
    private static function personelKaydiGruplari(PDO $pdo): array
    {
        $rows = self::rows(
            $pdo,
            'SELECT u.id, u.username, u.durum, u.personel_id
               FROM users u
               JOIN (SELECT personel_id FROM users WHERE personel_id IS NOT NULL
                      GROUP BY personel_id HAVING COUNT(*) > 1) d ON d.personel_id = u.personel_id
              ORDER BY u.personel_id, u.id'
        );

        return self::grupla($rows, 'PERSONEL_KAYDI', static fn (array $r): string => (string) $r['personel_id']);
    }

    /** Aynı T.C. ile birden fazla personel kaydı ve her birinde kullanıcı. T.C. çıktıya konmaz. */
    private static function tcGruplari(PDO $pdo): array
    {
        $rows = self::rows(
            $pdo,
            'SELECT u.id, u.username, u.durum, u.personel_id, MD5(p.tc_kimlik_no) AS grup_anahtari
               FROM personeller p
               JOIN users u ON u.personel_id = p.id
              WHERE p.tc_kimlik_no IS NOT NULL
                AND CHAR_LENGTH(TRIM(p.tc_kimlik_no)) = 11
                AND p.tc_kimlik_no IN (
                    SELECT p2.tc_kimlik_no
                      FROM personeller p2
                      JOIN users u2 ON u2.personel_id = p2.id
                     WHERE p2.tc_kimlik_no IS NOT NULL
                     GROUP BY p2.tc_kimlik_no
                    HAVING COUNT(DISTINCT p2.id) > 1
                )
              ORDER BY grup_anahtari, u.id'
        );

        return self::grupla($rows, 'TC_ESLESME', static fn (array $r): string => (string) $r['grup_anahtari']);
    }

    /** Doğrulanmış kimliğin personeli dışındaki bir kayda bağlı hesap. */
    private static function kimlikGruplari(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'actor_identities')) {
            return [];
        }
        $rows = self::rows(
            $pdo,
            'SELECT u.id, u.username, u.durum, u.personel_id, ai.personel_id AS kimlik_personel_id
               FROM actor_identities ai
               JOIN users u ON u.actor_identity_id = ai.id
              WHERE ai.status = \'VERIFIED\'
                AND ai.personel_id IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM users diger
                     WHERE diger.personel_id = ai.personel_id AND diger.id <> u.id
                )
                AND NOT (u.personel_id <=> ai.personel_id)
              ORDER BY ai.personel_id, u.id'
        );
        $gruplar = [];
        foreach ($rows as $r) {
            $pid = (int) $r['kimlik_personel_id'];
            $digerleri = self::rows(
                $pdo,
                'SELECT id, username, durum, personel_id FROM users WHERE personel_id = ' . $pid . ' ORDER BY id'
            );
            $gruplar[] = [
                'kaynak' => 'KIMLIK',
                'personel_ids' => array_values(array_unique(array_filter([$pid, $r['personel_id'] !== null ? (int) $r['personel_id'] : 0]))),
                'hesaplar' => array_map([self::class, 'hesap'], array_merge($digerleri, [$r])),
            ];
        }

        return $gruplar;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function grupla(array $rows, string $kaynak, callable $anahtar): array
    {
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$anahtar($r)][] = $r;
        }
        $out = [];
        foreach ($byKey as $grup) {
            $pids = [];
            foreach ($grup as $r) {
                if ($r['personel_id'] !== null) {
                    $pids[(int) $r['personel_id']] = true;
                }
            }
            $out[] = [
                'kaynak' => $kaynak,
                'personel_ids' => array_keys($pids),
                'hesaplar' => array_map([self::class, 'hesap'], $grup),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $r */
    private static function hesap(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'username' => (string) $r['username'],
            'durum' => (string) $r['durum'],
            'personel_id' => $r['personel_id'] !== null ? (int) $r['personel_id'] : null,
        ];
    }

    private static function rows(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);

        return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));

        return $stmt !== false && $stmt->fetch(PDO::FETCH_NUM) !== false;
    }
}
