<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

class BildirimPuantajEtkiConflictResolutionService
{
    public const SNAPSHOT_SCHEMA_VERSION = 'S75_CONFLICT_RESOLUTION_V1';
    public const RESOLUTION_TABLE = 'bildirim_puantaj_etki_cakisma_cozumleri';

    /**
     * @param array<string, mixed> $aday Locked FOR UPDATE row
     * @param array<string, mixed>|null $puantajRow Locked FOR UPDATE row or null
     * @return array{
     *   status: string,
     *   code?: string,
     *   message?: string,
     *   aday?: array<string, mixed>,
     *   puantaj?: array<string, mixed>|null,
     *   conflict_class?: string,
     *   karar_turu?: string,
     *   cakisma_cozum?: array<string, mixed>|null,
     *   onceki_ozet?: array<string, mixed>|null,
     *   sonraki_ozet?: array<string, mixed>|null,
     *   idempotent?: bool
     * }
     */
    public static function resolve(
        PDO $pdo,
        array $aday,
        $puantajRow,
        $expectedState,
        $kararTuru,
        $gerekce,
        $expectedPuantajId,
        $expectedPuantajHash,
        $kararVerenUserId
    ) {
        $adayId = (int) ($aday['id'] ?? 0);
        $normalizedGerekce = self::normalizeGerekce($gerekce);
        $kararTuru = strtoupper(trim((string) $kararTuru));
        $expectedState = BildirimPuantajEtkiDecisionPolicy::normalizeState((string) $expectedState);
        $currentState = BildirimPuantajEtkiDecisionPolicy::normalizeState((string) ($aday['state'] ?? ''));

        $existingResolution = self::fetchResolutionByAdayId($pdo, $adayId);
        $requestHash = self::computeRequestHash(
            $adayId,
            $expectedState,
            $kararTuru,
            $normalizedGerekce,
            (int) $expectedPuantajId,
            (string) $expectedPuantajHash
        );

        if ($existingResolution !== null) {
            return self::evaluateExistingResolution(
                $pdo,
                $aday,
                $puantajRow,
                $existingResolution,
                $requestHash,
                $kararTuru,
                $normalizedGerekce,
                (int) $expectedPuantajId
            );
        }

        if (!BildirimPuantajEtkiDecisionPolicy::isConflictResolveAllowed($currentState)) {
            return self::conflict('STATE_CONFLICT', 'Puantaj etki adayi cakisma cozumu icin uygun degil.');
        }

        if (!BildirimPuantajEtkiDecisionPolicy::validateExpectedState($currentState, $expectedState)['valid']) {
            return [
                'status' => 'stale',
                'code' => 'STATE_STALE',
                'message' => 'Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin.',
            ];
        }

        $integrity = BildirimPuantajEtkiManualApplyService::verifySourceIntegrity($aday);
        if (($integrity['ok'] ?? false) !== true) {
            return self::conflict(
                (string) ($integrity['code'] ?? 'SOURCE_INTEGRITY_FAILED'),
                (string) ($integrity['message'] ?? 'Aday kaynak butunlugu dogrulanamadi.')
            );
        }

        if (!is_array($puantajRow)) {
            return self::conflict('PUANTAJ_ARTIK_YOK', 'Mevcut puantaj kaydi bulunamadi.');
        }

        $puantajId = (int) ($puantajRow['id'] ?? 0);
        if ($puantajId < 1 || $puantajId !== (int) $expectedPuantajId) {
            return self::conflict('PUANTAJ_STALE', 'Mevcut puantaj kaydi degismis. Listeyi yenileyip tekrar deneyin.');
        }

        $liveHash = BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($puantajRow);
        if (!hash_equals((string) $expectedPuantajHash, $liveHash)) {
            return self::conflict('PUANTAJ_STALE', 'Mevcut puantaj kaydi degismis. Listeyi yenileyip tekrar deneyin.');
        }

        $classification = BildirimPuantajEtkiConflictClassificationService::classify($aday, $puantajRow);
        $conflictClass = (string) ($classification['class'] ?? '');

        if (!BildirimPuantajEtkiConflictClassificationService::isReviseAllowed($conflictClass, $kararTuru)) {
            $code = BildirimPuantajEtkiConflictClassificationService::conflictCodeForReviseBlocked($conflictClass);
            $message = $code === 'PUANTAJ_SOURCE_PROTECTED'
                ? 'Resmi surec dayanakli puantaj bildirim etkisiyle revize edilemez.'
                : ($code === 'PERIOD_LOCKED'
                    ? 'Muhurlu puantaj kaydi revize edilemez.'
                    : 'Secilen karar bu cakisma sinifi icin uygulanamaz.');

            return self::conflict($code, $message);
        }

        $kararZamani = gmdate('Y-m-d H:i:s');
        $oncekiSnapshot = self::buildResolutionSnapshot($aday, $puantajRow, $conflictClass, $kararTuru, $normalizedGerekce);

        if ($kararTuru === BildirimPuantajEtkiConflictClassificationService::KARAR_MEVCUT_KORU) {
            return self::applyKeepDecision(
                $pdo,
                $aday,
                $puantajRow,
                $conflictClass,
                $kararTuru,
                $normalizedGerekce,
                $expectedPuantajHash,
                $requestHash,
                $oncekiSnapshot,
                $kararVerenUserId,
                $kararZamani
            );
        }

        if ($kararTuru === BildirimPuantajEtkiConflictClassificationService::KARAR_REVIZE) {
            return self::applyReviseDecision(
                $pdo,
                $aday,
                $puantajRow,
                $conflictClass,
                $kararTuru,
                $normalizedGerekce,
                $expectedPuantajHash,
                $requestHash,
                $oncekiSnapshot,
                $kararVerenUserId,
                $kararZamani
            );
        }

        return self::validation('VALIDATION_ERROR', 'Desteklenmeyen karar turu.');
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed> $puantajRow
     * @return array<string, mixed>
     */
    public static function buildResolutionSnapshot(
        array $aday,
        array $puantajRow,
        $conflictClass,
        $kararTuru,
        $gerekce
    ) {
        return [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'aday_id' => (int) ($aday['id'] ?? 0),
            'conflict_class' => (string) $conflictClass,
            'karar_turu' => strtoupper(trim((string) $kararTuru)),
            'gerekce' => self::normalizeGerekce($gerekce),
            'puantaj' => BildirimPuantajEtkiPuantajMapper::canonicalPuantajConcurrencyPayload($puantajRow),
        ];
    }

    public static function computeRequestHash(
        $adayId,
        $expectedState,
        $kararTuru,
        $gerekce,
        $expectedPuantajId,
        $expectedPuantajHash
    ) {
        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'aday_id' => (int) $adayId,
            'expected_state' => BildirimPuantajEtkiDecisionPolicy::normalizeState((string) $expectedState),
            'karar_turu' => strtoupper(trim((string) $kararTuru)),
            'gerekce' => self::normalizeGerekce($gerekce),
            'expected_puantaj_id' => (int) $expectedPuantajId,
            'expected_puantaj_hash' => strtolower(trim((string) $expectedPuantajHash)),
        ];

        return hash('sha256', BildirimPuantajEtkiProjectionService::canonicalJson($payload));
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed>|null $sonrakiPuantaj
     */
    public static function computeSonucHash(
        array $aday,
        $conflictClass,
        $kararTuru,
        $gerekce,
        $puantajId,
        array $oncekiSnapshot,
        $sonrakiPuantaj,
        $kararVerenUserId
    ) {
        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'source_hash' => (string) ($aday['source_hash'] ?? ''),
            'conflict_class' => (string) $conflictClass,
            'karar_turu' => strtoupper(trim((string) $kararTuru)),
            'gerekce' => self::normalizeGerekce($gerekce),
            'puantaj_id' => $puantajId !== null ? (int) $puantajId : null,
            'onceki' => $oncekiSnapshot['puantaj'] ?? null,
            'sonraki' => $sonrakiPuantaj === null
                ? null
                : BildirimPuantajEtkiPuantajMapper::canonicalPuantajConcurrencyPayload($sonrakiPuantaj),
            'karar_veren_user_id' => (int) $kararVerenUserId,
        ];

        return hash('sha256', BildirimPuantajEtkiProjectionService::canonicalJson($payload));
    }

