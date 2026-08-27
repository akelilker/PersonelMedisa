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

        $personel = self::loadPersonelRow($pdo, $personelId);
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

        $subeId = isset($personel['sube_id']) && $personel['sube_id'] !== null && (int) $personel['sube_id'] > 0
            ? (int) $personel['sube_id']
            : null;
        $departmanId = isset($personel['departman_id']) && $personel['departman_id'] !== null
            ? (int) $personel['departman_id']
            : null;
        $bolumId = isset($personel['bolum_id']) && $personel['bolum_id'] !== null
            ? (int) $personel['bolum_id']
            : null;
        $birimId = isset($personel['birim_id']) && $personel['birim_id'] !== null
            ? (int) $personel['birim_id']
            : null;
        $subeAd = (string) ($personel['sube_ad'] ?? '');
        $departmanAd = isset($personel['departman_ad']) && $personel['departman_ad'] !== null
            ? (string) $personel['departman_ad']
            : null;
        $bolumAd = isset($personel['bolum_ad']) && $personel['bolum_ad'] !== null
            ? (string) $personel['bolum_ad']
            : null;
        $birimAd = isset($personel['birim_ad']) && $personel['birim_ad'] !== null
            ? (string) $personel['birim_ad']
            : null;

        // Aktif geçici görevlendirme effective org'u override eder (permanent overwrite yok).
        $opCtx = \Medisa\Api\Services\Personel\PersonelOperationalContextService::resolve($pdo, $personelId);
        if ($opCtx['source'] === \Medisa\Api\Services\Personel\PersonelOperationalContextService::SOURCE_ASSIGNMENT) {
            $eff = $opCtx['effective'];
            $subeId = $eff['sube_id'];
            $departmanId = $eff['departman_id'];
            $bolumId = $eff['bolum_id'];
            $birimId = $eff['birim_id'];
            if ($subeId !== null) {
                try {
                    $s = $pdo->prepare('SELECT ad FROM subeler WHERE id = :id LIMIT 1');
                    $s->execute(['id' => $subeId]);
                    $subeAd = (string) ($s->fetchColumn() ?: '');
                } catch (\Throwable $e) {
                    $subeAd = '';
                }
            }
        }

        return [
            'personel_id' => (int) $personel['personel_id'],
            'ad' => $ad,
            'soyad' => $soyad,
            'ad_soyad' => $adSoyad,
            'sube_id' => $subeId !== null ? $subeId : 0,
            'sube_ad' => $subeAd,
            'departman_id' => $departmanId,
            'departman_ad' => $departmanAd,
            'bolum_id' => $bolumId,
            'bolum_ad' => $bolumAd,
            'birim_id' => $birimId,
            'birim_ad' => $birimAd,
            'gorev_id' => isset($personel['gorev_id']) && $personel['gorev_id'] !== null
                ? (int) $personel['gorev_id']
                : null,
            'gorev_ad' => isset($personel['gorev_ad']) && $personel['gorev_ad'] !== null
                ? (string) $personel['gorev_ad']
                : null,
            'aktif_durum' => $aktif,
            'sicil_no' => $personel['sicil_no'] ?? null,
            'tc_kimlik_no' => $personel['tc_kimlik_no'] ?? null,
            'dogum_tarihi' => $personel['dogum_tarihi'] ?? null,
            'telefon' => $personel['telefon'] ?? null,
            'ise_giris_tarihi' => $personel['ise_giris_tarihi'] ?? null,
            'personel_tipi_id' => isset($personel['personel_tipi_id']) && $personel['personel_tipi_id'] !== null
                ? (int) $personel['personel_tipi_id']
                : null,
            'calisan_kapsami' => $personel['calisan_kapsami'] ?? null,
            'org_status' => $opCtx['org_status'],
            'operational_scope' => $opCtx,
        ];
    }

    /**
     * Prefer org-enriched row; fall back to baseline when bolum/birim schema absent.
     *
     * @return array<string, mixed>|null
     */
    private static function loadPersonelRow(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        $queries = [
            'SELECT
                p.id AS personel_id,
                p.ad,
                p.soyad,
                p.sube_id,
                p.departman_id,
                p.bolum_id,
                p.birim_id,
                p.gorev_id,
                p.aktif_durum,
                p.sicil_no,
                p.tc_kimlik_no,
                p.dogum_tarihi,
                p.telefon,
                p.ise_giris_tarihi,
                p.personel_tipi_id,
                p.calisan_kapsami,
                s.ad AS sube_ad,
                d.ad AS departman_ad,
                b.ad AS bolum_ad,
                bi.ad AS birim_ad,
                g.ad AS gorev_ad
             FROM personeller p
             LEFT JOIN subeler s ON s.id = p.sube_id
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             LEFT JOIN bolumler b ON b.id = p.bolum_id
             LEFT JOIN birimler bi ON bi.id = p.birim_id
             LEFT JOIN gorevler g ON g.id = p.gorev_id
             WHERE p.id = :id
             LIMIT 1',
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
             LEFT JOIN subeler s ON s.id = p.sube_id
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             LEFT JOIN gorevler g ON g.id = p.gorev_id
             WHERE p.id = :id
             LIMIT 1',
        ];

        foreach ($queries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $personelId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return is_array($row) ? $row : null;
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }
}
