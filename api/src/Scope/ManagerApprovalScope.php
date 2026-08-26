<?php

declare(strict_types=1);

namespace Medisa\Api\Scope;

use Medisa\Api\Database\UserOrgAssignmentSchema;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use PDO;

/**
 * Canonical BIRIM_AMIRI approval-chain context (user_birimler → birimler → şube relevance).
 * Does not redesign RolePermissions / OrgScope; only BA selection + actor chain checks.
 */
class ManagerApprovalScope
{
    /**
     * Selected BA must be AKTIF BIRIM_AMIRI with user_birimler relevant to requested sube
     * (personnel in assigned birimler at that branch). Never uses user_subeler.
     *
     * @param array<string, mixed>|null $actor when set, also asserts actor may access this BA chain
     */
    public static function assertSelectedBirimAmiriForSube(PDO $pdo, $subeId, $amirUserId, array $actor = null, Request $request = null)
    {
        $subeId = (int) $subeId;
        $amirUserId = (int) $amirUserId;
        if ($subeId <= 0 || $amirUserId <= 0) {
            JsonResponse::forbidden('Secili birim yoneticisi bu sube icin yetkili degil.');
        }

        $context = self::resolveBirimAmiriContextForSube($pdo, $amirUserId, $subeId);
        if ($context === null) {
            JsonResponse::forbidden('Secili birim yoneticisi bu sube icin yetkili degil.');
        }

        if ($actor !== null) {
            if ($request === null) {
                JsonResponse::serverError('ManagerApprovalScope actor assertion requires Request.');
            }
            self::assertActorCanAccessBirimAmiriContext($actor, $request, $subeId, $amirUserId, $context);
        }
    }

    /**
     * @return array{birim_ids: array<int, int>, bolum_ids: array<int, int>}|null
     */
    public static function resolveBirimAmiriContextForSube(PDO $pdo, $amirUserId, $subeId)
    {
        $amirUserId = (int) $amirUserId;
        $subeId = (int) $subeId;
        if ($amirUserId <= 0 || $subeId <= 0 || !UserOrgAssignmentSchema::isReady($pdo)) {
            return null;
        }

        $userStmt = $pdo->prepare(
            "SELECT id FROM users WHERE id = :id AND rol = 'BIRIM_AMIRI' AND durum = 'AKTIF' LIMIT 1"
        );
        $userStmt->execute(['id' => $amirUserId]);
        if (!$userStmt->fetchColumn()) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT DISTINCT ub.birim_id AS birim_id, b.bolum_id AS bolum_id
             FROM user_birimler ub
             INNER JOIN birimler b ON b.id = ub.birim_id
             WHERE ub.user_id = :user_id
               AND EXISTS (
                 SELECT 1
                 FROM personeller p
                 WHERE p.birim_id = ub.birim_id
                   AND p.sube_id = :sube_id
               )
             ORDER BY ub.birim_id ASC'
        );
        $stmt->execute([
            'user_id' => $amirUserId,
            'sube_id' => $subeId,
        ]);

