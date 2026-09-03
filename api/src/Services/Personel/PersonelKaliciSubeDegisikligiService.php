<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use PDO;

/**
 * The one and only owner of a permanent personnel branch change.
 *
 * PUT /personeller/{id} still refuses to move anybody between branches, and
 * that refusal is not softened anywhere. A permanent move is a different kind
 * of event: it needs a stated justification, a narrower role, an expected
 * pre-image and an immutable audit row, none of which a field-level update
 * form can supply. Giving it its own owner is what keeps the generic update
 * path honest.
 *
 * A temporary assignment (personel_gecici_gorevlendirmeler) is unrelated and
 * untouched: it adds a dated row and never writes personeller.sube_id.
 *
 * Exactly one column changes here. calisma_lokasyonu_id and sgk_isveren_id are
 * read before the write and re-read after it, and a mismatch aborts the whole
 * transaction — a branch move that quietly relocated somebody's work location
 * or payroll employer would be a data-loss bug, not a successful move.
 */
final class PersonelKaliciSubeDegisikligiService
{
    /** Only these roles may permanently move a person between branches. */
    public const ALLOWED_ROLES = ['GENEL_YONETICI', 'SISTEM_YONETICISI'];

    public const ERROR_ROLE = 'KALICI_SUBE_DEGISIKLIGI_FORBIDDEN';
    public const ERROR_STALE = 'KALICI_SUBE_DEGISIKLIGI_STALE_PREIMAGE';
    public const ERROR_TARGET = 'KALICI_SUBE_DEGISIKLIGI_TARGET_INVALID';
    public const ERROR_SCOPE = 'KALICI_SUBE_DEGISIKLIGI_SCOPE_FORBIDDEN';
    public const ERROR_SGK_MISMATCH = 'KALICI_SUBE_DEGISIKLIGI_SGK_SIRKET_MISMATCH';
    public const ERROR_NO_CHANGE = 'KALICI_SUBE_DEGISIKLIGI_NO_CHANGE';
    public const ERROR_READBACK = 'KALICI_SUBE_DEGISIKLIGI_READBACK_MISMATCH';

    private const GEREKCE_MIN = 10;
    private const GEREKCE_MAX = 500;

    /** @param array<string, mixed> $user */
    public static function assertRole(array $user): void
    {
        if (!in_array((string) ($user['rol'] ?? ''), self::ALLOWED_ROLES, true)) {
            throw new OrganizasyonException(
                403,
                self::ERROR_ROLE,
                'Kalıcı şube değişikliği yalnızca genel yönetici veya sistem yöneticisi tarafından yapılabilir.'
            );
        }
    }

