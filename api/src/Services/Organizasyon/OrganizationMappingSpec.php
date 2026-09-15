<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

/**
 * Typed, allowlisted description of ONE approved initial organisation mapping.
 *
 * Production values live in the spec an operator supplies, never in this file:
 * hardcoding company codes or branch names here would turn a reviewed business
 * decision into a code constant that silently outlives it. What this owner does
 * hardcode is the shape and the rules — which fields may exist, which must, and
 * which combinations are refused before a single row is touched.
 *
 * The spec pins the inventory it was written against (`inventory_checksum`) and
 * carries the expected preimage of every row it wants to change. That is the
 * whole safety model: if production moved between the inventory and the apply,
 * the preimage no longer matches and the operation fails closed instead of
 * writing a decision that was made about different data.
 *
 * Deliberately absent, and rejected if present: personnel fields, user scope,
 * `tam_ad`, branch code changes, company id references (companies are addressed
 * by `kod`, the stable technical identity), and any name-based auto-mapping.
 */
final class OrganizationMappingSpec
{
    public const SCHEMA_VERSION = '1';

    /** A branch id that does not exist in production must never be invented. */
    public const FORBIDDEN_BRANCH_IDS = [3];

    private const TOP_LEVEL_FIELDS = [
        'schema_version',
        'metadata',
        'companies',
        'sgk_mappings',
        'branch_mappings',
        'location_mappings',
        'preservation',
    ];

    private const METADATA_FIELDS = [
        'authorized_deploy_sha',
        'expected_production_tip',
        'inventory_checksum',
        'inventory_generated_at',
        'expected_row_counts',
        'expected_branch_ids',
        'operation_id',
    ];

    private const COMPANY_FIELDS = ['kod', 'ad', 'durum'];

    private const SGK_MAPPING_FIELDS = [
        'sgk_isveren_id',
        'expected_kod',
        'expected_ad',
        'expected_sirket_id',
        'target_company_kod',
    ];

    private const BRANCH_MAPPING_FIELDS = [
        'sube_id',
        'expected_kod',
        'expected_ad',
        'expected_sirket_id',
        'expected_sgk_isveren_id',
        'target_company_kod',
        'approved_ad',
    ];

    private const LOCATION_MAPPING_FIELDS = [
        'calisma_lokasyonu_id',
        'expected_kod',
        'expected_ad',
        'expected_sube_id',
        'target_sube_id',
    ];

    private const PRESERVATION_FIELDS = [
        'expected_personel_count',
        'expected_user_sube_count',
        'expected_user_sirket_count',
        'expected_user_sgk_isveren_count',
    ];

    private const EXPECTED_ROW_COUNT_TABLES = [
        'sirketler',
        'subeler',
        'sgk_isverenler',
        'calisma_lokasyonlari',
        'personeller',
        'user_subeler',
        'user_sirketler',
        'user_sgk_isverenler',
    ];

    /** @var array<string, mixed> */
    private $spec;

    /** @param array<string, mixed> $spec */
    private function __construct(array $spec)
    {
        $this->spec = $spec;
    }

    /**
     * Parse and fully validate a raw decoded spec. Throws on the first rule it
     * breaks; a partially valid spec is never returned.
     *
     * @param mixed $raw
     */
    public static function parse($raw): self
    {
        if (!is_array($raw) || $raw === [] || array_keys($raw) === range(0, count($raw) - 1)) {
            throw OrganizationMappingFailure::of('SPEC_NOT_OBJECT');
        }
        self::rejectUnknown($raw, self::TOP_LEVEL_FIELDS, 'spec');

        if (self::string($raw, 'schema_version', 'spec') !== self::SCHEMA_VERSION) {
            throw OrganizationMappingFailure::of('SPEC_SCHEMA_VERSION_UNSUPPORTED');
        }

        $spec = [
            'schema_version' => self::SCHEMA_VERSION,
            'metadata' => self::parseMetadata(self::object($raw, 'metadata', 'spec')),
            'companies' => self::parseCompanies(self::listOf($raw, 'companies', 'spec')),
            'sgk_mappings' => self::parseSgkMappings(self::listOf($raw, 'sgk_mappings', 'spec')),
            'branch_mappings' => self::parseBranchMappings(self::listOf($raw, 'branch_mappings', 'spec')),
            'location_mappings' => self::parseLocationMappings(
                array_key_exists('location_mappings', $raw) ? self::listOf($raw, 'location_mappings', 'spec') : []
            ),
            'preservation' => self::parsePreservation(self::object($raw, 'preservation', 'spec')),
        ];

        self::assertCrossReferences($spec);

        return new self($spec);
    }

