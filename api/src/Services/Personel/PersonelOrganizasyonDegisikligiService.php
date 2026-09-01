<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\HrWriteScope;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use PDO;

/**
 * Canonical owner for audited personnel organisation field changes
 * (gorev/unvan, departman, bolum, birim, pozisyon, SGK isveren, calisma lokasyonu).
 * Permanent branch moves use PersonelKaliciSubeDegisikligiService instead.
 */
final class PersonelOrganizasyonDegisikligiService
{
    public const ERROR_STALE = 'PERSONEL_ORGANIZASYON_STALE_PREIMAGE';
    public const ERROR_NO_CHANGE = 'PERSONEL_ORGANIZASYON_NO_CHANGE';
    public const ERROR_FORBIDDEN = 'PERSONEL_ORGANIZASYON_FORBIDDEN';
    public const ERROR_GENERIC_PUT = 'PERSONEL_ORGANIZASYON_CANONICAL_OWNER_REQUIRED';

    /** @var list<string> */
    public const TRACKED_FIELDS = [
        'gorev_id',
        'departman_id',
        'bolum_id',
        'birim_id',
        'pozisyon_id',
        'sgk_isveren_id',
        'calisma_lokasyonu_id',
    ];

    private const GEREKCE_MIN = 10;
    private const GEREKCE_MAX = 500;

