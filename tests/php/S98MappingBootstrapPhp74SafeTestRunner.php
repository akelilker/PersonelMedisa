<?php

declare(strict_types=1);

/**
 * S98 mapping bootstrap without Composer autoload.
 * Mirrors production require_once chain for template/dry-run class load.
 * Exit 0 on success; non-zero on failure.
 */

$root = dirname(__DIR__, 2);

require_once $root . '/api/src/Http/CsvResponse.php';
require_once $root . '/api/src/Services/Payroll/SgkKatalogContracts.php';
require_once $root . '/api/src/Services/Payroll/SgkEslemeKararContract.php';
require_once $root . '/api/src/Services/Payroll/SgkSurecEslemeImportValidator.php';

use Medisa\Api\Services\Payroll\SgkEslemeKararContract;
use Medisa\Api\Services\Payroll\SgkSurecEslemeImportValidator;

function s98MapAssert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$msg}\n");
}

$codes = SgkEslemeKararContract::requiredCatalogCodes('OLAY_NEDENINE_GORE', null);
s98MapAssert($codes === ['01', '06', '15', '21'], 'requiredCatalogCodes(OLAY)=01,06,15,21');

$codesWage = SgkEslemeKararContract::requiredCatalogCodes('UCRET_MODELINE_GORE', null);
s98MapAssert($codesWage === ['01'], 'requiredCatalogCodes(UCRET_MODELINE_GORE)=01');

$codesMazeret = SgkEslemeKararContract::requiredCatalogCodes('UCRET_KESINTISI_SECIMINE_GORE', null);
s98MapAssert($codesMazeret === ['21'], 'requiredCatalogCodes(MAZERET)=21');

$codesEmpty = SgkEslemeKararContract::requiredCatalogCodes('HER_ZAMAN_DAHIL', null);
s98MapAssert($codesEmpty === [], 'requiredCatalogCodes(DAHIL)=[]');

$export = SgkSurecEslemeImportValidator::buildTemplateExport();
s98MapAssert(is_array($export), 'buildTemplateExport returns array');
s98MapAssert(isset($export['csv'], $export['sha256']), 'export has csv+sha256');
s98MapAssert(strncmp($export['csv'], "\xEF\xBB\xBF", 3) === 0, 'UTF-8 BOM present');
s98MapAssert(substr_count($export['csv'], "\r\n") >= 14, 'CRLF rows >= 14');
s98MapAssert(strlen($export['sha256']) === 64, 'sha256 hex length 64');

$inventory = SgkSurecEslemeImportValidator::rawSurecInventory();
s98MapAssert(count($inventory) === 14, 'raw inventory exact 14');

fwrite(STDOUT, "S98_MAPPING_BOOTSTRAP_PHP74_SAFE=PASS\n");
exit(0);
