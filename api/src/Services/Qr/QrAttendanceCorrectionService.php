<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Auth\DualControl;
use Medisa\Api\Services\Attendance\AttendanceCorrectionApproverResolver;
use Medisa\Api\Services\Personel\PersonelOperationalContextService;
use Medisa\Api\Services\PuantajDonemKilidiService;
use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\PuantajDonemReopenException;
use Medisa\Api\Services\SelfService\PersonelInboxNotificationService;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use PDO;
use PDOException;

/**
 * Personel-initiated QR attendance time correction requests.
 * Original qr_attendance_events rows stay immutable.
 */
class QrAttendanceCorrectionService
{
    public const REMINDER_MINUTES = 10;

    public static function assertSchemaReady(PDO $pdo)
    {
        QrAttendanceEventService::assertSchemaReady($pdo);
        $stmt = $pdo->query("SHOW TABLES LIKE 'qr_attendance_correction_requests'");
        if ($stmt === false || $stmt->fetch(PDO::FETCH_NUM) === false) {
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
            throw new QrAttendanceException('QR_CORRECTION_SCHEMA_NOT_READY', 'Duzeltme semasi hazir degil.', 503);
        }
        if ($stmt !== false) {
            $stmt->closeCursor();
        }
    }

    /**
     * @param array<string, mixed> $authUser
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function createRequest(PDO $pdo, array $authUser, array $body)
    {
        self::assertSchemaReady($pdo);
        $ctx = SelfPersonelContext::resolveForSelfService($authUser, $pdo, true);
        $caps = PersonelMobileCapabilityService::resolve($pdo, (int) $ctx['personel_id'], $ctx);
        PersonelMobileCapabilityService::assertBusinessCapability($caps, PersonelMobileCapabilityService::CAP_ATTENDANCE_CORRECT);

        $sourceEventId = isset($body['source_event_id']) ? (int) $body['source_event_id'] : 0;
        $requestedLocal = isset($body['requested_local_time']) ? trim((string) $body['requested_local_time']) : '';
        $explanation = isset($body['explanation']) ? trim((string) $body['explanation']) : '';
        if ($explanation === '') {
            $explanation = null;
        } elseif (mb_strlen($explanation) > 500) {
            throw new QrAttendanceException('VALIDATION_ERROR', 'Aciklama en fazla 500 karakter olabilir.', 400, 'explanation');
        }
        if ($sourceEventId <= 0) {
            throw new QrAttendanceException('VALIDATION_ERROR', 'source_event_id zorunludur.', 400, 'source_event_id');
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $requestedLocal)) {
            throw new QrAttendanceException('VALIDATION_ERROR', 'requested_local_time HH:MM olmalidir.', 400, 'requested_local_time');
        }

        $eventStmt = $pdo->prepare(
            'SELECT * FROM qr_attendance_events WHERE id = :id AND personel_id = :pid LIMIT 1'
        );
        $eventStmt->execute([
            'id' => $sourceEventId,
            'pid' => (int) $ctx['personel_id'],
        ]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($event)) {
            throw new QrAttendanceException('NOT_FOUND', 'QR hareketi bulunamadi.', 404, 'source_event_id');
        }

        $anomalyType = isset($body['anomaly_type']) ? strtoupper(trim((string) $body['anomaly_type'])) : '';
        $anomaly = null;
        if ($anomalyType !== '') {
            if ($anomalyType !== 'MISSING_CIKIS' && $anomalyType !== 'MISSING_GIRIS') {
                throw new QrAttendanceException('VALIDATION_ERROR', 'anomaly_type gecersiz.', 400, 'anomaly_type');
            }
            $anomaly = self::requireUnresolvedAnomaly($pdo, (int) $ctx['personel_id'], $sourceEventId, $anomalyType);
        } else {
            $allowed = \Medisa\Api\Services\Attendance\AttendanceBusinessDayService::isCorrectionAllowedNow(
                $pdo,
                (int) $ctx['personel_id'],
                (string) $event['occurred_at_utc']
            );
            if (!$allowed) {
                throw new QrAttendanceException(
                    'CORRECTION_WINDOW_CLOSED',
                    'Bu Kayıt İçin Düzeltme Talebi Süresi Doldu. Amirinizle Görüşün.',
                    409,
                    'source_event_id'
                );
            }
        }

        $pending = $pdo->prepare(
            "SELECT id FROM qr_attendance_correction_requests
             WHERE source_event_id = :eid AND status = 'BEKLIYOR' LIMIT 1"
        );
        $pending->execute(['eid' => $sourceEventId]);
        if ($pending->fetchColumn()) {
            throw new QrAttendanceException(
                'CORRECTION_PENDING_EXISTS',
                'Bu hareket icin bekleyen duzeltme talebi var.',
                409,
                'source_event_id'
            );
        }

        // Approver zinciri event zamanındaki effective org üzerinden (current değil).
        $historical = PersonelOperationalContextService::resolveAt(
            $pdo,
            (int) $ctx['personel_id'],
            (string) $event['occurred_at_utc']
        );
        $approverCtx = $ctx;
        $approverCtx['sube_id'] = $historical['effective']['sube_id'] ?? 0;
        $approverCtx['bolum_id'] = $historical['effective']['bolum_id'];
        $approverCtx['birim_id'] = $historical['effective']['birim_id'];
        $approverCtx['departman_id'] = $historical['effective']['departman_id'];
        $approver = AttendanceCorrectionApproverResolver::resolve($pdo, $authUser, $approverCtx);
        if ($approver === null) {
            throw new QrAttendanceException(
                'CORRECTION_NO_APPROVER',
                'Uygun bagimsiz yonetici bulunamadi. Talep olusturulamadi.',
                409
            );
        }

        $originalUtc = (string) $event['occurred_at_utc'];
        $storedEventType = (string) $event['event_type'];
        $businessDate = self::istanbulDate($originalUtc);
        $requestedDate = $businessDate;
        if (is_array($anomaly)) {
            $storedEventType = $anomalyType === 'MISSING_GIRIS' ? 'GIRIS' : 'CIKIS';
            $businessDate = (string) $anomaly['business_date'];
            $requestedDate = self::requestedClockDate($anomaly, $storedEventType);
        }
        $requestedUtc = self::localTimeOnBusinessDateToUtc($requestedDate, $requestedLocal);
        $nowUtc = self::utcNow();
        $reminderDue = (new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC')))
            ->modify('+' . self::REMINDER_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s.u');

        try {
            $ins = $pdo->prepare(
                'INSERT INTO qr_attendance_correction_requests
                    (personel_id, requester_user_id, source_event_id, event_type, business_date,
                     original_occurred_at_utc, requested_local_time, requested_occurred_at_utc,
                     explanation, status, assigned_approver_user_id, assigned_approver_role,
                     reminder_due_at_utc)
                 VALUES
                    (:personel_id, :requester_user_id, :source_event_id, :event_type, :business_date,
                     :original_occurred_at_utc, :requested_local_time, :requested_occurred_at_utc,
                     :explanation, \'BEKLIYOR\', :assigned_approver_user_id, :assigned_approver_role,
                     :reminder_due_at_utc)'
            );
            $ins->execute([
                'personel_id' => (int) $ctx['personel_id'],
                'requester_user_id' => (int) $authUser['id'],
                'source_event_id' => $sourceEventId,
                'event_type' => $storedEventType,
                'business_date' => $businessDate,
                'original_occurred_at_utc' => $originalUtc,
                'requested_local_time' => $requestedLocal,
                'requested_occurred_at_utc' => $requestedUtc,
                'explanation' => $explanation,
                'assigned_approver_user_id' => (int) $approver['user_id'],
                'assigned_approver_role' => (string) $approver['rol'],
                'reminder_due_at_utc' => $reminderDue,
            ]);
        } catch (PDOException $e) {
            $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($code === 1062) {
                throw new QrAttendanceException(
                    'CORRECTION_PENDING_EXISTS',
                    'Bu hareket icin bekleyen duzeltme talebi var.',
                    409,
                    'source_event_id'
                );
            }
            throw $e;
        }

        $requestId = (int) $pdo->lastInsertId();
        $originalLocal = self::istanbulHhmm($originalUtc);
        $personelName = (string) $ctx['ad_soyad'];
        if (is_array($anomaly) && $anomalyType === 'MISSING_CIKIS') {
            $body = sprintf(
                '%s, %s tarihinde saat %s girişinin çıkış kaydı yok. Talep edilen çıkış %s.',
                $personelName,
                (string) ($anomaly['business_date_label'] ?? $businessDate),
                (string) ($anomaly['context_local_time'] ?? $originalLocal),
                $requestedLocal
            );
        } elseif (is_array($anomaly) && $anomalyType === 'MISSING_GIRIS') {
            $body = sprintf(
                '%s, %s tarihinde saat %s çıkışının giriş kaydı yok. Talep edilen giriş %s.',
                $personelName,
                (string) ($anomaly['business_date_label'] ?? $businessDate),
                (string) ($anomaly['context_local_time'] ?? $originalLocal),
                $requestedLocal
            );
        } else {
            $eventLabel = ((string) $event['event_type'] === 'CIKIS') ? 'çıkış' : 'giriş';
            $body = sprintf(
                '%s, %s olan %s saatini %s olarak güncellemek istiyor.',
                $personelName,
                $originalLocal,
                $eventLabel,
                $requestedLocal
            );
        }

        PersonelInboxNotificationService::create(
            $pdo,
            (int) $approver['user_id'],
            'ATTENDANCE_CORRECTION_REQUEST',
            'Puantaj Düzeltme Talebi',
            $body,
            (int) $ctx['personel_id'],
            $requestId,
            [
                'source_event_id' => $sourceEventId,
                'event_type' => $storedEventType,
                'anomaly_type' => $anomalyType !== '' ? $anomalyType : null,
                'original_local_time' => $originalLocal,
                'requested_local_time' => $requestedLocal,
                'personel_ad_soyad' => $personelName,
            ],
            true
        );

        return self::publicRequest($pdo, $requestId);
    }

    /**
     * @param array<string, mixed> $authUser
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    /**
     * Self list. Scoped to the bound personel only; caller personel_id is ignored.
     *
     * @param array<string, mixed> $authUser
     * @return array{items:array<int, array<string, mixed>>}
     */
    public static function listForSelf(PDO $pdo, array $authUser)
    {
        self::assertSchemaReady($pdo);
        $ctx = SelfPersonelContext::resolveForSelfService($authUser, $pdo, true);
        $stmt = $pdo->prepare(
            'SELECT id, business_date, event_type, explanation, status, decision_note, requested_local_time
             FROM qr_attendance_correction_requests
             WHERE personel_id = :pid
             ORDER BY business_date DESC, id DESC
             LIMIT 100'
        );
        $stmt->execute(['pid' => (int) $ctx['personel_id']]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eventType = (string) ($row['event_type'] ?? '');
            $items[] = [
                'id' => (int) $row['id'],
                'tarih' => (string) $row['business_date'],
                'talep_turu' => $eventType === 'CIKIS' ? 'CIKIS_DUZELTME' : 'GIRIS_DUZELTME',
                'event_type' => $eventType,
                'aciklama' => $row['explanation'] !== null && trim((string) $row['explanation']) !== ''
                    ? (string) $row['explanation']
                    : null,
                'durum' => (string) $row['status'],
                'sonuc' => $row['decision_note'] !== null && trim((string) $row['decision_note']) !== ''
                    ? (string) $row['decision_note']
                    : null,
                'istenen_saat' => (string) $row['requested_local_time'],
            ];
        }

        return ['items' => $items];
    }

