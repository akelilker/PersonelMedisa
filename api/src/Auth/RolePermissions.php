<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Http\JsonResponse;

class RolePermissions
{
    /** @var array<string, array<int, string>> */
    private static $matrix = [
        'GENEL_YONETICI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.update',
            'personeller.detail.view',
            'surecler.view',
            'surecler.view.sube',
            'surecler.create',
            'surecler.update',
            'surecler.cancel',
            'surecler.detail.view',
            'bildirimler.view',
            'bildirimler.create',
            'bildirimler.update',
            'bildirimler.cancel',
            'bildirimler.detail.view',
            'puantaj.view',
            'puantaj.update',
            'puantaj.muhurle',
            'raporlar.view',
            'finans.view',
            'finans.create',
            'finans.update',
            'finans.cancel',
            'isg.view',
            'yonetim-paneli.view',
            'yonetim-paneli.manage',
            'aylik-ozet.view',
            'aylik-ozet.executive_ack',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.approve',
            'revizyon.reject',
            'revizyon.view_finance_effect',
            'revizyon.view_audit_history',
        ],
        'BOLUM_YONETICISI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.update',
            'personeller.detail.view',
            'surecler.view',
            'surecler.view.sube',
            'surecler.create',
            'surecler.update',
            'surecler.cancel',
            'surecler.detail.view',
            'bildirimler.view',
            'bildirimler.create',
            'bildirimler.update',
            'bildirimler.cancel',
            'bildirimler.detail.view',
            'puantaj.view',
            'puantaj.update',
            'puantaj.muhurle',
            'raporlar.view',
            'finans.view',
            'finans.create',
            'finans.update',
            'finans.cancel',
            'isg.view',
            'aylik-ozet.view',
            'aylik-ozet.review',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.view_finance_effect',
            'revizyon.view_audit_history',
        ],
        'MUHASEBE' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.update',
            'personeller.detail.view',
            'surecler.view',
            'surecler.view.sube',
            'surecler.create',
            'surecler.update',
            'surecler.cancel',
            'surecler.detail.view',
            'bildirimler.view',
            'bildirimler.create',
            'bildirimler.update',
            'bildirimler.cancel',
            'bildirimler.detail.view',
            'puantaj.view',
            'puantaj.update',
            'raporlar.view',
            'finans.view',
            'finans.create',
            'finans.update',
            'finans.cancel',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.view_finance_effect',
            'revizyon.view_audit_history',
        ],
        'BIRIM_AMIRI' => [
            'personeller.view.sube',
            'personeller.detail.view',
            'surecler.view.sube',
            'surecler.detail.view',
            'bildirimler.view',
            'bildirimler.create',
            'bildirimler.update',
            'bildirimler.cancel',
            'bildirimler.detail.view',
            'puantaj.view',
            'puantaj.amir_kontrol',
            'raporlar.view',
            'isg.view',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.view_audit_history',
        ],
    ];

    /** @param array<string, mixed> $user */
    public static function has(array $user, $permission)
    {
        $role = self::normalizeRole(isset($user['rol']) ? (string) $user['rol'] : '');
        $permission = trim((string) $permission);
        if ($role === '' || $permission === '') {
            return false;
        }

        if (!isset(self::$matrix[$role])) {
            return false;
        }

        return in_array($permission, self::$matrix[$role], true);
    }

    /** @param array<string, mixed> $user */
    public static function assert(array $user, $permission)
    {
        if (!self::has($user, $permission)) {
            JsonResponse::forbidden();
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, string> $permissions
     */
    public static function assertAny(array $user, array $permissions)
    {
        foreach ($permissions as $permission) {
            if (self::has($user, (string) $permission)) {
                return;
            }
        }

        JsonResponse::forbidden();
    }

    private static function normalizeRole($role)
    {
        $normalized = strtoupper(trim((string) $role));
        if ($normalized === '') {
            return '';
        }

        if (isset(self::$matrix[$normalized])) {
            return $normalized;
        }

        return '';
    }
}
