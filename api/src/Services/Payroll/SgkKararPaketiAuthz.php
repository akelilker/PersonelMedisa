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
 * Formal write: identity + permission + verified actor_identity link + explicit scope (fail-closed).
 * Same-person owner: users.actor_identity_id (not personel master).
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
        self::assertFormalActorIdentity($actor);
        self::assertPermission($actor, self::PERM_PREPARE, 'SGK_PREPARE_FORBIDDEN');
        self::assertActorIdentitySchemaRequired($pdo);
        self::assertActorIdentityLinkedAndVerified($pdo, $actor);
    }

    /**
     * @param array<string,mixed> $actor
     */
    public static function assertApprove(PDO $pdo, array $actor): void
    {
        self::assertFormalActorIdentity($actor);
        self::assertPermission($actor, self::PERM_APPROVE, 'SGK_APPROVE_FORBIDDEN');
        self::assertActorIdentitySchemaRequired($pdo);
        self::assertActorIdentityLinkedAndVerified($pdo, $actor);
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
     * Same real person via users.actor_identity_id — fail-closed when schema/link missing.
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
                'code' => 'SGK_PREPARER_ACTOR_IDENTITY_REQUIRED',
                'message' => 'Hazirlayan actor identity bagi cozumlenemedi.',
                'link_supported' => self::actorIdentitySchemaSupported($pdo),
            ];
        }
        if ($hazirlayanId === $actorId) {
            // Self-approval is owned by denySelfApproval; do not double-fire same-person here.
            return ['ok' => true, 'link_supported' => self::actorIdentitySchemaSupported($pdo)];
        }

        if (!self::actorIdentitySchemaSupported($pdo)) {
            return [
                'ok' => false,
                'code' => 'SGK_ACTOR_IDENTITY_SCHEMA_REQUIRED',
                'message' => 'actor_identities / users.actor_identity_id semasi formal dual-control icin zorunlu.',
                'link_supported' => false,
            ];
        }

        $approverIdentity = self::resolveActorIdentityId($pdo, $actorId, $actor);
        if ($approverIdentity === null) {
            return [
                'ok' => false,
                'code' => 'SGK_APPROVER_ACTOR_IDENTITY_REQUIRED',
                'message' => 'Onaylayan hesabin actor_identity_id bagi zorunlu.',
                'link_supported' => true,
            ];
        }

        $preparerIdentity = self::resolveActorIdentityId($pdo, $hazirlayanId, null);
        if ($preparerIdentity === null) {
            return [
                'ok' => false,
                'code' => 'SGK_PREPARER_ACTOR_IDENTITY_REQUIRED',
                'message' => 'Hazirlayan hesabin actor_identity_id bagi zorunlu.',
                'link_supported' => true,
            ];
        }

        if ($approverIdentity === $preparerIdentity) {
            return [
                'ok' => false,
                'code' => 'SGK_SAME_ACTOR_IDENTITY_FORBIDDEN',
                'message' => 'Ayni actor identity dual-control icin kullanilamaz.',
                'link_supported' => true,
            ];
        }

        return ['ok' => true, 'link_supported' => true];
    }

    /**
     * Schema probe without process-level static cache (safe across PDO / schema states).
     */
    public static function actorIdentitySchemaSupported(PDO $pdo): bool
    {
        try {
            $table = $pdo->query("SHOW TABLES LIKE 'actor_identities'");
            if ($table === false || $table->fetch(PDO::FETCH_NUM) === false) {
                if ($table !== false) {
                    $table->closeCursor();
                }

                return false;
            }
            $table->closeCursor();

            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'actor_identity_id'");
            if ($col === false) {
                return false;
            }
            $row = $col->fetch(PDO::FETCH_ASSOC);
            $col->closeCursor();

            return $row !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @deprecated Use actorIdentitySchemaSupported(); kept only to avoid stale call sites during refactor.
     */
    public static function personelLinkSupported(PDO $pdo): bool
    {
        return self::actorIdentitySchemaSupported($pdo);
    }

    /**
     * @param array<string,mixed>|null $actorHint
     * @return int|null
     */
    public static function resolveActorIdentityId(PDO $pdo, $userId, $actorHint)
    {
        $userId = (int) $userId;
        // Session hint is trusted only as cache of authenticated user row — never from request body.
        if (is_array($actorHint) && array_key_exists('actor_identity_id', $actorHint) && $actorHint['actor_identity_id'] !== null && $actorHint['actor_identity_id'] !== '') {
            $aid = (int) $actorHint['actor_identity_id'];

            return $aid > 0 ? $aid : null;
        }
        if ($userId <= 0 || !self::actorIdentitySchemaSupported($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT actor_identity_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return null;
        }
        $aid = (int) $val;

        return $aid > 0 ? $aid : null;
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

        // Technical / smoke / probe / demo / test username patterns are not formal actors.
        if (
            strpos($username, 'smoke') !== false
            || strpos($username, 'probe') !== false
            || strpos($username, 'test') !== false
            || strpos($username, 'demo') !== false
        ) {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_NOT_READY');
        }

        if (!array_key_exists('durum', $actor) || strtoupper(trim((string) $actor['durum'])) !== 'AKTIF') {
            throw new RuntimeException('SGK_ACTOR_INACTIVE');
        }
    }

    /**
     * @param array<string,mixed> $actor
     */
    private static function assertActorIdentityLinkedAndVerified(PDO $pdo, array $actor): void
    {
        $aid = isset($actor['actor_identity_id']) ? (int) $actor['actor_identity_id'] : 0;
        if ($aid <= 0) {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_LINK_REQUIRED');
        }

        $statusHint = isset($actor['actor_identity_status'])
            ? strtoupper(trim((string) $actor['actor_identity_status']))
            : '';

        $status = $statusHint;
        if ($status === '') {
            $stmt = $pdo->prepare('SELECT status FROM actor_identities WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $aid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('SGK_ACTOR_IDENTITY_NOT_FOUND');
            }
            $status = strtoupper(trim((string) ($row['status'] ?? '')));
        } else {
            // Defensive: confirm identity row still exists even when session carries status.
            $stmt = $pdo->prepare('SELECT status FROM actor_identities WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $aid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('SGK_ACTOR_IDENTITY_NOT_FOUND');
            }
            $status = strtoupper(trim((string) ($row['status'] ?? '')));
        }

        if ($status !== 'VERIFIED') {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_NOT_VERIFIED');
        }

        // Defensive multi-account: unique index should prevent this; fail-closed if broken data.
        $cntStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users WHERE actor_identity_id = :aid AND actor_identity_id IS NOT NULL'
        );
        $cntStmt->execute(['aid' => $aid]);
        $cnt = (int) $cntStmt->fetchColumn();
        if ($cnt > 1) {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_CONFLICT');
        }
    }

    private static function assertActorIdentitySchemaRequired(PDO $pdo): void
    {
        if (!self::actorIdentitySchemaSupported($pdo)) {
            throw new RuntimeException('SGK_ACTOR_IDENTITY_SCHEMA_REQUIRED');
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
