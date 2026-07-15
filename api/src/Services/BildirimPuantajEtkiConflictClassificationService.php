<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

/**
 * Classifies existing gunluk_puantaj rows for bildirim etki aday conflict resolution.
 */
class BildirimPuantajEtkiConflictClassificationService
{
    public const CLASS_A = 'AYNI_ADAY_PUANTAJI';
    public const CLASS_B = 'BASKA_ADAY_KAYNAGI';
    public const CLASS_C = 'MANUEL_KAYNAK';
    public const CLASS_D = 'RESMI_SUREC_DAYANAK';
    public const CLASS_E = 'MUHURLU_PUANTAJ';
    public const CLASS_F = 'AMIR_KONTROL_EDILMIS';
    public const CLASS_G = 'LEGACY_BELIRSIZ';

    public const KARAR_MEVCUT_KORU = 'MEVCUT_PUANTAJI_KORU';
    public const KARAR_REVIZE = 'ADAY_ETKISIYLE_REVIZE_ET';

    /** @var array<int, string> */
    private static $surecDayanaklari = [
        'Yillik_Izin',
        'Ucretli_Izinli',
        'Raporlu_Hastalik',
        'Raporlu_Is_Kazasi',
    ];

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed> $puantaj
     * @return array{class: string, default_karar: string, revise_allowed: bool, risk: string}
     */
    public static function classify(array $aday, array $puantaj)
    {
        $puantajId = (int) ($puantaj['id'] ?? 0);
        $adayState = BildirimPuantajEtkiDecisionPolicy::normalizeState((string) ($aday['state'] ?? ''));
        $uygulananId = isset($aday['uygulanan_puantaj_id']) ? (int) $aday['uygulanan_puantaj_id'] : 0;

        if ((string) ($puantaj['state'] ?? 'ACIK') === 'MUHURLENDI'
            || (isset($puantaj['muhur_id']) && $puantaj['muhur_id'] !== null && (int) $puantaj['muhur_id'] > 0)) {
            return self::result(self::CLASS_E, self::KARAR_MEVCUT_KORU, false, 'KRITIK');
        }

        if ($adayState === 'UYGULANDI' && $uygulananId > 0 && $uygulananId === $puantajId) {
            return self::result(self::CLASS_A, self::KARAR_REVIZE, true, 'DUSUK');
        }

        $kaynak = self::normalizeKaynak($puantaj['kaynak'] ?? null);
        if ($kaynak === BildirimPuantajEtkiPuantajMapper::KAYNAK
            || $kaynak === BildirimPuantajEtkiPuantajMapper::KAYNAK_REVIZYON) {
            return self::result(self::CLASS_B, self::KARAR_MEVCUT_KORU, true, 'ORTA');
        }

        $dayanak = trim((string) ($puantaj['dayanak'] ?? ''));
        if ($dayanak !== '' && in_array($dayanak, self::$surecDayanaklari, true)) {
            return self::result(self::CLASS_D, self::KARAR_MEVCUT_KORU, false, 'YUKSEK');
        }

        $kontrol = strtoupper(trim((string) ($puantaj['kontrol_durumu'] ?? 'BEKLIYOR')));
        if ($kontrol === 'AMIR_KONTROL_ETTI') {
            return self::result(self::CLASS_F, self::KARAR_MEVCUT_KORU, true, 'ORTA');
        }

        if ($kaynak === 'MANUEL' || $kaynak === '') {
            if ($kaynak === 'MANUEL') {
                return self::result(self::CLASS_C, self::KARAR_MEVCUT_KORU, true, 'YUKSEK');
            }

            return self::result(self::CLASS_G, self::KARAR_MEVCUT_KORU, true, 'YUKSEK');
        }

        return self::result(self::CLASS_C, self::KARAR_MEVCUT_KORU, true, 'YUKSEK');
    }

    public static function isReviseAllowed($conflictClass, $kararTuru)
    {
        $kararTuru = strtoupper(trim((string) $kararTuru));
        if ($kararTuru === self::KARAR_MEVCUT_KORU) {
            return true;
        }
        if ($kararTuru !== self::KARAR_REVIZE) {
            return false;
        }
        if ($conflictClass === self::CLASS_D || $conflictClass === self::CLASS_E) {
            return false;
        }

        return true;
    }

    public static function conflictCodeForReviseBlocked($conflictClass)
    {
        if ($conflictClass === self::CLASS_D) {
            return 'PUANTAJ_SOURCE_PROTECTED';
        }
        if ($conflictClass === self::CLASS_E) {
            return 'PERIOD_LOCKED';
        }

        return 'REVISION_NOT_ALLOWED';
    }

    /** @return array{class: string, default_karar: string, revise_allowed: bool, risk: string} */
    private static function result($class, $defaultKarar, $reviseAllowed, $risk)
    {
        return [
            'class' => (string) $class,
            'default_karar' => (string) $defaultKarar,
            'revise_allowed' => (bool) $reviseAllowed,
            'risk' => (string) $risk,
        ];
    }

  /** @param mixed $value */
    private static function normalizeKaynak($value)
    {
        if ($value === null) {
            return '';
        }

        return strtoupper(trim((string) $value));
    }
}