    /**
     * Generic PUT must not change organisation fields — use this owner.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $payload
     */
    public static function assertNotChangedViaGenericPut(array $current, array $payload): void
    {
        foreach (self::TRACKED_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $before = self::normalizeFieldValue($field, $current[$field] ?? null);
            $after = self::normalizeFieldValue($field, $payload[$field]);
            if ($before !== $after) {
                throw new OrganizasyonException(
                    409,
                    self::ERROR_GENERIC_PUT,
                    'Organizasyon alanı değişiklikleri yalnızca denetimli organizasyon-degisikligi yolundan yapılabilir.',
                    $field
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body Expected preimage + new values + gerekce
     * @return array{replay:bool, personel_id:int, olay_tipi:string, audit_id:int|null, degisen_alanlar:list<string>}
     */
    public static function apply(
        PDO $pdo,
        array $user,
        Request $request,
        int $personelId,
        array $body,
        OrganizasyonAuditContext $auditContext,
        ?callable $claimIdempotency = null,
        ?callable $completeIdempotency = null
    ): array {
        if (!RolePermissionsBridge::canWritePersonel($user)) {
            throw new OrganizasyonException(403, self::ERROR_FORBIDDEN, 'Organizasyon değişikliği yetkiniz yok.');
        }

        OrganizasyonAuditWriter::assertPersonelOrganizasyonReady($pdo);

        $gerekce = self::parseGerekce($body);
        $preimage = self::parsePreimage($body);
        $targets = self::parseTargets($body);

        $pdo->beginTransaction();
        try {
            if ($claimIdempotency !== null) {
                $replay = $claimIdempotency($pdo);
                if (is_array($replay)) {
                    $pdo->commit();

                    return [
                        'replay' => true,
                        'personel_id' => $personelId,
                        'olay_tipi' => '',
                        'audit_id' => null,
                        'degisen_alanlar' => [],
                    ];
                }
            }

            $current = self::loadPersonelOrgRow($pdo, $personelId);
            if ($current === null) {
                throw OrganizasyonException::notFound('Personel bulunamadı.');
            }

            OrgScope::assertPersonelAccess($user, $request, [
                'sube_id' => $current['sube_id'],
                'bolum_id' => $current['bolum_id'] ?? null,
                'birim_id' => $current['birim_id'] ?? null,
            ], $pdo);
            HrWriteScope::assertPersonelWritable($user, $request, $current);

            foreach ($preimage as $field => $expected) {
                $actual = self::normalizeFieldValue($field, $current[$field] ?? null);
                if ($actual !== self::normalizeFieldValue($field, $expected)) {
                    throw new OrganizasyonException(
                        409,
                        self::ERROR_STALE,
                        'Personel organizasyon önizlemesi güncel değil; yeniden dry-run çalıştırın.',
                        $field
                    );
                }
            }

            $changes = [];
            $oldValues = [];
            $newValues = [];
            foreach ($targets as $field => $newVal) {
                $before = self::normalizeFieldValue($field, $current[$field] ?? null);
                $after = self::normalizeFieldValue($field, $newVal);
                if ($before === $after) {
                    continue;
                }
                self::validateReference($pdo, $field, $after);
                $changes[] = $field;
                $oldValues[$field] = $before;
                $newValues[$field] = $after;
            }

            if (count($changes) === 0) {
                throw new OrganizasyonException(
                    422,
                    self::ERROR_NO_CHANGE,
                    'Organizasyon alanlarında değişiklik yok.'
                );
            }

            $olayTipi = self::resolveOlayTipi($changes);
            $setParts = [];
            $params = ['id' => $personelId];
            foreach ($newValues as $field => $value) {
                $setParts[] = $field . ' = :' . $field;
                $params[$field] = $value;
            }
            $stmt = $pdo->prepare(
                'UPDATE personeller SET ' . implode(', ', $setParts) . ' WHERE id = :id'
            );
            $stmt->execute($params);

            $auditId = OrganizasyonAuditWriter::recordPersonelOrganizasyonDegisikligi(
                $pdo,
                [
                    'personel_id' => $personelId,
                    'olay_tipi' => $olayTipi,
                    'degisen_alanlar' => $changes,
                    'eski_degerler' => $oldValues,
                    'yeni_degerler' => $newValues,
                    'gerekce' => $gerekce,
                ],
                $auditContext
            );

            if ($completeIdempotency !== null) {
                $completeIdempotency($pdo);
            }

            $pdo->commit();

            return [
                'replay' => false,
                'personel_id' => $personelId,
                'olay_tipi' => $olayTipi,
                'audit_id' => $auditId,
                'degisen_alanlar' => $changes,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<string> $changes */
    private static function resolveOlayTipi(array $changes): string
    {
        $onlyGorev = count($changes) === 1 && $changes[0] === 'gorev_id';
        if ($onlyGorev) {
            return 'GOREV_UNVAN_DEGISIKLIGI';
        }
        $onlyLoc = count($changes) === 1 && $changes[0] === 'calisma_lokasyonu_id';
        if ($onlyLoc) {
            return 'CALISMA_LOKASYONU_DEGISIKLIGI';
        }
        $onlySgk = count($changes) === 1 && $changes[0] === 'sgk_isveren_id';
        if ($onlySgk) {
            return 'SGK_ISVERENI_DEGISIKLIGI';
        }

        return 'DEPARTMAN_BOLUM_BIRIM_POZISYON_DEGISIKLIGI';
    }

    /** @return array<string, mixed>|null */
    private static function loadPersonelOrgRow(PDO $pdo, int $personelId): ?array
    {
        $cols = ['id', 'sube_id', 'departman_id', 'gorev_id'];
        if (PersonelOrgStructureSchema::isReady($pdo)) {
            $cols = array_merge($cols, ['bolum_id', 'birim_id', 'pozisyon_id']);
        }
        if (PersonelOrgLocationSchema::isReady($pdo)) {
            $cols = array_merge($cols, ['sgk_isveren_id', 'calisma_lokasyonu_id']);
        }
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM personeller WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function parsePreimage(array $body): array
    {
        $pre = $body['preimage'] ?? $body['beklenen'] ?? null;
        if (!is_array($pre)) {
            throw OrganizasyonException::validation('Organizasyon önizleme (preimage) zorunludur.', 'preimage');
        }
        $out = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if (array_key_exists($field, $pre)) {
                $out[$field] = $pre[$field];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private static function parseTargets(array $body): array
    {
        $targets = $body['yeni'] ?? $body['targets'] ?? null;
        if (!is_array($targets)) {
            throw OrganizasyonException::validation('Yeni organizasyon değerleri zorunludur.', 'yeni');
        }
        $out = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if (array_key_exists($field, $targets)) {
                $out[$field] = $targets[$field];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $body */
    private static function parseGerekce(array $body): string
    {
        $gerekce = trim((string) ($body['gerekce'] ?? ''));
        if (strlen($gerekce) < self::GEREKCE_MIN) {
            throw OrganizasyonException::validation(
                'Gerekçe en az ' . self::GEREKCE_MIN . ' karakter olmalıdır.',
                'gerekce'
            );
        }

        return $gerekce;
    }

    /** @param mixed $value */
    private static function normalizeFieldValue(string $field, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** @param mixed $value */
    private static function validateReference(PDO $pdo, string $field, $value): void
    {
        if ($value === null) {
            return;
        }
        $tableMap = [
            'gorev_id' => 'gorevler',
            'departman_id' => 'departmanlar',
            'bolum_id' => 'bolumler',
            'birim_id' => 'birimler',
            'pozisyon_id' => 'pozisyonlar',
            'sgk_isveren_id' => 'sgk_isverenler',
            'calisma_lokasyonu_id' => 'calisma_lokasyonlari',
        ];
        if (!isset($tableMap[$field])) {
            return;
        }
        $table = $tableMap[$field];
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int) $value]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw OrganizasyonException::validation('Geçersiz referans: ' . $field, $field);
        }
    }
}

/**
 * Thin bridge to avoid circular imports with RolePermissions in service layer tests.
 */
final class RolePermissionsBridge
{
    /** @param array<string, mixed> $user */
    public static function canWritePersonel(array $user): bool
    {
        return \Medisa\Api\Auth\RolePermissions::has($user, 'personeller.update')
            || in_array(
                OrgScope::normalizeRole($user),
                ['GENEL_YONETICI', 'BOLUM_YONETICISI', 'MUHASEBE'],
                true
            );
    }
}
