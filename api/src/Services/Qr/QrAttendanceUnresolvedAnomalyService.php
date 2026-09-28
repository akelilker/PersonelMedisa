<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Services\Attendance\AttendanceCorrectionApproverResolver;
use Medisa\Api\Services\Attendance\LateEarlyInfoService;
use Medisa\Api\Services\Bildirim\BugunPersonelDurumuService;
use Medisa\Api\Services\SelfService\PersonelInboxNotificationService;
use PDO;

/**
 * Single unresolved attendance-anomaly read contract.
 *
 * Pairing stays in QrAttendanceIntervalDerivationService (MISSING_CIKIS /
 * MISSING_GIRIS, correction hint GIRIS_CIKIS_DUZELTME). This owner adds the
 * planned-exit + 180 minute gate, work-expectation filter, approved-correction
 * resolution, and the one identity every surface reads:
 *   {anomaly_type}:{source_event_id}
 *
 * CLI notification, Talepler badge, anomaly card, correction prefill and
 * duplicate detection all consume listForPersonel(). No second calculator.
 */
class QrAttendanceUnresolvedAnomalyService
{
    public const THRESHOLD_MINUTES = 180;
    public const LOOKBACK_DAYS = 8;
    public const NOTIFICATION_KIND = 'ATTENDANCE_ANOMALY';
    public const AUDIENCE_PERSONEL = 'PERSONEL';
    public const AUDIENCE_AMIR = 'AMIR';
    public const PERSONEL_TITLE = 'Olağan Dışı Giriş/Çıkış Kaydı';
    public const PERSONEL_BODY = 'Talep oluşturarak amirinizle görüşebilirsiniz.';
    public const LIVE_WARNING = 'Günlük Çalışma Süresi Doldu. Çıkış Yapmanız Gerekmektedir. Amirinizle İrtibata Geçin.';

