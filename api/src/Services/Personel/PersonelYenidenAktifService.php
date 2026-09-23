<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use PDO;

/**
 * Canonical lifecycle owner for reactivating a PASIF (arşiv) personel back to
 * AKTIF — the symmetric counterpart of PersonelIstenAyrilmaService.
 *
 * Why a dedicated owner exists: PASIF records are read-only behind
 * PersonelArchiveGate, and no generic write path may change aktif_durum. The
 * only canonical way back is this narrow, transactional, fail-closed flow. The
 * archive gate itself is never bypassed or weakened — this owner performs the
 * audited transition the gate is designed to funnel writes through.
 *
 * Axis ownership (no parallel systems, no bypassed owners):
 *   - aktif_durum + the DIS_KAYNAK → IC_PERSONEL kapsam transition: this owner,
 *     validating internal identity through PersonelCalisanKapsamService.
 *   - hedef şube: delegated to PersonelKaliciSubeDegisikligiService (the
 *     canonical permanent branch-change owner) when a different branch is asked.
 *   - sgk_isveren_id / personel_tipi_id: delegated to
 *     PersonelOrganizasyonDegisikligiService (the canonical org-field owner).
 *   - target branch + SGK employer consistency: verified through
 *     PersonelSgkCompanyConsistency, never re-implemented here.
 *   - lifecycle manifests: minted through ArchiveManifestService (INSERT-only).
 *   - durable evidence: the append-only personnel change audit (migration 083).
 *
 * Fail-closed: every guard below raises before any mutation, and every write
 * happens inside the caller-owned transaction so a late failure rolls the whole
 * reactivation back. Nothing here is idempotent-by-silence except the
 * already-AKTIF case, which is an explicit no-op replay.
 */
final class PersonelYenidenAktifService
{
    public const OPERATION_TYPE = 'YENIDEN_ISE_ALMA';

    public const ERROR_NOT_FOUND = 'REACTIVATE_PERSONEL_NOT_FOUND';
    public const ERROR_TEST_FIXTURE_HIDDEN = 'REACTIVATE_TEST_FIXTURE_HIDDEN';
    public const ERROR_LEGAL_HOLD = RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE;
    public const ERROR_LEGAL_HOLD_SCHEMA = 'REACTIVATE_LEGAL_HOLD_SCHEMA_NOT_READY';
    public const ERROR_EXIT_SUREC_MISSING = 'REACTIVATE_EXIT_SUREC_MISSING';
    public const ERROR_EXIT_SUREC_NOT_UNIQUE = 'REACTIVATE_EXIT_SUREC_NOT_UNIQUE';
    public const ERROR_KAPSAM_TRANSITION = 'REACTIVATE_KAPSAM_TRANSITION_INVALID';
    public const ERROR_KAPSAM_SCHEMA = 'REACTIVATE_KAPSAM_SCHEMA_NOT_READY';
    public const ERROR_SGK_INCONSISTENT = 'REACTIVATE_SGK_SIRKET_UYUSMAZLIGI';
    public const ERROR_CONFLICT = 'REACTIVATE_PREIMAGE_CONFLICT';
    public const ERROR_READBACK = 'REACTIVATE_READBACK_MISMATCH';

    private const GEREKCE_MIN = 10;
    private const GEREKCE_MAX = 500;

