<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/PersonelUcretException.php';
require_once __DIR__ . '/../../api/src/Services/PersonelUcretService.php';

use Medisa\Api\Services\PersonelUcretException;
use Medisa\Api\Services\PersonelUcretService;

function salaryAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function salaryException(callable $callback, string $code, string $name): void
{
    try {
        $callback();
    } catch (PersonelUcretException $e) {
        salaryAssert($e->getCodeString() === $code, $name);
        return;
    }
    throw new RuntimeException('[FAIL] ' . $name);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE personeller (
    id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, maas_tutari REAL, ise_giris_tarihi TEXT
)');
$pdo->exec('CREATE TABLE personel_ucret_gecmisi (
    id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER NOT NULL, ucret_tutari REAL NOT NULL,
    ucret_turu TEXT NOT NULL, para_birimi TEXT NOT NULL, gecerlilik_baslangic TEXT NOT NULL,
    gecerlilik_bitis TEXT, state TEXT NOT NULL, kaynak TEXT NOT NULL, aciklama TEXT,
    created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_by INTEGER,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP, iptal_edildi_at TEXT, iptal_edildi_by INTEGER,
    revision_no INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE personel_ucret_auditleri (
    id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER NOT NULL, ucret_kaydi_id INTEGER,
    aksiyon TEXT NOT NULL, onceki_snapshot TEXT, sonraki_snapshot TEXT, actor_id INTEGER,
    actor_rol TEXT, sube_id INTEGER, request_hash TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec("INSERT INTO personeller VALUES
    (1, 1, 12000, '2020-01-01'),
    (2, 1, NULL, '2021-01-01'),
    (3, 1, 9000, NULL)");

$legacy = PersonelUcretService::resolveSalaryForDate($pdo, 1, '2024-06-01');
salaryAssert(($legacy['virtual'] ?? false) === true && $legacy['ucret_turu'] === 'NET', 'legacy salary resolves as virtual NET');
salaryAssert(PersonelUcretService::resolveSalaryForDate($pdo, 3, '1900-01-01')['gecerlilik_baslangic'] === '1900-01-01', 'legacy null hire date uses 1900 start');

$first = PersonelUcretService::createSalaryRecord($pdo, 2, [
    'ucret_tutari' => 10000,
    'ucret_turu' => 'BRUT',
    'gecerlilik_baslangic' => '2024-01-01',
]);
$second = PersonelUcretService::createSalaryRecord($pdo, 2, [
    'ucret_tutari' => 15000,
    'ucret_turu' => 'NET',
    'gecerlilik_baslangic' => '2025-01-01',
]);
$history = PersonelUcretService::listSalaryHistory($pdo, 2);
salaryAssert(count($history) === 2, 'salary history stores separate periods');
salaryAssert($history[1]['gecerlilik_bitis'] === '2024-12-31', 'new period closes previous open period at start minus one');
salaryAssert((int) PersonelUcretService::resolveSalaryForDate($pdo, 2, '2024-12-31')['id'] === (int) $first['id'], 'inclusive end resolves previous salary');
salaryAssert((int) PersonelUcretService::resolveSalaryForDate($pdo, 2, '2025-01-01')['id'] === (int) $second['id'], 'inclusive start resolves new salary');

salaryException(function () use ($pdo): void {
    PersonelUcretService::validateNoOverlap($pdo, 2, '2024-12-31', '2024-12-31');
}, 'SALARY_DATE_OVERLAP', 'inclusive boundary overlap is rejected');

PersonelUcretService::cancelSalaryRecord($pdo, (int) $second['id']);
salaryException(function () use ($pdo): void {
    PersonelUcretService::resolveSalaryForDate($pdo, 2, '2025-01-01');
}, 'SALARY_MISSING', 'cancelled salary does not resolve');

PersonelUcretService::createSalaryRecord($pdo, 1, [
    'ucret_tutari' => 18000,
    'ucret_turu' => 'NET',
    'gecerlilik_baslangic' => '2025-01-01',
]);
$personOneHistory = PersonelUcretService::listSalaryHistory($pdo, 1);
salaryAssert(count($personOneHistory) === 2 && $personOneHistory[1]['kaynak'] === 'PERSONEL_KAYDI_MIGRASYON', 'explicit create migrates legacy salary history');
salaryAssert((float) PersonelUcretService::resolveSalaryForDate($pdo, 1, '2024-12-31')['ucret_tutari'] === 12000.0, 'legacy migration preserves past salary');
salaryAssert((int) $pdo->query('SELECT COUNT(*) FROM personel_ucret_auditleri')->fetchColumn() >= 6, 'all salary mutations write audit rows');

echo 'verify-personel-ucret-service: OK' . PHP_EOL;
