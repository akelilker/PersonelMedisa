<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\ResmiTatilTakvimProjectionService;

function projAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

// Safe: fully inside holiday interval, mola=0
$inside = ResmiTatilTakvimProjectionService::resolveTatilDonemiMinutes(
    [
        'giris_saati' => '13:00',
        'cikis_saati' => '15:00',
        'gercek_mola_dakika' => 0,
        'net_calisma_suresi_dakika' => 120,
        'gunluk_brut_sure_dakika' => 120,
    ],
    [
        'tatil_gun_kapsami' => 'YARIM_GUN',
        'tatil_interval_baslangic' => '13:00:00',
        'tatil_interval_bitis' => '16:45:00',
    ]
);
projAssert($inside['net'] === 120 && $inside['brut'] === 120, 'fully inside overlap net=120');

// Safe: no overlap → 0
$outside = ResmiTatilTakvimProjectionService::resolveTatilDonemiMinutes(
    [
        'giris_saati' => '08:00',
        'cikis_saati' => '12:00',
        'gercek_mola_dakika' => 0,
        'net_calisma_suresi_dakika' => 240,
    ],
    [
        'tatil_gun_kapsami' => 'YARIM_GUN',
        'tatil_interval_baslangic' => '13:00:00',
        'tatil_interval_bitis' => '16:45:00',
    ]
);
projAssert($outside['net'] === 0 && $outside['brut'] === 0, 'no overlap → 0');

// Unsafe: partial overlap + mola → null (no random break allocation)
$ambiguous = ResmiTatilTakvimProjectionService::resolveTatilDonemiMinutes(
    [
        'giris_saati' => '11:00',
        'cikis_saati' => '15:00',
        'gercek_mola_dakika' => 15,
        'net_calisma_suresi_dakika' => 225,
    ],
    [
        'tatil_gun_kapsami' => 'YARIM_GUN',
        'tatil_interval_baslangic' => '13:00:00',
        'tatil_interval_bitis' => '16:45:00',
    ]
);
projAssert($ambiguous['net'] === null, 'partial overlap + mola → null fail-closed');

// Authoritative override preserved
$auth = ResmiTatilTakvimProjectionService::resolveTatilDonemiMinutes(
    [
        'tatil_donemi_net_calisma_dakika' => 45,
        'tatil_donemi_brut_calisma_dakika' => 60,
        'tatil_donemi_ara_dinlenme_dakika' => 15,
        'giris_saati' => '11:00',
        'cikis_saati' => '15:00',
        'gercek_mola_dakika' => 15,
        'net_calisma_suresi_dakika' => 225,
    ],
    [
        'tatil_gun_kapsami' => 'YARIM_GUN',
        'tatil_interval_baslangic' => '13:00:00',
        'tatil_interval_bitis' => '16:45:00',
    ]
);
projAssert($auth['net'] === 45 && $auth['brut'] === 60 && $auth['mola'] === 15, 'authoritative minutes preserved');

// net=0 → 0 without guessing
$zero = ResmiTatilTakvimProjectionService::resolveTatilDonemiMinutes(
    ['net_calisma_suresi_dakika' => 0],
    [
        'tatil_gun_kapsami' => 'YARIM_GUN',
        'tatil_interval_baslangic' => '13:00:00',
        'tatil_interval_bitis' => '16:45:00',
    ]
);
projAssert($zero['net'] === 0, 'net=0 → tatil donemi 0');

echo "ALL_PROJECTION_HALF_DAY_TESTS_PASSED\n";
