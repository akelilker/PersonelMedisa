<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Kalıcı org + geçici görevlendirmeden operasyonel çalışma bağlamı.
 * Permanent personeller satırını görevlendirme uğruna overwrite etmez.
 *
 * Canonical owner: resolveNow / resolveAt.
 * Öncelik: aktif geçici görevlendirme → yoksa permanent org.
 */
final class PersonelOperationalContextService
{
    public const SOURCE_NONE = 'NONE';
    public const SOURCE_PERMANENT = 'PERMANENT';
    public const SOURCE_ASSIGNMENT = 'ASSIGNMENT';

    public const ORG_BAGLANTISIZ = 'BAGLANTISIZ';
    public const ORG_KALICI = 'KALICI_ORGANIZASYON';
    public const ORG_GECICI = 'AKTIF_GECICI_GOREVLENDIRME';

    /**
     * @return array{
     *   personel_id: int,
     *   calisan_kapsami: string,
     *   has_operational_scope: bool,
     *   org_status: string,
     *   source: string,
     *   permanent: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   effective: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   active_assignment: ?array<string,mixed>
     * }
     */
    public static function resolve(PDO $pdo, $personelId): array
    {
        return self::resolveNow($pdo, $personelId);
    }

    /**
     * Current business-time effective org.
     *
     * @return array{
     *   personel_id: int,
     *   calisan_kapsami: string,
     *   has_operational_scope: bool,
     *   org_status: string,
     *   source: string,
     *   permanent: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   effective: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   active_assignment: ?array<string,mixed>
     * }
     */
    public static function resolveNow(PDO $pdo, $personelId): array
    {
        $personelId = (int) $personelId;
        $empty = self::emptyResult($personelId);
        if ($personelId <= 0) {
            return $empty;
        }
        $row = self::loadPersonelRow($pdo, $personelId);
        if ($row === null) {
            return $empty;
        }
        $assignment = PersonelGeciciGorevlendirmeService::findActive($pdo, $personelId);

        return self::buildResult($personelId, $row, $assignment);
    }

