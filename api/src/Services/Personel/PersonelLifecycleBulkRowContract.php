<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

/**
 * Shared bulk row operation contract (dry-run + apply).
 */
final class PersonelLifecycleBulkRowContract
{
    public const OP_CREATE = 'PERSONEL_CREATE';
    public const OP_BASIC_UPDATE = 'PERSONEL_BASIC_UPDATE';
    public const OP_ORG_UPDATE = 'PERSONEL_ORGANIZATION_UPDATE';
    public const OP_BRANCH = 'PERMANENT_BRANCH_CHANGE';
    public const OP_EXIT = 'PERSONEL_EXIT';
    public const OP_REFERENCE = 'REFERENCE_CREATE_OR_RESOLVE';

    /** @var list<string> */
    public const OPERATION_TYPES = [
        self::OP_CREATE,
        self::OP_BASIC_UPDATE,
        self::OP_ORG_UPDATE,
        self::OP_BRANCH,
        self::OP_EXIT,
        self::OP_REFERENCE,
        'YENI_GIRIS',
        'ISTEN_AYRILMA',
        'YENIDEN_ISE_ALMA',
        'GOREV_UNVAN_DEGISIKLIGI',
        'DEPARTMAN_BOLUM_BIRIM_POZISYON_DEGISIKLIGI',
        'KALICI_SUBE_DEGISIKLIGI',
        'CALISMA_LOKASYONU_DEGISIKLIGI',
        'SGK_ISVERENI_DEGISIKLIGI',
        'SADECE_NOT',
        'DEGISIKLIK_YOK',
    ];

    /**
     * @param array<string, mixed> $row
     */
    public static function resolveOperationType(array $row): string
    {
        $explicit = strtoupper(trim((string) ($row['operation_type'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        return strtoupper(trim((string) ($row['islem_tipi'] ?? '')));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalizeRow(array $raw): array
    {
        if (isset($raw['payload']) && is_array($raw['payload'])) {
            $row = $raw;
        } else {
            $row = ['payload' => []];
            foreach (PersonelExportService::CHANGE_TEMPLATE_HEADERS as $col) {
                $row[$col] = trim((string) ($raw[$col] ?? ''));
            }
            if (isset($raw['payload']) && is_array($raw['payload'])) {
                $row['payload'] = $raw['payload'];
            }
        }

        $mutationId = trim((string) ($raw['mutation_id'] ?? $row['mutation_id'] ?? ''));
        if ($mutationId === '') {
            $mutationId = 'legacy:' . substr(hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE)), 0, 32);
        }
        $row['mutation_id'] = $mutationId;
        $row['operation_type'] = self::resolveOperationType(array_merge($raw, $row));
        $row['personel_ref'] = trim((string) ($raw['personel_ref'] ?? $row['personel_ref'] ?? ''));
        $row['depends_on_personel_ref'] = trim((string) ($raw['depends_on_personel_ref'] ?? $row['depends_on_personel_ref'] ?? ''));

        return $row;
    }

    public static function mapLegacyToCanonical(string $operationType): string
    {
        switch ($operationType) {
            case 'YENI_GIRIS':
                return self::OP_CREATE;
            case 'ISTEN_AYRILMA':
                return self::OP_EXIT;
            case 'GOREV_UNVAN_DEGISIKLIGI':
            case 'DEPARTMAN_BOLUM_BIRIM_POZISYON_DEGISIKLIGI':
            case 'CALISMA_LOKASYONU_DEGISIKLIGI':
            case 'SGK_ISVERENI_DEGISIKLIGI':
                return self::OP_ORG_UPDATE;
            case 'KALICI_SUBE_DEGISIKLIGI':
                return self::OP_BRANCH;
            default:
                return $operationType;
        }
    }
}
