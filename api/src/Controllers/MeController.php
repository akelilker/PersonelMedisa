<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Izin\YillikIzinBakiyeService;
use Medisa\Api\Services\Izin\YillikIzinHakDuzeltmeException;
use Medisa\Api\Services\Qr\QrAttendanceEventService;
use Medisa\Api\Services\Qr\QrAttendanceException;
use Medisa\Api\Services\Qr\QrAttendanceIntervalReadService;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use Medisa\Api\Services\SelfService\SelfPuantajReadService;
use PDO;

/**
 * Self-service /me surfaces (S3B reads + S3C QR scan/history + S3D QR intervals).
 * Self-scope only; no arbitrary personel_id / client timestamp.
 */
class MeController
{
    private const YILLIK_LIMIT_DAKIKA = 16200;
    private const YILLIK_YAKLASMA_ESIK_DAKIKA = 15600;

    public static function me(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.view');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $ctx = SelfPersonelContext::resolveForSelfService($user, $pdo, true);

        JsonResponse::success([
            'user_id' => (int) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'ad_soyad' => (string) ($user['ad_soyad'] ?? ''),
            'rol' => (string) ($user['rol'] ?? ''),
            'personel_id' => (int) $ctx['personel_id'],
            'personel' => [
                'id' => (int) $ctx['personel_id'],
                'ad' => (string) $ctx['ad'],
                'soyad' => (string) $ctx['soyad'],
                'ad_soyad' => (string) $ctx['ad_soyad'],
                'sube_id' => (int) $ctx['sube_id'],
                'sube_ad' => (string) $ctx['sube_ad'],
                'departman_id' => $ctx['departman_id'],
                'departman_ad' => $ctx['departman_ad'],
                'gorev_id' => $ctx['gorev_id'],
                'gorev_ad' => $ctx['gorev_ad'],
                'aktif_durum' => (string) $ctx['aktif_durum'],
            ],
        ]);
    }

    public static function puantaj(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.puantaj.view');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $ctx = SelfPersonelContext::resolveForSelfService($user, $pdo, true);
        $defaults = SelfPuantajReadService::defaultMonthRange();
        $from = $request->getQuery('from', $defaults['from']);
        $to = $request->getQuery('to', $defaults['to']);

        JsonResponse::success(
            SelfPuantajReadService::listForPersonel($pdo, (int) $ctx['personel_id'], $from, $to)
        );
    }

    public static function yillikIzinBakiye(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.yillik_izin.view');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $ctx = SelfPersonelContext::resolveForSelfService($user, $pdo, true);
        $ref = $request->getQuery('referans_tarih', null);

        try {
            JsonResponse::success(
                YillikIzinBakiyeService::assemble($pdo, (int) $ctx['personel_id'], $ref)
            );
        } catch (YillikIzinHakDuzeltmeException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Yillik izin bakiyesi hesaplanamadi.');
        }
    }

    public static function fazlaCalisma(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.fazla_calisma.view');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $ctx = SelfPersonelContext::resolveForSelfService($user, $pdo, true);
        $personelId = (int) $ctx['personel_id'];
        $subeId = (int) $ctx['sube_id'];

        $yilRaw = $request->getQuery('yil', null);
        if ($yilRaw === null || trim((string) $yilRaw) === '') {
            try {
                $tz = new \DateTimeZone('Europe/Istanbul');
                $yil = (int) (new \DateTimeImmutable('now', $tz))->format('Y');
            } catch (\Throwable $e) {
                $yil = (int) date('Y');
            }
        } else {
            $yil = (int) $yilRaw;
            if ($yil < 1) {
                JsonResponse::badRequest('yil pozitif tam sayi olmalidir.', 'VALIDATION_ERROR', 'yil');
            }
        }

        $defaults = SelfPuantajReadService::defaultMonthRange();
        $from = $request->getQuery('from', $defaults['from']);
        $to = $request->getQuery('to', $defaults['to']);

        $donem = null;
        try {
            $donem = SelfPuantajReadService::listForPersonel($pdo, $personelId, $from, $to);
        } catch (\Throwable $e) {
            $donem = null;
        }

        $yillik = self::aggregateYillikFazlaCalisma($pdo, $personelId, $yil, $subeId);

        JsonResponse::success([
            'personel_id' => $personelId,
            'yil' => $yil,
            'from' => is_array($donem) ? $donem['from'] : $from,
            'to' => is_array($donem) ? $donem['to'] : $to,
            'donem_ozet' => is_array($donem)
                ? [
                    'fazla_calisma_dakika_toplam' => (int) ($donem['ozet']['fazla_calisma_dakika_toplam'] ?? 0),
                    'calisma_gun_adet' => (int) ($donem['ozet']['calisma_gun_adet'] ?? 0),
                ]
                : null,
            'yillik' => $yillik,
        ]);
    }

