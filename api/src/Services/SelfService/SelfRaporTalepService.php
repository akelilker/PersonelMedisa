<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use Medisa\Api\Services\PersonelBelge\PersonelBelgeBase64Guard;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeContracts;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeKayitRepository;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;
use PDO;

/**
 * PERSONEL health report (RAPOR / Raporlu_Hastalik) — not IS_KAZASI.
 */
class SelfRaporTalepService
{
    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, array $ctx, array $user, array $body)
    {
        $personelId = (int) $ctx['personel_id'];
        $baslangic = self::requireDate($body, 'baslangic_tarihi');
        $bitis = array_key_exists('bitis_tarihi', $body) && trim((string) $body['bitis_tarihi']) !== ''
            ? self::requireDate($body, 'bitis_tarihi')
            : $baslangic;
        if ($bitis < $baslangic) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Bitis tarihi gecersiz.', 422, 'bitis_tarihi');
        }
        $aciklama = isset($body['aciklama']) ? trim((string) $body['aciklama']) : null;
        if ($aciklama === '') {
            $aciklama = null;
        } elseif (function_exists('mb_strlen') ? mb_strlen($aciklama) > 500 : strlen($aciklama) > 500) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Aciklama cok uzun.', 422, 'aciklama');
        }

        $filePayload = self::parseOptionalFile($body);
        $belgeSurecId = null;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO surecler (
                    personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                    ucretli_mi, tam_gun_mu, aciklama, state
                ) VALUES (
                    :personel_id, \'RAPOR\', \'Raporlu_Hastalik\', :baslangic, :bitis,
                    0, 1, :aciklama, \'AKTIF\'
                )'
            );
            $stmt->execute([
                'personel_id' => $personelId,
                'baslangic' => $baslangic,
                'bitis' => $bitis,
                'aciklama' => $aciklama,
            ]);
            $raporId = (int) $pdo->lastInsertId();

            if ($filePayload !== null) {
                PersonelBelgeKayitRepository::ensureSchemaReady($pdo);
                $belgeStmt = $pdo->prepare(
                    'INSERT INTO surecler (
                        personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                        ucretli_mi, aciklama, state
                    ) VALUES (
                        :personel_id, \'BELGE\', \'SAGLIK_RAPORU\', :baslangic, :bitis,
                        0, :aciklama, \'AKTIF\'
                    )'
                );
                $meta = json_encode([
                    '_personel_belge_kaydi' => true,
                    'kayit_tipi' => 'SAGLIK_RAPORU',
                    'ad' => $filePayload['original_name'],
                    'linked_rapor_surec_id' => $raporId,
                ], JSON_UNESCAPED_UNICODE);
                $belgeStmt->execute([
                    'personel_id' => $personelId,
                    'baslangic' => $baslangic,
                    'bitis' => $bitis,
                    'aciklama' => $meta,
                ]);
                $belgeSurecId = (int) $pdo->lastInsertId();
                $stored = PersonelBelgeStorageService::writeNewVersion(
                    $filePayload['bytes'],
                    $filePayload['extension']
                );
                $userId = isset($user['id']) ? (int) $user['id'] : 0;
                PersonelBelgeKayitRepository::insertVersion($pdo, [
                    'surec_id' => $belgeSurecId,
                    'personel_id' => $personelId,
                    'surum_no' => 1,
                    'aktif_mi' => true,
                    'storage_key' => $stored['storage_key'],
                    'orijinal_dosya_adi' => $filePayload['original_name'],
                    'mime_type' => $filePayload['mime'],
                    'uzanti' => $filePayload['extension'],
                    'byte_boyutu' => $stored['byte_boyutu'],
                    'sha256' => $stored['sha256'],
                    'yukleyen_kullanici_id' => $userId > 0 ? $userId : null,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $row = [
            'id' => $raporId,
            'surec_turu' => 'RAPOR',
            'alt_tur' => 'Raporlu_Hastalik',
            'baslangic_tarihi' => $baslangic,
            'bitis_tarihi' => $bitis,
            'state' => 'AKTIF',
            'aciklama' => $aciklama,
            'belge_surec_id' => $belgeSurecId,
        ];

        SelfRequestInboxNotifier::notifyRaporRequest($pdo, $ctx, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private static function parseOptionalFile(array $body)
    {
        $hasAny = array_key_exists('dosya_icerik_base64', $body)
            || array_key_exists('dosya_adi', $body)
            || array_key_exists('dosya_mime', $body);
        if (!$hasAny) {
            return null;
        }
        $encoded = trim((string) ($body['dosya_icerik_base64'] ?? ''));
        $originalName = trim((string) ($body['dosya_adi'] ?? ''));
        $claimedMime = trim((string) ($body['dosya_mime'] ?? 'application/octet-stream'));
        if ($encoded === '' || $originalName === '') {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Dosya alanlari eksik.', 422, 'dosya_adi');
        }
        $decoded = PersonelBelgeBase64Guard::decode($encoded);
        if (empty($decoded['ok'])) {
            throw new PersonelSelfProductException(
                (string) ($decoded['code'] ?? 'VALIDATION_ERROR'),
                (string) ($decoded['message'] ?? 'Dosya gecersiz.'),
                (int) ($decoded['http'] ?? 422),
                'dosya_icerik_base64'
            );
        }
        $validated = PersonelBelgeContracts::validateFilenameAndMime($originalName, $claimedMime);
        if (empty($validated['ok'])) {
            throw new PersonelSelfProductException(
                (string) $validated['code'],
                (string) $validated['message'],
                422,
                'dosya_adi'
            );
        }
        $bytes = (string) $decoded['bytes'];
        $extension = (string) $validated['extension'];
        if (!PersonelBelgeContracts::validateContentMagic($bytes, $extension)) {
            throw new PersonelSelfProductException(
                'PERSONEL_BELGE_ICERIK_GECERSIZ',
                'Dosya icerigi uzantisi ile uyusmuyor.',
                422,
                'dosya_icerik_base64'
            );
        }

        return [
            'bytes' => $bytes,
            'original_name' => $originalName,
            'mime' => (string) $validated['mime'],
            'extension' => $extension,
        ];
    }

    /** @param array<string, mixed> $body */
    private static function requireDate(array $body, $field)
    {
        $raw = trim((string) ($body[$field] ?? ''));
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($raw === '' || $dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Tarih YYYY-MM-DD olmalidir.', 422, $field);
        }

        return $raw;
    }
}
