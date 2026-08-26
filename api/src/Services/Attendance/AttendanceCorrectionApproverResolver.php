<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\UserOrgAssignmentSchema;
use PDO;

/**
 * Canonical attendance-correction approver resolution.
 * PERSONEL → BIRIM_YONETICISI → BOLUM_YONETICISI → GENEL_YONETICI
 * No self-approval. Branch-manager role is not in this attendance-correction hierarchy.
 */
class AttendanceCorrectionApproverResolver
{
    /**
     * @param array<string, mixed> $requesterUser Auth user row (id, rol, ...)
     * @param array<string, mixed> $personelCtx SelfPersonelContext-like (personel_id, birim_id, bolum_id, sube_id)
     * @return array{user_id:int,rol:string,ad_soyad:string}|null
     */
    public static function resolve(PDO $pdo, array $requesterUser, array $personelCtx)
    {
        if (!UserOrgAssignmentSchema::isReady($pdo)) {
            return null;
        }

        $requesterId = isset($requesterUser['id']) ? (int) $requesterUser['id'] : 0;
        $requesterRole = RolePermissions::normalizeRole(isset($requesterUser['rol']) ? (string) $requesterUser['rol'] : '');
        if ($requesterId <= 0 || $requesterRole === '') {
            return null;
        }

        $chain = self::chainForRequesterRole($requesterRole);
        $birimId = isset($personelCtx['birim_id']) && $personelCtx['birim_id'] !== null
            ? (int) $personelCtx['birim_id']
            : 0;
        $bolumId = isset($personelCtx['bolum_id']) && $personelCtx['bolum_id'] !== null
            ? (int) $personelCtx['bolum_id']
            : 0;

        foreach ($chain as $targetRole) {
            $candidate = null;
            if ($targetRole === 'BIRIM_AMIRI') {
                if ($birimId <= 0) {
                    continue;
                }
                $candidate = self::findAssignedManager($pdo, 'BIRIM_AMIRI', 'user_birimler', 'birim_id', $birimId, $requesterId);
            } elseif ($targetRole === 'BOLUM_YONETICISI') {
                if ($bolumId <= 0) {
                    continue;
                }
                $candidate = self::findAssignedManager($pdo, 'BOLUM_YONETICISI', 'user_bolumler', 'bolum_id', $bolumId, $requesterId);
            } elseif ($targetRole === 'GENEL_YONETICI') {
                $candidate = self::findGenelYonetici($pdo, $requesterId);
            }

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function chainForRequesterRole($role)
    {
        $role = RolePermissions::normalizeRole((string) $role);
        if ($role === 'BIRIM_AMIRI') {
            return ['BOLUM_YONETICISI', 'GENEL_YONETICI'];
        }
        if ($role === 'BOLUM_YONETICISI') {
            return ['GENEL_YONETICI'];
        }
        if ($role === 'GENEL_YONETICI' || $role === 'SISTEM_YONETICISI') {
            // No higher independent approver in this chain.
            return [];
        }

        // PERSONEL and other self-service actors start at birim.
        return ['BIRIM_AMIRI', 'BOLUM_YONETICISI', 'GENEL_YONETICI'];
    }

    /**
     * @return array{user_id:int,rol:string,ad_soyad:string}|null
     */
    private static function findAssignedManager(PDO $pdo, $role, $assignmentTable, $assignmentColumn, $orgId, $excludeUserId)
    {
        $allowedTables = [
            'user_birimler' => 'birim_id',
            'user_bolumler' => 'bolum_id',
        ];
        if (!isset($allowedTables[$assignmentTable]) || $allowedTables[$assignmentTable] !== $assignmentColumn) {
            return null;
        }

        $sql = "SELECT u.id, u.rol, u.ad_soyad
                FROM users u
                INNER JOIN {$assignmentTable} a ON a.user_id = u.id
                WHERE u.rol = :rol
                  AND u.durum = 'AKTIF'
                  AND a.{$assignmentColumn} = :org_id
                  AND u.id <> :exclude_id
                ORDER BY u.id ASC
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'rol' => $role,
            'org_id' => (int) $orgId,
            'exclude_id' => (int) $excludeUserId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'user_id' => (int) $row['id'],
            'rol' => RolePermissions::normalizeRole((string) $row['rol']),
            'ad_soyad' => (string) ($row['ad_soyad'] ?? ''),
        ];
    }

    /**
     * @return array{user_id:int,rol:string,ad_soyad:string}|null
     */
    private static function findGenelYonetici(PDO $pdo, $excludeUserId)
    {
        $stmt = $pdo->prepare(
            "SELECT id, rol, ad_soyad
             FROM users
             WHERE rol = 'GENEL_YONETICI'
               AND durum = 'AKTIF'
               AND id <> :exclude_id
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(['exclude_id' => (int) $excludeUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'user_id' => (int) $row['id'],
            'rol' => 'GENEL_YONETICI',
            'ad_soyad' => (string) ($row['ad_soyad'] ?? ''),
        ];
    }
}
