<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Database\QrAttendanceSchema;
use Medisa\Api\Services\Organizasyon\SubeReadModel;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use PDO;
use PDOException;

/**
 * Append-only QR raw attendance capture + self history (S3C).
 * Never writes gunluk_puantaj / intervals.
 */
class QrAttendanceEventService
{
    private const MAX_WINDOW_DAYS_INCLUSIVE = 366;

    /**
     * Bounded final-early-exit lookback: current business day + this many
     * previous calendar days. Small and fixed on purpose so the canonical Today
     * read lifecycle can recover a recently settled final early exit without
     * ever scanning the whole history on each app open.
     */
    private const FINAL_EARLY_EXIT_LOOKBACK_DAYS = 3;
    private const NONCE_RE = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    public static function assertSchemaReady(PDO $pdo)
    {
        if (!QrAttendanceSchema::hasTable($pdo)) {
            throw new QrAttendanceException(
                'QR_SCHEMA_NOT_READY',
                'QR kayit semasi hazir degil.',
                503
            );
        }
    }

    /**
     * @param array<string, mixed> $authUser
     * @param array<string, mixed> $body Public HTTP contract only: token, event_type, request_nonce, early_exit_confirmed
     * @param array<string, mixed>|null $internalOptions Test/service-only. NEVER built from HTTP body.
     *        Supported keys: occurred_at_utc (string), skip_late_early (bool)
     * @return array{event:?array<string,mixed>,idempotent:bool,confirmation_required?:bool,early_exit_confirm?:?array,late_early_info?:?array}
     */
    public static function scan(PDO $pdo, array $authUser, array $body, array $internalOptions = null)
    {
        self::assertSchemaReady($pdo);
        QrConfig::assertReady();

        $ctx = SelfPersonelContext::resolveForSelfService($authUser, $pdo, true);
        $personelId = (int) $ctx['personel_id'];
        $caps = \Medisa\Api\Services\SelfService\PersonelMobileCapabilityService::resolve($pdo, $personelId, $ctx);
        try {
            \Medisa\Api\Services\SelfService\PersonelMobileCapabilityService::assertBusinessCapability(
                $caps,
                \Medisa\Api\Services\SelfService\PersonelMobileCapabilityService::CAP_QR_SCAN
            );
        } catch (\Medisa\Api\Services\SelfService\PersonelMobileCapabilityException $e) {
            throw new QrAttendanceException($e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        }
        \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::assertTimeOperationalEligible($pdo, $personelId);
        $personelSubeId = (int) $ctx['sube_id'];
        $userId = (int) ($authUser['id'] ?? 0);
        if ($userId <= 0) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'Kimlik dogrulanamadi.', 401);
        }

        $token = isset($body['token']) ? trim((string) $body['token']) : '';
        $eventType = isset($body['event_type']) ? strtoupper(trim((string) $body['event_type'])) : '';
        $nonce = isset($body['request_nonce']) ? trim((string) $body['request_nonce']) : '';

        if ($eventType !== 'GIRIS' && $eventType !== 'CIKIS') {
            throw new QrAttendanceException(
                'QR_EVENT_TYPE_INVALID',
                'event_type GIRIS veya CIKIS olmalidir.',
                400,
                'event_type'
            );
        }
        if ($nonce === '' || !preg_match(self::NONCE_RE, $nonce)) {
            throw new QrAttendanceException(
                'QR_REQUEST_NONCE_INVALID',
                'request_nonce gecersiz.',
                400,
                'request_nonce'
            );
        }

        $claims = QrTokenService::verify($token);
        if ((int) $claims['sube_id'] !== $personelSubeId) {
            throw new QrAttendanceException(
                'QR_CROSS_BRANCH_DENIED',
                'Bu QR baska bir subeye aittir.',
                403,
                'token'
            );
        }

        $existingNonce = self::findByUserNonce($pdo, $userId, $nonce);
        if ($existingNonce !== null) {
            if (
                (int) $existingNonce['personel_id'] !== $personelId
                || (string) $existingNonce['event_type'] !== $eventType
                || strtolower((string) $existingNonce['qr_jti']) !== strtolower((string) $claims['jti'])
                || (int) $existingNonce['sube_id'] !== (int) $claims['sube_id']
            ) {
                throw new QrAttendanceException(
                    'QR_IDEMPOTENCY_CONFLICT',
                    'Ayni request_nonce farkli istek ile kullanilamaz.',
                    409,
                    'request_nonce'
                );
            }

            return [
                'event' => self::publicEvent($pdo, $existingNonce),
                'idempotent' => true,
                'late_early_info' => null,
            ];
        }

        $existingJti = self::findByUserJtiType($pdo, $userId, (string) $claims['jti'], $eventType);
        if ($existingJti !== null) {
            return [
                'event' => self::publicEvent($pdo, $existingJti),
                'idempotent' => true,
                'late_early_info' => null,
            ];
        }

        self::assertOpenShiftTransition($pdo, $personelId, $eventType);

        $internal = is_array($internalOptions) ? $internalOptions : [];
        $occurredAt = self::utcNowMicro();
        // Internal service/test clock only — never read from HTTP body.
        if (!empty($internal['occurred_at_utc']) && is_string($internal['occurred_at_utc'])) {
            $candidate = trim((string) $internal['occurred_at_utc']);
            if (
                $candidate !== ''
                && \Medisa\Api\Services\Attendance\LateEarlyInfoService::toIstanbulMinutes($candidate) !== null
            ) {
                $occurredAt = $candidate;
            }
        }
        $skipLateEarly = !empty($internal['skip_late_early']);
        $issuedAt = self::unixToUtcMicro((int) $claims['iat']);
        $expiresAt = self::unixToUtcMicro((int) $claims['exp']);

