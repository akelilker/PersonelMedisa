<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use PDO;
use PDOException;

class ReferansController
{
    private const CATALOG_AD_MAX_LENGTH = 120;

    public static function departmanlar(Request $request)
    {
        self::listByTable($request, 'departmanlar');
    }

    public static function createDepartman(Request $request)
    {
        self::createCatalogNamedEntity(
            $request,
            'departmanlar',
            'DEPARTMAN_NAME_REQUIRED',
            'DEPARTMAN_NAME_TYPE',
            'DEPARTMAN_NAME_TOO_LONG',
            'DEPARTMAN_ZATEN_VAR',
            'Departman adi zorunludur.',
            'Departman adi metin olmalidir.',
            'Bu departman adi zaten kayitli.',
            'Departman kaydi olusturulamadi.'
        );
    }

    /**
     * Global departman katalog kaydı. Caller auth sorumluluğundadır.
     * Payload allowlist: yalnız `ad` (trim). sube_id ve diğer alanlar yok sayılır.
     *
     * @param array<string, mixed> $body
     * @return array{id: int, ad: string}
     */
    public static function createDepartmanRecord(PDO $pdo, array $body)
    {
        return self::createCatalogNamedEntityRecord(
            $pdo,
            'departmanlar',
            $body,
            'DEPARTMAN_NAME_REQUIRED',
            'DEPARTMAN_NAME_TYPE',
            'DEPARTMAN_NAME_TOO_LONG',
            'DEPARTMAN_ZATEN_VAR'
        );
    }

    public static function createGorev(Request $request)
    {
        self::createCatalogNamedEntity(
            $request,
            'gorevler',
            'GOREV_NAME_REQUIRED',
            'GOREV_NAME_TYPE',
            'GOREV_NAME_TOO_LONG',
            'GOREV_ZATEN_VAR',
            'Gorev adi zorunludur.',
            'Gorev adi metin olmalidir.',
            'Bu gorev adi zaten kayitli.',
            'Gorev kaydi olusturulamadi.'
        );
    }

