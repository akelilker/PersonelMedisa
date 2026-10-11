<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\EffectivePermissionResolver;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Database\UserYetkiIstisnaSchema;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use PDO;

/**
 * Kullanıcı bazlı yetki — OKUMA uçları (dinamik yetki P2). P2'de yazma ucu YOKTUR (P3).
 * Şube kapsamlı istisnalar kapalıdır; yanıt `sube_istisnalari_etkin: false` taşır.
 *
 *  GET /auth/yetkiler                         → oturum kullanıcısının etkin izinleri + kaynakları
 *  GET /yonetim/kullanicilar/{id}/yetkiler    → kullanici_yetkileri.view
 *  GET /yonetim/yetki-auditleri               → kullanici_yetkileri.audit.view (yalnız GENEL_YONETICI)
 */
final class KullaniciYetkiController
{
    public const PERMISSION_VIEW = 'kullanici_yetkileri.view';
    public const PERMISSION_AUDIT_VIEW = 'kullanici_yetkileri.audit.view';

    public static function benimYetkilerim(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $kaynaklar = EffectivePermissionResolver::effectivePermissionSources($user);
        JsonResponse::success([
            'user_id' => (int) $user['id'],
            'rol' => (string) ($user['rol'] ?? ''),
            'effective_permissions' => array_keys($kaynaklar),
            'kaynaklar' => $kaynaklar,
            'istisnalar' => array_values($user['yetki_istisnalari'] ?? []),
            'sube_istisnalari_etkin' => EffectivePermissionResolver::subeIstisnalariEtkin(),
        ]);
    }

    public static function kullaniciYetkileri(Request $request, $kullaniciId)
    {
        $actor = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($actor, self::PERMISSION_VIEW);
        $kullaniciId = (int) $kullaniciId;
        $pdo = Connection::get();

        $target = self::loadTarget($pdo, $kullaniciId);
        if ($target === null) {
            JsonResponse::notFound('Kullanici bulunamadi.');
        }
        $target['yetki_istisnalari'] = UserYetkiIstisnaSchema::loadActive($pdo, $kullaniciId);
        $history = UserYetkiIstisnaSchema::loadHistory($pdo, $kullaniciId);
        $rol = RolePermissions::normalizeRole((string) $target['rol']);
        $kaynaklar = EffectivePermissionResolver::effectivePermissionSources($target);
        $varsayilan = RolePermissions::roleDefaultPermissions($rol);
        sort($varsayilan, SORT_STRING);

        JsonResponse::success([
            'user_id' => $kullaniciId,
            'rol' => $rol !== '' ? $rol : (string) $target['rol'],
            'schema_ready' => $history['schema_ready'],
            'rol_varsayilanlari' => $varsayilan,
            'istisnalar' => $history['rows'],
            'effective_permissions' => array_keys($kaynaklar),
            'kaynaklar' => $kaynaklar,
            'sube_istisnalari_etkin' => EffectivePermissionResolver::subeIstisnalariEtkin(),
        ]);
    }

    public static function yetkiAuditleri(Request $request)
    {
        $actor = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($actor, self::PERMISSION_AUDIT_VIEW);
        $hedef = isset($_GET['kullanici_id']) ? (int) $_GET['kullanici_id'] : null;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
        $audit = UserYetkiIstisnaSchema::loadAudit(Connection::get(), $hedef, $limit);
        JsonResponse::success([
            'schema_ready' => $audit['schema_ready'],
            'items' => $audit['rows'],
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function loadTarget(PDO $pdo, int $kullaniciId): ?array
    {
        if ($kullaniciId <= 0) {
            return null;
        }
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $stmt = $pdo->prepare(
            'SELECT id, rol' . ($hasPersonelId ? ', personel_id' : '') . ' FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $kullaniciId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $personelId = $hasPersonelId && $row['personel_id'] !== null && (int) $row['personel_id'] > 0
            ? (int) $row['personel_id']
            : null;
        $collar = $personelId !== null
            ? SelfPersonelContext::loadCollar($pdo, $personelId)
            : ['personel_tipi_id' => null, 'personel_tipi_ad' => null];

        return [
            'id' => (int) $row['id'],
            'rol' => (string) $row['rol'],
            'personel_id' => $personelId,
            'personel_tipi_ad' => $collar['personel_tipi_ad'],
        ];
    }
}