        $businessDateForScan = (new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('Y-m-d');
        $daySequence = self::loadDayEventSequence($pdo, $personelId, $businessDateForScan);

        // Early-exit pre-confirm: server authoritative. No event write until confirmed.
        // Confirmation remains for wrong-tap prevention; mid-day exits are not "final early exit".
        $earlyExitConfirmed = !empty($body['early_exit_confirmed']);
        $earlyExitConfirm = null;
        if (
            $eventType === 'CIKIS'
            && !$earlyExitConfirmed
            && !$skipLateEarly
        ) {
            $confirmPlanned = \Medisa\Api\Services\Attendance\LateEarlyInfoService::loadPlannedDay(
                $pdo,
                $personelId,
                $businessDateForScan
            );
            $earlyExitConfirm = \Medisa\Api\Services\Attendance\LateEarlyInfoService::evaluateEarlyExitConfirmation(
                $occurredAt,
                $confirmPlanned
            );
            if (is_array($earlyExitConfirm)) {
                return [
                    'event' => null,
                    'idempotent' => false,
                    'confirmation_required' => true,
                    'early_exit_confirm' => $earlyExitConfirm,
                    'late_early_info' => null,
                ];
            }
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO qr_attendance_events
                    (personel_id, user_id, sube_id, event_type, occurred_at_utc,
                     qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce)
                 VALUES
                    (:personel_id, :user_id, :sube_id, :event_type, :occurred_at_utc,
                     :qr_version, :qr_jti, :qr_issued_at_utc, :qr_expires_at_utc, :request_nonce)'
            );
            $stmt->execute([
                'personel_id' => $personelId,
                'user_id' => $userId,
                'sube_id' => (int) $claims['sube_id'],
                'event_type' => $eventType,
                'occurred_at_utc' => $occurredAt,
                'qr_version' => (int) $claims['version'],
                'qr_jti' => (string) $claims['jti'],
                'qr_issued_at_utc' => $issuedAt,
                'qr_expires_at_utc' => $expiresAt,
                'request_nonce' => $nonce,
            ]);
        } catch (PDOException $e) {
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($driverCode === 1062) {
                $againNonce = self::findByUserNonce($pdo, $userId, $nonce);
                if ($againNonce !== null) {
                    if (
                        (int) $againNonce['personel_id'] !== $personelId
                        || (string) $againNonce['event_type'] !== $eventType
                        || strtolower((string) $againNonce['qr_jti']) !== strtolower((string) $claims['jti'])
                    ) {
                        throw new QrAttendanceException(
                            'QR_IDEMPOTENCY_CONFLICT',
                            'Ayni request_nonce farkli istek ile kullanilamaz.',
                            409,
                            'request_nonce'
                        );
                    }

                    return [
                        'event' => self::publicEvent($pdo, $againNonce),
                        'idempotent' => true,
                    ];
                }
                $againJti = self::findByUserJtiType($pdo, $userId, (string) $claims['jti'], $eventType);
                if ($againJti !== null) {
                    return [
                        'event' => self::publicEvent($pdo, $againJti),
                        'idempotent' => true,
                    ];
                }
            }
            throw $e;
        }

