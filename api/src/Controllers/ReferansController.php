<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Personel\PersonelOrgLocationSchema;
use Medisa\Api\Services\Personel\PersonelOrgStructureSchema;
use PDO;
use PDOException;

class ReferansController
{
    private const CATALOG_AD_MAX_LENGTH = 120;
    private const KISA_KOD_MAX_LENGTH = 16;

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

    /** @param array<string, mixed> $body @return array{id:int, ad:string} */
    public static function createPozisyonRecord(PDO $pdo, array $body)
    {
        return self::createCatalogNamedEntityRecord(
            $pdo,
            'pozisyonlar',
            $body,
            'POZISYON_NAME_REQUIRED',
            'POZISYON_NAME_TYPE',
            'POZISYON_NAME_TOO_LONG',
            'POZISYON_ZATEN_VAR'
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
        $allowedTables = ['departmanlar', 'gorevler', 'pozisyonlar'];
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

        if ($table === 'pozisyonlar' && !PersonelOrgStructureSchema::isReady($pdo)) {
            JsonResponse::error(
                409,
                PersonelOrgStructureSchema::ERROR_CODE,
                'Org structure schema hazir degil.'
            );
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

    /**
     * Normalize optional hierarchical short code.
     * "" / whitespace-only → null. Invalid type → InvalidArgumentException.
     *
     * @param mixed $raw
     * @return string|null
     */
    private static function normalizeKisaKodOrThrow($raw)
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('KISA_KOD_TYPE');
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (self::utf8Length($trimmed) > self::KISA_KOD_MAX_LENGTH) {
            throw new \InvalidArgumentException('KISA_KOD_TOO_LONG');
        }

        return $trimmed;
    }

    /**
     * @param mixed $raw
     * @return string|null
     */
    private static function responseKisaKod($raw)
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
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

    public static function bolumler(Request $request)
    {
        self::listHierarchical($request, 'bolumler');
    }

    public static function createBolum(Request $request)
    {
        self::createHierarchicalNamedEntity(
            $request,
            'bolumler',
            'departman_id',
            'departmanlar',
            'BOLUM_NAME_REQUIRED',
            'BOLUM_NAME_TYPE',
            'BOLUM_NAME_TOO_LONG',
            'BOLUM_PARENT_REQUIRED',
            'BOLUM_PARENT_INVALID',
            'BOLUM_ZATEN_VAR',
            'Bolum adi zorunludur.',
            'Bolum adi metin olmalidir.',
            'Departman zorunludur.',
            'Gecersiz departman.',
            'Bu bolum adi ayni departman altinda zaten kayitli.',
            'Bolum kaydi olusturulamadi.'
        );
    }

    public static function birimler(Request $request)
    {
        self::listHierarchical($request, 'birimler');
    }

    public static function createBirim(Request $request)
    {
        self::createHierarchicalNamedEntity(
            $request,
            'birimler',
            'bolum_id',
            'bolumler',
            'BIRIM_NAME_REQUIRED',
            'BIRIM_NAME_TYPE',
            'BIRIM_NAME_TOO_LONG',
            'BIRIM_PARENT_REQUIRED',
            'BIRIM_PARENT_INVALID',
            'BIRIM_ZATEN_VAR',
            'Birim adi zorunludur.',
            'Birim adi metin olmalidir.',
            'Bolum zorunludur.',
            'Gecersiz bolum.',
            'Bu birim adi ayni bolum altinda zaten kayitli.',
            'Birim kaydi olusturulamadi.'
        );
    }

    public static function pozisyonlar(Request $request)
    {
        self::listByTable($request, 'pozisyonlar');
    }

    public static function createPozisyon(Request $request)
    {
        self::createCatalogNamedEntity(
            $request,
            'pozisyonlar',
            'POZISYON_NAME_REQUIRED',
            'POZISYON_NAME_TYPE',
            'POZISYON_NAME_TOO_LONG',
            'POZISYON_ZATEN_VAR',
            'Pozisyon adi zorunludur.',
            'Pozisyon adi metin olmalidir.',
            'Bu pozisyon adi zaten kayitli.',
            'Pozisyon kaydi olusturulamadi.'
        );
    }

    public static function personelTipleri(Request $request)
    {
        self::listByTable($request, 'personel_tipleri');
    }

    public static function sgkIsverenler(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (!PersonelOrgLocationSchema::isReady($pdo)) {
            JsonResponse::error(
                409,
                PersonelOrgLocationSchema::ERROR_CODE,
                'Org location schema hazir degil.'
            );
        }

        // Catalog axis (not branch default): id/ad/kod + optional company parent.
        $stmt = $pdo->query(
            "SELECT id, kod, ad, sirket_id FROM sgk_isverenler WHERE durum = 'AKTIF' ORDER BY ad ASC, id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $sirketId = isset($row['sirket_id']) && $row['sirket_id'] !== null && $row['sirket_id'] !== ''
                ? (int) $row['sirket_id']
                : null;
            $kod = isset($row['kod']) && is_string($row['kod']) ? trim($row['kod']) : '';
            $items[] = [
                'id' => (int) $row['id'],
                'kod' => $kod !== '' ? $kod : null,
                'ad' => (string) $row['ad'],
                'sirket_id' => $sirketId !== null && $sirketId > 0 ? $sirketId : null,
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function calismaLokasyonlari(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (!PersonelOrgLocationSchema::isReady($pdo)) {
            JsonResponse::error(
                409,
                PersonelOrgLocationSchema::ERROR_CODE,
                'Org location schema hazir degil.'
            );
        }

        // Catalog parentage (sube_id) is metadata only — not personel.sube_id and not an auth axis.
        $stmt = $pdo->query(
            "SELECT id, kod, ad, sube_id FROM calisma_lokasyonlari WHERE durum = 'AKTIF' ORDER BY ad ASC, id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $subeId = isset($row['sube_id']) && $row['sube_id'] !== null && $row['sube_id'] !== ''
                ? (int) $row['sube_id']
                : null;
            $kod = isset($row['kod']) && is_string($row['kod']) ? trim($row['kod']) : '';
            $items[] = [
                'id' => (int) $row['id'],
                'kod' => $kod !== '' ? $kod : null,
                'ad' => (string) $row['ad'],
                'sube_id' => $subeId !== null && $subeId > 0 ? $subeId : null,
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    /**
     * Bağlı amir seçenekleri (Kayıt formu + Personel Kartı).
     *
     * Kontrat:
     * - Yalnız AKTIF kullanıcılar; personel kaydı olmayan/olmayan hesap yoktur.
     * - Uygunluk canonical rol eksenidir: PERSONEL (ajan seviyesi) ve
     *   AUTH_SMOKE_READONLY (teknik smoke aktörü) HARİÇ tüm yönetim rolleri.
     *   Böylece üst düzey yönetici (GENEL_YONETICI / SISTEM_YONETICISI) ve
     *   şube/İK yöneticileri de bağlı amir olarak seçilebilir.
     * - Yazma kontratı (`bagli_amir_id` → users.id + durum=AKTIF) ile aynı tablo:
     *   liste yazma kontratından daha dar olamaz.
     * - `personel_id` (`users.personel_id`), seçilen amirin personel context'ine
     *   (departman/şube) geçişin tek owner'ıdır; `bagli_amir_id` asla personel id
     *   gibi okunmaz. Personel kaydı olmayan yönetim hesabında `null` kalır.
     * - Otomatik seçim yok; self/cycle koruması çağıran form kontratlarındadır.
     */
    public static function bagliAmirler(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $selectCols = 'id, ad_soyad';
        if ($hasPersonelId) {
            $selectCols .= ', personel_id';
        }

        $stmt = $pdo->query(
            "SELECT {$selectCols}
             FROM users
             WHERE durum = 'AKTIF'
               AND rol NOT IN ('PERSONEL', 'AUTH_SMOKE_READONLY')
             ORDER BY ad_soyad ASC, id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $personelId = $hasPersonelId && isset($row['personel_id']) && $row['personel_id'] !== null
                ? (int) $row['personel_id']
                : null;
            $items[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad_soyad'],
                'personel_id' => $personelId !== null && $personelId > 0 ? $personelId : null,
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function surecTurleri(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

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
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

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
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

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
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

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

    /**
     * Self-service boundary for the reference read surface.
     *
     * PERSONEL is the self-service-only role: its whole contract is "my own data
     * through /me". The reference pickers below feed management forms, and
     * `bagli-amirler` additionally returns other people's names, so reaching them
     * would break that contract — the permission matrix cannot express it because
     * these reads carry no permission today, and inventing one would widen the
     * published role matrices for a surface PERSONEL never uses.
     *
     * Fail-closed by role only: every other role keeps exactly today's access, and
     * an unresolved/legacy role is not silently widened into a deny either.
     *
     * @param array<string, mixed> $user
     */
    private static function assertReferenceReadAllowed(array $user)
    {
        if (RolePermissions::normalizeRole(isset($user['rol']) ? (string) $user['rol'] : '') === 'PERSONEL') {
            JsonResponse::error(
                403,
                'SELF_SERVICE_ONLY_ROLE_FORBIDDEN',
                'Bu kaynak self-service hesabi icin kullanilamaz.'
            );
        }
    }

    private static function listByTable(Request $request, $table)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

        $allowed = ['departmanlar', 'gorevler', 'personel_tipleri', 'pozisyonlar'];
        if (!in_array($table, $allowed, true)) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if ($table === 'pozisyonlar' && !PersonelOrgStructureSchema::isReady($pdo)) {
            JsonResponse::error(
                409,
                PersonelOrgStructureSchema::ERROR_CODE,
                'Org structure schema hazir degil.'
            );
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

    private static function listHierarchical(Request $request, $table)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertReferenceReadAllowed($user);

        $allowed = [
            'bolumler' => 'departman_id',
            'birimler' => 'bolum_id',
        ];
        if (!isset($allowed[$table])) {
            JsonResponse::notFound();
        }
        $parentColumn = $allowed[$table];

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (!PersonelOrgStructureSchema::isReady($pdo)) {
            JsonResponse::error(
                409,
                PersonelOrgStructureSchema::ERROR_CODE,
                'Org structure schema hazir degil.'
            );
        }

        $hasKisaKod = PersonelOrgStructureSchema::hasKisaKodColumns($pdo);
        $kisaSelect = $hasKisaKod ? ', kisa_kod' : '';
        $parentId = (int) ($request->getQuery($parentColumn, 0) ?: 0);
        if ($parentId > 0) {
            $stmt = $pdo->prepare(
                "SELECT id, ad, {$parentColumn}{$kisaSelect}
                 FROM {$table}
                 WHERE durum = 'AKTIF' AND {$parentColumn} = :parent_id
                 ORDER BY ad ASC"
            );
            $stmt->execute(['parent_id' => $parentId]);
        } else {
            $stmt = $pdo->query(
                "SELECT id, ad, {$parentColumn}{$kisaSelect}
                 FROM {$table}
                 WHERE durum = 'AKTIF'
                 ORDER BY ad ASC"
            );
        }
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad'],
                'kisa_kod' => $hasKisaKod ? self::responseKisaKod($row['kisa_kod'] ?? null) : null,
                $parentColumn => (int) $row[$parentColumn],
            ];
        }

        JsonResponse::success(['items' => $items]);
    }

    private static function createHierarchicalNamedEntity(
        Request $request,
        $table,
        $parentColumn,
        $parentTable,
        $requiredCode,
        $typeCode,
        $tooLongCode,
        $parentRequiredCode,
        $parentInvalidCode,
        $duplicateCode,
        $requiredMessage,
        $typeMessage,
        $parentRequiredMessage,
        $parentInvalidMessage,
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

        if (!PersonelOrgStructureSchema::isReady($pdo)) {
            JsonResponse::error(
                409,
                PersonelOrgStructureSchema::ERROR_CODE,
                'Org structure schema hazir degil.'
            );
        }

        if (!array_key_exists($parentColumn, $body) || $body[$parentColumn] === null || $body[$parentColumn] === '') {
            JsonResponse::badRequest($parentRequiredMessage, $parentRequiredCode, $parentColumn);
        }
        $parentId = (int) $body[$parentColumn];
        if ($parentId < 1) {
            JsonResponse::badRequest($parentRequiredMessage, $parentRequiredCode, $parentColumn);
        }
        $parentStmt = $pdo->prepare(
            "SELECT id FROM {$parentTable} WHERE id = :id AND durum = 'AKTIF' LIMIT 1"
        );
        $parentStmt->execute(['id' => $parentId]);
        if (!$parentStmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::badRequest($parentInvalidMessage, $parentInvalidCode, $parentColumn);
        }

        if (!array_key_exists('ad', $body)) {
            JsonResponse::badRequest($requiredMessage, $requiredCode, 'ad');
        }
        if (!is_string($body['ad'])) {
            JsonResponse::badRequest($typeMessage, 'VALIDATION_ERROR', 'ad');
        }
        $ad = trim($body['ad']);
        if ($ad === '') {
            JsonResponse::badRequest($requiredMessage, $requiredCode, 'ad');
        }
        if (self::utf8Length($ad) > self::CATALOG_AD_MAX_LENGTH) {
            JsonResponse::badRequest(
                'Ad en fazla ' . self::CATALOG_AD_MAX_LENGTH . ' karakter olabilir.',
                'VALIDATION_ERROR',
                'ad'
            );
        }

        $hasKisaKod = PersonelOrgStructureSchema::hasKisaKodColumns($pdo);
        $kisaKod = null;
        if (array_key_exists('kisa_kod', $body)) {
            if (!$hasKisaKod) {
                JsonResponse::error(
                    409,
                    'ORG_REFERENCE_SHORT_CODE_SCHEMA_NOT_READY',
                    'kisa_kod alani henuz hazir degil.'
                );
            }
            try {
                $kisaKod = self::normalizeKisaKodOrThrow($body['kisa_kod']);
            } catch (\InvalidArgumentException $e) {
                $code = $e->getMessage();
                if ($code === 'KISA_KOD_TYPE') {
                    JsonResponse::badRequest('kisa_kod metin olmalidir.', 'VALIDATION_ERROR', 'kisa_kod');
                }
                if ($code === 'KISA_KOD_TOO_LONG') {
                    JsonResponse::badRequest(
                        'kisa_kod en fazla ' . self::KISA_KOD_MAX_LENGTH . ' karakter olabilir.',
                        'VALIDATION_ERROR',
                        'kisa_kod'
                    );
                }
                JsonResponse::badRequest('Gecersiz kisa_kod.', 'VALIDATION_ERROR', 'kisa_kod');
            }
        }

        $dupStmt = $pdo->prepare(
            "SELECT id FROM {$table} WHERE {$parentColumn} = :parent_id AND ad = :ad LIMIT 1"
        );
        $dupStmt->execute(['parent_id' => $parentId, 'ad' => $ad]);
        if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::error(409, $duplicateCode, $duplicateMessage, 'ad');
        }

        try {
            if ($hasKisaKod) {
                $stmt = $pdo->prepare(
                    "INSERT INTO {$table} ({$parentColumn}, ad, kisa_kod, durum)
                     VALUES (:parent_id, :ad, :kisa_kod, 'AKTIF')"
                );
                $stmt->execute([
                    'parent_id' => $parentId,
                    'ad' => $ad,
                    'kisa_kod' => $kisaKod,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO {$table} ({$parentColumn}, ad, durum) VALUES (:parent_id, :ad, 'AKTIF')"
                );
                $stmt->execute(['parent_id' => $parentId, 'ad' => $ad]);
            }
        } catch (PDOException $e) {
            if (self::isDuplicateKeyException($e)) {
                JsonResponse::error(409, $duplicateCode, $duplicateMessage, 'ad');
            }
            JsonResponse::serverError($serverErrorMessage);
        }

        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            JsonResponse::serverError($serverErrorMessage);
        }

        JsonResponse::success([
            'id' => $id,
            'ad' => $ad,
            'kisa_kod' => $kisaKod,
            $parentColumn => $parentId,
        ], [], 201);
    }
}
