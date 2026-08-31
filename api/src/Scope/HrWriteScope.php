<?php

declare(strict_types=1);

namespace Medisa\Api\Scope;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;

/**
 * Canonical write-company assertion for organisation-wide İK roles.
 *
 * OrgScope answers "which records may this user see". This owner answers the
 * separate question "which of those records may this user change", and it exists
 * because the two answers stopped being the same the moment a role got
 * organisation-wide read.
 *
 * IK_SORUMLUSU: reads and writes everywhere its permissions allow — no company
 * restriction, and no explicit assignment row may narrow it.
 *
 * IK_PERSONELI: reads everywhere, writes only inside the companies granted in
 * user_sirketler. Work outside that grant is not queued or delegated; the İK
 * sorumlusu inspects it and performs the mutation with their own account, so the
 * audit actor is always the person who actually decided.
 *
 * Fail-closed by construction: the write branch set is resolved once per request
 * in AuthMiddleware, and a target branch that cannot be attributed to a granted
 * company is denied rather than guessed.
 */
class HrWriteScope
{
    /** Roles whose write reach is narrower than their read reach. */
    const COMPANY_WRITE_SCOPED_ROLES = ['IK_PERSONELI'];

    const FORBIDDEN_CODE = 'IK_YAZMA_KAPSAMI_DISI';

    const FORBIDDEN_MESSAGE = 'Bu işlem İK sorumlusu tarafından gerçekleştirilmelidir.';

    /** HTTP methods that never mutate business data. */
    const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param array<string, mixed> $user
     */
    public static function isCompanyWriteScoped(array $user)
    {
        return in_array(OrgScope::normalizeRole($user), self::COMPANY_WRITE_SCOPED_ROLES, true);
    }

    /**
     * Companies the user may write in. user_sirketler is the canonical source:
     * for a write-scoped role it is a write grant, not a visibility grant.
     *
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function writeSirketIds(array $user)
    {
        return OrgScope::allowedSirketIds($user);
    }

    /**
     * Branches belonging to the granted companies, resolved at authentication
     * time so a branch added to a granted company later is writable immediately
     * and no grant row ever has to be rewritten.
     *
     * Explicit user_subeler rows deliberately do not appear here: for a
     * write-scoped role the company grant is the only write source.
     *
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function writeSubeIds(array $user)
    {
        if (!isset($user['write_sube_ids']) || !is_array($user['write_sube_ids'])) {
            return [];
        }

        $ids = [];
        foreach ($user['write_sube_ids'] as $id) {
            $value = (int) $id;
            if ($value > 0 && !in_array($value, $ids, true)) {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    /** A request that can change business state. */
    public static function isMutation(Request $request)
    {
        return !in_array(strtoupper($request->getMethod()), self::READ_METHODS, true);
    }

    /**
     * Target branch must resolve to a granted company. An unknown or branchless
     * target is denied: a write whose company cannot be derived cannot be proven
     * in scope.
     *
     * @param array<string, mixed> $user
     * @param int|null $subeId
     */
    public static function assertSubeWritable(array $user, $subeId)
    {
        if (!self::isCompanyWriteScoped($user)) {
            return;
        }

        $subeId = ($subeId === null || $subeId === '') ? 0 : (int) $subeId;
        if ($subeId <= 0 || !in_array($subeId, self::writeSubeIds($user), true)) {
            self::deny();
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param int|null $sirketId
     */
    public static function assertSirketWritable(array $user, $sirketId)
    {
        if (!self::isCompanyWriteScoped($user)) {
            return;
        }

        $sirketId = ($sirketId === null || $sirketId === '') ? 0 : (int) $sirketId;
        if ($sirketId <= 0 || !in_array($sirketId, self::writeSirketIds($user), true)) {
            self::deny();
        }
    }

    /**
     * Write gate for a personnel-targeted mutation. Called from the single
     * personnel access owner so every route inherits it instead of copying it.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed>|int $personelOrg personel row fragment or legacy sube_id int
     */
    public static function assertPersonelWritable(array $user, Request $request, $personelOrg)
    {
        if (!self::isCompanyWriteScoped($user) || !self::isMutation($request)) {
            return;
        }

        $subeId = null;
        if (is_array($personelOrg)) {
            if (isset($personelOrg['sube_id'])) {
                $subeId = $personelOrg['sube_id'];
            }
        } else {
            $subeId = $personelOrg;
        }

        self::assertSubeWritable($user, $subeId);
    }

    private static function deny()
    {
        JsonResponse::error(403, self::FORBIDDEN_CODE, self::FORBIDDEN_MESSAGE);
    }

    /**
     * Role catalog check kept next to the write model so a caller can describe
     * the scope contract without re-deriving it.
     *
     * @param string $role
     */
    public static function requiresWriteCompanySelection($role)
    {
        return in_array(RolePermissions::normalizeRole((string) $role), self::COMPANY_WRITE_SCOPED_ROLES, true);
    }
}
