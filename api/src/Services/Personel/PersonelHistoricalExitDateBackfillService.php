<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\RetentionClock;
use PDO;

/**
 * Canonical owner for historical PASIF exit-date residual backfill.
 *
 * Distinct from PersonelIstenAyrilmaService (AKTIF-only normal termination).
 * Does not activate, rehire, or loosen the normal exit AKTIF gate.
 *
 * Canonical exit date remains surecler.baslangic_tarihi on ISTEN_AYRILMA
 * (personeller.cikis_tarihi is optional/legacy and not required by current schema).
 */
final class PersonelHistoricalExitDateBackfillService
{
    public const ERROR_PERSONEL_NOT_FOUND = 'HISTORICAL_EXIT_BACKFILL_PERSONEL_NOT_FOUND';
    public const ERROR_NOT_PASIF = 'HISTORICAL_EXIT_BACKFILL_NOT_PASIF';
    public const ERROR_EXIT_DATE_REQUIRED = 'HISTORICAL_EXIT_BACKFILL_EXIT_DATE_REQUIRED';
    public const ERROR_EXIT_DATE_INVALID = 'HISTORICAL_EXIT_BACKFILL_EXIT_DATE_INVALID';
    public const ERROR_EXIT_DATE_IN_FUTURE = 'HISTORICAL_EXIT_BACKFILL_EXIT_DATE_IN_FUTURE';
    public const ERROR_EXIT_BEFORE_HIRE = 'HISTORICAL_EXIT_BACKFILL_EXIT_BEFORE_HIRE';
    public const ERROR_CONFLICT = 'HISTORICAL_EXIT_BACKFILL_CONFLICT';

    public const ACTION_CREATE_HISTORICAL_SUREC = 'CREATE_HISTORICAL_ISTEN_AYRILMA';
    public const ACTION_ALREADY_APPLIED = 'ALREADY_APPLIED';

    public const BACKFILL_ACIKLAMA_PREFIX = '[HISTORICAL_EXIT_DATE_BACKFILL]';

