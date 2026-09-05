<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\RetentionClock;
use PDO;

/**
 * Canonical owner for correcting a wrong historical PASIF ISTEN_AYRILMA exit date.
 *
 * Distinct from:
 * - PersonelIstenAyrilmaService (AKTIF-only normal termination)
 * - PersonelHistoricalExitDateBackfillService (create-if-empty residual only; never overwrites)
 *
 * Preserves surec identity (UPDATE same surec_id). Does not activate, cancel, or duplicate exit.
 * Remints lifecycle archive manifests after date correction.
 * Durable audit is append-only; original surec.aciklama business text is preserved.
 */
final class PersonelHistoricalExitDateCorrectionService
{
    public const ERROR_PERSONEL_NOT_FOUND = 'HISTORICAL_EXIT_CORRECTION_PERSONEL_NOT_FOUND';
    public const ERROR_NOT_PASIF = 'HISTORICAL_EXIT_CORRECTION_NOT_PASIF';
    public const ERROR_EXIT_DATE_REQUIRED = 'HISTORICAL_EXIT_CORRECTION_EXIT_DATE_REQUIRED';
    public const ERROR_EXIT_DATE_INVALID = 'HISTORICAL_EXIT_CORRECTION_EXIT_DATE_INVALID';
    public const ERROR_EXIT_DATE_IN_FUTURE = 'HISTORICAL_EXIT_CORRECTION_EXIT_DATE_IN_FUTURE';
    public const ERROR_EXIT_BEFORE_HIRE = 'HISTORICAL_EXIT_CORRECTION_EXIT_BEFORE_HIRE';
    public const ERROR_SUREC_REQUIRED = 'HISTORICAL_EXIT_CORRECTION_SUREC_REQUIRED';
    public const ERROR_SUREC_NOT_FOUND = 'HISTORICAL_EXIT_CORRECTION_SUREC_NOT_FOUND';
    public const ERROR_SUREC_MISMATCH = 'HISTORICAL_EXIT_CORRECTION_SUREC_MISMATCH';
    public const ERROR_EXPECTED_OLD_REQUIRED = 'HISTORICAL_EXIT_CORRECTION_EXPECTED_OLD_REQUIRED';
    public const ERROR_PREIMAGE_MISMATCH = 'HISTORICAL_EXIT_CORRECTION_PREIMAGE_MISMATCH';
    public const ERROR_CONFLICT = 'HISTORICAL_EXIT_CORRECTION_CONFLICT';

    public const ACTION_CORRECT_HISTORICAL_SUREC = 'CORRECT_HISTORICAL_ISTEN_AYRILMA';
    public const ACTION_ALREADY_APPLIED = 'ALREADY_APPLIED';

    public const CORRECTION_ACIKLAMA_PREFIX = '[HISTORICAL_EXIT_DATE_CORRECTION]';
    public const OPERATION_TYPE = 'HISTORICAL_EXIT_DATE_CORRECTION';

    /**
     * SELECT-only plan / validation. Never mutates. Never takes FOR UPDATE.
     *
     * @return array{
     *   personel_id:int,
     *   surec_id:int,
     *   old_exit_date:string,
     *   new_exit_date:string,
     *   action:string,
     *   no_change:bool,
     *   preimage:array<string,mixed>,
     *   postimage:array<string,mixed>,
     *   retention_reconciliation:array<string,mixed>,
     *   audit:array<string,mixed>,
     *   aciklama:string,
     *   old_aciklama:?string
     * }
     */
    public static function plan(
        PDO $pdo,
        int $personelId,
        int $surecId,
        string $expectedOldExitDate,
        string $newExitDate,
        ?string $aciklama
    ): array {
        $expectedOld = self::normalizeAndValidateExitDate(
            $expectedOldExitDate,
            self::ERROR_EXPECTED_OLD_REQUIRED
        );
        $normalizedNew = self::normalizeAndValidateExitDate($newExitDate);
        $personel = self::loadPersonel($pdo, $personelId, false);

        return self::composePlanFromPersonel(
            $pdo,
            $personelId,
            $personel,
            $surecId,
            $expectedOld,
            $normalizedNew,
            $aciklama
        );
    }

