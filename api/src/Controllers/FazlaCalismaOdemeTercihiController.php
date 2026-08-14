<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Payroll\FazlaCalismaYillikLimitService;
use Medisa\Api\Services\Payroll\PayrollComplianceGuard;
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
        'sisteme_giren_kullanici_id',
        'sisteme_giris_zamani',
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
        // Authorize snapshot/branch before operational guard.
        self::assertSnapshotScope($user, $request, $satir);
        \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::assertOperationalEligible(
            $pdo,
            (int) $satir['personel_id']
        );

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

        foreach ([
            'fazla_calisma_odeme_tercihleri',
            'fazla_calisma_odeme_tercihi_audit',
            'haftalik_kapanis_satirlari',
            'yillik_fazla_calisma_kilitleri',
        ] as $table) {
            if (!self::tableExists($pdo, $table)) {
                JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Odeme tercihi semasi hazir degil.');
            }
        }

        $satirProbe = self::loadSnapshotSatir($pdo, $snapshotId);
        if ($satirProbe === null) {
            JsonResponse::error(404, 'NOT_FOUND', 'snapshot_id icin odeme tercihi veya kapanis satiri bulunamadi.');
        }
        // Authorize snapshot/branch before operational guard.
        self::assertSnapshotScope($user, $request, $satirProbe);
        \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::assertOperationalEligible(
            $pdo,
            (int) $satirProbe['personel_id']
        );

        $hasKanitCols = self::columnExists($pdo, 'fazla_calisma_odeme_tercihleri', 'talep_tarihi')
            && self::columnExists($pdo, 'fazla_calisma_odeme_tercihleri', 'imzali_talep_belge_id');
        $hasSistemeCols = self::columnExists($pdo, 'fazla_calisma_odeme_tercihleri', 'sisteme_giren_kullanici_id')
            && self::columnExists($pdo, 'fazla_calisma_odeme_tercihleri', 'sisteme_giris_zamani');
        $hasAuditKanitCols = self::columnExists($pdo, 'fazla_calisma_odeme_tercihi_audit', 'imzali_talep_belge_id')
            && self::columnExists($pdo, 'fazla_calisma_odeme_tercihi_audit', 'talep_tarihi');

        $pdo->beginTransaction();

        try {
            $satir = self::loadSnapshotSatir($pdo, $snapshotId, true);
            if ($satir === null) {
                self::rollbackNotFound($pdo, 'snapshot_id icin odeme tercihi veya kapanis satiri bulunamadi.');
            }

            $subeId = (int) $satir['sube_id'];
            $personelId = (int) $satir['personel_id'];
            \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::assertOperationalEligible($pdo, $personelId);
            $fazlaDk = (int) $satir['fazla_calisma_dakika'];
            $haftaBaslangic = (string) $satir['hafta_baslangic'];
            $haftaBitis = (string) $satir['hafta_bitis'];

            self::assertWeekPeriodsOpen($pdo, $subeId, $haftaBaslangic, $haftaBitis);

            $existing = self::loadTercihBySnapshot($pdo, $snapshotId, true);
            $onceki = $existing !== null
                ? (string) $existing['odeme_tipi']
                : self::DEFAULT_ODEME_TIPI;

            if (
                $existing !== null
                && $onceki === 'SERBEST_ZAMAN'
                && ($odemeTipi === 'UCRET' || $odemeTipi === 'KARAR_BEKLIYOR')
                && PayrollComplianceGuard::hasActiveSerbestZamanOlusum($pdo, (int) $existing['id'])
            ) {
                self::rollbackConflict(
                    $pdo,
                    'STATE_CONFLICT',
                    'Aktif serbest zaman olusumu varken odeme tipi degistirilemez.'
                );
            }

            // UCRET/KARAR_BEKLIYOR icin odeme tipi owner'dir; gerekce-only delta idempotent kalir.
            if (
                $existing !== null
                && (string) $existing['odeme_tipi'] === $odemeTipi
                && $odemeTipi !== 'SERBEST_ZAMAN'
            ) {
                $pdo->commit();
                JsonResponse::success(self::mapTercih($existing));
            }

            $talepTarihi = null;
            $imzaliTalepBelgeId = null;
            $sistemeGirenKullaniciId = null;
            $sistemeGirisZamani = null;

            $userId = (int) ($user['id'] ?? 0);
            $now = date('Y-m-d H:i:s');

            if ($odemeTipi === 'SERBEST_ZAMAN') {
                if (
                    !$hasKanitCols
                    || !$hasAuditKanitCols
                    || !self::tableExists($pdo, 'surecler')
                    || !self::tableExists($pdo, 'serbest_zaman_events')
                ) {
                    self::rollbackConflict(
                        $pdo,
                        'SCHEMA_NOT_READY',
                        'SERBEST_ZAMAN kanit semasi hazir degil.'
                    );
                }

                $kanitPayload = [
                    'talep_tarihi' => $body['talep_tarihi'] ?? null,
                    'imzali_talep_belge_id' => $body['imzali_talep_belge_id'] ?? null,
                    'gerekce' => $gerekce ?? ($body['gerekce'] ?? null),
                ];
                $belgeId = isset($body['imzali_talep_belge_id'])
                    ? (int) $body['imzali_talep_belge_id']
                    : 0;
                $belgeRow = $belgeId > 0
                    ? PayrollComplianceGuard::loadBelgeKaydi($pdo, $belgeId)
                    : null;
                $kanit = PayrollComplianceGuard::validateSerbestZamanKanit(
                    $kanitPayload,
                    $belgeRow,
                    $personelId
                );
                if (!$kanit['ok']) {
                    $code = (string) ($kanit['code'] ?? PayrollComplianceGuard::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK);
                    $message = (string) ($kanit['message'] ?? 'SERBEST_ZAMAN kanit dogrulamasi basarisiz.');
                    self::rollbackValidation($pdo, $code, $message);
                }
                $talepTarihi = trim((string) $body['talep_tarihi']);
                $imzaliTalepBelgeId = $belgeId;
                $sistemeGirenKullaniciId = $userId > 0 ? $userId : null;
                $sistemeGirisZamani = $now;
                if ($gerekce === null || $gerekce === '') {
                    self::rollbackValidation(
                        $pdo,
                        PayrollComplianceGuard::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                        'SERBEST_ZAMAN icin gerekce/not zorunludur.'
                    );
                }

                // SERBEST_ZAMAN idempotency tam kanit payload'idir; belge/tarih/gerekce degisirse duzeltme yazilir.
                if (
                    $existing !== null
                    && (string) $existing['odeme_tipi'] === 'SERBEST_ZAMAN'
                    && self::isSameSerbestZamanEvidence(
                        $existing,
                        $talepTarihi,
                        $imzaliTalepBelgeId,
                        $gerekce
                    )
                ) {
                    $pdo->commit();
                    JsonResponse::success(self::mapTercih($existing));
                }
            }

            if ($fazlaDk > 0) {
                $dob = self::loadPersonelDogumTarihi($pdo, $personelId);
                $age = PayrollComplianceGuard::resolveUnder18($dob, $haftaBitis);
                if ($age['missing_dob']) {
                    self::rollbackValidation(
                        $pdo,
                        PayrollComplianceGuard::BLOCKER_DOGUM_TARIHI_REQUIRED,
                        'Dogum tarihi olmadan fazla calisma islemi yapilamaz.'
                    );
                }
                if ($age['under_18']) {
                    self::rollbackConflict(
                        $pdo,
                        PayrollComplianceGuard::BLOCKER_ONSEKIZ_YAS_FAZLA_CALISMA,
                        '18 yasini doldurmamis personelde fazla calisma kaydedilemez.'
                    );
                }
            }

            if ($odemeTipi === 'UCRET' || $odemeTipi === 'SERBEST_ZAMAN') {
                FazlaCalismaYillikLimitService::acquirePersonelRollingLock(
                    $pdo,
                    $personelId,
                    $userId > 0 ? $userId : null
                );
                $pendingDist = FazlaCalismaYillikLimitService::loadWeekPendingDistribution(
                    $pdo,
                    $personelId,
                    $haftaBaslangic
                );
                $eval = FazlaCalismaYillikLimitService::evaluatePendingAgainstRolling(
                    $pdo,
                    $personelId,
                    $haftaBitis,
                    $fazlaDk,
                    $pendingDist,
                    [$haftaBaslangic]
                );
                if ($eval['asildi']) {
                    self::rollbackConflict(
                        $pdo,
                        PayrollComplianceGuard::BLOCKER_YILLIK_270_SAAT_ASIMI,
                        'Yillik fazla calisma 270 saat limiti asiliyor.'
                    );
                }
            }

            if ($existing === null) {
                $cols = [
                    'snapshot_id', 'kapanis_id', 'personel_id', 'hafta_baslangic', 'hafta_bitis',
                    'fazla_calisma_dakika', 'odeme_tipi', 'secim_zamani', 'secen_kullanici_id',
                    'onceki_odeme_tipi', 'gerekce',
                ];
                $params = [
                    'snapshot_id' => $snapshotId,
                    'kapanis_id' => (int) $satir['kapanis_id'],
                    'personel_id' => $personelId,
                    'hafta_baslangic' => $haftaBaslangic,
                    'hafta_bitis' => $haftaBitis,
                    'fazla_calisma_dakika' => $fazlaDk,
                    'odeme_tipi' => $odemeTipi,
                    'secim_zamani' => $now,
                    'secen_kullanici_id' => $userId > 0 ? $userId : null,
                    'onceki_odeme_tipi' => $onceki,
                    'gerekce' => $gerekce,
                ];
                if ($hasKanitCols) {
                    $cols[] = 'talep_tarihi';
                    $cols[] = 'imzali_talep_belge_id';
                    $params['talep_tarihi'] = $talepTarihi;
                    $params['imzali_talep_belge_id'] = $imzaliTalepBelgeId;
                }
                if ($hasSistemeCols) {
                    $cols[] = 'sisteme_giren_kullanici_id';
                    $cols[] = 'sisteme_giris_zamani';
                    $params['sisteme_giren_kullanici_id'] = $sistemeGirenKullaniciId;
                    $params['sisteme_giris_zamani'] = $sistemeGirisZamani;
                }
                $placeholders = array_map(static function (string $c): string {
                    return ':' . $c;
                }, $cols);
                $ins = $pdo->prepare(
                    'INSERT INTO fazla_calisma_odeme_tercihleri
                      (' . implode(', ', $cols) . ')
                     VALUES
                      (' . implode(', ', $placeholders) . ')'
                );
                $ins->execute($params);
                $tercihId = (int) $pdo->lastInsertId();
            } else {
                $tercihId = (int) $existing['id'];
                $set = [
                    'odeme_tipi = :odeme_tipi',
                    'secim_zamani = :secim_zamani',
                    'secen_kullanici_id = :secen_kullanici_id',
                    'onceki_odeme_tipi = :onceki_odeme_tipi',
                    'gerekce = :gerekce',
                    'fazla_calisma_dakika = :fazla_calisma_dakika',
                ];
                $params = [
                    'odeme_tipi' => $odemeTipi,
                    'secim_zamani' => $now,
                    'secen_kullanici_id' => $userId > 0 ? $userId : null,
                    'onceki_odeme_tipi' => $onceki,
                    'gerekce' => $gerekce,
                    'fazla_calisma_dakika' => $fazlaDk,
                    'id' => $tercihId,
                ];
                if ($hasKanitCols) {
                    $set[] = 'talep_tarihi = :talep_tarihi';
                    $set[] = 'imzali_talep_belge_id = :imzali_talep_belge_id';
                    $params['talep_tarihi'] = $talepTarihi;
                    $params['imzali_talep_belge_id'] = $imzaliTalepBelgeId;
                }
                if ($hasSistemeCols) {
                    $set[] = 'sisteme_giren_kullanici_id = :sisteme_giren_kullanici_id';
                    $set[] = 'sisteme_giris_zamani = :sisteme_giris_zamani';
                    $params['sisteme_giren_kullanici_id'] = $sistemeGirenKullaniciId;
                    $params['sisteme_giris_zamani'] = $sistemeGirisZamani;
                }
                $upd = $pdo->prepare(
                    'UPDATE fazla_calisma_odeme_tercihleri
                     SET ' . implode(",\n                         ", $set) . '
                     WHERE id = :id'
                );
                $upd->execute($params);
            }

            if ($hasAuditKanitCols) {
                $audit = $pdo->prepare(
                    'INSERT INTO fazla_calisma_odeme_tercihi_audit
                      (tercih_id, snapshot_id, onceki_odeme_tipi, yeni_odeme_tipi,
                       secen_kullanici_id, secim_zamani, gerekce,
                       imzali_talep_belge_id, talep_tarihi)
                     VALUES
                      (:tercih_id, :snapshot_id, :onceki_odeme_tipi, :yeni_odeme_tipi,
                       :secen_kullanici_id, :secim_zamani, :gerekce,
                       :imzali_talep_belge_id, :talep_tarihi)'
                );
                $audit->execute([
                    'tercih_id' => $tercihId,
                    'snapshot_id' => $snapshotId,
                    'onceki_odeme_tipi' => $onceki,
                    'yeni_odeme_tipi' => $odemeTipi,
                    'secen_kullanici_id' => $userId,
                    'secim_zamani' => $now,
                    'gerekce' => $gerekce,
                    'imzali_talep_belge_id' => $imzaliTalepBelgeId,
                    'talep_tarihi' => $talepTarihi,
                ]);
            } else {
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
            }

            if ($odemeTipi === 'SERBEST_ZAMAN') {
                self::ensureSerbestZamanOlusum(
                    $pdo,
                    $tercihId,
                    $personelId,
                    $snapshotId,
                    $subeId,
                    $fazlaDk,
                    $now,
                    $userId > 0 ? $userId : null
                );
            }

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
     * Idempotent SERBEST_ZAMAN_OLUSUM — columns match SerbestZamanController::olusum().
     */
    private static function ensureSerbestZamanOlusum(
        PDO $pdo,
        int $tercihId,
        int $personelId,
        int $snapshotId,
        int $subeId,
        int $fazlaDk,
        string $secimZamani,
        ?int $createdBy
    ): void {
        if (!self::tableExists($pdo, 'serbest_zaman_events')) {
            throw new \RuntimeException('SCHEMA_NOT_READY:serbest_zaman_events');
        }
        if (PayrollComplianceGuard::hasActiveSerbestZamanOlusum($pdo, $tercihId)) {
            return;
        }

        $dakika = (int) round($fazlaDk * PayrollComplianceGuard::SERBEST_ZAMAN_DONUSUM_KATSAYISI);
        if ($dakika <= 0) {
            return;
        }

        $eventTarihi = self::extractEventTarihi($secimZamani);
        $sonKullanim = self::hesaplaSonKullanimTarihi($eventTarihi);
        $donem = self::resolveDonemMeta($pdo, $subeId, $eventTarihi);

        $ins = $pdo->prepare(
            'INSERT INTO serbest_zaman_events
              (personel_id, event_tipi, dakika, event_tarihi, son_kullanim_tarihi,
               kaynak_snapshot_id, kaynak_odeme_tercihi_id, aciklama,
               donem_yil, donem_ay, donem_kilitli_miydi, created_by)
             VALUES
              (:personel_id, \'SERBEST_ZAMAN_OLUSUM\', :dakika, :event_tarihi, :son_kullanim,
               :snapshot_id, :tercih_id, :aciklama,
               :donem_yil, :donem_ay, :donem_kilitli, :created_by)'
        );
        $ins->execute([
            'personel_id' => $personelId,
            'dakika' => $dakika,
            'event_tarihi' => $eventTarihi,
            'son_kullanim' => $sonKullanim,
            'snapshot_id' => $snapshotId,
            'tercih_id' => $tercihId,
            'aciklama' => 'FM snapshot ' . $snapshotId . ' serbest zaman olusumu',
            'donem_yil' => $donem['yil'],
            'donem_ay' => $donem['ay'],
            'donem_kilitli' => $donem['kilitli'] ? 1 : 0,
            'created_by' => $createdBy,
        ]);
        $eventId = (int) $pdo->lastInsertId();

        if (self::tableExists($pdo, 'serbest_zaman_aktif_olusumlar')) {
            $gIns = $pdo->prepare(
                'INSERT INTO serbest_zaman_aktif_olusumlar (odeme_tercihi_id, olusum_event_id)
                 VALUES (:tid, :eid)'
            );
            try {
                $gIns->execute(['tid' => $tercihId, 'eid' => $eventId]);
            } catch (PDOException $e) {
                $info = $e->errorInfo ?? [];
                if (isset($info[1]) && (int) $info[1] === 1062) {
                    // Concurrent insert — treat as idempotent success.
                    return;
                }
                throw $e;
            }
        }
    }

    /** @return array{yil: int|null, ay: int|null, kilitli: bool} */
    private static function resolveDonemMeta(PDO $pdo, int $subeId, string $eventTarihi): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $eventTarihi, $m)) {
            return ['yil' => null, 'ay' => null, 'kilitli' => false];
        }
        $yil = (int) $m[1];
        $ay = (int) $m[2];
        $kilitli = false;
        try {
            if (self::tableExists($pdo, 'puantaj_aylik_muhurleri')) {
                $kilitli = PuantajDonemKilidiService::hasEffectiveSeal($pdo, [
                    'sube_id' => $subeId,
                    'yil' => $yil,
                    'ay' => $ay,
                ]);
            }
        } catch (Throwable $e) {
            $kilitli = false;
        }

        return ['yil' => $yil, 'ay' => $ay, 'kilitli' => $kilitli];
    }

    private static function extractEventTarihi(?string $secimZamani): string
    {
        if ($secimZamani !== null && $secimZamani !== '') {
            $part = substr(trim($secimZamani), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $part) === 1) {
                return $part;
            }
        }

        return date('Y-m-d');
    }

    private static function hesaplaSonKullanimTarihi(string $eventTarihi): string
    {
        try {
            $dt = new \DateTimeImmutable($eventTarihi);
            $target = $dt->modify('+6 months');
            $day = (int) $dt->format('d');
            $lastDay = (int) $target->format('t');
            $normalizedDay = min($day, $lastDay);

            return $target->format('Y-m-') . str_pad((string) $normalizedDay, 2, '0', STR_PAD_LEFT);
        } catch (Throwable $e) {
            return $eventTarihi;
        }
    }

    private static function loadPersonelDogumTarihi(PDO $pdo, int $personelId): ?string
    {
        if ($personelId < 1 || !self::tableExists($pdo, 'personeller')) {
            return null;
        }
        if (!self::columnExists($pdo, 'personeller', 'dogum_tarihi')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT dogum_tarihi FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['dogum_tarihi'] === null || $row['dogum_tarihi'] === '') {
            return null;
        }

        return (string) $row['dogum_tarihi'];
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
                if (PuantajDonemKilidiService::hasEffectiveSeal($pdo, $lock)) {
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
        if (array_key_exists('talep_tarihi', $row) && $row['talep_tarihi'] !== null && $row['talep_tarihi'] !== '') {
            $out['talep_tarihi'] = (string) $row['talep_tarihi'];
        }
        if (array_key_exists('imzali_talep_belge_id', $row) && $row['imzali_talep_belge_id'] !== null) {
            $out['imzali_talep_belge_id'] = (int) $row['imzali_talep_belge_id'];
        }
        if (array_key_exists('sisteme_giren_kullanici_id', $row) && $row['sisteme_giren_kullanici_id'] !== null) {
            $out['sisteme_giren_kullanici_id'] = (int) $row['sisteme_giren_kullanici_id'];
        }
        if (array_key_exists('sisteme_giris_zamani', $row) && !empty($row['sisteme_giris_zamani'])) {
            $out['sisteme_giris_zamani'] = self::toIso((string) $row['sisteme_giris_zamani']);
        }

        return $out;
    }

    /** @param array<string, mixed> $existing */
    private static function isSameSerbestZamanEvidence(
        array $existing,
        string $talepTarihi,
        int $imzaliTalepBelgeId,
        string $gerekce
    ): bool {
        return trim((string) ($existing['talep_tarihi'] ?? '')) === $talepTarihi
            && (int) ($existing['imzali_talep_belge_id'] ?? 0) === $imzaliTalepBelgeId
            && trim((string) ($existing['gerekce'] ?? '')) === trim($gerekce);
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

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

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

    private static function rollbackValidation(PDO $pdo, string $code, string $message): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(422, $code, $message);
    }

    private static function rollbackNotFound(PDO $pdo, string $message): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(404, 'NOT_FOUND', $message);
    }
}
