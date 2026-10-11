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
 *  - Veren ile hedef aynı gerçek kişi (aynı actor_identity): ZORUNLU → ENGEL, UYARI → uyarı.
 */
final class YetkiKimlikPolitikasi
{
    public const TABLE = 'yetki_politikasi';
    public const ANAHTAR_K1 = 'K1_KIMLIK_MODU';
    public const MOD_UYARI = 'UYARI';
    public const MOD_ZORUNLU = 'ZORUNLU';
    public const BAG_TABLE = 'user_gercek_kisi_baglari';

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

        $veren = self::kimlik($pdo, $verenUserId);
        $hedef = self::kimlik($pdo, $hedefUserId);

        $ihlaller = [];
        if ($veren['id'] === null || $veren['status'] !== 'VERIFIED') {
            $ihlaller[] = self::CODE_KIMLIK_YOK;
        }
        if ($veren['id'] !== null && $hedef['id'] !== null && $veren['id'] === $hedef['id']) {
            $ihlaller[] = self::CODE_AYNI_KISI;
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
     * Hesabın gerçek kişisi: ek hesap bağı (user_gercek_kisi_baglari) varsa o kimlik,
     * yoksa users.actor_identity_id. Bağ tablosu yoksa (100 öncesi) yalnız users kolonu.
     *
     * @return array{id: int|null, status: string|null}
     */
    public static function kimlik(PDO $pdo, int $userId): array
    {
        $bagli = null;
        try {
            $stmt = $pdo->prepare('SELECT actor_identity_id FROM ' . self::BAG_TABLE . ' WHERE user_id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $v = $stmt->fetchColumn();
            $bagli = $v !== false && $v !== null ? (int) $v : null;
        } catch (\PDOException $e) {
            if (!\Medisa\Api\Database\UserYetkiIstisnaSchema::isMissingTable($e)) {
                throw $e;
            }
        }
        $stmt = $pdo->prepare(
            'SELECT COALESCE(:bagli, u.actor_identity_id) AS id, ai.status
               FROM users u
               LEFT JOIN actor_identities ai ON ai.id = COALESCE(:bagli2, u.actor_identity_id)
              WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['bagli' => $bagli, 'bagli2' => $bagli, 'id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['id'] === null) {
            return ['id' => null, 'status' => null];
        }

        return ['id' => (int) $row['id'], 'status' => $row['status'] !== null ? strtoupper((string) $row['status']) : null];
    }
}
