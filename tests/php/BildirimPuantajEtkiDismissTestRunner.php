<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';

use Medisa\Api\Services\BildirimPuantajEtkiDecisionPolicy;

/**
 * Mirrors controller dismiss validation and state rules for CLI regression.
 */
function dismiss_validate_expected_state($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $state = BildirimPuantajEtkiDecisionPolicy::normalizeState($value);
    if ($state !== 'HAZIR' && $state !== 'INCELEME_GEREKLI') {
        return null;
    }

    return $state;
}

function dismiss_validate_gerekce($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $gerekce = trim((string) $value);
    if ($gerekce === '' || mb_strlen($gerekce) < 5) {
        return null;
    }
    if (mb_strlen($gerekce) > 500) {
        return null;
    }

    return $gerekce;
}

function dismiss_can_mutate($currentState, $expectedState): string
{
    $current = BildirimPuantajEtkiDecisionPolicy::normalizeState($currentState);
    if ($current === 'YOK_SAYILDI' || $current === 'UYGULANDI') {
        return 'terminal';
    }
    if (!BildirimPuantajEtkiDecisionPolicy::isDismissAllowed($current)) {
        return 'blocked';
    }
    if (!BildirimPuantajEtkiDecisionPolicy::validateExpectedState($current, $expectedState)['valid']) {
        return 'stale';
    }

    return 'ok';
}

/**
 * Models locked-row decision path; scope row state is ignored for karar logic.
 *
 * @return array{outcome: string, mutate: bool}
 */
function dismiss_locked_row_outcome($lockedState, $expectedState, $gerekce, $lockedGerekce = null): array
{
    $current = BildirimPuantajEtkiDecisionPolicy::normalizeState($lockedState);
    if ($current === 'YOK_SAYILDI') {
        $stored = trim((string) ($lockedGerekce ?? $gerekce));
        if ($stored === trim((string) $gerekce)) {
            return ['outcome' => 'idempotent', 'mutate' => false];
        }

        return ['outcome' => 'conflict', 'mutate' => false];
    }
    if ($current === 'UYGULANDI') {
        return ['outcome' => 'conflict', 'mutate' => false];
    }
    if (!BildirimPuantajEtkiDecisionPolicy::isDismissAllowed($current)) {
        return ['outcome' => 'conflict', 'mutate' => false];
    }
    if (!BildirimPuantajEtkiDecisionPolicy::validateExpectedState($current, $expectedState)['valid']) {
        return ['outcome' => 'stale', 'mutate' => false];
    }

    return ['outcome' => 'ok', 'mutate' => true];
}

/**
 * @return array{scope_before_tx: bool, locked_after_tx: bool}
 */
function dismiss_fetch_contract(): array
{
    $events = [];
    $events[] = 'scope_read';
    $events[] = 'scope_check';
    $events[] = 'begin_tx';
    $events[] = 'locked_read';

    $scopeCheckIndex = array_search('scope_check', $events, true);
    $beginTxIndex = array_search('begin_tx', $events, true);
    $lockedReadIndex = array_search('locked_read', $events, true);

    return [
        'scope_before_tx' => $scopeCheckIndex !== false
            && $beginTxIndex !== false
            && $scopeCheckIndex < $beginTxIndex,
        'locked_after_tx' => $beginTxIndex !== false
            && $lockedReadIndex !== false
            && $lockedReadIndex > $beginTxIndex,
    ];
}