    public static function normalizeGerekce($value)
    {
        return trim((string) $value);
    }

    /** @return array<string, mixed>|null */
    public static function fetchResolutionByAdayId(PDO $pdo, $adayId)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::RESOLUTION_TABLE . ' WHERE aday_id = :aday_id LIMIT 1'
        );
        $stmt->execute(['aday_id' => (int) $adayId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|false */
    public static function fetchPuantajForUpdate(PDO $pdo, $personelId, $tarih)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM gunluk_puantaj WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'tarih' => (string) $tarih,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    public static function fetchPuantajById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM gunluk_puantaj WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function mapPuantajOzet(array $row)
    {
        return BildirimPuantajEtkiPuantajMapper::canonicalPuantajConcurrencyPayload($row);
    }

    /**
     * @param array<string, mixed> $resolution
     * @return array<string, mixed>
     */
    public static function mapResolutionSummary(array $resolution)
    {
        return [
            'id' => (int) $resolution['id'],
            'aday_id' => (int) $resolution['aday_id'],
            'puantaj_id' => $resolution['puantaj_id'] !== null ? (int) $resolution['puantaj_id'] : null,
            'conflict_class' => (string) $resolution['conflict_class'],
            'karar_turu' => (string) $resolution['karar_turu'],
            'gerekce' => (string) $resolution['gerekce'],
            'request_hash' => (string) $resolution['request_hash'],
            'sonuc_hash' => (string) $resolution['sonuc_hash'],
            'karar_veren_user_id' => (int) $resolution['karar_veren_user_id'],
            'karar_zamani' => (string) $resolution['karar_zamani'],
        ];
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed>|null $puantajRow
     * @param array<string, mixed> $existingResolution
     */
    private static function evaluateExistingResolution(
        PDO $pdo,
        array $aday,
        $puantajRow,
        array $existingResolution,
        $requestHash,
        $kararTuru,
        $gerekce,
        $expectedPuantajId
    ) {
        $storedRequestHash = trim((string) ($existingResolution['request_hash'] ?? ''));
        if ($storedRequestHash !== '' && hash_equals($storedRequestHash, $requestHash)) {
            $updatedAday = self::fetchAdayById($pdo, (int) $aday['id']);
            $puantaj = is_array($puantajRow)
                ? $puantajRow
                : self::fetchPuantajById($pdo, (int) ($existingResolution['puantaj_id'] ?? 0));

            return [
                'status' => 'idempotent',
                'aday' => $updatedAday ?: $aday,
                'puantaj' => is_array($puantaj) ? $puantaj : null,
                'conflict_class' => (string) $existingResolution['conflict_class'],
                'karar_turu' => (string) $existingResolution['karar_turu'],
                'cakisma_cozum' => self::mapResolutionSummary($existingResolution),
                'onceki_ozet' => self::decodeJsonField($existingResolution['onceki_snapshot'] ?? null),
                'sonraki_ozet' => self::decodeJsonField($existingResolution['sonraki_snapshot'] ?? null),
                'idempotent' => true,
            ];
        }

        $storedKarar = strtoupper(trim((string) ($existingResolution['karar_turu'] ?? '')));
        $storedGerekce = self::normalizeGerekce($existingResolution['gerekce'] ?? '');
        $storedPuantajId = $existingResolution['puantaj_id'] !== null ? (int) $existingResolution['puantaj_id'] : 0;

        if ($storedKarar !== strtoupper(trim((string) $kararTuru))
            || $storedGerekce !== $gerekce
            || ($storedPuantajId > 0 && $storedPuantajId !== $expectedPuantajId)) {
            return self::conflict(
                'REVISION_DECISION_CONFLICT',
                'Bu aday icin daha once farkli bir cakisma karari verilmis.'
            );
        }

        return self::conflict(
            'REVISION_DECISION_CONFLICT',
            'Bu aday icin daha once farkli bir cakisma karari verilmis.'
        );
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed> $puantajRow
     * @param array<string, mixed> $oncekiSnapshot
     */
    private static function applyKeepDecision(
        PDO $pdo,
        array $aday,
        array $puantajRow,
        $conflictClass,
        $kararTuru,
        $gerekce,
        $expectedPuantajHash,
        $requestHash,
        array $oncekiSnapshot,
        $kararVerenUserId,
        $kararZamani
    ) {
        $adayId = (int) $aday['id'];
        $sonrakiSnapshot = self::buildResolutionSnapshot($aday, $puantajRow, $conflictClass, $kararTuru, $gerekce);
        $sonucHash = self::computeSonucHash(
            $aday,
            $conflictClass,
            $kararTuru,
            $gerekce,
            (int) $puantajRow['id'],
            $oncekiSnapshot,
            $puantajRow,
            $kararVerenUserId
        );

        self::insertResolution(
            $pdo,
            $aday,
            (int) $puantajRow['id'],
            $conflictClass,
            $kararTuru,
            $gerekce,
            $expectedPuantajHash,
            $requestHash,
            $oncekiSnapshot,
            $sonrakiSnapshot,
            $sonucHash,
            $kararVerenUserId,
            $kararZamani
        );

        self::updateAdayForKeep($pdo, $adayId, $gerekce, $kararVerenUserId, $kararZamani);

        $updatedAday = self::fetchAdayById($pdo, $adayId);
        $resolution = self::fetchResolutionByAdayId($pdo, $adayId);

        return [
            'status' => 'success',
            'aday' => $updatedAday ?: $aday,
            'puantaj' => $puantajRow,
            'conflict_class' => $conflictClass,
            'karar_turu' => $kararTuru,
            'cakisma_cozum' => $resolution ? self::mapResolutionSummary($resolution) : null,
            'onceki_ozet' => $oncekiSnapshot,
            'sonraki_ozet' => $sonrakiSnapshot,
            'idempotent' => false,
        ];
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed> $puantajRow
     * @param array<string, mixed> $oncekiSnapshot
     */
    private static function applyReviseDecision(
        PDO $pdo,
        array $aday,
        array $puantajRow,
        $conflictClass,
        $kararTuru,
        $gerekce,
        $expectedPuantajHash,
        $requestHash,
        array $oncekiSnapshot,
        $kararVerenUserId,
        $kararZamani
    ) {
        $mapping = BildirimPuantajEtkiPuantajMapper::buildRevizeUpdateValues($aday, $puantajRow);
        if (($mapping['ok'] ?? false) !== true) {
            return self::conflict(
                (string) ($mapping['code'] ?? 'REVISION_NOT_ALLOWED'),
                (string) ($mapping['message'] ?? 'Aday etkisi revize edilemedi.')
            );
        }

        /** @var array<string, mixed> $values */
        $values = $mapping['values'];
        self::updatePuantajRow($pdo, (int) $puantajRow['id'], $values);

        $updatedPuantaj = self::fetchPuantajById($pdo, (int) $puantajRow['id']);
        if (!$updatedPuantaj) {
            return self::conflict('REVISION_INTEGRITY_FAILED', 'Revize edilen puantaj kaydi dogrulanamadi.');
        }

        $recomputedHash = BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($updatedPuantaj);
        if (hash_equals((string) $expectedPuantajHash, $recomputedHash)) {
            return self::conflict('REVISION_INTEGRITY_FAILED', 'Puantaj revizyonu uygulanamadi.');
        }

        $adayId = (int) $aday['id'];
        $sonrakiSnapshot = self::buildResolutionSnapshot($aday, $updatedPuantaj, $conflictClass, $kararTuru, $gerekce);
        $sonucHash = self::computeSonucHash(
            $aday,
            $conflictClass,
            $kararTuru,
            $gerekce,
            (int) $updatedPuantaj['id'],
            $oncekiSnapshot,
            $updatedPuantaj,
            $kararVerenUserId
        );

        self::insertResolution(
            $pdo,
            $aday,
            (int) $updatedPuantaj['id'],
            $conflictClass,
            $kararTuru,
            $gerekce,
            $expectedPuantajHash,
            $requestHash,
            $oncekiSnapshot,
            $sonrakiSnapshot,
            $sonucHash,
            $kararVerenUserId,
            $kararZamani
        );

        self::updateAdayForRevise(
            $pdo,
            $adayId,
            (int) $updatedPuantaj['id'],
            $gerekce,
            $kararVerenUserId,
            $kararZamani,
            $oncekiSnapshot,
            $sonrakiSnapshot,
            $sonucHash
        );

        $updatedAday = self::fetchAdayById($pdo, $adayId);
        $resolution = self::fetchResolutionByAdayId($pdo, $adayId);

        return [
            'status' => 'success',
            'aday' => $updatedAday ?: $aday,
            'puantaj' => $updatedPuantaj,
            'conflict_class' => $conflictClass,
            'karar_turu' => $kararTuru,
            'cakisma_cozum' => $resolution ? self::mapResolutionSummary($resolution) : null,
            'onceki_ozet' => $oncekiSnapshot,
            'sonraki_ozet' => $sonrakiSnapshot,
            'idempotent' => false,
        ];
    }

    /** @param array<string, mixed> $aday */
    private static function insertResolution(
        PDO $pdo,
        array $aday,
        $puantajId,
        $conflictClass,
        $kararTuru,
        $gerekce,
        $expectedPuantajHash,
        $requestHash,
        array $oncekiSnapshot,
        array $sonrakiSnapshot,
        $sonucHash,
        $kararVerenUserId,
        $kararZamani
    ) {
        $stmt = $pdo->prepare('
            INSERT INTO ' . self::RESOLUTION_TABLE . ' (
                aday_id, puantaj_id, sube_id, personel_id, tarih,
                conflict_class, karar_turu, gerekce,
                expected_puantaj_hash, request_hash,
                onceki_snapshot, sonraki_snapshot, snapshot_schema, sonuc_hash,
                karar_veren_user_id, karar_zamani
            ) VALUES (
                :aday_id, :puantaj_id, :sube_id, :personel_id, :tarih,
                :conflict_class, :karar_turu, :gerekce,
                :expected_puantaj_hash, :request_hash,
                :onceki_snapshot, :sonraki_snapshot, :snapshot_schema, :sonuc_hash,
                :karar_veren_user_id, :karar_zamani
            )
        ');
        $stmt->execute([
            'aday_id' => (int) $aday['id'],
            'puantaj_id' => $puantajId !== null ? (int) $puantajId : null,
            'sube_id' => (int) $aday['sube_id'],
            'personel_id' => (int) $aday['personel_id'],
            'tarih' => (string) $aday['tarih'],
            'conflict_class' => (string) $conflictClass,
            'karar_turu' => strtoupper(trim((string) $kararTuru)),
            'gerekce' => self::normalizeGerekce($gerekce),
            'expected_puantaj_hash' => strtolower(trim((string) $expectedPuantajHash)),
            'request_hash' => (string) $requestHash,
            'onceki_snapshot' => BildirimPuantajEtkiProjectionService::canonicalJson($oncekiSnapshot),
            'sonraki_snapshot' => BildirimPuantajEtkiProjectionService::canonicalJson($sonrakiSnapshot),
            'snapshot_schema' => self::SNAPSHOT_SCHEMA_VERSION,
            'sonuc_hash' => (string) $sonucHash,
            'karar_veren_user_id' => (int) $kararVerenUserId,
            'karar_zamani' => (string) $kararZamani,
        ]);
    }

    private static function updateAdayForKeep(PDO $pdo, $adayId, $gerekce, $kararVerenUserId, $kararZamani)
    {
        $stmt = $pdo->prepare('
            UPDATE onayli_bildirim_puantaj_etki_adaylari
            SET state = :state,
                uygulama_modu = :uygulama_modu,
                karar_veren_user_id = :karar_veren_user_id,
                karar_zamani = :karar_zamani,
                karar_gerekcesi = :karar_gerekcesi,
                uygulanan_puantaj_id = NULL
            WHERE id = :id
        ');
        $stmt->execute([
            'state' => 'YOK_SAYILDI',
            'uygulama_modu' => BildirimPuantajEtkiDecisionPolicy::UYGULAMA_MODU_CAKISMA_COZUM,
            'karar_veren_user_id' => (int) $kararVerenUserId,
            'karar_zamani' => (string) $kararZamani,
            'karar_gerekcesi' => self::normalizeGerekce($gerekce),
            'id' => (int) $adayId,
        ]);
    }

    /**
     * @param array<string, mixed> $oncekiSnapshot
     * @param array<string, mixed> $sonrakiSnapshot
     */
    private static function updateAdayForRevise(
        PDO $pdo,
        $adayId,
        $puantajId,
        $gerekce,
        $kararVerenUserId,
        $kararZamani,
        array $oncekiSnapshot,
        array $sonrakiSnapshot,
        $sonucHash
    ) {
        $stmt = $pdo->prepare('
            UPDATE onayli_bildirim_puantaj_etki_adaylari
            SET state = :state,
                uygulama_modu = :uygulama_modu,
                karar_veren_user_id = :karar_veren_user_id,
                karar_zamani = :karar_zamani,
                karar_gerekcesi = :karar_gerekcesi,
                uygulanan_puantaj_id = :uygulanan_puantaj_id,
                onceki_puantaj_snapshot = :onceki_puantaj_snapshot,
                sonraki_puantaj_snapshot = :sonraki_puantaj_snapshot,
                uygulama_hash = :uygulama_hash
            WHERE id = :id
        ');
        $stmt->execute([
            'state' => 'UYGULANDI',
            'uygulama_modu' => BildirimPuantajEtkiDecisionPolicy::UYGULAMA_MODU_CAKISMA_COZUM,
            'karar_veren_user_id' => (int) $kararVerenUserId,
            'karar_zamani' => (string) $kararZamani,
            'karar_gerekcesi' => self::normalizeGerekce($gerekce),
            'uygulanan_puantaj_id' => (int) $puantajId,
            'onceki_puantaj_snapshot' => BildirimPuantajEtkiProjectionService::canonicalJson($oncekiSnapshot),
            'sonraki_puantaj_snapshot' => BildirimPuantajEtkiProjectionService::canonicalJson($sonrakiSnapshot),
            'uygulama_hash' => (string) $sonucHash,
            'id' => (int) $adayId,
        ]);
    }

    /** @param array<string, mixed> $values */
    private static function updatePuantajRow(PDO $pdo, $id, array $values)
    {
        $stmt = $pdo->prepare('
            UPDATE gunluk_puantaj
            SET gun_tipi = :gun_tipi,
                hareket_durumu = :hareket_durumu,
                dayanak = :dayanak,
                durumu_bildirdi_mi = :durumu_bildirdi_mi,
                durum_bildirim_aciklamasi = :durum_bildirim_aciklamasi,
                hesap_etkisi = :hesap_etkisi,
                giris_saati = :giris_saati,
                cikis_saati = :cikis_saati,
                beklenen_giris_saati = :beklenen_giris_saati,
                beklenen_cikis_saati = :beklenen_cikis_saati,
                gec_kalma_dakika = :gec_kalma_dakika,
                erken_cikis_dakika = :erken_cikis_dakika,
                gercek_mola_dakika = :gercek_mola_dakika,
                hesaplanan_mola_dakika = :hesaplanan_mola_dakika,
                net_calisma_suresi_dakika = :net_calisma_suresi_dakika,
                gunluk_brut_sure_dakika = :gunluk_brut_sure_dakika,
                hafta_tatili_hak_kazandi_mi = :hafta_tatili_hak_kazandi_mi,
                state = :state,
                kontrol_durumu = :kontrol_durumu,
                kaynak = :kaynak,
                aciklama = :aciklama,
                muhur_id = :muhur_id,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute([
            'gun_tipi' => $values['gun_tipi'],
            'hareket_durumu' => $values['hareket_durumu'],
            'dayanak' => $values['dayanak'],
            'durumu_bildirdi_mi' => $values['durumu_bildirdi_mi'],
            'durum_bildirim_aciklamasi' => $values['durum_bildirim_aciklamasi'],
            'hesap_etkisi' => $values['hesap_etkisi'],
            'giris_saati' => $values['giris_saati'],
            'cikis_saati' => $values['cikis_saati'],
            'beklenen_giris_saati' => $values['beklenen_giris_saati'],
            'beklenen_cikis_saati' => $values['beklenen_cikis_saati'],
            'gec_kalma_dakika' => $values['gec_kalma_dakika'],
            'erken_cikis_dakika' => $values['erken_cikis_dakika'],
            'gercek_mola_dakika' => $values['gercek_mola_dakika'],
            'hesaplanan_mola_dakika' => $values['hesaplanan_mola_dakika'],
            'net_calisma_suresi_dakika' => $values['net_calisma_suresi_dakika'],
            'gunluk_brut_sure_dakika' => $values['gunluk_brut_sure_dakika'],
            'hafta_tatili_hak_kazandi_mi' => $values['hafta_tatili_hak_kazandi_mi'],
            'state' => $values['state'],
            'kontrol_durumu' => $values['kontrol_durumu'],
            'kaynak' => $values['kaynak'],
            'aciklama' => $values['aciklama'],
            'muhur_id' => $values['muhur_id'],
            'id' => (int) $id,
        ]);
    }

    /** @return array<string, mixed>|false */
    private static function fetchAdayById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return mixed */
    private static function decodeJsonField($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{status: string, code: string, message: string} */
    private static function conflict($code, $message)
    {
        return [
            'status' => 'conflict',
            'code' => (string) $code,
            'message' => (string) $message,
        ];
    }

    /** @return array{status: string, code: string, message: string} */
    private static function validation($code, $message)
    {
        return [
            'status' => 'validation',
            'code' => (string) $code,
            'message' => (string) $message,
        ];
    }
}
