<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use PDO;

/**
 * Archive access checks + append-only access audit (VIEW/DOWNLOAD/LIST).
 */
class ArchiveAccessService
{
    public const ACTION_VIEW = 'VIEW';
    public const ACTION_DOWNLOAD = 'DOWNLOAD';
    public const ACTION_LIST = 'LIST';

    /**
     * @param array<string, mixed> $user
     */
    public static function canAccessArchive(array $user)
    {
        return RolePermissions::has($user, 'arsiv.view');
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function canDownloadArchive(array $user)
    {
        return RolePermissions::has($user, 'arsiv.download');
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function assertPasifAccess(array $user)
    {
        if (!self::canAccessArchive($user)) {
            JsonResponse::error(
                403,
                'ARCHIVE_ACCESS_REQUIRED',
                'Pasif personel arsiv erisimi icin arsiv.view yetkisi gerekir.'
            );
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $metadata
     */
    public static function writeAccessAudit(
        PDO $pdo,
        array $user,
        $action,
        $targetType,
        $targetId,
        $personelId,
        $routeSource,
        $metadata = null
    ) {
        if (!self::tableExists($pdo, 'arsiv_erisim_auditleri')) {
            return;
        }

        $action = strtoupper(trim((string) $action));
        if (!in_array($action, [self::ACTION_VIEW, self::ACTION_DOWNLOAD, self::ACTION_LIST], true)) {
            return;
        }

        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId <= 0) {
            return;
        }

        $personelId = $personelId !== null ? (int) $personelId : null;
        if ($personelId !== null && $personelId <= 0) {
            $personelId = null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO arsiv_erisim_auditleri
                (actor_user_id, target_type, target_id, personel_id, action, route_source, metadata_json)
             VALUES
                (:actor, :target_type, :target_id, :personel_id, :action, :route_source, :metadata)'
        );
        $stmt->execute([
            'actor' => $actorId,
            'target_type' => (string) $targetType,
            'target_id' => (int) $targetId,
            'personel_id' => $personelId,
            'action' => $action,
            'route_source' => (string) $routeSource,
            'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }
}
