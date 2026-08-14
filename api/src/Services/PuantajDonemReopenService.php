<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Retention\PhysicalDestruction\PuantajPhysicalDestructionGate;
use PDO;

/**
 * Dual-control reopen talep owner + reseal revision.
 */
class PuantajDonemReopenService
{
    public const AUDIT_REQUESTED = 'PERIOD_REOPEN_REQUESTED';
    public const AUDIT_APPROVED = 'PERIOD_REOPEN_APPROVED';
    public const AUDIT_REJECTED = 'PERIOD_REOPEN_REJECTED';
    public const AUDIT_RESEAL_STARTED = 'PERIOD_RESEAL_STARTED';
    public const AUDIT_RESEALED = 'PERIOD_RESEALED';
    public const AUDIT_RESEAL_FAILED = 'PERIOD_RESEAL_FAILED';

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function createReopenRequest(PDO $pdo, array $user, $subeId, $yil, $ay, $gerekce)
    {
        $gerekce = trim((string) $gerekce);
        if ($gerekce === '') {
            throw new PuantajDonemReopenException('VALIDATION_ERROR', 'Gerekce zorunludur.', 400, ['field' => 'gerekce']);
        }

        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId < 1) {
            throw new PuantajDonemReopenException('UNAUTHORIZED', 'Oturum gerekli.', 401);
        }

        PuantajDonemKilidiService::acquire($pdo, (int) $subeId, (int) $yil, (int) $ay);
        PuantajPhysicalDestructionGate::assertPeriodNotDestroyed($pdo, $subeId, $yil, $ay);

