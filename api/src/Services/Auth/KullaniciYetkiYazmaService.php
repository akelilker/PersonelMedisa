<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Auth\EffectivePermissionResolver;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\UserYetkiIstisnaSchema;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\SelfService\PersonelInboxNotificationService;
use PDO;

/**
 * Kullanıcı bazlı yetki istisnası YAZMA kuralları (dinamik yetki P3).
 *
 * Sıra: yetki (kullanici_yetkileri.manage) → hedef ≠ aktör → girdi (yalnız GLOBAL istisna;
 * şube kapsamı kapalı) → hedef satırı FOR UPDATE (Kalıcı Sil ile yarış yok) → kırmızı liste →
 * K1 (YetkiKimlikPolitikasi; Medisa UYARI) → K3 (atayan / uygun başka GY) → yaz + audit →
 * yetenek tabanlı son yetki yöneticisi → commit → K5 (yalnız hedef GY ise diğer GY'lere bildirim).
 *
 * Tüm GY'ler eşit yetki yöneticisidir; kısıtı kimin koyduğu önemli değildir (v3, K2′ yok).
 * Verilen iznin aktörde olması aranmaz (karar 2). Kayıtlar silinmez, yalnız bir kez iptal edilir.
 */
final class KullaniciYetkiYazmaService
{
    public const PERMISSION_MANAGE = 'kullanici_yetkileri.manage';
    public const ROL_GY = 'GENEL_YONETICI';
    public const MAX_SURE_GUN = 365;
    public const BILDIRIM_KIND = 'GY_YETKI_DEGISIKLIGI';