    /**
     * SELECT-only plan / validation. Never mutates.
     *
     * @return array{
     *   personel_id:int,
     *   exit_date:string,
     *   action:string,
     *   no_change:bool,
     *   preimage:array<string,mixed>,
     *   postimage:array<string,mixed>,
     *   aciklama:string
     * }
     */
    public static function plan(PDO $pdo, int $personelId, string $exitDate, ?string $aciklama): array
    {
        $normalizedExit = self::normalizeAndValidateExitDate($exitDate);
        $personel = self::loadPersonel($pdo, $personelId, false);
        self::assertPasifPreimage($personel);

        $hireDate = self::normalizeDateOnly($personel['ise_giris_tarihi'] ?? null);
        if ($hireDate !== null && $normalizedExit < $hireDate) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Ayrilis tarihi ise giris tarihinden once olamaz.',
                self::ERROR_EXIT_BEFORE_HIRE
            );
        }

        $surecler = self::loadActiveIstenAyrilmaSurecler($pdo, $personelId);
        $currentExit = self::resolveCurrentExitDate($pdo, $personelId, $surecler);
        $aciklamaFinal = self::buildAciklama($aciklama);

        $preimage = [
            'aktif_durum' => 'PASIF',
            'ise_giris_tarihi' => $hireDate,
            'current_exit_date' => $currentExit,
            'isten_ayrilma_count' => count($surecler),
            'isten_ayrilma_surec_id' => count($surecler) > 0 ? (int) $surecler[0]['id'] : null,
        ];

        if ($currentExit !== null) {
            if ($currentExit === $normalizedExit) {
                return [
                    'personel_id' => $personelId,
                    'exit_date' => $normalizedExit,
                    'action' => self::ACTION_ALREADY_APPLIED,
                    'no_change' => true,
                    'preimage' => $preimage,
                    'postimage' => [
                        'aktif_durum' => 'PASIF',
                        'exit_date' => $normalizedExit,
                        'isten_ayrilma_surec_id' => $preimage['isten_ayrilma_surec_id'],
                    ],
                    'aciklama' => $aciklamaFinal,
                ];
            }

            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Mevcut tarihi cikis kaydi ile celisen backfill tarihi.',
                self::ERROR_CONFLICT
            );
        }

        if (count($surecler) > 0) {
            throw new PersonelValidationException(
                'surec_turu',
                'ISTEN_AYRILMA kaydi var ama cikis tarihi okunamadi; backfill reddedildi.',
                self::ERROR_CONFLICT
            );
        }

        return [
            'personel_id' => $personelId,
            'exit_date' => $normalizedExit,
            'action' => self::ACTION_CREATE_HISTORICAL_SUREC,
            'no_change' => false,
            'preimage' => $preimage,
            'postimage' => [
                'aktif_durum' => 'PASIF',
                'exit_date' => $normalizedExit,
                'isten_ayrilma_surec_id' => null,
            ],
            'aciklama' => $aciklamaFinal,
        ];
    }

    /**
     * Apply historical backfill inside an existing transaction (caller owns begin/commit/rollback).
     *
     * @return array{
     *   personel_id:int,
     *   surec_id:int|null,
     *   action:string,
     *   exit_date:string,
     *   already_applied:bool
     * }
     */
    public static function applyInTransaction(
        PDO $pdo,
        int $personelId,
        string $exitDate,
        ?string $aciklama,
        int $actorUserId
    ): array {
        $plan = self::plan($pdo, $personelId, $exitDate, $aciklama);

        // Re-lock personel row for apply-time TOCTOU protection.
        self::loadPersonel($pdo, $personelId, true);

        if ($plan['action'] === self::ACTION_ALREADY_APPLIED) {
            return [
                'personel_id' => $personelId,
                'surec_id' => $plan['preimage']['isten_ayrilma_surec_id'] !== null
                    ? (int) $plan['preimage']['isten_ayrilma_surec_id']
                    : null,
                'action' => self::ACTION_ALREADY_APPLIED,
                'exit_date' => $plan['exit_date'],
                'already_applied' => true,
            ];
        }

        $surecler = self::loadActiveIstenAyrilmaSurecler($pdo, $personelId);
        if (count($surecler) > 0) {
            $currentExit = self::resolveCurrentExitDate($pdo, $personelId, $surecler);
            if ($currentExit === $plan['exit_date']) {
                return [
                    'personel_id' => $personelId,
                    'surec_id' => (int) $surecler[0]['id'],
                    'action' => self::ACTION_ALREADY_APPLIED,
                    'exit_date' => $plan['exit_date'],
                    'already_applied' => true,
                ];
            }

            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Mevcut tarihi cikis kaydi ile celisen backfill tarihi.',
                self::ERROR_CONFLICT
            );
        }

        $surecId = self::insertHistoricalSurec($pdo, $personelId, $plan['exit_date'], $plan['aciklama']);

        // Personel stays PASIF — do not touch aktif_durum.
        ArchiveManifestService::createPersonelLifecycleManifests($pdo, $personelId, $actorUserId);

        return [
            'personel_id' => $personelId,
            'surec_id' => $surecId,
            'action' => self::ACTION_CREATE_HISTORICAL_SUREC,
            'exit_date' => $plan['exit_date'],
            'already_applied' => false,
        ];
    }

    private static function normalizeAndValidateExitDate(string $exitDate): string
    {
        $trimmed = trim($exitDate);
        if ($trimmed === '') {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Cikis tarihi zorunludur.',
                self::ERROR_EXIT_DATE_REQUIRED
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
                'Tarihi cikis backfill yalniz PASIF personel icin uygulanir.',
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
     * @param list<array<string, mixed>> $surecler
     */
    private static function resolveCurrentExitDate(PDO $pdo, int $personelId, array $surecler): ?string
    {
        $dates = [];
        foreach ($surecler as $row) {
            $date = self::normalizeDateOnly($row['baslangic_tarihi'] ?? null);
            if ($date !== null) {
                $dates[$date] = true;
            }
        }
        if (count($dates) > 1) {
            throw new PersonelValidationException(
                'surec_turu',
                'Birden fazla celisen ISTEN_AYRILMA tarihi var; backfill reddedildi.',
                self::ERROR_CONFLICT
            );
        }
        if (count($dates) === 1) {
            return array_key_first($dates);
        }

        if (self::columnExists($pdo, 'personeller', 'cikis_tarihi')) {
            $stmt = $pdo->prepare('SELECT cikis_tarihi FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return self::normalizeDateOnly($row['cikis_tarihi'] ?? null);
            }
        }

        return null;
    }

    private static function insertHistoricalSurec(
        PDO $pdo,
        int $personelId,
        string $exitDate,
        string $aciklama
    ): int {
        $sql = '
            INSERT INTO surecler (
                personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                ucretli_mi, tam_gun_mu, ilk_iki_gun_firma_oder_mi, aciklama, state
            ) VALUES (
                :personel_id, :surec_turu, :alt_tur, :baslangic_tarihi, :bitis_tarihi,
                :ucretli_mi, :tam_gun_mu, :ilk_iki_gun_firma_oder_mi, :aciklama, :state
            )
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'personel_id' => $personelId,
            'surec_turu' => 'ISTEN_AYRILMA',
            'alt_tur' => null,
            'baslangic_tarihi' => $exitDate,
            'bitis_tarihi' => $exitDate,
            'ucretli_mi' => 0,
            'tam_gun_mu' => null,
            'ilk_iki_gun_firma_oder_mi' => null,
            'aciklama' => $aciklama,
            'state' => 'AKTIF',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function buildAciklama(?string $aciklama): string
    {
        $trimmed = trim((string) $aciklama);
        if ($trimmed === '') {
            return self::BACKFILL_ACIKLAMA_PREFIX . ' Tarihi cikis tarihi residual backfill.';
        }
        if (strpos($trimmed, self::BACKFILL_ACIKLAMA_PREFIX) === 0) {
            return $trimmed;
        }

        return self::BACKFILL_ACIKLAMA_PREFIX . ' ' . $trimmed;
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

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        $cache[$key] = (bool) $stmt->fetchColumn();

        return $cache[$key];
    }
}
