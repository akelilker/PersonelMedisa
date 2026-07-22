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
    public static function hydrate(mixed $stmt): array
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
}
