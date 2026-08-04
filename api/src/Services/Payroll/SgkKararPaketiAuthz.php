<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use Medisa\Api\Auth\RolePermissions;
use PDO;
use RuntimeException;

/**
 * S98 least-privilege SGK karar paketi prepare/approve authz (PHP 7.4-safe).
 * Actor always comes from authenticated session — never from request payload.
 *
 * Formal write: identity + permission + personel link + explicit scope are fail-closed.
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
    public static function assertPrepare(PDO $pdo, array $actor): void
    {
        // Deterministic order: identity → permission → personel schema/link
        self::assertFormalActorIdentity($actor);
        self::assertPermission($actor, self::PERM_PREPARE, 'SGK_PREPARE_FORBIDDEN');
        self::assertPersonelSchemaRequired($pdo);
        self::assertActorPersonelLinked($actor);
    }

    /**
     * @param array<string,mixed> $actor
     */
    public static function assertApprove(PDO $pdo, array $actor): void
    {
        self::assertFormalActorIdentity($actor);
        self::assertPermission($actor, self::PERM_APPROVE, 'SGK_APPROVE_FORBIDDEN');
        self::assertPersonelSchemaRequired($pdo);
        self::assertActorPersonelLinked($actor);
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
        if (!isset($actor['sube_ids']) || !is_array($actor['sube_ids'])) {
            throw new RuntimeException('SGK_ACTOR_SCOPE_NOT_READY');
        }
        $subeIds = $actor['sube_ids'];
        if ($subeIds === []) {
            throw new RuntimeException('SGK_ACTOR_SCOPE_NOT_READY');
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
     * Same real person via users.personel_id — fail-closed when schema/link missing.
     *
     * @param array<string,mixed> $actor
     * @return array{ok: bool, code?: string, message?: string, link_supported?: bool}
     */
    public static function denySamePerson(PDO $pdo, array $actor, $hazirlayanId)
    {
        $hazirlayanId = (int) $hazirlayanId;
        $actorId = (int) ($actor['id'] ?? 0);
        if ($hazirlayanId <= 0 || $actorId <= 0) {
            return [
                'ok' => false,
                'code' => 'SGK_PREPARER_PERSONEL_LINK_REQUIRED',
                'message' => 'Hazirlayan kimlik bagi cozumlenemedi.',
                'link_supported' => self::personelLinkSupported($pdo),
            ];
        }
        if ($hazirlayanId === $actorId) {
            // Self-approval is owned by denySelfApproval; do not double-fire same-person here.
            return ['ok' => true, 'link_supported' => self::personelLinkSupported($pdo)];
        }

        if (!self::personelLinkSupported($pdo)) {
            return [
                'ok' => false,
                'code' => 'SGK_ACTOR_PERSONEL_SCHEMA_REQUIRED',
                'message' => 'users.personel_id semasi formal dual-control icin zorunlu.',
                'link_supported' => false,
            ];
        }

        $actorPersonel = self::resolvePersonelId($pdo, $actorId, $actor);
        if ($actorPersonel === null) {
            return [
                'ok' => false,
                'code' => 'SGK_ACTOR_PERSONEL_LINK_REQUIRED',
                'message' => 'Onaylayan hesabin personel_id bagi zorunlu.',
                'link_supported' => true,
            ];
        }

        $hazirlayanPersonel = self::resolvePersonelId($pdo, $hazirlayanId, null);
        if ($hazirlayanPersonel === null) {
            return [
                'ok' => false,
                'code' => 'SGK_PREPARER_PERSONEL_LINK_REQUIRED',
                'message' => 'Hazirlayan hesabin personel_id bagi zorunlu.',
                'link_supported' => true,
            ];
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

    /**
     * Schema probe without process-level static cache (safe across PDO / schema states).
     */
    public static function personelLinkSupported(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'personel_id'");
            if ($stmt === false) {
                return false;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return $row !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed>|null $actorHint
     * @return int|null
     */
    public static function resolvePersonelId(PDO $pdo, $userId, $actorHint)
    {
        $userId = (int) $userId;
        if (is_array($actorHint) && array_key_exists('personel_id', $actorHint) && $actorHint['personel_id'] !== null && $actorHint['personel_id'] !== '') {
            $pid = (int) $actorHint['personel_id'];

            return $pid > 0 ? $pid : null;
        }
        if ($userId <= 0 || !self::personelLinkSupported($pdo)) {
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
    private static function assertFormalActorIdentity(array $actor): void
    {
        $actorId = (int) ($actor['id'] ?? 0);
        if ($actorId <= 0) {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_INVALID');
        }

        $username = strtolower(trim((string) ($actor['username'] ?? '')));
        if ($username === '' || in_array($username, self::$genericUsernames, true)) {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_NOT_READY');
        }

        if (!array_key_exists('durum', $actor) || strtoupper(trim((string) $actor['durum'])) !== 'AKTIF') {
            throw new RuntimeException('SGK_ACTOR_INACTIVE');
        }
    }

    /**
     * @param array<string,mixed> $actor
     */
    private static function assertActorPersonelLinked(array $actor): void
    {
        $pid = isset($actor['personel_id']) ? (int) $actor['personel_id'] : 0;
        if ($pid <= 0) {
            throw new RuntimeException('SGK_ACTOR_PERSONEL_LINK_REQUIRED');
        }
    }

    private static function assertPersonelSchemaRequired(PDO $pdo): void
    {
        if (!self::personelLinkSupported($pdo)) {
            throw new RuntimeException('SGK_ACTOR_PERSONEL_SCHEMA_REQUIRED');
        }
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
}