    /**
     * Historical effective org (assignment-aware).
     * $at: UTC QR timestamp veya Istanbul business datetime.
     *
     * @param mixed $at
     * @return array{
     *   personel_id: int,
     *   calisan_kapsami: string,
     *   has_operational_scope: bool,
     *   org_status: string,
     *   source: string,
     *   permanent: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   effective: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   active_assignment: ?array<string,mixed>
     * }
     */
    public static function resolveAt(PDO $pdo, $personelId, $at): array
    {
        $personelId = (int) $personelId;
        $empty = self::emptyResult($personelId);
        if ($personelId <= 0) {
            return $empty;
        }
        $row = self::loadPersonelRow($pdo, $personelId);
        if ($row === null) {
            return $empty;
        }
        $assignment = PersonelGeciciGorevlendirmeService::findCoveringAt($pdo, $personelId, $at, false);

        return self::buildResult($personelId, $row, $assignment);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $assignment
     * @return array{
     *   personel_id: int,
     *   calisan_kapsami: string,
     *   has_operational_scope: bool,
     *   org_status: string,
     *   source: string,
     *   permanent: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   effective: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   active_assignment: ?array<string,mixed>
     * }
     */
    private static function buildResult($personelId, array $row, $assignment): array
    {
        $kapsam = PersonelCalisanKapsamService::resolveFromRow($row);
        $permanent = [
            'sube_id' => self::nullablePositiveInt($row['sube_id'] ?? null),
            'departman_id' => self::nullablePositiveInt($row['departman_id'] ?? null),
            'bolum_id' => self::nullablePositiveInt($row['bolum_id'] ?? null),
            'birim_id' => self::nullablePositiveInt($row['birim_id'] ?? null),
        ];

        if (is_array($assignment)) {
            $effective = [
                'sube_id' => self::nullablePositiveInt($assignment['hedef_sube_id'] ?? null),
                'departman_id' => self::nullablePositiveInt($assignment['hedef_departman_id'] ?? null),
                'bolum_id' => self::nullablePositiveInt($assignment['hedef_bolum_id'] ?? null),
                'birim_id' => self::nullablePositiveInt($assignment['hedef_birim_id'] ?? null),
            ];

            return [
                'personel_id' => (int) $personelId,
                'calisan_kapsami' => $kapsam,
                'has_operational_scope' => self::isOperationallyReady($effective),
                'org_status' => self::ORG_GECICI,
                'source' => self::SOURCE_ASSIGNMENT,
                'permanent' => $permanent,
                'effective' => $effective,
                'active_assignment' => $assignment,
            ];
        }

        $isDis = $kapsam === PersonelCalisanKapsamService::DIS_KAYNAK;
        $effective = $permanent;
        $hasBolumOrBirim = $permanent['bolum_id'] !== null || $permanent['birim_id'] !== null;

        if ($isDis) {
            if ($hasBolumOrBirim || $permanent['sube_id'] !== null) {
                return [
                    'personel_id' => (int) $personelId,
                    'calisan_kapsami' => $kapsam,
                    'has_operational_scope' => self::isOperationallyReady($effective),
                    'org_status' => self::isOperationallyReady($effective) || $hasBolumOrBirim
                        ? self::ORG_KALICI
                        : self::ORG_BAGLANTISIZ,
                    'source' => self::SOURCE_PERMANENT,
                    'permanent' => $permanent,
                    'effective' => $effective,
                    'active_assignment' => null,
                ];
            }

            return [
                'personel_id' => (int) $personelId,
                'calisan_kapsami' => $kapsam,
                'has_operational_scope' => false,
                'org_status' => self::ORG_BAGLANTISIZ,
                'source' => self::SOURCE_NONE,
                'permanent' => $permanent,
                'effective' => $effective,
                'active_assignment' => null,
            ];
        }

        $hasIcScope = self::isOperationallyReady($effective);

        return [
            'personel_id' => (int) $personelId,
            'calisan_kapsami' => $kapsam,
            'has_operational_scope' => $hasIcScope,
            'org_status' => $hasIcScope ? self::ORG_KALICI : self::ORG_BAGLANTISIZ,
            'source' => $hasIcScope ? self::SOURCE_PERMANENT : self::SOURCE_NONE,
            'permanent' => $permanent,
            'effective' => $effective,
            'active_assignment' => null,
        ];
    }

    /**
     * Effective org fragment for OrgScope assert (assignment-aware when 076 ready).
     *
     * @param array<string, mixed> $personelRow
     * @return array<string, mixed>
     */
    public static function effectiveOrgForAccess(PDO $pdo, array $personelRow): array
    {
        $id = isset($personelRow['id']) ? (int) $personelRow['id'] : 0;
        if ($id <= 0) {
            return $personelRow;
        }
        $ctx = self::resolveNow($pdo, $id);
        $eff = $ctx['effective'];

        return array_merge($personelRow, [
            'sube_id' => $eff['sube_id'],
            'departman_id' => $eff['departman_id'],
            'bolum_id' => $eff['bolum_id'],
            'birim_id' => $eff['birim_id'],
            'org_status' => $ctx['org_status'],
            'operational_source' => $ctx['source'],
        ]);
    }

    /**
     * @param array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int} $effective
     */
    private static function isOperationallyReady(array $effective): bool
    {
        return isset($effective['sube_id']) && $effective['sube_id'] !== null && (int) $effective['sube_id'] > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadPersonelRow(PDO $pdo, $personelId): ?array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, calisan_kapsami, sube_id, departman_id, bolum_id, birim_id
                 FROM personeller WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => (int) $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, sube_id, departman_id FROM personeller WHERE id = :id LIMIT 1'
                );
                $stmt->execute(['id' => (int) $personelId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return is_array($row) ? $row : null;
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }

    /**
     * @return array{
     *   personel_id: int,
     *   calisan_kapsami: string,
     *   has_operational_scope: bool,
     *   org_status: string,
     *   source: string,
     *   permanent: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   effective: array{sube_id:?int,departman_id:?int,bolum_id:?int,birim_id:?int},
     *   active_assignment: ?array<string,mixed>
     * }
     */
    private static function emptyResult($personelId): array
    {
        return [
            'personel_id' => (int) $personelId,
            'calisan_kapsami' => PersonelCalisanKapsamService::IC_PERSONEL,
            'has_operational_scope' => false,
            'org_status' => self::ORG_BAGLANTISIZ,
            'source' => self::SOURCE_NONE,
            'permanent' => [
                'sube_id' => null,
                'departman_id' => null,
                'bolum_id' => null,
                'birim_id' => null,
            ],
            'effective' => [
                'sube_id' => null,
                'departman_id' => null,
                'bolum_id' => null,
                'birim_id' => null,
            ],
            'active_assignment' => null,
        ];
    }

    /** @param mixed $value */
    private static function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
