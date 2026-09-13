<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use PDO;

/**
 * Canonical non-org personel update owner (generic PUT subset without org tracked fields).
 */
final class PersonelBasicUpdateService
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload Validated update fields only
     * @return array<string, mixed> Updated row subset
     */
    public static function apply(
        PDO $pdo,
        array $user,
        Request $request,
        int $personelId,
        array $payload
    ): array {
        $current = self::fetchRow($pdo, $personelId);
        if ($current === null) {
            throw new PersonelValidationException('personel_id', 'Personel bulunamadi.');
        }

        PersonelArchiveGate::assertBusinessWriteAllowed($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, $current, $pdo);

        if (array_key_exists('aktif_durum', $payload)
            && (string) $payload['aktif_durum'] !== (string) $current['aktif_durum']
        ) {
            throw new PersonelValidationException(
                'aktif_durum',
                'Aktif durum bu owner ile degistirilemez.'
            );
        }

        try {
            PersonelOrganizasyonDegisikligiService::assertNotChangedViaGenericPut($current, $payload);
        } catch (OrganizasyonException $e) {
            throw new PersonelValidationException(
                $e->field ?? 'organizasyon',
                $e->getMessage(),
                $e->errorCode
            );
        }

        if (array_key_exists('sube_id', $payload)
            && (int) ($payload['sube_id'] ?? 0) !== (int) ($current['sube_id'] ?? 0)
        ) {
            throw new PersonelValidationException('sube_id', 'Kalici sube degisikligi canonical owner gerektirir.');
        }
        $payload = PersonelOrganizasyonDegisikligiService::stripProtectedOrgFieldsFromGenericPut($payload);

        // DIS_KAYNAK artık SGK/bordro kaynağını taşıyabilir (şirketten bağımsız
        // eksen). sgk_isveren_id zaten bu owner'ın allowedColumns'unda değildir;
        // organizasyon ekseni canonical org owner'ında yönetilir.

        self::validateReferences($pdo, $payload, $personelId);

        if (count($payload) === 0) {
            return $current;
        }

        $set = [];
        $params = ['id' => $personelId];
        foreach ($payload as $column => $value) {
            if (!self::isAllowedColumn($column)) {
                continue;
            }
            $set[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }
        if (count($set) === 0) {
            return $current;
        }

        $sql = 'UPDATE personeller SET ' . implode(', ', $set) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $updated = self::fetchRow($pdo, $personelId);

        return is_array($updated) ? $updated : $current;
    }

    /** @return list<string> */
    public static function allowedColumns(): array
    {
        return [
            'tc_kimlik_no',
            'ad',
            'soyad',
            'dogum_tarihi',
            'telefon',
            'acil_durum_kisi',
            'acil_durum_telefon',
            'sicil_no',
            'ise_giris_tarihi',
            'bagli_amir_id',
            'calisan_kapsami',
            'personel_tipi_id',
            'dogum_yeri',
            'kan_grubu',
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function assertPlanReferences(PDO $pdo, array $payload, int $personelId): void
    {
        self::validateReferences($pdo, $payload, $personelId);
    }

    private static function isAllowedColumn(string $column): bool
    {
        return in_array($column, self::allowedColumns(), true);
    }

    /** @param array<string, mixed> $payload */
    private static function validateReferences(PDO $pdo, array $payload, int $personelId): void
    {
        if (array_key_exists('bagli_amir_id', $payload) && $payload['bagli_amir_id'] !== null) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => (int) $payload['bagli_amir_id']]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new PersonelValidationException('bagli_amir_id', 'Gecersiz bagli amir.');
            }
        }
        if (array_key_exists('tc_kimlik_no', $payload) && $payload['tc_kimlik_no'] !== null && $payload['tc_kimlik_no'] !== '') {
            $tc = trim((string) $payload['tc_kimlik_no']);
            $stmt = $pdo->prepare(
                'SELECT id FROM personeller WHERE tc_kimlik_no = :tc AND id <> :id LIMIT 1'
            );
            $stmt->execute(['tc' => $tc, 'id' => $personelId]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new PersonelValidationException('tc_kimlik_no', 'Bu T.C. Kimlik No baska personelde kayitli.');
            }
        }
        if (array_key_exists('personel_tipi_id', $payload) && $payload['personel_tipi_id'] !== null) {
            if (!PersonelCreateService::existsActiveRecord($pdo, 'personel_tipleri', (int) $payload['personel_tipi_id'])) {
                throw new PersonelValidationException('personel_tipi_id', 'Gecersiz personel tipi.');
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function fetchRow(PDO $pdo, int $personelId): ?array
    {
        $cols = PersonelOrganizasyonDegisikligiService::TRACKED_FIELDS;
        $cols = array_merge(
            ['id', 'sube_id', 'aktif_durum', 'calisan_kapsami', 'ad', 'soyad'],
            $cols
        );
        $cols = array_values(array_unique($cols));
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM personeller WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
