<?php

declare(strict_types=1);

namespace Medisa\Api\Services\PersonelBelge;

use Medisa\Api\Services\SelfService\PersonelSelfProductException;
use PDO;
use RuntimeException;

/**
 * Canonical belge owner path for self-service sağlık raporu attachments linked to a RAPOR surec.
 */
final class PersonelBelgeLinkedRaporAttachmentService
{
    /**
     * @param array<string, mixed> $body
     * @return int|null belge surec id
     */
    public static function attachOptionalFile(
        PDO $pdo,
        $personelId,
        $userId,
        $raporSurecId,
        $baslangic,
        $bitis,
        array $body,
        $manageTransaction = true
    ) {
        $filePayload = self::parseOptionalFilePayload($body);
        if ($filePayload === null) {
            return null;
        }

        PersonelBelgeKayitRepository::ensureSchemaReady($pdo);
        $personelId = (int) $personelId;
        $raporSurecId = (int) $raporSurecId;
        $userId = (int) $userId;
        $orphanStorageKey = null;

        $ownsTx = $manageTransaction && !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $meta = [
                '_personel_belge_kaydi' => true,
                'kayit_tipi' => 'SAGLIK_RAPORU',
                'ad' => $filePayload['original_name'],
                'baslangic_tarihi' => $baslangic,
                'bitis_tarihi' => $bitis,
                'linked_rapor_surec_id' => $raporSurecId,
            ];
            $stmt = $pdo->prepare(
                'INSERT INTO surecler (
                    personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                    ucretli_mi, aciklama, state
                ) VALUES (
                    :personel_id, \'BELGE\', \'SAGLIK_RAPORU\', :baslangic, :bitis,
                    0, :aciklama, \'AKTIF\'
                )'
            );
            $stmt->execute([
                'personel_id' => $personelId,
                'baslangic' => $baslangic,
                'bitis' => $bitis,
                'aciklama' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
            $belgeSurecId = (int) $pdo->lastInsertId();

            $stored = PersonelBelgeStorageService::writeNewVersion(
                $filePayload['bytes'],
                $filePayload['extension']
            );
            $orphanStorageKey = $stored['storage_key'];

            $surumId = PersonelBelgeKayitRepository::insertVersion($pdo, [
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

            PersonelBelgeKayitRepository::insertAudit(
                $pdo,
                $belgeSurecId,
                $personelId,
                PersonelBelgeContracts::AUDIT_CREATED,
                null,
                $meta,
                $userId > 0 ? $userId : null,
                null,
                $surumId,
                $stored['sha256'],
                $stored['byte_boyutu'],
                $filePayload['mime']
            );

            $orphanStorageKey = null;
            if ($ownsTx) {
                $pdo->commit();
            }

            return $belgeSurecId;
        } catch (RuntimeException $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($orphanStorageKey !== null) {
                PersonelBelgeStorageService::deleteKey($orphanStorageKey);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($orphanStorageKey !== null) {
                PersonelBelgeStorageService::deleteKey($orphanStorageKey);
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private static function parseOptionalFilePayload(array $body)
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
}
