<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\PuantajDonemKilidiService;
use PDO;
use PDOException;
use Throwable;

/**
 * Fazla calisma odeme tercihi owner (S79-C).
 *
 * Permissions (existing RolePermissions — no new keys):
 * - GET → puantaj.view
 * - PUT → puantaj.muhurle
 *
 * Identity: UNIQUE(snapshot_id) where snapshot_id = haftalik_kapanis_satirlari.id
 * Period: week months must be unsealed (puantaj_aylik_muhurleri); unknown → PERIOD_STATE_UNKNOWN
 * SZ guard: active SERBEST_ZAMAN_OLUSUM blocks leaving SERBEST_ZAMAN
 */
class FazlaCalismaOdemeTercihiController
{
    private const ODEME_TIPLERI = ['KARAR_BEKLIYOR', 'UCRET', 'SERBEST_ZAMAN'];
    private const DEFAULT_ODEME_TIPI = 'KARAR_BEKLIYOR';
    private const SERVER_OWNED_FIELDS = [
        'id',
        'kapanis_id',
        'personel_id',
        'hafta_baslangic',
        'hafta_bitis',
        'fazla_calisma_dakika',
        'secen_kullanici_id',
        'secim_zamani',
        'onceki_odeme_tipi',
        'created_at',
        'updated_at',
        'sube_id',
    ];

    public static function get(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');

        $snapshotId = self::parsePositiveInt($request->getQuery('snapshot_id'), 'snapshot_id', true);
        $pdo = Connection::get();

        $satir = self::loadSnapshotSatir($pdo, $snapshotId);
        if ($satir === null) {
            JsonResponse::error(404, 'NOT_FOUND', 'snapshot_id icin odeme tercihi veya kapanis satiri bulunamadi.');
        }

        self::assertSnapshotScope($user, $request, $satir);

        $stored = self::loadTercihBySnapshot($pdo, $snapshotId);
        if ($stored !== null) {
            JsonResponse::success(self::mapTercih($stored));
        }

        JsonResponse::success(self::syntheticTercih($satir));
    }