    public const CODE_KENDI = 'KENDI_YETKISI_DEGISTIRILEMEZ';
    public const CODE_SUBE_KAPALI = 'SUBE_ISTISNASI_KAPALI';
    public const CODE_VERILEMEZ = 'YETKI_ALLOW_ILE_VERILEMEZ';
    public const CODE_KISITLANAMAZ = 'YETKI_GY_ICIN_KISITLANAMAZ';
    public const CODE_SURE = 'YETKI_SURESI_GECERSIZ';
    public const CODE_ZATEN = 'YETKI_ISTISNASI_ZATEN_VAR';
    public const CODE_GEREKSIZ = 'YETKI_ZATEN_ROLDE_VAR';
    public const CODE_ATAYAN = 'ATAYAN_YETKI_VEREMEZ';
    public const CODE_SON_YONETICI = 'SON_YETKI_YONETICISI';
    public const CODE_SEMA = 'YETKI_SEMASI_HAZIR_DEGIL';
    public const UYARI_K3 = 'K3_ISTISNA_TEK_YONETICI';

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $girdi permission, etki, gerekce, gecerlilik_baslangic?, gecerlilik_bitis?, sube_id?
     * @return array{istisna_id: int, audit_id: int, uyarilar: array<int, string>, bildirim: int}
     */
    public static function ver(PDO $pdo, array $actor, int $hedefId, array $girdi, ?string $requestId = null): array
    {
        $aktorId = self::assertAktor($actor, $hedefId);
        $permission = trim((string) ($girdi['permission'] ?? ''));
        if ($permission === '' || !in_array($permission, RolePermissions::permissionCatalog(), true)) {
            throw new OrganizasyonException(422, 'VALIDATION_ERROR', 'Bilinmeyen yetki.', 'permission');
        }
        $etki = strtoupper(trim((string) ($girdi['etki'] ?? '')));
        if ($etki !== 'ALLOW' && $etki !== 'DENY') {
            throw new OrganizasyonException(422, 'VALIDATION_ERROR', 'Etki ALLOW veya DENY olmalıdır.', 'etki');
        }
        if (array_key_exists('sube_id', $girdi) && $girdi['sube_id'] !== null && $girdi['sube_id'] !== '') {
            throw new OrganizasyonException(422, self::CODE_SUBE_KAPALI, 'Şube kapsamlı yetki istisnası henüz kapalıdır; yalnız tüm şubeleri kapsayan istisna verilebilir.', 'sube_id');
        }
        $gerekce = self::gerekce($girdi);
        [$baslangic, $bitis] = self::sure($girdi, $etki);

        self::assertSema($pdo);
        $pdo->beginTransaction();
        try {
            // Kilit sırası #523 ile aynı: önce aktif GY satırları, sonra hedef (kilitlenme döngüsü olmaz).
            self::aktifGyler($pdo, true);
            $hedef = self::lockHedef($pdo, $hedefId);
            $hedefRol = RolePermissions::normalizeRole((string) $hedef['rol']);
            $hedefGy = $hedefRol === self::ROL_GY;

            if ($etki === 'ALLOW' && in_array($permission, EffectivePermissionResolver::NON_GRANTABLE_PERMISSIONS, true)) {
                throw new OrganizasyonException(422, self::CODE_VERILEMEZ, 'Bu yetki kişiye özel olarak verilemez (yalnız rol ile).', 'permission');
            }
            if ($etki === 'DENY' && $hedefGy && in_array($permission, EffectivePermissionResolver::GY_NON_DENYABLE_PERMISSIONS, true)) {
                throw new OrganizasyonException(422, self::CODE_KISITLANAMAZ, 'Genel Yönetici için bu sistem yetkisi kısıtlanamaz.', 'permission');
            }
            if ($etki === 'ALLOW' && in_array($permission, RolePermissions::roleDefaultPermissions($hedefRol), true)) {
                throw new OrganizasyonException(409, self::CODE_GEREKSIZ, 'Bu yetki kullanıcının rolünde zaten var.', 'permission');
            }
            foreach (UserYetkiIstisnaSchema::loadActive($pdo, $hedefId) as $row) {
                if ((string) $row['permission'] === $permission && strtoupper((string) $row['etki']) === $etki) {
                    throw new OrganizasyonException(409, self::CODE_ZATEN, 'Bu kullanıcıda aynı yetki istisnası zaten etkin.', 'permission');
                }
            }

            $uyarilar = self::kurallar($pdo, $aktorId, $hedefId, $hedefGy, $etki === 'ALLOW');

            $aktor = self::userRow($pdo, $aktorId);
            $stmt = $pdo->prepare(
                'INSERT INTO ' . UserYetkiIstisnaSchema::TABLE . ' (user_id, hedef_username_snapshot, permission, etki, sube_id,
                    gecerlilik_baslangic, gecerlilik_bitis, veren_user_id, veren_username_snapshot, veren_actor_identity_id,
                    hedef_rol_snapshot, gerekce)
                 VALUES (:u, :hu, :p, :e, NULL, :b, :bt, :v, :vu, :vk, :hr, :g)'
            );
            $stmt->execute([
                'u' => $hedefId, 'hu' => (string) $hedef['username'], 'p' => $permission, 'e' => $etki,
                'b' => $baslangic, 'bt' => $bitis, 'v' => $aktorId, 'vu' => (string) $aktor['username'],
                'vk' => $aktor['actor_identity_id'], 'hr' => $hedefRol, 'g' => $gerekce,
            ]);
            $istisnaId = (int) $pdo->lastInsertId();
            $auditId = self::audit($pdo, 'VER', $aktor, $hedef, $hedefRol, [
                'istisna_id' => $istisnaId, 'permission' => $permission, 'etki' => $etki,
                'gecerlilik_baslangic' => $baslangic, 'gecerlilik_bitis' => $bitis,
                'onceki_json' => null,
                'sonraki_json' => ['permission' => $permission, 'etki' => $etki, 'kapsam' => 'GLOBAL', 'gecerlilik_bitis' => $bitis],
                'gerekce' => $gerekce, 'uyarilar' => $uyarilar, 'request_id' => $requestId,
            ]);
            self::assertYetkiYoneticisiKalir($pdo);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $bildirim = $hedefGy ? self::bildir($pdo, $aktorId, $hedef, 'VER', $permission, $etki, $auditId) : 0;

        return ['istisna_id' => $istisnaId, 'audit_id' => $auditId, 'uyarilar' => $uyarilar, 'bildirim' => $bildirim];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array{istisna_id: int, audit_id: int, uyarilar: array<int, string>, bildirim: int}
     */
    public static function iptal(PDO $pdo, array $actor, int $hedefId, int $istisnaId, $gerekceRaw, ?string $requestId = null): array
    {
        $aktorId = self::assertAktor($actor, $hedefId);
        $gerekce = self::gerekce(['gerekce' => $gerekceRaw]);
        self::assertSema($pdo);
        $pdo->beginTransaction();
        try {
            // Kilit sırası #523 ile aynı: önce aktif GY satırları, sonra hedef (kilitlenme döngüsü olmaz).
            self::aktifGyler($pdo, true);
            $hedef = self::lockHedef($pdo, $hedefId);
            $hedefRol = RolePermissions::normalizeRole((string) $hedef['rol']);
            $hedefGy = $hedefRol === self::ROL_GY;
            $stmt = $pdo->prepare(
                'SELECT id, permission, etki, sube_id, gecerlilik_baslangic, gecerlilik_bitis, iptal_edildi_at
                   FROM ' . UserYetkiIstisnaSchema::TABLE . ' WHERE id = :id AND user_id = :u FOR UPDATE'
            );
            $stmt->execute(['id' => $istisnaId, 'u' => $hedefId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new OrganizasyonException(404, 'NOT_FOUND', 'Yetki istisnası bulunamadı.', 'istisna_id');
            }
            if ($row['iptal_edildi_at'] !== null) {
                throw new OrganizasyonException(409, 'YETKI_ISTISNASI_ZATEN_IPTAL', 'Bu yetki istisnası zaten iptal edilmiş.', 'istisna_id');
            }
            $etki = strtoupper((string) $row['etki']);
            // DENY kaldırmak yetkiyi genişletir: K3 bu durumda da uygulanır.
            $uyarilar = self::kurallar($pdo, $aktorId, $hedefId, $hedefGy, $etki === 'DENY');

            $aktor = self::userRow($pdo, $aktorId);
            $pdo->prepare(
                'UPDATE ' . UserYetkiIstisnaSchema::TABLE . "
                    SET iptal_edildi_at = UTC_TIMESTAMP(), iptal_eden_user_id = :a, iptal_eden_username_snapshot = :au,
                        iptal_nedeni = 'MANUEL'
                  WHERE id = :id"
            )->execute(['a' => $aktorId, 'au' => (string) $aktor['username'], 'id' => $istisnaId]);
            $auditId = self::audit($pdo, 'KALDIR', $aktor, $hedef, $hedefRol, [
                'istisna_id' => $istisnaId, 'permission' => (string) $row['permission'], 'etki' => $etki,
                'gecerlilik_baslangic' => $row['gecerlilik_baslangic'], 'gecerlilik_bitis' => $row['gecerlilik_bitis'],
                'onceki_json' => ['permission' => (string) $row['permission'], 'etki' => $etki, 'aktif' => true],
                'sonraki_json' => ['aktif' => false, 'iptal_nedeni' => 'MANUEL'],
                'gerekce' => $gerekce, 'uyarilar' => $uyarilar, 'request_id' => $requestId,
            ]);
            self::assertYetkiYoneticisiKalir($pdo);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $bildirim = $hedefGy ? self::bildir($pdo, $aktorId, $hedef, 'KALDIR', (string) $row['permission'], $etki, $auditId) : 0;

        return ['istisna_id' => $istisnaId, 'audit_id' => $auditId, 'uyarilar' => $uyarilar, 'bildirim' => $bildirim];
    }

    /**
     * Karar 8: rolü değişen kullanıcının etkin istisnaları AYNI transaction içinde iptal edilir
     * (ROL_DEGISTI) ve her biri audit'lenir. GY'ye atama ise atayan PROFIL_DEGISTI ile kaydedilir (K3).
     * Migration 100 uygulanmamışsa sessizce 0 döner (davranış değişmez).
     *
     * @param array<string, mixed> $actor
     */
    public static function rolDegisti(PDO $pdo, array $actor, int $hedefId, string $eskiRol, string $yeniRol): int
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Rol değişikliği istisna iptali transaction içinde çalışmalıdır.');
        }
        $eski = RolePermissions::normalizeRole($eskiRol);
        $yeni = RolePermissions::normalizeRole($yeniRol);
        if ($eski === $yeni && $eski !== '') {
            return 0;
        }
        try {
            $pdo->query('SELECT 1 FROM ' . UserYetkiIstisnaSchema::TABLE . ' LIMIT 0');
        } catch (\PDOException $e) {
            if (UserYetkiIstisnaSchema::isMissingTable($e)) {
                return 0; // migration 100 uygulanmamış: davranış değişmez
            }
            throw $e;
        }
        $stmt = $pdo->prepare(
            'SELECT id, permission, etki, gecerlilik_baslangic, gecerlilik_bitis FROM ' . UserYetkiIstisnaSchema::TABLE
            . ' WHERE user_id = :u AND iptal_edildi_at IS NULL ORDER BY id' . self::forUpdate($pdo)
        );
        $stmt->execute(['u' => $hedefId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $aktorId = (int) ($actor['id'] ?? 0);
        $aktor = self::userRow($pdo, $aktorId) ?? ['id' => $aktorId, 'username' => (string) ($actor['username'] ?? ''), 'actor_identity_id' => null];
        $hedef = self::userRow($pdo, $hedefId) ?? ['id' => $hedefId, 'username' => ''];
        $upd = $pdo->prepare(
            'UPDATE ' . UserYetkiIstisnaSchema::TABLE . "
                SET iptal_edildi_at = UTC_TIMESTAMP(), iptal_eden_user_id = :a, iptal_eden_username_snapshot = :au,
                    iptal_nedeni = 'ROL_DEGISTI'
              WHERE id = :id"
        );
        foreach ($rows as $row) {
            $upd->execute(['a' => $aktorId, 'au' => (string) $aktor['username'], 'id' => (int) $row['id']]);
            self::audit($pdo, 'ROL_DEGISTI_IPTAL', $aktor, $hedef, $yeni, [
                'istisna_id' => (int) $row['id'], 'permission' => (string) $row['permission'], 'etki' => (string) $row['etki'],
                'gecerlilik_baslangic' => $row['gecerlilik_baslangic'], 'gecerlilik_bitis' => $row['gecerlilik_bitis'],
                'onceki_json' => ['rol' => $eski, 'aktif' => true],
                'sonraki_json' => ['rol' => $yeni, 'aktif' => false, 'iptal_nedeni' => 'ROL_DEGISTI'],
                'gerekce' => 'Rol değişikliği: ' . $eski . ' → ' . $yeni, 'uyarilar' => [], 'request_id' => null,
            ]);
        }
        if ($yeni === self::ROL_GY && $eski !== self::ROL_GY) {
            self::audit($pdo, 'PROFIL_DEGISTI', $aktor, $hedef, $yeni, [
                'istisna_id' => null, 'permission' => null, 'etki' => null,
                'gecerlilik_baslangic' => null, 'gecerlilik_bitis' => null,
                'onceki_json' => ['rol' => $eski !== '' ? $eski : null],
                'sonraki_json' => ['rol' => $yeni, 'gy_atamasi' => true],
                'gerekce' => 'Genel Yönetici ataması', 'uyarilar' => [], 'request_id' => null,
            ]);
        }

        return count($rows);
    }

    // ---- kurallar ----------------------------------------------------------------------

    /** @return array<int, string> uyarılar */
    private static function kurallar(PDO $pdo, int $aktorId, int $hedefId, bool $hedefGy, bool $genisletir): array
    {
        $k1 = YetkiKimlikPolitikasi::degerlendir($pdo, $aktorId, $hedefId);
        if (!$k1['izin']) {
            $kod = (string) $k1['engel'];
            $mesaj = $kod === YetkiKimlikPolitikasi::CODE_KENDINE
                ? 'Kendi yetkinizi değiştiremezsiniz.'
                : ($kod === YetkiKimlikPolitikasi::CODE_AYNI_KISI
                    ? 'Aynı gerçek kişiye ait başka bir hesabın yetkisini değiştiremezsiniz.'
                    : 'Yetki değiştirebilmek için hesabınızın doğrulanmış gerçek kişi kimliği olmalıdır.');
            throw new OrganizasyonException(403, $kod, $mesaj, 'id');
        }
        $uyarilar = $k1['uyarilar'];
        if ($hedefGy && $genisletir) {
            $atayan = self::sonGyAtayani($pdo, $hedefId);
            if ($atayan !== null && $atayan === $aktorId) {
                if (self::uygunBaskaGyVar($pdo, $aktorId, $hedefId, $k1['mod'])) {
                    throw new OrganizasyonException(403, self::CODE_ATAYAN, 'Bu Genel Yöneticiyi siz atadınız; ek yetkiyi başka bir aktif Genel Yönetici vermelidir.', 'id');
                }
                $uyarilar[] = self::UYARI_K3;
            }
        }

        return array_values(array_unique($uyarilar));
    }

    /** Hedefi en son GENEL_YONETICI yapan kullanıcı (yetki audit'i, yoksa 082 erişim audit'i). */
    private static function sonGyAtayani(PDO $pdo, int $hedefId): ?int
    {
        $stmt = $pdo->prepare(
            "SELECT aktor_user_id FROM " . UserYetkiIstisnaSchema::AUDIT_TABLE . "
              WHERE hedef_user_id = :h AND aksiyon = 'PROFIL_DEGISTI' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['h' => $hedefId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT actor_user_id FROM user_erisim_degisiklik_auditleri
                  WHERE target_user_id = :h AND yeni_rol = 'GENEL_YONETICI' ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(['h' => $hedefId]);
            $id = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (UserYetkiIstisnaSchema::isMissingTable($e)) {
                return null;
            }
            throw $e;
        }

        return $id !== false ? (int) $id : null;
    }

    /** Uygun başka GY: aktif GY, yetki yöneticisi, atayan ve hedef dışında; ZORUNLU'da kimliği atayanla aynı değil. */
    private static function uygunBaskaGyVar(PDO $pdo, int $atayanId, int $hedefId, string $mod): bool
    {
        $atayanKimlik = self::userRow($pdo, $atayanId)['actor_identity_id'] ?? null;
        foreach (self::aktifGyler($pdo, false) as $gy) {
            if ($gy['id'] === $atayanId || $gy['id'] === $hedefId) {
                continue;
            }
            if ($mod === YetkiKimlikPolitikasi::MOD_ZORUNLU && $atayanKimlik !== null && $gy['actor_identity_id'] === $atayanKimlik) {
                continue;
            }
            if (self::yetkiYoneticisi($pdo, $gy)) {
                return true;
            }
        }

        return false;
    }

    /** Yetenek tabanlı son yönetici: işlem sonrası en az bir aktif GY yetki ve kullanıcı yönetimini etkin taşır. */
    private static function assertYetkiYoneticisiKalir(PDO $pdo): void
    {
        foreach (self::aktifGyler($pdo, false) as $gy) {
            if (self::yetkiYoneticisi($pdo, $gy)) {
                return;
            }
        }
        throw new OrganizasyonException(409, self::CODE_SON_YONETICI, 'Bu işlem sistemde yetki yönetebilen aktif Genel Yönetici bırakmaz.', 'id');
    }

    /** @param array{id: int, rol: string} $gy */
    private static function yetkiYoneticisi(PDO $pdo, array $gy): bool
    {
        $user = ['id' => $gy['id'], 'rol' => $gy['rol'], 'yetki_istisnalari' => UserYetkiIstisnaSchema::loadActive($pdo, $gy['id'])];

        return EffectivePermissionResolver::resolve($user, self::PERMISSION_MANAGE)
            && EffectivePermissionResolver::resolve($user, 'yonetim-paneli.manage');
    }

    /** @return array<int, array{id: int, rol: string, username: string, actor_identity_id: int|null}> */
    private static function aktifGyler(PDO $pdo, bool $kilitle): array
    {
        $stmt = $pdo->query(
            "SELECT id, rol, username, actor_identity_id FROM users WHERE rol = 'GENEL_YONETICI' AND durum = 'AKTIF' ORDER BY id"
            . ($kilitle ? ' FOR UPDATE' : '')
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'rol' => (string) $r['rol'], 'username' => (string) $r['username'],
                'actor_identity_id' => $r['actor_identity_id'] !== null ? (int) $r['actor_identity_id'] : null,
            ];
        }

        return $out;
    }

    // ---- yardımcılar ------------------------------------------------------------------

    private static function forUpdate(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    /** @param array<string, mixed> $actor */
    private static function assertAktor(array $actor, int $hedefId): int
    {
        if (!RolePermissions::has($actor, self::PERMISSION_MANAGE)) {
            throw new OrganizasyonException(403, 'FORBIDDEN', 'Bu işlem için yetkiniz yok.', null);
        }
        $aktorId = (int) ($actor['id'] ?? 0);
        if ($hedefId <= 0) {
            throw new OrganizasyonException(422, 'VALIDATION_ERROR', 'Geçersiz kullanıcı id.', 'id');
        }
        if ($aktorId <= 0 || $aktorId === $hedefId) {
            throw new OrganizasyonException(403, self::CODE_KENDI, 'Kendi yetkinizi veya kendi üzerinizdeki kısıtı değiştiremezsiniz.', 'id');
        }

        return $aktorId;
    }

    /** @param array<string, mixed> $girdi */
    private static function gerekce(array $girdi): string
    {
        $g = is_string($girdi['gerekce'] ?? null) ? trim($girdi['gerekce']) : '';
        if ($g === '') {
            throw new OrganizasyonException(422, 'VALIDATION_ERROR', 'Gerekçe zorunludur.', 'gerekce');
        }
        if (mb_strlen($g) > 500) {
            throw new OrganizasyonException(422, 'VALIDATION_ERROR', 'Gerekçe en fazla 500 karakter olabilir.', 'gerekce');
        }

        return $g;
    }

    /**
     * Karar 5: bitiş NULL = kalıcı; doluysa > başlangıç ve süreli ALLOW için ≤ başlangıç + 365 gün.
     *
     * @param array<string, mixed> $girdi
     * @return array{0: string, 1: string|null} UTC 'Y-m-d H:i:s'
     */
    private static function sure(array $girdi, string $etki): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $baslangic = self::tarih($girdi['gecerlilik_baslangic'] ?? null, 'gecerlilik_baslangic') ?? $now;
        if (strcmp($baslangic, $now) < 0) {
            $baslangic = $now; // geçmişe dönük istisna yok
        }
        $bitis = self::tarih($girdi['gecerlilik_bitis'] ?? null, 'gecerlilik_bitis');
        if ($bitis !== null) {
            if (strcmp($bitis, $baslangic) <= 0) {
                throw new OrganizasyonException(422, self::CODE_SURE, 'Bitiş tarihi başlangıçtan sonra olmalıdır.', 'gecerlilik_bitis');
            }
            $limit = gmdate('Y-m-d H:i:s', strtotime($baslangic . ' UTC') + self::MAX_SURE_GUN * 86400);
            if ($etki === 'ALLOW' && strcmp($bitis, $limit) > 0) {
                throw new OrganizasyonException(422, self::CODE_SURE, 'Süreli yetki en fazla 365 gün olabilir; süresiz için bitiş boş bırakılır.', 'gecerlilik_bitis');
            }
        }

        return [$baslangic, $bitis];
    }

    /** @param mixed $v */
    private static function tarih($v, string $alan): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $ts = is_string($v) ? strtotime($v . (preg_match('/(Z|[+-]\d\d:?\d\d)$/', $v) ? '' : ' UTC')) : false;
        if ($ts === false) {
            throw new OrganizasyonException(422, 'VALIDATION_ERROR', 'Geçersiz tarih.', $alan);
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function assertSema(PDO $pdo): void
    {
        try {
            $pdo->query('SELECT 1 FROM ' . UserYetkiIstisnaSchema::TABLE . ' LIMIT 0');
        } catch (\PDOException $e) {
            if (UserYetkiIstisnaSchema::isMissingTable($e)) {
                throw new OrganizasyonException(503, self::CODE_SEMA, 'Yetki istisnası şeması henüz kurulmadı (migration 100).', null);
            }
            throw $e;
        }
    }

    /** Hedef satırı FOR UPDATE: Kalıcı Sil aynı satırı kilitler, yarış oluşmaz. @return array<string, mixed> */
    private static function lockHedef(PDO $pdo, int $hedefId): array
    {
        $stmt = $pdo->prepare('SELECT id, username, rol, durum, actor_identity_id FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $hedefId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new OrganizasyonException(404, 'NOT_FOUND', 'Kullanıcı bulunamadı.', 'id');
        }

        return $row;
    }

    /** @return array{id: int, username: string, actor_identity_id: int|null}|null */
    private static function userRow(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT id, username, actor_identity_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }

        return ['id' => (int) $r['id'], 'username' => (string) $r['username'],
            'actor_identity_id' => $r['actor_identity_id'] !== null ? (int) $r['actor_identity_id'] : null];
    }

    /**
     * @param array<string, mixed> $aktor
     * @param array<string, mixed> $hedef
     * @param array<string, mixed> $d
     */
    private static function audit(PDO $pdo, string $aksiyon, array $aktor, array $hedef, string $hedefRol, array $d): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . UserYetkiIstisnaSchema::AUDIT_TABLE . ' (aksiyon, aktor_user_id, aktor_username_snapshot,
                aktor_actor_identity_id, hedef_user_id, hedef_username_snapshot, hedef_rol, istisna_id, permission, etki,
                sube_id, gecerlilik_baslangic, gecerlilik_bitis, onceki_json, sonraki_json, gerekce, uyari_kodlari, request_id)
             VALUES (:a, :au, :ausr, :ak, :h, :hu, :hr, :i, :p, :e, NULL, :b, :bt, :o, :s, :g, :uy, :r)'
        );
        $stmt->execute([
            'a' => $aksiyon, 'au' => (int) $aktor['id'], 'ausr' => (string) $aktor['username'],
            'ak' => $aktor['actor_identity_id'] ?? null, 'h' => (int) $hedef['id'], 'hu' => (string) $hedef['username'],
            'hr' => $hedefRol !== '' ? $hedefRol : '-', 'i' => $d['istisna_id'], 'p' => $d['permission'], 'e' => $d['etki'],
            'b' => $d['gecerlilik_baslangic'], 'bt' => $d['gecerlilik_bitis'],
            'o' => $d['onceki_json'] !== null ? json_encode($d['onceki_json'], JSON_UNESCAPED_UNICODE) : null,
            's' => $d['sonraki_json'] !== null ? json_encode($d['sonraki_json'], JSON_UNESCAPED_UNICODE) : null,
            'g' => $d['gerekce'], 'uy' => $d['uyarilar'] !== [] ? implode(',', $d['uyarilar']) : null,
            'r' => $d['request_id'] !== null ? substr((string) $d['request_id'], 0, 64) : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * K5: hedef GY ise diğer aktif GY'lere (aktör ve hedef hariç) gelen kutusu bildirimi.
     * Commit sonrası; yazılamazsa işlem geri alınmaz, loglanır.
     *
     * @param array<string, mixed> $hedef
     */
    private static function bildir(PDO $pdo, int $aktorId, array $hedef, string $aksiyon, string $permission, string $etki, int $auditId): int
    {
        $sayi = 0;
        try {
            $aktorAdi = (string) (self::userRow($pdo, $aktorId)['username'] ?? '');
            $baslik = 'Genel Yönetici yetkisi değişti';
            $metin = sprintf(
                '%s, %s hesabında %s yetkisi için %s %s.',
                $aktorAdi, (string) $hedef['username'], $permission,
                $etki === 'ALLOW' ? 'ek izin' : 'kısıt',
                $aksiyon === 'VER' ? 'tanımladı' : 'kaldırdı'
            );
            foreach (self::aktifGyler($pdo, false) as $gy) {
                if ($gy['id'] === $aktorId || $gy['id'] === (int) $hedef['id']) {
                    continue;
                }
                PersonelInboxNotificationService::create(
                    $pdo, $gy['id'], self::BILDIRIM_KIND, $baslik, $metin, null, null,
                    ['yetki_audit_id' => $auditId, 'hedef_user_id' => (int) $hedef['id'], 'aksiyon' => $aksiyon],
                    false
                );
                $sayi++;
            }
        } catch (\Throwable $e) {
            error_log('[yetki-k5] bildirim yazilamadi: ' . $e->getMessage());
        }

        return $sayi;
    }
}