    public static function decide(PDO $pdo, array $authUser, $requestId, array $body)
    {
        self::assertSchemaReady($pdo);
        $requestId = (int) $requestId;
        $actorId = (int) ($authUser['id'] ?? 0);
        $action = isset($body['action']) ? strtoupper(trim((string) $body['action'])) : '';
        if ($action !== 'ONAYLA' && $action !== 'REDDET') {
            throw new QrAttendanceException('VALIDATION_ERROR', 'action ONAYLA veya REDDET olmalidir.', 400, 'action');
        }
        // Client-supplied approver id is ignored; assigned_approver_user_id is authoritative.
        $stmt = $pdo->prepare('SELECT * FROM qr_attendance_correction_requests WHERE id = :id LIMIT 1 FOR UPDATE');
        $pdo->beginTransaction();
        try {
            // Period serialization lock must precede canonical row locks (QR candidate apply parity).
            $lockInfo = self::resolvePeriodLockContext($pdo, $requestId);
            $periodLock = PuantajDonemKilidiService::acquireForDate(
                $pdo,
                (int) $lockInfo['sube_id'],
                (string) $lockInfo['business_date']
            );
            if ($action === 'ONAYLA') {
                try {
                    PuantajDonemPeriodService::assertCanonicalWriteAllowed(
                        $pdo,
                        (int) $periodLock['sube_id'],
                        (int) $periodLock['yil'],
                        (int) $periodLock['ay']
                    );
                } catch (PuantajDonemReopenException $e) {
                    throw new QrAttendanceException(
                        'QR_CORRECTION_PERIOD_LOCKED',
                        'Donem muhurlu veya kilitli oldugu icin duzeltme uygulanamaz.',
                        409
                    );
                }
            }

            $stmt->execute(['id' => $requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->rollBack();
                throw new QrAttendanceException('NOT_FOUND', 'Duzeltme talebi bulunamadi.', 404);
            }
            if ((string) $row['status'] !== 'BEKLIYOR') {
                $pdo->rollBack();
                throw new QrAttendanceException('CORRECTION_NOT_PENDING', 'Talep bekleyen durumda degil.', 409);
            }
            if ((int) $row['assigned_approver_user_id'] !== $actorId) {
                $pdo->rollBack();
                throw new QrAttendanceException('FORBIDDEN', 'Bu talebi karara baglama yetkiniz yok.', 403);
            }
            if (!DualControl::isSeparated($authUser, $row['requester_user_id'] ?? null, $pdo)) {
                $pdo->rollBack();
                throw new QrAttendanceException('SELF_APPROVAL_FORBIDDEN', 'Kendi talebinizi onaylayamazsiniz.', 403);
            }

            $now = self::utcNow();
            if ($action === 'ONAYLA') {
                $gunlukId = self::applyEffectiveTime($pdo, $row);
                if ($gunlukId === null) {
                    $pdo->rollBack();
                    throw new QrAttendanceException(
                        'QR_CORRECTION_NO_PUANTAJ_ROW',
                        'Bu tarih icin gunluk puantaj kaydi bulunamadi; duzeltme uygulanmadi.',
                        409,
                        'source_event_id'
                    );
                }
                $upd = $pdo->prepare(
                    "UPDATE qr_attendance_correction_requests
                     SET status = 'ONAYLANDI',
                         resolved_approver_user_id = :actor,
                         decision_at_utc = :now,
                         effective_applied_at_utc = :now,
                         effective_local_time = :effective_local,
                         gunluk_puantaj_id = :gp_id
                     WHERE id = :id"
                );
                $upd->execute([
                    'actor' => $actorId,
                    'now' => $now,
                    'effective_local' => (string) $row['requested_local_time'],
                    'gp_id' => $gunlukId,
                    'id' => $requestId,
                ]);
                $eventType = (string) $row['event_type'];
                $effectiveLocal = (string) $row['requested_local_time'];
                if ($eventType === 'CIKIS') {
                    $personnelTitle = 'Düzeltme Talebiniz Uygun Görüldü.';
                    $personnelBody = 'Güncellenen Çıkış Saati ' . $effectiveLocal;
                } else {
                    $personnelTitle = 'Düzeltme Talebiniz Uygun Görüldü.';
                    $personnelBody = 'Güncellenen Giriş Saati ' . $effectiveLocal;
                }
                $kind = 'ATTENDANCE_CORRECTION_APPROVED';
            } else {
                $upd = $pdo->prepare(
                    "UPDATE qr_attendance_correction_requests
                     SET status = 'UYGUN_GORULMEDI',
                         resolved_approver_user_id = :actor,
                         decision_at_utc = :now
                     WHERE id = :id"
                );
                $upd->execute([
                    'actor' => $actorId,
                    'now' => $now,
                    'id' => $requestId,
                ]);
                $eventType = (string) $row['event_type'];
                if ($eventType === 'CIKIS') {
                    $personnelTitle = 'Çıkış Saati Düzeltme Talebiniz Uygun Bulunmadı.';
                    $personnelBody = 'Çıkış Saati Düzeltme Talebiniz Uygun Bulunmadı.';
                } else {
                    $personnelTitle = 'Giriş Saati Düzeltme Talebiniz Uygun Bulunmadı.';
                    $personnelBody = 'Giriş Saati Düzeltme Talebiniz Uygun Bulunmadı.';
                }
                $kind = 'ATTENDANCE_CORRECTION_REJECTED';
            }

            PersonelInboxNotificationService::create(
                $pdo,
                (int) $row['requester_user_id'],
                $kind,
                $personnelTitle,
                $personnelBody,
                (int) $row['personel_id'],
                $requestId,
                [
                    'status' => $action === 'ONAYLA' ? 'ONAYLANDI' : 'UYGUN_GORULMEDI',
                    'original_local_time' => self::istanbulHhmm((string) $row['original_occurred_at_utc']),
                    'requested_local_time' => (string) $row['requested_local_time'],
                    'event_type' => (string) $row['event_type'],
                ],
                true
            );

            $pdo->commit();
        } catch (QrAttendanceException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::publicRequest($pdo, $requestId);
    }

    /**
     * Emit exactly one reminder popup when due.
     *
     * @return int number of reminders created
     */
    public static function processDueReminders(PDO $pdo, $limit = 50)
    {
        self::assertSchemaReady($pdo);
        $limit = max(1, min(100, (int) $limit));
        $now = self::utcNow();
        $stmt = $pdo->prepare(
            "SELECT * FROM qr_attendance_correction_requests
             WHERE status = 'BEKLIYOR'
               AND reminder_sent_at_utc IS NULL
               AND reminder_due_at_utc <= :now
             ORDER BY reminder_due_at_utc ASC
             LIMIT {$limit}"
        );
        $stmt->execute(['now' => $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $originalLocal = self::istanbulHhmm((string) $row['original_occurred_at_utc']);
            $personelName = self::loadPersonelName($pdo, (int) $row['personel_id']);
            $eventLabel = ((string) $row['event_type'] === 'CIKIS') ? 'çıkış' : 'giriş';
            $body = sprintf(
                '%s, %s olan %s saatini %s olarak güncellemek istiyor. (Hatırlatma)',
                $personelName,
                $originalLocal,
                $eventLabel,
                (string) $row['requested_local_time']
            );
            PersonelInboxNotificationService::create(
                $pdo,
                (int) $row['assigned_approver_user_id'],
                'ATTENDANCE_CORRECTION_REMINDER',
                'Puantaj Düzeltme Talebi',
                $body,
                (int) $row['personel_id'],
                (int) $row['id'],
                [
                    'reminder' => true,
                    'original_local_time' => $originalLocal,
                    'requested_local_time' => (string) $row['requested_local_time'],
                ],
                true
            );
            $mark = $pdo->prepare(
                'UPDATE qr_attendance_correction_requests
                 SET reminder_sent_at_utc = :now
                 WHERE id = :id AND reminder_sent_at_utc IS NULL'
            );
            $mark->execute(['now' => $now, 'id' => (int) $row['id']]);
            if ($mark->rowCount() > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Source QR event branch + business date for period lock acquisition.
     * Scan-time cross-branch denial guarantees event sube_id is the personel working branch.
     *
     * @return array{sube_id:int,business_date:string}
     */
    private static function resolvePeriodLockContext(PDO $pdo, $requestId)
    {
        $stmt = $pdo->prepare(
            'SELECT e.sube_id, r.business_date
             FROM qr_attendance_correction_requests r
             INNER JOIN qr_attendance_events e ON e.id = r.source_event_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new QrAttendanceException('NOT_FOUND', 'Duzeltme talebi bulunamadi.', 404);
        }

        return [
            'sube_id' => (int) $row['sube_id'],
            'business_date' => (string) $row['business_date'],
        ];
    }

    /** @return int|null gunluk_puantaj id when updated */
    private static function applyEffectiveTime(PDO $pdo, array $row)
    {
        $personelId = (int) $row['personel_id'];
        $date = (string) $row['business_date'];
        $time = (string) $row['requested_local_time'];
        $eventType = (string) $row['event_type'];
        try {
            $find = $pdo->prepare(
                'SELECT id FROM gunluk_puantaj WHERE personel_id = :pid AND tarih = :tarih LIMIT 1'
            );
            $find->execute(['pid' => $personelId, 'tarih' => $date]);
            $id = $find->fetchColumn();
            if ($id === false) {
                return null;
            }
            $col = $eventType === 'CIKIS' ? 'cikis_saati' : 'giris_saati';
            $upd = $pdo->prepare(
                "UPDATE gunluk_puantaj
                 SET {$col} = :t,
                     kontrol_durumu = 'BEKLIYOR',
                     state = 'ACIK',
                     muhur_id = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $upd->execute(['t' => $time, 'id' => (int) $id]);

            return (int) $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public static function publicRequest(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM qr_attendance_correction_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new QrAttendanceException('NOT_FOUND', 'Duzeltme talebi bulunamadi.', 404);
        }

        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'source_event_id' => (int) $row['source_event_id'],
            'event_type' => (string) $row['event_type'],
            'business_date' => (string) $row['business_date'],
            'original_occurred_at' => self::formatClient((string) $row['original_occurred_at_utc']),
            'original_local_time' => self::istanbulHhmm((string) $row['original_occurred_at_utc']),
            'requested_local_time' => (string) $row['requested_local_time'],
            'status' => (string) $row['status'],
            'status_label' => self::statusLabel((string) $row['status']),
            'assigned_approver_user_id' => (int) $row['assigned_approver_user_id'],
            'effective_local_time' => $row['effective_local_time'] !== null
                ? (string) $row['effective_local_time']
                : null,
            'decision_at' => $row['decision_at_utc'] !== null
                ? self::formatClient((string) $row['decision_at_utc'])
                : null,
            'message' => 'Düzeltme Talebiniz Amirinize İletildi.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function requireUnresolvedAnomaly(PDO $pdo, $personelId, $sourceEventId, $anomalyType)
    {
        $items = QrAttendanceUnresolvedAnomalyService::listForPersonel($pdo, (int) $personelId);
        foreach ($items as $item) {
            if ((int) ($item['source_event_id'] ?? 0) === (int) $sourceEventId
                && (string) ($item['anomaly_type'] ?? '') === (string) $anomalyType
            ) {
                if (!empty($item['pending_request_id'])) {
                    throw new QrAttendanceException(
                        'CORRECTION_PENDING_EXISTS',
                        'Bu hareket icin bekleyen duzeltme talebi var.',
                        409,
                        'source_event_id'
                    );
                }

                return $item;
            }
        }

        throw new QrAttendanceException(
            'ANOMALY_NOT_UNRESOLVED',
            'Bu kayit icin acik giris/cikis anomalisi yok.',
            409,
            'source_event_id'
        );
    }

    /**
     * Clock the personel typed is placed on the canonical planned-exit date.
     * The clock itself is never invented.
     *
     * @param array<string,mixed> $anomaly
     */
    private static function requestedClockDate(array $anomaly, $storedEventType)
    {
        if ((string) $storedEventType === 'CIKIS' && !empty($anomaly['planned_exit_local'])) {
            try {
                return (new \DateTimeImmutable((string) $anomaly['planned_exit_local']))
                    ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
                    ->format('Y-m-d');
            } catch (\Throwable $e) {
                // Fall through to the anomaly business date.
            }
        }

        return (string) $anomaly['business_date'];
    }

    private static function statusLabel($status)
    {
        if ($status === 'ONAYLANDI') {
            return 'Onaylandı';
        }
        if ($status === 'UYGUN_GORULMEDI') {
            return 'Uygun Görülmedi';
        }

        return 'Bekliyor';
    }

    private static function loadPersonelName(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT ad, soyad FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'Personel';
        }

        return trim((string) ($row['ad'] ?? '') . ' ' . (string) ($row['soyad'] ?? ''));
    }

    private static function istanbulDate($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('Y-m-d');
    }

    private static function istanbulHhmm($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('H:i');
    }

    private static function localTimeOnBusinessDateToUtc($businessDate, $hhmm)
    {
        $tz = new \DateTimeZone('Europe/Istanbul');
        $local = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $businessDate . ' ' . $hhmm . ':00', $tz);
        if ($local === false) {
            throw new QrAttendanceException('VALIDATION_ERROR', 'Istenen saat gecersiz.', 400, 'requested_local_time');
        }

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function utcNow()
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private static function formatClient($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('c');
    }
}
