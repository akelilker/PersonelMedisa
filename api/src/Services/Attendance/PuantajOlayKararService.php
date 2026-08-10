<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use Medisa\Api\Auth\RolePermissions;
use PDO;
use RuntimeException;
use Throwable;

final class PuantajOlayKararService
{
    private const TABLE = 'puantaj_olay_kararlari';
    private const AUDIT_TABLE = 'puantaj_olay_karar_auditleri';

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
     * Decision must bind to sealed/canonical event fact. Returns mismatch metadata or null.
     *
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $sealedRow
     * @return array<string, mixed>|null
     */
    public static function sourceBindingMismatch(array $decision, array $sealedRow)
    {
        $olayTuru = strtoupper(trim((string) ($decision['olay_turu'] ?? '')));
        $canonicalRaw = self::resolveCanonicalRawDakika($sealedRow, $olayTuru);
        $decisionRaw = isset($decision['raw_dakika']) ? (int) $decision['raw_dakika'] : null;
        $personelId = isset($decision['personel_id'])
            ? (int) $decision['personel_id']
            : (isset($sealedRow['personel_id']) ? (int) $sealedRow['personel_id'] : 0);
        $tarih = isset($decision['tarih'])
            ? (string) $decision['tarih']
            : (string) ($sealedRow['tarih'] ?? '');

        $expectedHash = self::computeSourceHash([
            'personel_id' => $personelId,
            'tarih' => $tarih,
            'olay_turu' => $olayTuru,
            'raw_dakika' => $canonicalRaw !== null ? $canonicalRaw : 0,
        ]);
        $decisionHash = isset($decision['source_hash']) ? (string) $decision['source_hash'] : '';

        $rawMatches = $canonicalRaw !== null && $decisionRaw !== null && $decisionRaw === $canonicalRaw;
        $hashMatches = $decisionHash !== '' && hash_equals($expectedHash, $decisionHash);
        if ($rawMatches && $hashMatches) {
            return null;
        }

        return [
            'personel_id' => $personelId,
            'tarih' => $tarih,
            'olay_turu' => $olayTuru,
            'decision_raw' => $decisionRaw,
            'canonical_raw' => $canonicalRaw,
            'decision_source_hash' => $decisionHash !== '' ? $decisionHash : null,
            'expected_source_hash' => $expectedHash,
        ];
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
        $karar = strtoupper(trim((string) ($payload['karar'] ?? '')));
        $gerekce = isset($payload['gerekce']) ? trim((string) $payload['gerekce']) : '';

        if ($personelId < 1 || $tarih === '' || !in_array($olayTuru, AttendanceDisciplineCatalog::olayTurleri(), true)) {
            throw new RuntimeException('Gecersiz olay karar payload.');
        }
        if (!in_array($karar, AttendanceDisciplineCatalog::kararTurleri(), true)) {
            throw new RuntimeException('Gecersiz karar degeri.');
        }
        if ($karar === AttendanceDisciplineCatalog::KARAR_BEKLIYOR) {
            throw new RuntimeException('VALIDATION_ERROR: BEKLIYOR manager decision olarak yazilamaz.');
        }
        if ($gerekce === '') {
            throw new RuntimeException('VALIDATION_ERROR: karar gerekcesi zorunludur.');
        }

        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            if (!self::tableExists($pdo) || !self::auditTableExists($pdo)) {
                throw new RuntimeException('SCHEMA_NOT_READY: puantaj olay karar/audit semasi hazir degil.');
            }

            // Lock canonical puantaj before resolving raw — serialize concurrent puantaj edits.
            $puantaj = self::loadCanonicalPuantaj($pdo, $personelId, $tarih, true);
            if ($puantaj === null) {
                throw new RuntimeException('VALIDATION_ERROR: gunluk_puantaj satiri bulunamadi.');
            }

            $canonicalRaw = self::resolveCanonicalRawDakika($puantaj, $olayTuru);
            if ($canonicalRaw === null || $canonicalRaw < 1) {
                throw new RuntimeException('VALIDATION_ERROR: ilgili olay icin canonical raw dakika yok.');
            }

            if (array_key_exists('raw_dakika', $payload) && $payload['raw_dakika'] !== null && $payload['raw_dakika'] !== '') {
                $clientRaw = (int) $payload['raw_dakika'];
                if ($clientRaw !== $canonicalRaw) {
                    throw new RuntimeException('VALIDATION_ERROR: raw_dakika canonical degerle eslesmiyor.');
                }
            }

            self::assertKararAllowedForEvent($olayTuru, $karar, $canonicalRaw);

            $canonicalNotice = self::nullableBool($puantaj['durumu_bildirdi_mi'] ?? null);
            if (array_key_exists('durumu_bildirdi_mi', $payload) && $payload['durumu_bildirdi_mi'] !== '') {
                $clientNotice = self::nullableBool($payload['durumu_bildirdi_mi']);
                if ($clientNotice !== $canonicalNotice) {
                    throw new RuntimeException('VALIDATION_ERROR: durumu_bildirdi_mi canonical degerle eslesmiyor.');
                }
            }

            $gunlukPuantajId = (int) $puantaj['id'];
            $gunlukBildirimId = null;
            if (isset($payload['gunluk_bildirim_id']) && $payload['gunluk_bildirim_id'] !== null && $payload['gunluk_bildirim_id'] !== '') {
                $candidateBildirimId = (int) $payload['gunluk_bildirim_id'];
                if ($candidateBildirimId > 0) {
                    self::assertBildirimOwnership($pdo, $candidateBildirimId, $personelId, $tarih);
                    $gunlukBildirimId = $candidateBildirimId;
                }
            }

            $sourceHash = self::computeSourceHash([
                'personel_id' => $personelId,
                'tarih' => $tarih,
                'olay_turu' => $olayTuru,
                'raw_dakika' => $canonicalRaw,
            ]);

            $actorId = isset($user['id']) ? (int) $user['id'] : null;
            $existing = self::getByPersonelTarihOlay($pdo, $personelId, $tarih, $olayTuru, true);

            // Exact idempotent retry only: same karar/raw/gerekce/actor/hash → no duplicate audit.
            if (
                $existing
                && (string) $existing['karar'] === $karar
                && (string) $existing['source_hash'] === $sourceHash
                && (int) $existing['raw_dakika'] === $canonicalRaw
                && (string) ($existing['gerekce'] ?? '') === $gerekce
                && (int) ($existing['karar_veren_user_id'] ?? 0) === (int) ($actorId ?? 0)
            ) {
                if ($ownsTx) {
                    $pdo->commit();
                }

                return $existing;
            }

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
                    'raw_dakika' => $canonicalRaw,
                    'durumu_bildirdi_mi' => $canonicalNotice,
                    'karar' => $karar,
                    'karar_veren_user_id' => $actorId,
                    'gerekce' => $gerekce,
                    'gunluk_puantaj_id' => $gunlukPuantajId,
                    'gunluk_bildirim_id' => $gunlukBildirimId,
                    'source_hash' => $sourceHash,
                    'id' => (int) $existing['id'],
                ]);

                $updated = self::getById($pdo, (int) $existing['id']) ?? $existing;
                self::writeAudit($pdo, [
                    'puantaj_olay_karar_id' => (int) $updated['id'],
                    'personel_id' => $personelId,
                    'tarih' => $tarih,
                    'olay_turu' => $olayTuru,
                    'raw_dakika' => $canonicalRaw,
                    'onceki_karar' => (string) $existing['karar'],
                    'yeni_karar' => $karar,
                    'actor_user_id' => $actorId,
                    'gerekce' => $gerekce,
                    'source_hash' => $sourceHash,
                ]);

                if ($ownsTx) {
                    $pdo->commit();
                }

                return $updated;
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
                'raw_dakika' => $canonicalRaw,
                'durumu_bildirdi_mi' => $canonicalNotice,
                'karar' => $karar,
                'karar_veren_user_id' => $actorId,
                'gerekce' => $gerekce,
                'source_hash' => $sourceHash,
            ]);

            $created = self::getById($pdo, (int) $pdo->lastInsertId());
            if ($created === null) {
                throw new RuntimeException('Olay karari kaydedilemedi.');
            }
            self::writeAudit($pdo, [
                'puantaj_olay_karar_id' => (int) $created['id'],
                'personel_id' => $personelId,
                'tarih' => $tarih,
                'olay_turu' => $olayTuru,
                'raw_dakika' => $canonicalRaw,
                'onceki_karar' => null,
                'yeni_karar' => $karar,
                'actor_user_id' => $actorId,
                'gerekce' => $gerekce,
                'source_hash' => $sourceHash,
            ]);

            if ($ownsTx) {
                $pdo->commit();
            }

            return $created;
        } catch (Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
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
    public static function getByPersonelTarihOlay(PDO $pdo, $personelId, $tarih, $olayTuru, $forUpdate = false)
    {
        $sql = 'SELECT * FROM ' . self::TABLE . '
             WHERE personel_id = :personel_id AND tarih = :tarih AND olay_turu = :olay_turu
             LIMIT 1';
        if ($forUpdate) {
            $sql .= self::forUpdateClause($pdo);
        }
        $stmt = $pdo->prepare($sql);
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
        return self::driverTableExists($pdo, self::TABLE);
    }

    public static function auditTableExists(PDO $pdo)
    {
        return self::driverTableExists($pdo, self::AUDIT_TABLE);
    }

    private static function driverTableExists(PDO $pdo, $table)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t");
            $stmt->execute(['t' => (string) $table]);

            return ((int) $stmt->fetchColumn()) > 0;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => (string) $table]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    private static function forUpdateClause(PDO $pdo)
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
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

    private static function assertKararAllowedForEvent($olayTuru, $karar, $rawDakika)
    {
        if ($olayTuru === AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS) {
            if ($karar === AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA) {
                throw new RuntimeException('VALIDATION_ERROR: ERKEN_CIKIS icin TOLERANS_UYGULA gecersiz.');
            }

            return;
        }

        if ($olayTuru === AttendanceDisciplineCatalog::OLAY_GEC_KALMA) {
            if (
                $karar === AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA
                && !AttendanceDisciplineCatalog::isLateToleranceAllowed($rawDakika)
            ) {
                throw new RuntimeException(
                    'VALIDATION_ERROR: TOLERANS_UYGULA yalniz raw <= '
                    . AttendanceDisciplineCatalog::LATE_TOLERANCE_MAX_MINUTE
                    . ' dakika icin gecerlidir.'
                );
            }
        }
    }

    private static function assertBildirimOwnership(PDO $pdo, $bildirimId, $personelId, $tarih)
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM gunluk_bildirimler
             WHERE id = :id AND personel_id = :personel_id AND tarih = :tarih
             LIMIT 1'
        );
        $stmt->execute([
            'id' => (int) $bildirimId,
            'personel_id' => (int) $personelId,
            'tarih' => (string) $tarih,
        ]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('VALIDATION_ERROR: gunluk_bildirim_id personel/tarih ile eslesmiyor.');
        }
    }

    /** @return array<string, mixed>|null */
    private static function loadCanonicalPuantaj(PDO $pdo, $personelId, $tarih, $forUpdate = false)
    {
        $sql = 'SELECT id, personel_id, tarih, gec_kalma_dakika, erken_cikis_dakika, durumu_bildirdi_mi
             FROM gunluk_puantaj
             WHERE personel_id = :personel_id AND tarih = :tarih
             LIMIT 1';
        if ($forUpdate) {
            $sql .= self::forUpdateClause($pdo);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['personel_id' => (int) $personelId, 'tarih' => (string) $tarih]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $puantaj
     * @return int|null
     */
    private static function resolveCanonicalRawDakika(array $puantaj, $olayTuru)
    {
        if ($olayTuru === AttendanceDisciplineCatalog::OLAY_GEC_KALMA) {
            return isset($puantaj['gec_kalma_dakika']) ? (int) $puantaj['gec_kalma_dakika'] : null;
        }
        if ($olayTuru === AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS) {
            return isset($puantaj['erken_cikis_dakika']) ? (int) $puantaj['erken_cikis_dakika'] : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $audit
     */
    private static function writeAudit(PDO $pdo, array $audit)
    {
        if (!self::auditTableExists($pdo)) {
            throw new RuntimeException('SCHEMA_NOT_READY: puantaj_olay_karar_auditleri tablosu hazir degil.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::AUDIT_TABLE . ' (
                puantaj_olay_karar_id, personel_id, tarih, olay_turu, raw_dakika,
                onceki_karar, yeni_karar, actor_user_id, gerekce, source_hash
             ) VALUES (
                :puantaj_olay_karar_id, :personel_id, :tarih, :olay_turu, :raw_dakika,
                :onceki_karar, :yeni_karar, :actor_user_id, :gerekce, :source_hash
             )'
        );
        $stmt->execute([
            'puantaj_olay_karar_id' => (int) $audit['puantaj_olay_karar_id'],
            'personel_id' => (int) $audit['personel_id'],
            'tarih' => (string) $audit['tarih'],
            'olay_turu' => (string) $audit['olay_turu'],
            'raw_dakika' => (int) $audit['raw_dakika'],
            'onceki_karar' => $audit['onceki_karar'],
            'yeni_karar' => (string) $audit['yeni_karar'],
            'actor_user_id' => $audit['actor_user_id'],
            'gerekce' => $audit['gerekce'],
            'source_hash' => (string) $audit['source_hash'],
        ]);
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
