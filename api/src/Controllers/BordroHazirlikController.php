<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\CsvResponse;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\BordroHazirlikPreflightService;
use Medisa\Api\Services\BordroOnIzlemeService;
use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\PersonelBordroDevirService;
use PDO;

class BordroHazirlikController
{
    public static function preflight(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'bordro_on_izleme.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        try {
            JsonResponse::success(BordroHazirlikPreflightService::build($pdo, $subeId, $yil, $ay));
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bordro hazirlik preflight olusturulamadi.');
        }
    }

    /** S83 readiness alias — same projection as preflight. */
    public static function readiness(Request $request)
    {
        self::preflight($request);
    }

    public static function netMaasEksikleri(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'bordro_on_izleme.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        $departmanId = self::optionalQueryInt($request, 'departman_id', 1, 999999);
        try {
            $result = BordroHazirlikPreflightService::listNetMaasEksikleri($pdo, $subeId, $yil, $ay, $departmanId);
            JsonResponse::success($result);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Net maas eksikleri okunamadi.');
        }
    }

    public static function readinessExportCsv(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'bordro_on_izleme.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        try {
            $preflight = BordroHazirlikPreflightService::build($pdo, $subeId, $yil, $ay);
            $columns = [
                'domain_key',
                'domain_label',
                'status',
                'eksik_kayit_sayisi',
                'etkilenen_personel_sayisi',
                'aciklama',
                'action_link',
                'blocker_codes',
            ];
            $rows = [];
            foreach ($preflight['readiness_domains'] ?? [] as $domain) {
                $rows[] = [
                    'domain_key' => $domain['key'] ?? '',
                    'domain_label' => $domain['label'] ?? '',
                    'status' => $domain['status'] ?? '',
                    'eksik_kayit_sayisi' => $domain['eksik_kayit_sayisi'] ?? 0,
                    'etkilenen_personel_sayisi' => $domain['etkilenen_personel_sayisi'] ?? 0,
                    'aciklama' => $domain['aciklama'] ?? '',
                    'action_link' => $domain['action_link'] ?? '',
                    'blocker_codes' => implode('|', $domain['blocker_codes'] ?? []),
                ];
            }
            CsvResponse::send(
                sprintf('bordro-readiness-%04d-%02d.csv', $yil, $ay),
                $columns,
                $rows
            );
        } catch (\Throwable $e) {
            JsonResponse::serverError('Readiness export olusturulamadi.');
        }
    }

    public static function devirSablonCsv(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama_adaylari.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        $departmanId = self::optionalQueryInt($request, 'departman_id', 1, 999999);
        $eksikOnly = (string) $request->getQuery('eksik', '1') === '1';
        try {
            $items = self::enrichDevirler(
                $pdo,
                PersonelBordroDevirService::listForSube($pdo, $subeId, $yil, $ay),
                $subeId,
                $yil,
                $ay,
                $eksikOnly,
                $departmanId
            );
            $columns = [
                'sicil_no',
                'ad_soyad',
                'yil',
                'ay',
                'onceki_kumulatif_gelir_vergisi_matrahi',
                'onceki_kumulatif_gelir_vergisi',
                'onceki_kumulatif_sgk_matrahi',
                'aciklama',
            ];
            $rows = [];
            foreach ($items as $item) {
                $devir = $item['devir'] ?? null;
                $rows[] = [
                    'sicil_no' => $item['personel']['sicil'] ?? '',
                    'ad_soyad' => trim(($item['personel']['ad'] ?? '') . ' ' . ($item['personel']['soyad'] ?? '')),
                    'yil' => (int) $yil,
                    'ay' => (int) $ay,
                    'onceki_kumulatif_gelir_vergisi_matrahi' => $devir['onceki_kumulatif_gelir_vergisi_matrahi'] ?? '',
                    'onceki_kumulatif_gelir_vergisi' => $devir['onceki_kumulatif_gelir_vergisi'] ?? '',
                    'onceki_kumulatif_sgk_matrahi' => $devir['onceki_kumulatif_sgk_matrahi'] ?? '',
                    'aciklama' => count($item['eksik_alanlar'] ?? []) > 0 ? 'EKSIK_DEVIR' : '',
                ];
            }
            CsvResponse::send(
                sprintf('bordro-devir-sablon-%04d-%02d.csv', $yil, $ay),
                $columns,
                $rows
            );
        } catch (\Throwable $e) {
            JsonResponse::serverError('Devir sablonu olusturulamadi.');
        }
    }

    public static function onIzleme(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'bordro_on_izleme.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        $departmanId = self::optionalQueryInt($request, 'departman_id', 1, 999999);
        try {
            $ozet = BordroOnIzlemeService::buildDonemOzeti($pdo, $subeId, $yil, $ay, $departmanId);
            if (!RolePermissions::has($user, 'finans.view')) {
                $ozet = BordroOnIzlemeService::maskFinanceFields($ozet);
            }
            JsonResponse::success($ozet);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bordro on izleme olusturulamadi.');
        }
    }

    public static function adayDetay(Request $request, $adayId)
    {
        [$pdo, $user] = self::authOnly($request, 'bordro_on_izleme.view');
        $detail = BordroOnIzlemeService::getAdayDetay($pdo, (int) $adayId);
        if (!$detail) {
            JsonResponse::error(404, 'BORDRO_ADAY_NOT_FOUND', 'Bordro adayi bulunamadi.');
        }
        self::assertSubeScope($user, $request, (int) ($detail['sube_id'] ?? 0));
        if (!RolePermissions::has($user, 'finans.view')) {
            $detail = BordroOnIzlemeService::maskAdayFinanceFields($detail);
        }
        JsonResponse::success($detail);
    }

    public static function submitKontrol(Request $request, $calistirmaId)
    {
        [$pdo, $user] = self::authOnly($request, 'maas_hesaplama_adaylari.manage');
        $body = $request->getJsonBody();
        $not = trim((string) ($body['muhasebe_kontrol_notu'] ?? ''));
        try {
            JsonResponse::success([
                'calistirma' => BordroOnIzlemeService::submitMuhasebeKontrol($pdo, (int) $calistirmaId, $not, $user),
            ]);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Muhasebe kontrolu gonderilemedi.');
        }
    }

    public static function geriGonder(Request $request, $calistirmaId)
    {
        [$pdo, $user] = self::authOnly($request, 'bordro_kesinlestirme.approve');
        $body = $request->getJsonBody();
        $not = trim((string) ($body['not'] ?? ''));
        try {
            JsonResponse::success([
                'calistirma' => BordroOnIzlemeService::geriGonder($pdo, (int) $calistirmaId, $not, $user),
            ]);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bordro geri gonderilemedi.');
        }
    }

    public static function kesinlestir(Request $request, $calistirmaId)
    {
        [$pdo, $user] = self::authOnly($request, 'bordro_kesinlestirme.approve');
        try {
            JsonResponse::success([
                'calistirma' => BordroOnIzlemeService::kesinlestir($pdo, (int) $calistirmaId, $user),
            ]);
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bordro kesinlestirilemedi.');
        }
    }

    public static function listDevirler(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama_adaylari.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        $eksik = (string) $request->getQuery('eksik', '') === '1';
        $departmanId = self::optionalQueryInt($request, 'departman_id', 1, 999999);
        try {
            $items = self::enrichDevirler(
                $pdo,
                PersonelBordroDevirService::listForSube($pdo, $subeId, $yil, $ay),
                $subeId,
                $yil,
                $ay,
                $eksik,
                $departmanId
            );
            JsonResponse::success(['items' => $items]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Devir listesi okunamadi.');
        }
    }

    public static function importDevirler(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'maas_hesaplama_adaylari.manage');
        $body = $request->getJsonBody();
        $yil = (int) ($body['yil'] ?? 0);
        $ay = (int) ($body['ay'] ?? 0);
        if ($yil < 2000 || $yil > 2100 || $ay < 1 || $ay > 12) {
            self::validationError('yil', 'Gecerli yil/ay zorunludur.');
        }
        $dryRun = array_key_exists('dry_run', $body) ? (bool) $body['dry_run'] : true;
        $rows = $body['rows'] ?? [];
        if (!is_array($rows)) {
            self::validationError('rows', 'rows dizisi zorunludur.');
        }
        try {
            JsonResponse::success(PersonelBordroDevirService::processImport($pdo, $subeId, $yil, $ay, $rows, $dryRun, $user));
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Devir import islenemedi.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $devirler
     * @return array<int, array<string, mixed>>
     */
    private static function enrichDevirler(PDO $pdo, array $devirler, $subeId, $yil, $ay, $eksikOnly, $departmanId = null)
    {
        $sql = "SELECT p.id, p.ad, p.soyad, p.sicil_no, p.departman_id, d.ad AS departman_ad
             FROM personeller p
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             WHERE p.sube_id = :sube AND p.aktif_durum = 'AKTIF'";
        $params = ['sube' => (int) $subeId];
        if ($departmanId !== null) {
            $sql .= ' AND p.departman_id = :departman_id';
            $params['departman_id'] = (int) $departmanId;
        }
        $sql .= ' ORDER BY p.ad ASC, p.soyad ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $personeller = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $devirByPersonel = [];
        foreach ($devirler as $devir) {
            $devirByPersonel[(int) $devir['personel_id']] = $devir;
        }
        $items = [];
        foreach ($personeller as $personel) {
            $pid = (int) $personel['id'];
            $devir = $devirByPersonel[$pid] ?? null;
            $eksikAlanlar = [];
            if ($ay > 1 && $devir === null) {
                $eksikAlanlar = ['onceki_kumulatif_gelir_vergisi_matrahi', 'onceki_kumulatif_gelir_vergisi'];
            }
            if ($eksikOnly && count($eksikAlanlar) === 0) {
                continue;
            }
            $items[] = [
                'personel' => [
                    'ad' => (string) $personel['ad'],
                    'soyad' => (string) $personel['soyad'],
                    'sicil' => (string) $personel['sicil_no'],
                    'departman' => (string) ($personel['departman_ad'] ?? ''),
                ],
                'donem' => sprintf('%04d-%02d', (int) $yil, (int) $ay),
                'devir' => $devir,
                'eksik_alanlar' => $eksikAlanlar,
                'dogrulama_durumu' => count($eksikAlanlar) === 0 ? 'TAMAM' : 'EKSIK',
            ];
        }

        return $items;
    }

    /** @return array{0: \PDO, 1: array<string, mixed>, 2: int} */
    private static function context(Request $request, $permission)
    {
        [$pdo, $user] = self::authOnly($request, $permission);
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Bordro hazirlik icin aktif sube secilmelidir.');
        }

        return [$pdo, $user, (int) $scope];
    }

    /** @return array{0: \PDO, 1: array<string, mixed>} */
    private static function authOnly(Request $request, $permission)
    {
        $user = AuthMiddleware::authenticate($request, true);
        if (!RolePermissions::has($user, $permission)) {
            JsonResponse::error(403, 'BORDRO_ACCESS_FORBIDDEN', 'Bordro hazirlik merkezine erisim yetkiniz yok.');
        }

        return [Connection::get(), $user];
    }

    private static function assertSubeScope(array $user, Request $request, $subeId)
    {
        SubeScope::assertPersonelAccess($user, $request, (int) $subeId);
    }

    private static function readQueryInt(Request $request, $key, $min, $max)
    {
        $value = (int) $request->getQuery($key, '0');
        if ($value < $min || $value > $max) {
            self::validationError($key, 'Gecersiz ' . $key);
        }

        return $value;
    }

    private static function optionalQueryInt(Request $request, $key, $min, $max)
    {
        $raw = $request->getQuery($key, '');
        if ($raw === '' || $raw === null) {
            return null;
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            self::validationError($key, 'Gecersiz ' . $key);
        }

        return $value;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', (string) $message, (string) $field);
        exit;
    }

    private static function domainError(MaasHesaplamaException $e)
    {
        JsonResponse::error(
            $e->getHttpStatus() > 0 ? $e->getHttpStatus() : 409,
            $e->getCodeString(),
            $e->getMessage(),
            null,
            $e->getDetails()
        );
    }
}
