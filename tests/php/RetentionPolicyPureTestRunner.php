<?php

declare(strict_types=1);

/**
 * Phase C: RetentionPolicyService pure calendar/category tests (no MySQL).
 * php tests/php/RetentionPolicyPureTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionPolicyService;

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

// Codes present
foreach ([
    RetentionPolicyService::CODE_UNKNOWN_CATEGORY,
    RetentionPolicyService::CODE_PERIOD_NOT_CLOSED,
    RetentionPolicyService::CODE_TERMINATION_DATE_MISSING,
    RetentionPolicyService::CODE_RETENTION_NOT_MATURE,
    RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE,
    RetentionPolicyService::CODE_NO_GM_APPROVAL,
    RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
    RetentionPolicyService::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED,
    RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION,
] as $code) {
    rpPureAssert($code !== '', 'code ' . $code);
}

echo "verify-retention-policy-pure: OK\n";
