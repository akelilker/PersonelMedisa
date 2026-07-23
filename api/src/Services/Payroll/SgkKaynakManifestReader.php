<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;
use PDOException;
use RuntimeException;

/**
 * S85-C1: Read SGK source manifests without masking storage failures as an empty catalog.
 */
final class SgkKaynakManifestReader
{
    public const STORAGE_ERROR_CODE = 'SGK_KAYNAK_MANIFEST_STORAGE_HATASI';

    /**
     * @return list<array<string,mixed>>
     * @throws RuntimeException with message STORAGE_ERROR_CODE when schema/query/connection fails
     */
    public static function fetchAll(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                "SELECT kaynak_id, kaynak_turu, kurum, belge_basligi, belge_tarihi, yayimlanma_tarihi,
                        yururluk_baslangic, yururluk_bitis, kaynak_adresi,
                        indirilen_dosya_sha256, icerik_sha256, indirilen_dosya_byte,
                        durum, dogrulama_turu, observed_at, arsiv_kopyasi_repoda_mi, aciklama
                 FROM sgk_kaynak_manifestleri
                 ORDER BY kaynak_id ASC"
            );

            return self::hydrate($stmt);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === self::STORAGE_ERROR_CODE) {
                throw $e;
            }
            throw self::storageError($e);
        } catch (PDOException $e) {
            throw self::storageError($e);
        } catch (\Throwable $e) {
            throw self::storageError($e);
        }
    }

    /**
     * @param mixed $stmt PDOStatement|false|object with fetchAll()
     * @return list<array<string,mixed>>
     */
    public static function hydrate($stmt): array
    {
        if ($stmt === false || $stmt === null) {
            throw new RuntimeException(self::STORAGE_ERROR_CODE);
        }
        if (!is_object($stmt) || !method_exists($stmt, 'fetchAll')) {
            throw new RuntimeException(self::STORAGE_ERROR_CODE);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false) {
            throw new RuntimeException(self::STORAGE_ERROR_CODE);
        }

        return array_map(static function (array $r): array {
            $r['arsiv_kopyasi_repoda_mi'] = (bool) ($r['arsiv_kopyasi_repoda_mi'] ?? false);
            return $r;
        }, $rows);
    }

    public static function storageError(\Throwable $previous): RuntimeException
    {
        return new RuntimeException(self::STORAGE_ERROR_CODE, 0, $previous);
    }

    /**
     * Single-line sanitized server log for SGK catalog runtime failures.
     * Never includes message, SQL, DSN, credentials, headers, body, or stack trace.
     */
    public static function formatSanitizedRuntimeLog(
        string $action,
        \Throwable $e,
        string $ownerClass = self::class
    ): string {
        $target = $e;
        if ($e->getPrevious() instanceof \Throwable) {
            $target = $e->getPrevious();
        }

        $sqlstate = '';
        $driverCode = '';
        if ($target instanceof PDOException) {
            $info = $target->errorInfo;
            if (is_array($info)) {
                if (isset($info[0]) && is_scalar($info[0])) {
                    $sqlstate = (string) $info[0];
                }
                if (isset($info[1]) && is_scalar($info[1])) {
                    $driverCode = (string) $info[1];
                }
            }
            if ($sqlstate === '') {
                $pdoCode = $target->getCode();
                if (is_string($pdoCode) && preg_match('/^[0-9A-Z]{5}$/', $pdoCode) === 1) {
                    $sqlstate = $pdoCode;
                }
            }
        }

        $rawCode = $target->getCode();
        $exceptionCode = is_int($rawCode)
            ? (string) $rawCode
            : (is_numeric($rawCode) ? (string) (int) $rawCode : '0');

        $nsPos = strrpos($ownerClass, '\\');
        $ownerShort = $nsPos === false
            ? $ownerClass
            : substr($ownerClass, $nsPos + 1);

        return sprintf(
            'SGK_KATALOG_RUNTIME_EXCEPTION action=%s exception_class=%s exception_code=%s sqlstate=%s driver_code=%s owner_class=%s file=%s line=%d',
            self::sanitizeLogToken($action),
            self::sanitizeLogToken(get_class($target)),
            self::sanitizeLogToken($exceptionCode),
            self::sanitizeLogToken($sqlstate),
            self::sanitizeLogToken($driverCode),
            self::sanitizeLogToken($ownerShort),
            self::sanitizeLogToken(basename($target->getFile())),
            (int) $target->getLine()
        );
    }

    private static function sanitizeLogToken(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\\\\.{}\/-]+/', '_', $value);
        return $clean === null || $clean === '' ? '-' : $clean;
    }
}
