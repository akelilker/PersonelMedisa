<?php

declare(strict_types=1);

namespace Medisa\Api\Scope;

use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;

class SubeScope
{
    /**
     * Effective sube scope for list/filter operations.
     * null = no single-sub scope (GENEL all-data mode or unrestricted list).
     *
     * @param array<string, mixed> $user
     * @return int|null
     */
    public static function resolveScope(array $user, Request $request)
    {
        $querySube = self::parsePositiveInt($request->getQuery('sube_id'));
        $headerSube = self::parsePositiveInt($request->getHeader('x-active-sube-id'));

        $requested = $querySube !== null ? $querySube : $headerSube;
        $allowed = self::allowedSubeIds($user);

        if (count($allowed) === 0) {
            return $requested;
        }

        if ($requested === null) {
            if (count($allowed) === 1) {
                return $allowed[0];
            }

            return null;
        }

        if (!in_array($requested, $allowed, true)) {
            JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
        }

        return $requested;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function assertPersonelAccess(array $user, Request $request, $personelSubeId)
    {
        $personelSubeId = (int) $personelSubeId;
        $allowed = self::allowedSubeIds($user);

        if (count($allowed) === 0) {
            $scope = self::resolveScope($user, $request);
            if ($scope !== null && $personelSubeId !== $scope) {
                JsonResponse::forbidden();
            }
            return;
        }

        if (!in_array($personelSubeId, $allowed, true)) {
            JsonResponse::forbidden();
        }

        $scope = self::resolveScope($user, $request);
        if ($scope !== null && $personelSubeId !== $scope) {
            JsonResponse::forbidden();
        }
    }

    /** @param array<int, int> $subeIds */
    public static function resolveInitialActiveSubeId(array $subeIds)
    {
        if (count($subeIds) === 0) {
            return null;
        }

        if (count($subeIds) === 1) {
            return $subeIds[0];
        }

        return $subeIds[0];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function allowedSubeIds(array $user)
    {
        $ids = isset($user['sube_ids']) && is_array($user['sube_ids']) ? $user['sube_ids'] : [];
        $normalized = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /** @param mixed $value */
    private static function parsePositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;
        return $parsed > 0 ? $parsed : null;
    }
}
