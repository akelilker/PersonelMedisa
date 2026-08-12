<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Database\QrAttendanceSchema;
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
     * @param array<string, mixed> $body
     * @return array{event:array<string,mixed>,idempotent:bool}
     */
    public static function scan(PDO $pdo, array $authUser, array $body)
    {
        self::assertSchemaReady($pdo);
        QrConfig::assertReady();

        $ctx = SelfPersonelContext::resolveForSelfService($authUser, $pdo, true);
        $personelId = (int) $ctx['personel_id'];
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
            ];
        }

        $existingJti = self::findByUserJtiType($pdo, $userId, (string) $claims['jti'], $eventType);
        if ($existingJti !== null) {
            return [
                'event' => self::publicEvent($pdo, $existingJti),
                'idempotent' => true,
            ];
        }

        $occurredAt = self::utcNowMicro();
        $issuedAt = self::unixToUtcMicro((int) $claims['iat']);
        $expiresAt = self::unixToUtcMicro((int) $claims['exp']);

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

        return [
            'event' => self::publicEvent($pdo, $row),
            'idempotent' => false,
        ];
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
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'occurred_at' => self::formatUtcForClient((string) $row['occurred_at_utc']),
                'sube' => [
                    'id' => (int) $row['sube_id'],
                    'ad' => (string) ($row['sube_ad'] ?? ''),
                ],
            ];
        }

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'items' => $items,
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
        $subeAd = '';
        $subeId = (int) ($row['sube_id'] ?? 0);
        if ($subeId > 0) {
            $stmt = $pdo->prepare('SELECT ad FROM subeler WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $subeId]);
            $ad = $stmt->fetchColumn();
            $subeAd = is_string($ad) ? $ad : '';
        }

        return [
            'id' => (int) $row['id'],
            'event_type' => (string) $row['event_type'],
            'occurred_at' => self::formatUtcForClient((string) $row['occurred_at_utc']),
            'sube' => [
                'id' => $subeId,
                'ad' => $subeAd,
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
