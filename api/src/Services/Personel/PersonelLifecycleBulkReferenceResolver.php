<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Controllers\ReferansController;
use PDO;

/**
 * Name→ID resolution for lifecycle bulk apply (no duplicate catalog writes when canonical match exists).
 */
final class PersonelLifecycleBulkReferenceResolver
{
    /**
     * @return int|null Active users.id for manager display name; null when unresolved (no account fabrication).
     */
    public static function resolveBagliAmirUserId(PDO $pdo, string $displayName): ?int
    {
        $needle = self::normalizeName($displayName);
        if ($needle === '') {
            return null;
        }
        $asciiNeedle = self::searchKey($displayName);

        $stmt = $pdo->query(
            "SELECT id, ad_soyad FROM users
             WHERE durum = 'AKTIF'
               AND rol IN ('GENEL_YONETICI', 'BOLUM_YONETICISI', 'BIRIM_AMIRI', 'MUHASEBE', 'SUBE_YONETICISI', 'IK_SORUMLUSU')"
        );
        if ($stmt === false) {
            return null;
        }
        $matchedIds = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = (string) ($row['ad_soyad'] ?? '');
            if (self::normalizeName($candidate) === $needle || self::searchKey($candidate) === $asciiNeedle) {
                $matchedIds[(int) $row['id']] = true;
            }
        }

        $ids = array_keys($matchedIds);

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    public static function resolveActiveIdByName(PDO $pdo, string $table, string $name): ?int
    {
        $needle = self::normalizeName($name);
        if ($needle === '') {
            return null;
        }

        $stmt = $pdo->query("SELECT id, ad FROM {$table} WHERE durum = 'AKTIF'");
        if ($stmt === false) {
            return null;
        }
        $asciiNeedle = self::searchKey($name);
        $matchedIds = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = self::normalizeName((string) ($row['ad'] ?? ''));
            if ($candidate === $needle || self::searchKey((string) ($row['ad'] ?? '')) === $asciiNeedle) {
                $matchedIds[(int) $row['id']] = true;
            }
        }

        $ids = array_keys($matchedIds);
        if (count($ids) === 1) {
            return (int) $ids[0];
        }

        return null;
    }

    public static function searchKey(string $value): string
    {
        return self::asciiFold(self::normalizeName($value));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{id:int, ad:string, created:bool}
     */
    public static function resolveOrCreateCatalog(PDO $pdo, string $table, array $body): array
    {
        $ad = trim((string) ($body['ad'] ?? ''));
        if ($ad === '') {
            throw new PersonelValidationException('ad', 'Referans adi zorunludur.');
        }

        $existing = self::resolveActiveIdByName($pdo, $table, $ad);
        if ($existing !== null) {
            return ['id' => $existing, 'ad' => $ad, 'created' => false];
        }

        switch ($table) {
            case 'departmanlar':
                $record = ReferansController::createDepartmanRecord($pdo, ['ad' => $ad]);
                break;
            case 'gorevler':
                $record = ReferansController::createGorevRecord($pdo, ['ad' => $ad]);
                break;
            case 'pozisyonlar':
                $record = ReferansController::createPozisyonRecord($pdo, ['ad' => $ad]);
                break;
            default:
                throw new PersonelValidationException('referans_turu', 'Desteklenmeyen referans turu.');
        }

        return ['id' => (int) $record['id'], 'ad' => (string) $record['ad'], 'created' => true];
    }

    public static function normalizeName(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strtoupper($value, 'UTF-8');
    }

    private static function asciiFold(string $value): string
    {
        $map = [
            'İ' => 'I', 'I' => 'I', 'Ş' => 'S', 'Ğ' => 'G', 'Ü' => 'U', 'Ö' => 'O', 'Ç' => 'C',
            'ı' => 'I', 'ş' => 'S', 'ğ' => 'G', 'ü' => 'U', 'ö' => 'O', 'ç' => 'C',
        ];

        return strtr($value, $map);
    }
}
