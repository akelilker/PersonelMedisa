<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Scope\OrgScope;

/**
 * Etkin izin çözücüsü — kullanıcının bir izne sahip olup olmadığının TEK sahibi.
 *
 * Karar sırası:
 *  1. QR self-service: rolden bağımsız; bağlı personel + kanonik "Mavi Yaka".
 *  2. Personele bağlı hesap: self-service temel izinleri (PERSONEL matrisi).
 *     (1 ve 2 kişiye özel istisnalardan etkilenmez.)
 *  3. Kanonik rol yoksa (bilinmeyen/eski rol) → RED (istisnalar da uygulanmaz).
 *  4. Geçerli bir DENY → RED. DENY her zaman ALLOW'u ve rol varsayılanını yener;
 *     global DENY (sube_id NULL) tüm şubeleri kapatır.
 *  5. Rol varsayılanı → KABUL.
 *  6. Geçerli bir ALLOW → KABUL. ALLOW kapsamı genişletmez: şubeye özel ALLOW yalnız
 *     o şube bağlamında ve şube kullanıcının mevcut org kapsamındaysa geçerlidir.
 *
 * Şube kapsamlı istisnalar şu an KAPALI (SUBE_ISTISNALARI_ETKIN = false). Açıldığında
 * semantik (iki karar türü):
 *  - Şube bağlamlı karar (resolveForSube / RolePermissions::hasForSube): şube DENY
 *    yalnız o şubeyi kapatır; şube ALLOW yalnız o şubede ve şube kullanıcının org
 *    kapsamındaysa izin verir.
 *  - Şubesiz karar (resolve / has — bugünkü 232 çağrı yeri): şube ALLOW izin VERMEZ,
 *    şube DENY ENGELLEMEZ. Şube kısıtı, kaydın şubesi bilinen kayıt düzeyi kontrolde
 *    (hasForSube) uygulanır; çağrı yerlerinin şube bağlamına geçişi P3'tedir.
 *    (Şubesiz kararda şube DENY'ı her yere yaymak Kayseri kısıtını tüm şubelere
 *    taşırdı; yok saymak kapsamı genişletmez çünkü ALLOW da yok sayılır.)
 *
 * Geçerlilik (başlangıç/bitiş, UTC) değerlendirme anında kontrol edilir.
 * İstisnalar `$user['yetki_istisnalari']` içinden okunur (AuthMiddleware tek sorguyla
 * yükler). Liste yoksa/boşsa sonuç PR #528 ile birebir aynıdır.
 */
final class EffectivePermissionResolver
{
    public const SOURCE_QR_SELF_SERVICE = 'QR_SELF_SERVICE';
    public const SOURCE_SELF_SERVICE_BASELINE = 'SELF_SERVICE_BASELINE';
    public const SOURCE_ROLE_DEFAULT = 'ROLE_DEFAULT';
    public const SOURCE_USER_ALLOW = 'USER_ALLOW';

    /**
     * ALLOW ile verilemeyen izinler: yalnız rol varsayılanıyla gelir. Çözücü bu
     * izinlerdeki ALLOW kayıtlarını yok sayar (yazma kuralı P3'te ayrıca reddeder).
     */
    public const NON_GRANTABLE_PERMISSIONS = [
        'kullanicilar.kalici_sil',
        'yonetim-paneli.manage',
        'kullanici_yetkileri.view',
        'kullanici_yetkileri.manage',
        'kullanici_yetkileri.audit.view',
        'sgk_karar_paketi.approve',
        'bordro_kesinlestirme.approve',
        'genel_yonetici_onayi.approve',
        'legal_hold.manage',
        'retention.destruction.request',
        'retention.destruction.approve',
        'retention.destruction.execute',
        'retention.destruction.view',
    ];

    /**
     * GENEL_YONETICI'de DENY ile kapatılamayan sistem hakları (son yönetici /
     * yetki yönetimi sürekliliği). Bu izinlerde GY hedefli DENY yok sayılır.
     */
    public const GY_NON_DENYABLE_PERMISSIONS = [
        'kullanicilar.kalici_sil',
        'yonetim-paneli.view',
        'yonetim-paneli.manage',
        'kullanici_yetkileri.view',
        'kullanici_yetkileri.manage',
        'kullanici_yetkileri.audit.view',
    ];

    /**
     * Şube kapsamlı istisnalar (sube_id dolu) ilgili ekranlar şube bağlamına geçene
     * kadar KAPALI: kapalıyken bu satırlar hiçbir kararda kullanılmaz (ne izin verir
     * ne engeller). P2'de istisna yazan uç/CLI/seed yoktur; P3 yazma ucu da yalnız
     * global istisna kabul eder. Açılış ayrı onaylı PR ile bu sabitin değişmesidir.
     */
    public const SUBE_ISTISNALARI_ETKIN = false;

    /** @var bool|null yalnız testler: şube semantiğini doğrulamak için geçici açma */
    private static $subeIstisnaOverride = null;

    /** @var string|null test saati (UTC 'Y-m-d H:i:s') */
    private static $nowOverride = null;

    /**
     * @param array<string, mixed> $user
     * @param mixed $permission
     */
    public static function resolve(array $user, $permission): bool
    {
        return self::source($user, $permission) !== null;
    }