    /**
     * The two idempotency callables run inside this method's transaction, which
     * is the only place they can run without reintroducing the window where a
     * retry is claimed but the move is not yet durable.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @param callable(PDO):(array<string, mixed>|null)|null $claimIdempotency
     * @param callable(PDO):void|null $completeIdempotency
     * @return array{
     *   replay:bool,
     *   personel_id:int,
     *   onceki_sube_id:int|null,
     *   yeni_sube_id:int|null,
     *   calisma_lokasyonu_id:int|null,
     *   sgk_isveren_id:int|null,
     *   audit_id:int|null
     * }
     */
    public static function apply(
        PDO $pdo,
        array $user,
        int $personelId,
        array $body,
        OrganizasyonAuditContext $auditContext,
        ?callable $claimIdempotency = null,
        ?callable $completeIdempotency = null
    ): array {
        self::assertRole($user);

        if ($personelId <= 0) {
            throw OrganizasyonException::notFound('Personel bulunamadı.');
        }

        $expectedSubeId = self::parseExpectedSubeId($body);
        $targetSubeId = self::parseTargetSubeId($body);
        $gerekce = self::parseGerekce($body);

        // Refuse before opening a transaction rather than rolling one back:
        // an environment without migration 080 can never satisfy this call.
        OrganizasyonAuditWriter::assertReady($pdo, OrganizasyonAuditWriter::PERSONEL_SUBE_TABLE);

        $orgLocationReady = PersonelOrgLocationSchema::isReady($pdo);
        $hierarchyReady = OrganizasyonSchema::isSchemaReady($pdo);

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
                        'onceki_sube_id' => null,
                        'yeni_sube_id' => null,
                        'calisma_lokasyonu_id' => null,
                        'sgk_isveren_id' => null,
                        'audit_id' => null,
                    ];
                }
            }

            $current = self::lockPersonel($pdo, $personelId, $orgLocationReady);
            if ($current === null) {
                throw OrganizasyonException::notFound('Personel bulunamadı.');
            }

            $currentSubeId = self::nullableId($current['sube_id']);
            if ($currentSubeId !== $expectedSubeId) {
                throw new OrganizasyonException(
                    409,
                    self::ERROR_STALE,
                    'Personelin güncel şubesi beklenen değerden farklı; işlem güvenlik gereği durduruldu.',
                    'beklenen_mevcut_sube_id'
                );
            }
            if ($currentSubeId === $targetSubeId) {
                throw new OrganizasyonException(
                    409,
                    self::ERROR_NO_CHANGE,
                    'Personel zaten hedef şubede.',
                    'yeni_sube_id'
                );
            }

            $target = self::readTargetSube($pdo, $targetSubeId, $hierarchyReady);
            if ($target === null) {
                throw new OrganizasyonException(404, self::ERROR_TARGET, 'Hedef şube bulunamadı.', 'yeni_sube_id');
            }
            if ((string) $target['durum'] !== 'AKTIF') {
                throw new OrganizasyonException(
                    409,
                    self::ERROR_TARGET,
                    'Hedef şube aktif değil.',
                    'yeni_sube_id'
                );
            }

            self::assertActorScope($user, $currentSubeId, $targetSubeId);
            self::assertSgkSirketConsistency(
                $pdo,
                self::nullableId($current['sgk_isveren_id'] ?? null),
                self::nullableId($target['sirket_id'] ?? null),
                $hierarchyReady
            );

            self::writeSubeId($pdo, $personelId, $currentSubeId, $targetSubeId);

            $auditId = OrganizasyonAuditWriter::recordPersonelSubeDegisikligi(
                $pdo,
                [
                    'personel_id' => $personelId,
                    'onceki_sube_id' => $currentSubeId,
                    'yeni_sube_id' => $targetSubeId,
                    'korunan_calisma_lokasyonu_id' => self::nullableId($current['calisma_lokasyonu_id'] ?? null),
                    'korunan_sgk_isveren_id' => self::nullableId($current['sgk_isveren_id'] ?? null),
                    'gerekce' => $gerekce,
                ],
                $auditContext
            );

            $after = self::lockPersonel($pdo, $personelId, $orgLocationReady);
            self::assertReadback($current, $after, $targetSubeId);

            if ($completeIdempotency !== null) {
                $completeIdempotency($pdo);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'replay' => false,
            'personel_id' => $personelId,
            'onceki_sube_id' => $currentSubeId,
            'yeni_sube_id' => $targetSubeId,
            'calisma_lokasyonu_id' => self::nullableId($current['calisma_lokasyonu_id'] ?? null),
            'sgk_isveren_id' => self::nullableId($current['sgk_isveren_id'] ?? null),
            'audit_id' => $auditId,
        ];
    }

    /**
     * Execute the canonical branch owner inside a caller-owned atomic
     * transaction. The outer lifecycle mutation owns idempotency.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array{replay:bool, personel_id:int, onceki_sube_id:int|null, yeni_sube_id:int|null, calisma_lokasyonu_id:int|null, sgk_isveren_id:int|null, audit_id:int|null}
     */
    public static function applyInTransaction(
        PDO $pdo,
        array $user,
        int $personelId,
        array $body,
        OrganizasyonAuditContext $auditContext
    ): array {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('applyInTransaction aktif bir transaction gerektirir.');
        }

        return self::apply($pdo, $user, $personelId, $body, $auditContext);
    }

    /** @return array<string, mixed>|null */
    private static function lockPersonel(PDO $pdo, int $personelId, bool $orgLocationReady): ?array
    {
        $columns = 'id, sube_id';
        if ($orgLocationReady) {
            $columns .= ', sgk_isveren_id, calisma_lokasyonu_id';
        }

        $stmt = $pdo->prepare("SELECT {$columns} FROM personeller WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function readTargetSube(PDO $pdo, int $subeId, bool $hierarchyReady): ?array
    {
        $columns = 'id, durum';
        if ($hierarchyReady) {
            $columns .= ', sirket_id';
        }

        $stmt = $pdo->prepare("SELECT {$columns} FROM subeler WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * The actor must already be able to see both sides of the move. Moving
     * somebody out of a branch you cannot read is as much a scope escape as
     * moving them into one.
     *
     * @param array<string, mixed> $user
     */
    private static function assertActorScope(array $user, ?int $currentSubeId, int $targetSubeId): void
    {
        $allowed = OrgScope::allowedSubeIds($user);
        if (count($allowed) === 0) {
            return;
        }

        if ($currentSubeId !== null && !in_array($currentSubeId, $allowed, true)) {
            throw new OrganizasyonException(403, self::ERROR_SCOPE, 'Personelin mevcut şubesi için yetkiniz yok.');
        }
        if (!in_array($targetSubeId, $allowed, true)) {
            throw new OrganizasyonException(
                403,
                self::ERROR_SCOPE,
                'Hedef şube için yetkiniz yok.',
                'yeni_sube_id'
            );
        }
    }

    /**
     * A person's payroll employer and their branch must stay inside one company.
     * Canonical evaluator: PersonelSgkCompanyConsistency (no duplicated SQL).
     * This owner keeps its historical error code for branch-move API clients.
     */
    private static function assertSgkSirketConsistency(
        PDO $pdo,
        ?int $sgkIsverenId,
        ?int $targetSirketId,
        bool $hierarchyReady
    ): void {
        if ($sgkIsverenId === null) {
            return;
        }

        if (!$hierarchyReady) {
            throw new OrganizasyonException(
                409,
                self::ERROR_SGK_MISMATCH,
                'Şirket hiyerarşisi hazır olmadığı için SGK işvereni ile hedef şube eşleşmesi doğrulanamıyor.'
            );
        }

        $result = PersonelSgkCompanyConsistency::evaluateAgainstSirket($pdo, $sgkIsverenId, $targetSirketId);
        if ($result['ok']) {
            return;
        }

        throw new OrganizasyonException(
            409,
            self::ERROR_SGK_MISMATCH,
            $result['code'] === PersonelSgkCompanyConsistency::ERROR_HIERARCHY
                ? 'Şirket hiyerarşisi hazır olmadığı için SGK işvereni ile hedef şube eşleşmesi doğrulanamıyor.'
                : 'Personelin SGK işvereni ile hedef şubenin şirketi uyuşmuyor.',
            'yeni_sube_id'
        );
    }

    /**
     * The WHERE clause repeats the pre-image on purpose. FOR UPDATE already
     * serialises writers, but this makes a lost update impossible to express
     * even if a future caller reaches the statement without the lock.
     */
    private static function writeSubeId(PDO $pdo, int $personelId, ?int $currentSubeId, int $targetSubeId): void
    {
        if ($currentSubeId === null) {
            $stmt = $pdo->prepare(
                'UPDATE personeller SET sube_id = :yeni WHERE id = :id AND sube_id IS NULL'
            );
            $stmt->execute(['yeni' => $targetSubeId, 'id' => $personelId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE personeller SET sube_id = :yeni WHERE id = :id AND sube_id = :mevcut'
            );
            $stmt->execute(['yeni' => $targetSubeId, 'id' => $personelId, 'mevcut' => $currentSubeId]);
        }

        if ($stmt->rowCount() !== 1) {
            throw new OrganizasyonException(
                409,
                self::ERROR_STALE,
                'Personel kaydı eşzamanlı olarak değiştiği için şube değişikliği uygulanamadı.'
            );
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     */
    private static function assertReadback(array $before, ?array $after, int $targetSubeId): void
    {
        if ($after === null || self::nullableId($after['sube_id']) !== $targetSubeId) {
            throw new OrganizasyonException(500, self::ERROR_READBACK, 'Şube değişikliği doğrulanamadı.');
        }

        foreach (['calisma_lokasyonu_id', 'sgk_isveren_id'] as $preserved) {
            $wasSet = array_key_exists($preserved, $before);
            $isSet = array_key_exists($preserved, $after);
            if ($wasSet !== $isSet) {
                throw new OrganizasyonException(500, self::ERROR_READBACK, 'Şube değişikliği doğrulanamadı.');
            }
            if ($wasSet && self::nullableId($before[$preserved]) !== self::nullableId($after[$preserved])) {
                throw new OrganizasyonException(
                    500,
                    self::ERROR_READBACK,
                    'Şube değişikliği sırasında korunması gereken alanlar değişti.'
                );
            }
        }
    }

    /** @param array<string, mixed> $body */
    private static function parseExpectedSubeId(array $body): ?int
    {
        if (!array_key_exists('beklenen_mevcut_sube_id', $body)) {
            throw OrganizasyonException::validation(
                'Beklenen mevcut şube zorunludur.',
                'beklenen_mevcut_sube_id'
            );
        }

        $raw = $body['beklenen_mevcut_sube_id'];
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
            throw OrganizasyonException::validation(
                'Beklenen mevcut şube geçersiz.',
                'beklenen_mevcut_sube_id'
            );
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /** @param array<string, mixed> $body */
    private static function parseTargetSubeId(array $body): int
    {
        $raw = $body['yeni_sube_id'] ?? null;
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
            throw OrganizasyonException::validation('Yeni şube zorunludur.', 'yeni_sube_id');
        }

        $id = (int) $raw;
        if ($id <= 0) {
            throw OrganizasyonException::validation('Yeni şube zorunludur.', 'yeni_sube_id');
        }

        return $id;
    }

    /** @param array<string, mixed> $body */
    private static function parseGerekce(array $body): string
    {
        $raw = $body['gerekce'] ?? null;
        $gerekce = is_string($raw) ? trim($raw) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($gerekce) : strlen($gerekce);

        if ($length < self::GEREKCE_MIN) {
            throw OrganizasyonException::validation(
                'Kalıcı şube değişikliği için en az ' . self::GEREKCE_MIN . ' karakterlik bir gerekçe zorunludur.',
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

    /** @param int|string|null $value */
    private static function nullableId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
