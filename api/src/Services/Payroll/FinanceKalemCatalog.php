<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * Snapshot FINANS kalem_turu -> matrah/net siniflandirmasi.
 * Belirsiz tur blocker uretir; MAAS duplicate salary blocker'dir.
 */
final class FinanceKalemCatalog
{
    /** @var array<string, array{yon: string, sgk: bool, gv: bool, damga: bool, net_odeme: bool, kalem_grubu: string}> */
    private static $map = [
        'PRIM' => ['yon' => 'ARTI', 'sgk' => true, 'gv' => true, 'damga' => true, 'net_odeme' => false, 'kalem_grubu' => 'EK_ODEME'],
        'BONUS' => ['yon' => 'ARTI', 'sgk' => true, 'gv' => true, 'damga' => true, 'net_odeme' => false, 'kalem_grubu' => 'EK_ODEME'],
        'IKRAMIYE' => ['yon' => 'ARTI', 'sgk' => true, 'gv' => true, 'damga' => true, 'net_odeme' => false, 'kalem_grubu' => 'EK_ODEME'],
        'TESVIK' => ['yon' => 'ARTI', 'sgk' => true, 'gv' => true, 'damga' => true, 'net_odeme' => false, 'kalem_grubu' => 'EK_ODEME'],
        'EKSTRA_PRIM' => ['yon' => 'ARTI', 'sgk' => true, 'gv' => true, 'damga' => true, 'net_odeme' => false, 'kalem_grubu' => 'EK_ODEME'],
        'MESAI' => ['yon' => 'ARTI', 'sgk' => true, 'gv' => true, 'damga' => true, 'net_odeme' => false, 'kalem_grubu' => 'FAZLA_MESAI'],
        'CEZA' => ['yon' => 'EKSI', 'sgk' => false, 'gv' => false, 'damga' => false, 'net_odeme' => true, 'kalem_grubu' => 'KESINTI'],
        'AVANS' => ['yon' => 'EKSI', 'sgk' => false, 'gv' => false, 'damga' => false, 'net_odeme' => true, 'kalem_grubu' => 'KESINTI'],
        'BES' => ['yon' => 'EKSI', 'sgk' => false, 'gv' => false, 'damga' => false, 'net_odeme' => true, 'kalem_grubu' => 'KESINTI'],
        'DIGER_KESINTI' => ['yon' => 'EKSI', 'sgk' => false, 'gv' => false, 'damga' => false, 'net_odeme' => true, 'kalem_grubu' => 'KESINTI'],
    ];

    public static function isDuplicateSalary($kalemTuru)
    {
        return strtoupper(trim((string) $kalemTuru)) === 'MAAS';
    }

    /** @return array{yon: string, sgk: bool, gv: bool, damga: bool, net_odeme: bool, kalem_grubu: string}|null */
    public static function classify($kalemTuru)
    {
        $code = strtoupper(trim((string) $kalemTuru));
        if ($code === 'MAAS') {
            return null;
        }

        return isset(self::$map[$code]) ? self::$map[$code] : null;
    }

    /** @return array<int, string> */
    public static function knownCodes()
    {
        $codes = array_keys(self::$map);
        $codes[] = 'MAAS';
        sort($codes);

        return $codes;
    }
}
