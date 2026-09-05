<?php

declare(strict_types=1);

/**
 * Focused pure / source semantics for calculation → finalization operational close.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Services\BordroHazirlikPreflightService;
use Medisa\Api\Services\BordroOnIzlemeService;
use Medisa\Api\Services\BordroOperasyonelHazirlikService;
use Medisa\Api\Services\PuantajDonemPeriodService;

function pcfFail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function pcfOk(string $msg): void
{
    echo "OK: {$msg}\n";
}

function pcfAssert(bool $condition, string $msg): void
{
    if (!$condition) {
        pcfFail($msg);
    }
    pcfOk($msg);
}

// 1) Operasyonel not-ready is not silently treated as ready
pcfAssert(
    BordroOperasyonelHazirlikService::isOperasyonelHazir(['HENUZ_DEGERLENDIRILMEDI']) === false,
    'open HENUZ day is not operasyonel ready'
);
pcfAssert(
    BordroOperasyonelHazirlikService::isOperasyonelHazir([]) === true,
    'empty problems are operasyonel ready'
);

// 2) Preflight source wires OPERASYONEL_HAZIRLIK_EKSIK before blocker_count
$preflightSrc = file_get_contents($root . '/api/src/Services/BordroHazirlikPreflightService.php');
pcfAssert(is_string($preflightSrc) && $preflightSrc !== '', 'preflight source readable');
$opPos = strpos($preflightSrc, "OPERASYONEL_HAZIRLIK_EKSIK");
$countPos = strpos($preflightSrc, "\$blockerCount = self::countSeverity(\$items, 'BLOCKER')");
$buildPos = strpos($preflightSrc, 'function build(');
pcfAssert($buildPos !== false && $opPos !== false && $countPos !== false, 'preflight markers present');
pcfAssert($opPos > $buildPos && $countPos > $opPos, 'operasyonel blocker precedes blocker_count');
pcfAssert(strpos($preflightSrc, 'operasyonel_puantaj') !== false, 'readiness domain operasyonel_puantaj');
pcfAssert(strpos($preflightSrc, 'sessizce hazır kabul edilmez') !== false, 'Turkish no-silent-ready copy');

// 3) Finalization owner guards
$finalSrc = file_get_contents($root . '/api/src/Services/BordroOnIzlemeService.php');
pcfAssert(is_string($finalSrc) && $finalSrc !== '', 'on-izleme source readable');
pcfAssert(strpos($finalSrc, 'function kesinlestir') !== false, 'kesinlestir owner present');
pcfAssert(strpos($finalSrc, 'Idempotent guard') !== false, 'duplicate finalization idempotent guard');
pcfAssert(strpos($finalSrc, 'PERIOD_REOPENED') !== false, 'period reopen blocks kesinleştir');
pcfAssert(strpos($finalSrc, 'isPeriodReopened') !== false, 'uses period service reopen check');
pcfAssert(strpos($finalSrc, 'OPERASYONEL_HAZIRLIK_EKSIK') !== false, 'finalization surfaces operasyonel blocker message');

// 4) Period lock constants preserved (SEALED / REOPEN_PENDING)
pcfAssert(PuantajDonemPeriodService::STATE_SEALED === 'SEALED', 'SEALED');
pcfAssert(PuantajDonemPeriodService::STATE_REOPEN_PENDING === 'REOPEN_PENDING', 'REOPEN_PENDING');
pcfAssert(PuantajDonemPeriodService::STATE_REOPENED === 'REOPENED', 'REOPENED');

// 5) Mid-period exit retention: net-maaş list no longer AKTIF-only in primary SQL
$listPos = strpos($preflightSrc, 'function listNetMaasEksikleri');
$classifyPos = strpos($preflightSrc, 'function classifyNetMaasDurumu');
pcfAssert($listPos !== false && $classifyPos !== false && $classifyPos > $listPos, 'listNetMaas markers');
$listSlice = substr($preflightSrc, $listPos, $classifyPos - $listPos);
pcfAssert(strpos($listSlice, "aktif_durum = 'AKTIF'") === false, 'net-maaş period roster not AKTIF-only');
pcfAssert(strpos($listSlice, 'Period roster (not AKTIF-only)') !== false, 'retention comment present');

// 6) Export owner exists
$controllerSrc = file_get_contents($root . '/api/src/Controllers/BordroHazirlikController.php');
pcfAssert(is_string($controllerSrc) && strpos($controllerSrc, 'onIzlemeExportCsv') !== false, 'on-izleme export owner');
pcfAssert(strpos($controllerSrc, 'buildDonemOzeti') !== false, 'export reuses on-izleme projection');
pcfAssert(class_exists(BordroOnIzlemeService::class), 'BordroOnIzlemeService loadable');
pcfAssert(class_exists(BordroHazirlikPreflightService::class), 'BordroHazirlikPreflightService loadable');

fwrite(STDOUT, "Payroll calculation finalization PHP runner OK\n");
