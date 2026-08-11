<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use PDO;

/**
 * Resolve authenticated user → bound personel for self-service reads (S3B).
 * Binding is DB-authoritative (users.personel_id); client personel_id is never trusted.
 */
class SelfPersonelContext
{
    /**
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>|null null only when !$required and unbound/unavailable
     */
    public static function resolveForSelfService(array $authUser, PDO $pdo, $required = true)
    {
        $required = (bool) $required;
        $userId = isset($authUser['id']) ? (int) $authUser['id'] : 0;
        if ($userId <= 0) {
            if ($required) {
                JsonResponse::unauthorized();
            }

            return null;
        }

        if (!UsersSchema::hasPersonelId($pdo)) {
            if ($required) {
                JsonResponse::error(
                    403,
                    'SELF_SERVICE_SCHEMA_NOT_READY',
                    'Self-service personel baglama semasi hazir degil.'
                );
            }

            return null;
        }

        $stmt = $pdo->prepare('SELECT personel_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            if ($required) {
                JsonResponse::unauthorized();
            }

            return null;
        }

        $personelIdRaw = $userRow['personel_id'] ?? null;
        $personelId = ($personelIdRaw === null || $personelIdRaw === '')
            ? null
            : (int) $personelIdRaw;
        if ($personelId === null || $personelId <= 0) {
            if ($required) {
                JsonResponse::error(
                    403,
                    'SELF_SERVICE_BINDING_REQUIRED',
                    'Hesabiniz personel kaydiyla eslestirilmemis.'
                );
            }

            return null;
        }

        $personelStmt = $pdo->prepare(
            'SELECT
                p.id AS personel_id,
                p.ad,
                p.soyad,
                p.sube_id,
                p.departman_id,
                p.gorev_id,
                p.aktif_durum,
                s.ad AS sube_ad,
                d.ad AS departman_ad,
                g.ad AS gorev_ad
             FROM personeller p
             INNER JOIN subeler s ON s.id = p.sube_id
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             LEFT JOIN gorevler g ON g.id = p.gorev_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $personelStmt->execute(['id' => $personelId]);
        $personel = $personelStmt->fetch(PDO::FETCH_ASSOC);
        if (!$personel) {
            if ($required) {
                JsonResponse::error(
                    403,
                    'SELF_SERVICE_PERSONEL_MISSING',
                    'Bagli personel kaydi bulunamadi.'
                );
            }

            return null;
        }

        $aktif = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        if ($aktif !== 'AKTIF') {
            if ($required) {
                JsonResponse::error(
                    403,
                    'SELF_SERVICE_PERSONEL_INACTIVE',
                    'Bagli personel kaydi aktif degil.'
                );
            }

            return null;
        }

        $ad = (string) ($personel['ad'] ?? '');
        $soyad = (string) ($personel['soyad'] ?? '');
        $adSoyad = trim($ad . ' ' . $soyad);

        return [
            'personel_id' => (int) $personel['personel_id'],
            'ad' => $ad,
            'soyad' => $soyad,
            'ad_soyad' => $adSoyad,
            'sube_id' => (int) $personel['sube_id'],
            'sube_ad' => (string) ($personel['sube_ad'] ?? ''),
            'departman_id' => isset($personel['departman_id']) && $personel['departman_id'] !== null
                ? (int) $personel['departman_id']
                : null,
            'departman_ad' => isset($personel['departman_ad']) && $personel['departman_ad'] !== null
                ? (string) $personel['departman_ad']
                : null,
            'gorev_id' => isset($personel['gorev_id']) && $personel['gorev_id'] !== null
                ? (int) $personel['gorev_id']
                : null,
            'gorev_ad' => isset($personel['gorev_ad']) && $personel['gorev_ad'] !== null
                ? (string) $personel['gorev_ad']
                : null,
            'aktif_durum' => $aktif,
        ];
    }
}