    /**
     * Apply historical exit-date correction inside an existing transaction.
     *
     * Fail-closed order:
     * 1) Lock personel FOR UPDATE
     * 2) Re-validate PASIF + hire/new-date rules + exact surec preimage (baslangic+bitis)
     * 3) Lock target surec FOR UPDATE and revalidate both dates
     * 4) UPDATE baslangic/bitis on same surec_id (preserve aciklama)
     * 5) Append durable audit
     * 6) Remint ArchiveManifestService lifecycle manifests
     *
     * @return array{
     *   personel_id:int,
     *   surec_id:int,
     *   action:string,
     *   old_exit_date:string,
     *   new_exit_date:string,
     *   already_applied:bool,
     *   audit:array<string,mixed>,
     *   audit_id:?int
     * }
     */
    public static function applyInTransaction(
        PDO $pdo,
        int $personelId,
        int $surecId,
        string $expectedOldExitDate,
        string $newExitDate,
        ?string $aciklama,
        int $actorUserId,
        ?string $mutationId = null
    ): array {
        $expectedOld = self::normalizeAndValidateExitDate(
            $expectedOldExitDate,
            self::ERROR_EXPECTED_OLD_REQUIRED
        );
        $normalizedNew = self::normalizeAndValidateExitDate($newExitDate);

        $personel = self::loadPersonel($pdo, $personelId, true);
        $plan = self::composePlanFromPersonel(
            $pdo,
            $personelId,
            $personel,
            $surecId,
            $expectedOld,
            $normalizedNew,
            $aciklama
        );

        if ($plan['action'] === self::ACTION_ALREADY_APPLIED) {
            return [
                'personel_id' => $personelId,
                'surec_id' => $surecId,
                'action' => self::ACTION_ALREADY_APPLIED,
                'old_exit_date' => $plan['old_exit_date'],
                'new_exit_date' => $plan['new_exit_date'],
                'already_applied' => true,
                'audit' => $plan['audit'],
                'audit_id' => null,
            ];
        }

        $lockedSurec = self::loadSurecForUpdate($pdo, $surecId);
        self::assertSurecPreimageExactOld($lockedSurec, $personelId, $expectedOld);

        $oldAciklama = isset($lockedSurec['aciklama']) ? (string) $lockedSurec['aciklama'] : null;
        $reason = self::buildReason($aciklama, $expectedOld, $normalizedNew);
        $audit = self::buildAuditRecord(
            $actorUserId,
            $mutationId,
            $personelId,
            $surecId,
            $expectedOld,
            $expectedOld,
            $normalizedNew,
            $normalizedNew,
            $oldAciklama,
            $reason
        );

        self::updateSurecDates($pdo, $surecId, $normalizedNew);
        $auditId = PersonelHistoricalExitDateCorrectionAuditService::appendInTransaction($pdo, [
            'actor_user_id' => $actorUserId,
            'mutation_id' => $mutationId,
            'personel_id' => $personelId,
            'surec_id' => $surecId,
            'old_baslangic_tarihi' => $expectedOld,
            'old_bitis_tarihi' => $expectedOld,
            'new_baslangic_tarihi' => $normalizedNew,
            'new_bitis_tarihi' => $normalizedNew,
            'old_aciklama' => $oldAciklama,
            'reason' => $reason,
        ]);
        ArchiveManifestService::createPersonelLifecycleManifests($pdo, $personelId, $actorUserId);

        $audit['audit_id'] = $auditId;

        return [
            'personel_id' => $personelId,
            'surec_id' => $surecId,
            'action' => self::ACTION_CORRECT_HISTORICAL_SUREC,
            'old_exit_date' => $expectedOld,
            'new_exit_date' => $normalizedNew,
            'already_applied' => false,
            'audit' => $audit,
            'audit_id' => $auditId,
        ];
    }

