<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\PersonelBelge\PersonelBelgeLinkedRaporAttachmentService;
use Medisa\Api\Services\SelfService\PersonelBordroOkumaService;
use Medisa\Api\Services\SelfService\PersonelSelfProductException;

function pr472Fail(string $msg): void
{
    fwrite(STDERR, "[FAIL] {$msg}\n");
    exit(1);
}

function pr472Pass(string $msg): void
{
    echo "[PASS] {$msg}\n";
}

function pr472Assert(bool $ok, string $msg): void
{
    if (!$ok) {
        pr472Fail($msg);
    }
    pr472Pass($msg);
}

// --- Bordro okudum (sqlite) ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE personel_bordro_okumalari (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        personel_id INTEGER NOT NULL,
        calistirma_id INTEGER NOT NULL,
        yil INTEGER NOT NULL,
        ay INTEGER NOT NULL,
        okundu_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        okundu_by_user_id INTEGER NOT NULL,
        UNIQUE(personel_id, calistirma_id)
    )'
);
$pdo->exec(
    'CREATE TABLE maas_hesaplama_calistirmalari (
        id INTEGER PRIMARY KEY, yil INTEGER, ay INTEGER, sube_id INTEGER
    )'
);
$pdo->exec(
    'CREATE TABLE maas_hesaplama_personel_snapshotlari (
        id INTEGER PRIMARY KEY, personel_id INTEGER
    )'
);
$pdo->exec(
    'CREATE TABLE maas_hesaplama_adaylari (
        calistirma_id INTEGER, personel_snapshot_id INTEGER, bordro_onay_durumu TEXT
    )'
);
$pdo->exec('INSERT INTO maas_hesaplama_calistirmalari VALUES (10, 2026, 3, 1)');
$pdo->exec('INSERT INTO maas_hesaplama_personel_snapshotlari VALUES (100, 5)');
$pdo->exec("INSERT INTO maas_hesaplama_adaylari VALUES (10, 100, 'KESINLESTI')");
$pdo->exec("INSERT INTO maas_hesaplama_adaylari VALUES (10, 100, 'TASLAK')");

try {
    PersonelBordroOkumaService::acknowledge($pdo, 5, 99, 10);
    pr472Pass('bordro ack KESINLESTI row');
} catch (Throwable $e) {
    pr472Fail('bordro ack should succeed: ' . $e->getMessage());
}

$first = PersonelBordroOkumaService::acknowledge($pdo, 5, 99, 10);
$second = PersonelBordroOkumaService::acknowledge($pdo, 5, 88, 10);
pr472Assert($first['okundu_at'] === $second['okundu_at'], 'bordro idempotent okundu_at preserved');
pr472Assert((int) $first['okundu_by_user_id'] === 99, 'bordro first ack user kept');

try {
    PersonelBordroOkumaService::acknowledge($pdo, 6, 99, 10);
    pr472Fail('cross-personel bordro ack must fail');
} catch (PersonelSelfProductException $e) {
    pr472Assert($e->getErrorCode() === 'NOT_FOUND', 'cross-personel ack NOT_FOUND');
}

$countStmt = $pdo->query('SELECT COUNT(*) FROM personel_bordro_okumalari');
pr472Assert((int) $countStmt->fetchColumn() === 1, 'single bordro ack row (payroll tables untouched)');

// --- Rapor attachment validation (no file / invalid) ---
try {
    PersonelBelgeLinkedRaporAttachmentService::attachOptionalFile(
        $pdo,
        1,
        1,
        1,
        '2026-01-01',
        '2026-01-02',
        ['dosya_adi' => 'x.pdf']
    );
    pr472Fail('missing base64 must fail');
} catch (PersonelSelfProductException $e) {
    pr472Assert($e->getField() === 'dosya_adi', 'rapor attachment missing fields');
}

try {
    PersonelBelgeLinkedRaporAttachmentService::attachOptionalFile(
        $pdo,
        1,
        1,
        1,
        '2026-01-01',
        '2026-01-05',
        ['dosya_adi' => 'x.pdf', 'dosya_icerik_base64' => '!!!', 'dosya_mime' => 'application/pdf']
    );
    pr472Fail('invalid base64 must fail');
} catch (PersonelSelfProductException $e) {
    pr472Pass('rapor attachment rejects invalid base64');
}

pr472Pass('POST_PR472_SELF_SERVICE_BEHAVIOR=OK');