    /**
     * Şube bağlamında karar (şubeye özel DENY/ALLOW için).
     *
     * @param array<string, mixed> $user
     * @param mixed $permission
     */
    public static function resolveForSube(array $user, $permission, int $subeId): bool
    {
        return self::source($user, $permission, $subeId) !== null;
    }

    /**
     * İzni veren kaynak; izin yoksa null.
     *
     * @param array<string, mixed> $user
     * @param mixed $permission
     */
    public static function source(array $user, $permission, ?int $subeId = null): ?string
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

        $istisnalar = self::validExceptions($user, $permission);
        if ($istisnalar !== [] && self::isDenied($istisnalar, $role, $permission, $subeId)) {
            return null;
        }

        if (in_array($permission, RolePermissions::roleDefaultPermissions($role), true)) {
            return self::SOURCE_ROLE_DEFAULT;
        }

        if ($istisnalar !== [] && self::isAllowed($istisnalar, $user, $permission, $subeId)) {
            return self::SOURCE_USER_ALLOW;
        }

        return null;
    }

    /**
     * Kullanıcının katalogdaki tüm etkin izinleri (sıralı, şube bağlamı yok).
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

    /**
     * Katalogdaki her etkin izin → kaynağı.
     *
     * @param array<string, mixed> $user
     * @return array<string, string>
     */
    public static function effectivePermissionSources(array $user): array
    {
        $result = [];
        foreach (RolePermissions::permissionCatalog() as $permission) {
            $source = self::source($user, $permission);
            if ($source !== null) {
                $result[$permission] = $source;
            }
        }

        return $result;
    }

    /** Yalnız testler: değerlendirme saatini sabitler (UTC 'Y-m-d H:i:s'); null = gerçek saat. */
    public static function setNowForTests(?string $nowUtc): void
    {
        self::$nowOverride = $nowUtc;
    }

    /** Yalnız testler: şube kapsamlı istisna semantiğini açar/kapatır; null = sabit. */
    public static function setSubeIstisnalariForTests(?bool $etkin): void
    {
        self::$subeIstisnaOverride = $etkin;
    }

    public static function subeIstisnalariEtkin(): bool
    {
        return self::$subeIstisnaOverride ?? self::SUBE_ISTISNALARI_ETKIN;
    }

    private static function nowUtc(): string
    {
        return self::$nowOverride ?? gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private static function validExceptions(array $user, string $permission): array
    {
        $rows = $user['yetki_istisnalari'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        $now = self::nowUtc();
        $valid = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['permission'] ?? '')) !== $permission) {
                continue;
            }
            $etki = strtoupper((string) ($row['etki'] ?? ''));
            if ($etki !== 'ALLOW' && $etki !== 'DENY') {
                continue;
            }
            $baslangic = (string) ($row['gecerlilik_baslangic'] ?? '');
            $bitis = $row['gecerlilik_bitis'] ?? null;
            if ($baslangic === '' || strcmp($baslangic, $now) > 0) {
                continue;
            }
            if ($bitis !== null && $bitis !== '' && strcmp((string) $bitis, $now) <= 0) {
                continue;
            }
            if (isset($row['sube_id']) && $row['sube_id'] !== null && $row['sube_id'] !== '' && !self::subeIstisnalariEtkin()) {
                continue; // şube kapsamlı istisnalar kapalı (bkz. SUBE_ISTISNALARI_ETKIN)
            }
            $row['etki'] = $etki;
            $valid[] = $row;
        }

        return $valid;
    }

    /** @param array<int, array<string, mixed>> $istisnalar */
    private static function isDenied(array $istisnalar, string $role, string $permission, ?int $subeId): bool
    {
        if ($role === 'GENEL_YONETICI' && in_array($permission, self::GY_NON_DENYABLE_PERMISSIONS, true)) {
            return false;
        }
        foreach ($istisnalar as $row) {
            if ($row['etki'] !== 'DENY') {
                continue;
            }
            $rowSube = isset($row['sube_id']) && $row['sube_id'] !== null ? (int) $row['sube_id'] : null;
            // Global DENY her şubeyi kapatır; şube DENY yalnız şube bağlamı aynıysa.
            if ($rowSube === null || ($subeId !== null && $rowSube === $subeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $istisnalar
     * @param array<string, mixed> $user
     */
    private static function isAllowed(array $istisnalar, array $user, string $permission, ?int $subeId): bool
    {
        if (in_array($permission, self::NON_GRANTABLE_PERMISSIONS, true)) {
            return false;
        }
        foreach ($istisnalar as $row) {
            if ($row['etki'] !== 'ALLOW') {
                continue;
            }
            $rowSube = isset($row['sube_id']) && $row['sube_id'] !== null ? (int) $row['sube_id'] : null;
            if ($rowSube === null) {
                // Global ALLOW izni verir; kapsam OrgScope/HrWriteScope ile ayrıca daraltılır.
                return true;
            }
            if ($subeId !== null && $rowSube === $subeId && self::subeWithinScope($user, $subeId)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $user */
    private static function subeWithinScope(array $user, int $subeId): bool
    {
        if (OrgScope::isUnrestricted($user) || OrgScope::isOrganizationGlobalRead($user)) {
            return true;
        }
        if (in_array($subeId, OrgScope::allowedSubeIds($user), true)) {
            return true;
        }
        $write = isset($user['write_sube_ids']) && is_array($user['write_sube_ids']) ? $user['write_sube_ids'] : [];

        return in_array($subeId, array_map('intval', $write), true);
    }
}
