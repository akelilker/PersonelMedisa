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
 * (gorev/unvan, departman, bolum, birim, pozisyon, SGK isveren, calisma lokasyonu)
 * plus same-transaction çalışma bilgisi axes (bagli_amir_id, personel_tipi_id).
 * Permanent branch moves use PersonelKaliciSubeDegisikligiService instead.
 *
 * TRACKED_FIELDS stay blocked on generic PUT. WORK_INFO_FIELDS may still be
 * written by bulk/basic PUT owners; the Görev/Organizasyon screen uses this
 * owner so org + amir + tip never partial-persist across two client mutations.
 */
final class PersonelOrganizasyonDegisikligiService
{
    public const ERROR_STALE = 'PERSONEL_ORGANIZASYON_STALE_PREIMAGE';
    public const ERROR_NO_CHANGE = 'PERSONEL_ORGANIZASYON_NO_CHANGE';
    public const ERROR_FORBIDDEN = 'PERSONEL_ORGANIZASYON_FORBIDDEN';
    public const ERROR_GENERIC_PUT = 'PERSONEL_ORGANIZASYON_CANONICAL_OWNER_REQUIRED';

    /** @var list<string> Protected org axes — generic PUT must not change these. */
    public const TRACKED_FIELDS = [
        'gorev_id',
        'departman_id',
        'bolum_id',
        'birim_id',
        'pozisyon_id',
        'sgk_isveren_id',
        'calisma_lokasyonu_id',
    ];

    /**
     * Work-info axes accepted by this owner in the same transaction.
     * Not blocked on generic PUT (bulk basic axis still owns that path).
     *
     * @var list<string>
     */
    public const WORK_INFO_FIELDS = [
        'bagli_amir_id',
        'personel_tipi_id',
    ];

    private const GEREKCE_MIN = 10;
    private const GEREKCE_MAX = 500;
    private const WORK_INFO_ONLY_GEREKCE = 'Calisma bilgisi guncellemesi';

    /**
     * Fields this owner may mutate in one atomic apply().
     *
     * @return list<string>
     */
    public static function mutableFields(): array
    {
        return array_merge(self::TRACKED_FIELDS, self::WORK_INFO_FIELDS);
    }

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

        $preimage = self::parsePreimage($body);
        $targets = self::parseTargets($body);

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($claimIdempotency !== null) {
                $replay = $claimIdempotency($pdo);
                if (is_array($replay)) {
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }

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
                if ($field === 'sgk_isveren_id') {
                    self::assertSgkCompanyCompatible($pdo, $after, self::normalizeFieldValue('sube_id', $current['sube_id'] ?? null));
                }
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

            $hasTrackedChange = false;
            foreach ($changes as $field) {
                if (in_array($field, self::TRACKED_FIELDS, true)) {
                    $hasTrackedChange = true;
                    break;
                }
            }
            $gerekce = self::parseGerekce($body, $hasTrackedChange);
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

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'replay' => false,
                'personel_id' => $personelId,
                'olay_tipi' => $olayTipi,
                'audit_id' => $auditId,
                'degisen_alanlar' => $changes,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Execute the canonical organisation owner inside a caller-owned atomic
     * transaction. Idempotency belongs to that caller's outer mutation.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array{replay:bool, personel_id:int, olay_tipi:string, audit_id:int|null, degisen_alanlar:list<string>}
     */
    public static function applyInTransaction(
        PDO $pdo,
        array $user,
        Request $request,
        int $personelId,
        array $body,
        OrganizasyonAuditContext $auditContext
    ): array {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('applyInTransaction aktif bir transaction gerektirir.');
        }

        return self::apply($pdo, $user, $request, $personelId, $body, $auditContext);
    }

    /** @param list<string> $changes */
    private static function resolveOlayTipi(array $changes): string
    {
        $tracked = [];
        $work = [];
        foreach ($changes as $field) {
            if (in_array($field, self::TRACKED_FIELDS, true)) {
                $tracked[] = $field;
            } elseif (in_array($field, self::WORK_INFO_FIELDS, true)) {
                $work[] = $field;
            }
        }

        if (count($tracked) === 0 && count($work) > 0) {
            return 'CALISMA_BILGISI_DEGISIKLIGI';
        }

        $onlyGorev = count($tracked) === 1 && $tracked[0] === 'gorev_id';
        if ($onlyGorev) {
            return 'GOREV_UNVAN_DEGISIKLIGI';
        }
        $onlyLoc = count($tracked) === 1 && $tracked[0] === 'calisma_lokasyonu_id';
        if ($onlyLoc) {
            return 'CALISMA_LOKASYONU_DEGISIKLIGI';
        }
        $onlySgk = count($tracked) === 1 && $tracked[0] === 'sgk_isveren_id';
        if ($onlySgk) {
            return 'SGK_ISVERENI_DEGISIKLIGI';
        }

        return 'DEPARTMAN_BOLUM_BIRIM_POZISYON_DEGISIKLIGI';
    }

    /** @return array<string, mixed>|null */
    private static function loadPersonelOrgRow(PDO $pdo, int $personelId): ?array
    {
        $cols = ['id', 'sube_id', 'departman_id', 'gorev_id', 'bagli_amir_id', 'personel_tipi_id'];
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
        foreach (self::mutableFields() as $field) {
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
        foreach (self::mutableFields() as $field) {
            if (array_key_exists($field, $targets)) {
                $out[$field] = $targets[$field];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $body */
    private static function parseGerekce(array $body, bool $requiresJustification): string
    {
        $gerekce = trim((string) ($body['gerekce'] ?? ''));
        if (!$requiresJustification) {
            if ($gerekce === '') {
                return self::WORK_INFO_ONLY_GEREKCE;
            }
            $length = function_exists('mb_strlen') ? mb_strlen($gerekce) : strlen($gerekce);
            if ($length > self::GEREKCE_MAX) {
                throw OrganizasyonException::validation(
                    'Gerekçe en fazla ' . self::GEREKCE_MAX . ' karakter olabilir.',
                    'gerekce'
                );
            }

            return $gerekce;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($gerekce) : strlen($gerekce);
        if ($length < self::GEREKCE_MIN) {
            throw OrganizasyonException::validation(
                'Gerekçe en az ' . self::GEREKCE_MIN . ' karakter olmalıdır.',
                'gerekce'
            );
        }
        if ($length > self::GEREKCE_MAX) {
            throw OrganizasyonException::validation(
                'Gerekçe en fazla ' . self::GEREKCE_MAX . ' karakter olabilir.',
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
            if ($field === 'personel_tipi_id') {
                throw OrganizasyonException::validation('Personel tipi boş bırakılamaz.', 'personel_tipi_id');
            }

            return;
        }

        if ($field === 'bagli_amir_id') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => (int) $value]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw OrganizasyonException::validation('Geçersiz bağlı amir.', 'bagli_amir_id');
            }

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
            'personel_tipi_id' => 'personel_tipleri',
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

    /** @param mixed $sgkIsverenId @param mixed $subeId */
    private static function assertSgkCompanyCompatible(PDO $pdo, $sgkIsverenId, $subeId): void
    {
        $result = PersonelSgkCompanyConsistency::evaluate($pdo, $sgkIsverenId, $subeId);
        if ($result['ok']) {
            return;
        }

        throw new OrganizasyonException(
            409,
            (string) $result['code'],
            (string) $result['message'],
            'sgk_isveren_id'
        );
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
