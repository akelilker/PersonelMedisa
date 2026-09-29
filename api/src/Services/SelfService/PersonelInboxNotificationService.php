<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Qr\QrAttendanceUnresolvedAnomalyService;
use PDO;
use PDOException;

/**
 * Persistent personel/manager inbox + one-time popup delivery.
 */
class PersonelInboxNotificationService
{
    public static function assertSchemaReady(PDO $pdo)
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'personel_inbox_notifications' LIMIT 1"
            );
            $ready = $stmt !== false && (int) $stmt->fetchColumn() === 1;
        } else {
            $stmt = $pdo->query("SHOW TABLES LIKE 'personel_inbox_notifications'");
            $ready = $stmt !== false && $stmt->fetch(PDO::FETCH_NUM) !== false;
        }
        if ($stmt !== false) {
            $stmt->closeCursor();
        }
        if (!$ready) {
            throw new \RuntimeException('PERSONEL_INBOX_SCHEMA_NOT_READY');
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public static function create(
        PDO $pdo,
        $recipientUserId,
        $kind,
        $title,
        $body,
        $personelId = null,
        $relatedCorrectionId = null,
        array $payload = null,
        $popupRequired = true,
        $reminderOfId = null
    ) {
        self::assertSchemaReady($pdo);
        $now = self::utcNow();
        $stmt = $pdo->prepare(
            'INSERT INTO personel_inbox_notifications
                (recipient_user_id, personel_id, kind, title, body, payload_json,
                 related_correction_id, status, popup_required, reminder_of_notification_id, created_at_utc)
             VALUES
                (:recipient_user_id, :personel_id, :kind, :title, :body, :payload_json,
                 :related_correction_id, \'ACTIVE\', :popup_required, :reminder_of, :created_at_utc)'
        );
        $stmt->execute([
            'recipient_user_id' => (int) $recipientUserId,
            'personel_id' => $personelId !== null && (int) $personelId > 0 ? (int) $personelId : null,
            'kind' => (string) $kind,
            'title' => (string) $title,
            'body' => (string) $body,
            'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'related_correction_id' => $relatedCorrectionId !== null && (int) $relatedCorrectionId > 0
                ? (int) $relatedCorrectionId
                : null,
            'popup_required' => $popupRequired ? 1 : 0,
            'reminder_of' => $reminderOfId !== null && (int) $reminderOfId > 0 ? (int) $reminderOfId : null,
            'created_at_utc' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Atomic insert for one anomaly audience. The unique key
     * uq_pin_anomaly_dedupe (migration 093) is the race boundary: a second
     * CLI tick receives a duplicate-key error and creates nothing.
     *
     * @param array<string, mixed>|null $payload
     * @param string|null $businessDate day-key identity; null keeps the event-key insert
     * @param string|null $anomalyType day-key type; NO_EVENT_DAY does not store a source event id
     * @return int new id, or 0 when the anomaly notification already exists
     */
    public static function createAnomaly(
        PDO $pdo,
        $recipientUserId,
        $kind,
        $title,
        $body,
        $personelId,
        $sourceEventId,
        $audience,
        array $payload = null,
        $businessDate = null,
        $anomalyType = null
    ) {
        $sourceEventId = (int) $sourceEventId;
        $audience = trim((string) $audience);
        $businessDate = $businessDate !== null ? trim((string) $businessDate) : '';
        $anomalyType = $anomalyType !== null ? trim((string) $anomalyType) : '';
        $dayKeyed = $anomalyType !== '' && $businessDate !== '';
        if ((int) $recipientUserId <= 0 || $audience === '' || (!$dayKeyed && $sourceEventId <= 0)) {
            return 0;
        }
        if ($dayKeyed && !QrAttendanceUnresolvedAnomalyService::dayKeySchemaReady($pdo)) {
            return 0;
        }
        self::assertSchemaReady($pdo);
        $now = self::utcNow();
        try {
            if ($dayKeyed) {
                $stmt = $pdo->prepare(
                    'INSERT INTO personel_inbox_notifications
                        (recipient_user_id, personel_id, kind, title, body, payload_json,
                         anomaly_source_event_id, anomaly_audience, anomaly_type, anomaly_business_date,
                         related_correction_id, status, popup_required, reminder_of_notification_id, created_at_utc)
                     VALUES
                        (:recipient_user_id, :personel_id, :kind, :title, :body, :payload_json,
                         NULL, :anomaly_audience, :anomaly_type, :anomaly_business_date,
                         NULL, \'ACTIVE\', 1, NULL, :created_at_utc)'
                );
                $stmt->execute([
                    'recipient_user_id' => (int) $recipientUserId,
                    'personel_id' => $personelId !== null && (int) $personelId > 0 ? (int) $personelId : null,
                    'kind' => (string) $kind,
                    'title' => (string) $title,
                    'body' => (string) $body,
                    'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                    'anomaly_audience' => $audience,
                    'anomaly_type' => $anomalyType,
                    'anomaly_business_date' => $businessDate,
                    'created_at_utc' => $now,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO personel_inbox_notifications
                        (recipient_user_id, personel_id, kind, title, body, payload_json,
                         anomaly_source_event_id, anomaly_audience,
                         related_correction_id, status, popup_required, reminder_of_notification_id, created_at_utc)
                     VALUES
                        (:recipient_user_id, :personel_id, :kind, :title, :body, :payload_json,
                         :anomaly_source_event_id, :anomaly_audience,
                         NULL, \'ACTIVE\', 1, NULL, :created_at_utc)'
                );
                $stmt->execute([
                    'recipient_user_id' => (int) $recipientUserId,
                    'personel_id' => $personelId !== null && (int) $personelId > 0 ? (int) $personelId : null,
                    'kind' => (string) $kind,
                    'title' => (string) $title,
                    'body' => (string) $body,
                    'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                    'anomaly_source_event_id' => $sourceEventId,
                    'anomaly_audience' => $audience,
                    'created_at_utc' => $now,
                ]);
            }
        } catch (PDOException $e) {
            if (self::isAnomalyDedupeViolation($e)) {
                return 0;
            }
            throw $e;
        }

        return (int) $pdo->lastInsertId();
    }

    private static function isAnomalyDedupeViolation(PDOException $e)
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode === 1062 || $driverCode === 19) {
            return true;
        }
        $message = $e->getMessage();

        return stripos($message, 'uq_pin_anomaly_dedupe') !== false
            || stripos($message, 'UNIQUE constraint failed') !== false
            || stripos($message, 'Duplicate entry') !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForUser(PDO $pdo, $userId, $limit = 50)
    {
        self::assertSchemaReady($pdo);
        $limit = max(1, min(100, (int) $limit));
        $stmt = $pdo->prepare(
            'SELECT id, recipient_user_id, personel_id, kind, title, body, payload_json,
                    related_correction_id, status, popup_required, popup_consumed_at_utc,
                    reminder_of_notification_id, created_at_utc
             FROM personel_inbox_notifications
             WHERE recipient_user_id = :uid
             ORDER BY created_at_utc DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['uid' => (int) $userId]);
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = self::publicRow($row);
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pendingPopupsForUser(PDO $pdo, $userId)
    {
        self::assertSchemaReady($pdo);
        $stmt = $pdo->prepare(
            'SELECT id, recipient_user_id, personel_id, kind, title, body, payload_json,
                    related_correction_id, status, popup_required, popup_consumed_at_utc,
                    reminder_of_notification_id, created_at_utc
             FROM personel_inbox_notifications
             WHERE recipient_user_id = :uid
               AND popup_required = 1
               AND popup_consumed_at_utc IS NULL
               AND status = \'ACTIVE\'
             ORDER BY created_at_utc ASC, id ASC
             LIMIT 20'
        );
        $stmt->execute(['uid' => (int) $userId]);
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = self::publicRow($row);
        }

        return $items;
    }

    public static function ackPopup(PDO $pdo, $userId, $notificationId)
    {
        self::assertSchemaReady($pdo);
        $now = self::utcNow();
        $stmt = $pdo->prepare(
            'UPDATE personel_inbox_notifications
             SET popup_consumed_at_utc = :now
             WHERE id = :id
               AND recipient_user_id = :uid
               AND popup_consumed_at_utc IS NULL'
        );
        $stmt->execute([
            'now' => $now,
            'id' => (int) $notificationId,
            'uid' => (int) $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed> */
    private static function publicRow(array $row)
    {
        $payload = null;
        if (isset($row['payload_json']) && is_string($row['payload_json']) && $row['payload_json'] !== '') {
            $decoded = json_decode($row['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'payload' => $payload,
            'related_correction_id' => isset($row['related_correction_id']) && $row['related_correction_id'] !== null
                ? (int) $row['related_correction_id']
                : null,
            'personel_id' => isset($row['personel_id']) && $row['personel_id'] !== null
                ? (int) $row['personel_id']
                : null,
            'status' => (string) $row['status'],
            'popup_required' => ((int) ($row['popup_required'] ?? 0)) === 1,
            'popup_consumed' => $row['popup_consumed_at_utc'] !== null && $row['popup_consumed_at_utc'] !== '',
            'created_at' => self::formatUtcForClient((string) $row['created_at_utc']),
        ];
    }

    private static function utcNow()
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private static function formatUtcForClient($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('c');
    }
}