    /**
     * Global gorev katalog kaydı. Caller auth sorumluluğundadır.
     * Payload allowlist: yalnız `ad` (trim).
     *
     * @param array<string, mixed> $body
     * @return array{id: int, ad: string}
     */
    public static function createGorevRecord(PDO $pdo, array $body)
    {
        return self::createCatalogNamedEntityRecord(
            $pdo,
            'gorevler',
            $body,
            'GOREV_NAME_REQUIRED',
            'GOREV_NAME_TYPE',
            'GOREV_NAME_TOO_LONG',
            'GOREV_ZATEN_VAR'
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{id: int, ad: string}
     */
    private static function createCatalogNamedEntityRecord(
        PDO $pdo,
        $table,
        array $body,
        $requiredCode,
        $typeCode,
        $tooLongCode,
        $duplicateCode
    ) {
        $allowedTables = ['departmanlar', 'gorevler'];
        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException('CATALOG_TABLE_INVALID');
        }

        if (!array_key_exists('ad', $body)) {
            throw new \InvalidArgumentException($requiredCode);
        }
        // JSON string zorunlu; numeric/boolean/null/array/object reddedilir.
        if (!is_string($body['ad'])) {
            throw new \InvalidArgumentException($typeCode);
        }

        $ad = trim($body['ad']);
        if ($ad === '') {
            throw new \InvalidArgumentException($requiredCode);
        }
        if (self::utf8Length($ad) > self::CATALOG_AD_MAX_LENGTH) {
            throw new \InvalidArgumentException($tooLongCode);
        }

        // Erken kullanıcı dostu hata; asıl concurrency güvenliği UNIQUE(ad) + 1062.
        self::assertCatalogAdUniqueOrThrow($pdo, $table, $ad, $duplicateCode);

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO {$table} (ad, durum) VALUES (:ad, 'AKTIF')"
            );
            $stmt->execute(['ad' => $ad]);
        } catch (PDOException $e) {
            if (self::isDuplicateKeyException($e)) {
                throw new \DomainException($duplicateCode);
            }
            throw $e;
        }

        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            throw new \RuntimeException('INSERT_FAILED');
        }

        // Allowlist: beklenmeyen alanlar insert edilmez — yalnız ad/durum.
        return [
            'id' => $id,
            'ad' => $ad,
        ];
    }

    private static function createCatalogNamedEntity(
        Request $request,
        $table,
        $requiredCode,
        $typeCode,
        $tooLongCode,
        $duplicateCode,
        $requiredMessage,
        $typeMessage,
        $duplicateMessage,
        $serverErrorMessage
    ) {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'yonetim-paneli.manage');

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        try {
            $created = self::createCatalogNamedEntityRecord(
                $pdo,
                $table,
                $body,
                $requiredCode,
                $typeCode,
                $tooLongCode,
                $duplicateCode
            );
        } catch (\InvalidArgumentException $e) {
            $code = $e->getMessage();
            if ($code === $requiredCode) {
                JsonResponse::badRequest($requiredMessage, $requiredCode, 'ad');
            }
            if ($code === $typeCode) {
                JsonResponse::badRequest($typeMessage, 'VALIDATION_ERROR', 'ad');
            }
            if ($code === $tooLongCode) {
                JsonResponse::badRequest(
                    'Ad en fazla ' . self::CATALOG_AD_MAX_LENGTH . ' karakter olabilir.',
                    'VALIDATION_ERROR',
                    'ad'
                );
            }
            JsonResponse::badRequest('Gecersiz istek.', 'VALIDATION_ERROR', 'ad');
        } catch (\DomainException $e) {
            if ($e->getMessage() === $duplicateCode) {
                JsonResponse::error(409, $duplicateCode, $duplicateMessage, 'ad');
            }
            JsonResponse::serverError($serverErrorMessage);
        } catch (PDOException $e) {
            if (self::isDuplicateKeyException($e)) {
                JsonResponse::error(409, $duplicateCode, $duplicateMessage, 'ad');
            }
            JsonResponse::serverError($serverErrorMessage);
        } catch (\Throwable $e) {
            JsonResponse::serverError($serverErrorMessage);
        }

        JsonResponse::success($created, [], 201);
    }

    private static function assertCatalogAdUniqueOrThrow(PDO $pdo, $table, $ad, $duplicateCode)
    {
        // Collation (utf8mb4_unicode_ci) eşitliğini DB uygular; PHP normalize yok.
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE ad = :ad LIMIT 1");
        $stmt->execute(['ad' => $ad]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            throw new \DomainException($duplicateCode);
        }
    }

    private static function utf8Length($value)
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen((string) $value, 'UTF-8');
        }

        return strlen((string) $value);
    }

    private static function isDuplicateKeyException(PDOException $e)
    {
        $sqlState = isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : '';
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

        return $sqlState === '23000' || $driverCode === 1062;
    }

    public static function gorevler(Request $request)
    {
        self::listByTable($request, 'gorevler');
    }

    public static function personelTipleri(Request $request)
    {
        self::listByTable($request, 'personel_tipleri');
    }

    public static function bagliAmirler(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->query(
            "SELECT id, ad_soyad
             FROM users
             WHERE durum = 'AKTIF'
               AND rol IN ('GENEL_YONETICI', 'BOLUM_YONETICISI', 'BIRIM_AMIRI', 'MUHASEBE')
             ORDER BY ad_soyad ASC, id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad_soyad'],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function surecTurleri(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success([
            'items' => [
                ['key' => 'IZIN', 'label' => 'İzin'],
                ['key' => 'RAPOR', 'label' => 'Rapor'],
                ['key' => 'IS_KAZASI', 'label' => 'İş Kazası'],
                ['key' => 'DEVAMSIZLIK', 'label' => 'Devamsızlık'],
                ['key' => 'TESVIK', 'label' => 'Teşvik'],
                ['key' => 'BELGE', 'label' => 'Belge / Sertifika'],
                ['key' => 'ISTEN_AYRILMA', 'label' => 'İşten Ayrılma'],
                ['key' => 'GOREV_DEGISIKLIGI', 'label' => 'Görev Değişikliği'],
                ['key' => 'UCRET_DEGISIKLIGI', 'label' => 'Ücret Değişikliği'],
                ['key' => 'DISIPLIN', 'label' => 'Disiplin'],
            ],
        ]);
    }

    public static function ucretTipleri(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success([
            'items' => [
                ['id' => 1, 'ad' => 'Aylık'],
                ['id' => 2, 'ad' => 'Günlük'],
                ['id' => 3, 'ad' => 'Saatlik'],
            ],
        ]);
    }

    public static function primKurallari(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success([
            'items' => [
                ['id' => 1, 'ad' => 'Devamsızlık Primi Yok'],
                ['id' => 2, 'ad' => 'Tam Prim'],
                ['id' => 3, 'ad' => 'Kısmi Prim'],
            ],
        ]);
    }

    public static function bildirimTurleri(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success([
            'items' => [
                ['key' => 'GELMEDI', 'label' => 'Gelmedi'],
                ['key' => 'GEC_GELDI', 'label' => 'Geç Geldi'],
                ['key' => 'ERKEN_CIKTI', 'label' => 'Erken Çıktı'],
                ['key' => 'IZINLI', 'label' => 'İzinli'],
                ['key' => 'RAPORLU', 'label' => 'Raporlu'],
                ['key' => 'GOREVDE', 'label' => 'Görevde'],
                ['key' => 'DIGER', 'label' => 'Diğer'],
            ],
        ]);
    }

    private static function listByTable(Request $request, $table)
    {
        AuthMiddleware::authenticate($request, true);

        $allowed = ['departmanlar', 'gorevler', 'personel_tipleri'];
        if (!in_array($table, $allowed, true)) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->query("SELECT id, ad FROM $table WHERE durum = 'AKTIF' ORDER BY ad ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad'],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }
}
