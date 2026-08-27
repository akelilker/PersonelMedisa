<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Kalıcı org + aktif geçici görevlendirmeden operasyonel çalışma bağlamı.
 * Permanent personeller satırını görevlendirme uğruna overwrite etmez.
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
        $personelId = (int) $personelId;
        $empty = self::emptyResult($personelId);
        if ($personelId <= 0) {
            return $empty;
        }

        $row = self::loadPersonelRow($pdo, $personelId);
        if ($row === null) {
            return $empty;
        }

        $kapsam = PersonelCalisanKapsamService::resolveFromRow($row);
        $permanent = [
            'sube_id' => self::nullablePositiveInt($row['sube_id'] ?? null),
            'departman_id' => self::nullablePositiveInt($row['departman_id'] ?? null),
            'bolum_id' => self::nullablePositiveInt($row['bolum_id'] ?? null),
            'birim_id' => self::nullablePositiveInt($row['birim_id'] ?? null),
        ];

        $assignment = PersonelGeciciGorevlendirmeService::findActive($pdo, $personelId);
        if ($assignment !== null) {
            return [
                'personel_id' => $personelId,
                'calisan_kapsami' => $kapsam,
                'has_operational_scope' => true,
                'org_status' => self::ORG_GECICI,
                'source' => self::SOURCE_ASSIGNMENT,
                'permanent' => $permanent,
                'effective' => [
                    'sube_id' => self::nullablePositiveInt($assignment['hedef_sube_id'] ?? null),
                    'departman_id' => self::nullablePositiveInt($assignment['hedef_departman_id'] ?? null),
                    'bolum_id' => self::nullablePositiveInt($assignment['hedef_bolum_id'] ?? null),
                    'birim_id' => self::nullablePositiveInt($assignment['hedef_birim_id'] ?? null),
                ],
                'active_assignment' => $assignment,
            ];
        }

        $isDis = $kapsam === PersonelCalisanKapsamService::DIS_KAYNAK;
        $hasBolumOrBirim = $permanent['bolum_id'] !== null || $permanent['birim_id'] !== null;

        if ($isDis) {
            if ($hasBolumOrBirim) {
                return [
                    'personel_id' => $personelId,
                    'calisan_kapsami' => $kapsam,
                    'has_operational_scope' => true,
                    'org_status' => self::ORG_KALICI,
                    'source' => self::SOURCE_PERMANENT,
                    'permanent' => $permanent,
                    'effective' => $permanent,
                    'active_assignment' => null,
                ];
            }

            return [
                'personel_id' => $personelId,
                'calisan_kapsami' => $kapsam,
                'has_operational_scope' => false,
                'org_status' => self::ORG_BAGLANTISIZ,
                'source' => self::SOURCE_NONE,
                'permanent' => $permanent,
                'effective' => $permanent,
                'active_assignment' => null,
            ];
        }

        // IC_PERSONEL: mevcut davranış — şube bağlamı yeterli.
        $hasIcScope = $permanent['sube_id'] !== null;

        return [
            'personel_id' => $personelId,
            'calisan_kapsami' => $kapsam,
            'has_operational_scope' => $hasIcScope,
            'org_status' => $hasIcScope ? self::ORG_KALICI : self::ORG_BAGLANTISIZ,
            'source' => $hasIcScope ? self::SOURCE_PERMANENT : self::SOURCE_NONE,
            'permanent' => $permanent,
            'effective' => $permanent,
            'active_assignment' => null,
        ];
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
