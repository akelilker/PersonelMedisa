<?php

declare(strict_types=1);

namespace Medisa\Api\Services\PersonelBelge;

use RuntimeException;

/**
 * S86: Opaque on-disk storage for personel belge binaries (outside public web root).
 */
final class PersonelBelgeStorageService
{
    public static function storageRoot(): string
    {
        // Prefer already-bootstrapped config (api/src/bootstrap.php). Avoid require-inside-method
        // because config.php assigns $config locally and medisa_config() reads global $config.
        if (function_exists('medisa_config')) {
            $configured = medisa_config('personel_belge_storage_root');
            if (is_string($configured) && trim($configured) !== '') {
                return rtrim($configured, "\\/");
            }
        }

        // Default: api/storage/personel-belgeler (not under api/public).
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'personel-belgeler';
    }

    public static function ensureRoot(): string
    {
        $root = self::storageRoot();
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('PERSONEL_BELGE_STORAGE_HATASI');
        }

        return $root;
    }

    /**
     * @return array{storage_key:string,absolute_path:string,sha256:string,byte_boyutu:int}
     */
    public static function writeNewVersion(string $bytes, string $extension): array
    {
        if ($bytes === '') {
            throw new RuntimeException('PERSONEL_BELGE_DOSYA_BOS');
        }
        $byteLen = strlen($bytes);
        if ($byteLen > PersonelBelgeContracts::MAX_DECODED_BYTES) {
            throw new RuntimeException('PERSONEL_BELGE_DOSYA_BOYUTU_ASILDI');
        }

        $root = self::ensureRoot();
        $key = bin2hex(random_bytes(16)) . '.' . strtolower($extension);
        if (strpos($key, '..') !== false || strpos($key, '/') !== false || strpos($key, '\\') !== false) {
            throw new RuntimeException('PERSONEL_BELGE_STORAGE_HATASI');
        }

        $absolute = $root . DIRECTORY_SEPARATOR . $key;
        $tmp = $absolute . '.tmp.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmp, $bytes, LOCK_EX);
        if ($written === false || $written !== $byteLen) {
            @unlink($tmp);
            throw new RuntimeException('PERSONEL_BELGE_STORAGE_HATASI');
        }
        if (!@rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new RuntimeException('PERSONEL_BELGE_STORAGE_HATASI');
        }

        return [
            'storage_key' => $key,
            'absolute_path' => $absolute,
            'sha256' => hash('sha256', $bytes),
            'byte_boyutu' => $byteLen,
        ];
    }

    public static function resolvePath(string $storageKey): string
    {
        $key = trim($storageKey);
        if ($key === '' || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,16}$/', $key)) {
            throw new RuntimeException('PERSONEL_BELGE_STORAGE_KEY_GECERSIZ');
        }

        $root = self::ensureRoot();
        $absolute = $root . DIRECTORY_SEPARATOR . $key;
        $realRoot = realpath($root);
        $realFile = realpath($absolute);
        if ($realRoot === false || $realFile === false) {
            throw new RuntimeException('PERSONEL_BELGE_DOSYA_BULUNAMADI');
        }
        $prefix = $realRoot . DIRECTORY_SEPARATOR;
        if (strpos($realFile, $prefix) !== 0) {
            throw new RuntimeException('PERSONEL_BELGE_PATH_GECERSIZ');
        }

        return $realFile;
    }

    public static function readBytes(string $storageKey): string
    {
        $path = self::resolvePath($storageKey);
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('PERSONEL_BELGE_DOSYA_BULUNAMADI');
        }

        return $bytes;
    }

    public static function deleteKey(string $storageKey): void
    {
        try {
            $path = self::resolvePath($storageKey);
            @unlink($path);
        } catch (RuntimeException $e) {
            // Best-effort orphan cleanup; ignore missing file.
        }
    }
}