    /**
     * @param array<string, mixed> $personel
     * @return array{
     *   personel_id:int,
     *   surec_id:int,
     *   old_exit_date:string,
     *   new_exit_date:string,
     *   action:string,
     *   no_change:bool,
     *   preimage:array<string,mixed>,
     *   postimage:array<string,mixed>,
     *   retention_reconciliation:array<string,mixed>,
     *   audit:array<string,mixed>,
     *   aciklama:string,
     *   old_aciklama:?string
     * }
     */
    private static function composePlanFromPersonel(
        PDO $pdo,
        int $personelId,
        array $personel,
        int $surecId,
        string $expectedOld,
        string $normalizedNew,
        ?string $aciklama
    ): array {
        self::assertPasifPreimage($personel);

        if ($surecId <= 0) {
            throw new PersonelValidationException(
                'surec_id',
                'Duzeltme icin surec_id zorunludur.',
                self::ERROR_SUREC_REQUIRED
            );
        }

        $hireDate = self::normalizeDateOnly($personel['ise_giris_tarihi'] ?? null);
        if ($hireDate !== null && $normalizedNew < $hireDate) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Ayrilis tarihi ise giris tarihinden once olamaz.',
                self::ERROR_EXIT_BEFORE_HIRE
            );
        }

        $surecler = self::loadActiveIstenAyrilmaSurecler($pdo, $personelId);
        if (count($surecler) !== 1) {
            throw new PersonelValidationException(
                'surec_turu',
                'Tarihi cikis duzeltmesi yalniz tek non-IPTAL ISTEN_AYRILMA icin uygulanir.',
                self::ERROR_CONFLICT
            );
        }

        $target = $surecler[0];
        if ((int) ($target['id'] ?? 0) !== $surecId) {
            throw new PersonelValidationException(
                'surec_id',
                'Hedef surec_id mevcut ISTEN_AYRILMA kaydi ile eslesmiyor.',
                self::ERROR_SUREC_MISMATCH
            );
        }

        $currentExit = self::normalizeDateOnly($target['baslangic_tarihi'] ?? null);
        $currentBitis = self::normalizeDateOnly($target['bitis_tarihi'] ?? null);
        if ($currentExit === null || $currentBitis === null) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Mevcut ISTEN_AYRILMA cikis tarihi okunamadi.',
                self::ERROR_CONFLICT
            );
        }

        $oldAciklama = isset($target['aciklama']) ? (string) $target['aciklama'] : null;
        $reason = self::buildReason($aciklama, $expectedOld, $normalizedNew);

        // Service-level idempotency: exact same surec already at corrected new date.
        if ($currentExit === $normalizedNew && $currentBitis === $normalizedNew) {
            $audit = self::buildAuditRecord(
                null,
                null,
                $personelId,
                $surecId,
                $currentExit,
                $currentBitis,
                $normalizedNew,
                $normalizedNew,
                $oldAciklama,
                $reason
            );

            return [
                'personel_id' => $personelId,
                'surec_id' => $surecId,
                'old_exit_date' => $currentExit,
                'new_exit_date' => $normalizedNew,
                'action' => self::ACTION_ALREADY_APPLIED,
                'no_change' => true,
                'preimage' => [
                    'aktif_durum' => 'PASIF',
                    'ise_giris_tarihi' => $hireDate,
                    'surec_id' => $surecId,
                    'surec_turu' => 'ISTEN_AYRILMA',
                    'state' => strtoupper(trim((string) ($target['state'] ?? ''))),
                    'old_exit_date' => $currentExit,
                    'old_bitis_tarihi' => $currentBitis,
                    'old_aciklama' => $oldAciklama,
                    'isten_ayrilma_count' => 1,
                ],
                'postimage' => [
                    'aktif_durum' => 'PASIF',
                    'surec_id' => $surecId,
                    'exit_date' => $normalizedNew,
                    'bitis_tarihi' => $normalizedNew,
                    'aciklama' => $oldAciklama,
                ],
                'retention_reconciliation' => [
                    'required' => false,
                    'action' => 'NONE',
                    'owner' => 'ArchiveManifestService::createPersonelLifecycleManifests',
                    'old_trigger_date' => $currentExit,
                    'new_trigger_date' => $normalizedNew,
                    'note' => 'Already at corrected date; no remint.',
                ],
                'audit' => $audit,
                'aciklama' => $reason,
                'old_aciklama' => $oldAciklama,
            ];
        }

        // Exact preimage: both baslangic and bitis must equal expected_old.
        if ($currentExit !== $expectedOld || $currentBitis !== $expectedOld) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Canli preimage beklenen eski cikis tarihi ile eslesmiyor.',
                self::ERROR_PREIMAGE_MISMATCH
            );
        }

        $audit = self::buildAuditRecord(
            null,
            null,
            $personelId,
            $surecId,
            $expectedOld,
            $expectedOld,
            $normalizedNew,
            $normalizedNew,
            $oldAciklama,
            $reason
        );

        $preimage = [
            'aktif_durum' => 'PASIF',
            'ise_giris_tarihi' => $hireDate,
            'surec_id' => $surecId,
            'surec_turu' => 'ISTEN_AYRILMA',
            'state' => strtoupper(trim((string) ($target['state'] ?? ''))),
            'old_exit_date' => $currentExit,
            'old_bitis_tarihi' => $currentBitis,
            'old_aciklama' => $oldAciklama,
            'isten_ayrilma_count' => 1,
        ];

        $retention = [
            'required' => true,
            'action' => 'REMINT_PERSONEL_LIFECYCLE_MANIFESTS',
            'owner' => 'ArchiveManifestService::createPersonelLifecycleManifests',
            'old_trigger_date' => $currentExit,
            'new_trigger_date' => $normalizedNew,
            'note' => 'Prior termination identities remain immutable; new identity uses corrected date.',
        ];

        return [
            'personel_id' => $personelId,
            'surec_id' => $surecId,
            'old_exit_date' => $currentExit,
            'new_exit_date' => $normalizedNew,
            'action' => self::ACTION_CORRECT_HISTORICAL_SUREC,
            'no_change' => false,
            'preimage' => $preimage,
            'postimage' => [
                'aktif_durum' => 'PASIF',
                'surec_id' => $surecId,
                'exit_date' => $normalizedNew,
                'bitis_tarihi' => $normalizedNew,
                'aciklama' => $oldAciklama,
            ],
            'retention_reconciliation' => $retention,
            'audit' => $audit,
            'aciklama' => $reason,
            'old_aciklama' => $oldAciklama,
        ];
    }

    private static function normalizeAndValidateExitDate(
        string $exitDate,
        string $requiredCode = self::ERROR_EXIT_DATE_REQUIRED
    ): string {
        $trimmed = trim($exitDate);
        if ($trimmed === '') {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Cikis tarihi zorunludur.',
                $requiredCode
            );
        }
        if (!PersonelCanonicalValidator::isValidDateString($trimmed)) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Gecerli bir tarih olmalidir.',
                self::ERROR_EXIT_DATE_INVALID
            );
        }

        $today = RetentionClock::now()->format('Y-m-d');
        if ($trimmed > $today) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'İşten ayrılış tarihi ileri bir tarih olamaz.',
                self::ERROR_EXIT_DATE_IN_FUTURE
            );
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadPersonel(PDO $pdo, int $personelId, bool $forUpdate): array
    {
        if ($personelId <= 0) {
            throw new PersonelValidationException(
                'personel_id',
                'Personel bulunamadi.',
                self::ERROR_PERSONEL_NOT_FOUND
            );
        }

        $sql = 'SELECT id, aktif_durum, ise_giris_tarihi FROM personeller WHERE id = :id LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($personel)) {
            throw new PersonelValidationException(
                'personel_id',
                'Personel bulunamadi.',
                self::ERROR_PERSONEL_NOT_FOUND
            );
        }

        return $personel;
    }

    /** @param array<string, mixed> $personel */
    private static function assertPasifPreimage(array $personel): void
    {
        $aktifDurum = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        if ($aktifDurum !== 'PASIF') {
            throw new PersonelValidationException(
                'personel_id',
                'Tarihi cikis duzeltmesi yalniz PASIF personel icin uygulanir.',
                self::ERROR_NOT_PASIF
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function loadActiveIstenAyrilmaSurecler(PDO $pdo, int $personelId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, baslangic_tarihi, bitis_tarihi, state, aciklama
             FROM surecler
             WHERE personel_id = :pid
               AND surec_turu = 'ISTEN_AYRILMA'
               AND state <> 'IPTAL'
             ORDER BY baslangic_tarihi DESC, id DESC"
        );
        $stmt->execute(['pid' => $personelId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadSurecForUpdate(PDO $pdo, int $surecId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, personel_id, surec_turu, baslangic_tarihi, bitis_tarihi, state, aciklama
             FROM surecler
             WHERE id = :id
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute(['id' => $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PersonelValidationException(
                'surec_id',
                'Surec bulunamadi.',
                self::ERROR_SUREC_NOT_FOUND
            );
        }

        return $row;
    }

    /**
     * Locked apply revalidation: baslangic AND bitis must both equal expected_old.
     *
     * @param array<string, mixed> $surec
     */
    private static function assertSurecPreimageExactOld(array $surec, int $personelId, string $expectedOld): void
    {
        if ((int) ($surec['personel_id'] ?? 0) !== $personelId) {
            throw new PersonelValidationException(
                'surec_id',
                'Surec baska personele ait.',
                self::ERROR_SUREC_MISMATCH
            );
        }
        if (strtoupper(trim((string) ($surec['surec_turu'] ?? ''))) !== 'ISTEN_AYRILMA') {
            throw new PersonelValidationException(
                'surec_turu',
                'Surec ISTEN_AYRILMA degil.',
                self::ERROR_SUREC_MISMATCH
            );
        }
        if (strtoupper(trim((string) ($surec['state'] ?? ''))) === 'IPTAL') {
            throw new PersonelValidationException(
                'state',
                'IPTAL surec duzeltilemez.',
                self::ERROR_SUREC_MISMATCH
            );
        }
        $currentBaslangic = self::normalizeDateOnly($surec['baslangic_tarihi'] ?? null);
        $currentBitis = self::normalizeDateOnly($surec['bitis_tarihi'] ?? null);
        if ($currentBaslangic !== $expectedOld || $currentBitis !== $expectedOld) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Kilitli surec preimage beklenen eski tarihle eslesmiyor.',
                self::ERROR_PREIMAGE_MISMATCH
            );
        }
    }

    private static function updateSurecDates(PDO $pdo, int $surecId, string $exitDate): void
    {
        // Preserve original business aciklama; provenance lives in durable audit.
        $stmt = $pdo->prepare(
            'UPDATE surecler
             SET baslangic_tarihi = :baslangic_tarihi,
                 bitis_tarihi = :bitis_tarihi
             WHERE id = :id
               AND surec_turu = \'ISTEN_AYRILMA\'
               AND state <> \'IPTAL\''
        );
        $stmt->execute([
            'baslangic_tarihi' => $exitDate,
            'bitis_tarihi' => $exitDate,
            'id' => $surecId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PersonelValidationException(
                'surec_id',
                'Surec tarih guncellemesi uygulanamadi.',
                self::ERROR_CONFLICT
            );
        }
    }

    private static function buildReason(?string $aciklama, string $oldDate, string $newDate): string
    {
        $trimmed = trim((string) $aciklama);
        $default = self::CORRECTION_ACIKLAMA_PREFIX
            . ' HR authoritative correction ' . $oldDate . ' -> ' . $newDate;
        if ($trimmed === '') {
            return $default;
        }
        if (strpos($trimmed, self::CORRECTION_ACIKLAMA_PREFIX) === 0) {
            return $trimmed;
        }

        return self::CORRECTION_ACIKLAMA_PREFIX . ' ' . $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildAuditRecord(
        ?int $actorUserId,
        ?string $mutationId,
        int $personelId,
        int $surecId,
        string $oldBaslangic,
        string $oldBitis,
        string $newBaslangic,
        string $newBitis,
        ?string $oldAciklama,
        string $reason
    ): array {
        $mutation = $mutationId !== null ? trim($mutationId) : '';

        return [
            'operation_type' => self::OPERATION_TYPE,
            'actor_user_id' => $actorUserId,
            'mutation_id' => $mutation !== '' ? $mutation : null,
            'personel_id' => $personelId,
            'surec_id' => $surecId,
            'old_baslangic_tarihi' => $oldBaslangic,
            'old_bitis_tarihi' => $oldBitis,
            'new_baslangic_tarihi' => $newBaslangic,
            'new_bitis_tarihi' => $newBitis,
            'old_exit_date' => $oldBaslangic,
            'new_exit_date' => $newBaslangic,
            'old_aciklama' => $oldAciklama,
            'reason' => $reason,
            'created_at' => RetentionClock::now()->format(DATE_ATOM),
            'timestamp' => RetentionClock::now()->format(DATE_ATOM),
        ];
    }

    /** @param mixed $value */
    private static function normalizeDateOnly($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) === 1) {
            $raw = $m[1];
        }
        if (!PersonelCanonicalValidator::isValidDateString($raw)) {
            return null;
        }

        return $raw;
    }
}