    /**
     * Digest of the approved decision, computed over the canonical JSON of the
     * whole spec. The preflight publishes it and the apply re-checks it, so the
     * document that passed preflight is provably the one that ran.
     */
    public function checksum(): string
    {
        return hash('sha256', OrganizationMappingInventoryReport::canonicalJson($this->spec));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->spec;
    }

    public function operationId(): string
    {
        return (string) $this->spec['metadata']['operation_id'];
    }

    public function authorizedDeploySha(): string
    {
        return (string) $this->spec['metadata']['authorized_deploy_sha'];
    }

    public function expectedProductionTip(): string
    {
        return (string) $this->spec['metadata']['expected_production_tip'];
    }

    public function inventoryChecksum(): string
    {
        return (string) $this->spec['metadata']['inventory_checksum'];
    }

    /** @return array<string, int> */
    public function expectedRowCounts(): array
    {
        return $this->spec['metadata']['expected_row_counts'];
    }

    /** @return list<int> */
    public function expectedBranchIds(): array
    {
        return $this->spec['metadata']['expected_branch_ids'];
    }

    /** @return list<array<string, mixed>> */
    public function companies(): array
    {
        return $this->spec['companies'];
    }

    /** @return list<array<string, mixed>> */
    public function sgkMappings(): array
    {
        return $this->spec['sgk_mappings'];
    }

    /** @return list<array<string, mixed>> */
    public function branchMappings(): array
    {
        return $this->spec['branch_mappings'];
    }

    /** @return list<array<string, mixed>> */
    public function locationMappings(): array
    {
        return $this->spec['location_mappings'];
    }

    /** @return array<string, int> */
    public function preservation(): array
    {
        return $this->spec['preservation'];
    }

    // ------------------------------------------------------------------ parsing

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function parseMetadata(array $raw): array
    {
        self::rejectUnknown($raw, self::METADATA_FIELDS, 'metadata');

        $counts = self::object($raw, 'expected_row_counts', 'metadata');
        self::rejectUnknown($counts, self::EXPECTED_ROW_COUNT_TABLES, 'metadata.expected_row_counts');
        $expectedCounts = [];
        foreach (self::EXPECTED_ROW_COUNT_TABLES as $table) {
            $expectedCounts[$table] = self::nonNegativeInt($counts, $table, 'metadata.expected_row_counts');
        }

        $branchIds = self::intList($raw, 'expected_branch_ids', 'metadata');
        if ($branchIds === []) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', 'metadata.expected_branch_ids');
        }

