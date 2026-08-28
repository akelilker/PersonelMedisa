<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use PDO;

/**
 * Canonical separation-of-duties owner for every approval chain.
 *
 * Permission = what the role may do; DualControl = whether *this* actor may be the
 * approver of *this* record given who entered/prepared it. Role name is never a bypass.
 *
 * Generic by construction: this class owns actor identity resolution
 * (users.actor_identity_id) so no domain service needs its own copy. Dependency
 * direction is always Domain/Service/Controller -> DualControl, never the reverse.
 * Domain layers map the canonical violation to their own error code and status.
 */
final class DualControl
{
    const CODE_ACTOR_REQUIRED = 'DUAL_CONTROL_ACTOR_REQUIRED';
    const CODE_ENTERED_BY_REQUIRED = 'DUAL_CONTROL_ENTERED_BY_REQUIRED';
    const CODE_SELF_APPROVAL = 'SELF_APPROVAL_FORBIDDEN';
    const CODE_SAME_IDENTITY = 'SAME_ACTOR_IDENTITY_FORBIDDEN';

    /**
     * Fail-closed: an unresolvable actor or unrecorded enterer cannot prove separation
     * of duties, so it is treated as a violation rather than silently approved.
     *
     * @param array<string, mixed> $actor authenticated session user
     * @param int|string|null $enteredByUserId users.id that entered/prepared/submitted the record
     * @return array{code: string, message: string}|null null when separation holds
     */
    public static function violation(array $actor, $enteredByUserId, PDO $pdo = null)
    {
        $actorId = self::actorUserId($actor);
        if ($actorId <= 0) {
            return [
                'code' => self::CODE_ACTOR_REQUIRED,
                'message' => 'Onaylayan kullanici cozumlenemedi.',
            ];
        }

        $enteredBy = self::normalizeUserId($enteredByUserId);
        if ($enteredBy <= 0) {
            return [
                'code' => self::CODE_ENTERED_BY_REQUIRED,
                'message' => 'Kaydi giren kullanici bilinmeden onay verilemez.',
            ];
        }

        if ($enteredBy === $actorId) {
            return [
                'code' => self::CODE_SELF_APPROVAL,
                'message' => 'Kaydi giren kullanici kendi kaydini onaylayamaz.',
            ];
        }

        if ($pdo instanceof PDO && self::isSameActorIdentity($pdo, $actor, $enteredBy)) {
            return [
                'code' => self::CODE_SAME_IDENTITY,
                'message' => 'Ayni kisiye ait ikinci hesap onay adimi icin kullanilamaz.',
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $actor
     * @param int|string|null $enteredByUserId
     */
    public static function isSeparated(array $actor, $enteredByUserId, PDO $pdo = null)
    {
        return self::violation($actor, $enteredByUserId, $pdo) === null;
    }

    /**
     * Same user account. Both sides must resolve to a positive id to count as a match,
     * so callers that must fail closed on an unknown enterer use violation() instead.
     *
     * @param array<string, mixed> $actor
     * @param int|string|null $enteredByUserId
     */
    public static function isSameActorUser(array $actor, $enteredByUserId)
    {
        $actorId = self::actorUserId($actor);
        $enteredBy = self::normalizeUserId($enteredByUserId);

        return $actorId > 0 && $enteredBy > 0 && $actorId === $enteredBy;
    }

    /**
     * Same real person through two different accounts (users.actor_identity_id).
     * False when the schema is absent or either link is missing; callers needing a
     * hard requirement on the link check resolveActorIdentityId() explicitly.
     *
     * @param array<string, mixed> $actor
     * @param int|string|null $enteredByUserId
     */
    public static function isSameActorIdentity(PDO $pdo, array $actor, $enteredByUserId)
    {
        $actorId = self::actorUserId($actor);
        $enteredBy = self::normalizeUserId($enteredByUserId);
        if ($actorId <= 0 || $enteredBy <= 0 || !self::actorIdentitySchemaSupported($pdo)) {
            return false;
        }

        $approverIdentity = self::resolveActorIdentityId($pdo, $actorId, $actor);
        $entererIdentity = self::resolveActorIdentityId($pdo, $enteredBy, null);

        return $approverIdentity !== null
            && $entererIdentity !== null
            && $approverIdentity === $entererIdentity;
    }

    /**
     * Schema probe without process-level static cache (safe across PDO / schema states).
     */
    public static function actorIdentitySchemaSupported(PDO $pdo)
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
     * @param array<string, mixed>|null $actorHint
     * @return int|null
     */
    public static function resolveActorIdentityId(PDO $pdo, $userId, $actorHint)
    {
        $userId = (int) $userId;
        // Session hint is trusted only as cache of authenticated user row — never from request body.
        if (is_array($actorHint)
            && array_key_exists('actor_identity_id', $actorHint)
            && $actorHint['actor_identity_id'] !== null
            && $actorHint['actor_identity_id'] !== ''
        ) {
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

    /** @param array<string, mixed> $actor */
    private static function actorUserId(array $actor)
    {
        return isset($actor['id']) ? (int) $actor['id'] : 0;
    }

    /** @param int|string|null $value */
    private static function normalizeUserId($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $id = (int) $value;

        return $id > 0 ? $id : 0;
    }
}