        $seal = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay, true);
        if ($seal === null) {
            throw new PuantajDonemReopenException('PERIOD_NOT_SEALED', 'Donem muhurlenmemis; reopen talebi olusturulamaz.', 409);
        }

        $state = PuantajDonemPeriodService::resolvePeriodState($pdo, $subeId, $yil, $ay);
        if ($state !== PuantajDonemPeriodService::STATE_SEALED) {
            $open = PuantajDonemPeriodService::findOpenReopenTalep($pdo, $subeId, $yil, $ay);
            if ($open !== null) {
                throw new PuantajDonemReopenException(
                    'REOPEN_REQUEST_ALREADY_EXISTS',
                    'Bu donem icin acik reopen talebi zaten var.',
                    409,
                    ['talep_id' => (int) $open['id'], 'talep_durumu' => (string) $open['talep_durumu']]
                );
            }
            throw new PuantajDonemReopenException('PERIOD_NOT_SEALED', 'Donem SEALED degil; reopen talebi olusturulamaz.', 409);
        }

        $requestHash = PuantajDonemPeriodService::hashCanonical([
            'aksiyon' => self::AUDIT_REQUESTED,
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'kaynak_muhur_id' => (int) $seal['id'],
            'gerekce' => $gerekce,
            'actor_id' => $actorId,
        ]);

        $existingByHash = self::findTalepByHash($pdo, $subeId, $yil, $ay, $requestHash);
        if ($existingByHash) {
            $existingDurum = (string) ($existingByHash['talep_durumu'] ?? '');
            if ($existingDurum === PuantajDonemPeriodService::TALEP_ONAY_BEKLIYOR
                || $existingDurum === PuantajDonemPeriodService::TALEP_ONAYLANDI
            ) {
                return self::mapTalep($existingByHash);
            }
            // Terminal talep (REDDEDILDI/UYGULANDI): ayni gerekce ile yeni lifecycle ac.
            $requestHash = PuantajDonemPeriodService::hashCanonical([
                'aksiyon' => self::AUDIT_REQUESTED,
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'kaynak_muhur_id' => (int) $seal['id'],
                'gerekce' => $gerekce,
                'actor_id' => $actorId,
                'retry_after_talep_id' => (int) $existingByHash['id'],
            ]);
        }

        $now = self::serverNow($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO puantaj_donem_reopen_talepleri (
                sube_id, yil, ay, kaynak_muhur_id, talep_durumu, gerekce,
                requested_by, requested_at, request_hash
             ) VALUES (
                :sube_id, :yil, :ay, :kaynak_muhur_id, :durum, :gerekce,
                :requested_by, :requested_at, :request_hash
             )'
        );
        try {
            $stmt->execute([
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'kaynak_muhur_id' => (int) $seal['id'],
                'durum' => PuantajDonemPeriodService::TALEP_ONAY_BEKLIYOR,
                'gerekce' => $gerekce,
                'requested_by' => $actorId,
                'requested_at' => $now,
                'request_hash' => $requestHash,
            ]);
        } catch (\PDOException $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }
            $open = PuantajDonemPeriodService::findOpenReopenTalep($pdo, $subeId, $yil, $ay);
            if ($open !== null) {
                throw new PuantajDonemReopenException(
                    'REOPEN_REQUEST_ALREADY_EXISTS',
                    'Bu donem icin acik reopen talebi zaten var.',
                    409,
                    ['talep_id' => (int) $open['id'], 'talep_durumu' => (string) $open['talep_durumu']]
                );
            }
            throw new PuantajDonemReopenException(
                'REOPEN_REQUEST_ALREADY_EXISTS',
                'Reopen talebi yarisi: duplicate anahtar.',
                409
            );
        }
        $talepId = (int) $pdo->lastInsertId();

        self::writeAudit($pdo, [
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'aksiyon' => self::AUDIT_REQUESTED,
            'sonuc' => 'OK',
            'reopen_talep_id' => $talepId,
            'source_muhur_id' => (int) $seal['id'],
            'source_revision' => (int) ($seal['revision_no'] ?? 1),
            'target_muhur_id' => null,
            'target_revision' => null,
            'request_hash' => $requestHash,
            'previous_source_hash' => $seal['source_hash'] ?? null,
            'new_source_hash' => null,
            'failure_code' => null,
            'payload_json' => ['gerekce_len' => strlen($gerekce)],
            'actor_id' => $actorId,
        ]);

        $talep = self::findTalepById($pdo, $talepId);

        return self::mapTalep($talep ?: []);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function approveReopenRequest(PDO $pdo, array $user, $subeId, $yil, $ay, $talepId, $onayNotu = null)
    {
        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId < 1) {
            throw new PuantajDonemReopenException('UNAUTHORIZED', 'Oturum gerekli.', 401);
        }

        PuantajDonemKilidiService::acquire($pdo, (int) $subeId, (int) $yil, (int) $ay);
        PuantajPhysicalDestructionGate::assertPeriodNotDestroyed($pdo, $subeId, $yil, $ay);

        $talep = self::findTalepById($pdo, $talepId, true);
        if ($talep === null
            || (int) $talep['sube_id'] !== (int) $subeId
            || (int) $talep['yil'] !== (int) $yil
            || (int) $talep['ay'] !== (int) $ay
        ) {
            throw new PuantajDonemReopenException('REOPEN_REQUEST_NOT_FOUND', 'Reopen talebi bulunamadi.', 404);
        }

        if ((string) $talep['talep_durumu'] === PuantajDonemPeriodService::TALEP_ONAYLANDI) {
            return self::mapTalep($talep);
        }
        if ((string) $talep['talep_durumu'] !== PuantajDonemPeriodService::TALEP_ONAY_BEKLIYOR) {
            throw new PuantajDonemReopenException('REOPEN_REQUEST_NOT_PENDING', 'Talep onay bekliyor degil.', 409, [
                'talep_durumu' => (string) $talep['talep_durumu'],
            ]);
        }

        if ((int) $talep['requested_by'] === $actorId) {
            throw new PuantajDonemReopenException(
                'REOPEN_SELF_APPROVAL_FORBIDDEN',
                'Talep sahibi kendi talebini onaylayamaz.',
                403
            );
        }

        $seal = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay, true);
        if ($seal === null || (int) $seal['id'] !== (int) $talep['kaynak_muhur_id']) {
            throw new PuantajDonemReopenException(
                'REOPEN_SOURCE_SEAL_CHANGED',
                'Kaynak muhur artik effective degil; talep stale.',
                409
            );
        }

        $now = self::serverNow($pdo);
        $upd = $pdo->prepare(
            "UPDATE puantaj_donem_reopen_talepleri
             SET talep_durumu = 'ONAYLANDI', approved_by = :ab, approved_at = :at
             WHERE id = :id AND talep_durumu = 'ONAY_BEKLIYOR'"
        );
        $upd->execute(['ab' => $actorId, 'at' => $now, 'id' => (int) $talepId]);
        if ($upd->rowCount() < 1) {
            throw new PuantajDonemReopenException('REOPEN_REQUEST_NOT_PENDING', 'Talep onaylanamadi (yaris).', 409);
        }

        // Canonical duzeltme icin live satirlari ACIK'a cek (muhur_satirlari dokunulmaz).
        self::unlockLivePuantajForPeriod($pdo, (int) $subeId, (int) $yil, (int) $ay, (int) $seal['id']);

        $requestHash = PuantajDonemPeriodService::hashCanonical([
            'aksiyon' => self::AUDIT_APPROVED,
            'talep_id' => (int) $talepId,
            'actor_id' => $actorId,
            'onay_notu' => trim((string) $onayNotu),
        ]);
        self::writeAudit($pdo, [
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'aksiyon' => self::AUDIT_APPROVED,
            'sonuc' => 'OK',
            'reopen_talep_id' => (int) $talepId,
            'source_muhur_id' => (int) $seal['id'],
            'source_revision' => (int) ($seal['revision_no'] ?? 1),
            'target_muhur_id' => null,
            'target_revision' => null,
            'request_hash' => $requestHash,
            'previous_source_hash' => $seal['source_hash'] ?? null,
            'new_source_hash' => null,
            'failure_code' => null,
            'payload_json' => ['onay_notu_len' => strlen(trim((string) $onayNotu))],
            'actor_id' => $actorId,
        ]);

        return self::mapTalep(self::findTalepById($pdo, $talepId) ?: $talep);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function rejectReopenRequest(PDO $pdo, array $user, $subeId, $yil, $ay, $talepId, $rejectionReason)
    {
        $actorId = (int) ($user['id'] ?? 0);
        $reason = trim((string) $rejectionReason);
        if ($reason === '') {
            throw new PuantajDonemReopenException('VALIDATION_ERROR', 'Red gerekcesi zorunludur.', 400, ['field' => 'rejection_reason']);
        }

        PuantajDonemKilidiService::acquire($pdo, (int) $subeId, (int) $yil, (int) $ay);

        $talep = self::findTalepById($pdo, $talepId, true);
        if ($talep === null
            || (int) $talep['sube_id'] !== (int) $subeId
            || (int) $talep['yil'] !== (int) $yil
            || (int) $talep['ay'] !== (int) $ay
        ) {
            throw new PuantajDonemReopenException('REOPEN_REQUEST_NOT_FOUND', 'Reopen talebi bulunamadi.', 404);
        }
        if ((string) $talep['talep_durumu'] !== PuantajDonemPeriodService::TALEP_ONAY_BEKLIYOR) {
            throw new PuantajDonemReopenException('REOPEN_REQUEST_NOT_PENDING', 'Yalniz bekleyen talep reddedilebilir.', 409);
        }
        if ((int) $talep['requested_by'] === $actorId) {
            throw new PuantajDonemReopenException(
                'REOPEN_SELF_APPROVAL_FORBIDDEN',
                'Talep sahibi kendi talebini reddedemez.',
                403
            );
        }

        $now = self::serverNow($pdo);
        $upd = $pdo->prepare(
            "UPDATE puantaj_donem_reopen_talepleri
             SET talep_durumu = 'REDDEDILDI', rejected_by = :rb, rejected_at = :rt, rejection_reason = :rr
             WHERE id = :id AND talep_durumu = 'ONAY_BEKLIYOR'"
        );
        $upd->execute([
            'rb' => $actorId,
            'rt' => $now,
            'rr' => $reason,
            'id' => (int) $talepId,
        ]);

        $requestHash = PuantajDonemPeriodService::hashCanonical([
            'aksiyon' => self::AUDIT_REJECTED,
            'talep_id' => (int) $talepId,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
        self::writeAudit($pdo, [
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'aksiyon' => self::AUDIT_REJECTED,
            'sonuc' => 'OK',
            'reopen_talep_id' => (int) $talepId,
            'source_muhur_id' => (int) $talep['kaynak_muhur_id'],
            'source_revision' => null,
            'target_muhur_id' => null,
            'target_revision' => null,
            'request_hash' => $requestHash,
            'previous_source_hash' => null,
            'new_source_hash' => null,
            'failure_code' => null,
            'payload_json' => ['rejection_reason_len' => strlen($reason)],
            'actor_id' => $actorId,
        ]);

        return self::mapTalep(self::findTalepById($pdo, $talepId) ?: $talep);
    }

    /**
     * @param array<string, mixed> $user
     * @param callable(PDO,int,int,int,int):array{rows:list<array<string,mixed>>,source_hash:string} $sealCopyCallback
     * @return array<string, mixed>
     */
    public static function reseal(PDO $pdo, array $user, $subeId, $yil, $ay, $neden, $expectedPreviousSealId, callable $sealCopyCallback)
    {
        $actorId = (int) ($user['id'] ?? 0);
        $neden = trim((string) $neden);
        if ($neden === '') {
            throw new PuantajDonemReopenException('VALIDATION_ERROR', 'Reseal gerekcesi zorunludur.', 400, ['field' => 'neden']);
        }

        PuantajDonemKilidiService::acquire($pdo, (int) $subeId, (int) $yil, (int) $ay);
        PuantajPhysicalDestructionGate::assertPeriodNotDestroyed($pdo, $subeId, $yil, $ay);

        $talep = PuantajDonemPeriodService::findOpenReopenTalep($pdo, $subeId, $yil, $ay, true);
        if ($talep === null || (string) $talep['talep_durumu'] !== PuantajDonemPeriodService::TALEP_ONAYLANDI) {
            throw new PuantajDonemReopenException(
                'PERIOD_REOPEN_NOT_APPROVED',
                'Onayli reopen oturumu yok; reseal yapilamaz.',
                409
            );
        }

        $activeSnap = PuantajDonemPeriodService::findActivePayrollSnapshot($pdo, $subeId, $yil, $ay, true);
        if ($activeSnap !== null) {
            throw new PuantajDonemReopenException(
                'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED',
                'Aktif maas snapshot iptal edilmeden reseal yapilamaz.',
                409,
                ['snapshot_id' => (int) $activeSnap['id']]
            );
        }

        $oldSeal = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay, true);
        if ($oldSeal === null) {
            throw new PuantajDonemReopenException('PERIOD_NOT_SEALED', 'Effective muhur yok.', 409);
        }
        if ((int) $expectedPreviousSealId > 0 && (int) $oldSeal['id'] !== (int) $expectedPreviousSealId) {
            throw new PuantajDonemReopenException(
                'RESEAL_CONFLICT',
                'Beklenen onceki muhur ile effective muhur uyusmuyor.',
                409,
                ['expected' => (int) $expectedPreviousSealId, 'actual' => (int) $oldSeal['id']]
            );
        }
        if ((int) $oldSeal['id'] !== (int) $talep['kaynak_muhur_id']) {
            throw new PuantajDonemReopenException('REOPEN_SOURCE_SEAL_CHANGED', 'Kaynak muhur degismis.', 409);
        }

        $startHash = PuantajDonemPeriodService::hashCanonical([
            'aksiyon' => self::AUDIT_RESEAL_STARTED,
            'talep_id' => (int) $talep['id'],
            'actor_id' => $actorId,
            'old_seal' => (int) $oldSeal['id'],
        ]);
        self::writeAudit($pdo, [
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'aksiyon' => self::AUDIT_RESEAL_STARTED,
            'sonuc' => 'STARTED',
            'reopen_talep_id' => (int) $talep['id'],
            'source_muhur_id' => (int) $oldSeal['id'],
            'source_revision' => (int) ($oldSeal['revision_no'] ?? 1),
            'target_muhur_id' => null,
            'target_revision' => null,
            'request_hash' => $startHash,
            'previous_source_hash' => $oldSeal['source_hash'] ?? null,
            'new_source_hash' => null,
            'failure_code' => null,
            'payload_json' => ['neden_len' => strlen($neden)],
            'actor_id' => $actorId,
        ]);

        try {
            self::assertCanonicalCalendarComplete($pdo, (int) $subeId, (int) $yil, (int) $ay);

            $revisionNo = PuantajDonemPeriodService::nextRevisionNo($pdo, $subeId, $yil, $ay);
            $oldSealId = (int) $oldSeal['id'];
            $oldSourceHash = $oldSeal['source_hash'] ?? null;

            // Unique aktif_muhur: once eski effective'i kaldir, sonra yeni MUHURLENDI ekle.
            $sup = $pdo->prepare(
                "UPDATE puantaj_aylik_muhurleri
                 SET durum = 'SUPERSEDED'
                 WHERE id = :old_id AND durum = 'MUHURLENDI'"
            );
            $sup->execute(['old_id' => $oldSealId]);
            if ($sup->rowCount() < 1) {
                throw new PuantajDonemReopenException('RESEAL_CONFLICT', 'Eski muhur supersede edilemedi.', 409);
            }

            $copy = $sealCopyCallback(
                $pdo,
                (int) $subeId,
                (int) $yil,
                (int) $ay,
                $revisionNo,
                $oldSealId,
                (int) $talep['id']
            );
            $rows = $copy['rows'];
            $sourceHash = (string) $copy['source_hash'];
            $newMuhurId = (int) $copy['muhur_id'];

            $link = $pdo->prepare(
                'UPDATE puantaj_aylik_muhurleri SET superseded_by_id = :new_id WHERE id = :old_id'
            );
            $link->execute(['new_id' => $newMuhurId, 'old_id' => $oldSealId]);

            $now = self::serverNow($pdo);
            $close = $pdo->prepare(
                "UPDATE puantaj_donem_reopen_talepleri
                 SET talep_durumu = 'UYGULANDI', applied_at = :at, reseal_muhur_id = :mid
                 WHERE id = :id AND talep_durumu = 'ONAYLANDI'"
            );
            $close->execute(['at' => $now, 'mid' => $newMuhurId, 'id' => (int) $talep['id']]);
            if ($close->rowCount() < 1) {
                throw new PuantajDonemReopenException(
                    'RESEAL_CONFLICT',
                    'Reopen talebi UYGULANDI yapilamadi (yaris veya stale state).',
                    409
                );
            }

            $doneHash = PuantajDonemPeriodService::hashCanonical([
                'aksiyon' => self::AUDIT_RESEALED,
                'talep_id' => (int) $talep['id'],
                'new_muhur_id' => $newMuhurId,
                'source_hash' => $sourceHash,
                'actor_id' => $actorId,
            ]);
            self::writeAudit($pdo, [
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'aksiyon' => self::AUDIT_RESEALED,
                'sonuc' => 'OK',
                'reopen_talep_id' => (int) $talep['id'],
                'source_muhur_id' => $oldSealId,
                'source_revision' => (int) ($oldSeal['revision_no'] ?? 1),
                'target_muhur_id' => $newMuhurId,
                'target_revision' => $revisionNo,
                'request_hash' => $doneHash,
                'previous_source_hash' => $oldSourceHash,
                'new_source_hash' => $sourceHash,
                'failure_code' => null,
                'payload_json' => ['muhurlenen_kayit_sayisi' => count($rows)],
                'actor_id' => $actorId,
            ]);

            return [
                'muhur_id' => $newMuhurId,
                'revision_no' => $revisionNo,
                'parent_muhur_id' => $oldSealId,
                'source_hash' => $sourceHash,
                'muhurlenen_kayit_sayisi' => count($rows),
                'reopen_talep_id' => (int) $talep['id'],
                'period_state' => PuantajDonemPeriodService::STATE_SEALED,
            ];
        } catch (PuantajDonemReopenException $e) {
            $failHash = PuantajDonemPeriodService::hashCanonical([
                'aksiyon' => self::AUDIT_RESEAL_FAILED,
                'talep_id' => (int) $talep['id'],
                'code' => $e->getErrorCode(),
                'actor_id' => $actorId,
                't' => microtime(true),
            ]);
            self::writeAudit($pdo, [
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'aksiyon' => self::AUDIT_RESEAL_FAILED,
                'sonuc' => 'FAILED',
                'reopen_talep_id' => (int) $talep['id'],
                'source_muhur_id' => (int) $oldSeal['id'],
                'source_revision' => (int) ($oldSeal['revision_no'] ?? 1),
                'target_muhur_id' => null,
                'target_revision' => null,
                'request_hash' => $failHash,
                'previous_source_hash' => $oldSeal['source_hash'] ?? null,
                'new_source_hash' => null,
                'failure_code' => $e->getErrorCode(),
                'payload_json' => ['message' => $e->getMessage()],
                'actor_id' => $actorId,
            ]);
            throw $e;
        }
    }

    /**
     * Employment-aware canonical completeness for all sube personel intersecting period.
     * @throws PuantajDonemReopenException
     */
    public static function assertCanonicalCalendarComplete(PDO $pdo, $subeId, $yil, $ay)
    {
        $firstDay = sprintf('%04d-%02d-01', $yil, $ay);
        $lastDay = date('Y-m-t', strtotime($firstDay));

        $personelStmt = $pdo->prepare(
            'SELECT id, ise_giris_tarihi, cikis_tarihi
             FROM personeller
             WHERE sube_id = :sube_id
               AND ' . \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::sqlIcPersonelPredicate($pdo, 'personeller')
        );
        $personelStmt->execute(['sube_id' => (int) $subeId]);
        $personeller = $personelStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $puStmt = $pdo->prepare(
            'SELECT personel_id, tarih, gun_tipi
             FROM gunluk_puantaj
             WHERE personel_id = :pid AND tarih BETWEEN :d1 AND :d2'
        );

        foreach ($personeller as $personel) {
            $pid = (int) $personel['id'];
            $start = (string) ($personel['ise_giris_tarihi'] ?? '');
            $end = (string) ($personel['cikis_tarihi'] ?? '');
            if ($start === '') {
                throw new PuantajDonemReopenException(
                    'CANONICAL_CALENDAR_INCOMPLETE',
                    'Personel ise_giris_tarihi olmadan reseal yapilamaz.',
                    409,
                    ['personel_id' => $pid]
                );
            }
            $empStart = $start > $firstDay ? $start : $firstDay;
            $empEnd = ($end !== '' && $end < $lastDay) ? $end : $lastDay;
            if ($empStart > $empEnd) {
                continue;
            }

            $puStmt->execute(['pid' => $pid, 'd1' => $empStart, 'd2' => $empEnd]);
            $rows = $puStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $byDate = [];
            foreach ($rows as $row) {
                $t = (string) $row['tarih'];
                if (isset($byDate[$t])) {
                    throw new PuantajDonemReopenException(
                        'CANONICAL_CALENDAR_DUPLICATE_DATE',
                        'Ayni personel/tarih icin birden fazla puantaj satiri var.',
                        409,
                        ['personel_id' => $pid, 'tarih' => $t]
                    );
                }
                $byDate[$t] = $row;
            }

            $cursor = new \DateTimeImmutable($empStart);
            $endDt = new \DateTimeImmutable($empEnd);
            while ($cursor <= $endDt) {
                $t = $cursor->format('Y-m-d');
                if (!isset($byDate[$t])) {
                    throw new PuantajDonemReopenException(
                        'CANONICAL_CALENDAR_INCOMPLETE',
                        'Istihdam gunu icin puantaj kaydi eksik.',
                        409,
                        ['personel_id' => $pid, 'tarih' => $t]
                    );
                }
                $gunTipi = trim((string) ($byDate[$t]['gun_tipi'] ?? ''));
                if ($gunTipi === '') {
                    throw new PuantajDonemReopenException(
                        'CANONICAL_DAY_TYPE_REQUIRED',
                        'gun_tipi bos olamaz.',
                        409,
                        ['personel_id' => $pid, 'tarih' => $t]
                    );
                }
                $cursor = $cursor->modify('+1 day');
            }
        }
    }

    /** @return array<string, mixed> */
    public static function sealHistoryPayload(PDO $pdo, $subeId, $yil, $ay)
    {
        $seals = PuantajDonemPeriodService::listSealHistory($pdo, $subeId, $yil, $ay);
        $talepler = PuantajDonemPeriodService::listReopenTalepleri($pdo, $subeId, $yil, $ay);
        $effective = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay);
        $snapshots = [];
        if (self::tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            $stmt = $pdo->prepare(
                'SELECT id, muhur_id, revision_no, state, source_hash, snapshot_hash, created_at, iptal_edildi_at
                 FROM maas_hesaplama_donem_snapshotlari
                 WHERE sube_id = :s AND yil = :y AND ay = :a
                 ORDER BY revision_no ASC'
            );
            $stmt->execute(['s' => (int) $subeId, 'y' => (int) $yil, 'a' => (int) $ay]);
            $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return [
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'period_state' => PuantajDonemPeriodService::resolvePeriodState($pdo, $subeId, $yil, $ay),
            'effective_seal_id' => $effective ? (int) $effective['id'] : null,
            'effective_revision_no' => $effective ? (int) ($effective['revision_no'] ?? 1) : null,
            'seals' => array_map(static function (array $s) {
                return [
                    'id' => (int) $s['id'],
                    'revision_no' => (int) ($s['revision_no'] ?? 1),
                    'durum' => (string) $s['durum'],
                    'effective' => (string) $s['durum'] === 'MUHURLENDI',
                    'parent_muhur_id' => isset($s['parent_muhur_id']) && $s['parent_muhur_id'] !== null ? (int) $s['parent_muhur_id'] : null,
                    'superseded_by_id' => isset($s['superseded_by_id']) && $s['superseded_by_id'] !== null ? (int) $s['superseded_by_id'] : null,
                    'source_hash' => $s['source_hash'] ?? null,
                    'muhurlenen_kayit_sayisi' => (int) ($s['muhurlenen_kayit_sayisi'] ?? 0),
                    'created_by' => isset($s['created_by']) && $s['created_by'] !== null ? (int) $s['created_by'] : null,
                    'created_at' => (string) ($s['created_at'] ?? ''),
                    'reopen_talep_id' => isset($s['reopen_talep_id']) && $s['reopen_talep_id'] !== null ? (int) $s['reopen_talep_id'] : null,
                ];
            }, $seals),
            'reopen_talepleri' => array_map([self::class, 'mapTalep'], $talepler),
            'snapshots' => array_map(static function (array $s) {
                return [
                    'id' => (int) $s['id'],
                    'muhur_id' => (int) $s['muhur_id'],
                    'revision_no' => (int) $s['revision_no'],
                    'state' => (string) $s['state'],
                    'source_hash' => (string) ($s['source_hash'] ?? ''),
                    'snapshot_hash' => (string) ($s['snapshot_hash'] ?? ''),
                    'created_at' => (string) ($s['created_at'] ?? ''),
                    'iptal_edildi_at' => $s['iptal_edildi_at'] ?? null,
                ];
            }, $snapshots),
        ];
    }

    private static function unlockLivePuantajForPeriod(PDO $pdo, $subeId, $yil, $ay, $muhurId)
    {
        $firstDay = sprintf('%04d-%02d-01', $yil, $ay);
        $lastDay = date('Y-m-t', strtotime($firstDay));
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                "UPDATE gunluk_puantaj
                 SET state = 'ACIK', muhur_id = NULL
                 WHERE personel_id IN (
                   SELECT id FROM personeller
                   WHERE sube_id = :sube_id
                     AND " . \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::sqlIcPersonelPredicate($pdo, 'personeller') . "
                 )
                   AND tarih BETWEEN :d1 AND :d2
                   AND (muhur_id = :muhur_id OR state = 'MUHURLENDI')"
            );
        } else {
            $stmt = $pdo->prepare(
                "UPDATE gunluk_puantaj gp
                 INNER JOIN personeller p ON p.id = gp.personel_id
                 SET gp.state = 'ACIK', gp.muhur_id = NULL
                 WHERE p.sube_id = :sube_id
                   AND " . \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::sqlIcPersonelPredicate($pdo, 'p') . "
                   AND gp.tarih BETWEEN :d1 AND :d2
                   AND (gp.muhur_id = :muhur_id OR gp.state = 'MUHURLENDI')"
            );
        }
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'd1' => $firstDay,
            'd2' => $lastDay,
            'muhur_id' => (int) $muhurId,
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function findTalepById(PDO $pdo, $id, $forUpdate = false)
    {
        $sql = 'SELECT * FROM puantaj_donem_reopen_talepleri WHERE id = :id LIMIT 1';
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($forUpdate && $driver !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function findTalepByHash(PDO $pdo, $subeId, $yil, $ay, $hash)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM puantaj_donem_reopen_talepleri
             WHERE sube_id = :s AND yil = :y AND ay = :a AND request_hash = :h LIMIT 1'
        );
        $stmt->execute([
            's' => (int) $subeId,
            'y' => (int) $yil,
            'a' => (int) $ay,
            'h' => (string) $hash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function mapTalep(array $row)
    {
        if ($row === []) {
            return [];
        }

        return [
            'id' => (int) $row['id'],
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'kaynak_muhur_id' => (int) $row['kaynak_muhur_id'],
            'talep_durumu' => (string) $row['talep_durumu'],
            'gerekce' => (string) $row['gerekce'],
            'requested_by' => (int) $row['requested_by'],
            'requested_at' => (string) $row['requested_at'],
            'approved_by' => isset($row['approved_by']) && $row['approved_by'] !== null ? (int) $row['approved_by'] : null,
            'approved_at' => $row['approved_at'] ?? null,
            'rejected_by' => isset($row['rejected_by']) && $row['rejected_by'] !== null ? (int) $row['rejected_by'] : null,
            'rejected_at' => $row['rejected_at'] ?? null,
            'rejection_reason' => $row['rejection_reason'] ?? null,
            'applied_at' => $row['applied_at'] ?? null,
            'reseal_muhur_id' => isset($row['reseal_muhur_id']) && $row['reseal_muhur_id'] !== null ? (int) $row['reseal_muhur_id'] : null,
            'request_hash' => (string) ($row['request_hash'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $data */
    private static function writeAudit(PDO $pdo, array $data)
    {
        if (!self::tableExists($pdo, 'puantaj_donem_reopen_auditleri')) {
            return;
        }
        $existing = $pdo->prepare(
            'SELECT id FROM puantaj_donem_reopen_auditleri
             WHERE sube_id = :s AND yil = :y AND ay = :a AND aksiyon = :ax AND request_hash = :h LIMIT 1'
        );
        $existing->execute([
            's' => $data['sube_id'],
            'y' => $data['yil'],
            'a' => $data['ay'],
            'ax' => $data['aksiyon'],
            'h' => $data['request_hash'],
        ]);
        if ($existing->fetch(PDO::FETCH_ASSOC)) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO puantaj_donem_reopen_auditleri (
                sube_id, yil, ay, aksiyon, sonuc, reopen_talep_id,
                source_muhur_id, source_revision, target_muhur_id, target_revision,
                request_hash, previous_source_hash, new_source_hash, failure_code, payload_json, actor_id
             ) VALUES (
                :sube_id, :yil, :ay, :aksiyon, :sonuc, :reopen_talep_id,
                :source_muhur_id, :source_revision, :target_muhur_id, :target_revision,
                :request_hash, :previous_source_hash, :new_source_hash, :failure_code, :payload_json, :actor_id
             )'
        );
        $stmt->execute([
            'sube_id' => $data['sube_id'],
            'yil' => $data['yil'],
            'ay' => $data['ay'],
            'aksiyon' => $data['aksiyon'],
            'sonuc' => $data['sonuc'],
            'reopen_talep_id' => $data['reopen_talep_id'],
            'source_muhur_id' => $data['source_muhur_id'],
            'source_revision' => $data['source_revision'],
            'target_muhur_id' => $data['target_muhur_id'],
            'target_revision' => $data['target_revision'],
            'request_hash' => $data['request_hash'],
            'previous_source_hash' => $data['previous_source_hash'],
            'new_source_hash' => $data['new_source_hash'],
            'failure_code' => $data['failure_code'],
            'payload_json' => $data['payload_json'] !== null
                ? json_encode($data['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'actor_id' => $data['actor_id'],
        ]);
    }

    private static function serverNow(PDO $pdo)
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return gmdate('Y-m-d H:i:s');
        }
        $v = $pdo->query('SELECT NOW()')->fetchColumn();

        return $v ? (string) $v : gmdate('Y-m-d H:i:s');
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t");
            $stmt->execute(['t' => $table]);

            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => $table]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function isDuplicateKey(\PDOException $e)
    {
        $info = $e->errorInfo ?? [];

        return (isset($info[1]) && (int) $info[1] === 1062)
            || strpos($e->getMessage(), '1062') !== false
            || stripos($e->getMessage(), 'UNIQUE constraint failed') !== false;
    }
}
