<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Services\Retention\ArchiveManifestService;
use PDO;

/**
 * Canonical owner for ISTEN_AYRILMA (deactivate + retention manifests).
 * Shared by SureclerController and lifecycle bulk apply.
 */
final class PersonelIstenAyrilmaService
{
    /**
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

        $stmt = $pdo->prepare('SELECT id, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($personel)) {
            throw new PersonelValidationException('personel_id', 'Personel bulunamadi.');
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

        if (strtoupper((string) ($personel['aktif_durum'] ?? '')) === 'AKTIF') {
            $deactivate = $pdo->prepare("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = :id");
            $deactivate->execute(['id' => $personelId]);
            ArchiveManifestService::createPersonelLifecycleManifests($pdo, $personelId, $actorUserId);
        }

        return ['surec_id' => $surecId, 'personel_id' => $personelId];
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
