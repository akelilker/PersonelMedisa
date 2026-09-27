<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use RuntimeException;

/**
 * Profile portrait bytes. Separate from personel belge storage.
 * Opaque key only; callers never receive a filesystem path or public URL.
 */
final class PersonelProfilFotoStorageService
{
    public const MAX_BYTES = 2097152;

    public static function storageRoot()
    {
        $envRoot = getenv('MEDISA_PROFIL_FOTO_STORAGE_ROOT');
        if (is_string($envRoot) && trim($envRoot) !== '') {
            return rtrim($envRoot, "\\/");
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'personel-profil-fotograflari';
    }

    public static function ensureRoot()
    {
        $root = self::storageRoot();
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('PROFIL_FOTO_STORAGE_HATASI');
        }

        return $root;
    }

    /**
     * @return array{storage_key:string,sha256:string,byte_boyutu:int,mime_type:string}
     */
    public static function writeImage($bytes, $extension)
    {
        $byteLen = strlen((string) $bytes);
        if ($bytes === '' || $byteLen > self::MAX_BYTES) {
            throw new RuntimeException('PROFIL_FOTO_BOYUT');
        }
        $extension = strtolower((string) $extension);
        if (!in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('PROFIL_FOTO_TIP');
        }

        $root = self::ensureRoot();
        $realRoot = realpath($root);
        if ($realRoot === false) {
            throw new RuntimeException('PROFIL_FOTO_STORAGE_HATASI');
        }
        $key = bin2hex(random_bytes(16)) . '.' . $extension;
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $key)) {
            throw new RuntimeException('PROFIL_FOTO_KEY_GECERSIZ');
        }
        $absolute = $realRoot . DIRECTORY_SEPARATOR . $key;
        $tmp = $absolute . '.tmp.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmp, $bytes, LOCK_EX);
        if ($written === false || $written !== $byteLen) {
            @unlink($tmp);
            throw new RuntimeException('PROFIL_FOTO_STORAGE_HATASI');
        }
        if (!@rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new RuntimeException('PROFIL_FOTO_STORAGE_HATASI');
        }

        return [
            'storage_key' => $key,
            'sha256' => hash('sha256', (string) $bytes),
            'byte_boyutu' => $byteLen,
            'mime_type' => $extension === 'png' ? 'image/png' : ($extension === 'webp' ? 'image/webp' : 'image/jpeg'),
        ];
    }

    public static function resolvePath($storageKey)
    {
        $key = trim((string) $storageKey);
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $key)) {
            throw new RuntimeException('PROFIL_FOTO_KEY_GECERSIZ');
        }
        $root = self::ensureRoot();
        $absolute = $root . DIRECTORY_SEPARATOR . $key;
        $realRoot = realpath($root);
        $realFile = realpath($absolute);
        if ($realRoot === false || $realFile === false) {
            throw new RuntimeException('PROFIL_FOTO_BULUNAMADI');
        }
        $prefix = $realRoot . DIRECTORY_SEPARATOR;
        if (strpos($realFile, $prefix) !== 0) {
            throw new RuntimeException('PROFIL_FOTO_PATH_GECERSIZ');
        }

        return $realFile;
    }

    public static function readBytes($storageKey)
    {
        $path = self::resolvePath($storageKey);
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('PROFIL_FOTO_BULUNAMADI');
        }

        return $bytes;
    }

    public static function deleteKey($storageKey)
    {
        try {
            $path = self::resolvePath($storageKey);
            @unlink($path);
        } catch (\Throwable $e) {
            // Missing file must not block a replacement row update.
        }
    }
}