    /**
     * Reactivate a PASIF personel. Must be called inside the caller's
     * transaction: the caller owns idempotency and commit/rollback.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body gerekce (required), optional
     *                                    calisan_kapsami / yeni_sube_id /
     *                                    sgk_isveren_id / personel_tipi_id
     * @return array<string, mixed>
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
            throw new \LogicException('PersonelYenidenAktifService aktif bir transaction gerektirir.');
        }
        if ($personelId <= 0) {
            throw new OrganizasyonException(404, self::ERROR_NOT_FOUND, 'Personel bulunamadı.');
        }

        // Fail-closed before any work: an environment without the append-only
        // personnel-change audit (083) must never accept an unaudited transition.
        OrganizasyonAuditWriter::assertPersonelOrganizasyonReady($pdo);

        $current = self::lockPersonel($pdo, $personelId);
        if ($current === null) {
            throw new OrganizasyonException(404, self::ERROR_NOT_FOUND, 'Personel bulunamadı.');
        }

        $aktifDurum = strtoupper(trim((string) ($current['aktif_durum'] ?? '')));
        if ($aktifDurum !== 'PASIF') {
            // Idempotent replay, matching the canonical cancel owners: a record
            // already in the target state is a success with no new writes.
            return self::replayResult($current);
        }

        SubeScope::assertPersonelAccess($user, $request, [
            'id' => (int) $current['id'],
            'sube_id' => $current['sube_id'] ?? null,
            'bolum_id' => $current['bolum_id'] ?? null,
            'birim_id' => $current['birim_id'] ?? null,
            'sgk_isveren_id' => $current['sgk_isveren_id'] ?? null,
        ], $pdo);

        if (TestFixturePersonelClassificationService::isOperationallyHidden($pdo, $personelId)) {
            throw new OrganizasyonException(
                409,
                self::ERROR_TEST_FIXTURE_HIDDEN,
                'Test fixture olarak sınıflandırılmış kayıt yeniden aktif edilemez.'
            );
        }

        // Legal hold is queried through the canonical retention owner; a hold
        // that cannot be evaluated is treated as an active hold (fail-closed),
        // exactly like PersonelArchiveGate::buildArchiveMarkers does.
        $legalHold = false;
        try {
            $legalHold = RetentionPolicyService::hasActiveLegalHold(
                $pdo,
                RetentionCategories::PERSONEL_OZLUK,
                ['personel_id' => $personelId, 'entity_type' => 'personel', 'record_id' => $personelId]
            );
        } catch (\Throwable $e) {
            throw new OrganizasyonException(
                409,
                self::ERROR_LEGAL_HOLD_SCHEMA,
                'Legal hold durumu doğrulanamadı; kayıt yeniden aktif edilemez.'
            );
        }
        if ($legalHold) {
            throw new OrganizasyonException(
                409,
                self::ERROR_LEGAL_HOLD,
                'Aktif legal hold nedeniyle kayıt yeniden aktif edilemez.'
            );
        }

        $exitSurec = self::resolveSingleOpenExitSurec($pdo, $personelId);

        $gerekce = self::parseGerekce($body);

        // Lifecycle manifests must be minted while the record is still PASIF:
        // the archived period's baseline is frozen at the reactivation boundary,
        // and ArchiveManifestService is INSERT-only, so existing rows are never
        // rewritten. After this call the exit is cancelled and the termination
        // anchor disappears, which is exactly when this window closes.
        $manifests = ArchiveManifestService::createPersonelLifecycleManifests(
            $pdo,
            $personelId,
            $auditContext->actorUserId()
        );

        // Immutable preimage (audit "before") plus a mutable target state so the
        // audit row never reports the post-write values as its own preimage.
        $before = [
            'aktif_durum' => $aktifDurum,
            'sube_id' => self::nullableInt($current['sube_id'] ?? null),
            'calisan_kapsami' => PersonelCalisanKapsamService::resolveFromRow($current),
            'sgk_isveren_id' => self::nullableInt($current['sgk_isveren_id'] ?? null),
            'personel_tipi_id' => self::nullableInt($current['personel_tipi_id'] ?? null),
        ];
        $target = $before;
        $changed = [];

        // 1) Hedef şube — canonical permanent branch-change owner.
        $targetSubeId = self::parseTargetSubeId($body);
        if ($targetSubeId !== null && $targetSubeId !== $target['sube_id']) {
            PersonelKaliciSubeDegisikligiService::applyInTransaction(
                $pdo,
                $user,
                $personelId,
                [
                    'beklenen_mevcut_sube_id' => $target['sube_id'],
                    'yeni_sube_id' => $targetSubeId,
                    'gerekce' => $gerekce,
                ],
                $auditContext
            );
            $target['sube_id'] = $targetSubeId;
            $changed[] = 'sube_id';
        }

        // 2) DIS_KAYNAK → IC_PERSONEL — internal identity contract applies.
        $targetKapsam = self::parseTargetKapsam($body, $pdo);
        if ($targetKapsam !== null && $targetKapsam !== $target['calisan_kapsami']) {
            if ($targetKapsam !== PersonelCalisanKapsamService::IC_PERSONEL
                || $target['calisan_kapsami'] !== PersonelCalisanKapsamService::DIS_KAYNAK
            ) {
                throw new OrganizasyonException(
                    409,
                    self::ERROR_KAPSAM_TRANSITION,
                    'Yeniden aktif etmede yalnızca DIS_KAYNAK → IC_PERSONEL kapsam geçişi yapılabilir.'
                );
            }
            $merged = $current;
            $merged['calisan_kapsami'] = $targetKapsam;
            PersonelCalisanKapsamService::assertInternalIdentityComplete($merged);
            $target['calisan_kapsami'] = $targetKapsam;
            $changed[] = 'calisan_kapsami';
        }

        // 3) SGK işvereni / personel tipi — canonical org-field owner.
        $orgPreimage = [];
        $orgTargets = [];
        $targetSgkId = self::parsePositiveId($body, 'sgk_isveren_id');
        if ($targetSgkId !== null && $targetSgkId !== $target['sgk_isveren_id']) {
            $orgPreimage['sgk_isveren_id'] = $target['sgk_isveren_id'];
            $orgTargets['sgk_isveren_id'] = $targetSgkId;
        }
        $targetTipId = self::parsePositiveId($body, 'personel_tipi_id');
        if ($targetTipId !== null && $targetTipId !== $target['personel_tipi_id']) {
            $orgPreimage['personel_tipi_id'] = $target['personel_tipi_id'];
            $orgTargets['personel_tipi_id'] = $targetTipId;
        }
        if (array_key_exists('sgk_isveren_id', $orgTargets) && !PersonelOrgLocationSchema::isReady($pdo)) {
            throw new OrganizasyonException(
                409,
                PersonelOrgLocationSchema::ERROR_CODE,
                'Org location şeması bu ortamda hazır değil; SGK işvereni yazılamaz.'
            );
        }
        if (count($orgTargets) > 0) {
            PersonelOrganizasyonDegisikligiService::applyInTransaction(
                $pdo,
                $user,
                $request,
                $personelId,
                [
                    'preimage' => $orgPreimage,
                    'targets' => $orgTargets,
                    'gerekce' => $gerekce,
                ],
                $auditContext
            );
            foreach ($orgTargets as $field => $value) {
                $target[$field] = $value;
                $changed[] = $field;
            }
        }

        // 4) Hedef şube/SGK işveren tutarlılığı — canonical consistency owner.
        self::assertReadyForActivePersonel($pdo, $target);

        // 5) The transition itself. Guarded UPDATE so a concurrent change cannot
        //    be silently overwritten after our preimage read.
        $setParts = ["aktif_durum = 'AKTIF'"];
        $params = ['id' => $personelId];
        if (in_array('calisan_kapsami', $changed, true)) {
            $setParts[] = 'calisan_kapsami = :calisan_kapsami';
            $params['calisan_kapsami'] = $target['calisan_kapsami'];
        }
        $stmt = $pdo->prepare(
            'UPDATE personeller SET ' . implode(', ', $setParts)
            . " WHERE id = :id AND aktif_durum = 'PASIF'"
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new OrganizasyonException(
                409,
                self::ERROR_CONFLICT,
                'Personel durumu bu işlem sırasında değişti; yeniden aktif etme uygulanmadı.'
            );
        }

        // 6) Cancel the exit process instead of deleting it: the historical
        //    exit record stays visible and immutable in place.
        $cancel = $pdo->prepare(
            "UPDATE surecler SET state = 'IPTAL'
             WHERE id = :id AND state NOT IN ('IPTAL', 'TAMAMLANDI')"
        );
        $cancel->execute(['id' => $exitSurec['id']]);
        if ($cancel->rowCount() !== 1) {
            throw new OrganizasyonException(
                409,
                self::ERROR_CONFLICT,
                'Ayrılış süreci bu işlem sırasında değişti; yeniden aktif etme uygulanmadı.'
            );
        }

        // 7) Append-only evidence for the reactivation event itself.
        $degisenAlanlar = array_values(array_unique(array_merge(['aktif_durum'], $changed)));
        $yeniDegerler = ['aktif_durum' => 'AKTIF'];
        foreach ($degisenAlanlar as $field) {
            if ($field !== 'aktif_durum') {
                $yeniDegerler[$field] = $target[$field];
            }
        }
        $auditId = OrganizasyonAuditWriter::recordPersonelOrganizasyonDegisikligi(
            $pdo,
            [
                'personel_id' => $personelId,
                'olay_tipi' => self::OPERATION_TYPE,
                'degisen_alanlar' => $degisenAlanlar,
                'eski_degerler' => $before,
                'yeni_degerler' => $yeniDegerler,
                'gerekce' => $gerekce,
            ],
            $auditContext
        );

        $manifestIds = [];
        foreach ($manifests as $manifest) {
            if (isset($manifest['id'])) {
                $manifestIds[] = (int) $manifest['id'];
            }
        }

        return [
            'replay' => false,
            'already_active' => false,
            'personel_id' => $personelId,
            'onceki_aktif_durum' => $aktifDurum,
            'aktif_durum' => 'AKTIF',
            'calisan_kapsami' => $target['calisan_kapsami'],
            'iptal_edilen_surec_id' => (int) $exitSurec['id'],
            'degisen_alanlar' => $degisenAlanlar,
            'audit_id' => $auditId,
            'lifecycle_manifest_ids' => $manifestIds,
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private static function replayResult(array $current): array
    {
        return [
            'replay' => true,
            'already_active' => true,
            'personel_id' => (int) $current['id'],
            'onceki_aktif_durum' => 'AKTIF',
            'aktif_durum' => 'AKTIF',
            'calisan_kapsami' => PersonelCalisanKapsamService::resolveFromRow($current),
            'iptal_edilen_surec_id' => null,
            'degisen_alanlar' => [],
            'audit_id' => null,
            'lifecycle_manifest_ids' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function lockPersonel(PDO $pdo, int $personelId): ?array
    {
        $columns = 'id, sube_id, personel_tipi_id, aktif_durum';
        if (PersonelOrgStructureSchema::isReady($pdo)) {
            $columns .= ', bolum_id, birim_id';
        }
        if (PersonelOrgLocationSchema::isReady($pdo)) {
            $columns .= ', sgk_isveren_id';
        }
        if (PersonelCalisanKapsamSchema::isReady($pdo)) {
            $columns .= ', calisan_kapsami';
        }
        foreach (['tc_kimlik_no', 'soyad', 'dogum_tarihi', 'telefon'] as $identityColumn) {
            if (self::columnExists($pdo, 'personeller', $identityColumn)) {
                $columns .= ', ' . $identityColumn;
            }
        }

        $stmt = $pdo->prepare("SELECT {$columns} FROM personeller WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Exactly one open ISTEN_AYRILMA is required. Zero means the record was not
     * archived through the canonical exit owner; more than one is ambiguous and
     * must not be guessed at.
     *
     * @return array<string, mixed>
     */
    private static function resolveSingleOpenExitSurec(PDO $pdo, int $personelId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, state, baslangic_tarihi
             FROM surecler
             WHERE personel_id = :pid
               AND surec_turu = 'ISTEN_AYRILMA'
               AND state NOT IN ('IPTAL', 'TAMAMLANDI')
             ORDER BY id DESC"
        );
        $stmt->execute(['pid' => $personelId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($rows) === 0) {
            throw new OrganizasyonException(
                409,
                self::ERROR_EXIT_SUREC_MISSING,
                'Açık bir İşten Ayrılma süreci bulunamadı; kayıt yeniden aktif edilemez.'
            );
        }
        if (count($rows) > 1) {
            throw new OrganizasyonException(
                409,
                self::ERROR_EXIT_SUREC_NOT_UNIQUE,
                'Birden fazla açık İşten Ayrılma süreci var; kayıt yeniden aktif edilemez.'
            );
        }

        return $rows[0];
    }

