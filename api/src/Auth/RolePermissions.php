<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Http\JsonResponse;

class RolePermissions
{
    /** @var array<string, string> */
    private static $safeAliases = [
        'PATRON' => 'GENEL_YONETICI',
        'IK_BORDRO' => 'IK_SORUMLUSU',
    ];

    /** @var array<string, array<int, string>> */
    private static $matrix = [
        'GENEL_YONETICI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.import.apply',
            'personeller.update',
            'personeller.detail.view',
            'personeller.ucret.view',
            'personeller.ucret.manage',
            'maas_hesaplama.view',
            'maas_hesaplama.manage',
            'maas_hesaplama_adaylari.view',
            'maas_hesaplama_adaylari.manage',
            'mevzuat_parametreleri.view',
            'mevzuat_parametreleri.manage',
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
            'puantaj.donem_reopen.approve',
            'puantaj.donem_seal.history',
            'puantaj.bildirim_etki.view',
            'puantaj.donem_kapanis.view',
            'puantaj.donem_kapanis.export',
            'puantaj.bildirim_etki.rapor.view',
            'puantaj.bildirim_etki.rapor.export',
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
            'gunluk_bildirim.request_correction',
            'haftalik_mutabakat.view',
            'haftalik_mutabakat.reopen_request',
            'aylik_bolum_onayi.view',
            'aylik_bildirim_onayi.view',
            'genel_yonetici_onayi.view',
            'genel_yonetici_onayi.approve',
            'genel_yonetici_bildirim_onayi.view',
            'genel_yonetici_bildirim_onayi.approve',
            'patron_ack.view',
            'patron_ack.mark_seen',
            'sirket_parametreleri.view',
            'sirket_parametreleri.manage',
            'resmi_tatil_takvimi.view',
            'resmi_tatil_takvimi.manage',
            'bordro_on_izleme.view',
            'bordro_kesinlestirme.approve',
            'personel_bordro_kapsam.view',
            'personel_bordro_kapsam.manage',
            'personel_bordro_kapsam.approve',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.approve',
            'revizyon.reject',
            'revizyon.view_finance_effect',
            'revizyon.view_audit_history',
            'sgk.manuel_kod_override',
            'sgk_karar_paketi.prepare',
            'sgk_karar_paketi.approve',
            'disiplin.view',
            'disiplin.review',
            'disiplin.defense_manage',
            'puantaj.olay_karar.view',
            'arsiv.view',
            'arsiv.download',
            'arsiv.audit.view',
            'retention.view',
            'legal_hold.manage',
            'retention.destruction.request',
            'retention.destruction.approve',
            'retention.destruction.view',
        ],
        'BOLUM_YONETICISI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.import.apply',
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
            'puantaj.donem_reopen.request',
            'puantaj.donem_seal.history',
            'puantaj.bildirim_etki.view',
            'puantaj.donem_kapanis.view',
            'puantaj.bildirim_etki.rapor.view',
            'raporlar.view',
            'finans.view',
            'finans.create',
            'finans.update',
            'finans.cancel',
            'isg.view',
            'aylik-ozet.view',
            'aylik-ozet.review',
            'gunluk_bildirim.request_correction',
            'haftalik_mutabakat.view',
            'haftalik_mutabakat.reopen_request',
            'aylik_bolum_onayi.view',
            'aylik_bolum_onayi.approve',
            'aylik_bildirim_onayi.view',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.view_finance_effect',
            'revizyon.view_audit_history',
            'disiplin.view',
            'disiplin.final_decision',
            'puantaj.olay_karar.decide',
            'puantaj.olay_karar.view',
        ],
        // External accountant: finalized mali/bordro read + export. No operational write.
        'MUHASEBE' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.detail.view',
            'personeller.ucret.view',
            'maas_hesaplama.view',
            'maas_hesaplama_adaylari.view',
            'mevzuat_parametreleri.view',
            'surecler.view',
            'surecler.view.sube',
            'surecler.detail.view',
            'puantaj.view',
            'puantaj.donem_seal.history',
            'puantaj.donem_kapanis.view',
            'puantaj.donem_kapanis.export',
            'puantaj.bildirim_etki.rapor.view',
            'puantaj.bildirim_etki.rapor.export',
            'raporlar.view',
            'finans.view',
            'haftalik_mutabakat.view',
            'bordro_on_izleme.view',
            'sirket_parametreleri.view',
            'resmi_tatil_takvimi.view',
            'personel_bordro_kapsam.view',
            'revizyon.view',
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
            'puantaj.donem_kapanis.view',
            'puantaj.donem_seal.history',
            'puantaj.bildirim_etki.rapor.view',
            'raporlar.view',
            'isg.view',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.view_audit_history',
            'gunluk_bildirim.create',
            'gunluk_bildirim.update_own_open',
            'gunluk_bildirim.submit',
            'gunluk_bildirim.complete_day',
            'haftalik_mutabakat.view',
            'haftalik_mutabakat.approve',
            'aylik_bildirim_onayi.view',
            'aylik_bildirim_onayi.approve',
        ],
        // IK operational owner. SGK prepare-only; no final approve / business decision.
        'IK_SORUMLUSU' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.import.apply',
            'personeller.update',
            'personeller.detail.view',
            'personeller.ucret.view',
            'mevzuat_parametreleri.view',
            'surecler.view',
            'surecler.view.sube',
            'surecler.create',
            'surecler.update',
            'surecler.cancel',
            'surecler.detail.view',
            'bildirimler.view',
            'bildirimler.detail.view',
            'puantaj.view',
            'puantaj.donem_reopen.request',
            'puantaj.donem_reseal',
            'puantaj.donem_seal.history',
            'puantaj.bildirim_etki.view',
            'puantaj.bildirim_etki.generate',
            'puantaj.bildirim_etki.apply',
            'puantaj.bildirim_etki.dismiss',
            'puantaj.bildirim_etki.resolve_conflict',
            'puantaj.donem_kapanis.view',
            'puantaj.bildirim_etki.rapor.view',
            'puantaj.olay_karar.view',
            'disiplin.view',
            'disiplin.review',
            'disiplin.defense_manage',
            'maas_hesaplama.view',
            'maas_hesaplama.manage',
            'maas_hesaplama_adaylari.view',
            'maas_hesaplama_adaylari.manage',
            'raporlar.view',
            'bordro_on_izleme.view',
            'sirket_parametreleri.view',
            'sirket_parametreleri.manage',
            'personel_bordro_kapsam.view',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.view_audit_history',
            'sgk_karar_paketi.prepare',
            'arsiv.view',
            'arsiv.download',
            'retention.view',
        ],
        // Technical visibility — never business approver / legal_hold.manage / destruction.approve.
        'SISTEM_YONETICISI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.detail.view',
            'surecler.view',
            'raporlar.view',
            'arsiv.view',
            'arsiv.download',
            'arsiv.audit.view',
            'retention.view',
            'retention.destruction.view',
        ],
        // Future self-service — zero business permissions this phase.
        'PERSONEL' => [],
        'AUTH_SMOKE_READONLY' => [
            'ops.auth_smoke.read',
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

    /**
     * Single BE normalization boundary.
     * Safe aliases: PATRON→GENEL_YONETICI, IK_BORDRO→IK_SORUMLUSU.
     * Unresolved legacy (SGK_KARAR_ONAY_YETKILISI, IDARI_ISLER) → '' (fail-closed).
     *
     * @return string
     */
    public static function normalizeRole($role)
    {
        $normalized = strtoupper(trim((string) $role));
        if ($normalized === '') {
            return '';
        }

        if (isset(self::$safeAliases[$normalized])) {
            $normalized = self::$safeAliases[$normalized];
        }

        if ($normalized === 'SGK_KARAR_ONAY_YETKILISI' || $normalized === 'IDARI_ISLER') {
            return '';
        }

        if (isset(self::$matrix[$normalized])) {
            return $normalized;
        }

        return '';
    }
}
