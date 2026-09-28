<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Http\JsonResponse;

class RolePermissions
{
    /**
     * bildirimler.cancel is intentionally absent from every role: the cancel owner
     * (BildirimlerController::cancel, POST /bildirimler/{id}/iptal) gates on
     * gunluk_bildirim.update_own_open, so the grant was never read by any route,
     * controller or frontend gate. Re-adding it would grant nothing.
     *
     * @var array<string, array<int, string>>
     */
    private static $matrix = [
        'GENEL_YONETICI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.import.apply',
            'personeller.update',
            'personeller.reaktif',
            'personeller.test_fixture.classify',
            'personeller.test_fixture.archive',
            'personeller.test_fixture.purge',
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
            'bildirimler.detail.view',
            'bugun_personel_durumu.view',
            'puantaj.view',
            'puantaj.update',
            'puantaj.donem_muhurle',
            'puantaj.haftalik_kapanis.manage',
            'fazla_calisma_odeme_tercihi.manage',
            'serbest_zaman.manage',
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
            'gunluk_bildirim.correct_scoped',
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
            'yillik_izin_hak_duzeltme.manage',
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
            'retention.destruction.execute',
            'retention.destruction.view',
            'qr.kiosk.display',
            'attendance.correction.decide',
            'duyurular.manage',
        ],
        // Branch-level operational management, scoped by explicit user_subeler (fail-closed).
        // Enters and submits branch operational data; never central payroll finalization,
        // company-wide SGK/finance decisions, or user/system administration.
        'SUBE_YONETICISI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            // personeller.import.apply intentionally absent: bulk import apply is a central
            // data-load capability, not branch-level single-personnel data entry.
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
            'bildirimler.detail.view',
            'puantaj.view',
            'puantaj.update',
            // Branch-scoped payroll input: both writes load the target personel/snapshot from
            // the DB and assert SubeScope::assertPersonelAccess, so they stay inside user_subeler.
            'fazla_calisma_odeme_tercihi.manage',
            'serbest_zaman.manage',
            // puantaj.donem_muhurle / puantaj.haftalik_kapanis.manage intentionally absent:
            // period sealing and weekly closing are central closing decisions, not branch input.
            'puantaj.donem_reopen.request',
            'puantaj.donem_seal.history',
            'puantaj.bildirim_etki.view',
            'puantaj.donem_kapanis.view',
            'puantaj.bildirim_etki.rapor.view',
            'raporlar.view',
            'finans.view',
            'isg.view',
            'aylik-ozet.view',
            // aylik-ozet.review intentionally absent: it is an accepted alternative gate for
            // the aylikOzetBolumOnay write, so keeping it would re-open the removed approval.
            'gunluk_bildirim.request_correction',
            'haftalik_mutabakat.view',
            'haftalik_mutabakat.reopen_request',
            'aylik_bolum_onayi.view',
            // aylik_bolum_onayi.approve intentionally absent: the underlying write records no
            // actor, so separation of duties cannot be proven on that path.
            'aylik_bildirim_onayi.view',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            // revizyon.view_finance_effect intentionally absent: it unmasks bordro effect
            // labels while this role holds no bordro read grant at all.
            'revizyon.view_audit_history',
            'disiplin.view',
            'disiplin.final_decision',
            'puantaj.olay_karar.decide',
            'puantaj.olay_karar.view',
            'qr.kiosk.display',
        ],
        // Department operational management within assigned bolumler only.
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
            'bildirimler.detail.view',
            'puantaj.view',
            'puantaj.update',
            'puantaj.donem_muhurle',
            'puantaj.haftalik_kapanis.manage',
            'fazla_calisma_odeme_tercihi.manage',
            'serbest_zaman.manage',
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
            // Explicit SGK final approve only — does not inherit GENEL_YONETICI matrix.
            'sgk_karar_paketi.approve',
            'attendance.correction.decide',
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
            'attendance.correction.decide',
        ],
        // IK operational owner. SGK prepare-only; no final approve / business decision.
        'IK_SORUMLUSU' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.create',
            'personeller.import.apply',
            'personeller.update',
            'personeller.reaktif',
            'personeller.test_fixture.classify',
            'personeller.test_fixture.archive',
            'personeller.test_fixture.purge',
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
            'bugun_personel_durumu.view',
            'haftalik_mutabakat.view',
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
            'personel_bordro_kapsam.manage',
            'revizyon.view',
            'revizyon.create',
            'revizyon.submit',
            'revizyon.cancel',
            'revizyon.view_finance_effect',
            'revizyon.view_audit_history',
            'sgk_karar_paketi.prepare',
            'yillik_izin_hak_duzeltme.manage',
            'gunluk_bildirim.correct_scoped',
            'arsiv.view',
            'arsiv.download',
            'retention.view',
            'duyurular.manage',
        ],
        // IT Müdürü: broad troubleshooting READ + yonetim-paneli.manage (users/roles/subeler).
        // Never business approver / policy owner / domain data writer.
        'SISTEM_YONETICISI' => [
            'personeller.view',
            'personeller.view.sube',
            'personeller.detail.view',
            'personeller.ucret.view',
            'mevzuat_parametreleri.view',
            'surecler.view',
            'surecler.view.sube',
            'surecler.detail.view',
            'bildirimler.view',
            'bildirimler.detail.view',
            'bugun_personel_durumu.view',
            'puantaj.view',
            'puantaj.donem_seal.history',
            'puantaj.bildirim_etki.view',
            'puantaj.donem_kapanis.view',
            'puantaj.donem_kapanis.export',
            'puantaj.bildirim_etki.rapor.view',
            'puantaj.bildirim_etki.rapor.export',
            'puantaj.olay_karar.view',
            'disiplin.view',
            'maas_hesaplama.view',
            'maas_hesaplama_adaylari.view',
            'raporlar.view',
            'finans.view',
            'isg.view',
            'yonetim-paneli.view',
            'yonetim-paneli.manage',
            'aylik-ozet.view',
            'haftalik_mutabakat.view',
            'aylik_bildirim_onayi.view',
            'aylik_bolum_onayi.view',
            'genel_yonetici_onayi.view',
            'genel_yonetici_bildirim_onayi.view',
            'patron_ack.view',
            'sirket_parametreleri.view',
            'resmi_tatil_takvimi.view',
            'bordro_on_izleme.view',
            'personel_bordro_kapsam.view',
            'revizyon.view',
            'revizyon.view_finance_effect',
            'revizyon.view_audit_history',
            'arsiv.view',
            'arsiv.download',
            'arsiv.audit.view',
            'retention.view',
            'retention.destruction.view',
            'qr.kiosk.display',
        ],
        // Self-service read surfaces (S3B). No broad personeller.* / puantaj.view.
        // Canonical self-service-only role; also the reusable personnel-linked baseline set.
        'PERSONEL' => [
            'self_service.view',
            'self_service.puantaj.view',
            'self_service.yillik_izin.view',
            'self_service.fazla_calisma.view',
            'self_service.qr.scan',
            'self_service.qr.events.view',
            'self_service.attendance.correct',
        ],
        'AUTH_SMOKE_READONLY' => [
            'ops.auth_smoke.read',
        ],
    ];

    /**
     * IK_PERSONELI is derived from IK_SORUMLUSU minus the permissions that carry
     * İK owner authority, so the subset relation is guaranteed by construction
     * instead of by a second hand-maintained list that could silently drift above
     * its owner.
     *
     * Removed here: period reseal, SGK decision-package preparation, company
     * parameter and payroll-scope management, salary-calculation management.
     * These are the İK sorumlusu's own control/approval surfaces; an İK personeli
     * that needed one of them would have to be promoted, not widened.
     *
     * Organisational reach is *not* expressed here — permission answers "what",
     * OrgScope/HrWriteScope answer "on which company".
     *
     * @var array<int, string>
     */
    const IK_PERSONELI_WITHHELD_PERMISSIONS = [
        'puantaj.donem_reseal',
        'sgk_karar_paketi.prepare',
        'sirket_parametreleri.manage',
        'personel_bordro_kapsam.manage',
        'maas_hesaplama.manage',
        'maas_hesaplama_adaylari.manage',
        'duyurular.manage',
    ];

    /**
     * Canonical collar that may use self-service QR/kart okutma.
     * Owner chain: personeller.personel_tipi_id → personel_tipleri.ad.
     * `ucret_tipi` is intentionally NOT a collar source (no business mapping).
     */
    public const QR_SELF_SERVICE_COLLAR = 'Mavi Yaka';

    /**
     * Self-service QR/kart okutma capabilities.
     *
     * Role-independent: decided by the personnel binding + canonical collar
     * (`hasQrSelfServiceEntitlement`), never by the application role. A bound
     * BIRIM_AMIRI / BOLUM_YONETICISI keeps its management matrix and additionally
     * gains its own entry/exit scanning right. Beyaz Yaka / Diğer / unknown
     * collar fails closed.
     *
     * @var array<int, string>
     */
    public const QR_SELF_SERVICE_PERMISSIONS = [
        'self_service.qr.scan',
        'self_service.qr.events.view',
    ];

    /** @var array<string, array<int, string>>|null */
    private static $resolvedMatrix = null;

    /** @param mixed $permission */
    public static function isQrSelfServicePermission($permission)
    {
        return in_array(trim((string) $permission), self::QR_SELF_SERVICE_PERMISSIONS, true);
    }

    /**
     * Canonical collar business value → QR eligibility. Fail-closed: only an
     * explicit "Mavi Yaka" (case/whitespace tolerant) is eligible.
     *
     * @param mixed $collarAd
     */
    public static function collarAllowsQrSelfService($collarAd)
    {
        if (!is_string($collarAd) && !is_int($collarAd) && !is_float($collarAd)) {
            return false;
        }
        $given = self::normalizeCollar($collarAd);
        if ($given === '') {
            return false;
        }

        return $given === self::normalizeCollar(self::QR_SELF_SERVICE_COLLAR);
    }

    /**
     * Canonical collar decision from the DB-authoritative auth user collar.
     * Missing / null / unresolvable collar → denied.
     *
     * @param array<string, mixed> $user
     */
    public static function personelCollarAllowsQr(array $user)
    {
        return self::collarAllowsQrSelfService($user['personel_tipi_ad'] ?? null);
    }

    /**
     * Role-independent QR/kart okutma entitlement.
     *
     * Kanonik iş kuralı: kendi giriş/çıkışını okutma hakkı bir *çalışan kapsamı*
     * kararıdır, uygulama rolü kararı değildir. Bağlı ve aktif personeli kanonik
     * "Mavi Yaka" collar'ında olan her hesap bu hakkı alır — yönetici rolleri
     * (BIRIM_AMIRI / BOLUM_YONETICISI / SUBE_YONETICISI ...) dahil. Bu kullanıcı
     * QR okutacak diye PERSONEL rolüne düşürülmez ve yönetim yetkileri korunur.
     *
     * Fail-closed: bağlantı yok ya da collar kanonik değer değilse hak verilmez.
     *
     * @param array<string, mixed> $user
     */
    public static function hasQrSelfServiceEntitlement(array $user)
    {
        return self::hasPersonnelLinkedSelfServiceEligibility($user)
            && self::personelCollarAllowsQr($user);
    }

    /** @param mixed $value */
    private static function normalizeCollar($value)
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim((string) $value));
        $text = $collapsed === null ? trim((string) $value) : $collapsed;

        return function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
    }

    /**
     * Canonical role → permission map, including the derived IK_PERSONELI row.
     *
     * @return array<string, array<int, string>>
     */
    private static function matrix()
    {
        if (self::$resolvedMatrix !== null) {
            return self::$resolvedMatrix;
        }

        $matrix = self::$matrix;
        $matrix['IK_PERSONELI'] = array_values(array_diff(
            $matrix['IK_SORUMLUSU'],
            self::IK_PERSONELI_WITHHELD_PERMISSIONS
        ));
        self::$resolvedMatrix = $matrix;

        return self::$resolvedMatrix;
    }

    /**
     * Canonical self-service baseline (PERSONEL matrix). No duplicate list.
     *
     * @return array<int, string>
     */
    public static function selfServiceBaselinePermissions()
    {
        return self::$matrix['PERSONEL'];
    }

    /**
     * Linked-personnel eligibility for self-service baseline (no extra DB I/O).
     * Active/valid enforcement remains SelfPersonelContext on /me endpoints.
     *
     * @param array<string, mixed> $user
     */
    public static function hasPersonnelLinkedSelfServiceEligibility(array $user)
    {
        if (!array_key_exists('personel_id', $user)) {
            return false;
        }
        $raw = $user['personel_id'];
        if ($raw === null || $raw === '') {
            return false;
        }

        return (int) $raw > 0;
    }

    /** @param array<string, mixed> $user */
    public static function has(array $user, $permission)
    {
        $permission = trim((string) $permission);
        if ($permission === '') {
            return false;
        }

        // QR/kart okutma: rol bağımsız (bağlı personel + kanonik mavi yaka).
        // Yönetici rolleri de kendi giriş/çıkışını okutabilir; rol bu kararı
        // ne genişletir ne daraltır.
        if (self::isQrSelfServicePermission($permission)) {
            return self::hasQrSelfServiceEntitlement($user);
        }

        // personel_id binding → own self-service baseline (role-independent).
        // Does not grant management permissions or expand org scope.
        if (
            self::hasPersonnelLinkedSelfServiceEligibility($user)
            && in_array($permission, self::selfServiceBaselinePermissions(), true)
        ) {
            return true;
        }

        $role = self::normalizeRole(isset($user['rol']) ? (string) $user['rol'] : '');
        if ($role === '') {
            return false;
        }

        $matrix = self::matrix();
        if (!isset($matrix[$role])) {
            return false;
        }

        return in_array($permission, $matrix[$role], true);
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
     * Canonical catalog only; anything else (including legacy role strings) → ''
     * so authorization fails closed instead of guessing an authority level.
     *
     * @return string
     */
    public static function normalizeRole($role)
    {
        $normalized = strtoupper(trim((string) $role));
        if ($normalized === '') {
            return '';
        }

        if (isset(self::matrix()[$normalized])) {
            return $normalized;
        }

        return '';
    }
}
