<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\RetentionClock;
use PDO;

/**
 * Canonical owner for ISTEN_AYRILMA (deactivate + retention manifests).
 * Shared by SureclerController and lifecycle bulk apply.
 *
 * No future-effective scheduler: exit_date > today is denied (no surec / PASIF / retention).
 */
final class PersonelIstenAyrilmaService
{
    public const ERROR_EXIT_NOT_AKTIF = 'EXIT_PREIMAGE_NOT_AKTIF';
    public const ERROR_EXIT_ALREADY_ACTIVE = 'EXIT_ALREADY_ACTIVE';
    public const ERROR_EXIT_BEFORE_HIRE = 'EXIT_BEFORE_HIRE_DATE';
    public const ERROR_EXIT_IN_FUTURE = 'EXIT_DATE_IN_FUTURE';

    /**
     * Apply termination inside an existing transaction (caller owns begin/commit/rollback).
     *
     * @return array{surec_id:int, personel_id:int}
     */
    public static function applyInTransaction(
        PDO $pdo,
        int $personelId,
        string $exitDate,
        ?string $aciklama,
        int $actorUserId
    ): array {
        if (!PersonelCanonicalValidator::isValidDateString($exitDate)) {
            throw new PersonelValidationException('baslangic_tarihi', 'Gecerli bir tarih olmalidir.');
        }

        $today = RetentionClock::now()->format('Y-m-d');
        if ($exitDate > $today) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'İşten ayrılış tarihi ileri bir tarih olamaz.',
                self::ERROR_EXIT_IN_FUTURE
            );
        }

        $stmt = $pdo->prepare(
            'SELECT id, aktif_durum, ise_giris_tarihi FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($personel)) {
            throw new PersonelValidationException('personel_id', 'Personel bulunamadi.');
        }

        $aktifDurum = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        if ($aktifDurum !== 'AKTIF') {
            throw new PersonelValidationException(
                'personel_id',
                'Bu personel pasif; ayrilma kaydi eklenmez.',
                self::ERROR_EXIT_NOT_AKTIF
            );
        }

        $hireDate = self::normalizeDateOnly($personel['ise_giris_tarihi'] ?? null);
        if ($hireDate !== null && $exitDate < $hireDate) {
            throw new PersonelValidationException(
                'baslangic_tarihi',
                'Ayrilis tarihi ise giris tarihinden once olamaz.',
                self::ERROR_EXIT_BEFORE_HIRE
            );
        }

        $dup = $pdo->prepare(
            "SELECT id FROM surecler
             WHERE personel_id = :pid AND surec_turu = 'ISTEN_AYRILMA' AND state = 'AKTIF'
             LIMIT 1"
        );
        $dup->execute(['pid' => $personelId]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) {
            throw new PersonelValidationException(
                'surec_turu',
                'Aktif isten ayrilma kaydi zaten var.',
                self::ERROR_EXIT_ALREADY_ACTIVE
            );
        }

        $surecId = self::insertSurec($pdo, [
            'personel_id' => $personelId,
            'surec_turu' => 'ISTEN_AYRILMA',
            'alt_tur' => null,
            'baslangic_tarihi' => $exitDate,
            'bitis_tarihi' => $exitDate,
            'ucretli_mi' => false,
            'tam_gun_mu' => null,
            'ilk_iki_gun_firma_oder_mi' => null,
            'aciklama' => $aciklama,
        ]);

        $deactivate = $pdo->prepare("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = :id AND aktif_durum = 'AKTIF'");
        $deactivate->execute(['id' => $personelId]);
        if ($deactivate->rowCount() !== 1) {
            throw new PersonelValidationException(
                'personel_id',
                'Bu personel pasif; ayrilma kaydi eklenmez.',
                self::ERROR_EXIT_NOT_AKTIF
            );
        }

        ArchiveManifestService::createPersonelLifecycleManifests($pdo, $personelId, $actorUserId);

        return ['surec_id' => $surecId, 'personel_id' => $personelId];
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

    /** @param array<string, mixed> $payload */
    private static function insertSurec(PDO $pdo, array $payload): int
    {
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
            'personel_id' => (int) $payload['personel_id'],
            'surec_turu' => (string) $payload['surec_turu'],
            'alt_tur' => $payload['alt_tur'],
            'baslangic_tarihi' => (string) $payload['baslangic_tarihi'],
            'bitis_tarihi' => $payload['bitis_tarihi'],
            'ucretli_mi' => !empty($payload['ucretli_mi']) ? 1 : 0,
            'tam_gun_mu' => $payload['tam_gun_mu'] === null ? null : ($payload['tam_gun_mu'] ? 1 : 0),
            'ilk_iki_gun_firma_oder_mi' => $payload['ilk_iki_gun_firma_oder_mi'] === null
                ? null
                : ($payload['ilk_iki_gun_firma_oder_mi'] ? 1 : 0),
            'aciklama' => $payload['aciklama'],
            'state' => 'AKTIF',
        ]);

        return (int) $pdo->lastInsertId();
    }
}
