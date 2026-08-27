<?php

declare(strict_types=1);

namespace Medisa\Api\Scope;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Personel\PersonelGeciciGorevlendirmeSchema;
use Medisa\Api\Services\Personel\PersonelGeciciGorevlendirmeService;
use Medisa\Api\Services\Personel\PersonelOperationalContextService;
use PDO;

/**
 * Canonical organizational authorization scope.
 * Permission = what; OrgScope = on which org records.
 *
 * Hierarchy (business): ŞİRKET → ŞUBE → BÖLÜM → BİRİM → PERSONEL
 * Schema note: bolumler ⊂ departmanlar (catalog); personeller carry sube_id/bolum_id/birim_id.
 * Scope uses assignment tables + personnel FKs — not empty-assignment-as-all.
 */
class OrgScope
{
    /** Empty assignment means unrestricted. */
    const GLOBAL_ROLES = ['GENEL_YONETICI', 'SISTEM_YONETICISI'];

    /** Require user_subeler; empty = deny. */
    const SUBE_ASSIGNMENT_ROLES = ['SUBE_YONETICISI', 'IK_SORUMLUSU', 'MUHASEBE', 'AUTH_SMOKE_READONLY'];

    /** Require user_bolumler; empty = deny. */
    const BOLUM_ASSIGNMENT_ROLES = ['BOLUM_YONETICISI'];

    /** Require user_birimler; empty = deny. */
    const BIRIM_ASSIGNMENT_ROLES = ['BIRIM_AMIRI'];

