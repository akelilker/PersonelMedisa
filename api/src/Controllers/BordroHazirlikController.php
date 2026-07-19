<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\BordroHazirlikPreflightService;
use Medisa\Api\Services\BordroOnIzlemeService;
use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\PersonelBordroDevirService;
use Medisa\Api\Services\SirketCalismaPolitikasiException;
use Medisa\Api\Services\SirketCalismaPolitikasiService;

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

    public static function onIzleme(Request $request)
    {
        [$pdo, $user, $subeId] = self::context($request, 'bordro_on_izleme.view');
        $yil = self::readQueryInt($request, 'yil', 2000, 2100);
        $ay = self::readQueryInt($request, 'ay', 1, 12);
        $departmanId = self::optionalQueryInt($request, 'departman_id', 1, 999999);
        try {
            JsonResponse::success(BordroOnIzlemeService::buildDonemOzeti($pdo, $subeId, $yil, $ay, $departmanId));
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
        try {
            $items = self::enrichDevirler($pdo, PersonelBordroDevirService::listForSube($pdo, $subeId, $yil, $ay), $subeId, $yil, $ay, $eksik);
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
        $dryRun = (bool) ($body['dry_run'] ?? true);
        $rows = $body['rows'] ?? [];
        if (!is_array($rows)) {
            self::validationError('rows', 'rows dizisi zorunludur.');
        }
        try {
            JsonResponse::success(self::processDevirImport($pdo, $subeId, $yil, $ay, $rows, $dryRun, $user));
        } catch (MaasHesaplamaException $e) {
            self::domainError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Devir import islenemedi.');
        }
    }

    /** @return array<string, mixed> */
    private static function processDevirImport(PDO $pdo, $subeId, $yil, $ay, array $rows, $dryRun, array $user)
    {
        $results = [];
        $success = 0;
        $failed = 0;
        $seen = [];
        foreach ($rows as $index => $row) {
            $sicil = trim((string) ($row['sicil'] ?? $row['sicil_no'] ?? ''));
            if ($sicil === '') {
                $results[] = ['satir' => $index + 1, 'ok' => false, 'hata' => 'sicil zorunlu'];
                $failed++;
                continue;
            }
            $personel = self::findPersonelBySicil($pdo, $sicil, (int) $subeId);
            if (!$personel) {
                $results[] = ['satir' => $index + 1, 'sicil' => $sicil, 'ok' => false, 'hata' => 'personel bulunamadi'];
                $failed++;
                continue;
            }
            $key = $sicil . ':' . $yil . ':' . $ay;
            if (isset($seen[$key])) {
                $results[] = ['satir' => $index + 1, 'sicil' => $sicil, 'ok' => false, 'hata' => 'duplicate personel/donem'];
                $failed++;
                continue;
            }
            $seen[$key] = true;
            $payload = [
                'personel_id' => (int) $personel['id'],
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'onceki_kumulatif_gelir_vergisi_matrahi' => (string) ($row['onceki_kumulatif_gelir_vergisi_matrahi'] ?? $row['gv_matrah'] ?? '0'),
                'onceki_kumulatif_gelir_vergisi' => (string) ($row['onceki_kumulatif_gelir_vergisi'] ?? $row['gv'] ?? '0'),
                'devir_kaynagi' => 'CSV_IMPORT',
            ];
            if (!$dryRun) {
                PersonelBordroDevirService::upsert($pdo, $payload, $user);
            }
            $results[] = ['satir' => $index + 1, 'sicil' => $sicil, 'ok' => true];
            $success++;
        }
        if (!$dryRun) {
            $pdo->prepare(
                'INSERT INTO personel_bordro_devir_importlari (sube_id, yil, ay, dry_run, toplam_satir, basarili_satir, hatali_satir, hata_ozeti, actor_id)
                 VALUES (:s, :y, :a, 0, :t, :b, :h, :ozet, :actor)'
            )->execute([
                's' => (int) $subeId,
                'y' => (int) $yil,
                'a' => (int) $ay,
                't' => count($rows),
                'b' => $success,
                'h' => $failed,
                'ozet' => json_encode($results, JSON_UNESCAPED_UNICODE),
                'actor' => isset($user['id']) ? (int) $user['id'] : null,
            ]);
        }

        return [
            'dry_run' => (bool) $dryRun,
            'toplam_satir' => count($rows),
            'basarili_satir' => $success,
            'hatali_satir' => $failed,
            'satirlar' => $results,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function findPersonelBySicil(PDO $pdo, $sicil, $subeId)
    {
        $stmt = $pdo->prepare(
            "SELECT id, ad, soyad, sicil_no, departman_id FROM personeller
             WHERE sicil_no = :sicil AND sube_id = :sube AND durum = 'AKTIF' LIMIT 1"
        );
        $stmt->execute(['sicil' => (string) $sicil, 'sube' => (int) $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<int, array<string, mixed>> $devirler @return array<int, array<string, mixed>> */
    private static function enrichDevirler(PDO $pdo, array $devirler, $subeId, $yil, $ay, $eksikOnly)
    {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.ad, p.soyad, p.sicil_no, p.departman_id, d.ad AS departman_ad
             FROM personeller p
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             WHERE p.sube_id = :sube AND p.durum = 'AKTIF'
             ORDER BY p.ad ASC, p.soyad ASC"
        );
        $stmt->execute(['sube' => (int) $subeId]);
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
        JsonResponse::error($e->getCode() > 0 ? (int) $e->getCode() : 409, $e->getErrorCode(), $e->getMessage(), null, $e->getContext());
    }
}
