<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use PDO;

/**
 * K1 — kişiye özel yetki VERME işleminde gerçek-kişi kimliği (actor_identity) kuralı.
 *
 * Mod kurulum düzeyindedir (`yetki_politikasi.K1_KIMLIK_MODU`):
 *  - Satır/tablo yok → UYARI (Medisa canlı; kimse kilitlenmez, eksik kimlik uyarı olur).
 *  - Yeni kurulum (boş DB): ilk yönetici oluşturulurken ZORUNLU yazılır (ilk günden).
 *  - Medisa (mevcut kurulum): satır yok → UYARI. ZORUNLU'ya geçiş yalnız canlı salt
 *    okunur doğrulama + İlker'in açık onayı + ayrı PR ile; otomatik/zamanlı geçiş yok.
 *  - Not: ilk yöneticinin VERIFIED kimliği kurulumcu beyanıdır (BOOTSTRAP_VERIFY),
 *    bağımsız kimlik doğrulaması değildir.
 *
 * Kurallar (P3 yazma uçları bu değerlendirmeyi çağırır):
 *  - Kendine yetki verme: her modda ENGEL.
 *  - Veren hesabın doğrulanmış (VERIFIED) kimliği yok: ZORUNLU → ENGEL, UYARI → uyarı.
 *    Kimliği kesinleşmeyen eski hesap kilitlenmez ve başka bir kişiyle aynı sayılmaz.
 *  - Güvenilir mevcut bilgiyle aynı gerçek kişi (dolu T.C. + personel bağlantısı,
 *    veya doğrulanmış actor_identity köprüsü): kendi lehine yetki verme ve kısıt
 *    kaldırma dahil her yetki yazımı her modda ENGEL. İsim eşleştirmesi yok.
 *    T.C. değeri okunmaz, yazılmaz, mesaja veya denetime konmaz.
 */
final class YetkiKimlikPolitikasi
{
    public const TABLE = 'yetki_politikasi';
    public const ANAHTAR_K1 = 'K1_KIMLIK_MODU';
    public const MOD_UYARI = 'UYARI';
    public const MOD_ZORUNLU = 'ZORUNLU';

    public const CODE_KENDINE = 'YETKI_KENDINE_VERILEMEZ';
    public const CODE_KIMLIK_YOK = 'YETKI_VEREN_KIMLIK_DOGRULANMAMIS';
    public const CODE_AYNI_KISI = 'YETKI_AYNI_GERCEK_KISI';

    public static function mod(PDO $pdo): string
    {
        try {
            $stmt = $pdo->prepare('SELECT deger FROM ' . self::TABLE . ' WHERE anahtar = :a LIMIT 1');
            $stmt->execute(['a' => self::ANAHTAR_K1]);
            $deger = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (\Medisa\Api\Database\UserYetkiIstisnaSchema::isMissingTable($e)) {
                return self::MOD_UYARI;
            }
            throw $e;
        }

        return $deger === self::MOD_ZORUNLU ? self::MOD_ZORUNLU : self::MOD_UYARI;
    }

    /**
     * @return array{mod: string, izin: bool, engel: string|null, uyarilar: array<int, string>}
     */
    public static function degerlendir(PDO $pdo, int $verenUserId, int $hedefUserId): array
    {
        $mod = self::mod($pdo);
        $sonuc = ['mod' => $mod, 'izin' => true, 'engel' => null, 'uyarilar' => []];
        if ($verenUserId <= 0 || $verenUserId === $hedefUserId) {
            return ['mod' => $mod, 'izin' => false, 'engel' => self::CODE_KENDINE, 'uyarilar' => []];
        }

        if (self::ayniGuvenilirKisi($pdo, $verenUserId, $hedefUserId)) {
            return ['mod' => $mod, 'izin' => false, 'engel' => self::CODE_AYNI_KISI, 'uyarilar' => [self::CODE_AYNI_KISI]];
        }

        $veren = self::kimlik($pdo, $verenUserId);

        $ihlaller = [];
        if ($veren['id'] === null || $veren['status'] !== 'VERIFIED') {
            $ihlaller[] = self::CODE_KIMLIK_YOK;
        }
        if ($ihlaller === []) {
            return $sonuc;
        }
        if ($mod === self::MOD_ZORUNLU) {
            return ['mod' => $mod, 'izin' => false, 'engel' => $ihlaller[0], 'uyarilar' => $ihlaller];
        }
        $sonuc['uyarilar'] = $ihlaller;

        return $sonuc;
    }

