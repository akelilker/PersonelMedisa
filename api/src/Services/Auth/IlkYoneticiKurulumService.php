<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Auth\PasswordHasher;
use Medisa\Api\Database\UsersSchema;
use PDO;

/**
 * Yeni şirket kurulumu: ilk Genel Yönetici hesabının tek sahibi (CLI: api/bin/ilk-yonetici-olustur.php).
 *
 * Kişiden/şirketten bağımsızdır: Medisa'ya özgü hesap, kullanıcı adı veya id gerektirmez.
 * Yalnız sistemde HİÇ Genel Yönetici yokken çalışır (boş/yeni kurulum); bir tane bile
 * varsa (aktif/pasif) hiçbir şey yazmadan reddeder. Eşzamanlı iki kurulum aynı anda
 * iki "ilk" yönetici oluşturamaz: advisory lock + transaction içinde GY satırları
 * `FOR UPDATE` ile okunur. Hesap `silinmesi_korunur = 1` ile işaretlenir (kolon varsa).
 * Parola loglanmaz, sonuçta dönmez.
 *
 * Gerçek-kişi kimliği (dinamik yetki K1): kurulum CLI'ı sunucuda operatör tarafından
 * çalıştırılır; bu kurulum beyanı ilk yöneticinin actor_identity kaydını VERIFIED
 * olarak oluşturur ve bağlar (audit: BOOTSTRAP_VERIFY). Böylece yeni kurulumda
 * K1 = ZORUNLU yazılır ve ilk yönetici kilitlenmeden yetki verebilir. Medisa canlıda
 * bu servis hiç çalışmaz (GY varken reddeder), mod orada UYARI kalır.
 */
final class IlkYoneticiKurulumService
{
    public const ROL = 'GENEL_YONETICI';
    public const LOCK_NAME = 'medisa_ilk_yonetici_kurulum';
    public const MIN_PASSWORD_LENGTH = 10;

    public const CODE_ALREADY = 'GENEL_YONETICI_ZATEN_VAR';
    public const CODE_VALIDATION = 'VALIDATION_ERROR';
    public const CODE_DUPLICATE = 'DUPLICATE_USERNAME';
    public const CODE_LOCK = 'KURULUM_KILIDI_ALINAMADI';

    /**
     * @return array{user_id: int, username: string, rol: string, silinmesi_korunur: bool}
     * @throws IlkYoneticiKurulumException
     */
    public static function olustur(PDO $pdo, string $username, string $adSoyad, string $password): array
    {
        $username = trim($username);
        $adSoyad = trim($adSoyad);
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            throw new IlkYoneticiKurulumException(self::CODE_VALIDATION, 'Kullanıcı adı 3-64 karakter olmalı (harf, rakam, . _ -).');
        }
        if ($adSoyad === '' || mb_strlen($adSoyad) > 150) {
            throw new IlkYoneticiKurulumException(self::CODE_VALIDATION, 'Ad soyad zorunludur.');
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new IlkYoneticiKurulumException(
                self::CODE_VALIDATION,
                'Parola en az ' . self::MIN_PASSWORD_LENGTH . ' karakter olmalıdır.'
            );
        }

        $lock = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute(['name' => self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new IlkYoneticiKurulumException(self::CODE_LOCK, 'Kurulum kilidi alınamadı; başka bir kurulum sürüyor.');
        }

        try {
            $pdo->beginTransaction();
            try {
                $gy = $pdo->query(
                    "SELECT id FROM users WHERE rol = '" . self::ROL . "' FOR UPDATE"
                )->fetchAll(PDO::FETCH_COLUMN);
                if (count($gy) > 0) {
                    throw new IlkYoneticiKurulumException(
                        self::CODE_ALREADY,
                        'Sistemde zaten Genel Yönetici var; ilk yönetici kurulumu yalnız boş kurulumda çalışır.'
                    );
                }
                $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
                $dup->execute(['u' => $username]);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new IlkYoneticiKurulumException(self::CODE_DUPLICATE, 'Bu kullanıcı adı zaten kayıtlı.');
                }

                $koruma = UsersSchema::hasSilinmesiKorunur($pdo);
                $cols = ['username', 'password_hash', 'ad_soyad', 'rol', 'durum'];
                $vals = [':username', ':password_hash', ':ad_soyad', "'" . self::ROL . "'", "'AKTIF'"];
                if ($koruma) {
                    $cols[] = 'silinmesi_korunur';
                    $vals[] = '1';
                }
                $insert = $pdo->prepare(
                    'INSERT INTO users (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')'
                );
                $insert->execute([
                    'username' => $username,
                    'password_hash' => PasswordHasher::hash($password),
                    'ad_soyad' => $adSoyad,
                ]);
                $id = (int) $pdo->lastInsertId();
                $kimlikId = self::kimlikOlustur($pdo, $id, $adSoyad);
                $k1Mod = self::tableExists($pdo, YetkiKimlikPolitikasi::TABLE)
                    && YetkiKimlikPolitikasi::yeniKurulumZorunluYaz($pdo, 'ILK_YONETICI_KURULUMU')
                    ? YetkiKimlikPolitikasi::MOD_ZORUNLU
                    : YetkiKimlikPolitikasi::MOD_UYARI;
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => self::LOCK_NAME]);
        }

        return [
            'user_id' => $id,
            'username' => $username,
            'rol' => self::ROL,
            'silinmesi_korunur' => $koruma,
            'actor_identity_id' => $kimlikId,
            'k1_kimlik_modu' => $k1Mod,
        ];
    }

    /** İlk yöneticinin gerçek-kişi kimliği: kurulum beyanıyla VERIFIED + bağlı. */
    private static function kimlikOlustur(PDO $pdo, int $userId, string $adSoyad): ?int
    {
        if (!self::tableExists($pdo, 'actor_identities')) {
            return null;
        }
        $displayName = (string) preg_replace('/\s+/u', ' ', $adSoyad);
        $pdo->prepare(
            "INSERT INTO actor_identities (identity_code, display_name, normalized_name, status, verification_source, personel_id)
             VALUES (:code, :display, :normalized, 'VERIFIED', 'HUMAN_CONFIRMED', NULL)"
        )->execute([
            'code' => 'USER-' . $userId,
            'display' => $displayName,
            'normalized' => mb_strtolower($displayName, 'UTF-8'),
        ]);
        $kimlikId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE users SET actor_identity_id = :k WHERE id = :id')->execute(['k' => $kimlikId, 'id' => $userId]);
        if (self::tableExists($pdo, 'actor_identity_audits')) {
            $pdo->prepare(
                "INSERT INTO actor_identity_audits (actor_identity_id, target_user_id, action, changed_by_user_id, details_json)
                 VALUES (:k, :u, 'BOOTSTRAP_VERIFY', :u2, :d)"
            )->execute([
                'k' => $kimlikId,
                'u' => $userId,
                'u2' => $userId,
                'd' => json_encode(['kaynak' => 'ILK_YONETICI_CLI', 'status' => 'VERIFIED'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $kimlikId;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
