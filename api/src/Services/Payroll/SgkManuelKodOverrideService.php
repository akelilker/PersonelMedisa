<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use Medisa\Api\Services\MaasHesaplamaSnapshotService;
use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__ . '/SgkKatalogContracts.php';

/**
 * S98-R1: Authorized manual SGK eksik gun kod override owner.
 * Applied after auto mapping resolve; supersede-per-target semantics.
 */
final class SgkManuelKodOverrideService
{
    /**
     * @param array{id: int, rol?: string} $user
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function createOverride(PDO $pdo, array $user, array $payload): array
    {
        $targetType = strtoupper(trim((string) ($payload['target_type'] ?? '')));
        $targetId = (int) ($payload['target_id'] ?? 0);
        $personelId = (int) ($payload['personel_id'] ?? 0);
        $tarih = trim((string) ($payload['tarih'] ?? ''));
        $yeniKod = strtoupper(trim((string) ($payload['yeni_eksik_gun_kodu'] ?? '')));
        $gerekce = trim((string) ($payload['gerekce'] ?? ''));
        $belgeId = (int) ($payload['belge_id'] ?? 0);
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

        if (!in_array($targetType, ['SUREC', 'GUNLUK_PUANTAJ'], true)) {
            return self::error(422, 'GECERSIZ_TARGET_TYPE', 'target_type SUREC veya GUNLUK_PUANTAJ olmalidir.');
        }
        if ($targetId < 1 || $personelId < 1 || $tarih === '' || $yeniKod === '' || $gerekce === '') {
            return self::error(422, 'VALIDATION_ERROR', 'target_id, personel_id, tarih, yeni_eksik_gun_kodu ve gerekce zorunludur.');
        }
        if ($belgeId < 1) {
            return self::error(422, 'BELGE_ID_ZORUNLU', 'belge_id zorunludur.');
        }
        if ($idempotencyKey === '') {
            return self::error(422, 'IDEMPOTENCY_KEY_ZORUNLU', 'idempotency_key zorunludur.');
        }

        if (!self::isApprovedCatalogCode($pdo, $yeniKod)) {
            return self::error(422, 'GECERSIZ_EKSIK_GUN_KODU', 'yeni_eksik_gun_kodu onayli katalogda yok.');
        }
        if (!self::isVerifiedBelgeForPersonel($pdo, $belgeId, $personelId)) {
            return self::error(422, 'BELGE_DOGRULANMADI', 'belge_id personel icin dogrulanmis degil.');
        }
        if (!self::validateTargetScope($pdo, $targetType, $targetId, $personelId, $tarih)) {
            return self::error(422, 'TARGET_SCOPE_GECERSIZ', 'target personel/tarih ile uyusmuyor.');
        }

        $canonicalPayload = [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'personel_id' => $personelId,
            'tarih' => $tarih,
            'onceki_eksik_gun_kodu' => $payload['onceki_eksik_gun_kodu'] ?? null,
            'yeni_eksik_gun_kodu' => $yeniKod,
            'gerekce' => $gerekce,
            'belge_id' => $belgeId,
        ];
        $payloadHash = SgkKatalogContracts::sha256Canonical($canonicalPayload);

        $existing = self::fetchByIdempotencyKey($pdo, $idempotencyKey);
        if ($existing !== null) {
            if ((string) ($existing['payload_hash'] ?? '') === $payloadHash) {
                return self::ok([
                    'id' => (int) $existing['id'],
                    'state' => (string) $existing['state'],
                    'idempotent_mi' => true,
                ]);
            }

            return self::error(409, 'IDEMPOTENCY_KEY_CELISKISI', 'Ayni idempotency_key farkli payload ile kullanildi.');
        }

        $pdo->beginTransaction();
        try {
            $previous = self::fetchActiveForTarget($pdo, $targetType, $targetId);
            $supersedesId = null;
            if ($previous !== null) {
                $supersedesId = (int) $previous['id'];
                $upd = $pdo->prepare(
                    "UPDATE sgk_manuel_kod_override_auditleri
                     SET state = 'SUPERSEDED', aktif_hedef_anahtari = NULL
                     WHERE id = :id AND state = 'AKTIF'"
                );
                $upd->execute(['id' => $supersedesId]);
            }

            $aktifKey = $targetType . ':' . $targetId;
            $insert = $pdo->prepare(
                "INSERT INTO sgk_manuel_kod_override_auditleri (
                    target_type, target_id, personel_id, tarih,
                    onceki_eksik_gun_kodu, yeni_eksik_gun_kodu, gerekce, belge_id,
                    actor_id, idempotency_key, payload_hash, state, supersedes_id, aktif_hedef_anahtari
                 ) VALUES (
                    :target_type, :target_id, :personel_id, :tarih,
                    :onceki, :yeni, :gerekce, :belge_id,
                    :actor_id, :idempotency_key, :payload_hash, 'AKTIF', :supersedes_id, :aktif_key
                 )"
            );
            $insert->execute([
                'target_type' => $targetType,
                'target_id' => $targetId,
                'personel_id' => $personelId,
                'tarih' => $tarih,
                'onceki' => isset($payload['onceki_eksik_gun_kodu']) && $payload['onceki_eksik_gun_kodu'] !== ''
                    ? strtoupper(trim((string) $payload['onceki_eksik_gun_kodu'])) : null,
                'yeni' => $yeniKod,
                'gerekce' => $gerekce,
                'belge_id' => $belgeId,
                'actor_id' => (int) ($user['id'] ?? 0),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'supersedes_id' => $supersedesId,
                'aktif_key' => $aktifKey,
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();

            return self::ok([
                'id' => $newId,
                'state' => 'AKTIF',
                'supersedes_id' => $supersedesId,
                'idempotent_mi' => false,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (strpos($e->getMessage(), 'Duplicate') !== false || (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return self::error(409, 'AKTIF_OVERRIDE_CELISKISI', 'Hedef icin aktif override zaten var.');
            }
            throw new RuntimeException('Manuel override kaydedilemedi.', 0, $e);
        }
    }

    /**
     * @param list<int> $personelIds
     * @return array<string, array<string,mixed>> keyed by TARGET_TYPE:TARGET_ID
     */
    public static function loadCurrentOverridesForPersonnel(PDO $pdo, array $personelIds, string $periodStart, string $periodEnd): array
    {
        if (count($personelIds) === 0) {
            return [];
        }
        try {
            $placeholders = implode(', ', array_fill(0, count($personelIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT * FROM sgk_manuel_kod_override_auditleri
                 WHERE personel_id IN ($placeholders)
                   AND state = 'AKTIF'
                   AND tarih BETWEEN ? AND ?
                 ORDER BY id ASC"
            );
            $stmt->execute(array_merge(array_map('intval', $personelIds), [$periodStart, $periodEnd]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['target_type'] ?? '') . ':' . (int) ($row['target_id'] ?? 0);
            $out[$key] = $row;
        }

        return $out;
    }

    /** @param array<string,mixed> $process @param array<string, array<string,mixed>> $overrides */
    public static function resolveForProcess(array &$process, array $overrides): void
    {
        $surecId = (int) ($process['surec_id'] ?? 0);
        if ($surecId < 1) {
            return;
        }
        $key = 'SUREC:' . $surecId;
        if (!isset($overrides[$key])) {
            return;
        }
        self::applyOverrideRow($process, $overrides[$key]);
    }

    /** @param array<string,mixed> $process @param array<string, array<string,mixed>> $overrides */
    public static function resolveForAttendance(array &$process, array $overrides): void
    {
        $satirId = (int) ($process['muhur_satir_id'] ?? 0);
        if ($satirId < 1) {
            return;
        }
        $key = 'GUNLUK_PUANTAJ:' . $satirId;
        if (!isset($overrides[$key])) {
            return;
        }
        self::applyOverrideRow($process, $overrides[$key]);
    }

    /** @param array<string,mixed> $process @param array<string,mixed> $override */
    private static function applyOverrideRow(array &$process, array $override): void
    {
        $process['eksik_gun_kodu'] = strtoupper(trim((string) ($override['yeni_eksik_gun_kodu'] ?? '')));
        $process['prim_gunu_etkisi'] = 'DUSUR';
        $process['cozulmus_prim_gunu_etkisi'] = 'DUSUR';
        $process['manuel_override_audit_ok_mi'] = true;
        $process['manuel_override_id'] = (int) ($override['id'] ?? 0);
        $process['manuel_override_belge_id'] = (int) ($override['belge_id'] ?? 0);
        $process['manuel_override_gerekce'] = (string) ($override['gerekce'] ?? '');
        $process['manuel_override_payload_hash'] = (string) ($override['payload_hash'] ?? '');
    }

    private static function isApprovedCatalogCode(PDO $pdo, string $kod): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sgk_eksik_gun_kodlari k
                 INNER JOIN sgk_eksik_gun_katalog_surumleri s ON s.id = k.katalog_surum_id
                 WHERE s.state = 'ONAYLANDI' AND k.eksik_gun_kodu = :kod AND k.aktif_mi = 1"
            );
            $stmt->execute(['kod' => $kod]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    private static function isVerifiedBelgeForPersonel(PDO $pdo, int $belgeId, int $personelId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM sgk_eksik_gun_belgeleri
                 WHERE id = :id AND personel_id = :pid AND dogrulama_durumu = 'DOGRULANDI'
                 LIMIT 1"
            );
            $stmt->execute(['id' => $belgeId, 'pid' => $personelId]);

            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    private static function validateTargetScope(PDO $pdo, string $targetType, int $targetId, int $personelId, string $tarih): bool
    {
        try {
            if ($targetType === 'SUREC') {
                $stmt = $pdo->prepare(
                    'SELECT personel_id, baslangic_tarihi, bitis_tarihi FROM surecler WHERE id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $targetId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || (int) $row['personel_id'] !== $personelId) {
                    return false;
                }
                $bitis = $row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : (string) $row['baslangic_tarihi'];

                return $tarih >= (string) $row['baslangic_tarihi'] && $tarih <= $bitis;
            }
            $stmt = $pdo->prepare(
                'SELECT personel_id, tarih FROM puantaj_aylik_muhur_satirlari WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row)
                && (int) $row['personel_id'] === $personelId
                && (string) $row['tarih'] === $tarih;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private static function fetchByIdempotencyKey(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM sgk_manuel_kod_override_auditleri WHERE idempotency_key = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private static function fetchActiveForTarget(PDO $pdo, string $targetType, int $targetId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM sgk_manuel_kod_override_auditleri
             WHERE target_type = :tt AND target_id = :tid AND state = 'AKTIF'
             LIMIT 1"
        );
        $stmt->execute(['tt' => $targetType, 'tid' => $targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    private static function ok(array $data): array
    {
        return ['http_status' => 201] + $data;
    }

    private static function error(int $status, string $code, string $message): array
    {
        return ['http_status' => $status, 'error_code' => $code, 'message' => $message];
    }
}
