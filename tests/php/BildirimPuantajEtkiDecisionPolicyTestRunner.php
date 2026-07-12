<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';

use Medisa\Api\Services\BildirimPuantajEtkiDecisionPolicy;

$scenarios = [
    ['name' => 'HAZIR apply allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateApply('HAZIR')['allowed'] === true;
    }],
    ['name' => 'HAZIR dismiss allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateDismiss('HAZIR')['allowed'] === true;
    }],
    ['name' => 'INCELEME_GEREKLI apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateApply('INCELEME_GEREKLI')['allowed'] === false;
    }],
    ['name' => 'INCELEME_GEREKLI dismiss allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateDismiss('INCELEME_GEREKLI')['allowed'] === true;
    }],
    ['name' => 'UYGULANDI apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateApply('UYGULANDI')['allowed'] === false;
    }],
    ['name' => 'UYGULANDI dismiss blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateDismiss('UYGULANDI')['allowed'] === false;
    }],
    ['name' => 'YOK_SAYILDI apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateApply('YOK_SAYILDI')['allowed'] === false;
    }],
    ['name' => 'YOK_SAYILDI dismiss blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateDismiss('YOK_SAYILDI')['allowed'] === false;
    }],
    ['name' => 'unknown state apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateApply('UNKNOWN')['allowed'] === false;
    }],
    ['name' => 'unknown state dismiss blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::evaluateDismiss('UNKNOWN')['allowed'] === false;
    }],
    ['name' => 'unknown action not known', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isKnownAction('invalid') === false;
    }],
    ['name' => 'UYGULANDI is terminal', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isTerminalState('UYGULANDI') === true;
    }],
    ['name' => 'YOK_SAYILDI is terminal', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isTerminalState('YOK_SAYILDI') === true;
    }],
    ['name' => 'HAZIR is not terminal', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isTerminalState('HAZIR') === false;
    }],
    ['name' => 'expected_state match', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::validateExpectedState('HAZIR', 'HAZIR')['valid'] === true;
    }],
    ['name' => 'expected_state mismatch', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::validateExpectedState('HAZIR', 'INCELEME_GEREKLI')['valid'] === false;
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
