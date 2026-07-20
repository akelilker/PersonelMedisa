<?php

declare(strict_types=1);

/**
 * S82 policy + correction projection + bordro preflight acceptance runner.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use Medisa\Api\Services\MaasHesaplamaCorrectionProjectionService;

function s82Assert(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$name}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$name}\n");
}

s82Assert(SirketCalismaPolitikasiCatalog::isKnown('NORMAL_AY_GUN_SAYISI'), 'company policy catalog known code');
s82Assert(!SirketCalismaPolitikasiCatalog::isKnown('ASGARI_UCRET_BRUT'), 'legal code not in company catalog');
s82Assert(count(SirketCalismaPolitikasiCatalog::requiredCodes()) >= 6, 'company policy required codes');

$projection = MaasHesaplamaCorrectionProjectionService::applyToPuantajlar(
    [['tarih' => '2026-03-10', 'toplam_net_dakika' => 450]],
    [[
        'etkilenen_tarih' => '2026-03-10',
        'correction_tipi' => 'GIRIS_CIKIS_DUZELTME',
        'delta_dakika' => 30,
        'correction_event_id' => 1,
        'revizyon_talebi_id' => 2,
    ]]
);
s82Assert($projection['puantajlar'][0]['toplam_net_dakika'] === 480, 'correction projection applies delta');
s82Assert(count($projection['applied']) === 1, 'correction projection audit applied');

$hash1 = MaasHesaplamaEngine::hashCanonical(['a' => 1]);
$hash2 = MaasHesaplamaEngine::hashCanonical(['a' => 1]);
s82Assert($hash1 === $hash2, 'deterministic hash');

fwrite(STDOUT, "S82 PHP runner OK\n");
