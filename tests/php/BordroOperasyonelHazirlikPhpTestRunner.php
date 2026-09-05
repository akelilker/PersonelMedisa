<?php

declare(strict_types=1);

/**
 * Focused pure semantics for BordroOperasyonelHazirlikService.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Services\Bildirim\BugunPersonelDurumuService;
use Medisa\Api\Services\BordroOperasyonelHazirlikService;
use Medisa\Api\Services\PuantajDonemPeriodService;

function bohFail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function bohOk(string $msg): void
{
    echo "OK: {$msg}\n";
}

function bohAssert(bool $condition, string $msg): void
{
    if (!$condition) {
        bohFail($msg);
    }
    bohOk($msg);
}

// 1) HENUZ → not ready
bohAssert(
    BordroOperasyonelHazirlikService::isOperasyonelHazir(['HENUZ_DEGERLENDIRILMEDI']) === false,
    'HENUZ_DEGERLENDIRILMEDI → not ready'
);

// 2) no open problems → ready
bohAssert(
    BordroOperasyonelHazirlikService::isOperasyonelHazir([]) === true,
    'empty problems → ready'
);

// 3) existing problem codes only
bohAssert(
    BordroOperasyonelHazirlikService::PROBLEM_HENUZ === 'HENUZ_DEGERLENDIRILMEDI',
    'HENUZ problem code matches Bugün'
);
bohAssert(
    BordroOperasyonelHazirlikService::PROBLEM_PUANTAJ_KONTROL === 'PUANTAJ_KONTROL_BEKLIYOR',
    'puantaj kontrol problem is existing DonemKapanis signal'
);
bohAssert(
    BordroOperasyonelHazirlikService::PROBLEM_ETKI_HAZIR === 'CANDIDATE_HAZIR_PENDING',
    'etki HAZIR pending reuses DonemKapanis code'
);

// 4) Bugün precedence parity (PR #263)
bohAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false) === 'HENUZ_DEGERLENDIRILMEDI',
    'precedence: no evidence → HENUZ'
);
bohAssert(
    BugunPersonelDurumuService::resolvePersonDurum('IZINLI', null, false) === 'IZINLI',
    'precedence: IZINLI exception'
);
bohAssert(
    BugunPersonelDurumuService::mapSurecToBugunExceptionTur('RAPOR') === 'RAPORLU',
    'precedence: RAPOR surec → RAPORLU'
);
bohAssert(
    BugunPersonelDurumuService::mapSurecToBugunExceptionTur('DEVAMSIZLIK', 'IZINSIZ_GELMEDI') === 'GELMEDI',
    'precedence: DEVAMSIZLIK → GELMEDI'
);
bohAssert(
    BugunPersonelDurumuService::effectiveExceptionTur(null, 'IZINLI') === 'IZINLI',
    'precedence: surec overlay when no bildirim'
);
bohAssert(
    BugunPersonelDurumuService::effectiveExceptionTur('GEC_GELDI', 'IZINLI') === 'GEC_GELDI',
    'precedence: bildirim wins over surec'
);

// 5) period state constants (OPEN=ACIK)
bohAssert(PuantajDonemPeriodService::STATE_ACIK === 'ACIK', 'OPEN period = ACIK');
bohAssert(PuantajDonemPeriodService::STATE_SEALED === 'SEALED', 'SEALED constant');
bohAssert(PuantajDonemPeriodService::STATE_REOPEN_PENDING === 'REOPEN_PENDING', 'REOPEN_PENDING constant');
bohAssert(PuantajDonemPeriodService::STATE_REOPENED === 'REOPENED', 'REOPENED constant');

fwrite(STDOUT, "Bordro operasyonel hazirlik PHP runner OK\n");
