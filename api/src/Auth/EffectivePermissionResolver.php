<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

/**
 * Etkin izin çözücüsü — kullanıcının bir izne sahip olup olmadığının TEK sahibi.
 *
 * Bugünkü kaynaklar (sırayla; davranış PR öncesi RolePermissions::has ile birebir):
 *  1. QR self-service: rolden bağımsız; bağlı personel + kanonik "Mavi Yaka".
 *  2. Personele bağlı hesap: self-service temel izinleri (PERSONEL matrisi).
 *  3. Rol varsayılanı (kanonik rol; bilinmeyen/eski rol → fail-closed).
 *
 * İleride kişiye özel ALLOW/DENY istisnaları yalnız bu sınıfa eklenir; çağrı
 * noktaları (RolePermissions::has/assert/assertAny) değişmez.
 */
final class EffectivePermissionResolver
{
    public const SOURCE_QR_SELF_SERVICE = 'QR_SELF_SERVICE';
    public const SOURCE_SELF_SERVICE_BASELINE = 'SELF_SERVICE_BASELINE';
    public const SOURCE_ROLE_DEFAULT = 'ROLE_DEFAULT';

    /**
     * @param array<string, mixed> $user
     * @param mixed $permission
     */
    public static function resolve(array $user, $permission): bool
    {
        return self::source($user, $permission) !== null;
    }

    /**
     * İzni veren kaynak; izin yoksa null. QR izinleri yalnız QR kuralıyla karar
     * verir (rol ya da self-service temeli genişletemez).
     *
     * @param array<string, mixed> $user
     * @param mixed $permission
     */
    public static function source(array $user, $permission): ?string
    {
        $permission = trim((string) $permission);
        if ($permission === '') {
            return null;
        }

        if (RolePermissions::isQrSelfServicePermission($permission)) {
            return RolePermissions::hasQrSelfServiceEntitlement($user) ? self::SOURCE_QR_SELF_SERVICE : null;
        }

        if (
            RolePermissions::hasPersonnelLinkedSelfServiceEligibility($user)
            && in_array($permission, RolePermissions::selfServiceBaselinePermissions(), true)
        ) {
            return self::SOURCE_SELF_SERVICE_BASELINE;
        }

        $role = RolePermissions::normalizeRole(isset($user['rol']) ? (string) $user['rol'] : '');
        if ($role === '') {
            return null;
        }

        return in_array($permission, RolePermissions::roleDefaultPermissions($role), true)
            ? self::SOURCE_ROLE_DEFAULT
            : null;
    }

    /**
     * Kullanıcının katalogdaki tüm etkin izinleri (sıralı).
     *
     * @param array<string, mixed> $user
     * @return array<int, string>
     */
    public static function effectivePermissions(array $user): array
    {
        $result = [];
        foreach (RolePermissions::permissionCatalog() as $permission) {
            if (self::resolve($user, $permission)) {
                $result[] = $permission;
            }
        }

        return $result;
    }
}
