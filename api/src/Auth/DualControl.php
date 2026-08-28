<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Services\Payroll\SgkKararPaketiAuthz;
use PDO;

/**
 * Canonical separation-of-duties primitive for approval chains.
 *
 * Permission = what the role may do; DualControl = whether *this* actor may be the
 * approver of *this* record given who entered/prepared it. Role name is never a bypass.
 *
 * Actor identity resolution is delegated to SgkKararPaketiAuthz (existing owner of
 * users.actor_identity_id) so there is a single same-person resolver in the codebase.
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
        $actorId = isset($actor['id']) ? (int) $actor['id'] : 0;
        if ($actorId <= 0) {
            return [
                'code' => self::CODE_ACTOR_REQUIRED,
                'message' => 'Onaylayan kullanici cozumlenemedi.',
            ];
        }

        $enteredBy = ($enteredByUserId === null || $enteredByUserId === '') ? 0 : (int) $enteredByUserId;
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

        if ($pdo === null || !SgkKararPaketiAuthz::actorIdentitySchemaSupported($pdo)) {
            return null;
        }

        $approverIdentity = SgkKararPaketiAuthz::resolveActorIdentityId($pdo, $actorId, $actor);
        $entererIdentity = SgkKararPaketiAuthz::resolveActorIdentityId($pdo, $enteredBy, null);
        if ($approverIdentity !== null && $entererIdentity !== null && $approverIdentity === $entererIdentity) {
            return [
                'code' => self::CODE_SAME_IDENTITY,
                'message' => 'Ayni kisiye ait ikinci hesap onay adimi icin kullanilamaz.',
            ];
        }

        return null;
    }

    /**
     * True when the actor may act as approver for a record entered by $enteredByUserId.
     *
     * @param array<string, mixed> $actor
     * @param int|string|null $enteredByUserId
     */
    public static function isSeparated(array $actor, $enteredByUserId, PDO $pdo = null)
    {
        return self::violation($actor, $enteredByUserId, $pdo) === null;
    }
}
