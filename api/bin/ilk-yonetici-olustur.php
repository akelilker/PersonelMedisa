<?php

declare(strict_types=1);

/**
 * Yeni şirket kurulumu: ilk Genel Yönetici hesabını oluşturur. Web isteklerine 404.
 *
 * Yalnız sistemde hiç Genel Yönetici yokken çalışır (migration'lar boş DB'ye
 * uygulandıktan sonra, bir kez). Medisa'ya özgü hesap gerektirmez.
 * Parola argüman olarak verilmez (shell geçmişine düşmesin): ortam değişkeni
 * MEDISA_ILK_YONETICI_PAROLA ya da STDIN'in ilk satırı.
 *
 *   MEDISA_ILK_YONETICI_PAROLA='...' php api/bin/ilk-yonetici-olustur.php --kullanici-adi=yonetici --ad-soyad="Ad SOYAD"
 */

use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Auth\IlkYoneticiKurulumException;
use Medisa\Api\Services\Auth\IlkYoneticiKurulumService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$options = getopt('', ['kullanici-adi:', 'ad-soyad:']);
$username = is_string($options['kullanici-adi'] ?? null) ? $options['kullanici-adi'] : '';
$adSoyad = is_string($options['ad-soyad'] ?? null) ? $options['ad-soyad'] : '';
$password = getenv('MEDISA_ILK_YONETICI_PAROLA');
if (!is_string($password) || $password === '') {
    $line = fgets(STDIN);
    $password = $line === false ? '' : rtrim($line, "\r\n");
}

try {
    $pdo = Connection::get();
} catch (Throwable $e) {
    fwrite(STDERR, "DB_CONNECTION_FAILED\n");
    exit(1);
}

try {
    $result = IlkYoneticiKurulumService::olustur($pdo, $username, $adSoyad, $password);
} catch (IlkYoneticiKurulumException $e) {
    fwrite(STDERR, 'ILK_YONETICI_REDDEDILDI ' . $e->errorCode . ': ' . $e->getMessage() . "\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, "ILK_YONETICI_OLUSTURULAMADI\n");
    exit(1);
}

echo 'ILK_YONETICI_OLUSTURULDU id=' . $result['user_id'] . ' username=' . $result['username']
    . ' silinmesi_korunur=' . ($result['silinmesi_korunur'] ? '1' : '0')
    . ' actor_identity_id=' . ($result['actor_identity_id'] ?? 'yok') . ' (kurulum_beyani)'
    . ' k1_kimlik_modu=' . $result['k1_kimlik_modu'] . "\n";
exit(0);
