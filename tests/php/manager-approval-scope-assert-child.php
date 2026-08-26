<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\ManagerApprovalScope;

$raw = isset($argv[1]) ? (string) $argv[1] : '';
$decoded = json_decode(base64_decode($raw), true);
if (!is_array($decoded)) {
    fwrite(STDERR, "INVALID_PAYLOAD\n");
    exit(2);
}

$user = isset($decoded['user']) && is_array($decoded['user']) ? $decoded['user'] : [];
$subeId = (int) ($decoded['sube_id'] ?? 0);
$amirId = (int) ($decoded['amir_id'] ?? 0);
$context = isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [];

$_GET = ['sube_id' => (string) $subeId];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/t';
$request = new Request();

ManagerApprovalScope::assertActorCanAccessBirimAmiriContext(
    $user,
    $request,
    $subeId,
    $amirId,
    $context
);
echo "ALLOW\n";
exit(0);
