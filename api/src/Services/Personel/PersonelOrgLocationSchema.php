<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Pack5 Track B — SGK isveren / calisma lokasyonu schema readiness owner.
 * Unconditional JOIN/SELECT/INSERT against new tables is forbidden until ready.
 */
final class PersonelOrgLocationSchema
{
    public const ERROR_CODE = 'ORG_LOCATION_SCHEMA_NOT_READY';

    public static function isReady(PDO $pdo): bool
    {
        return self::tableExists($pdo, 'sgk_isverenler')
            && self::tableExists($pdo, 'calisma_lokasyonlari')
            && self::columnExists($pdo, 'personeller', 'sgk_isveren_id')
            && self::columnExists($pdo, 'personeller', 'calisma_lokasyonu_id');
    }

    /**
     * True when payload explicitly attempts to write new org-location fields.
     *
     * @param array<string, mixed> $payload
     */
    public static function payloadRequestsOrgFields(array $payload): bool
    {
        foreach (['sgk_isveren_id', 'calisma_lokasyonu_id', 'sgk_isveren', 'calisma_lokasyonu'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $v = $payload[$key];
            if ($v === null || $v === '') {
                // Explicit null/blank still counts as a write intent when key present
                return true;
            }

            return true;
        }

        return false;
    }

    /**
     * Fail-closed gate for shared create/update owners.
     * Explicit org-field write when schema is missing is not a generic validation miss.
     *
     * @param array<string, mixed> $payload
     * @throws PersonelValidationException
     */
    public static function assertReadyForOrgWrite(PDO $pdo, array $payload): void
    {
        if (!self::payloadRequestsOrgFields($payload)) {
            return;
        }
        if (self::isReady($pdo)) {
            return;
        }
        throw new PersonelValidationException(
            'sgk_isveren_id',
            'Org location schema hazir degil; sgk_isveren_id / calisma_lokasyonu_id yazilamaz.',
            self::ERROR_CODE
        );
    }

    public static function existsActiveSgkIsveren(PDO $pdo, int $id): bool
    {
        if ($id < 1 || !self::isReady($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id FROM sgk_isverenler WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function existsActiveCalismaLokasyonu(PDO $pdo, int $id): bool
    {
        if ($id < 1 || !self::isReady($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id FROM calisma_lokasyonlari WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t"
                );
                $stmt->execute(['t' => $table]);

                return (int) $stmt->fetchColumn() === 1;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t'
            );
            $stmt->execute(['t' => $table]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }

                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
