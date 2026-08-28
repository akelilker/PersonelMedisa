<?php

declare(strict_types=1);

/**
 * Phase C: RetentionPolicyService pure calendar/category tests (no MySQL).
 * php tests/php/RetentionPolicyPureTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use Medisa\Api\Services\Retention\RetentionSchemaGate;

function rpPureAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

// Catalog
rpPureAssert(RetentionCategories::isKnown(RetentionCategories::PUANTAJ), 'known PUANTAJ');
rpPureAssert(RetentionCategories::isKnown(RetentionCategories::PERSONEL_OZLUK), 'known PERSONEL_OZLUK');
rpPureAssert(!RetentionCategories::isKnown('XYZ_UNKNOWN'), 'unknown category');
rpPureAssert(
    RetentionCategories::triggerTypeForCategory(RetentionCategories::BORDRO) === RetentionCategories::TRIGGER_PERIOD_CLOSURE,
    'BORDRO period closure'
);
rpPureAssert(
    RetentionCategories::triggerTypeForCategory(RetentionCategories::DISIPLIN) === RetentionCategories::TRIGGER_TERMINATION_DATE,
    'DISIPLIN termination'
);
rpPureAssert(
    RetentionCategories::triggerTypeForCategory('NOPE') === null,
    'unknown trigger null'
);

// Calendar +10 years (not 3650 days)
$leap = DateTime::createFromFormat('Y-m-d', '2024-02-29');
rpPureAssert($leap !== false, 'parse leap');
$untilLeap = RetentionPolicyService::calculateRetentionUntil($leap);
rpPureAssert($untilLeap === '2034-03-01' || $untilLeap === '2034-02-28' || $untilLeap === '2034-02-29', 'leap +10 calendar years');

$plain = DateTime::createFromFormat('Y-m-d', '2015-06-15');
rpPureAssert($plain !== false, 'parse plain');
rpPureAssert(
    RetentionPolicyService::calculateRetentionUntil($plain) === '2025-06-15',
    'plain +10 calendar years'
);

$endYear = DateTime::createFromFormat('Y-m-d', '2010-12-31');
rpPureAssert($endYear !== false, 'parse end year');
rpPureAssert(
    RetentionPolicyService::calculateRetentionUntil($endYear) === '2020-12-31',
    'year-end +10 calendar years'
);

// Policy wording
rpPureAssert(
    strpos(RetentionCategories::POLICY_NOTE, 'Medisa saklama politikası') !== false
        || strpos(RetentionCategories::POLICY_NOTE, 'Medisa saklama politikasi') !== false,
    'policy note company wording'
);
rpPureAssert(strpos(strtolower(RetentionCategories::POLICY_NOTE), 'kanunen') === false, 'no statutory kanunen');
rpPureAssert(RetentionCategories::POLICY_RETENTION_YEARS === 10, 'policy years 10');

// Unified minimum 10-year floor: no category may retain for less.
rpPureAssert(RetentionCategories::MIN_RETENTION_YEARS === 10, 'min retention years 10');
$declared = RetentionCategories::declaredRetentionYears();
rpPureAssert(count($declared) === 15, 'declared retention map covers 15 categories');
foreach (RetentionCategories::all() as $cat) {
    rpPureAssert(isset($declared[$cat]), 'declared years present ' . $cat);
    rpPureAssert(
        RetentionCategories::retentionYearsForCategory($cat) >= RetentionCategories::MIN_RETENTION_YEARS,
        'effective years >= floor ' . $cat
    );
}
// G) a shorter declared period can never lower the effective floor
rpPureAssert(
    RetentionCategories::retentionYearsForCategory('UNDECLARED_CATEGORY') === 10,
    'undeclared category falls back to 10-year floor'
);
// H) a longer declared period is preserved, never shortened
$floorProbe = DateTime::createFromFormat('Y-m-d', '2026-01-01');
rpPureAssert($floorProbe !== false, 'parse floor probe');
rpPureAssert(
    RetentionPolicyService::calculateRetentionUntil($floorProbe, RetentionCategories::BORDRO) === '2036-01-01',
    'BORDRO effective retention = anchor + 10 years'
);
rpPureAssert(
    RetentionPolicyService::calculateRetentionUntil($floorProbe) === '2036-01-01',
    'category-less retention = anchor + 10 years'
);

// Real closure example: hire 2010, termination 2026 → earliest maturity 2036.
$hire = DateTime::createFromFormat('Y-m-d', '2010-03-01');
$termination = DateTime::createFromFormat('Y-m-d', '2026-06-30');
rpPureAssert($hire !== false && $termination !== false, 'parse lifecycle dates');
// A/B) the 2010 document date must never drive maturity for personnel-linked data
rpPureAssert(
    RetentionPolicyService::calculateRetentionUntil($hire, RetentionCategories::PERSONEL_OZLUK) === '2020-03-01',
    'raw 2010 document age alone matures in 2020 (must not be the anchor)'
);
$terminationUntil = RetentionPolicyService::calculateRetentionUntil(
    $termination,
    RetentionCategories::PERSONEL_OZLUK
);
rpPureAssert($terminationUntil === '2036-06-30', 'termination anchor matures 2036');
rpPureAssert($terminationUntil > '2027-12-31', 'not mature in 2026/2027');
rpPureAssert($terminationUntil > '2035-12-31', 'not mature in 2035');

// Personnel anchor floor is wired into the eligibility path (fail-closed source contract).
$policySrc = file_get_contents(__DIR__ . '/../../api/src/Services/Retention/RetentionPolicyService.php');
rpPureAssert(is_string($policySrc) && $policySrc !== '', 'read policy source');
rpPureAssert(
    strpos($policySrc, 'applyPersonnelAnchorFloor') !== false,
    'eligibility applies personnel anchor floor'
);
rpPureAssert(
    strpos($policySrc, 'self::applyPersonnelAnchorFloor(') !== false,
    'anchor floor invoked in eligibility path'
);
// D/E) active employee and missing exit date both resolve to no anchor → fail-closed
rpPureAssert(
    strpos($policySrc, "if (\$termination === null) {\n            throw new RuntimeException(self::CODE_TERMINATION_DATE_MISSING);") !== false,
    'missing/active termination anchor throws fail-closed'
);
// I) latest applicable anchor wins
rpPureAssert(
    strpos($policySrc, "if (\$termination > (string) \$trigger['trigger_date']) {") !== false,
    'latest applicable anchor wins'
);
rpPureAssert(
    strpos($policySrc, "return \$aktifDurum === 'AKTIF'") !== false
        || strpos($policySrc, "if (\$aktifDurum === 'AKTIF') {\n            return null;") !== false,
    'active employee has no termination anchor'
);
// F) legal hold remains an independent fail-closed gate
rpPureAssert(
    strpos($policySrc, 'hasActiveLegalHold') !== false
        && strpos($policySrc, 'CODE_LEGAL_HOLD_ACTIVE') !== false,
    'legal hold gate present'
);
// No blind created_at + 10 year shortcut anywhere in the policy owner.
rpPureAssert(
    preg_match('/created_at[^\n]{0,40}\+\s*10/i', $policySrc) !== 1,
    'no created_at + 10 year shortcut'
);

// Codes present (Phase C final integrity matrix)
foreach ([
    RetentionPolicyService::CODE_UNKNOWN_CATEGORY,
    RetentionPolicyService::CODE_PERIOD_NOT_CLOSED,
    RetentionPolicyService::CODE_TERMINATION_DATE_MISSING,
    RetentionPolicyService::CODE_RETENTION_NOT_MATURE,
    RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE,
    RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
    RetentionPolicyService::CODE_ARCHIVE_MANIFEST_MISSING,
    RetentionPolicyService::CODE_INTEGRITY_UNKNOWN,
    RetentionPolicyService::CODE_SCHEMA_NOT_READY,
    RetentionPolicyService::CODE_SOURCE_CONTEXT_CHANGED,
    RetentionPolicyService::CODE_SNAPSHOT_INCOMPLETE,
    RetentionPolicyService::CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE,
    RetentionPolicyService::CODE_RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED,
    RetentionPolicyService::CODE_ARCHIVED_PERSONEL_READ_ONLY,
    RetentionPolicyService::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST,
    RetentionPolicyService::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED,
    RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION,
    RetentionSchemaGate::CODE_SCHEMA_NOT_READY,
] as $code) {
    rpPureAssert($code !== '', 'code ' . $code);
}

// Clock override (tests only)
RetentionClock::clearOverride();
$now = RetentionClock::now();
rpPureAssert($now instanceof DateTimeImmutable, 'clock now returns DateTimeImmutable');
RetentionClock::setOverride(new DateTimeImmutable('2030-01-15'));
rpPureAssert(RetentionClock::now()->format('Y-m-d') === '2030-01-15', 'clock override');
RetentionClock::clearOverride();
rpPureAssert(RetentionClock::now()->format('Y-m-d') === date('Y-m-d'), 'clock clear');

echo "verify-retention-policy-pure: OK\n";
