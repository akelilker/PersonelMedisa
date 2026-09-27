<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * One portrait per personel. Not used for attendance, face matching, or embeddings.
 */
class PersonelProfilFotoService
{
    /**
     * @return array{has_photo:bool,mime_type:?string,image_base64:?string}
     */
    public static function read(PDO $pdo, $personelId)
    {
        self::assertSchema($pdo);
        $row = self::fetchRow($pdo, (int) $personelId);
        if (!$row) {
            return [
                'has_photo' => false,
                'mime_type' => null,
                'image_base64' => null,
            ];
        }
        try {
            $bytes = PersonelProfilFotoStorageService::readBytes((string) $row['storage_key']);
        } catch (\Throwable $e) {
            return [
                'has_photo' => false,
                'mime_type' => null,
                'image_base64' => null,
            ];
        }

        return [
            'has_photo' => true,
            'mime_type' => (string) $row['mime_type'],
            'image_base64' => base64_encode($bytes),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{has_photo:bool,mime_type:?string,image_base64:?string}
     */
    public static function upsert(PDO $pdo, $personelId, array $body)
    {
        self::assertSchema($pdo);
        $personelId = (int) $personelId;
        unset($body['personel_id'], $body['storage_key'], $body['path']);
        $parsed = self::decodeImage(isset($body['dosya_icerik_base64']) ? $body['dosya_icerik_base64'] : null);
        $stored = PersonelProfilFotoStorageService::writeImage($parsed['bytes'], $parsed['extension']);
        $previous = self::fetchRow($pdo, $personelId);

        if ($previous) {
            $stmt = $pdo->prepare(
                'UPDATE personel_profil_fotograflari
                 SET storage_key = :key, mime_type = :mime, byte_boyutu = :bytes, sha256 = :sha
                 WHERE personel_id = :pid'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO personel_profil_fotograflari (personel_id, storage_key, mime_type, byte_boyutu, sha256)
                 VALUES (:pid, :key, :mime, :bytes, :sha)'
            );
        }
        $stmt->execute([
            'pid' => $personelId,
            'key' => $stored['storage_key'],
            'mime' => $parsed['mime'],
            'bytes' => $stored['byte_boyutu'],
            'sha' => $stored['sha256'],
        ]);
        if ($previous && (string) $previous['storage_key'] !== $stored['storage_key']) {
            PersonelProfilFotoStorageService::deleteKey((string) $previous['storage_key']);
        }

        return self::read($pdo, $personelId);
    }

    private static function assertSchema(PDO $pdo)
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'personel_profil_fotograflari'");
        if ($stmt === false || $stmt->fetch(PDO::FETCH_NUM) === false) {
            throw new PersonelSelfProductException(
                'PROFIL_FOTO_SCHEMA_NOT_READY',
                'Profil fotografi semasi hazir degil.',
                503
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchRow(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT personel_id, storage_key, mime_type
             FROM personel_profil_fotograflari
             WHERE personel_id = :pid
             LIMIT 1'
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param mixed $encoded
     * @return array{bytes:string,mime:string,extension:string}
     */
    private static function decodeImage($encoded)
    {
        $raw = trim((string) $encoded);
        if (strpos($raw, ',') !== false && stripos($raw, 'base64') !== false) {
            $raw = substr($raw, (int) strrpos($raw, ',') + 1);
        }
        $bytes = base64_decode($raw, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > PersonelProfilFotoStorageService::MAX_BYTES) {
            throw new PersonelSelfProductException(
                'VALIDATION_ERROR',
                'Gorsel boyutu veya icerigi gecersiz.',
                422,
                'dosya_icerik_base64'
            );
        }

        $info = @getimagesizefromstring($bytes);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($bytes);
        $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;
        $map = [
            IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
            IMAGETYPE_PNG => ['image/png', 'png'],
            IMAGETYPE_WEBP => ['image/webp', 'webp'],
        ];
        if (!isset($map[$type]) || $detected !== $map[$type][0]) {
            throw new PersonelSelfProductException(
                'VALIDATION_ERROR',
                'Yalniz JPEG, PNG veya WEBP kabul edilir.',
                422,
                'dosya_icerik_base64'
            );
        }

        return [
            'bytes' => $bytes,
            'mime' => $map[$type][0],
            'extension' => $map[$type][1],
        ];
    }
}
