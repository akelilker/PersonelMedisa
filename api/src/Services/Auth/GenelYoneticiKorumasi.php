<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use PDO;

/**
 * Yönetici hesap korumalarının tek sahibi (Kullanıcı Yönetimi yazma yolları).
 *
 *  - GENEL_YONETICI rolünü yalnız GENEL_YONETICI verir / geri alır; GENEL_YONETICI
 *    hesabının durumunu ve erişimini de yalnız GENEL_YONETICI değiştirir
 *    (SISTEM_YONETICISI vb. yetki yükseltemez).
 *  - Kullanıcı kendi rolünü veya durumunu değiştiremez.
 *  - Son aktif GENEL_YONETICI rolünden düşürülemez, pasife alınamaz, erişimi
 *    kaldırılamaz. Sayım, yazmayla aynı transaction içinde aktif GENEL_YONETICI
 *    satırları `FOR UPDATE` ile kilitlenerek yapılır; eşzamanlı iki istek son
 *    yöneticiyi birlikte kaldıramaz (ikinci istek kilidi bekler, güncel sayıyı görür).
 *
 * Kişi/kullanıcı adı/id istisnası yoktur; kurallar yalnız role ve duruma bakar.
 */
final class GenelYoneticiKorumasi
{
    public const ROL = 'GENEL_YONETICI';

    public const CODE_ESCALATION = 'ROLE_ESCALATION_FORBIDDEN';
    public const CODE_SELF_ROLE = 'SELF_ROLE_CHANGE_FORBIDDEN';
    public const CODE_SELF_STATUS = 'SELF_STATUS_CHANGE_FORBIDDEN';
    public const CODE_LAST_ADMIN = 'LAST_ADMIN_PROTECTED';

    /**
     * Rol/durum değişikliğinin aktör yetkisi (DB'siz, transaction öncesi).
     *
     * @param array<string, mixed> $actor oturum kullanıcısı
     * @param int|null $targetId mevcut hedef (yeni kayıtta null)
     * @param string|null $currentRol mevcut rol (yeni kayıtta null)
     * @param string|null $currentDurum mevcut durum (yeni kayıtta null)
     */
    public static function assertActorMayAssign(
        array $actor,
        ?int $targetId,
        ?string $currentRol,
        ?string $currentDurum,
        string $newRol,
        string $newDurum
    ): void {
        $actorId = isset($actor['id']) ? (int) $actor['id'] : 0;
        $actorRol = self::normalize($actor['rol'] ?? '');
        $currentRol = $currentRol === null ? null : self::normalize($currentRol);
        $currentDurum = $currentDurum === null ? null : self::normalize($currentDurum);
        $newRol = self::normalize($newRol);
        $newDurum = self::normalize($newDurum);

        $rolDegisiyor = $currentRol !== $newRol;
        $durumDegisiyor = $currentDurum !== null && $currentDurum !== $newDurum;

        if ($targetId !== null && $actorId > 0 && $actorId === $targetId) {
            if ($rolDegisiyor) {
                throw new OrganizasyonException(409, self::CODE_SELF_ROLE, 'Kendi rolünüzü değiştiremezsiniz.', 'rol');
            }
            if ($durumDegisiyor) {
                throw new OrganizasyonException(409, self::CODE_SELF_STATUS, 'Kendi hesabınızın durumunu değiştiremezsiniz.', 'durum');
            }
        }

        if ($actorRol === self::ROL) {
            return;
        }
        $verilenGy = $newRol === self::ROL && $rolDegisiyor;
        $alinanGy = $currentRol === self::ROL && $rolDegisiyor;
        $gyDurumu = $currentRol === self::ROL && $durumDegisiyor;
        if ($verilenGy || $alinanGy || $gyDurumu) {
            throw new OrganizasyonException(
                403,
                self::CODE_ESCALATION,
                'Genel Yönetici rolünü ve Genel Yönetici hesaplarının durumunu yalnız Genel Yönetici değiştirebilir.',
                $gyDurumu && !$verilenGy && !$alinanGy ? 'durum' : 'rol'
            );
        }
    }

    /**
     * Erişim kaldırma (hesabı pasife alma) için aktör yetkisi.
     *
     * @param array<string, mixed> $actor
     */
    public static function assertActorMayRevoke(array $actor, string $targetRol): void
    {
        if (self::normalize($targetRol) === self::ROL && self::normalize($actor['rol'] ?? '') !== self::ROL) {
            throw new OrganizasyonException(
                403,
                self::CODE_ESCALATION,
                'Genel Yönetici hesabının erişimini yalnız Genel Yönetici kaldırabilir.',
                'id'
            );
        }
    }

    /**
     * Açık transaction içinde çağrılır. Hedef bugün aktif GENEL_YONETICI ise ve yazma
     * sonrası öyle kalmayacaksa, aktif GENEL_YONETICI satırlarını kilitler ve
     * hedef dışında en az bir aktif GENEL_YONETICI kalmasını şart koşar.
     */
    public static function assertNotLastActiveAdminLocked(
        PDO $pdo,
        int $targetId,
        string $newRol,
        string $newDurum
    ): void {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Son yönetici kontrolü transaction içinde çalışmalıdır.');
        }
        if (self::normalize($newRol) === self::ROL && self::normalize($newDurum) === 'AKTIF') {
            return;
        }

        $stmt = $pdo->query(
            "SELECT id FROM users WHERE rol = 'GENEL_YONETICI' AND durum = 'AKTIF' ORDER BY id FOR UPDATE"
        );
        $aktifIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array($targetId, $aktifIds, true)) {
            return;
        }
        if (count($aktifIds) <= 1) {
            throw new OrganizasyonException(
                409,
                self::CODE_LAST_ADMIN,
                'Sistemdeki son aktif Genel Yönetici rolünden düşürülemez, pasife alınamaz veya erişimi kaldırılamaz. Önce başka bir Genel Yönetici tanımlayın.',
                'rol'
            );
        }
    }

    /** @param mixed $value */
    private static function normalize($value): string
    {
        return strtoupper(trim((string) $value));
    }
}