    /**
     * @param string|null $girisHhmm
     * @param string|null $cikisHhmm
     * @return array{anchor_date:string,start:\DateTimeImmutable,exit:\DateTimeImmutable,threshold:\DateTimeImmutable,exit_hhmm:string}|null
     */
    public static function buildWindow($anchorYmd, $girisHhmm, $cikisHhmm)
    {
        $anchorYmd = trim((string) $anchorYmd);
        $girisMin = LateEarlyInfoService::hhmmToMinutes($girisHhmm);
        $cikisMin = LateEarlyInfoService::hhmmToMinutes($cikisHhmm);
        if ($girisMin === null || $cikisMin === null) {
            return null;
        }
        $tz = new \DateTimeZone('Europe/Istanbul');
        $start = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $anchorYmd . ' ' . self::minutesToHhmm($girisMin) . ':00',
            $tz
        );
        $exit = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $anchorYmd . ' ' . self::minutesToHhmm($cikisMin) . ':00',
            $tz
        );
        if (!$start || !$exit || $start->format('Y-m-d') !== $anchorYmd) {
            return null;
        }
        if ($cikisMin <= $girisMin) {
            $exit = $exit->modify('+1 day');
        }
        $threshold = $exit->modify('+' . self::THRESHOLD_MINUTES . ' minutes');

        return [
            'anchor_date' => $anchorYmd,
            'start' => $start,
            'exit' => $exit,
            'threshold' => $threshold,
            'exit_hhmm' => $exit->format('H:i'),
        ];
    }

    /**
     * @param list<array{anchor_date:string,start:\DateTimeImmutable,exit:\DateTimeImmutable,threshold:\DateTimeImmutable,exit_hhmm:string}> $windows
     * @return array{anchor_date:string,start:\DateTimeImmutable,exit:\DateTimeImmutable,threshold:\DateTimeImmutable,exit_hhmm:string}|null
     */
    public static function matchWindow(\DateTimeImmutable $entryLocal, array $windows)
    {
        $containing = null;
        foreach ($windows as $window) {
            if ($entryLocal >= $window['start'] && $entryLocal < $window['exit']) {
                if ($containing === null || $window['start'] > $containing['start']) {
                    $containing = $window;
                }
            }
        }
        if ($containing !== null) {
            return $containing;
        }

        $entryDate = $entryLocal->format('Y-m-d');
        $early = null;
        foreach ($windows as $window) {
            if ($window['anchor_date'] !== $entryDate) {
                continue;
            }
            if ($entryLocal < $window['start'] && $entryLocal < $window['exit']) {
                $early = $window;
            }
        }

        return $early;
    }

    /**
     * @param array{threshold:\DateTimeImmutable}|null $window
     * @param bool|null $workExpected
     */
    public static function isOperationalMissingCikis(\DateTimeImmutable $now, $window, $workExpected, $resolved)
    {
        if ($resolved || $workExpected !== true || !is_array($window)) {
            return false;
        }

        return $now >= $window['threshold'];
    }

    /**
     * Live open GİRİŞ still blocks the next GİRİŞ. A previous shift whose
     * planned exit + 180 has passed does not. Missing planned exit stays blocking.
     */
    public static function openGirisBlocksNextGiris(PDO $pdo, $personelId, $occurredAtUtc, $now = null)
    {
        $entry = self::utcToIstanbul($occurredAtUtc);
        if (!$entry) {
            return true;
        }
        $window = self::matchPlannedWindow($pdo, (int) $personelId, $entry);
        if ($window === null) {
            return true;
        }

        return self::resolveNow($now) < $window['threshold'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForPersonel(PDO $pdo, $personelId, $now = null)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            return [];
        }
        $nowDt = self::resolveNow($now);
        $events = self::loadRecentEvents($pdo, $personelId, $nowDt);
        if (count($events) === 0) {
            return [];
        }
        $derived = QrAttendanceIntervalDerivationService::derive($events);
        $corrections = self::loadCorrectionIndex($pdo, $personelId);
        $expectationCache = [];
        $items = [];

        foreach ($derived['anomalies'] as $anomaly) {
            $type = (string) ($anomaly['type'] ?? '');
            if ($type !== 'MISSING_CIKIS' && $type !== 'MISSING_GIRIS') {
                continue;
            }
            $sourceEventId = (int) ($anomaly['event_id'] ?? 0);
            if ($sourceEventId <= 0) {
                continue;
            }
            $event = self::eventById($events, $sourceEventId);
            if ($event === null) {
                continue;
            }
            $resolved = self::isResolvedByCorrection($corrections, $sourceEventId, $type);
            $pendingId = self::pendingRequestId($corrections, $sourceEventId);
            $entry = self::utcToIstanbul((string) $event['occurred_at_utc']);
            if (!$entry) {
                continue;
            }

            if ($type === 'MISSING_CIKIS') {
                $window = self::matchPlannedWindow($pdo, $personelId, $entry);
                $anchor = is_array($window) ? (string) $window['anchor_date'] : $entry->format('Y-m-d');
                $workExpected = self::workExpected($pdo, $personelId, $anchor, $expectationCache);
                if (!self::isOperationalMissingCikis($nowDt, $window, $workExpected, $resolved)) {
                    continue;
                }
                $items[] = self::publicAnomaly(
                    'MISSING_CIKIS',
                    $sourceEventId,
                    $anchor,
                    'Çıkış kaydı bulunamadı',
                    'GIRIS',
                    $entry->format('H:i'),
                    $window,
                    $pendingId,
                    (int) ($event['user_id'] ?? 0)
                );
                continue;
            }

            $anchor = $entry->format('Y-m-d');
            $workExpected = self::workExpected($pdo, $personelId, $anchor, $expectationCache);
            if ($resolved || $workExpected !== true) {
                continue;
            }
            $items[] = self::publicAnomaly(
                'MISSING_GIRIS',
                $sourceEventId,
                $anchor,
                'Giriş kaydı bulunamadı',
                'CIKIS',
                $entry->format('H:i'),
                null,
                $pendingId,
                (int) ($event['user_id'] ?? 0)
            );
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>>|null $anomalies
     * @return array{created:int,skipped_schema:bool}
     */
    public static function ensureNotifications(PDO $pdo, $personelId, array $anomalies = null, $now = null)
    {
        if (!self::dedupeSchemaReady($pdo)) {
            return ['created' => 0, 'skipped_schema' => true];
        }
        $personelId = (int) $personelId;
        if ($anomalies === null) {
            $anomalies = self::listForPersonel($pdo, $personelId, $now);
        }
        $created = 0;
        $personel = self::loadPersonel($pdo, $personelId);
        $actor = self::loadActorUser($pdo, $anomalies, $personelId);
        $approver = null;
        if ($actor !== null && $personel !== null) {
            $approver = AttendanceCorrectionApproverResolver::resolve($pdo, $actor, $personel);
        }
        $amirUserId = (is_array($approver) && (int) ($approver['user_id'] ?? 0) > 0)
            ? (int) $approver['user_id']
            : 0;

        foreach ($anomalies as $anomaly) {
            $sourceEventId = (int) ($anomaly['source_event_id'] ?? 0);
            if ($sourceEventId <= 0) {
                continue;
            }
            $payload = [
                'source_event_id' => $sourceEventId,
                'anomaly_type' => (string) ($anomaly['anomaly_type'] ?? ''),
                'business_date' => (string) ($anomaly['business_date'] ?? ''),
                'giris_local_time' => (string) ($anomaly['context_local_time'] ?? ''),
                'planned_exit' => $anomaly['planned_exit_local'] ?? null,
                'correction_hint' => QrAttendanceIntervalDerivationService::CORRECTION_HINT,
            ];
            $personelUserId = $actor !== null ? (int) $actor['id'] : (int) ($anomaly['actor_user_id'] ?? 0);
            if ($personelUserId > 0) {
                $id = PersonelInboxNotificationService::createAnomaly(
                    $pdo,
                    $personelUserId,
                    self::NOTIFICATION_KIND,
                    self::PERSONEL_TITLE,
                    self::PERSONEL_BODY,
                    $personelId,
                    $sourceEventId,
                    self::AUDIENCE_PERSONEL,
                    $payload
                );
                if ($id > 0) {
                    $created++;
                }
            }
            if ($amirUserId <= 0) {
                continue;
            }
            $amirBody = self::amirBody($personel, $anomaly);
            $id = PersonelInboxNotificationService::createAnomaly(
                $pdo,
                $amirUserId,
                self::NOTIFICATION_KIND,
                self::PERSONEL_TITLE,
                $amirBody,
                $personelId,
                $sourceEventId,
                self::AUDIENCE_AMIR,
                $payload
            );
            if ($id > 0) {
                $created++;
            }
        }

        return ['created' => $created, 'skipped_schema' => false];
    }

    /**
     * @return array{personel_count:int,created:int,skipped_schema:bool}
     */
    public static function scan(PDO $pdo, $now = null)
    {
        if (!self::dedupeSchemaReady($pdo)) {
            return ['personel_count' => 0, 'created' => 0, 'skipped_schema' => true];
        }
        $nowDt = self::resolveNow($now);
        $since = $nowDt->setTimezone(new \DateTimeZone('UTC'))->modify('-' . self::LOOKBACK_DAYS . ' days');
        $stmt = $pdo->prepare(
            "SELECT DISTINCT personel_id
             FROM qr_attendance_events
             WHERE event_type = 'GIRIS'
               AND occurred_at_utc >= :since"
        );
        $stmt->execute(['since' => $since->format('Y-m-d H:i:s.u')]);
        $created = 0;
        $count = 0;
        while ($personelId = $stmt->fetchColumn()) {
            $personelId = (int) $personelId;
            if ($personelId <= 0) {
                continue;
            }
            $count++;
            $items = self::listForPersonel($pdo, $personelId, $nowDt);
            $result = self::ensureNotifications($pdo, $personelId, $items, $nowDt);
            $created += (int) $result['created'];
        }

        return ['personel_count' => $count, 'created' => $created, 'skipped_schema' => false];
    }

    public static function dedupeSchemaReady(PDO $pdo)
    {
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(personel_inbox_notifications)');
                if ($stmt === false) {
                    return false;
                }
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row) && (string) ($row['name'] ?? '') === 'anomaly_source_event_id') {
                        return true;
                    }
                }

                return false;
            }
            $stmt = $pdo->query("SHOW COLUMNS FROM personel_inbox_notifications LIKE 'anomaly_source_event_id'");

            return $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed>|null $personel
     * @param array<string,mixed> $anomaly
     */
    public static function amirBody($personel, array $anomaly)
    {
        $name = 'Personel';
        if (is_array($personel)) {
            $name = trim((string) ($personel['ad_soyad'] ?? ''));
            if ($name === '') {
                $name = 'Personel';
            }
        }
        $date = (string) ($anomaly['business_date_label'] ?? '');
        $giris = (string) ($anomaly['context_local_time'] ?? '');
        $planned = (string) ($anomaly['planned_exit_label'] ?? '');
        $problem = (string) ($anomaly['problem'] ?? 'Çıkış kaydı bulunamadı');

        return $name . ', ' . $date . ' tarihinde saat ' . $giris
            . ' ile açık giriş var. Planlanan çıkış ' . ($planned !== '' ? $planned : 'bilinmiyor')
            . '. ' . $problem . '. Düzeltme gerekli.';
    }

    /**
     * @return array{anchor_date:string,start:\DateTimeImmutable,exit:\DateTimeImmutable,threshold:\DateTimeImmutable,exit_hhmm:string}|null
     */
    private static function matchPlannedWindow(PDO $pdo, $personelId, \DateTimeImmutable $entryLocal)
    {
        $dates = [
            $entryLocal->modify('-1 day')->format('Y-m-d'),
            $entryLocal->format('Y-m-d'),
        ];
        $windows = [];
        foreach ($dates as $date) {
            $planned = LateEarlyInfoService::loadPlannedDay($pdo, (int) $personelId, $date);
            if (!is_array($planned)) {
                continue;
            }
            $window = self::buildWindow(
                $date,
                isset($planned['beklenen_giris_saati']) ? (string) $planned['beklenen_giris_saati'] : null,
                isset($planned['beklenen_cikis_saati']) ? (string) $planned['beklenen_cikis_saati'] : null
            );
            if ($window !== null) {
                $windows[] = $window;
            }
        }

        return self::matchWindow($entryLocal, $windows);
    }

    /**
     * @param array<string, bool|null> $cache
     * @return bool|null
     */
    private static function workExpected(PDO $pdo, $personelId, $anchorYmd, array &$cache)
    {
        $anchorYmd = (string) $anchorYmd;
        if (array_key_exists($anchorYmd, $cache)) {
            return $cache[$anchorYmd];
        }
        $cover = BugunPersonelDurumuService::fetchCoveringSurecExceptionMap($pdo, [(int) $personelId], $anchorYmd);
        $pid = (int) $personelId;
        $exceptionTur = ($cover['resolved'] && isset($cover['map'][$pid])) ? $cover['map'][$pid] : null;
        $beklenti = BugunPersonelDurumuService::calismaBeklentisiFromCover($cover['resolved'], $exceptionTur);
        $cache[$anchorYmd] = $beklenti['bekleniyor'];

        return $beklenti['bekleniyor'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function loadRecentEvents(PDO $pdo, $personelId, \DateTimeImmutable $nowLocal)
    {
        $since = $nowLocal->setTimezone(new \DateTimeZone('UTC'))->modify('-' . self::LOOKBACK_DAYS . ' days');
        $stmt = $pdo->prepare(
            'SELECT id, event_type, occurred_at_utc, sube_id, user_id
             FROM qr_attendance_events
             WHERE personel_id = :pid
               AND occurred_at_utc >= :since
             ORDER BY occurred_at_utc ASC, id ASC'
        );
        $stmt->execute([
            'pid' => (int) $personelId,
            'since' => $since->format('Y-m-d H:i:s.u'),
        ]);
        $events = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'occurred_at_utc' => (string) $row['occurred_at_utc'],
                'sube_id' => (int) ($row['sube_id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'sube_ad' => '',
            ];
        }

        return $events;
    }

    /**
     * @return array<int, array{status:string,event_type:string,id:int}>
     */
    private static function loadCorrectionIndex(PDO $pdo, $personelId)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, source_event_id, event_type, status
                 FROM qr_attendance_correction_requests
                 WHERE personel_id = :pid
                   AND status IN ('BEKLIYOR', 'ONAYLANDI')"
            );
            $stmt->execute(['pid' => (int) $personelId]);
        } catch (\Throwable $e) {
            return [];
        }
        $index = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $sourceId = (int) $row['source_event_id'];
            $index[$sourceId][] = [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'event_type' => (string) $row['event_type'],
            ];
        }

        return $index;
    }

    /**
     * @param array<int, list<array{status:string,event_type:string,id:int}>> $corrections
     */
    private static function isResolvedByCorrection(array $corrections, $sourceEventId, $anomalyType)
    {
        $wanted = $anomalyType === 'MISSING_GIRIS' ? 'GIRIS' : 'CIKIS';
        foreach ($corrections[(int) $sourceEventId] ?? [] as $row) {
            if ($row['status'] === 'ONAYLANDI' && $row['event_type'] === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, list<array{status:string,event_type:string,id:int}>> $corrections
     * @return int|null
     */
    private static function pendingRequestId(array $corrections, $sourceEventId)
    {
        foreach ($corrections[(int) $sourceEventId] ?? [] as $row) {
            if ($row['status'] === 'BEKLIYOR') {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>|null
     */
    private static function eventById(array $events, $id)
    {
        foreach ($events as $event) {
            if ((int) $event['id'] === (int) $id) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @param array{exit:\DateTimeImmutable,threshold:\DateTimeImmutable,exit_hhmm:string,anchor_date:string}|null $window
     * @return array<string,mixed>
     */
    private static function publicAnomaly(
        $type,
        $sourceEventId,
        $businessDate,
        $problem,
        $contextEventType,
        $contextLocalTime,
        $window,
        $pendingId,
        $actorUserId
    ) {
        $dateLabel = $businessDate;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $businessDate);
        if ($parsed instanceof \DateTimeImmutable) {
            $dateLabel = $parsed->format('d.m.Y');
        }
        $plannedLocal = null;
        $plannedLabel = null;
        if (is_array($window)) {
            $plannedLocal = $window['exit']->format('c');
            $plannedLabel = $window['exit_hhmm'];
        }

        return [
            'identity' => $type . ':' . (int) $sourceEventId,
            'source_event_id' => (int) $sourceEventId,
            'anomaly_type' => $type,
            'correction_hint' => QrAttendanceIntervalDerivationService::CORRECTION_HINT,
            'business_date' => (string) $businessDate,
            'business_date_label' => $dateLabel,
            'problem' => $problem,
            'context_event_type' => $contextEventType,
            'context_local_time' => $contextLocalTime,
            'planned_exit_local' => $plannedLocal,
            'planned_exit_label' => $plannedLabel,
            'pending_request_id' => $pendingId,
            'actor_user_id' => (int) $actorUserId,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadPersonel(PDO $pdo, $personelId)
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, ad, soyad, sube_id, bolum_id, birim_id FROM personeller WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => (int) $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }

        return [
            'personel_id' => (int) $row['id'],
            'ad_soyad' => trim((string) ($row['ad'] ?? '') . ' ' . (string) ($row['soyad'] ?? '')),
            'sube_id' => (int) ($row['sube_id'] ?? 0),
            'bolum_id' => $row['bolum_id'] !== null ? (int) $row['bolum_id'] : null,
            'birim_id' => $row['birim_id'] !== null ? (int) $row['birim_id'] : null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $anomalies
     * @return array{id:int,rol:string}|null
     */
    private static function loadActorUser(PDO $pdo, array $anomalies, $personelId)
    {
        $userId = 0;
        foreach ($anomalies as $anomaly) {
            $candidate = (int) ($anomaly['actor_user_id'] ?? 0);
            if ($candidate > 0) {
                $userId = $candidate;
                break;
            }
        }
        try {
            if ($userId <= 0) {
                $stmt = $pdo->prepare(
                    "SELECT id, rol FROM users WHERE personel_id = :pid AND durum = 'AKTIF' ORDER BY id ASC LIMIT 1"
                );
                $stmt->execute(['pid' => (int) $personelId]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, rol FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1"
                );
                $stmt->execute(['id' => $userId]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'rol' => (string) ($row['rol'] ?? ''),
        ];
    }

    /**
     * @return \DateTimeImmutable|null
     */
    private static function utcToIstanbul($utc)
    {
        $raw = trim((string) $utc);
        if ($raw === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/Istanbul'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param \DateTimeImmutable|string|null $now
     */
    public static function resolveNow($now)
    {
        $tz = new \DateTimeZone('Europe/Istanbul');
        if ($now instanceof \DateTimeImmutable) {
            return $now->setTimezone($tz);
        }
        if (is_string($now) && trim($now) !== '') {
            return (new \DateTimeImmutable($now))->setTimezone($tz);
        }

        return new \DateTimeImmutable('now', $tz);
    }

    private static function minutesToHhmm($minutes)
    {
        $minutes = (int) $minutes;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
