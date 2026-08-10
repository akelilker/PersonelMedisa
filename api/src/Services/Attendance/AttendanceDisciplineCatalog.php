<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

final class AttendanceDisciplineCatalog
{
    public const OLAY_GEC_KALMA = 'GEC_KALMA';
    public const OLAY_ERKEN_CIKIS = 'ERKEN_CIKIS';

    public const KARAR_BEKLIYOR = 'BEKLIYOR';
    public const KARAR_KESINTI_UYGULA = 'KESINTI_UYGULA';
    public const KARAR_TOLERANS_UYGULA = 'TOLERANS_UYGULA';
    public const KARAR_OFFICIAL_PROCESS_REQUIRED = 'OFFICIAL_PROCESS_REQUIRED';

    public const LIFECYCLE_INCELEME_ADAYI = 'INCELEME_ADAYI';
    public const LIFECYCLE_IK_INCELEME = 'IK_INCELEME';
    public const LIFECYCLE_SAVUNMA_BEKLENIYOR = 'SAVUNMA_BEKLENIYOR';
    public const LIFECYCLE_SAVUNMA_ALINDI = 'SAVUNMA_ALINDI';
    public const LIFECYCLE_SAVUNMA_SUNULMADI = 'SAVUNMA_SUNULMADI';
    public const LIFECYCLE_KARAR_BEKLIYOR = 'KARAR_BEKLIYOR';
    public const LIFECYCLE_KARAR_VERILDI = 'KARAR_VERILDI';
    public const LIFECYCLE_KAPANDI = 'KAPANDI';
    public const LIFECYCLE_ISLEMSIZ_KAPATILDI = 'ISLEMSIZ_KAPATILDI';

    public const CANDIDATE_GEC_KALMA = 'GEC_KALMA';
    public const CANDIDATE_TAM_GUN_DEVAMSIZLIK = 'TAM_GUN_DEVAMSIZLIK';
    public const CANDIDATE_AYLIK_TEKRARLAYAN_GEC_KALMA = 'AYLIK_TEKRARLAYAN_GEC_KALMA';

    public const NIHAI_KARAR_NO_ACTION = 'NO_ACTION';
    public const NIHAI_KARAR_UYARI = 'UYARI';
    public const NIHAI_KARAR_CEZA = 'CEZA';

    public const SUREC_TURU_DISIPLIN = 'DISIPLIN';

    public const MONTHLY_LATE_MINUTES = 60;
    public const MONTHLY_LATE_EVENT_THRESHOLD = 3;
    /** Max raw late minutes for which TOLERANS_UYGULA is valid (inclusive). */
    public const LATE_TOLERANCE_MAX_MINUTE = 35;

    /** @return array<int, string> */
    public static function olayTurleri()
    {
        return [self::OLAY_GEC_KALMA, self::OLAY_ERKEN_CIKIS];
    }

    /** @return array<int, string> */
    public static function kararTurleri()
    {
        return [
            self::KARAR_BEKLIYOR,
            self::KARAR_KESINTI_UYGULA,
            self::KARAR_TOLERANS_UYGULA,
            self::KARAR_OFFICIAL_PROCESS_REQUIRED,
        ];
    }

    /** @return array<int, string> */
    public static function lifecycleStates()
    {
        return [
            self::LIFECYCLE_INCELEME_ADAYI,
            self::LIFECYCLE_IK_INCELEME,
            self::LIFECYCLE_SAVUNMA_BEKLENIYOR,
            self::LIFECYCLE_SAVUNMA_ALINDI,
            self::LIFECYCLE_SAVUNMA_SUNULMADI,
            self::LIFECYCLE_KARAR_BEKLIYOR,
            self::LIFECYCLE_KARAR_VERILDI,
            self::LIFECYCLE_KAPANDI,
            self::LIFECYCLE_ISLEMSIZ_KAPATILDI,
        ];
    }

    /** @return array<int, string> */
    public static function candidateTurleri()
    {
        return [
            self::CANDIDATE_GEC_KALMA,
            self::CANDIDATE_TAM_GUN_DEVAMSIZLIK,
            self::CANDIDATE_AYLIK_TEKRARLAYAN_GEC_KALMA,
        ];
    }

    /** @return array<int, string> */
    public static function nihaiKararTurleri()
    {
        return [
            self::NIHAI_KARAR_NO_ACTION,
            self::NIHAI_KARAR_UYARI,
            self::NIHAI_KARAR_CEZA,
        ];
    }

    /** @return array<int, string> */
    public static function olayKararDecideRoles()
    {
        return ['BOLUM_YONETICISI'];
    }

    /** @return array<int, string> */
    public static function finalDecisionRoles()
    {
        return ['BOLUM_YONETICISI'];
    }

    /**
     * Authorized full-day absence dayanak values — never auto discipline candidates.
     *
     * @return array<int, string>
     */
    public static function authorizedAbsenceDayanaklari()
    {
        return [
            'Ucretli_Izinli',
            'Yillik_Izin',
            'Raporlu_Hastalik',
            'Raporlu_Is_Kazasi',
            'Gorevde_Calisma',
            'Telafi_Calismasi',
        ];
    }

    public static function isAuthorizedAbsenceDayanak($dayanak)
    {
        $token = trim((string) $dayanak);
        if ($token === '') {
            return false;
        }

        return in_array($token, self::authorizedAbsenceDayanaklari(), true);
    }

    public static function isLateToleranceAllowed($rawDakika)
    {
        return (int) $rawDakika >= 0 && (int) $rawDakika <= self::LATE_TOLERANCE_MAX_MINUTE;
    }

    /** @return array<int, string> */
    public static function ikReviewRoles()
    {
        return ['IK_BORDRO', 'GENEL_YONETICI', 'MUHASEBE', 'BOLUM_YONETICISI'];
    }
}
