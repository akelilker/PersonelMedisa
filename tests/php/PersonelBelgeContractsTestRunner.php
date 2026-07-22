<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/PersonelBelge/PersonelBelgeContracts.php';
require_once __DIR__ . '/../../api/src/Services/PersonelBelge/PersonelBelgeBase64Guard.php';
require_once __DIR__ . '/../../api/src/Services/PersonelBelge/PersonelBelgeStorageService.php';

use Medisa\Api\Services\PersonelBelge\PersonelBelgeBase64Guard;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeContracts;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;

function assertTrue(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

assertTrue(PersonelBelgeContracts::MAX_DECODED_BYTES === 10 * 1024 * 1024, 'limit 10MiB');
assertTrue(PersonelBelgeContracts::EXPIRY_WARNING_DAYS === 30, 'expiry threshold 30');

assertTrue(
    PersonelBelgeContracts::deriveTakipDurumu('IPTAL', '2099-01-01', true) === PersonelBelgeContracts::STATUS_IPTAL,
    'takip IPTAL'
);
assertTrue(
    PersonelBelgeContracts::deriveTakipDurumu('AKTIF', '2099-01-01', false) === PersonelBelgeContracts::STATUS_BELGE_DOSYASI_EKSIK,
    'takip dosya eksik'
);
assertTrue(
    PersonelBelgeContracts::deriveTakipDurumu('AKTIF', '2020-01-01', true, '2026-07-22') === PersonelBelgeContracts::STATUS_SURESI_DOLDU,
    'takip suresi doldu'
);
assertTrue(
    PersonelBelgeContracts::deriveTakipDurumu('AKTIF', '2026-08-01', true, '2026-07-22') === PersonelBelgeContracts::STATUS_SURESI_YAKLASIYOR,
    'takip yaklasiyor'
);
assertTrue(
    PersonelBelgeContracts::deriveTakipDurumu('AKTIF', '2027-01-01', true, '2026-07-22') === PersonelBelgeContracts::STATUS_AKTIF,
    'takip aktif'
);

$mimeOk = PersonelBelgeContracts::validateFilenameAndMime('rapor.pdf', 'application/pdf');
assertTrue($mimeOk['ok'] === true && $mimeOk['extension'] === 'pdf', 'mime pdf ok');

$mimeBad = PersonelBelgeContracts::validateFilenameAndMime('x.php', 'application/x-php');
assertTrue($mimeBad['ok'] === false, 'php reddedilir');

$double = PersonelBelgeContracts::validateFilenameAndMime('evil.php.pdf', 'application/pdf');
assertTrue($double['ok'] === false, 'cift uzanti php engellenir');

$path = PersonelBelgeContracts::validateFilenameAndMime('../x.pdf', 'application/pdf');
assertTrue($path['ok'] === false, 'path traversal reddedilir');

$svg = PersonelBelgeContracts::validateFilenameAndMime('x.svg', 'image/svg+xml');
assertTrue($svg['ok'] === false, 'svg reddedilir');

assertTrue(PersonelBelgeContracts::validateContentMagic('%PDF-1.4 demo', 'pdf') === true, 'magic pdf');
assertTrue(PersonelBelgeContracts::validateContentMagic('not-pdf', 'pdf') === false, 'magic pdf fail');

$masked = PersonelBelgeContracts::maskBelgeNo('ABC1234567');
assertTrue($masked === '******4567', 'mask belge_no');

$b64 = PersonelBelgeBase64Guard::decode(base64_encode('%PDF-1.4'));
assertTrue($b64['ok'] === true && str_starts_with($b64['bytes'], '%PDF'), 'base64 decode ok');

$b64Bad = PersonelBelgeBase64Guard::decode("@@@");
assertTrue($b64Bad['ok'] === false && $b64Bad['http'] === 422, 'base64 invalid');

$huge = str_repeat('A', PersonelBelgeContracts::maxEncodedLength() + 4);
$b64Huge = PersonelBelgeBase64Guard::decode($huge);
assertTrue($b64Huge['ok'] === false && $b64Huge['http'] === 413, 'base64 oversized pre-decode');

$controller = file_get_contents(__DIR__ . '/../../api/src/Controllers/PersonelBelgelerController.php');
assertTrue(strpos($controller, 'PersonelBelgeContracts::deriveTakipDurumu') !== false, 'controller derive kullanir');
assertTrue(strpos($controller, 'PersonelBelgeStorageService') !== false, 'controller storage kullanir');
assertTrue(strpos($controller, 'iptal_nedeni') !== false, 'iptal neden zorunlu');
assertTrue(strpos($controller, 'replaceDosya') !== false, 'replaceDosya var');
assertTrue(!preg_match('/DELETE\s+FROM\s+surecler/i', $controller), 'hard delete surecler yok');
assertTrue(
    strpos(file_get_contents(__DIR__ . '/../../api/src/Services/PersonelBelge/PersonelBelgeKayitRepository.php'), "unset(\$payload['storage_key']") !== false
    || strpos(file_get_contents(__DIR__ . '/../../api/src/Services/PersonelBelge/PersonelBelgeKayitRepository.php'), 'stripSensitive') !== false,
    'audit strip sensitive fields mevcut'
);
assertTrue(!preg_match("/'dosya'\\s*=>\\s*\\[[^\\]]*storage_key/s", $controller), 'map dosya storage_key sizdirmaz');

$root = PersonelBelgeStorageService::storageRoot();
assertTrue(strpos(str_replace('\\', '/', $root), 'personel-belgeler') !== false, 'storage root personel-belgeler');
assertTrue(strpos(str_replace('\\', '/', $root), '/public/') === false, 'storage public altında değil');

$router = file_get_contents(__DIR__ . '/../../api/src/Router.php');
assertTrue(strpos($router, 'PersonelBelgelerController::updateKaydi') !== false, 'router update');
assertTrue(strpos($router, 'PersonelBelgelerController::replaceDosya') !== false, 'router replace');
assertTrue(strpos($router, 'PersonelBelgelerController::belgeTakip') !== false, 'router takip');
assertTrue(strpos($router, 'PersonelBelgelerController::indir') !== false, 'router indir');
assertTrue(strpos($router, 'PersonelBelgelerController::gecmis') !== false, 'router gecmis');

$migration = file_get_contents(__DIR__ . '/../../api/migrations/038_personel_belge_yonetimi.sql');
assertTrue(strpos($migration, 'personel_belge_dosya_surumleri') !== false, '038 surum tablosu');
assertTrue(strpos($migration, 'personel_belge_auditleri') !== false, '038 audit tablosu');
assertTrue(strpos($migration, 'ON DELETE RESTRICT') !== false, '038 RESTRICT');
assertTrue(strpos($migration, 'ON DELETE CASCADE') === false, '038 CASCADE yok');
assertTrue(!file_exists(__DIR__ . '/../../api/migrations/039_personel_belge_yonetimi.sql'), '039 yok');

echo 'verify-personel-belge-contracts: OK' . PHP_EOL;
