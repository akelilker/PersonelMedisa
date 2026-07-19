<?php
/**
 * S79-D-R3 migrate ops completed; endpoint retired.
 * Purges leftover public dump/sql artifacts, then returns 410.
 * Stale cPanel copies without FTP --delete become inert after first hit.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$deleted = [];
foreach (glob(__DIR__ . '/karmotor_medisa_pre_029_*.sql') ?: [] as $file) {
    if (is_file($file) && @unlink($file)) {
        $deleted[] = basename($file);
    }
}
foreach ([
    '029_serbest_zaman_events.sql',
    's79dr3_latest_backup_path.txt',
    's79dr3_smoke_fixture.json',
] as $name) {
    $path = __DIR__ . '/' . $name;
    if (is_file($path) && @unlink($path)) {
        $deleted[] = $name;
    }
}

http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'GONE',
    'message' => 'S79-D-R3 migrate endpoint retired.',
    'purged' => $deleted,
], JSON_UNESCAPED_UNICODE);
