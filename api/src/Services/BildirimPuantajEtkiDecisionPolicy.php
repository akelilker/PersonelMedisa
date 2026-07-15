<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

class BildirimPuantajEtkiDecisionPolicy
{
    public const ACTION_APPLY = 'apply';
    public const ACTION_MANUAL_APPLY = 'manual_apply';
    public const ACTION_DISMISS = 'dismiss';

    public const PERMISSION_APPLY = 'puantaj.bildirim_etki.apply';
    public const PERMISSION_DISMISS = 'puantaj.bildirim_etki.dismiss';

    /** @var array<int, string> */
    public static $allowedStates = [
        'HAZIR',
        'INCELEME_GEREKLI',
        'UYGULANDI',
        'YOK_SAYILDI',
    ];

    /** @var array<int, string> */
    public static $terminalStates = [
        'UYGULANDI',
        'YOK_SAYILDI',
    ];

    public static function normalizeState($state)
    {
        return strtoupper(trim((string) $state));
    }

    public static function isAllowedState($state)
    {
        return in_array(self::normalizeState($state), self::$allowedStates, true);
    }

    public static function isTerminalState($state)
    {
        return in_array(self::normalizeState($state), self::$terminalStates, true);
    }

    public static function isKnownAction($action)
    {
        $action = strtolower(trim((string) $action));

        return $action === self::ACTION_APPLY
            || $action === self::ACTION_MANUAL_APPLY
            || $action === self::ACTION_DISMISS;
    }

    public static function permissionForAction($action)
    {
        $action = strtolower(trim((string) $action));
        if ($action === self::ACTION_APPLY || $action === self::ACTION_MANUAL_APPLY) {
            return self::PERMISSION_APPLY;
        }
        if ($action === self::ACTION_DISMISS) {
            return self::PERMISSION_DISMISS;
        }

        return null;
    }

    public static function targetStateForAction($action)
    {
        $action = strtolower(trim((string) $action));
        if ($action === self::ACTION_APPLY) {
            return 'UYGULANDI';
        }
        if ($action === self::ACTION_DISMISS) {
            return 'YOK_SAYILDI';
        }

        return null;
    }

    /** @return array{allowed: bool} */
    public static function evaluateApply($state)
    {
        return ['allowed' => self::isApplyAllowed($state)];
    }

    /** @return array{allowed: bool} */
    public static function evaluateDismiss($state)
    {
        return ['allowed' => self::isDismissAllowed($state)];
    }

    public static function isApplyAllowed($state)
    {
        if (!self::isAllowedState($state) || self::isTerminalState($state)) {
            return false;
        }

        return self::normalizeState($state) === 'HAZIR';
    }

    public static function isManualApplyAllowed($state)
    {
        if (!self::isAllowedState($state) || self::isTerminalState($state)) {
            return false;
        }

        return self::normalizeState($state) === 'INCELEME_GEREKLI';
    }

    public static function isDismissAllowed($state)
    {
        if (!self::isAllowedState($state) || self::isTerminalState($state)) {
            return false;
        }

        $state = self::normalizeState($state);

        return $state === 'HAZIR' || $state === 'INCELEME_GEREKLI';
    }

    /** @return array{valid: bool} */
    public static function validateExpectedState($currentState, $expectedState)
    {
        $current = self::normalizeState($currentState);
        $expected = self::normalizeState($expectedState);
        if ($expected === '' || !self::isAllowedState($expected)) {
            return ['valid' => false];
        }

        return ['valid' => $current === $expected];
    }
}