    /**
     * @param array<string, mixed> $user
     */
    public static function normalizeRole(array $user)
    {
        return RolePermissions::normalizeRole(isset($user['rol']) ? (string) $user['rol'] : '');
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isUnrestricted(array $user)
    {
        $role = self::normalizeRole($user);

        return in_array($role, self::GLOBAL_ROLES, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function allowedSubeIds(array $user)
    {
        return self::normalizePositiveIds(isset($user['sube_ids']) && is_array($user['sube_ids']) ? $user['sube_ids'] : []);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function allowedBolumIds(array $user)
    {
        return self::normalizePositiveIds(isset($user['bolum_ids']) && is_array($user['bolum_ids']) ? $user['bolum_ids'] : []);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function allowedBirimIds(array $user)
    {
        return self::normalizePositiveIds(isset($user['birim_ids']) && is_array($user['birim_ids']) ? $user['birim_ids'] : []);
    }

    /**
     * Fail-closed for management-scoped roles without required assignment.
     * PERSONEL self-service uses personel_id binding instead.
     *
     * BOLUM_YONETICISI requires user_bolumler; BIRIM_AMIRI requires user_birimler.
     * user_subeler is not a fallback for either unit role (never empty-as-global).
     *
     * @param array<string, mixed> $user
     */
    public static function assertRequiredAssignment(array $user)
    {
        $role = self::normalizeRole($user);
        if ($role === '' || $role === 'PERSONEL' || self::isUnrestricted($user)) {
            return;
        }

        if (in_array($role, self::BOLUM_ASSIGNMENT_ROLES, true)) {
            if (count(self::allowedBolumIds($user)) === 0) {
                JsonResponse::forbidden('Bolum kapsami atanmamis.');
            }

            return;
        }

        if (in_array($role, self::BIRIM_ASSIGNMENT_ROLES, true)) {
            if (count(self::allowedBirimIds($user)) === 0) {
                JsonResponse::forbidden('Birim kapsami atanmamis.');
            }

            return;
        }

        if (in_array($role, self::SUBE_ASSIGNMENT_ROLES, true)) {
            if (count(self::allowedSubeIds($user)) === 0) {
                JsonResponse::forbidden('Sube kapsami atanmamis.');
            }
        }
    }

    /**
     * Active branch context. Never expands beyond assignment.
     * Unrestricted empty assignment may resolve requested/null.
     * Scoped empty assignment → forbidden.
     *
     * @param array<string, mixed> $user
     * @return int|null
     */
    public static function resolveActiveSubeId(array $user, Request $request)
    {
        self::assertRequiredAssignment($user);

        $querySube = self::parsePositiveInt($request->getQuery('sube_id'));
        $headerSube = self::parsePositiveInt($request->getHeader('x-active-sube-id'));
        $requested = $querySube !== null ? $querySube : $headerSube;
        $allowed = self::allowedSubeIds($user);
        $role = self::normalizeRole($user);

        if ($role === 'PERSONEL') {
            return $requested;
        }

        // Unit-assigned BOLUM/BIRIM: optional branch narrow only; cannot invent branch auth.
        // assertRequiredAssignment already denied empty unit assignment (no user_subeler fallback).
        if (in_array($role, self::BOLUM_ASSIGNMENT_ROLES, true)
            || in_array($role, self::BIRIM_ASSIGNMENT_ROLES, true)
        ) {
            return $requested;
        }

        if (self::isUnrestricted($user) && count($allowed) === 0) {
            return $requested;
        }

        if (count($allowed) === 0) {
            JsonResponse::forbidden('Sube kapsami atanmamis.');
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
     * Authoritative personnel access check.
     * Optional PDO: 076 hazırsa aktif geçici görevlendirme effective org kullanılır.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed>|int $personelOrg personel row fragment or legacy sube_id int
     */
    public static function assertPersonelAccess(array $user, Request $request, $personelOrg, $pdo = null)
    {
        $role = self::normalizeRole($user);

        if ($role === 'PERSONEL') {
            $bound = isset($user['personel_id']) ? (int) $user['personel_id'] : 0;
            $targetId = 0;
            if (is_array($personelOrg)) {
                $targetId = isset($personelOrg['id']) ? (int) $personelOrg['id'] : 0;
            }
            if ($bound <= 0 || $targetId <= 0 || $bound !== $targetId) {
                JsonResponse::forbidden();
            }

            return;
        }

        self::assertRequiredAssignment($user);

        if ($pdo instanceof PDO && is_array($personelOrg) && isset($personelOrg['id'])
            && PersonelGeciciGorevlendirmeSchema::isReady($pdo)
        ) {
            $personelOrg = PersonelOperationalContextService::effectiveOrgForAccess($pdo, $personelOrg);
        }

        $subeId = 0;
        $bolumId = null;
        $birimId = null;
        if (is_array($personelOrg)) {
            $subeId = isset($personelOrg['sube_id']) ? (int) $personelOrg['sube_id'] : 0;
            if (array_key_exists('bolum_id', $personelOrg) && $personelOrg['bolum_id'] !== null && $personelOrg['bolum_id'] !== '') {
                $bolumId = (int) $personelOrg['bolum_id'];
            }
            if (array_key_exists('birim_id', $personelOrg) && $personelOrg['birim_id'] !== null && $personelOrg['birim_id'] !== '') {
                $birimId = (int) $personelOrg['birim_id'];
            }
        } else {
            $subeId = (int) $personelOrg;
        }

        if (in_array($role, self::BIRIM_ASSIGNMENT_ROLES, true)) {
            $allowedBirim = self::allowedBirimIds($user);
            if ($birimId === null || $birimId <= 0 || !in_array($birimId, $allowedBirim, true)) {
                JsonResponse::forbidden();
            }
            self::assertActiveSubeNarrow($user, $request, $subeId);

            return;
        }

        if (in_array($role, self::BOLUM_ASSIGNMENT_ROLES, true)) {
            $allowedBolum = self::allowedBolumIds($user);
            if ($bolumId === null || $bolumId <= 0 || !in_array($bolumId, $allowedBolum, true)) {
                JsonResponse::forbidden();
            }
            self::assertActiveSubeNarrow($user, $request, $subeId);

            return;
        }

        // Branch-scoped roles (SUBE / IK / MUHASEBE) and optional GY active-sube narrow.

        if (self::isUnrestricted($user) && count(self::allowedSubeIds($user)) === 0) {
            // Bağlantısız DIS (sube_id NULL) merkezi havuz — GY/Sistem görür.
            if ($subeId <= 0) {
                return;
            }
            $scope = self::resolveActiveSubeId($user, $request);
            if ($scope !== null && $subeId !== (int) $scope) {
                JsonResponse::forbidden();
            }

            return;
        }

        // IK_SORUMLUSU: bağlantısız DIS havuzu merkezi görünüm.
        if ($role === 'IK_SORUMLUSU' && $subeId <= 0) {
            return;
        }

        $allowedSube = self::allowedSubeIds($user);
        if (count($allowedSube) === 0 || !in_array($subeId, $allowedSube, true)) {
            JsonResponse::forbidden();
        }

        $scope = self::resolveActiveSubeId($user, $request);
        if ($scope !== null && $subeId !== (int) $scope) {
            JsonResponse::forbidden();
        }
    }

    /**
     * SQL list filter for personeller alias (default p).
     * Optional PDO: 076 hazırsa aktif görevlendirme hedef_* alanlarıyla OR.
     * 076 yoksa mevcut 075 permanent-only davranış.
     *
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param array<string, mixed> $user
     * @param int|null $activeSubeScope
     */
    public static function appendPersonelOrgFilter(
        array &$where,
        array &$params,
        array $user,
        $activeSubeScope,
        $alias = 'p',
        $paramPrefix = 'org',
        $pdo = null
    ) {
        $role = self::normalizeRole($user);
        $col = $alias . '.';
        $assignmentAware = $pdo instanceof PDO && PersonelGeciciGorevlendirmeSchema::isReady($pdo);

        if ($role === 'PERSONEL') {
            $bound = isset($user['personel_id']) ? (int) $user['personel_id'] : 0;
            if ($bound <= 0) {
                $where[] = '1=0';

                return;
            }
            $key = $paramPrefix . '_self_personel_id';
            $where[] = $col . 'id = :' . $key;
            $params[$key] = $bound;

            return;
        }

        if (in_array($role, self::BIRIM_ASSIGNMENT_ROLES, true)) {
            $ids = self::allowedBirimIds($user);
            if (count($ids) === 0) {
                $where[] = '1=0';

                return;
            }
            if ($assignmentAware) {
                self::appendPermanentOrAssignmentInFilter(
                    $where,
                    $params,
                    $col,
                    'birim_id',
                    'hedef_birim_id',
                    $ids,
                    $paramPrefix . '_birim',
                    $activeSubeScope,
                    $paramPrefix
                );
            } else {
                self::appendInFilter($where, $params, $col . 'birim_id', $ids, $paramPrefix . '_birim');
                if ($activeSubeScope !== null) {
                    $key = $paramPrefix . '_active_sube';
                    $where[] = $col . 'sube_id = :' . $key;
                    $params[$key] = (int) $activeSubeScope;
                }
            }

            return;
        }

        if (in_array($role, self::BOLUM_ASSIGNMENT_ROLES, true)) {
            $ids = self::allowedBolumIds($user);
            if (count($ids) === 0) {
                $where[] = '1=0';

                return;
            }
            if ($assignmentAware) {
                self::appendPermanentOrAssignmentInFilter(
                    $where,
                    $params,
                    $col,
                    'bolum_id',
                    'hedef_bolum_id',
                    $ids,
                    $paramPrefix . '_bolum',
                    $activeSubeScope,
                    $paramPrefix
                );
            } else {
                self::appendInFilter($where, $params, $col . 'bolum_id', $ids, $paramPrefix . '_bolum');
                if ($activeSubeScope !== null) {
                    $key = $paramPrefix . '_active_sube';
                    $where[] = $col . 'sube_id = :' . $key;
                    $params[$key] = (int) $activeSubeScope;
                }
            }

            return;
        }

        if (self::isUnrestricted($user) && count(self::allowedSubeIds($user)) === 0) {
            if ($activeSubeScope !== null) {
                if ($assignmentAware) {
                    $key = $paramPrefix . '_active_sube';
                    $params[$key] = (int) $activeSubeScope;
                    $where[] = PersonelGeciciGorevlendirmeService::sqlPersonelMatchesEffectiveSube(
                        $pdo,
                        rtrim($col, '.'),
                        $key,
                        $params,
                        null,
                        $paramPrefix . '_eff'
                    );
                } else {
                    $key = $paramPrefix . '_active_sube';
                    $where[] = $col . 'sube_id = :' . $key;
                    $params[$key] = (int) $activeSubeScope;
                }
            }

            return;
        }

        $allowedSube = self::allowedSubeIds($user);
        if (count($allowedSube) === 0) {
            $where[] = '1=0';

            return;
        }

        if ($activeSubeScope !== null) {
            $key = $paramPrefix . '_active_sube';
            $params[$key] = (int) $activeSubeScope;
            if ($assignmentAware) {
                $where[] = PersonelGeciciGorevlendirmeService::sqlPersonelMatchesEffectiveSube(
                    $pdo,
                    rtrim($col, '.'),
                    $key,
                    $params,
                    null,
                    $paramPrefix . '_eff'
                );
            } else {
                $where[] = $col . 'sube_id = :' . $key;
            }

            return;
        }

        if ($assignmentAware) {
            self::appendPermanentOrAssignmentInFilter(
                $where,
                $params,
                $col,
                'sube_id',
                'hedef_sube_id',
                $allowedSube,
                $paramPrefix . '_sube',
                null,
                $paramPrefix
            );

            return;
        }

        self::appendInFilter($where, $params, $col . 'sube_id', $allowedSube, $paramPrefix . '_sube');
    }

    /**
     * (permanent.col IN ids [AND permanent.sube=active])
     * OR (aktif assignment hedef_col IN ids [AND hedef_sube=active])
     *
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param array<int, int> $ids
     */
    private static function appendPermanentOrAssignmentInFilter(
        array &$where,
        array &$params,
        $col,
        $permanentCol,
        $assignmentCol,
        array $ids,
        $paramPrefix,
        $activeSubeScope,
        $scopePrefix
    ) {
        $permPlaceholders = [];
        $asgPlaceholders = [];
        foreach (array_values($ids) as $index => $id) {
            $permKey = $paramPrefix . '_p_' . $index;
            $asgKey = $paramPrefix . '_a_' . $index;
            $permPlaceholders[] = ':' . $permKey;
            $asgPlaceholders[] = ':' . $asgKey;
            $params[$permKey] = (int) $id;
            $params[$asgKey] = (int) $id;
        }
        $permanentParts = [$col . $permanentCol . ' IN (' . implode(', ', $permPlaceholders) . ')'];
        $assignmentParts = ['g.' . $assignmentCol . ' IN (' . implode(', ', $asgPlaceholders) . ')'];

        if ($activeSubeScope !== null) {
            $permSubeKey = $scopePrefix . '_active_sube_p';
            $asgSubeKey = $scopePrefix . '_active_sube_a';
            $params[$permSubeKey] = (int) $activeSubeScope;
            $params[$asgSubeKey] = (int) $activeSubeScope;
            $permanentParts[] = $col . 'sube_id = :' . $permSubeKey;
            $assignmentParts[] = 'g.hedef_sube_id = :' . $asgSubeKey;
        }

        $nowKey = $scopePrefix . '_asg_now';
        $nowKey2 = $scopePrefix . '_asg_now_b';
        $now = PersonelGeciciGorevlendirmeService::businessNow();
        $params[$nowKey] = $now;
        $params[$nowKey2] = $now;

        $alias = rtrim($col, '.');
        $where[] = '((' . implode(' AND ', $permanentParts) . ') OR EXISTS (
            SELECT 1 FROM personel_gecici_gorevlendirmeler g
            WHERE g.personel_id = ' . $alias . '.id
              AND g.durum = \'AKTIF\'
              AND g.baslangic_at <= :' . $nowKey . '
              AND (g.bitis_at IS NULL OR g.bitis_at > :' . $nowKey2 . ')
              AND ' . implode(' AND ', $assignmentParts) . '
        ))';
    }

    /**
     * Branch-only filter used by legacy report/seal paths.
     * Fail-closed when caller marks empty-as-deny.
     *
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param int|null $scope
     * @param array<int, int> $allowedSubeIds
     */
    public static function appendSubeFilter(array &$where, array &$params, $scope, array $allowedSubeIds, $column, $paramPrefix = 'scope', $denyWhenEmpty = false)
    {
        if ($scope !== null) {
            $key = $paramPrefix . '_sube_id';
            $where[] = $column . ' = :' . $key;
            $params[$key] = (int) $scope;

            return;
        }

        if (count($allowedSubeIds) === 0) {
            if ($denyWhenEmpty) {
                $where[] = '1=0';
            }

            return;
        }

        self::appendInFilter($where, $params, $column, $allowedSubeIds, $paramPrefix . '_allowed_sube_id');
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function assertActiveSubeNarrow(array $user, Request $request, $personelSubeId)
    {
        $querySube = self::parsePositiveInt($request->getQuery('sube_id'));
        $headerSube = self::parsePositiveInt($request->getHeader('x-active-sube-id'));
        $requested = $querySube !== null ? $querySube : $headerSube;
        if ($requested !== null && (int) $personelSubeId !== (int) $requested) {
            JsonResponse::forbidden();
        }

        $assignedSube = self::allowedSubeIds($user);
        if (count($assignedSube) > 0 && !in_array((int) $personelSubeId, $assignedSube, true)) {
            JsonResponse::forbidden();
        }
    }

    /**
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param array<int, int> $ids
     */
    private static function appendInFilter(array &$where, array &$params, $column, array $ids, $paramPrefix)
    {
        $placeholders = [];
        foreach (array_values($ids) as $index => $id) {
            $key = $paramPrefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $id;
        }
        $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private static function normalizePositiveIds(array $ids)
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
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
