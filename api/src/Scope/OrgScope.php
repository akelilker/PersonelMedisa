<?php

declare(strict_types=1);

namespace Medisa\Api\Scope;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\SubeMuhasebeYetkiSchema;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamSchema;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
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
    const SUBE_ASSIGNMENT_ROLES = ['SUBE_YONETICISI', 'MUHASEBE', 'AUTH_SMOKE_READONLY'];

    /**
     * İK roles whose *read* reach is the whole organisation, derived from the
     * role itself rather than from assignment rows.
     *
     * İK works on people wherever they sit, so a branch-by-branch grant never
     * described the job: it only produced grants that had to be rewritten every
     * time a branch or company was added. Reading is therefore role-derived and
     * automatically covers future branches; explicit user_subeler/user_sirketler
     * rows may not narrow it.
     *
     * This is visibility only — it grants no management permission, and for a
     * write-scoped İK role the write reach stays narrower (see HrWriteScope).
     */
    const ORGANIZATION_GLOBAL_READ_ROLES = ['IK_SORUMLUSU', 'IK_PERSONELI'];

    /** Require user_bolumler; empty = deny. */
    const BOLUM_ASSIGNMENT_ROLES = ['BOLUM_YONETICISI'];

    /** Require user_birimler; empty = deny. */
    const BIRIM_ASSIGNMENT_ROLES = ['BIRIM_AMIRI'];

    /**
     * Roles that may hold a company-wide scope (user_sirketler).
     *
     * SUBE_YONETICISI is deliberately absent: a branch manager stays confined to
     * the branches explicitly granted in user_subeler and must never be widened
     * to every branch of a company. Global roles need no grant at all.
     *
     * IK_PERSONELI is eligible because for that role the company grant is what
     * defines where it may write.
     */
    const SIRKET_SCOPE_ELIGIBLE_ROLES = ['IK_SORUMLUSU', 'IK_PERSONELI', 'MUHASEBE'];

    /** Roles that may hold an SGK/payroll-employer scope (user_sgk_isverenler). */
    const SGK_SCOPE_ELIGIBLE_ROLES = ['IK_SORUMLUSU', 'MUHASEBE'];

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
     * Organisation-wide read reach without management authority.
     *
     * @param array<string, mixed> $user
     */
    public static function isOrganizationGlobalRead(array $user)
    {
        return in_array(self::normalizeRole($user), self::ORGANIZATION_GLOBAL_READ_ROLES, true);
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
     * Explicit branch grants only (user_subeler), without the branches a company
     * scope resolves to. Needed wherever the *assignment* matters rather than the
     * effective visibility — e.g. proving that a company scope was not
     * materialised into user_subeler.
     *
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function explicitSubeIds(array $user)
    {
        if (isset($user['explicit_sube_ids']) && is_array($user['explicit_sube_ids'])) {
            return self::normalizePositiveIds($user['explicit_sube_ids']);
        }

        return self::allowedSubeIds($user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function allowedSirketIds(array $user)
    {
        return self::normalizePositiveIds(isset($user['sirket_ids']) && is_array($user['sirket_ids']) ? $user['sirket_ids'] : []);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    public static function allowedSgkIsverenIds(array $user)
    {
        return self::normalizePositiveIds(isset($user['sgk_isveren_ids']) && is_array($user['sgk_isveren_ids']) ? $user['sgk_isveren_ids'] : []);
    }

    /** @param array<int, mixed> $ids */
    public static function isSirketScopeEligible($role, array $ids = [])
    {
        return count(self::normalizePositiveIds($ids)) === 0
            || in_array(RolePermissions::normalizeRole((string) $role), self::SIRKET_SCOPE_ELIGIBLE_ROLES, true);
    }

    /** @param array<int, mixed> $ids */
    public static function isSgkScopeEligible($role, array $ids = [])
    {
        return count(self::normalizePositiveIds($ids)) === 0
            || in_array(RolePermissions::normalizeRole((string) $role), self::SGK_SCOPE_ELIGIBLE_ROLES, true);
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

        // İK reach comes from the role, so there is no assignment to require and
        // an empty grant is a valid, fully readable state.
        if (self::isOrganizationGlobalRead($user)) {
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
            // allowedSubeIds already contains the branches a company scope
            // resolves to. An SGK-only grant has no branch axis at all, so it is
            // checked separately rather than being faked as a branch list.
            if (count(self::allowedSubeIds($user)) === 0 && count(self::allowedSgkIsverenIds($user)) === 0) {
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

        // İK sees every branch, so any requested branch is a narrow rather than
        // an escalation, and explicit legacy grants must not shrink the list.
        if (self::isOrganizationGlobalRead($user)) {
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
            // SGK/payroll-only scope has no branch axis; the active branch can
            // only narrow such a user, never authorize them, so it is passed
            // through and the personnel filter keeps confining on sgk_isveren_id.
            if (count(self::allowedSgkIsverenIds($user)) > 0) {
                return $requested;
            }

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

        self::assertMuhasebeBranchAccountingVisibility($user, $requested, null);

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

        // İK: organisation-wide read, optionally narrowed to the active branch.
        // The branchless DIS pool stays visible here for the same reason it is
        // visible to a global role — it belongs to no branch context at all.
        // Writing is the separate, narrower question answered by HrWriteScope.
        if (self::isOrganizationGlobalRead($user)) {
            $scope = self::resolveActiveSubeId($user, $request);
            if ($scope !== null && $subeId > 0 && $subeId !== (int) $scope) {
                JsonResponse::forbidden();
            }
            HrWriteScope::assertPersonelWritable($user, $request, $personelOrg);

            return;
        }

        // Branch-scoped roles (SUBE / MUHASEBE) and optional GY active-sube narrow.

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

        $allowedSube = self::allowedSubeIds($user);
        if (count($allowedSube) === 0 || !in_array($subeId, $allowedSube, true)) {
            // Payroll-employer scope is a second, independent axis: the record may
            // be outside every granted branch and still be inside the granted SGK
            // employer. Read from the personnel row, never guessed from the branch.
            $allowedSgk = self::allowedSgkIsverenIds($user);
            $personelSgkId = 0;
            if (is_array($personelOrg) && isset($personelOrg['sgk_isveren_id'])) {
                $personelSgkId = (int) $personelOrg['sgk_isveren_id'];
            }
            if (count($allowedSgk) === 0 || $personelSgkId <= 0 || !in_array($personelSgkId, $allowedSgk, true)) {
                JsonResponse::forbidden();
            }
        }

        // Branch accounting ACL applies only to MUHASEBE, after existing eligibility.
        if ($subeId > 0) {
            self::assertMuhasebeBranchAccountingVisibility($user, $subeId, $pdo instanceof PDO ? $pdo : null);
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

        // Organisation-wide readers list like an unrestricted user: no branch
        // predicate at all unless an active branch narrows the view. Legacy
        // explicit grants are ignored on purpose so they cannot shrink the list.
        if (self::isOrganizationGlobalRead($user)
            || (self::isUnrestricted($user) && count(self::allowedSubeIds($user)) === 0)
        ) {
            if ($activeSubeScope !== null) {
                if ($assignmentAware) {
                    $key = $paramPrefix . '_active_sube';
                    $params[$key] = (int) $activeSubeScope;
                    $where[] = self::withBranchlessDisKaynak(
                        PersonelGeciciGorevlendirmeService::sqlPersonelMatchesEffectiveSube(
                            $pdo,
                            rtrim($col, '.'),
                            $key,
                            $params,
                            null,
                            $paramPrefix . '_eff'
                        ),
                        $col,
                        $pdo
                    );
                } else {
                    $key = $paramPrefix . '_active_sube';
                    $where[] = self::withBranchlessDisKaynak(
                        $col . 'sube_id = :' . $key,
                        $col,
                        $pdo
                    );
                    $params[$key] = (int) $activeSubeScope;
                }
            }

            return;
        }

        $allowedSube = self::allowedSubeIds($user);
        $allowedSgk = self::allowedSgkIsverenIds($user);

        // The branch axis and the payroll axis are combined with OR, never
        // substituted for one another: an SGK scope resolves straight off
        // personeller.sgk_isveren_id
        // and is never inferred from a physical branch.
        $branchWhere = [];
        self::appendBranchScopedPersonelFilter(
            $branchWhere,
            $params,
            $user,
            $allowedSube,
            $activeSubeScope,
            $col,
            $paramPrefix,
            $pdo,
            $assignmentAware
        );

        if (count($allowedSgk) > 0) {
            $sgkWhere = [];
            self::appendInFilter($sgkWhere, $params, $col . 'sgk_isveren_id', $allowedSgk, $paramPrefix . '_sgk');
            if ($activeSubeScope !== null) {
                $key = $paramPrefix . '_sgk_active_sube';
                $params[$key] = (int) $activeSubeScope;
                $sgkWhere[] = $col . 'sube_id = :' . $key;
            }
            $branchWhere = array_values(array_filter($branchWhere, function ($clause) {
                return $clause !== '1=0';
            }));
            $sgkClause = '(' . implode(' AND ', $sgkWhere) . ')';
            $where[] = count($branchWhere) === 0
                ? $sgkClause
                : '((' . implode(' AND ', $branchWhere) . ') OR ' . $sgkClause . ')';
            self::appendMuhasebeBranchAccountingListFilter($where, $params, $user, $col, $paramPrefix, $pdo);

            return;
        }

        foreach ($branchWhere as $clause) {
            $where[] = $clause;
        }
        self::appendMuhasebeBranchAccountingListFilter($where, $params, $user, $col, $paramPrefix, $pdo);
    }

    /**
     * Branch-axis predicate for branch-scoped roles, extracted so the SGK axis
     * can be OR-combined with it instead of duplicating the assignment-aware logic.
     *
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param array<string, mixed> $user
     * @param array<int, int> $allowedSube
     * @param int|null $activeSubeScope
     */
    private static function appendBranchScopedPersonelFilter(
        array &$where,
        array &$params,
        array $user,
        array $allowedSube,
        $activeSubeScope,
        $col,
        $paramPrefix,
        $pdo,
        $assignmentAware
    ) {
        if (count($allowedSube) === 0) {
            $where[] = '1=0';

            return;
        }

        if ($activeSubeScope !== null) {
            $key = $paramPrefix . '_active_sube';
            $params[$key] = (int) $activeSubeScope;
            // Branch-scoped roles stay confined to the active branch; only an
            // unrestricted user also sees the branchless DIS_KAYNAK records.
            $includeBranchless = self::isUnrestricted($user);
            if ($assignmentAware) {
                $matches = PersonelGeciciGorevlendirmeService::sqlPersonelMatchesEffectiveSube(
                    $pdo,
                    rtrim($col, '.'),
                    $key,
                    $params,
                    null,
                    $paramPrefix . '_eff'
                );
            } else {
                $matches = $col . 'sube_id = :' . $key;
            }
            $where[] = $includeBranchless
                ? self::withBranchlessDisKaynak($matches, $col, $pdo)
                : $matches;

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
     * Widen an active-branch predicate to also match the branchless DIS_KAYNAK
     * records.
     *
     * Migration 076 forbids a placeholder branch for an unassigned DIS_KAYNAK
     * record, so `sube_id IS NULL` is its correct steady state. Without this term
     * such a record belongs to no branch context at all and stays invisible in
     * every one of them. Only DIS_KAYNAK is widened: a branchless IC_PERSONEL is
     * incomplete data, not a valid state, and must not leak into a branch list.
     *
     * Callers decide who gets this — it is only ever applied for an unrestricted
     * user.
     *
     * @param string $activeSubePredicate
     * @param string $col alias with trailing dot, e.g. `p.`
     * @param PDO|null $pdo
     * @return string
     */
    private static function withBranchlessDisKaynak($activeSubePredicate, $col, $pdo)
    {
        // Referencing calisan_kapsami before 066 is ready would break the query;
        // fall back to the plain branch predicate.
        if (!($pdo instanceof PDO) || !PersonelCalisanKapsamSchema::isReady($pdo)) {
            return $activeSubePredicate;
        }

        $branchless = '(' . $col . 'sube_id IS NULL AND ' . $col . "calisan_kapsami = '"
            . PersonelCalisanKapsamService::DIS_KAYNAK . "')";

        return '((' . $activeSubePredicate . ') OR ' . $branchless . ')';
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
     * Branch accounting visibility restriction — MUHASEBE only.
     *
     * Existing company/SGK/branch eligibility is evaluated first by the caller.
     * Relation presence on the branch enables the restriction; empty = disabled.
     * Selected user who later loses eligibility fails closed (role no longer
     * MUHASEBE, or actor not in the selected set).
     *
     * @param array<string, mixed> $user
     * @param PDO|null $pdo
     */
    public static function assertMuhasebeBranchAccountingVisibility(array $user, $subeId, $pdo = null)
    {
        if (self::normalizeRole($user) !== 'MUHASEBE') {
            return;
        }

        $branchId = (int) $subeId;
        if ($branchId <= 0) {
            return;
        }

        $selected = self::resolveMuhasebeYetkiliUserIds($user, $branchId, $pdo);
        if ($selected === null) {
            // Restriction disabled or schema absent → keep existing MUHASEBE behaviour.
            return;
        }

        $actorId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($actorId <= 0 || !in_array($actorId, $selected, true)) {
            JsonResponse::forbidden('Bu subenin muhasebe verileri icin yetkiniz yok.');
        }
    }

    /**
     * List-filter counterpart of assertMuhasebeBranchAccountingVisibility.
     *
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     * @param array<string, mixed> $user
     * @param PDO|null $pdo
     */
    private static function appendMuhasebeBranchAccountingListFilter(
        array &$where,
        array &$params,
        array $user,
        $col,
        $paramPrefix,
        $pdo
    ) {
        if (self::normalizeRole($user) !== 'MUHASEBE') {
            return;
        }

        $map = self::resolveMuhasebeYetkiMap($user, $pdo);
        if (count($map) === 0) {
            return;
        }

        $actorId = isset($user['id']) ? (int) $user['id'] : 0;
        $denied = [];
        foreach ($map as $subeId => $userIds) {
            $branchId = (int) $subeId;
            if ($branchId <= 0) {
                continue;
            }
            $selected = self::normalizePositiveIds(is_array($userIds) ? $userIds : []);
            if (count($selected) === 0) {
                continue;
            }
            if ($actorId <= 0 || !in_array($actorId, $selected, true)) {
                $denied[] = $branchId;
            }
        }

        if (count($denied) === 0) {
            return;
        }

        $placeholders = [];
        foreach (array_values($denied) as $index => $deniedId) {
            $key = $paramPrefix . '_muh_deny_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $deniedId;
        }
        $where[] = '(' . $col . 'sube_id IS NULL OR ' . $col . 'sube_id NOT IN ('
            . implode(', ', $placeholders) . '))';
    }

    /**
     * @param array<string, mixed> $user
     * @param PDO|null $pdo
     * @return array<int, int>|null null = restriction disabled
     */
    private static function resolveMuhasebeYetkiliUserIds(array $user, $subeId, $pdo)
    {
        $branchId = (int) $subeId;
        $map = self::resolveMuhasebeYetkiMap($user, $pdo);
        if (!isset($map[$branchId]) || !is_array($map[$branchId])) {
            return null;
        }

        $selected = self::normalizePositiveIds($map[$branchId]);

        return count($selected) > 0 ? $selected : null;
    }

    /**
     * @param array<string, mixed> $user
     * @param PDO|null $pdo
     * @return array<int, array<int, int>>
     */
    private static function resolveMuhasebeYetkiMap(array $user, $pdo)
    {
        if (isset($user['sube_muhasebe_yetki_map']) && is_array($user['sube_muhasebe_yetki_map'])) {
            $normalized = [];
            foreach ($user['sube_muhasebe_yetki_map'] as $subeId => $userIds) {
                $branchId = (int) $subeId;
                if ($branchId <= 0 || !is_array($userIds)) {
                    continue;
                }
                $ids = self::normalizePositiveIds($userIds);
                if (count($ids) > 0) {
                    $normalized[$branchId] = $ids;
                }
            }

            return $normalized;
        }

        if ($pdo instanceof PDO && SubeMuhasebeYetkiSchema::isReady($pdo)) {
            return SubeMuhasebeYetkiSchema::loadRestrictedSubeUserMap($pdo);
        }

        return [];
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

        // BOLUM org reach is owned by user_bolumler. Optional user_subeler used
        // as formal SGK scope must not confine bolum personnel access.
        $role = self::normalizeRole($user);
        if (in_array($role, self::BOLUM_ASSIGNMENT_ROLES, true)) {
            return;
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
