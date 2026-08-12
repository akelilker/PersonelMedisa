<?php

declare(strict_types=1);

/**
 * S3C: QrTokenService mint/verify contract (pure PHP, temporary signing secret).
 * php tests/php/S3CQrTokenServiceTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceException;
use Medisa\Api\Services\Qr\QrConfig;
use Medisa\Api\Services\Qr\QrTokenService;

function s3cQrAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @param array<string, mixed> $payload */
function s3cQrEncodeToken(array $payload, string $secret): string
{
    $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $signingInput = 'mqr1.' . $body;
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, $secret, true)), '+/', '-_'), '=');

    return $signingInput . '.' . $sig;
}

function s3cQrCatchCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (QrAttendanceException $e) {
        return $e->getErrorCode();
    }

    return null;
}

global $config;
$testSecret = 's3c-test-qr-signing-secret-32chars!!';
$config['qr_signing_secret'] = $testSecret;
$config['qr_ttl_seconds'] = 60;

// Valid mint + verify
$minted = QrTokenService::mint(1);
s3cQrAssert(isset($minted['token']) && is_string($minted['token']), 'mint returns token');
$verified = QrTokenService::verify($minted['token']);
s3cQrAssert((int) $verified['sube_id'] === 1, 'verify sube_id');
s3cQrAssert($verified['jti'] === $minted['jti'], 'verify jti matches mint');
s3cQrAssert((int) $verified['version'] === QrConfig::TOKEN_VERSION, 'verify version');

// Tampered payload
$parts = explode('.', $minted['token']);
$tamperedBody = rtrim(strtr(base64_encode('{"v":1,"sube_id":99}'), '+/', '-_'), '=');
$tamperedPayloadToken = $parts[0] . '.' . $tamperedBody . '.' . $parts[2];
s3cQrAssert(s3cQrCatchCode(static function () use ($tamperedPayloadToken) {
    QrTokenService::verify($tamperedPayloadToken);
}) === 'QR_TOKEN_INVALID', 'tampered payload DENY');

// Tampered signature
$tamperedSigToken = $parts[0] . '.' . $parts[1] . '.AAAAAAAA';
s3cQrAssert(s3cQrCatchCode(static function () use ($tamperedSigToken) {
    QrTokenService::verify($tamperedSigToken);
}) === 'QR_TOKEN_INVALID', 'tampered sig DENY');

// Expired
$now = time();
$expiredToken = s3cQrEncodeToken([
    'v' => QrConfig::TOKEN_VERSION,
    'sube_id' => 1,
    'iat' => $now - 120,
    'exp' => $now - 60,
    'jti' => str_repeat('d', 32),
], $testSecret);
s3cQrAssert(s3cQrCatchCode(static function () use ($expiredToken) {
    QrTokenService::verify($expiredToken);
}) === 'QR_TOKEN_EXPIRED', 'expired DENY');

// Wrong version
$wrongVersionToken = s3cQrEncodeToken([
    'v' => 99,
    'sube_id' => 1,
    'iat' => $now,
    'exp' => $now + 60,
    'jti' => str_repeat('e', 32),
], $testSecret);
s3cQrAssert(s3cQrCatchCode(static function () use ($wrongVersionToken) {
    QrTokenService::verify($wrongVersionToken);
}) === 'QR_TOKEN_VERSION_UNSUPPORTED', 'wrong version DENY');

// TTL out of range on verify
$ttlOutOfRangeToken = s3cQrEncodeToken([
    'v' => QrConfig::TOKEN_VERSION,
    'sube_id' => 1,
    'iat' => $now,
    'exp' => $now + 200,
    'jti' => str_repeat('f', 32),
], $testSecret);
s3cQrAssert(s3cQrCatchCode(static function () use ($ttlOutOfRangeToken) {
    QrTokenService::verify($ttlOutOfRangeToken);
}) === 'QR_TOKEN_INVALID', 'TTL out of range on verify DENY');

// Missing secret
$backupSecret = $config['qr_signing_secret'];
$config['qr_signing_secret'] = 'CHANGE_ME_QR_SIGNING_SECRET_MIN_32_CHARS';
s3cQrAssert(s3cQrCatchCode(static function () {
    QrTokenService::mint(1);
}) === 'QR_CONFIG_NOT_READY', 'missing secret QR_CONFIG_NOT_READY mint');
s3cQrAssert(s3cQrCatchCode(static function () use ($minted) {
    QrTokenService::verify($minted['token']);
}) === 'QR_CONFIG_NOT_READY', 'missing secret QR_CONFIG_NOT_READY verify');
$config['qr_signing_secret'] = $backupSecret;

echo "S3C QR token service runner OK\n";