    public static function qrScan(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.qr.scan');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $result = QrAttendanceEventService::scan($pdo, $user, $body);
            $status = !empty($result['idempotent']) ? 200 : 201;
            JsonResponse::success([
                'event' => $result['event'],
                'idempotent' => (bool) $result['idempotent'],
            ], [], $status);
        } catch (QrAttendanceException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('QR kaydi olusturulamadi.');
        }
    }

    public static function qrHareketleri(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.qr.events.view');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $ctx = SelfPersonelContext::resolveForSelfService($user, $pdo, true);
        $defaults = QrAttendanceEventService::defaultMonthRange();
        $from = $request->getQuery('from', $defaults['from']);
        $to = $request->getQuery('to', $defaults['to']);

        try {
            JsonResponse::success(
                QrAttendanceEventService::listForSelf($pdo, (int) $ctx['personel_id'], $from, $to)
            );
        } catch (QrAttendanceException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('QR hareketleri yuklenemedi.');
        }
    }

    public static function qrAraliklari(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.qr.events.view');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $ctx = SelfPersonelContext::resolveForSelfService($user, $pdo, true);
        $defaults = QrAttendanceEventService::defaultMonthRange();
        $from = $request->getQuery('from', $defaults['from']);
        $to = $request->getQuery('to', $defaults['to']);

        try {
            JsonResponse::success(
                QrAttendanceIntervalReadService::listForSelf($pdo, (int) $ctx['personel_id'], $from, $to)
            );
        } catch (QrAttendanceException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('QR eslesmeleri yuklenemedi.');
        }
    }

    /**
     * Mirrors HaftalikKapanisController::aggregateYillik (KAPANDI + tam_hafta winner).
     * Scoped to self personel_id / sube_id only — does not require puantaj.view.
     *
     * @return array<string, mixed>
     */
    private static function aggregateYillikFazlaCalisma(PDO $pdo, $personelId, $yil, $personelSubeId)
    {
        try {
            $tablesOk = true;
            foreach (['haftalik_kapanislar', 'haftalik_kapanis_satirlari'] as $table) {
                $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
                if (!$stmt || !$stmt->fetch()) {
                    $tablesOk = false;
                    break;
                }
            }
            if (!$tablesOk) {
                return self::emptyYillikOzet($personelId, $yil);
            }

            $stmt = $pdo->prepare('
                SELECT
                    s.kapanis_id,
                    s.personel_id,
                    s.yil,
                    s.hafta_baslangic,
                    s.fazla_calisma_dakika,
                    s.tam_hafta_verisi,
                    s.state,
                    k.sube_id
                FROM haftalik_kapanis_satirlari s
                INNER JOIN haftalik_kapanislar k ON k.id = s.kapanis_id
                WHERE s.personel_id = :personel_id
                  AND k.sube_id = :sube_id
                  AND s.state = \'KAPANDI\'
            ');
            $stmt->execute([
                'personel_id' => (int) $personelId,
                'sube_id' => (int) $personelSubeId,
            ]);

            $byHafta = [];
            $atlananEksik = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $satirYil = $row['yil'] !== null
                    ? (int) $row['yil']
                    : (int) substr((string) $row['hafta_baslangic'], 0, 4);
                if ($satirYil !== (int) $yil) {
                    continue;
                }
                if ((int) $row['tam_hafta_verisi'] !== 1) {
                    $atlananEksik += 1;
                    continue;
                }
                $key = (int) $personelId . '|' . $satirYil . '|' . (string) $row['hafta_baslangic'];
                $byHafta[$key][] = $row;
            }

            $kullanilan = 0;
            $atlananDup = 0;
            $kapanan = 0;
            foreach ($byHafta as $kayitlar) {
                usort($kayitlar, static function ($a, $b) {
                    return ((int) $b['kapanis_id']) <=> ((int) $a['kapanis_id']);
                });
                $kazanan = $kayitlar[0];
                $atlananDup += max(0, count($kayitlar) - 1);
                $kapanan += 1;
                $fm = isset($kazanan['fazla_calisma_dakika']) ? (int) $kazanan['fazla_calisma_dakika'] : 0;
                $kullanilan += $fm > 0 ? $fm : 0;
            }

            $limit = self::YILLIK_LIMIT_DAKIKA;
            $yaklasma = self::YILLIK_YAKLASMA_ESIK_DAKIKA;

            return [
                'personel_id' => (int) $personelId,
                'yil' => (int) $yil,
                'yillik_limit_dakika' => $limit,
                'yaklasma_esik_dakika' => $yaklasma,
                'kullanilan_dakika' => $kullanilan,
                'kalan_dakika' => max(0, $limit - $kullanilan),
                'limit_asildi_mi' => $kullanilan > $limit,
                'limit_yaklasiyor_mu' => $kullanilan >= $yaklasma,
                'kapanan_hafta_sayisi' => $kapanan,
                'atlanan_duplicate_hafta_sayisi' => $atlananDup,
                'atlanan_eksik_hafta_sayisi' => $atlananEksik,
            ];
        } catch (\Throwable $e) {
            return self::emptyYillikOzet($personelId, $yil);
        }
    }

    /** @return array<string, mixed> */
    private static function emptyYillikOzet($personelId, $yil)
    {
        $limit = self::YILLIK_LIMIT_DAKIKA;

        return [
            'personel_id' => (int) $personelId,
            'yil' => (int) $yil,
            'yillik_limit_dakika' => $limit,
            'yaklasma_esik_dakika' => self::YILLIK_YAKLASMA_ESIK_DAKIKA,
            'kullanilan_dakika' => 0,
            'kalan_dakika' => $limit,
            'limit_asildi_mi' => false,
            'limit_yaklasiyor_mu' => false,
            'kapanan_hafta_sayisi' => 0,
            'atlanan_duplicate_hafta_sayisi' => 0,
            'atlanan_eksik_hafta_sayisi' => 0,
        ];
    }
}