    public static function put(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.muhurle');

        $body = $request->getJsonBody();

        foreach (self::SERVER_OWNED_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                self::validationError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }

        $snapshotId = self::parsePositiveInt($body['snapshot_id'] ?? null, 'snapshot_id', false);
        $odemeTipi = $body['odeme_tipi'] ?? null;
        if (!is_string($odemeTipi) || !in_array($odemeTipi, self::ODEME_TIPLERI, true)) {
            self::validationError('odeme_tipi', 'odeme_tipi gecersiz.');
        }
        $gerekce = self::optionalGerekce($body['gerekce'] ?? null);

        $pdo = Connection::get();

        foreach (['fazla_calisma_odeme_tercihleri', 'fazla_calisma_odeme_tercihi_audit', 'haftalik_kapanis_satirlari'] as $table) {
            if (!self::tableExists($pdo, $table)) {
                JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Odeme tercihi semasi hazir degil.');
            }
        }

        $satirProbe = self::loadSnapshotSatir($pdo, $snapshotId);
        if ($satirProbe === null) {
            JsonResponse::error(404, 'NOT_FOUND', 'snapshot_id icin odeme tercihi veya kapanis satiri bulunamadi.');
        }
        self::assertSnapshotScope($user, $request, $satirProbe);

        $pdo->beginTransaction();

        try {
            $satir = self::loadSnapshotSatir($pdo, $snapshotId, true);
            if ($satir === null) {
                self::rollbackNotFound($pdo, 'snapshot_id icin odeme tercihi veya kapanis satiri bulunamadi.');
            }

            $subeId = (int) $satir['sube_id'];
            self::assertWeekPeriodsOpen(
                $pdo,
                $subeId,
                (string) $satir['hafta_baslangic'],
                (string) $satir['hafta_bitis']
            );

            $existing = self::loadTercihBySnapshot($pdo, $snapshotId, true);
            // Idempotency key is odeme_tipi only; gerekce-only delta is not a real preference change.
            if ($existing !== null && (string) $existing['odeme_tipi'] === $odemeTipi) {
                $pdo->commit();
                JsonResponse::success(self::mapTercih($existing));
            }

            $onceki = $existing !== null
                ? (string) $existing['odeme_tipi']
                : self::DEFAULT_ODEME_TIPI;

            if (
                $existing !== null
                && $onceki === 'SERBEST_ZAMAN'
                && ($odemeTipi === 'UCRET' || $odemeTipi === 'KARAR_BEKLIYOR')
                && self::hasActiveSerbestZamanOlusum($pdo, (int) $existing['id'])
            ) {
                self::rollbackConflict(
                    $pdo,
                    'STATE_CONFLICT',
                    'Aktif serbest zaman olusumu varken odeme tipi degistirilemez.'
                );
            }

            $userId = (int) ($user['id'] ?? 0);
            $now = date('Y-m-d H:i:s');

            if ($existing === null) {
                $ins = $pdo->prepare(
                    'INSERT INTO fazla_calisma_odeme_tercihleri
                      (snapshot_id, kapanis_id, personel_id, hafta_baslangic, hafta_bitis,
                       fazla_calisma_dakika, odeme_tipi, secim_zamani, secen_kullanici_id,
                       onceki_odeme_tipi, gerekce)
                     VALUES
                      (:snapshot_id, :kapanis_id, :personel_id, :hafta_baslangic, :hafta_bitis,
                       :fazla_calisma_dakika, :odeme_tipi, :secim_zamani, :secen_kullanici_id,
                       :onceki_odeme_tipi, :gerekce)'
                );
                $ins->execute([
                    'snapshot_id' => $snapshotId,
                    'kapanis_id' => (int) $satir['kapanis_id'],
                    'personel_id' => (int) $satir['personel_id'],
                    'hafta_baslangic' => (string) $satir['hafta_baslangic'],
                    'hafta_bitis' => (string) $satir['hafta_bitis'],
                    'fazla_calisma_dakika' => (int) $satir['fazla_calisma_dakika'],
                    'odeme_tipi' => $odemeTipi,
                    'secim_zamani' => $now,
                    'secen_kullanici_id' => $userId > 0 ? $userId : null,
                    'onceki_odeme_tipi' => $onceki,
                    'gerekce' => $gerekce,
                ]);
                $tercihId = (int) $pdo->lastInsertId();
            } else {
                $tercihId = (int) $existing['id'];
                $upd = $pdo->prepare(
                    'UPDATE fazla_calisma_odeme_tercihleri
                     SET odeme_tipi = :odeme_tipi,
                         secim_zamani = :secim_zamani,
                         secen_kullanici_id = :secen_kullanici_id,
                         onceki_odeme_tipi = :onceki_odeme_tipi,
                         gerekce = :gerekce,
                         fazla_calisma_dakika = :fazla_calisma_dakika
                     WHERE id = :id'
                );
                $upd->execute([
                    'odeme_tipi' => $odemeTipi,
                    'secim_zamani' => $now,
                    'secen_kullanici_id' => $userId > 0 ? $userId : null,
                    'onceki_odeme_tipi' => $onceki,
                    'gerekce' => $gerekce,
                    'fazla_calisma_dakika' => (int) $satir['fazla_calisma_dakika'],
                    'id' => $tercihId,
                ]);
            }

            $audit = $pdo->prepare(
                'INSERT INTO fazla_calisma_odeme_tercihi_audit
                  (tercih_id, snapshot_id, onceki_odeme_tipi, yeni_odeme_tipi,
                   secen_kullanici_id, secim_zamani, gerekce)
                 VALUES
                  (:tercih_id, :snapshot_id, :onceki_odeme_tipi, :yeni_odeme_tipi,
                   :secen_kullanici_id, :secim_zamani, :gerekce)'
            );
            $audit->execute([
                'tercih_id' => $tercihId,
                'snapshot_id' => $snapshotId,
                'onceki_odeme_tipi' => $onceki,
                'yeni_odeme_tipi' => $odemeTipi,
                'secen_kullanici_id' => $userId,
                'secim_zamani' => $now,
                'gerekce' => $gerekce,
            ]);

            $pdo->commit();
            $saved = self::loadTercihBySnapshot(Connection::get(), $snapshotId);
            JsonResponse::success(self::mapTercih($saved));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $info = $e->errorInfo ?? [];
            if (isset($info[1]) && (int) $info[1] === 1062) {
                JsonResponse::error(409, 'STATE_CONFLICT', 'Odeme tercihi cakismasi.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Snapshot → personel.sube_id → SubeScope.
     * Empty allowedSubeIds without personeller.view must not leak (BA-style).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $satir
     */
    private static function assertSnapshotScope(array $user, Request $request, array $satir): void
    {
        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0 && !RolePermissions::has($user, 'personeller.view')) {
            JsonResponse::forbidden('Sube baglami olmadan odeme tercihi erisilemez.');
        }
        SubeScope::assertPersonelAccess($user, $request, (int) $satir['sube_id']);
    }

    private static function assertWeekPeriodsOpen(PDO $pdo, int $subeId, string $haftaBaslangic, string $haftaBitis): void
    {
        $months = self::monthsCoveredByWeek($haftaBaslangic, $haftaBitis);
        if ($months === []) {
            self::rollbackConflict($pdo, 'PERIOD_STATE_UNKNOWN', 'Puantaj donem durumu belirlenemedi.');
        }

        foreach ($months as $month) {
            try {
                if (!self::tableExists($pdo, 'puantaj_donem_kilitleri') || !self::tableExists($pdo, 'puantaj_aylik_muhurleri')) {
                    self::rollbackConflict($pdo, 'PERIOD_STATE_UNKNOWN', 'Puantaj donem durumu belirlenemedi.');
                }
                $lock = PuantajDonemKilidiService::acquire($pdo, $subeId, $month['yil'], $month['ay']);
                if (PuantajDonemKilidiService::isSealed($pdo, $lock)) {
                    self::rollbackConflict(
                        $pdo,
                        'PERIOD_LOCKED',
                        'Bu donem muhurlenmis, odeme tercihi guncellenemez.'
                    );
                }
            } catch (Throwable $e) {
                if ($e instanceof PDOException || $e instanceof \RuntimeException || $e instanceof \LogicException || $e instanceof \InvalidArgumentException) {
                    self::rollbackConflict($pdo, 'PERIOD_STATE_UNKNOWN', 'Puantaj donem durumu belirlenemedi.');
                }
                throw $e;
            }
        }
    }

    /** @return list<array{yil: int, ay: int}> */
    private static function monthsCoveredByWeek(string $haftaBaslangic, string $haftaBitis): array
    {
        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $haftaBaslangic)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $haftaBitis)
        ) {
            return [];
        }

        try {
            $start = new \DateTimeImmutable($haftaBaslangic);
            $end = new \DateTimeImmutable($haftaBitis);
        } catch (Throwable $e) {
            return [];
        }

        if ($end < $start) {
            return [];
        }

        $seen = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $key = $cursor->format('Y-n');
            $seen[$key] = [
                'yil' => (int) $cursor->format('Y'),
                'ay' => (int) $cursor->format('n'),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return array_values($seen);
    }

    private static function hasActiveSerbestZamanOlusum(PDO $pdo, int $tercihId): bool
    {
        if ($tercihId < 1 || !self::tableExists($pdo, 'serbest_zaman_events')) {
            return false;
        }

        // Active = OLUSUM whose id is not targeted by a later IPTAL event.
        $sql = 'SELECT o.id
                FROM serbest_zaman_events o
                WHERE o.event_tipi = \'SERBEST_ZAMAN_OLUSUM\'
                  AND o.kaynak_odeme_tercihi_id = :tercih_id
                  AND NOT EXISTS (
                    SELECT 1 FROM serbest_zaman_events i
                    WHERE i.event_tipi = \'SERBEST_ZAMAN_IPTAL\'
                      AND i.hedef_event_id = o.id
                  )
                LIMIT 1';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['tercih_id' => $tercihId]);

            return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private static function loadSnapshotSatir(PDO $pdo, int $snapshotId, bool $forUpdate = false)
    {
        $sql = 'SELECT s.id, s.kapanis_id, s.personel_id, s.hafta_baslangic, s.hafta_bitis,
                       s.fazla_calisma_dakika, k.sube_id
                FROM haftalik_kapanis_satirlari s
                INNER JOIN haftalik_kapanislar k ON k.id = s.kapanis_id
                WHERE s.id = :id
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $snapshotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private static function loadTercihBySnapshot(PDO $pdo, int $snapshotId, bool $forUpdate = false)
    {
        $sql = 'SELECT * FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = :snapshot_id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['snapshot_id' => $snapshotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $satir @return array<string, mixed> */
    private static function syntheticTercih(array $satir): array
    {
        return [
            'id' => null,
            'snapshot_id' => (int) $satir['id'],
            'kapanis_id' => (int) $satir['kapanis_id'],
            'personel_id' => (int) $satir['personel_id'],
            'hafta_baslangic' => (string) $satir['hafta_baslangic'],
            'hafta_bitis' => (string) $satir['hafta_bitis'],
            'fazla_calisma_dakika' => (int) $satir['fazla_calisma_dakika'],
            'odeme_tipi' => self::DEFAULT_ODEME_TIPI,
            'secim_zamani' => null,
            'secen_kullanici_id' => null,
            'onceki_odeme_tipi' => null,
            'gerekce' => null,
        ];
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed> */
    private static function mapTercih($row): array
    {
        if (!is_array($row)) {
            JsonResponse::error(500, 'INTERNAL_ERROR', 'Odeme tercihi okunamadi.');
        }

        $out = [
            'id' => (int) $row['id'],
            'snapshot_id' => (int) $row['snapshot_id'],
            'kapanis_id' => (int) $row['kapanis_id'],
            'personel_id' => (int) $row['personel_id'],
            'hafta_baslangic' => (string) $row['hafta_baslangic'],
            'hafta_bitis' => (string) $row['hafta_bitis'],
            'fazla_calisma_dakika' => (int) $row['fazla_calisma_dakika'],
            'odeme_tipi' => (string) $row['odeme_tipi'],
        ];
        if (!empty($row['secim_zamani'])) {
            $out['secim_zamani'] = self::toIso((string) $row['secim_zamani']);
        }
        if (isset($row['secen_kullanici_id']) && $row['secen_kullanici_id'] !== null) {
            $out['secen_kullanici_id'] = (int) $row['secen_kullanici_id'];
        }
        if (!empty($row['onceki_odeme_tipi'])) {
            $out['onceki_odeme_tipi'] = (string) $row['onceki_odeme_tipi'];
        }
        if (isset($row['gerekce']) && $row['gerekce'] !== null && $row['gerekce'] !== '') {
            $out['gerekce'] = (string) $row['gerekce'];
        }

        return $out;
    }

    private static function toIso(string $datetime): string
    {
        try {
            return (new \DateTimeImmutable($datetime))->format('Y-m-d\TH:i:sP');
        } catch (Throwable $e) {
            return $datetime;
        }
    }

    private static function optionalGerekce($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            self::validationError('gerekce', 'gerekce metin olmalidir.');
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > 500) {
            self::validationError('gerekce', 'gerekce en fazla 500 karakter olabilir.');
        }

        return $trimmed;
    }

    private static function parsePositiveInt($value, string $field, bool $fromQuery): int
    {
        if ($value === null || $value === '') {
            if ($fromQuery) {
                JsonResponse::badRequest($field . ' zorunludur ve pozitif tam sayi olmalidir.', 'INVALID_QUERY', $field);
            }
            self::validationError($field, $field . ' zorunludur ve pozitif tam sayi olmalidir.');
        }
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $parsed = (int) trim($value);
        } else {
            if ($fromQuery) {
                JsonResponse::badRequest($field . ' zorunludur ve pozitif tam sayi olmalidir.', 'INVALID_QUERY', $field);
            }
            self::validationError($field, $field . ' zorunludur ve pozitif tam sayi olmalidir.');
        }
        if ($parsed < 1) {
            if ($fromQuery) {
                JsonResponse::badRequest($field . ' zorunludur ve pozitif tam sayi olmalidir.', 'INVALID_QUERY', $field);
            }
            self::validationError($field, $field . ' zorunludur ve pozitif tam sayi olmalidir.');
        }

        return $parsed;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private static function validationError(string $field, string $message): void
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    private static function rollbackConflict(PDO $pdo, string $code, string $message): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(409, $code, $message);
    }

    private static function rollbackNotFound(PDO $pdo, string $message): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(404, 'NOT_FOUND', $message);
    }
}
