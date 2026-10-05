<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Database\QrAttendanceSchema;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
use Medisa\Api\Services\Personel\PersonelOperationalContextService;
use PDO;

/**
 * First-version factory geofence audit for QR GIRIS/CIKIS (informational only).
 *
 * Thresholds (v1):
 * - Radius 300 m from approved entrance edge point.
 * - UNCERTAIN when GPS accuracy > 75 m OR distance within 50 m of boundary (>= 250 m inside).
 * - Raw coordinates are used only at request time; not stored on the event row.
 */
final class QrAttendanceLocationVerificationService
{
    public const GEOFENCE_KEY = 'v1_factory_entrance_300m';

    public const CENTER_LAT = 41.1450388;
    public const CENTER_LNG = 32.6505877;
    public const RADIUS_METERS = 300.0;
    public const ACCURACY_UNCERTAIN_METERS = 75.0;
    public const BOUNDARY_BUFFER_METERS = 50.0;

    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_UNCERTAIN = 'UNCERTAIN';
    public const STATUS_OUTSIDE = 'OUTSIDE';
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';

    /**
     * @param array<string,mixed>|null $capture Client location_capture payload
     * @return array{
     *   location_verification_status: string,
     *   location_accuracy_meters: ?float,
     *   location_distance_meters: ?float,
     *   location_geofence_key: string
     * }
     */
    public static function evaluateForScan(PDO $pdo, int $personelId, string $occurredAtUtc, $capture): array
    {
        $geofenceKey = self::GEOFENCE_KEY;
        if (!is_array($capture) || empty($capture['available'])) {
            return self::row(self::STATUS_UNAVAILABLE, null, null, $geofenceKey);
        }

        $lat = self::readCoordinate($capture['latitude'] ?? null);
        $lng = self::readCoordinate($capture['longitude'] ?? null);
        if ($lat === null || $lng === null) {
            return self::row(self::STATUS_UNAVAILABLE, self::readAccuracy($capture), null, $geofenceKey);
        }

        $accuracy = self::readAccuracy($capture);
        $distance = self::haversineMeters($lat, $lng, self::CENTER_LAT, self::CENTER_LNG);
        $status = self::classifyDistance($distance, $accuracy);

        if ($status === self::STATUS_OUTSIDE && self::shouldSoftenOutsideStatus($pdo, $personelId, $occurredAtUtc)) {
            $status = self::STATUS_UNCERTAIN;
        }

        return self::row($status, $accuracy, $distance, $geofenceKey);
    }

    public static function managerLabel(string $status): string
    {
        switch ($status) {
            case self::STATUS_VERIFIED:
                return 'Konum Doğrulandı';
            case self::STATUS_UNCERTAIN:
                return 'Konum Belirsiz';
            case self::STATUS_OUTSIDE:
                return 'İşyeri Dışından İşlem';
            default:
                return 'Konum Alınamadı';
        }
    }

    /**
     * @param array<string,mixed> $row qr_attendance_events row fragment
     * @return array<string,mixed>|null
     */
    public static function managerEventPayload(array $row): ?array
    {
        $status = isset($row['location_verification_status'])
            ? trim((string) $row['location_verification_status'])
            : '';
        if ($status === '') {
            return null;
        }

        $distance = array_key_exists('location_distance_meters', $row)
            ? self::nullableFloat($row['location_distance_meters'])
            : null;
        $accuracy = array_key_exists('location_accuracy_meters', $row)
            ? self::nullableFloat($row['location_accuracy_meters'])
            : null;

        return [
            'status_code' => $status,
            'status_label' => self::managerLabel($status),
            'distance_meters' => $distance,
            'accuracy_meters' => $accuracy,
            'geofence_key' => isset($row['location_geofence_key'])
                ? (string) $row['location_geofence_key']
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function row(string $status, ?float $accuracy, ?float $distance, string $geofenceKey): array
    {
        return [
            'location_verification_status' => $status,
            'location_accuracy_meters' => $accuracy,
            'location_distance_meters' => $distance !== null ? round($distance, 2) : null,
            'location_geofence_key' => $geofenceKey,
        ];
    }

    /**
     * @param array<string,mixed> $audit
     */
    public static function appendInsertColumns(PDO $pdo, array &$columns, array &$placeholders, array &$params, array $audit): void
    {
        if (!QrAttendanceSchema::hasLocationAuditColumns($pdo)) {
            return;
        }
        $columns[] = 'location_verification_status';
        $columns[] = 'location_accuracy_meters';
        $columns[] = 'location_distance_meters';
        $columns[] = 'location_geofence_key';
        $placeholders[] = ':location_verification_status';
        $placeholders[] = ':location_accuracy_meters';
        $placeholders[] = ':location_distance_meters';
        $placeholders[] = ':location_geofence_key';
        $params['location_verification_status'] = (string) $audit['location_verification_status'];
        $params['location_accuracy_meters'] = $audit['location_accuracy_meters'];
        $params['location_distance_meters'] = $audit['location_distance_meters'];
        $params['location_geofence_key'] = (string) $audit['location_geofence_key'];
    }

    private static function classifyDistance(float $distanceMeters, ?float $accuracyMeters): string
    {
        if ($distanceMeters > self::RADIUS_METERS) {
            return self::STATUS_OUTSIDE;
        }
        $nearBoundary = $distanceMeters >= (self::RADIUS_METERS - self::BOUNDARY_BUFFER_METERS);
        $weakAccuracy = $accuracyMeters !== null && $accuracyMeters > self::ACCURACY_UNCERTAIN_METERS;
        if ($nearBoundary || $weakAccuracy) {
            return self::STATUS_UNCERTAIN;
        }

        return self::STATUS_VERIFIED;
    }

    private static function shouldSoftenOutsideStatus(PDO $pdo, int $personelId, string $occurredAtUtc): bool
    {
        try {
            $ctx = PersonelOperationalContextService::resolveAt($pdo, $personelId, $occurredAtUtc);
        } catch (\Throwable $e) {
            return false;
        }
        if (($ctx['source'] ?? '') === PersonelOperationalContextService::SOURCE_ASSIGNMENT) {
            return true;
        }
        if (
            ($ctx['calisan_kapsami'] ?? '') === PersonelCalisanKapsamService::DIS_KAYNAK
            && !empty($ctx['has_operational_scope'])
        ) {
            return true;
        }

        return false;
    }

    private static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /** @param mixed $value */
    private static function readCoordinate($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $num = (float) $value;
        if ($num < -180.0 || $num > 180.0) {
            return null;
        }

        return $num;
    }

    /** @param array<string,mixed> $capture */
    private static function readAccuracy(array $capture): ?float
    {
        if (!array_key_exists('accuracy_meters', $capture) || !is_numeric($capture['accuracy_meters'])) {
            return null;
        }
        $acc = (float) $capture['accuracy_meters'];
        if ($acc < 0) {
            return null;
        }

        return round($acc, 2);
    }

    /** @param mixed $value */
    private static function nullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
