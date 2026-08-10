<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use Medisa\Api\Auth\RolePermissions;
use PDO;
use PDOException;
use RuntimeException;

final class PuantajOlayKararService
{
    private const TABLE = 'puantaj_olay_kararlari';

    /**
     * @param array<string, mixed> $payload
     */
    public static function computeSourceHash(array $payload)
    {
        $parts = [
            (int) ($payload['personel_id'] ?? 0),
            (string) ($payload['tarih'] ?? ''),
            strtoupper((string) ($payload['olay_turu'] ?? '')),
            (int) ($payload['raw_dakika'] ?? 0),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function upsertDecision(PDO $pdo, array $user, array $payload)
    {
        self::assertDecideActor($user);

        $personelId = (int) ($payload['personel_id'] ?? 0);
        $tarih = trim((string) ($payload['tarih'] ?? ''));
        $olayTuru = strtoupper(trim((string) ($payload['olay_turu'] ?? '')));
        $rawDakika = (int) ($payload['raw_dakika'] ?? 0);
        $karar = strtoupper(trim((string) ($payload['karar'] ?? '')));
        $gerekce = isset($payload['gerekce']) ? trim((string) $payload['gerekce']) : null;

        if ($personelId < 1 || $tarih === '' || !in_array($olayTuru, AttendanceDisciplineCatalog::olayTurleri(), true)) {
            throw new RuntimeException('Gecersiz olay karar payload.');
        }
        if (!in_array($karar, AttendanceDisciplineCatalog::kararTurleri(), true)) {
            throw new RuntimeException('Gecersiz karar degeri.');
        }
        if ($rawDakika < 0) {
            throw new RuntimeException('raw_dakika negatif olamaz.');
        }

        $durumuBildirdi = array_key_exists('durumu_bildirdi_mi', $payload)
            ? self::nullableBool($payload['durumu_bildirdi_mi'])
            : self::loadDurumuBildirdiFromPuantaj($pdo, $personelId, $tarih);

        $gunlukPuantajId = self::resolveGunlukPuantajId($pdo, $personelId, $tarih, $payload);
        $gunlukBildirimId = isset($payload['gunluk_bildirim_id']) ? (int) $payload['gunluk_bildirim_id'] : null;
        if ($gunlukBildirimId !== null && $gunlukBildirimId < 1) {
            $gunlukBildirimId = null;
        }

        $sourceHash = self::computeSourceHash([
            'personel_id' => $personelId,
            'tarih' => $tarih,
            'olay_turu' => $olayTuru,
            'raw_dakika' => $rawDakika,
        ]);

        $actorId = isset($user['id']) ? (int) $user['id'] : null;
        $existing = self::getByPersonelTarihOlay($pdo, $personelId, $tarih, $olayTuru);

        try {
            if ($existing) {
                $stmt = $pdo->prepare(
                    'UPDATE ' . self::TABLE . '
                     SET raw_dakika = :raw_dakika,
                         durumu_bildirdi_mi = :durumu_bildirdi_mi,
                         karar = :karar,
                         karar_veren_user_id = :karar_veren_user_id,
                         karar_at = CURRENT_TIMESTAMP,
                         gerekce = :gerekce,
                         gunluk_puantaj_id = :gunluk_puantaj_id,
                         gunluk_bildirim_id = :gunluk_bildirim_id,
                         source_hash = :source_hash
                     WHERE id = :id'
                );
                $stmt->execute([
                    'raw_dakika' => $rawDakika,
                    'durumu_bildirdi_mi' => $durumuBildirdi,
                    'karar' => $karar,
                    'karar_veren_user_id' => $actorId,
                    'gerekce' => $gerekce,
                    'gunluk_puantaj_id' => $gunlukPuantajId,
                    'gunluk_bildirim_id' => $gunlukBildirimId,
                    'source_hash' => $sourceHash,
                    'id' => (int) $existing['id'],
                ]);

                return self::getById($pdo, (int) $existing['id']) ?? $existing;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (
                    personel_id, tarih, gunluk_puantaj_id, gunluk_bildirim_id,
                    olay_turu, raw_dakika, durumu_bildirdi_mi, karar,
                    karar_veren_user_id, karar_at, gerekce, source_hash
                 ) VALUES (
                    :personel_id, :tarih, :gunluk_puantaj_id, :gunluk_bildirim_id,
                    :olay_turu, :raw_dakika, :durumu_bildirdi_mi, :karar,
                    :karar_veren_user_id, CURRENT_TIMESTAMP, :gerekce, :source_hash
                 )'
            );
            $stmt->execute([
                'personel_id' => $personelId,
                'tarih' => $tarih,
                'gunluk_puantaj_id' => $gunlukPuantajId,
                'gunluk_bildirim_id' => $gunlukBildirimId,
                'olay_turu' => $olayTuru,
                'raw_dakika' => $rawDakika,
                'durumu_bildirdi_mi' => $durumuBildirdi,
                'karar' => $karar,
                'karar_veren_user_id' => $actorId,
                'gerekce' => $gerekce,
                'source_hash' => $sourceHash,
            ]);

            return self::getById($pdo, (int) $pdo->lastInsertId()) ?? [];
        } catch (PDOException $e) {
            throw new RuntimeException('Olay karari kaydedilemedi.', 0, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForPeriod(PDO $pdo, $personelId, $from, $to)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE personel_id = :personel_id AND tarih >= :from_date AND tarih <= :to_date
             ORDER BY tarih ASC, olay_turu ASC'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'from_date' => (string) $from,
            'to_date' => (string) $to,
        ]);

        return array_map([self::class, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function indexKararlarForPeriod(PDO $pdo, array $personelIds, $from, $to)
    {
        if (count($personelIds) === 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($personelIds), '?'));
        $sql = 'SELECT * FROM ' . self::TABLE . '
                WHERE personel_id IN (' . $placeholders . ')
                  AND tarih >= ? AND tarih <= ?
                ORDER BY tarih ASC';
        $stmt = $pdo->prepare($sql);
        $params = array_merge(array_map('intval', $personelIds), [(string) $from, (string) $to]);
        $stmt->execute($params);

        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = AttendancePayrollEffectResolver::kararKey(
                (int) $row['personel_id'],
                (string) $row['tarih'],
                (string) $row['olay_turu']
            );
            $index[$key] = self::mapRow($row);
        }

        return $index;
    }

    /** @return array<string, mixed>|null */
    public static function getByPersonelTarihOlay(PDO $pdo, $personelId, $tarih, $olayTuru)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE personel_id = :personel_id AND tarih = :tarih AND olay_turu = :olay_turu
             LIMIT 1'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'tarih' => (string) $tarih,
            'olay_turu' => strtoupper((string) $olayTuru),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapRow($row) : null;
    }

    /** @return array<string, mixed>|null */
    public static function getById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapRow($row) : null;
    }

    public static function tableExists(PDO $pdo)
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
        );

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /** @param array<string, mixed> $user */
    private static function assertDecideActor(array $user)
    {
        $role = strtoupper(trim((string) ($user['rol'] ?? '')));
        if (!in_array($role, AttendanceDisciplineCatalog::olayKararDecideRoles(), true)) {
            throw new RuntimeException('Olay karar yetkisi yok.');
        }
        if (!RolePermissions::has($user, 'puantaj.olay_karar.decide')) {
            throw new RuntimeException('Olay karar yetkisi yok.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function resolveGunlukPuantajId(PDO $pdo, $personelId, $tarih, array $payload)
    {
        if (isset($payload['gunluk_puantaj_id'])) {
            $id = (int) $payload['gunluk_puantaj_id'];
            if ($id > 0) {
                return $id;
            }
        }
        $stmt = $pdo->prepare(
            'SELECT id FROM gunluk_puantaj WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1'
        );
        $stmt->execute(['personel_id' => (int) $personelId, 'tarih' => (string) $tarih]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private static function loadDurumuBildirdiFromPuantaj(PDO $pdo, $personelId, $tarih)
    {
        $stmt = $pdo->prepare(
            'SELECT durumu_bildirdi_mi FROM gunluk_puantaj
             WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1'
        );
        $stmt->execute(['personel_id' => (int) $personelId, 'tarih' => (string) $tarih]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            return null;
        }

        return self::nullableBool($val);
    }

    /** @param mixed $value */
    private static function nullableBool($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return (int) $value === 1 ? 1 : 0;
    }

    /** @param array<string, mixed> $row */
    private static function mapRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'],
            'gunluk_puantaj_id' => $row['gunluk_puantaj_id'] !== null ? (int) $row['gunluk_puantaj_id'] : null,
            'gunluk_bildirim_id' => $row['gunluk_bildirim_id'] !== null ? (int) $row['gunluk_bildirim_id'] : null,
            'olay_turu' => (string) $row['olay_turu'],
            'raw_dakika' => (int) $row['raw_dakika'],
            'durumu_bildirdi_mi' => $row['durumu_bildirdi_mi'] !== null ? (int) $row['durumu_bildirdi_mi'] : null,
            'karar' => (string) $row['karar'],
            'karar_veren_user_id' => $row['karar_veren_user_id'] !== null ? (int) $row['karar_veren_user_id'] : null,
            'karar_at' => $row['karar_at'] !== null ? (string) $row['karar_at'] : null,
            'gerekce' => $row['gerekce'] !== null ? (string) $row['gerekce'] : null,
            'source_hash' => (string) $row['source_hash'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