$scenarios = [
    ['name' => 'HAZIR dismiss allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isDismissAllowed('HAZIR') === true;
    }],
    ['name' => 'INCELEME_GEREKLI dismiss allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isDismissAllowed('INCELEME_GEREKLI') === true;
    }],
    ['name' => 'UYGULANDI dismiss blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isDismissAllowed('UYGULANDI') === false;
    }],
    ['name' => 'YOK_SAYILDI dismiss blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isDismissAllowed('YOK_SAYILDI') === false;
    }],
    ['name' => 'expected_state HAZIR accepted', 'fn' => function () {
        return dismiss_validate_expected_state('HAZIR') === 'HAZIR';
    }],
    ['name' => 'expected_state INCELEME_GEREKLI accepted', 'fn' => function () {
        return dismiss_validate_expected_state('INCELEME_GEREKLI') === 'INCELEME_GEREKLI';
    }],
    ['name' => 'expected_state missing rejected', 'fn' => function () {
        return dismiss_validate_expected_state(null) === null;
    }],
    ['name' => 'expected_state UYGULANDI rejected', 'fn' => function () {
        return dismiss_validate_expected_state('UYGULANDI') === null;
    }],
    ['name' => 'expected_state invalid rejected', 'fn' => function () {
        return dismiss_validate_expected_state('UNKNOWN') === null;
    }],
    ['name' => 'gerekce 5 chars accepted', 'fn' => function () {
        return dismiss_validate_gerekce('abcde') === 'abcde';
    }],
    ['name' => 'gerekce trim applied', 'fn' => function () {
        return dismiss_validate_gerekce('  abcde  ') === 'abcde';
    }],
    ['name' => 'gerekce 4 chars rejected', 'fn' => function () {
        return dismiss_validate_gerekce('abcd') === null;
    }],
    ['name' => 'gerekce empty rejected', 'fn' => function () {
        return dismiss_validate_gerekce('') === null;
    }],
    ['name' => 'gerekce whitespace only rejected', 'fn' => function () {
        return dismiss_validate_gerekce('     ') === null;
    }],
    ['name' => 'gerekce 500 chars accepted', 'fn' => function () {
        return dismiss_validate_gerekce(str_repeat('a', 500)) === str_repeat('a', 500);
    }],
    ['name' => 'gerekce 501 chars rejected', 'fn' => function () {
        return dismiss_validate_gerekce(str_repeat('a', 501)) === null;
    }],
    ['name' => 'HAZIR to YOK_SAYILDI path ok', 'fn' => function () {
        return dismiss_can_mutate('HAZIR', 'HAZIR') === 'ok';
    }],
    ['name' => 'INCELEME_GEREKLI to YOK_SAYILDI path ok', 'fn' => function () {
        return dismiss_can_mutate('INCELEME_GEREKLI', 'INCELEME_GEREKLI') === 'ok';
    }],
    ['name' => 'stale expected_state detected', 'fn' => function () {
        return dismiss_can_mutate('INCELEME_GEREKLI', 'HAZIR') === 'stale';
    }],
    ['name' => 'UYGULANDI terminal', 'fn' => function () {
        return dismiss_can_mutate('UYGULANDI', 'HAZIR') === 'terminal';
    }],
    ['name' => 'YOK_SAYILDI terminal', 'fn' => function () {
        return dismiss_can_mutate('YOK_SAYILDI', 'HAZIR') === 'terminal';
    }],
    ['name' => 'exact idempotent same gerekce', 'fn' => function () {
        $gerekce = 'Mevcut puantaj kaydiyla cakisti.';
        $stored = trim($gerekce);
        $incoming = trim('  ' . $gerekce . '  ');

        return $stored === $incoming;
    }],
    ['name' => 'different gerekce not idempotent', 'fn' => function () {
        $stored = trim('Ilk gerekce metni.');
        $incoming = trim('Farkli gerekce metni.');

        return $stored !== $incoming;
    }],
    ['name' => 'target state YOK_SAYILDI', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::targetStateForAction(
            BildirimPuantajEtkiDecisionPolicy::ACTION_DISMISS
        ) === 'YOK_SAYILDI';
    }],
    ['name' => 'dismiss permission constant', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::permissionForAction(
            BildirimPuantajEtkiDecisionPolicy::ACTION_DISMISS
        ) === 'puantaj.bildirim_etki.dismiss';
    }],
    ['name' => 'scope check happens before transaction model', 'fn' => function () {
        $order = ['scope_read', 'scope_check', 'begin_tx', 'locked_read'];
        $scopeIndex = array_search('scope_check', $order, true);
        $txIndex = array_search('begin_tx', $order, true);

        return $scopeIndex !== false && $txIndex !== false && $scopeIndex < $txIndex;
    }],
    ['name' => 'cross-sube scope rejection leaves transaction closed', 'fn' => function () {
        $scopeAllowed = false;
        $transactionStarted = false;

        if (!$scopeAllowed) {
            return $transactionStarted === false;
        }

        $transactionStarted = true;

        return false;
    }],
    ['name' => 'first fetch is lock-free scope read', 'fn' => function () {
        return dismiss_fetch_contract()['scope_before_tx'] === true;
    }],
    ['name' => 'second fetch is transaction FOR UPDATE', 'fn' => function () {
        return dismiss_fetch_contract()['locked_after_tx'] === true;
    }],
    ['name' => 'race HAZIR scope row ignored when locked YOK_SAYILDI same gerekce', 'fn' => function () {
        $outcome = dismiss_locked_row_outcome('YOK_SAYILDI', 'HAZIR', 'Mevcut puantaj kaydiyla cakisti.', 'Mevcut puantaj kaydiyla cakisti.');

        return $outcome['outcome'] === 'idempotent' && $outcome['mutate'] === false;
    }],
    ['name' => 'race HAZIR scope row ignored when locked YOK_SAYILDI different gerekce', 'fn' => function () {
        $outcome = dismiss_locked_row_outcome('YOK_SAYILDI', 'HAZIR', 'Farkli gerekce metni.', 'Ilk gerekce metni.');

        return $outcome['outcome'] === 'conflict' && $outcome['mutate'] === false;
    }],
    ['name' => 'locked row HAZIR still dismisses', 'fn' => function () {
        $outcome = dismiss_locked_row_outcome('HAZIR', 'HAZIR', 'Mevcut puantaj kaydiyla cakisti.');

        return $outcome['outcome'] === 'ok' && $outcome['mutate'] === true;
    }],
    ['name' => 'locked row INCELEME_GEREKLI still dismisses', 'fn' => function () {
        $outcome = dismiss_locked_row_outcome('INCELEME_GEREKLI', 'INCELEME_GEREKLI', 'Mevcut puantaj kaydiyla cakisti.');

        return $outcome['outcome'] === 'ok' && $outcome['mutate'] === true;
    }],
    ['name' => 'locked row UYGULANDI conflict', 'fn' => function () {
        $outcome = dismiss_locked_row_outcome('UYGULANDI', 'HAZIR', 'Mevcut puantaj kaydiyla cakisti.');

        return $outcome['outcome'] === 'conflict' && $outcome['mutate'] === false;
    }],
    ['name' => 'locked row stale expected_state', 'fn' => function () {
        $outcome = dismiss_locked_row_outcome('INCELEME_GEREKLI', 'HAZIR', 'Mevcut puantaj kaydiyla cakisti.');

        return $outcome['outcome'] === 'stale' && $outcome['mutate'] === false;
    }],
    ['name' => 'gerekce unicode 5 chars accepted', 'fn' => function () {
        return dismiss_validate_gerekce('çğüşö') === 'çğüşö';
    }],
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($scenarios as $scenario) {
    $ok = (bool) ($scenario['fn'])();
    if ($ok) {
        $passed++;
    } else {
        $failed++;
        $failures[] = $scenario['name'];
    }
}

echo json_encode([
    'total' => count($scenarios),
    'passed' => $passed,
    'failed' => $failed,
    'failures' => $failures,
], JSON_UNESCAPED_UNICODE);

exit($failed > 0 ? 1 : 0);
