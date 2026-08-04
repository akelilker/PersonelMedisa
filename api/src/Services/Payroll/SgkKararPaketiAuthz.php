<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use Medisa\Api\Auth\RolePermissions;
use PDO;
use RuntimeException;

/**
 * S98 least-privilege SGK karar paketi prepare/approve authz (PHP 7.4-safe).
 * Actor always comes from authenticated session — never from request payload.
 */
final class SgkKararPaketiAuthz
{
    public const PERM_PREPARE = 'sgk_karar_paketi.prepare';
    public const PERM_APPROVE = 'sgk_karar_paketi.approve';

    /** @var list<string> */
    private static $genericUsernames = [
        'genel_yonetici',
        'muhasebe',
        'birim_amiri',
        'bolum_yoneticisi',
        'patron',
    ];

    /**
     * @param array<string,mixed> $actor
     */
    public static function assertPrepare(array $actor): void
    {
        self::assertActorActive($actor);
        self::assertPermission($actor, self::PERM_PREPARE, 'SGK_PREPARE_FORBIDDEN');
        self::assertFormalActorReady($actor);
    }

    /**
     * @param array<string,mixed> $actor
     */
    public static function assertApprove(array $actor): void
    {
        self::assertActorActive($actor);
        self::assertPermission($actor, self::PERM_APPROVE, 'SGK_APPROVE_FORBIDDEN');
        self::assertFormalActorReady($actor);
    }

    /**
     * @param array<string,mixed> $actor
     */
    public static function assertSubeScope(array $actor, $subeId): void
    {
        $subeId = (int) $subeId;
        if ($subeId <= 0) {
            return;
        }
        $subeIds = isset($actor['sube_ids']) && is_array($actor['sube_ids']) ? $actor['sube_ids'] : [];
        if ($subeIds === []) {
            return;
        }
        $allowed = [];
        foreach ($subeIds as $id) {
            $allowed[] = (int) $id;
        }
        if (!in_array($subeId, $allowed, true)) {
            throw new RuntimeException('SGK_ACTOR_SCOPE_FORBIDDEN');
        }
    }

    /**
     * @param array<string,mixed> $actor
     * @return array{ok: bool, code?: string, message?: string}
     */
    public static function denySelfApproval(array $actor, $hazirlayanId)
    {
        $actorId = (int) ($actor['id'] ?? 0);
        $hazirlayanId = (int) $hazirlayanId;
        if ($hazirlayanId > 0 && $actorId > 0 && $hazirlayanId === $actorId) {
            return [
                'ok' => false,
                'code' => 'SGK_SELF_APPROVAL_FORBIDDEN',
                'message' => 'Hazirlayan kendi kaydini onaylayamaz.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * Same real person via users.personel_id when column and both links exist.
     *
     * @param array<string,mixed> $actor
     * @return array{ok: bool, code?: string, message?: string, link_supported?: bool}
     */
    public static function denySamePerson(PDO $pdo, array $actor, $hazirlayanId)
    {
        $hazirlayanId = (int) $hazirlayanId;
        $actorId = (int) ($actor['id'] ?? 0);
        if ($hazirlayanId <= 0 || $actorId <= 0 || $hazirlayanId === $actorId) {
            return ['ok' => true, 'link_supported' => self::personelLinkSupported($pdo)];
        }

        if (!self::personelLinkSupported($pdo)) {
            return ['ok' => true, 'link_supported' => false];
        }

        $actorPersonel = self::resolvePersonelId($pdo, $actorId, $actor);
        $hazirlayanPersonel = self::resolvePersonelId($pdo, $hazirlayanId, null);
        if ($actorPersonel === null || $hazirlayanPersonel === null) {
            return ['ok' => true, 'link_supported' => true];
        }
        if ($actorPersonel === $hazirlayanPersonel) {
            return [
                'ok' => false,
                'code' => 'SGK_SAME_PERSON_DUAL_CONTROL_FORBIDDEN',
                'message' => 'Ayni gercek kisi dual-control icin kullanilamaz.',
                'link_supported' => true,
            ];
        }

        return ['ok' => true, 'link_supported' => true];
    }

    public static function personelLinkSupported(PDO $pdo): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'personel_id'");
            $cached = $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (\Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    /**
     * @param array<string,mixed>|null $actorHint
     * @return int|null
     */
    private static function resolvePersonelId(PDO $pdo, $userId, $actorHint)
    {
        $userId = (int) $userId;
        if (is_array($actorHint) && array_key_exists('personel_id', $actorHint) && $actorHint['personel_id'] !== null && $actorHint['personel_id'] !== '') {
            $pid = (int) $actorHint['personel_id'];

            return $pid > 0 ? $pid : null;
        }
        if (!self::personelLinkSupported($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT personel_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return null;
        }
        $pid = (int) $val;

        return $pid > 0 ? $pid : null;
    }

    /**
     * @param array<string,mixed> $actor
     */
    private static function assertPermission(array $actor, $permission, $denyCode): void
    {
        if (!RolePermissions::has($actor, $permission)) {
            throw new RuntimeException((string) $denyCode);
        }
    }

    /**
     * @param array<string,mixed> $actor
     */
    private static function assertActorActive(array $actor): void
    {
        if (!array_key_exists('durum', $actor)) {
            return;
        }
        if (strtoupper(trim((string) $actor['durum'])) !== 'AKTIF') {
            throw new RuntimeException('SGK_ACTOR_INACTIVE');
        }
    }

    /**
     * Generic/shared role-named accounts cannot be formal S98 preparer/approver.
     *
     * @param array<string,mixed> $actor
     */
    private static function assertFormalActorReady(array $actor): void
    {
        $username = strtolower(trim((string) ($actor['username'] ?? '')));
        if ($username === '') {
            return;
        }
        if (in_array($username, self::$genericUsernames, true)) {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_NOT_READY');
        }
    }
}