        $birimIds = [];
        $bolumIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $birimId = (int) $row['birim_id'];
            $bolumId = (int) $row['bolum_id'];
            if ($birimId > 0) {
                $birimIds[$birimId] = $birimId;
            }
            if ($bolumId > 0) {
                $bolumIds[$bolumId] = $bolumId;
            }
        }

        if (count($birimIds) === 0) {
            return null;
        }

        $birimList = array_values($birimIds);
        $bolumList = array_values($bolumIds);
        sort($birimList);
        sort($bolumList);

        return [
            'birim_ids' => $birimList,
            'bolum_ids' => $bolumList,
        ];
    }

    /**
     * BA picker options for a branch — active BIRIM_AMIRI with canonical unit presence in sube.
     *
     * @return array<int, array{user_id: int, ad_soyad: string, sube_id: int}>
     */
    public static function listBirimAmiriOptionsForSube(PDO $pdo, $subeId)
    {
        $subeId = (int) $subeId;
        if ($subeId <= 0 || !UserOrgAssignmentSchema::isReady($pdo)) {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT u.id AS user_id, u.ad_soyad
             FROM users u
             INNER JOIN user_birimler ub ON ub.user_id = u.id
             WHERE u.rol = 'BIRIM_AMIRI'
               AND u.durum = 'AKTIF'
               AND EXISTS (
                 SELECT 1
                 FROM personeller p
                 WHERE p.birim_id = ub.birim_id
                   AND p.sube_id = :sube_id
               )
             ORDER BY u.ad_soyad ASC, u.id ASC"
        );
        $stmt->execute(['sube_id' => $subeId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'user_id' => (int) $row['user_id'],
                'ad_soyad' => (string) $row['ad_soyad'],
                'sube_id' => $subeId,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $user
     * @param array{birim_ids: array<int, int>, bolum_ids: array<int, int>} $context
     */
    public static function assertActorCanAccessBirimAmiriContext(
        array $user,
        Request $request,
        $subeId,
        $amirUserId,
        array $context
    ) {
        $role = OrgScope::normalizeRole($user);
        $subeId = (int) $subeId;
        $amirUserId = (int) $amirUserId;

        if ($role === '' || $role === 'PERSONEL') {
            JsonResponse::forbidden();
        }

        OrgScope::assertRequiredAssignment($user);

        if (OrgScope::isUnrestricted($user)) {
            $active = OrgScope::resolveActiveSubeId($user, $request);
            if ($active !== null && (int) $active !== $subeId) {
                JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
            }

            return;
        }

        if (in_array($role, OrgScope::SUBE_ASSIGNMENT_ROLES, true)) {
            $allowed = OrgScope::allowedSubeIds($user);
            if (!in_array($subeId, $allowed, true)) {
                JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
            }
            $active = OrgScope::resolveActiveSubeId($user, $request);
            if ($active !== null && (int) $active !== $subeId) {
                JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
            }

            return;
        }

        if (in_array($role, OrgScope::BOLUM_ASSIGNMENT_ROLES, true)) {
            $actorBolum = OrgScope::allowedBolumIds($user);
            $chainBolum = isset($context['bolum_ids']) && is_array($context['bolum_ids'])
                ? $context['bolum_ids']
                : [];
            if (count(array_intersect($actorBolum, $chainBolum)) === 0) {
                JsonResponse::forbidden();
            }

            return;
        }

        if (in_array($role, OrgScope::BIRIM_ASSIGNMENT_ROLES, true)) {
            $actorId = isset($user['id']) ? (int) $user['id'] : 0;
            if ($actorId > 0 && $actorId === $amirUserId) {
                return;
            }
            $actorBirim = OrgScope::allowedBirimIds($user);
            $chainBirim = isset($context['birim_ids']) && is_array($context['birim_ids'])
                ? $context['birim_ids']
                : [];
            if (count(array_intersect($actorBirim, $chainBirim)) === 0) {
                JsonResponse::forbidden();
            }

            return;
        }

        JsonResponse::forbidden();
    }

    /**
     * Convenience: resolve BA context for sube then assert actor access.
     *
     * @param array<string, mixed> $user
     */
    public static function assertActorCanAccessBirimAmiriChain(
        array $user,
        Request $request,
        PDO $pdo,
        $subeId,
        $amirUserId
    ) {
        self::assertSelectedBirimAmiriForSube($pdo, $subeId, $amirUserId, $user, $request);
    }

    /**
     * Load personel org fragment and assert via OrgScope (unit-aware).
     *
     * @param array<string, mixed> $user
     */
    public static function assertActorCanAccessPersonelId(array $user, Request $request, PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::forbidden();
        }

        $stmt = $pdo->prepare(
            'SELECT id, sube_id, bolum_id, birim_id FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::forbidden();
        }

        OrgScope::assertPersonelAccess($user, $request, [
            'id' => (int) $row['id'],
            'sube_id' => (int) $row['sube_id'],
            'bolum_id' => array_key_exists('bolum_id', $row) && $row['bolum_id'] !== null && $row['bolum_id'] !== ''
                ? (int) $row['bolum_id']
                : null,
            'birim_id' => array_key_exists('birim_id', $row) && $row['birim_id'] !== null && $row['birim_id'] !== ''
                ? (int) $row['birim_id']
                : null,
        ]);
    }

    /**
     * Enrich report filters with canonical unit ids for BY/BA (never authorize whole branch).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function enrichReportFiltersForActor(array $user, array $filters)
    {
        $role = OrgScope::normalizeRole($user);
        OrgScope::assertRequiredAssignment($user);

        if (in_array($role, OrgScope::BOLUM_ASSIGNMENT_ROLES, true)) {
            $ids = OrgScope::allowedBolumIds($user);
            if (count($ids) === 0) {
                JsonResponse::forbidden('Bolum kapsami atanmamis.');
            }
            $filters['bolum_ids'] = $ids;
        }

        if (in_array($role, OrgScope::BIRIM_ASSIGNMENT_ROLES, true)) {
            $ids = OrgScope::allowedBirimIds($user);
            if (count($ids) === 0) {
                JsonResponse::forbidden('Birim kapsami atanmamis.');
            }
            $filters['birim_ids'] = $ids;
        }

        return $filters;
    }
}
