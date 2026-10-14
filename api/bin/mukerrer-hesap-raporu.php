#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Eski mükerrer hesap raporu — SALT OKUMA (t900 B).
 *
 * Usage (cPanel / local with DB):
 *   php api/bin/mukerrer-hesap-raporu.php
 *
 * Oturum READ ONLY işlemde açılır ve sonunda geri alınır; hiçbir kayıt değişmez.
 * Çıktı: JSON (T.C. kimlik numarası içermez).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Auth\MukerrerHesapRaporuService;

try {
    $pdo = Connection::get();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(2);
}

$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
try {
    $report = MukerrerHesapRaporuService::scan($pdo);
} finally {
    $pdo->rollBack();
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit(0);
