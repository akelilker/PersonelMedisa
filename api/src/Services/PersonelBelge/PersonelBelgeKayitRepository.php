<?php

declare(strict_types=1);

namespace Medisa\Api\Services\PersonelBelge;

use PDO;
use RuntimeException;

/**
 * S86: DB helpers for belge kaydı versions + audit (extends surecler BELGE rows).
 */
final class PersonelBelgeKayitRepository
{
    public static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function ensureSchemaReady(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'personel_belge_dosya_surumleri')
            || !self::tableExists($pdo, 'personel_belge_auditleri')) {
            throw new RuntimeException('PERSONEL_BELGE_SCHEMA_EKSIK');
        }
    }

    /** @return array<string,mixed>|null */
    public static function fetchActiveVersion(PDO $pdo, int $surecId): ?array
    {
        if (!self::tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            return null;
        }
        $stmt = $pdo->prepare('
            SELECT id, surec_id, personel_id, surum_no, aktif_mi, storage_key, orijinal_dosya_adi,
                   mime_type, uzanti, byte_boyutu, sha256, yukleyen_kullanici_id, created_at
            FROM personel_belge_dosya_surumleri
            WHERE surec_id = :surec_id AND aktif_mi = 1
            ORDER BY surum_no DESC
            LIMIT 1
        ');
        $stmt->execute(['surec_id' => $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public static function listVersions(PDO $pdo, int $surecId): array
    {
        if (!self::tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            return [];
        }
        $stmt = $pdo->prepare('
            SELECT id, surec_id, personel_id, surum_no, aktif_mi, orijinal_dosya_adi,
                   mime_type, uzanti, byte_boyutu, sha256, yukleyen_kullanici_id, created_at
            FROM personel_belge_dosya_surumleri
            WHERE surec_id = :surec_id
            ORDER BY surum_no DESC
        ');
        $stmt->execute(['surec_id' => $surecId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public static function fetchVersionById(PDO $pdo, int $versionId, int $surecId): ?array
    {
        $stmt = $pdo->prepare('
            SELECT id, surec_id, personel_id, surum_no, aktif_mi, storage_key, orijinal_dosya_adi,
                   mime_type, uzanti, byte_boyutu, sha256, yukleyen_kullanici_id, created_at
            FROM personel_belge_dosya_surumleri
            WHERE id = :id AND surec_id = :surec_id
            LIMIT 1
        ');
        $stmt->execute(['id' => $versionId, 'surec_id' => $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function nextSurumNo(PDO $pdo, int $surecId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(surum_no), 0) FROM personel_belge_dosya_surumleri WHERE surec_id = :id');
        $stmt->execute(['id' => $surecId]);

        return ((int) $stmt->fetchColumn()) + 1;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function insertVersion(PDO $pdo, array $payload): int
    {
        if (!empty($payload['aktif_mi'])) {
            $deactivate = $pdo->prepare('UPDATE personel_belge_dosya_surumleri SET aktif_mi = 0 WHERE surec_id = :surec_id');
            $deactivate->execute(['surec_id' => (int) $payload['surec_id']]);
        }

        $stmt = $pdo->prepare('
            INSERT INTO personel_belge_dosya_surumleri (
                surec_id, personel_id, surum_no, aktif_mi, storage_key, orijinal_dosya_adi,
                mime_type, uzanti, byte_boyutu, sha256, yukleyen_kullanici_id
            ) VALUES (
                :surec_id, :personel_id, :surum_no, :aktif_mi, :storage_key, :orijinal_dosya_adi,
                :mime_type, :uzanti, :byte_boyutu, :sha256, :yukleyen_kullanici_id
            )
        ');
        $stmt->execute([
            'surec_id' => (int) $payload['surec_id'],
            'personel_id' => (int) $payload['personel_id'],
            'surum_no' => (int) $payload['surum_no'],
            'aktif_mi' => !empty($payload['aktif_mi']) ? 1 : 0,
            'storage_key' => (string) $payload['storage_key'],
            'orijinal_dosya_adi' => (string) $payload['orijinal_dosya_adi'],
            'mime_type' => (string) $payload['mime_type'],
            'uzanti' => (string) $payload['uzanti'],
            'byte_boyutu' => (int) $payload['byte_boyutu'],
            'sha256' => (string) $payload['sha256'],
            'yukleyen_kullanici_id' => $payload['yukleyen_kullanici_id'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed>|null $onceki
     * @param array<string,mixed>|null $yeni
     */
    public static function insertAudit(
        PDO $pdo,
        int $surecId,
        int $personelId,
        string $islem,
        ?array $onceki,
        ?array $yeni,
        ?int $userId,
        ?string $gerekce,
        ?int $surumId,
        ?string $sha256,
        ?int $byte,
        ?string $mime
    ): void {
        if (!self::tableExists($pdo, 'personel_belge_auditleri')) {
            return;
        }
        $stmt = $pdo->prepare('
            INSERT INTO personel_belge_auditleri (
                surec_id, personel_id, belge_surum_id, islem_turu,
                onceki_metadata_json, yeni_metadata_json, yapan_kullanici_id, gerekce,
                dosya_sha256, dosya_byte, dosya_mime
            ) VALUES (
                :surec_id, :personel_id, :belge_surum_id, :islem_turu,
                :onceki, :yeni, :user_id, :gerekce,
                :sha256, :byte, :mime
            )
        ');
        $stmt->execute([
            'surec_id' => $surecId,
            'personel_id' => $personelId,
            'belge_surum_id' => $surumId,
            'islem_turu' => $islem,
            'onceki' => $onceki !== null ? json_encode(self::stripSensitive($onceki), JSON_UNESCAPED_UNICODE) : null,
            'yeni' => $yeni !== null ? json_encode(self::stripSensitive($yeni), JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $userId,
            'gerekce' => $gerekce,
            'sha256' => $sha256,
            'byte' => $byte,
            'mime' => $mime,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function listAudits(PDO $pdo, int $surecId): array
    {
        if (!self::tableExists($pdo, 'personel_belge_auditleri')) {
            return [];
        }
        $stmt = $pdo->prepare('
            SELECT id, surec_id, personel_id, belge_surum_id, islem_turu,
                   onceki_metadata_json, yeni_metadata_json, yapan_kullanici_id, gerekce,
                   dosya_sha256, dosya_byte, dosya_mime, created_at
            FROM personel_belge_auditleri
            WHERE surec_id = :surec_id
            ORDER BY id DESC
        ');
        $stmt->execute(['surec_id' => $surecId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function countActiveDuplicate(PDO $pdo, int $personelId, string $kayitTipi, ?string $belgeNo, ?string $ad, ?int $excludeId = null): int
    {
        // Duplicate policy: same personel + tip + belge_no (if set) among AKTIF rows.
        if ($belgeNo === null || trim($belgeNo) === '') {
            return 0;
        }
        $stmt = $pdo->prepare("
            SELECT id, aciklama
            FROM surecler
            WHERE personel_id = :personel_id
              AND surec_turu = 'BELGE'
              AND state = 'AKTIF'
              AND (alt_tur IS NULL OR alt_tur <> 'BELGE_DURUMU')
        ");
        $stmt->execute(['personel_id' => $personelId]);
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if ($excludeId !== null && $id === $excludeId) {
                continue;
            }
            $meta = json_decode((string) ($row['aciklama'] ?? ''), true);
            if (!is_array($meta) || empty($meta['_personel_belge_kaydi'])) {
                continue;
            }
            $tip = strtoupper((string) ($meta['kayit_tipi'] ?? $row['alt_tur'] ?? ''));
            $no = isset($meta['belge_no']) ? trim((string) $meta['belge_no']) : '';
            if ($tip === strtoupper($kayitTipi) && $no !== '' && strcasecmp($no, trim($belgeNo)) === 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function stripSensitive(array $payload): array
    {
        unset($payload['dosya_icerik_base64'], $payload['storage_key'], $payload['absolute_path'], $payload['bytes']);
        if (isset($payload['belge_no']) && is_string($payload['belge_no'])) {
            $payload['belge_no_masked'] = PersonelBelgeContracts::maskBelgeNo($payload['belge_no']);
            unset($payload['belge_no']);
        }

        return $payload;
    }
}