    /**
     * Final target triple must satisfy the canonical IC_PERSONEL rules before we
     * flip the record to AKTIF.
     *
     * @param array<string, mixed> $target
     */
    private static function assertReadyForActivePersonel(PDO $pdo, array $target): void
    {
        $kapsam = (string) $target['calisan_kapsami'];
        $sgkId = $target['sgk_isveren_id'];
        $subeId = $target['sube_id'];

        $consistency = PersonelSgkCompanyConsistency::evaluateForKapsam(
            $pdo,
            $kapsam,
            $sgkId,
            $subeId
        );
        if (!$consistency['ok']) {
            throw new OrganizasyonException(
                409,
                (string) ($consistency['code'] ?? '') !== ''
                    ? (string) $consistency['code']
                    : self::ERROR_SGK_INCONSISTENT,
                (string) ($consistency['message'] ?? 'SGK işvereni ile şube şirketi uyuşmuyor.'),
                'sgk_isveren_id'
            );
        }

        try {
            PersonelSgkCompanyConsistency::assertRequiredForActiveIc($kapsam, 'AKTIF', $sgkId);
        } catch (PersonelValidationException $e) {
            throw new OrganizasyonException(
                409,
                (string) $e->getCodeString(),
                $e->getMessage(),
                $e->getField()
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function parseTargetKapsam(array $body, PDO $pdo): ?string
    {
        if (!array_key_exists('calisan_kapsami', $body) || $body['calisan_kapsami'] === null
            || $body['calisan_kapsami'] === ''
        ) {
            return null;
        }
        if (!PersonelCalisanKapsamSchema::isReady($pdo)) {
            throw new OrganizasyonException(
                409,
                self::ERROR_KAPSAM_SCHEMA,
                'Çalışan kapsamı şeması bu ortamda hazır değil; kapsam geçişi reddedildi.'
            );
        }
        try {
            return PersonelCalisanKapsamService::normalize($body['calisan_kapsami'], false);
        } catch (PersonelValidationException $e) {
            throw new OrganizasyonException(422, (string) $e->getCodeString(), $e->getMessage(), $e->getField());
        }
    }

    /** @param array<string, mixed> $body */
    private static function parseTargetSubeId(array $body): ?int
    {
        return self::parsePositiveId($body, 'yeni_sube_id');
    }

    /** @param array<string, mixed> $body */
    private static function parsePositiveId(array $body, string $field): ?int
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        $value = (int) $body[$field];
        if ($value <= 0) {
            throw OrganizasyonException::validation('Geçersiz kimlik: ' . $field, $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function parseGerekce(array $body): string
    {
        $gerekce = trim((string) ($body['gerekce'] ?? ''));
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
    private static function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
