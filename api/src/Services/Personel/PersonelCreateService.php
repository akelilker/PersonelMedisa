<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Shared personel ana-veri create owner (single create + import apply).
 * Does not create salary / bordro / SGK records.
 */
final class PersonelCreateService
{
    /**
     * @param array<string, mixed> $payload Canonical create payload from PersonelCanonicalValidator
     */
    public static function insertPersonel(PDO $pdo, array $payload): int
    {
        // Shared owner must not silently drop explicit org writes on pre-064 / pre-065 schema.
        PersonelOrgLocationSchema::assertReadyForOrgWrite($pdo, $payload);
        PersonelOrgStructureSchema::assertReadyForOrgStructureWrite($pdo, $payload);
        $orgReady = PersonelOrgLocationSchema::isReady($pdo);
        $orgStructReady = PersonelOrgStructureSchema::isReady($pdo);
        $cols = [
            'tc_kimlik_no', 'ad', 'soyad', 'dogum_tarihi', 'telefon', 'acil_durum_kisi', 'acil_durum_telefon',
            'sicil_no', 'ise_giris_tarihi', 'sube_id',
        ];
        $params = [
            'tc_kimlik_no' => $payload['tc_kimlik_no'],
            'ad' => $payload['ad'],
            'soyad' => $payload['soyad'],
            'dogum_tarihi' => $payload['dogum_tarihi'],
            'telefon' => $payload['telefon'],
            'acil_durum_kisi' => $payload['acil_durum_kisi'],
            'acil_durum_telefon' => $payload['acil_durum_telefon'],
            'sicil_no' => $payload['sicil_no'],
            'ise_giris_tarihi' => $payload['ise_giris_tarihi'],
            'sube_id' => $payload['sube_id'],
        ];
        if ($orgReady) {
            $cols[] = 'sgk_isveren_id';
            $cols[] = 'calisma_lokasyonu_id';
            $params['sgk_isveren_id'] = array_key_exists('sgk_isveren_id', $payload)
                ? $payload['sgk_isveren_id']
                : null;
            $params['calisma_lokasyonu_id'] = array_key_exists('calisma_lokasyonu_id', $payload)
                ? $payload['calisma_lokasyonu_id']
                : null;
        }
        $cols[] = 'departman_id';
        $params['departman_id'] = $payload['departman_id'];
        if ($orgStructReady) {
            $cols[] = 'bolum_id';
            $cols[] = 'birim_id';
            $params['bolum_id'] = array_key_exists('bolum_id', $payload) ? $payload['bolum_id'] : null;
            $params['birim_id'] = array_key_exists('birim_id', $payload) ? $payload['birim_id'] : null;
        }
        $cols[] = 'gorev_id';
        $params['gorev_id'] = $payload['gorev_id'];
        if ($orgStructReady) {
            $cols[] = 'pozisyon_id';
            $params['pozisyon_id'] = array_key_exists('pozisyon_id', $payload) ? $payload['pozisyon_id'] : null;
        }
        $cols = array_merge($cols, [
            'personel_tipi_id',
            'bagli_amir_id', 'aktif_durum', 'dogum_yeri', 'kan_grubu', 'ucret_tipi_id', 'maas_tutari', 'prim_kurali_id',
        ]);
        $params['personel_tipi_id'] = $payload['personel_tipi_id'];
        $params['bagli_amir_id'] = $payload['bagli_amir_id'];
        $params['aktif_durum'] = $payload['aktif_durum'];
        $params['dogum_yeri'] = $payload['dogum_yeri'];
        $params['kan_grubu'] = $payload['kan_grubu'];
        $params['ucret_tipi_id'] = $payload['ucret_tipi_id'];
        $params['maas_tutari'] = $payload['maas_tutari'];
        $params['prim_kurali_id'] = $payload['prim_kurali_id'];

        $placeholders = array_map(static function (string $c): string {
            return ':' . $c;
        }, $cols);
        $sql = 'INSERT INTO personeller (' . implode(', ', $cols) . ')
                VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, mixed> $payload */
    public static function validateCreateReferences(PDO $pdo, array $payload): void
    {
        PersonelOrgLocationSchema::assertReadyForOrgWrite($pdo, $payload);
        PersonelOrgStructureSchema::assertReadyForOrgStructureWrite($pdo, $payload);

        if (!self::existsActiveRecord($pdo, 'subeler', (int) $payload['sube_id'])) {
            throw new PersonelValidationException('sube_id', 'Gecersiz sube.');
        }
        if (!self::existsActiveRecord($pdo, 'departmanlar', (int) $payload['departman_id'])) {
            throw new PersonelValidationException('departman_id', 'Gecersiz departman.');
        }
        if (!self::existsActiveRecord($pdo, 'gorevler', (int) $payload['gorev_id'])) {
            throw new PersonelValidationException('gorev_id', 'Gecersiz gorev.');
        }
        if (!self::existsActiveRecord($pdo, 'personel_tipleri', (int) $payload['personel_tipi_id'])) {
            throw new PersonelValidationException('personel_tipi_id', 'Gecersiz personel tipi.');
        }

        if (array_key_exists('sgk_isveren_id', $payload) && $payload['sgk_isveren_id'] !== null) {
            if (!PersonelOrgLocationSchema::existsActiveSgkIsveren($pdo, (int) $payload['sgk_isveren_id'])) {
                throw new PersonelValidationException('sgk_isveren_id', 'Gecersiz SGK isveren.');
            }
        }
        if (array_key_exists('calisma_lokasyonu_id', $payload) && $payload['calisma_lokasyonu_id'] !== null) {
            if (!PersonelOrgLocationSchema::existsActiveCalismaLokasyonu($pdo, (int) $payload['calisma_lokasyonu_id'])) {
                throw new PersonelValidationException('calisma_lokasyonu_id', 'Gecersiz calisma lokasyonu.');
            }
        }

        if (array_key_exists('bolum_id', $payload) && $payload['bolum_id'] !== null) {
            if (!PersonelOrgStructureSchema::existsActiveBolum($pdo, (int) $payload['bolum_id'])) {
                throw new PersonelValidationException('bolum_id', 'Gecersiz bolum.');
            }
        }
        if (array_key_exists('birim_id', $payload) && $payload['birim_id'] !== null) {
            if (!PersonelOrgStructureSchema::existsActiveBirim($pdo, (int) $payload['birim_id'])) {
                throw new PersonelValidationException('birim_id', 'Gecersiz birim.');
            }
        }
        if (array_key_exists('pozisyon_id', $payload) && $payload['pozisyon_id'] !== null) {
            if (!PersonelOrgStructureSchema::existsActivePozisyon($pdo, (int) $payload['pozisyon_id'])) {
                throw new PersonelValidationException('pozisyon_id', 'Gecersiz pozisyon.');
            }
        }

        PersonelOrgStructureSchema::assertHierarchyConsistent($pdo, [
            'departman_id' => $payload['departman_id'] ?? null,
            'bolum_id' => $payload['bolum_id'] ?? null,
            'birim_id' => $payload['birim_id'] ?? null,
            'pozisyon_id' => $payload['pozisyon_id'] ?? null,
        ]);

        $bagliAmirId = $payload['bagli_amir_id'] ?? null;
        if ($bagliAmirId !== null) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => (int) $bagliAmirId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new PersonelValidationException('bagli_amir_id', 'Gecersiz bagli amir.');
            }
        }
    }

    public static function tcExists(PDO $pdo, string $tcKimlikNo): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc_kimlik_no LIMIT 1');
        $stmt->execute(['tc_kimlik_no' => $tcKimlikNo]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function existsActiveRecord(PDO $pdo, string $table, int $id): bool
    {
        $allowed = ['subeler', 'departmanlar', 'gorevler', 'personel_tipleri'];
        if (!in_array($table, $allowed, true) || $id <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function isDuplicateTcException(\PDOException $e): bool
    {
        $message = $e->getMessage();

        return strpos($message, 'uq_personeller_tc') !== false
            || (strpos($message, 'Duplicate') !== false && strpos($message, 'tc_kimlik_no') !== false);
    }
}
