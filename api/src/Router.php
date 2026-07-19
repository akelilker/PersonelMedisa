<?php

declare(strict_types=1);

namespace Medisa\Api;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\LoginController;
use Medisa\Api\Controllers\AylikBildirimOnaylariController;
use Medisa\Api\Controllers\GenelYoneticiBildirimOnaylariController;
use Medisa\Api\Controllers\BildirimPuantajEtkiAdaylariController;
use Medisa\Api\Controllers\BildirimlerController;
use Medisa\Api\Controllers\DonemKapanisController;
use Medisa\Api\Controllers\HaftalikBildirimMutabakatlariController;
use Medisa\Api\Controllers\HaftalikKapanisController;
use Medisa\Api\Controllers\EkOdemeKesintiController;
use Medisa\Api\Controllers\FazlaCalismaOdemeTercihiController;
use Medisa\Api\Controllers\MaasHesaplamaController;
use Medisa\Api\Controllers\MevzuatParametreController;
use Medisa\Api\Controllers\PersonelBelgelerController;
use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Controllers\PersonelUcretController;
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
        if ($path === '/puantaj/donem-kapanis-preflight' && $method === 'GET') {
            DonemKapanisController::summary($this->request);
        }
        if ($path === '/puantaj/donem-kapanis-preflight/items' && $method === 'GET') {
            DonemKapanisController::items($this->request);
        }
        if ($path === '/puantaj/donem-kapanis-preflight/export.csv' && $method === 'GET') {
            DonemKapanisController::exportCsv($this->request);
        }
        if ($path === '/puantaj/donem-kapanis-auditleri' && $method === 'GET') {
            DonemKapanisController::listAudits($this->request);
        }
        if ($path === '/puantaj/bildirim-etki-adaylari/rapor' && $method === 'GET') {
            BildirimPuantajEtkiAdaylariController::report($this->request);
        }
        if ($path === '/puantaj/bildirim-etki-adaylari/rapor/export.csv' && $method === 'GET') {
            BildirimPuantajEtkiAdaylariController::reportExportCsv($this->request);
        }
        if ($path === '/puantaj/bildirim-etki-adaylari/ozet' && $method === 'GET') {
            BildirimPuantajEtkiAdaylariController::summary($this->request);
        }
        if ($path === '/puantaj/bildirim-etki-adaylari/hazirla' && $method === 'POST') {
            BildirimPuantajEtkiAdaylariController::generate($this->request);
        }
        if ($path === '/puantaj/bildirim-etki-adaylari' && $method === 'GET') {
            BildirimPuantajEtkiAdaylariController::list($this->request);
        }
        if ($method === 'POST' && preg_match('#^/puantaj/bildirim-etki-adaylari/(\d+)/yok-say$#', $path, $matches)) {
            BildirimPuantajEtkiAdaylariController::dismiss($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/puantaj/bildirim-etki-adaylari/(\d+)/manuel-uygula$#', $path, $matches)) {
            BildirimPuantajEtkiAdaylariController::manualApply($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/puantaj/bildirim-etki-adaylari/(\d+)/uygula$#', $path, $matches)) {
            BildirimPuantajEtkiAdaylariController::apply($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/puantaj/bildirim-etki-adaylari/(\d+)/cakisma-coz$#', $path, $matches)) {
            BildirimPuantajEtkiAdaylariController::resolveConflict($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/puantaj/bildirim-etki-adaylari/(\d+)$#', $path, $matches)) {
            BildirimPuantajEtkiAdaylariController::detail($this->request, $matches[1]);
        }
        if ($method === 'POST' && $path === '/yonetim/aylik-ozet/bolum-onay') {
            YonetimController::aylikOzetBolumOnay($this->request);
        }
        if ($method === 'POST' && $path === '/yonetim/aylik-ozet/ay-kapat') {
            YonetimController::aylikOzetAyKapat($this->request);
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
        if ($method === 'GET' && preg_match('#^/personeller/(\d+)/ucretler$#', $path, $matches)) {
            PersonelUcretController::list($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/personeller/(\d+)/ucretler/aktif$#', $path, $matches)) {
            PersonelUcretController::aktif($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/personeller/(\d+)/ucretler$#', $path, $matches)) {
            PersonelUcretController::create($this->request, $matches[1]);
        }
        if ($method === 'PUT' && preg_match('#^/personeller/(\d+)/ucretler/(\d+)$#', $path, $matches)) {
            PersonelUcretController::update($this->request, $matches[1], $matches[2]);
        }
        if ($method === 'POST' && preg_match('#^/personeller/(\d+)/ucretler/(\d+)/iptal$#', $path, $matches)) {
            PersonelUcretController::iptal($this->request, $matches[1], $matches[2]);
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
        if ($path === '/referans/departmanlar' && $method === 'POST') {
            ReferansController::createDepartman($this->request);
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

        if ($path === '/maas-hesaplama/preflight' && $method === 'GET') {
            MaasHesaplamaController::preflight($this->request);
        }
        if ($path === '/maas-hesaplama/snapshotlar' && $method === 'GET') {
            MaasHesaplamaController::listSnapshots($this->request);
        }
        if ($path === '/maas-hesaplama/snapshotlar' && $method === 'POST') {
            MaasHesaplamaController::create($this->request);
        }
        if ($path === '/maas-hesaplama/auditler' && $method === 'GET') {
            MaasHesaplamaController::listAudits($this->request);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/snapshotlar/(\d+)$#', $path, $matches)) {
            MaasHesaplamaController::detail($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/maas-hesaplama/snapshotlar/(\d+)/iptal$#', $path, $matches)) {
            MaasHesaplamaController::cancel($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/snapshotlar/(\d+)/audit$#', $path, $matches)) {
            MaasHesaplamaController::audit($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/snapshotlar/(\d+)/hesaplama-preflight$#', $path, $matches)) {
            MaasHesaplamaController::calculationPreflight($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/maas-hesaplama/snapshotlar/(\d+)/hesapla$#', $path, $matches)) {
            MaasHesaplamaController::calculate($this->request, $matches[1]);
        }
        if ($path === '/maas-hesaplama/calistirmalar' && $method === 'GET') {
            MaasHesaplamaController::listCalistirmalar($this->request);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/calistirmalar/(\d+)$#', $path, $matches)) {
            MaasHesaplamaController::calistirmaDetail($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/calistirmalar/(\d+)/adaylar$#', $path, $matches)) {
            MaasHesaplamaController::listAdaylar($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/calistirmalar/(\d+)/audit$#', $path, $matches)) {
            MaasHesaplamaController::calistirmaAudit($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/maas-hesaplama/calistirmalar/(\d+)/iptal$#', $path, $matches)) {
            MaasHesaplamaController::cancelCalistirma($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/adaylar/(\d+)$#', $path, $matches)) {
            MaasHesaplamaController::adayDetail($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/maas-hesaplama/adaylar/(\d+)/kalemler$#', $path, $matches)) {
            MaasHesaplamaController::adayKalemler($this->request, $matches[1]);
        }
        if ($path === '/maas-hesaplama/yasal-katalog' && $method === 'GET') {
            MaasHesaplamaController::legalCatalog($this->request);
        }
        if ($path === '/maas-hesaplama/devirler' && $method === 'GET') {
            MaasHesaplamaController::listDevirler($this->request);
        }
        if ($path === '/maas-hesaplama/devirler' && $method === 'POST') {
            MaasHesaplamaController::upsertDevir($this->request);
        }

        if ($path === '/mevzuat-parametreleri' && $method === 'GET') {
            MevzuatParametreController::list($this->request);
        }
        if ($path === '/mevzuat-parametreleri' && $method === 'POST') {
            MevzuatParametreController::create($this->request);
        }
        if ($method === 'PUT' && preg_match('#^/mevzuat-parametreleri/(\d+)$#', $path, $matches)) {
            MevzuatParametreController::update($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/mevzuat-parametreleri/(\d+)/iptal$#', $path, $matches)) {
            MevzuatParametreController::iptal($this->request, $matches[1]);
        }

        if ($path === '/bildirimler' && $method === 'GET') {
            BildirimlerController::list($this->request);
        }
        if ($path === '/bildirimler' && $method === 'POST') {
            BildirimlerController::create($this->request);
        }
        if ($path === '/bildirimler/birim-amiri-secenekleri' && $method === 'GET') {
            BildirimlerController::birimAmiriSecenekleri($this->request);
        }
        if ($method === 'POST' && preg_match('#^/bildirimler/(\d+)/submit$#', $path, $matches)) {
            BildirimlerController::submit($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/bildirimler/(\d+)/request-correction$#', $path, $matches)) {
            BildirimlerController::requestCorrection($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/bildirimler/(\d+)/iptal$#', $path, $matches)) {
            BildirimlerController::cancel($this->request, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/bildirimler/(\d+)$#', $path, $matches)) {
            BildirimlerController::detail($this->request, $matches[1]);
        }
        if ($method === 'PUT' && preg_match('#^/bildirimler/(\d+)$#', $path, $matches)) {
            BildirimlerController::update($this->request, $matches[1]);
        }
        if ($path === '/aylik-bildirim-onaylari/ozet' && $method === 'GET') {
            AylikBildirimOnaylariController::summary($this->request);
        }
        if ($path === '/aylik-bildirim-onaylari' && $method === 'POST') {
            AylikBildirimOnaylariController::approve($this->request);
        }
        if ($method === 'GET' && preg_match('#^/aylik-bildirim-onaylari/(\d+)$#', $path, $matches)) {
            AylikBildirimOnaylariController::detail($this->request, $matches[1]);
        }
        if ($path === '/genel-yonetici-bildirim-onaylari/ozet' && $method === 'GET') {
            GenelYoneticiBildirimOnaylariController::summary($this->request);
        }
        if ($path === '/genel-yonetici-bildirim-onaylari' && $method === 'POST') {
            GenelYoneticiBildirimOnaylariController::approve($this->request);
        }
        if ($method === 'GET' && preg_match('#^/genel-yonetici-bildirim-onaylari/(\d+)$#', $path, $matches)) {
            GenelYoneticiBildirimOnaylariController::detail($this->request, $matches[1]);
        }
        if ($path === '/haftalik-bildirim-mutabakatlari/ozet' && $method === 'GET') {
            HaftalikBildirimMutabakatlariController::summary($this->request);
        }
        if ($path === '/haftalik-bildirim-mutabakatlari' && $method === 'POST') {
            HaftalikBildirimMutabakatlariController::approve($this->request);
        }
        if ($method === 'GET' && preg_match('#^/haftalik-bildirim-mutabakatlari/(\d+)$#', $path, $matches)) {
            HaftalikBildirimMutabakatlariController::detail($this->request, $matches[1]);
        }
        if ($path === '/surecler' && $method === 'GET') {
            SureclerController::list($this->request);
        }
        if ($path === '/surecler' && $method === 'POST') {
            SureclerController::create($this->request);
        }
        if ($method === 'GET' && preg_match('#^/surecler/(\d+)$#', $path, $matches)) {
            SureclerController::detail($this->request, $matches[1]);
        }
        if ($method === 'PUT' && preg_match('#^/surecler/(\d+)$#', $path, $matches)) {
            SureclerController::update($this->request, $matches[1]);
        }
        if ($method === 'POST' && preg_match('#^/surecler/(\d+)/iptal$#', $path, $matches)) {
            SureclerController::cancel($this->request, $matches[1]);
        }
        if ($path === '/zimmetler' && $method === 'GET') {
            ZimmetlerController::list($this->request);
        }
        if ($path === '/zimmetler' && $method === 'POST') {
            ZimmetlerController::create($this->request);
        }
        if ($path === '/fazla-calisma-odeme-tercihi' && $method === 'GET') {
            FazlaCalismaOdemeTercihiController::get($this->request);
        }
        if ($path === '/fazla-calisma-odeme-tercihi' && $method === 'PUT') {
            FazlaCalismaOdemeTercihiController::put($this->request);
        }
        if ($path === '/haftalik-kapanis/yillik-fazla-calisma' && $method === 'GET') {
            HaftalikKapanisController::yillikFazlaCalisma($this->request);
        }
        if ($path === '/haftalik-kapanis' && $method === 'POST') {
            HaftalikKapanisController::create($this->request);
        }
        if ($method === 'GET' && preg_match('#^/haftalik-kapanis/(\d+)$#', $path, $matches)) {
            HaftalikKapanisController::detail($this->request, $matches[1]);
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
        if ($path === '/yonetim/subeler' && $method === 'POST') {
            YonetimController::subeOlustur($this->request);
        }
        if ($method === 'PUT' && preg_match('#^/yonetim/subeler/(\d+)$#', $path, $matches)) {
            YonetimController::subeGuncelle($this->request, $matches[1]);
        }
        if ($method === 'DELETE' && preg_match('#^/yonetim/subeler/(\d+)$#', $path, $matches)) {
            YonetimController::subeSil($this->request, $matches[1]);
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