    /**
     * Yalnız ilk-yönetici kurulumu çağırır (boş DB). Mevcut değeri ezmez.
     */
    public static function yeniKurulumZorunluYaz(PDO $pdo, string $kaynak): bool
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO ' . self::TABLE . ' (anahtar, deger, kaynak) VALUES (:a, :d, :k)'
        );
        $stmt->execute(['a' => self::ANAHTAR_K1, 'd' => self::MOD_ZORUNLU, 'k' => $kaynak]);

        return self::mod($pdo) === self::MOD_ZORUNLU;
    }

    /**
     * Hesabın gerçek kişisi: yalnız users.actor_identity_id (048, 1:1).
     * SGK dört göz bu bağa dayanır; ikinci hesap buraya yazılmaz.
     *
     * @return array{id: int|null, status: string|null}
     */
    public static function kimlik(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.actor_identity_id AS id, ai.status
               FROM users u
               LEFT JOIN actor_identities ai ON ai.id = u.actor_identity_id
              WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['id'] === null) {
            return ['id' => null, 'status' => null];
        }

        return ['id' => (int) $row['id'], 'status' => $row['status'] !== null ? strtoupper((string) $row['status']) : null];
    }

    /**
     * İki farklı hesap güvenilir mevcut bilgiyle aynı kişi mi?
     * Aynı kullanıcı, aynı isim veya doğrulanmamış kimlik eşleşme sayılmaz.
     * Eski hesap birleştirilmez ve kilitlenmez.
     */
    public static function ayniGuvenilirKisi(PDO $pdo, int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return false;
        }

        return self::ayniDogrulanmisKimlik($pdo, $a, $b)
            || self::ayniPersonelTc($pdo, $a, $b)
            || self::kimlikPersonelKoprusu($pdo, $a, $b)
            || self::kimlikPersonelKoprusu($pdo, $b, $a);
    }

    /**
     * Bu personel kaydıyla aynı kişiye ait mevcut kullanıcı.
     * T.C. seçilmez ve dönülmez. Pasif hesap varsa o öne alınır (yeni hesap açılmaz).
     *
     * @return array{id: int, username: string, durum: string, kaynak: string}|null
     */
    public static function mevcutHesap(PDO $pdo, int $personelId, ?int $haricUserId = null): ?array
    {
        if ($personelId <= 0) {
            return null;
        }
        $haric = $haricUserId !== null && $haricUserId > 0 ? $haricUserId : 0;
        $bulunan = [];
        $ortak = ['pid' => $personelId, 'haric' => $haric];
        foreach ([
            ['PERSONEL_KAYDI', 'SELECT u.id, u.username, u.durum FROM users u WHERE u.personel_id = :pid AND u.id <> :haric ORDER BY u.id LIMIT 1', $ortak],
            ['TC_ESLESME', 'SELECT u.id, u.username, u.durum
                FROM personeller hedef
                JOIN personeller diger ON diger.id <> hedef.id AND diger.tc_kimlik_no = hedef.tc_kimlik_no
                JOIN users u ON u.personel_id = diger.id AND u.id <> :haric
               WHERE hedef.id = :pid
                 AND hedef.tc_kimlik_no IS NOT NULL
                 AND CHAR_LENGTH(TRIM(hedef.tc_kimlik_no)) = 11
               ORDER BY u.id LIMIT 1', $ortak],
            ['KIMLIK', 'SELECT u.id, u.username, u.durum
                FROM actor_identities ai
                JOIN users u ON u.actor_identity_id = ai.id AND u.id <> :haric
               WHERE ai.personel_id = :pid
                 AND ai.status = \'VERIFIED\'
                 AND NOT (u.personel_id <=> :pid2)
               ORDER BY u.id LIMIT 1', $ortak + ['pid2' => $personelId]],
        ] as [$kaynak, $sql, $params]) {
            $row = self::hesapSatiri($pdo, $sql . self::forUpdate($pdo), $params);
            if ($row !== null) {
                $row['kaynak'] = $kaynak;
                $bulunan[] = $row;
            }
        }
        foreach ($bulunan as $row) {
            if (strtoupper($row['durum']) === 'PASIF') {
                return $row;
            }
        }

        return $bulunan[0] ?? null;
    }

    private static function ayniDogrulanmisKimlik(PDO $pdo, int $a, int $b): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1
               FROM users ua
               JOIN users ub ON ub.id = :b AND ub.actor_identity_id = ua.actor_identity_id
               JOIN actor_identities ai ON ai.id = ua.actor_identity_id AND ai.status = \'VERIFIED\'
              WHERE ua.id = :a AND ua.actor_identity_id IS NOT NULL
              LIMIT 1'
        );
        $stmt->execute(['a' => $a, 'b' => $b]);

        return (bool) $stmt->fetchColumn();
    }

    private static function ayniPersonelTc(PDO $pdo, int $a, int $b): bool
    {
        $row = self::hesapSatiri(
            $pdo,
            'SELECT 1 AS id, \'\' AS username, \'\' AS durum
               FROM users ua
               JOIN personeller pa ON pa.id = ua.personel_id
               JOIN users ub ON ub.id = :b
               JOIN personeller pb ON pb.id = ub.personel_id
              WHERE ua.id = :a
                AND pa.tc_kimlik_no IS NOT NULL
                AND CHAR_LENGTH(TRIM(pa.tc_kimlik_no)) = 11
                AND pa.tc_kimlik_no = pb.tc_kimlik_no
              LIMIT 1',
            ['a' => $a, 'b' => $b]
        );

        return $row !== null;
    }

    /** Doğrulanmış kimliğin personel köprüsü, diğer hesabın personel bağlantısıyla aynıysa. */
    private static function kimlikPersonelKoprusu(PDO $pdo, int $sahip, int $diger): bool
    {
        $row = self::hesapSatiri(
            $pdo,
            'SELECT 1 AS id, \'\' AS username, \'\' AS durum
               FROM users sahip
               JOIN actor_identities ai ON ai.id = sahip.actor_identity_id AND ai.status = \'VERIFIED\'
               JOIN users diger ON diger.id = :diger
              WHERE sahip.id = :sahip
                AND ai.personel_id IS NOT NULL
                AND ai.personel_id = diger.personel_id
              LIMIT 1',
            ['sahip' => $sahip, 'diger' => $diger]
        );

        return $row !== null;
    }

    /**
     * @param array<string, int> $params
     * @return array{id: int, username: string, durum: string}|null
     */
    private static function hesapSatiri(PDO $pdo, string $sql, array $params): ?array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            if (\Medisa\Api\Database\UserYetkiIstisnaSchema::isMissingTable($e)) {
                return null;
            }
            throw $e;
        }
        if (!$row || !isset($row['id'])) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) ($row['username'] ?? ''),
            'durum' => strtoupper((string) ($row['durum'] ?? '')),
        ];
    }

    private static function forUpdate(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
