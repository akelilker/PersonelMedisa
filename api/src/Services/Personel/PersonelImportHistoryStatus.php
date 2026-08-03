<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

/**
 * S97-C: single owner for personel import run status labels and timestamps.
 * Canonical values come from migration 046 ENUM only.
 */
final class PersonelImportHistoryStatus
{
    public const COMPLETED = 'COMPLETED';
    public const BASARISIZ = 'BASARISIZ';
    public const CLAIMED = 'CLAIMED';

    /** Durable history statuses shown when no status filter is provided. */
    public const DEFAULT_LIST_STATUSES = [self::COMPLETED, self::BASARISIZ];

    /** @var array<string, string> */
    private static $labels = [
        self::COMPLETED => 'Tamamlandı',
        self::BASARISIZ => 'Başarısız',
        self::CLAIMED => 'İşlemde',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::COMPLETED, self::BASARISIZ, self::CLAIMED];
    }

    public static function isCanonical($status): bool
    {
        return in_array((string) $status, self::all(), true);
    }

    public static function label($status): string
    {
        $key = (string) $status;
        if (!isset(self::$labels[$key])) {
            return 'Bilinmeyen durum';
        }

        return self::$labels[$key];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{created_at: ?string, completed_at: ?string, failed_at: ?string}
     */
    public static function timestamps(array $row): array
    {
        $started = isset($row['started_at']) && $row['started_at'] !== null && $row['started_at'] !== ''
            ? (string) $row['started_at']
            : null;
        $finished = isset($row['finished_at']) && $row['finished_at'] !== null && $row['finished_at'] !== ''
            ? (string) $row['finished_at']
            : null;
        $status = (string) ($row['status'] ?? '');

        return [
            'created_at' => $started,
            'completed_at' => $status === self::COMPLETED ? $finished : null,
            'failed_at' => $status === self::BASARISIZ ? $finished : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   row_count: int,
     *   valid_row_count: int,
     *   created_count: int,
     *   failed_row_count: int
     * }
     */
    public static function counts(array $row): array
    {
        $toplam = (int) ($row['toplam_satir'] ?? 0);
        $gecerli = (int) ($row['gecerli_satir'] ?? 0);
        $created = (int) ($row['created_count'] ?? 0);
        $failed = max(0, $toplam - $created);
        if ((string) ($row['status'] ?? '') === self::BASARISIZ && $created === 0 && $toplam === 0) {
            $failed = 0;
        }

        return [
            'row_count' => $toplam,
            'valid_row_count' => $gecerli,
            'created_count' => $created,
            'failed_row_count' => $failed,
        ];
    }

    /** Scrubbed user-facing failure message; never returns SQL/stack. */
    public static function failureMessage($errorCode): ?string
    {
        $code = trim((string) $errorCode);
        if ($code === '') {
            return null;
        }

        $map = [
            'PERSONEL_IMPORT_TRANSACTION_FAILED' => 'Import işlemi tamamlanamadı.',
            'PERSONEL_IMPORT_DRY_RUN_FAILED' => 'Import doğrulaması başarısız olduğu için işlem uygulanamadı.',
            'PERSONEL_IMPORT_MANIFEST_CHANGED' => 'Kaynak dosya dry-run sonrası değişti.',
            'PERSONEL_IMPORT_IDEMPOTENCY_CONFLICT' => 'Aynı işlem anahtarı farklı içerikle kullanıldı.',
            'SCHEMA_NOT_READY' => 'Personel import şeması henüz hazır değil.',
        ];

        return $map[$code] ?? 'Personel import işlemi başarısız oldu.';
    }
}
