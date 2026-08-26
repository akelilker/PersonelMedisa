<?php

declare(strict_types=1);

namespace Medisa\Api\Scope;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\Request;

/**
 * Backward-compatible branch scope facade.
 * Authoritative org rules live in OrgScope.
 */
class SubeScope
{
    /**
     * @param array<string, mixed> $user
     * @return int|null
     */
    public static function resolveScope(array $user, Request $request)
    {
        return OrgScope::resolveActiveSubeId($user, $request);
    }

    /**
     * BA-scoped read surfaces: own birim amiri context when user is branch-amir only.
     * Retained for legacy surfaces; primary unit scope is OrgScope + user_birimler.
     *
     * @param array<string, mixed> $user
     * @return int|null
     */
    public static function restrictBirimAmiriUserId(array $user)
    {
        if (!RolePermissions::has($user, 'puantaj.amir_kontrol')) {
            return null;
        }
        if (RolePermissions::has($user, 'puantaj.bildirim_etki.generate')) {
            return null;
        }

        // Prefer org unit assignment over actor-user-id restriction when units exist.
        if (count(OrgScope::allowedBirimIds($user)) > 0) {
            return null;
        }

        $userId = isset($user['id']) ? (int) $user['id'] : 0;

        return $userId > 0 ? $userId : null;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|int $personelOrg
     */
    public static function assertPersonelAccess(array $user, Request $request, $personelOrg)
    {
        OrgScope::assertPersonelAccess($user, $request, $personelOrg);
    }

    /**
     * @param array<int, int> $subeIds
     * @param int|null $preferredSubeId
     */
    public static function resolveInitialActiveSubeId(array $subeIds, $preferredSubeId = null)
    {
        if (count($subeIds) === 0) {
            return null;
        }

        if ($preferredSubeId !== null && $preferredSubeId !== '') {
            $preferred = (int) $preferredSubeId;
            if ($preferred > 0 && in_array($preferred, $subeIds, true)) {
                return $preferred;
            }
        }

        if (count($subeIds) === 1) {
            return $subeIds[0];
        }

        return $subeIds[0];
    }

    /**
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param int|null $scope
     * @param array<int, int> $allowedSubeIds
     * @param bool $denyWhenEmpty fail-closed when no allowed branches (non-global roles)
     */
    public static function appendSubeFilter(array &$where, array &$params, $scope, array $allowedSubeIds, $column, $paramPrefix = 'scope', $denyWhenEmpty = false)
    {
        OrgScope::appendSubeFilter($where, $params, $scope, $allowedSubeIds, $column, $paramPrefix, $denyWhenEmpty);
    }

    /** @param array<int, int> $allowedSubeIds */
    public static function assertSealAccess($sealSubeId, $scope, array $allowedSubeIds)
    {
        $sealSubeId = (int) $sealSubeId;

        if (count($allowedSubeIds) > 0 && !in_array($sealSubeId, $allowedSubeIds, true)) {
            \Medisa\Api\Http\JsonResponse::forbidden('Bu kayit aktif sube baglaminda goruntulenemiyor.');
        }

        if ($scope !== null && $sealSubeId !== (int) $scope) {
            \Medisa\Api\Http\JsonResponse::forbidden('Bu kayit aktif sube baglaminda goruntulenemiyor.');
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function allowedSubeIds(array $user)
    {
        return OrgScope::allowedSubeIds($user);
    }
}