        $id = (int) $pdo->lastInsertId();
        $row = self::findById($pdo, $id);
        if ($row === null) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'Kayit olusturulamadi.', 500);
        }

        $publicEvent = self::publicEvent($pdo, $row);
        $lateEarly = null;
        if (!$skipLateEarly) {
            $businessDate = (new \DateTimeImmutable((string) $row['occurred_at_utc'], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
                ->format('Y-m-d');
            $planned = \Medisa\Api\Services\Attendance\LateEarlyInfoService::loadPlannedDay(
                $pdo,
                $personelId,
                $businessDate
            );
            $girisCountBefore = 0;
            foreach ($daySequence as $prior) {
                if ((string) $prior['event_type'] === 'GIRIS') {
                    $girisCountBefore++;
                }
            }
            $sequenceFlags = [
                'is_first_giris' => $eventType === 'GIRIS' && $girisCountBefore === 0,
                // Never emit EARLY_EXIT_INFO at write time — mid-day exits are operational;
                // final early-exit status is presentation-owned after planned end / day sequence settles.
                'evaluate_early_exit' => false,
                'is_final_cikis' => false,
            ];
            $lateEarly = \Medisa\Api\Services\Attendance\LateEarlyInfoService::evaluateAfterScan(
                $eventType,
                (string) $row['occurred_at_utc'],
                $planned,
                null,
                null,
                $sequenceFlags
            );
            if (is_array($lateEarly)) {
                try {
                    $notifTitle = isset($lateEarly['notification_title'])
                        ? (string) $lateEarly['notification_title']
                        : ($lateEarly['kind'] === 'LATE_ENTRY_INFO' ? 'Geç Giriş' : 'Erken Çıkış');
                    $notifBody = isset($lateEarly['notification_body'])
                        ? (string) $lateEarly['notification_body']
                        : (string) $lateEarly['message'];
                    \Medisa\Api\Services\SelfService\PersonelInboxNotificationService::create(
                        $pdo,
                        $userId,
                        $lateEarly['kind'],
                        $notifTitle,
                        $notifBody,
                        $personelId,
                        null,
                        [
                            'delta_dakika' => (int) $lateEarly['delta_dakika'],
                            'info_only' => true,
                            'financial_effect' => false,
                            'puantaj_effect' => false,
                        ],
                        true
                    );
                } catch (\Throwable $e) {
                    // Inbox schema may be absent pre-074; info still returned in scan payload.
                }
            }

            if ($eventType === 'GIRIS') {
                self::maybeNotifyAfterHoursReentry(
                    $pdo,
                    $authUser,
                    $ctx,
                    $personelId,
                    $userId,
                    $daySequence,
                    $planned,
                    (string) $row['occurred_at_utc'],
                    (int) $row['id']
                );
            }
        }

        return [
            'event' => $publicEvent,
            'idempotent' => false,
            'confirmation_required' => false,
            'early_exit_confirm' => null,
            'late_early_info' => $lateEarly,
        ];
    }

    /**
     * Canonical open-shift / next-action.
     *
     * A live open GİRİŞ (planned exit + 180 not yet reached, or planned exit
     * unknown) still requires ÇIKIŞ before another GİRİŞ. A previous shift whose
     * planned exit + 180 has passed is a stale missing-exit: it does not block
     * the next shift's GİRİŞ and does not accept a ÇIKIŞ that would pair onto it.
     *
     * @return array{last_event_type:?string,next_action:string,stale_missing_cikis:bool}
     */
    public static function resolveOpenShiftState(PDO $pdo, $personelId, $now = null)
    {
        $stmt = $pdo->prepare(
            'SELECT event_type, occurred_at_utc
             FROM qr_attendance_events
             WHERE personel_id = :pid
             ORDER BY occurred_at_utc DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastType = is_array($last) ? strtoupper((string) ($last['event_type'] ?? '')) : '';
        if ($lastType === 'GIRIS') {
            $blocks = QrAttendanceUnresolvedAnomalyService::openGirisBlocksNextGiris(
                $pdo,
                $personelId,
                (string) ($last['occurred_at_utc'] ?? ''),
                $now
            );

            return [
                'last_event_type' => 'GIRIS',
                'next_action' => $blocks ? 'CIKIS' : 'GIRIS',
                'stale_missing_cikis' => !$blocks,
            ];
        }

        return [
            'last_event_type' => $lastType !== '' ? $lastType : null,
            'next_action' => 'GIRIS',
            'stale_missing_cikis' => false,
        ];
    }

    /**
     * Fail-closed open-shift guard (explicit GIRIS/CIKIS still chosen by client).
     * Live open GIRIS → second GIRIS denied. Same-day GIRIS→CIKIS→GIRIS stays allowed.
     * Stale previous-shift open GIRIS does not block the next GIRIS and does not
     * accept a CIKIS onto that stale entry. No open GIRIS → CIKIS denied.
     */
    private static function assertOpenShiftTransition(PDO $pdo, $personelId, $eventType)
    {
        $state = self::resolveOpenShiftState($pdo, $personelId);
        $next = (string) ($state['next_action'] ?? '');

        if ($eventType === 'GIRIS' && $next !== 'GIRIS') {
            throw new QrAttendanceException(
                'QR_OPEN_SHIFT_EXISTS',
                'Acik giris kaydi varken yeni giris yapilamaz. Once cikis veya duzeltme gerekir.',
                409,
                'event_type'
            );
        }
        if ($eventType === 'CIKIS' && !empty($state['stale_missing_cikis'])) {
            throw new QrAttendanceException(
                'QR_STALE_OPEN_SHIFT',
                'Onceki vardiyanin cikis kaydi eksik. Duzeltme talebi olusturun. Yeni vardiya icin giris yapabilirsiniz.',
                409,
                'event_type'
            );
        }
        if ($eventType === 'CIKIS' && $next !== 'CIKIS') {
            throw new QrAttendanceException(
                'QR_NO_OPEN_SHIFT',
                'Acik giris olmadan cikis kaydedilemez.',
                409,
                'event_type'
            );
        }
    }

    /**
     * Business calendar YMD (Europe/Istanbul) → UTC half-open bounds for occurred_at_utc.
     * from inclusive local midnight; to exclusive = (to + 1 day) local midnight.
     * Does not hardcode a fixed UTC offset; uses DateTimeZone Europe/Istanbul.
     *
     * @return array{from:string,to:string,from_utc:string,to_exclusive_utc:string,days:int}
     */
    public static function businessDateRangeToUtc($from, $to)
    {
        $from = self::assertDateYmd($from, 'from');
        $to = self::assertDateYmd($to, 'to');
        if ($from > $to) {
            throw new QrAttendanceException('VALIDATION_ERROR', 'from tarihi to tarihinden sonra olamaz.', 400, 'from');
        }

        $fromDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        $days = (int) $fromDt->diff($toDt)->days + 1;
        if ($days > self::MAX_WINDOW_DAYS_INCLUSIVE) {
            throw new QrAttendanceException(
                'VALIDATION_ERROR',
                'Tarih penceresi en fazla 366 gun (dahil) olabilir.',
                400,
                'to'
            );
        }

        $businessTz = new \DateTimeZone('Europe/Istanbul');
        $utcTz = new \DateTimeZone('UTC');
        $fromLocal = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $businessTz);
        $toExclusiveLocal = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $businessTz)->modify('+1 day');
        if (!$fromLocal || !$toExclusiveLocal) {
            throw new QrAttendanceException('VALIDATION_ERROR', 'Tarih araligi cozumlenemedi.', 400, 'from');
        }

        return [
            'from' => $from,
            'to' => $to,
            'from_utc' => $fromLocal->setTimezone($utcTz)->format('Y-m-d H:i:s.000000'),
            'to_exclusive_utc' => $toExclusiveLocal->setTimezone($utcTz)->format('Y-m-d H:i:s.000000'),
            'days' => $days,
        ];
    }

    /**
     * @return array{from:string,to:string,items:array<int,array<string,mixed>>}
     */
    public static function listForSelf(PDO $pdo, $personelId, $from, $to)
    {
        self::assertSchemaReady($pdo);
        $personelId = (int) $personelId;
        $range = self::businessDateRangeToUtc($from, $to);

        $stmt = $pdo->prepare(
            'SELECT e.id, e.event_type, e.occurred_at_utc, e.sube_id, e.created_at, s.ad AS sube_ad
             FROM qr_attendance_events e
             LEFT JOIN subeler s ON s.id = e.sube_id
             WHERE e.personel_id = :personel_id
               AND e.occurred_at_utc >= :from_utc
               AND e.occurred_at_utc < :to_utc
             ORDER BY e.occurred_at_utc DESC, e.id DESC'
        );
        $stmt->execute([
            'personel_id' => $personelId,
            'from_utc' => $range['from_utc'],
            'to_utc' => $range['to_exclusive_utc'],
        ]);
        $items = [];
        $byDay = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $occurredUtc = (string) $row['occurred_at_utc'];
            $localDate = (new \DateTimeImmutable($occurredUtc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
                ->format('Y-m-d');
            $public = [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'occurred_at' => self::formatUtcForClient($occurredUtc),
                'sube' => [
                    'id' => (int) $row['sube_id'],
                    'ad' => (string) ($row['sube_ad'] ?? ''),
                ],
            ];
            $items[] = $public;
            if (!isset($byDay[$localDate])) {
                $byDay[$localDate] = [
                    'date' => $localDate,
                    'raw' => [],
                ];
            }
            $byDay[$localDate]['raw'][] = [
                'id' => $public['id'],
                'event_type' => $public['event_type'],
                'occurred_at_utc' => $occurredUtc,
            ];
        }

        $days = [];
        ksort($byDay);
        $todayYmd = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        foreach ($byDay as $day) {
            // Chronological timeline (query order is DESC).
            $rawChrono = $day['raw'];
            usort($rawChrono, function ($a, $b) {
                $cmp = strcmp((string) $a['occurred_at_utc'], (string) $b['occurred_at_utc']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ((int) $a['id']) <=> ((int) $b['id']);
            });

            $firstGirisId = null;
            $lastEvent = count($rawChrono) > 0 ? $rawChrono[count($rawChrono) - 1] : null;
            $lastIsFinalCikis = is_array($lastEvent) && (string) $lastEvent['event_type'] === 'CIKIS';
            $isToday = (string) $day['date'] === $todayYmd;

            $events = [];
            $statusLines = [];
            $girisPresented = null;
            $cikisPresented = null;
            $rawGiris = null;
            $rawCikis = null;

            foreach ($rawChrono as $raw) {
                if ((string) $raw['event_type'] === 'GIRIS' && $firstGirisId === null) {
                    $firstGirisId = (int) $raw['id'];
                }
            }

            foreach ($rawChrono as $raw) {
                $type = (string) $raw['event_type'];
                $seq = [
                    'is_first_giris' => $type === 'GIRIS' && $firstGirisId !== null && (int) $raw['id'] === $firstGirisId,
                    'is_final_cikis' => $type === 'CIKIS'
                        && $lastIsFinalCikis
                        && (int) $raw['id'] === (int) $lastEvent['id'],
                    'suppress_early_until_planned_end_passed' => $isToday,
                ];
                $presented = QrAttendancePresentationService::presentDayEvent(
                    $pdo,
                    $personelId,
                    (int) $raw['id'],
                    $type,
                    (string) $raw['occurred_at_utc'],
                    (string) $day['date'],
                    $seq
                );
                $events[] = $presented;
                if ($type === 'GIRIS') {
                    $rawGiris = $raw;
                    $girisPresented = $presented;
                } elseif ($type === 'CIKIS') {
                    $rawCikis = $raw;
                    $cikisPresented = $presented;
                }
                if (is_array($presented['status'] ?? null)) {
                    $statusLines[] = (string) $presented['status']['label'];
                }
            }

            if ($rawGiris === null) {
                $statusLines[] = 'Giriş Kaydı Bulunamadı.';
            }
            if ($rawCikis === null) {
                $statusLines[] = 'Çıkış Kaydı Bulunamadı.';
            }

            $days[] = [
                'date' => $day['date'],
                'has_events' => count($events) > 0,
                'events' => $events,
                'giris' => $girisPresented,
                'cikis' => $cikisPresented,
                'status_lines' => array_values(array_unique($statusLines)),
            ];
        }

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'items' => $items,
            'days' => $days,
        ];
    }

    /** @return array{from:string,to:string} */
    public static function defaultMonthRange()
    {
        try {
            $tz = new \DateTimeZone('Europe/Istanbul');
            $now = new \DateTimeImmutable('now', $tz);
        } catch (\Throwable $e) {
            $now = new \DateTimeImmutable('now');
        }

        return [
            'from' => $now->modify('first day of this month')->format('Y-m-d'),
            'to' => $now->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /**
     * @return list<array{id:int,event_type:string,occurred_at_utc:string}>
     */
    private static function loadDayEventSequence(PDO $pdo, $personelId, $businessDateYmd)
    {
        $range = self::businessDateRangeToUtc($businessDateYmd, $businessDateYmd);
        $stmt = $pdo->prepare(
            'SELECT id, event_type, occurred_at_utc
             FROM qr_attendance_events
             WHERE personel_id = :pid
               AND occurred_at_utc >= :from_utc
               AND occurred_at_utc < :to_utc
             ORDER BY occurred_at_utc ASC, id ASC'
        );
        $stmt->execute([
            'pid' => (int) $personelId,
            'from_utc' => $range['from_utc'],
            'to_utc' => $range['to_exclusive_utc'],
        ]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'occurred_at_utc' => (string) $row['occurred_at_utc'],
            ];
        }

        return $out;
    }

    /**
     * After-hours RE-GIRIS awareness for managers. No payroll / OT / SGK effect.
     * Requires a completed GIRIS→CIKIS cycle earlier the same business day.
     *
     * @param array<string, mixed> $authUser
     * @param array<string, mixed> $ctx
     * @param list<array{id:int,event_type:string,occurred_at_utc:string}> $daySequenceBefore
     * @param array<string, mixed>|null $planned
     */
    private static function maybeNotifyAfterHoursReentry(
        PDO $pdo,
        array $authUser,
        array $ctx,
        $personelId,
        $actorUserId,
        array $daySequenceBefore,
        array $planned = null,
        $occurredAtUtc,
        $sourceEventId
    ) {
        $beklenenCikis = \Medisa\Api\Services\Attendance\LateEarlyInfoService::hhmmToMinutes(
            is_array($planned) ? ($planned['beklenen_cikis_saati'] ?? null) : null
        );
        if ($beklenenCikis === null) {
            return;
        }
        $occurredLocal = \Medisa\Api\Services\Attendance\LateEarlyInfoService::toIstanbulMinutes($occurredAtUtc);
        if ($occurredLocal === null || $occurredLocal <= $beklenenCikis) {
            return;
        }

        $completedCycle = false;
        $open = false;
        foreach ($daySequenceBefore as $ev) {
            $type = (string) $ev['event_type'];
            if ($type === 'GIRIS') {
                $open = true;
            } elseif ($type === 'CIKIS' && $open) {
                $completedCycle = true;
                $open = false;
            }
        }
        if (!$completedCycle) {
            return;
        }

        // Idempotency: same source event must not fan-out twice (QR retry/replay).
        try {
            $dup = $pdo->prepare(
                "SELECT id FROM personel_inbox_notifications
                 WHERE kind = 'AFTER_HOURS_REENTRY_INFO'
                   AND personel_id = :pid
                   AND payload_json LIKE :eid
                 LIMIT 1"
            );
            $dup->execute([
                'pid' => (int) $personelId,
                'eid' => '%"source_event_id":' . (int) $sourceEventId . '%',
            ]);
            if ($dup->fetchColumn()) {
                return;
            }
        } catch (\Throwable $e) {
            // Schema may be absent — skip quietly.
            return;
        }

        $personelName = trim((string) ($ctx['ad_soyad'] ?? ''));
        if ($personelName === '') {
            $personelName = 'Personel';
        }
        $title = 'Mesai Sonrası Tekrar Giriş';
        $body = $personelName . '; Mesai Bitiminden Sonra Tekrar İşyerine Giriş Yapmıştır.';
        $recipients = self::resolveOperationalManagerRecipients($pdo, $ctx, (int) $actorUserId);
        foreach ($recipients as $recipientId) {
            try {
                \Medisa\Api\Services\SelfService\PersonelInboxNotificationService::create(
                    $pdo,
                    $recipientId,
                    'AFTER_HOURS_REENTRY_INFO',
                    $title,
                    $body,
                    (int) $personelId,
                    null,
                    [
                        'source_event_id' => (int) $sourceEventId,
                        'info_only' => true,
                        'financial_effect' => false,
                        'puantaj_effect' => false,
                        'payroll_effect' => false,
                    ],
                    true
                );
            } catch (\Throwable $e) {
                // Best-effort operational awareness.
            }
        }
    }

    /**
     * Canonical AFTER_HOURS_REENTRY manager audience (OrgScope-derived).
     *
     * Direct bagli_amir plus every management level that can actually manage this
     * personel through the canonical org/authorization scope:
     *   BIRIM_AMIRI      (birim scope, user_birimler)  — OrgScope::BIRIM_ASSIGNMENT_ROLES
     *   BOLUM_YONETICISI (bolum scope, user_bolumler)  — OrgScope::BOLUM_ASSIGNMENT_ROLES
     *   SUBE_YONETICISI  (sube scope,  user_subeler)   — OrgScope::SUBE_ASSIGNMENT_ROLES (management subset)
     *   IK_SORUMLUSU / IK_PERSONELI (their real assigned branch/company scope only)
     *   GENEL_YONETICI   (unrestricted global)         — OrgScope::GLOBAL_ROLES (management subset)
     *
     * İK roles are NOT an organisation-wide audience by role name. The
     * organisation-wide İK reach (OrgScope::ORGANIZATION_GLOBAL_READ_ROLES) is
     * *read visibility*, not operational management, so an İK user is a
     * recipient only inside the branch/company scope actually assigned in the
     * canonical user↔org tables (user_subeler ∪ user_sirketler→subeler). A role
     * name alone never fans a notification out to every company/branch.
     *
     * Read-only accountant (MUHASEBE), IT (SISTEM_YONETICISI) and the smoke role
     * (AUTH_SMOKE_READONLY) are deliberately excluded: none of them manage
     * personnel in the canonical scope. Unrelated branches never receive the
     * notification because every assignment axis matches this personel's own org
     * ids. Deduped; excludes the scanning personel user.
     *
     * @param array<string, mixed> $ctx
     * @return list<int>
     */
    public static function resolveOperationalManagerRecipients(PDO $pdo, array $ctx, $excludeUserId)
    {
        $ids = [];
        $excludeUserId = (int) $excludeUserId;
        $personelId = isset($ctx['personel_id']) ? (int) $ctx['personel_id'] : 0;
        $subeId = isset($ctx['sube_id']) ? (int) $ctx['sube_id'] : 0;
        $birimId = isset($ctx['birim_id']) && $ctx['birim_id'] !== null ? (int) $ctx['birim_id'] : 0;
        $bolumId = isset($ctx['bolum_id']) && $ctx['bolum_id'] !== null ? (int) $ctx['bolum_id'] : 0;

        // Direct supervisor (canonical bagli_amir_id → users.id, never a personel id).
        if ($personelId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT bagli_amir_id FROM personeller WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $personelId]);
                $amir = $stmt->fetchColumn();
                if ($amir !== false && $amir !== null && (int) $amir > 0) {
                    $ids[(int) $amir] = (int) $amir;
                }
            } catch (\Throwable $e) {
                // Column may be absent in older fixtures.
            }
        }

        if ($birimId > 0) {
            self::collectAssignedRoleIds($pdo, $ids, 'BIRIM_AMIRI', 'user_birimler', 'birim_id', $birimId);
        }
        if ($bolumId > 0) {
            self::collectAssignedRoleIds($pdo, $ids, 'BOLUM_YONETICISI', 'user_bolumler', 'bolum_id', $bolumId);
        }
        if ($subeId > 0) {
            self::collectAssignedRoleIds($pdo, $ids, 'SUBE_YONETICISI', 'user_subeler', 'sube_id', $subeId);
            // İK is matched on its real assignment, never on the role name: no
            // assignment (or an assignment to another branch/company) means no
            // notification for this personel.
            self::collectScopedIkRoleIds($pdo, $ids, ['IK_SORUMLUSU', 'IK_PERSONELI'], $subeId);
        }
        self::collectGlobalRoleIds($pdo, $ids, ['GENEL_YONETICI']);

        unset($ids[$excludeUserId]);

        return array_values($ids);
    }

    /**
     * Collect AKTIF users of one role assigned to one org id via an assignment table.
     *
     * @param array<int, int> $ids
     */
    private static function collectAssignedRoleIds(PDO $pdo, array &$ids, $role, $table, $column, $orgId)
    {
        $allowed = [
            'user_birimler' => 'birim_id',
            'user_bolumler' => 'bolum_id',
            'user_subeler' => 'sube_id',
        ];
        if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT u.id
                 FROM users u
                 INNER JOIN {$table} a ON a.user_id = u.id
                 WHERE u.rol = :rol
                   AND u.durum = 'AKTIF'
                   AND a.{$column} = :org_id"
            );
            $stmt->execute(['rol' => (string) $role, 'org_id' => (int) $orgId]);
            while ($id = $stmt->fetchColumn()) {
                $ids[(int) $id] = (int) $id;
            }
        } catch (\Throwable $e) {
            // Org assignment schema may be absent.
        }
    }

    /**
     * Collect AKTIF İK users whose canonical branch scope actually covers $subeId.
     *
     * Effective branch scope is the same union AuthMiddleware/OrgScope use:
     * explicit user_subeler grants ∪ the live branches of every company granted
     * in user_sirketler. Matching the branch (rather than the role) is what keeps
     * an unassigned İK user, and an İK user assigned to an unrelated
     * branch/company, out of this personel's audience.
     *
     * Both axes are read defensively: on a database that predates the scope
     * schema the query fails closed (no recipients) instead of aborting.
     *
     * @param array<int, int> $ids
     * @param list<string> $roles
     */
    private static function collectScopedIkRoleIds(PDO $pdo, array &$ids, array $roles, $subeId)
    {
        $subeId = (int) $subeId;
        $roles = array_values(array_filter($roles, 'is_string'));
        if ($subeId <= 0 || count($roles) === 0) {
            return;
        }

        $placeholders = [];
        $params = ['org_sube_id' => $subeId];
        foreach ($roles as $index => $role) {
            $key = 'ik_rol' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (string) $role;
        }
        $roleIn = implode(', ', $placeholders);

        // Explicit branch grant (user_subeler).
        try {
            $stmt = $pdo->prepare(
                "SELECT u.id
                 FROM users u
                 INNER JOIN user_subeler a ON a.user_id = u.id
                 WHERE u.rol IN ({$roleIn})
                   AND u.durum = 'AKTIF'
                   AND a.sube_id = :org_sube_id"
            );
            $stmt->execute($params);
            while ($id = $stmt->fetchColumn()) {
                $ids[(int) $id] = (int) $id;
            }
        } catch (\Throwable $e) {
            // Branch assignment schema may be absent.
        }

        // Company grant → its live branches (canonical company axis; same join as
        // UserOrgAssignmentSchema::resolveSubeIdsForSirketIds).
        try {
            $stmt = $pdo->prepare(
                "SELECT u.id
                 FROM users u
                 INNER JOIN user_sirketler us ON us.user_id = u.id
                 INNER JOIN subeler s ON s.sirket_id = us.sirket_id
                 WHERE u.rol IN ({$roleIn})
                   AND u.durum = 'AKTIF'
                   AND s.id = :org_sube_id"
            );
            $stmt->execute($params);
            while ($id = $stmt->fetchColumn()) {
                $ids[(int) $id] = (int) $id;
            }
        } catch (\Throwable $e) {
            // Company scope schema may be absent.
        }
    }

    /**
     * Collect AKTIF users holding any of the given org-wide management roles.
     *
     * @param array<int, int> $ids
     * @param list<string> $roles
     */
    private static function collectGlobalRoleIds(PDO $pdo, array &$ids, array $roles)
    {
        $roles = array_values(array_filter($roles, 'is_string'));
        if (count($roles) === 0) {
            return;
        }
        $placeholders = [];
        $params = [];
        foreach ($roles as $index => $role) {
            $key = 'rol' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (string) $role;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM users WHERE rol IN (' . implode(', ', $placeholders) . ")
                   AND durum = 'AKTIF'"
            );
            $stmt->execute($params);
            while ($id = $stmt->fetchColumn()) {
                $ids[(int) $id] = (int) $id;
            }
        } catch (\Throwable $e) {
            // users schema may be absent.
        }
    }

    /**
     * Pure final-early-exit decision for a settled business day (no DB).
     *
     * Returns the canonical EARLY_EXIT_INFO payload only when the day's final
     * movement is a CIKIS (i.e. no re-GIRIS after it), the planned exit time has
     * passed, and that final CIKIS is more than 30 minutes early. Otherwise null:
     *   0–30 dk early               → null (no notification);
     *   mid-day exit later closed by re-GIRIS → null (not the final movement);
     *   planned exit not yet reached → null (day not settled);
     *   open shift (last event GIRIS) → null.
     *
     * @param list<array{id:int,event_type:string,occurred_at_utc:string}> $daySequence
     * @param array<string, mixed>|null $planned
     * @param int|null $nowLocalMinutes Istanbul minutes-from-midnight for "now" (null = do not gate on clock)
     * @return array{kind:string,message:string,delta_dakika:int,card_label:?string,notification_title:string,notification_body:string}|null
     */
    public static function finalEarlyExitInfo(array $daySequence, array $planned = null, $nowLocalMinutes = null)
    {
        $last = count($daySequence) > 0 ? $daySequence[count($daySequence) - 1] : null;
        if (!is_array($last) || (string) ($last['event_type'] ?? '') !== 'CIKIS') {
            return null;
        }
        $beklenen = \Medisa\Api\Services\Attendance\LateEarlyInfoService::hhmmToMinutes(
            is_array($planned) ? ($planned['beklenen_cikis_saati'] ?? null) : null
        );
        if ($beklenen === null) {
            return null;
        }
        if ($nowLocalMinutes !== null && $nowLocalMinutes < $beklenen) {
            return null;
        }

        return \Medisa\Api\Services\Attendance\LateEarlyInfoService::evaluateAfterScan(
            'CIKIS',
            (string) $last['occurred_at_utc'],
            $planned,
            null,
            null,
            ['is_final_cikis' => true, 'evaluate_early_exit' => true]
        );
    }

    /**
     * Idempotently materialise the PERSONEL "Erken Çıkış" inbox notification for
     * the final state of a business day. Runs inside the canonical Today lifecycle
     * (no scheduler). No-op unless the day has settled to a final early CIKIS.
     *
     * @return bool true when a new notification row was created
     */
    public static function ensureFinalEarlyExitNotification(PDO $pdo, $personelId, $userId, $businessDateYmd, $nowIsoUtc = null)
    {
        $personelId = (int) $personelId;
        $userId = (int) $userId;
        if ($personelId <= 0 || $userId <= 0) {
            return false;
        }

        $businessDateYmd = trim((string) $businessDateYmd);
        if ($businessDateYmd === '') {
            return false;
        }

        if ($nowIsoUtc !== null && trim((string) $nowIsoUtc) !== '') {
            $nowLocal = \Medisa\Api\Services\Attendance\LateEarlyInfoService::toIstanbulMinutes((string) $nowIsoUtc);
        } else {
            $nowLocal = \Medisa\Api\Services\Attendance\LateEarlyInfoService::toIstanbulMinutes(
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')
            );
        }

        // The "planned end has passed" clock gate only means something for the
        // current business day. A previous business day has already settled, so a
        // lookback pass run just after midnight (Istanbul minutes still before
        // the planned end) must still materialise its final early exit.
        $istanbul = new \DateTimeZone('Europe/Istanbul');
        if ($businessDateYmd !== (new \DateTimeImmutable('now', $istanbul))->format('Y-m-d')) {
            $nowLocal = null;
        }

        $daySequence = self::loadDayEventSequence($pdo, $personelId, $businessDateYmd);
        $planned = \Medisa\Api\Services\Attendance\LateEarlyInfoService::loadPlannedDay($pdo, $personelId, $businessDateYmd);
        $info = self::finalEarlyExitInfo($daySequence, $planned, $nowLocal);
        if (!is_array($info) || ($info['kind'] ?? '') !== 'EARLY_EXIT_INFO') {
            return false;
        }

        $last = $daySequence[count($daySequence) - 1];
        $sourceEventId = (int) $last['id'];

        // Idempotency: exactly one EARLY_EXIT_INFO per final CIKIS source event.
        try {
            $dup = $pdo->prepare(
                "SELECT id FROM personel_inbox_notifications
                 WHERE kind = 'EARLY_EXIT_INFO'
                   AND personel_id = :pid
                   AND payload_json LIKE :eid
                 LIMIT 1"
            );
            $dup->execute([
                'pid' => $personelId,
                'eid' => '%"source_event_id":' . $sourceEventId . '%',
            ]);
            if ($dup->fetchColumn()) {
                return false;
            }
        } catch (\Throwable $e) {
            // Schema may be absent — skip quietly.
            return false;
        }

        try {
            \Medisa\Api\Services\SelfService\PersonelInboxNotificationService::create(
                $pdo,
                $userId,
                'EARLY_EXIT_INFO',
                (string) $info['notification_title'],
                (string) $info['notification_body'],
                $personelId,
                null,
                [
                    'source_event_id' => $sourceEventId,
                    'delta_dakika' => (int) $info['delta_dakika'],
                    'info_only' => true,
                    'financial_effect' => false,
                    'puantaj_effect' => false,
                    'payroll_effect' => false,
                ],
                true
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Idempotently materialise final early-exit notifications for the current
     * business day and a small bounded set of previous calendar days.
     *
     * A final early exit can settle after the personel's last app interaction
     * (e.g. 25 Sep 16:30 exit, app first opened 26 Sep), so evaluating only the
     * current day would let it disappear forever. This bounded lookback recovers
     * it on the next open without a scheduler.
     *
     * Guarantees:
     *   - bounded: never scans the whole history (current + FINAL_EARLY_EXIT_LOOKBACK_DAYS days);
     *   - idempotent: dedupe stays keyed on source_event_id, so re-opening the app never duplicates;
     *   - correct: only the day's LAST movement is considered, so a mid-day exit
     *     closed by a later re-GIRIS is never treated as an early exit.
     *
     * @return bool true when at least one new notification row was created
     */
    public static function ensureRecentFinalEarlyExitNotifications(PDO $pdo, $personelId, $userId, $todayYmd = null)
    {
        $personelId = (int) $personelId;
        $userId = (int) $userId;
        if ($personelId <= 0 || $userId <= 0) {
            return false;
        }

        $tz = new \DateTimeZone('Europe/Istanbul');
        $today = null;
        if ($todayYmd !== null && trim((string) $todayYmd) !== '') {
            $today = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $todayYmd), $tz);
        }
        if (!$today instanceof \DateTimeImmutable) {
            $today = \DateTimeImmutable::createFromFormat('!Y-m-d', (new \DateTimeImmutable('now', $tz))->format('Y-m-d'), $tz);
        }

        $created = false;
        for ($offset = 0; $offset <= self::FINAL_EARLY_EXIT_LOOKBACK_DAYS; $offset++) {
            $businessDateYmd = $today->modify('-' . $offset . ' day')->format('Y-m-d');
            if (self::ensureFinalEarlyExitNotification($pdo, $personelId, $userId, $businessDateYmd)) {
                $created = true;
            }
        }

        return $created;
    }

    /** @return array<string, mixed>|null */
    private static function findByUserNonce(PDO $pdo, $userId, $nonce)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM qr_attendance_events WHERE user_id = :user_id AND request_nonce = :nonce LIMIT 1'
        );
        $stmt->execute(['user_id' => (int) $userId, 'nonce' => (string) $nonce]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function findByUserJtiType(PDO $pdo, $userId, $jti, $eventType)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM qr_attendance_events
             WHERE user_id = :user_id AND qr_jti = :jti AND event_type = :event_type
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => (int) $userId,
            'jti' => strtolower((string) $jti),
            'event_type' => (string) $eventType,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function findById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM qr_attendance_events WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publicEvent(PDO $pdo, array $row)
    {
        $subeId = (int) ($row['sube_id'] ?? 0);
        $mapped = $subeId > 0 ? SubeReadModel::findById($pdo, $subeId) : null;

        return [
            'id' => (int) $row['id'],
            'event_type' => (string) $row['event_type'],
            'occurred_at' => self::formatUtcForClient((string) $row['occurred_at_utc']),
            'sube' => [
                'id' => $subeId,
                'ad' => $mapped !== null ? (string) $mapped['tam_ad'] : '',
            ],
        ];
    }

    private static function utcNowMicro()
    {
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        if (!$dt) {
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } else {
            $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
        }

        return $dt->format('Y-m-d H:i:s.u');
    }

    private static function unixToUtcMicro($unix)
    {
        $dt = (new \DateTimeImmutable('@' . (int) $unix))->setTimezone(new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s.000000');
    }

    private static function formatUtcForClient($dbValue)
    {
        $raw = trim((string) $dbValue);
        if ($raw === '') {
            return $raw;
        }
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            $local = $dt->setTimezone(new \DateTimeZone('Europe/Istanbul'));

            return $local->format('c');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    private static function assertDateYmd($value, $field)
    {
        $value = is_string($value) ? trim($value) : '';
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new QrAttendanceException(
                'VALIDATION_ERROR',
                $field . ' YYYY-MM-DD formatinda olmalidir.',
                400,
                $field
            );
        }

        return $value;
    }
}
