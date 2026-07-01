<?php

declare(strict_types=1);

namespace Medisa\Api;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\LoginController;
use Medisa\Api\Controllers\BildirimlerController;
use Medisa\Api\Controllers\EkOdemeKesintiController;
use Medisa\Api\Controllers\PersonelBelgelerController;
use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Controllers\PuantajController;
use Medisa\Api\Controllers\RaporlarController;
use Medisa\Api\Controllers\ReferansController;
use Medisa\Api\Controllers\RevizyonController;
use Medisa\Api\Controllers\SureclerController;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Controllers\ZimmetlerController;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;

class Router
{
    /** @var Request */
    private $request;

    public function __construct()
    {
        $this->request = new Request();
    }

    public function dispatch()
    {
        $method = $this->request->getMethod();
        $path = $this->request->getPath();

        if ($method === 'OPTIONS') {
            JsonResponse::success(['ok' => true]);
        }

        if ($path === '/health' && $method === 'GET') {
            JsonResponse::success([
                'status' => 'ok',
                'service' => 'personelmedisa-api',
            ]);
        }

        if ($path === '/auth/login' && $method === 'POST') {
            LoginController::login($this->request);
        }

        if ($method === 'PUT' && preg_match('#^/personeller/(\d+)$#', $path, $matches)) {
            PersonellerController::update($this->request, $matches[1]);
        }
        if ($method === 'PUT' && preg_match('#^/gunluk-puantaj/(\d+)/([^/]+)$#', $path, $matches)) {
            PuantajController::upsert($this->request, $matches[1], $matches[2]);
        }
        if ($method === 'POST' && $path === '/puantaj/muhurle') {
            PuantajController::muhurleAylik($this->request);
        }
        if ($method === 'POST' && $path === '/yonetim/aylik-ozet/bolum-onay') {
            JsonResponse::methodNotAllowed();
        }
        if ($method === 'POST' && $path === '/yonetim/aylik-ozet/ay-kapat') {
            JsonResponse::methodNotAllowed();
        }
        if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && preg_match('#^/yonetim/subeler(/(\d+))?$#', $path)) {
            JsonResponse::methodNotAllowed();
        }

        if ($path === '/personeller' && $method === 'GET') {
            PersonellerController::list($this->request);
        }
        if ($path === '/personeller' && $method === 'POST') {
            PersonellerController::create($this->request);
        }
        if ($method === 'GET' && preg_match('#^/personeller/(\d+)$#', $path, $matches)) {
            PersonellerController::detail($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/personeller/(\d+)/belge-durumu$#', $path, $matches)) {
            PersonelBelgelerController::belgeDurumu($this->request, $matches[1]);
        }
        if ($method === 'PUT' && preg_match('#^/personeller/(\d+)/belge-durumu$#', $path, $matches)) {
            PersonelBelgelerController::updateBelgeDurumu($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/personeller/(\d+)/belge-kayitlari$#', $path, $matches)) {
            PersonelBelgelerController::listKayitlari($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/personeller/(\d+)/belge-kayitlari$#', $path, $matches)) {
            PersonelBelgelerController::createKaydi($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/belge-kayitlari/(\d+)/iptal$#', $path, $matches)) {
            PersonelBelgelerController::cancelKaydi($this->request, $matches[1]);
        }

        if ($path === '/referans/departmanlar' && $method === 'GET') {
            ReferansController::departmanlar($this->request);
        }
        if ($path === '/referans/gorevler' && $method === 'GET') {
            ReferansController::gorevler($this->request);
        }
        if ($path === '/referans/personel-tipleri' && $method === 'GET') {
            ReferansController::personelTipleri($this->request);
        }
        if ($path === '/referans/bagli-amirler' && $method === 'GET') {
            ReferansController::bagliAmirler($this->request);
        }
        if ($path === '/referans/surec-turleri' && $method === 'GET') {
            ReferansController::surecTurleri($this->request);
        }
        if ($path === '/referans/ucret-tipleri' && $method === 'GET') {
            ReferansController::ucretTipleri($this->request);
        }
        if ($path === '/referans/prim-kurallari' && $method === 'GET') {
            ReferansController::primKurallari($this->request);
        }
        if ($path === '/referans/bildirim-turleri' && $method === 'GET') {
            ReferansController::bildirimTurleri($this->request);
        }

        if ($path === '/bildirimler' && $method === 'GET') {
            BildirimlerController::list($this->request);
        }
        if ($path === '/surecler' && $method === 'GET') {
            SureclerController::list($this->request);
        }
        if ($path === '/surecler' && $method === 'POST') {
            SureclerController::create($this->request);
        }
        if ($path === '/zimmetler' && $method === 'GET') {
            ZimmetlerController::list($this->request);
        }
        if ($path === '/haftalik-kapanis/revizyon-talepleri' && $method === 'GET') {
            RevizyonController::talepleri($this->request);
        }
        if ($path === '/haftalik-kapanis/revizyon-corrections' && $method === 'GET') {
            RevizyonController::corrections($this->request);
        }

        if ($path === '/yonetim/subeler' && $method === 'GET') {
            YonetimController::subeler($this->request);
        }
        if ($path === '/yonetim/kullanicilar' && $method === 'GET') {
            YonetimController::kullanicilar($this->request);
        }
        if ($path === '/yonetim/kullanicilar' && $method === 'POST') {
            YonetimController::kullaniciOlustur($this->request);
        }
        if ($method === 'PUT' && preg_match('#^/yonetim/kullanicilar/(\d+)$#', $path, $matches)) {
            YonetimController::kullaniciGuncelle($this->request, $matches[1]);
        }
        if ($path === '/yonetim/aylik-ozet' && $method === 'GET') {
            YonetimController::aylikOzet($this->request);
        }

        if ($method === 'GET' && preg_match('#^/gunluk-puantaj/(\d+)/([^/]+)$#', $path, $matches)) {
            PuantajController::detail($this->request, $matches[1], $matches[2]);
        }

        if ($path === '/ek-odeme-kesinti' && $method === 'GET') {
            EkOdemeKesintiController::list($this->request);
        }
        if ($path === '/ek-odeme-kesinti' && $method === 'POST') {
            EkOdemeKesintiController::create($this->request);
        }
        if ($method === 'GET' && preg_match('#^/ek-odeme-kesinti/(\d+)$#', $path, $matches)) {
            EkOdemeKesintiController::detail($this->request, $matches[1]);
        }
        if ($method === 'PUT' && preg_match('#^/ek-odeme-kesinti/(\d+)$#', $path, $matches)) {
            EkOdemeKesintiController::update($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/ek-odeme-kesinti/(\d+)/iptal$#', $path, $matches)) {
            EkOdemeKesintiController::cancel($this->request, $matches[1]);
        }

        if ($method === 'GET' && preg_match('#^/raporlar/([^/]+)$#', $path, $matches)) {
            RaporlarController::show($this->request, $matches[1]);
        }

        if ($path !== '/health' && $path !== '/auth/login') {
            AuthMiddleware::authenticate($this->request, true);
        }

        JsonResponse::notFound('Endpoint bulunamadi.');
    }
}