        return [
            'authorized_deploy_sha' => self::pattern($raw, 'authorized_deploy_sha', '/^[a-f0-9]{40}$/', 'metadata'),
            'expected_production_tip' => self::pattern($raw, 'expected_production_tip', '/^[0-9]{3}$/', 'metadata'),
            'inventory_checksum' => self::pattern($raw, 'inventory_checksum', '/^[a-f0-9]{64}$/', 'metadata'),
            'inventory_generated_at' => self::pattern(
                $raw,
                'inventory_generated_at',
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
                'metadata'
            ),
            'expected_row_counts' => $expectedCounts,
            'expected_branch_ids' => $branchIds,
            'operation_id' => self::pattern($raw, 'operation_id', '/^[A-Za-z0-9._-]{1,128}$/', 'metadata'),
        ];
    }

    /**
     * @param list<mixed> $raw
     * @return list<array<string, mixed>>
     */
    private static function parseCompanies(array $raw): array
    {
        if ($raw === []) {
            throw OrganizationMappingFailure::of('SPEC_NO_COMPANY');
        }

        $items = [];
        $seenKod = [];
        $seenAd = [];
        foreach ($raw as $index => $entry) {
            $path = 'companies[' . (int) $index . ']';
            $row = self::entry($entry, $path);
            self::rejectUnknown($row, self::COMPANY_FIELDS, $path);

            $kod = self::pattern($row, 'kod', '/^[A-Z0-9][A-Z0-9_-]{0,31}$/', $path);
            $ad = self::text($row, 'ad', 191, $path);
            $durum = self::durum($row, $path);

            $normalizedAd = SubeReadModel::normalizeName($ad);
            if (isset($seenKod[$kod])) {
                throw OrganizationMappingFailure::of('SPEC_DUPLICATE_COMPANY_KOD', $kod);
            }
            if (isset($seenAd[$normalizedAd])) {
                throw OrganizationMappingFailure::of('SPEC_DUPLICATE_COMPANY_AD', $path);
            }
            $seenKod[$kod] = true;
            $seenAd[$normalizedAd] = true;

            $items[] = ['kod' => $kod, 'ad' => $ad, 'durum' => $durum];
        }

        return $items;
    }

    /**
     * @param list<mixed> $raw
     * @return list<array<string, mixed>>
     */
    private static function parseSgkMappings(array $raw): array
    {
        if ($raw === []) {
            throw OrganizationMappingFailure::of('SPEC_NO_SGK_MAPPING');
        }

        $items = [];
        $seen = [];
        foreach ($raw as $index => $entry) {
            $path = 'sgk_mappings[' . (int) $index . ']';
            $row = self::entry($entry, $path);
            self::rejectUnknown($row, self::SGK_MAPPING_FIELDS, $path);

            $id = self::positiveInt($row, 'sgk_isveren_id', $path);
            if (isset($seen[$id])) {
                throw OrganizationMappingFailure::of('SPEC_DUPLICATE_SGK_MAPPING', (string) $id);
            }
            $seen[$id] = true;

            $items[] = [
                'sgk_isveren_id' => $id,
                'expected_kod' => self::text($row, 'expected_kod', 32, $path),
                'expected_ad' => self::text($row, 'expected_ad', 191, $path),
                'expected_sirket_id' => self::nullableId($row, 'expected_sirket_id', $path),
                'target_company_kod' => self::pattern($row, 'target_company_kod', '/^[A-Z0-9][A-Z0-9_-]{0,31}$/', $path),
            ];
        }

        return $items;
    }

    /**
     * @param list<mixed> $raw
     * @return list<array<string, mixed>>
     */
    private static function parseBranchMappings(array $raw): array
    {
        if ($raw === []) {
            throw OrganizationMappingFailure::of('SPEC_NO_BRANCH_MAPPING');
        }

        $items = [];
        $seen = [];
        foreach ($raw as $index => $entry) {
            $path = 'branch_mappings[' . (int) $index . ']';
            $row = self::entry($entry, $path);
            self::rejectUnknown($row, self::BRANCH_MAPPING_FIELDS, $path);

            $id = self::positiveInt($row, 'sube_id', $path);
            if (in_array($id, self::FORBIDDEN_BRANCH_IDS, true)) {
                throw OrganizationMappingFailure::of('SPEC_FORBIDDEN_BRANCH_ID', (string) $id);
            }
            if (isset($seen[$id])) {
                throw OrganizationMappingFailure::of('SPEC_DUPLICATE_BRANCH_MAPPING', (string) $id);
            }
            $seen[$id] = true;

            // Absent approved_ad is the documented way to say "keep the current
            // name": the two branches whose short name was never approved must
            // not be renamed by a default or a guess.
            $approvedAd = array_key_exists('approved_ad', $row) && $row['approved_ad'] !== null
                ? self::text($row, 'approved_ad', 120, $path)
                : null;

            $items[] = [
                'sube_id' => $id,
                'expected_kod' => self::text($row, 'expected_kod', 32, $path),
                'expected_ad' => self::text($row, 'expected_ad', 120, $path),
                'expected_sirket_id' => self::nullableId($row, 'expected_sirket_id', $path),
                'expected_sgk_isveren_id' => self::nullableId($row, 'expected_sgk_isveren_id', $path),
                'target_company_kod' => self::pattern($row, 'target_company_kod', '/^[A-Z0-9][A-Z0-9_-]{0,31}$/', $path),
                'approved_ad' => $approvedAd,
            ];
        }

        return $items;
    }

    /**
     * @param list<mixed> $raw
     * @return list<array<string, mixed>>
     */
    private static function parseLocationMappings(array $raw): array
    {
        $items = [];
        $seen = [];
        foreach ($raw as $index => $entry) {
            $path = 'location_mappings[' . (int) $index . ']';
            $row = self::entry($entry, $path);
            self::rejectUnknown($row, self::LOCATION_MAPPING_FIELDS, $path);

            $id = self::positiveInt($row, 'calisma_lokasyonu_id', $path);
            if (isset($seen[$id])) {
                throw OrganizationMappingFailure::of('SPEC_DUPLICATE_LOCATION_MAPPING', (string) $id);
            }
            $seen[$id] = true;

            // A location whose branch is not provable stays in the spec with a
            // null target: deferred, verified, and explicitly not guessed.
            $items[] = [
                'calisma_lokasyonu_id' => $id,
                'expected_kod' => self::text($row, 'expected_kod', 32, $path),
                'expected_ad' => self::text($row, 'expected_ad', 191, $path),
                'expected_sube_id' => self::nullableId($row, 'expected_sube_id', $path),
                'target_sube_id' => self::nullableId($row, 'target_sube_id', $path),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int>
     */
    private static function parsePreservation(array $raw): array
    {
        self::rejectUnknown($raw, self::PRESERVATION_FIELDS, 'preservation');

        $values = [];
        foreach (self::PRESERVATION_FIELDS as $field) {
            $values[$field] = self::nonNegativeInt($raw, $field, 'preservation');
        }

        return $values;
    }

    /** @param array<string, mixed> $spec */
    private static function assertCrossReferences(array $spec): void
    {
        $companyCodes = [];
        foreach ($spec['companies'] as $company) {
            $companyCodes[$company['kod']] = true;
        }

        foreach ($spec['sgk_mappings'] as $mapping) {
            if (!isset($companyCodes[$mapping['target_company_kod']])) {
                throw OrganizationMappingFailure::of(
                    'SPEC_UNKNOWN_COMPANY_REFERENCE',
                    'sgk_mappings sgk_isveren_id=' . $mapping['sgk_isveren_id']
                );
            }
        }

        $branchIds = [];
        foreach ($spec['branch_mappings'] as $mapping) {
            if (!isset($companyCodes[$mapping['target_company_kod']])) {
                throw OrganizationMappingFailure::of(
                    'SPEC_UNKNOWN_COMPANY_REFERENCE',
                    'branch_mappings sube_id=' . $mapping['sube_id']
                );
            }
            $branchIds[] = $mapping['sube_id'];
        }

        // Every branch the inventory expects must be decided. A partially mapped
        // company/branch tree can never satisfy data_ready, so accepting one here
        // would only move the failure to the apply.
        $missing = array_values(array_diff($spec['metadata']['expected_branch_ids'], $branchIds));
        if ($missing !== []) {
            throw OrganizationMappingFailure::of(
                'SPEC_BRANCH_MAPPING_INCOMPLETE',
                'missing=' . implode(',', $missing)
            );
        }
        $extra = array_values(array_diff($branchIds, $spec['metadata']['expected_branch_ids']));
        if ($extra !== []) {
            throw OrganizationMappingFailure::of(
                'SPEC_BRANCH_MAPPING_UNEXPECTED',
                'unexpected=' . implode(',', $extra)
            );
        }

        // A branch's payroll employer must be decided by the spec, otherwise the
        // commit would leave that employer without a company. Şube şirketi ile SGK
        // işvereninin şirketi ise farklı olabilir (2026-09-15 model): hedef
        // şirketlerinin ayrışması reddedilmez.
        $sgkTargets = [];
        foreach ($spec['sgk_mappings'] as $mapping) {
            $sgkTargets[$mapping['sgk_isveren_id']] = $mapping['target_company_kod'];
        }
        foreach ($spec['branch_mappings'] as $mapping) {
            $sgkId = $mapping['expected_sgk_isveren_id'];
            if ($sgkId === null) {
                continue;
            }
            if (!isset($sgkTargets[$sgkId])) {
                throw OrganizationMappingFailure::of(
                    'SPEC_SGK_MAPPING_INCOMPLETE',
                    'sube_id=' . $mapping['sube_id'] . ' sgk_isveren_id=' . $sgkId
                );
            }
        }

        foreach ($spec['location_mappings'] as $mapping) {
            if ($mapping['target_sube_id'] === null) {
                continue;
            }
            if (!in_array($mapping['target_sube_id'], $branchIds, true)) {
                throw OrganizationMappingFailure::of(
                    'SPEC_UNKNOWN_BRANCH_REFERENCE',
                    'location_mappings calisma_lokasyonu_id=' . $mapping['calisma_lokasyonu_id']
                );
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $raw
     * @param list<string> $allowed
     */
    private static function rejectUnknown(array $raw, array $allowed, string $path): void
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw OrganizationMappingFailure::of('SPEC_UNKNOWN_FIELD', $path . '.' . (string) $key);
            }
        }
    }

    /**
     * @param mixed $entry
     * @return array<string, mixed>
     */
    private static function entry($entry, string $path): array
    {
        if (!is_array($entry) || ($entry !== [] && array_keys($entry) === range(0, count($entry) - 1))) {
            throw OrganizationMappingFailure::of('SPEC_ENTRY_NOT_OBJECT', $path);
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function object(array $raw, string $key, string $path): array
    {
        if (!array_key_exists($key, $raw)) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_MISSING', $path . '.' . $key);
        }

        return self::entry($raw[$key], $path . '.' . $key);
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<mixed>
     */
    private static function listOf(array $raw, string $key, string $path): array
    {
        if (!array_key_exists($key, $raw)) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_MISSING', $path . '.' . $key);
        }
        $value = $raw[$key];
        if (!is_array($value) || ($value !== [] && array_keys($value) !== range(0, count($value) - 1))) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_NOT_LIST', $path . '.' . $key);
        }

        return array_values($value);
    }

    /** @param array<string, mixed> $raw */
    private static function string(array $raw, string $key, string $path): string
    {
        if (!array_key_exists($key, $raw)) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_MISSING', $path . '.' . $key);
        }
        if (!is_string($raw[$key])) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', $path . '.' . $key);
        }

        return $raw[$key];
    }

    /** @param array<string, mixed> $raw */
    private static function pattern(array $raw, string $key, string $pattern, string $path): string
    {
        $value = trim(self::string($raw, $key, $path));
        if (preg_match($pattern, $value) !== 1) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', $path . '.' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function text(array $raw, string $key, int $maxLength, string $path): string
    {
        $value = trim(self::string($raw, $key, $path));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', $path . '.' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function durum(array $raw, string $path): string
    {
        $value = self::string($raw, 'durum', $path);
        if (!in_array($value, ['AKTIF', 'PASIF'], true)) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', $path . '.durum');
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function positiveInt(array $raw, string $key, string $path): int
    {
        if (!array_key_exists($key, $raw)) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_MISSING', $path . '.' . $key);
        }
        if (!is_int($raw[$key]) || $raw[$key] <= 0) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', $path . '.' . $key);
        }

        return $raw[$key];
    }

    /** @param array<string, mixed> $raw */
    private static function nonNegativeInt(array $raw, string $key, string $path): int
    {
        if (!array_key_exists($key, $raw)) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_MISSING', $path . '.' . $key);
        }
        if (!is_int($raw[$key]) || $raw[$key] < 0) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', $path . '.' . $key);
        }

        return $raw[$key];
    }

    /**
     * Explicit null is meaningful here — it is the preimage assertion "this
     * relation is currently unset" — so the key must be present either way.
     *
     * @param array<string, mixed> $raw
     */
    private static function nullableId(array $raw, string $key, string $path): ?int
    {
        if (!array_key_exists($key, $raw)) {
            throw OrganizationMappingFailure::of('SPEC_FIELD_MISSING', $path . '.' . $key);
        }
        if ($raw[$key] === null) {
            return null;
        }

        return self::positiveInt($raw, $key, $path);
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<int>
     */
    private static function intList(array $raw, string $key, string $path): array
    {
        $values = self::listOf($raw, $key, $path);
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0) {
                throw OrganizationMappingFailure::of('SPEC_FIELD_INVALID', $path . '.' . $key);
            }
            if (in_array($value, $ids, true)) {
                throw OrganizationMappingFailure::of('SPEC_DUPLICATE_BRANCH_ID', (string) $value);
            }
            if (in_array($value, self::FORBIDDEN_BRANCH_IDS, true)) {
                throw OrganizationMappingFailure::of('SPEC_FORBIDDEN_BRANCH_ID', (string) $value);
            }
            $ids[] = $value;
        }

        return $ids;
    }
}
