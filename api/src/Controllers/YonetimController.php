<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\DualControl;
use Medisa\Api\Auth\InitialPassword;
use Medisa\Api\Auth\PasswordHasher;
use Medisa\Api\Auth\PasswordPolicy;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Database\UserOrgAssignmentSchema;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\HrWriteScope;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Auth\UserPersonelBindingService;
use Medisa\Api\Services\Auth\PersonelAccountOnboardingService;
use Medisa\Api\Services\Auth\ActorIdentityException;
use Medisa\Api\Services\Auth\ActorIdentityService;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use PDO;
use PDOException;

class YonetimController
{

    /** Reuses the canonical user projection without publishing the user directory. */
    public static function finalCloseRead(int $userId): array
    {
        if (PHP_SAPI !== 'cli' || !array_key_exists($userId, \Medisa\Api\Services\Operations\FinalClosePackage::USERS)) {
            throw new \RuntimeException('FINAL_CLOSE_USER_FORBIDDEN');
        }
        $row = self::findKullaniciById(Connection::get(), $userId);
        if (!$row) {
            throw new \RuntimeException('FINAL_CLOSE_USER_MISSING');
        }
        return $row;
    }

    public static function actorIdentityCreate(Request $request)
    {
        $admin = AuthMiddleware::authenticate($request, true);
        $body = $request->getJsonBody();
        $userId = $body['user_id'] ?? null;

        try {
            $pdo = Connection::get();
            JsonResponse::success(ActorIdentityService::create($pdo, $admin, $userId), [], 201);
        } catch (ActorIdentityException $e) {
            self::actorIdentityError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Actor identity olusturulamadi.');
        }
    }

    public static function actorIdentityVerify(Request $request, $identityId)
    {
        $admin = AuthMiddleware::authenticate($request, true);

        try {
            $pdo = Connection::get();
            JsonResponse::success(ActorIdentityService::verify($pdo, $admin, $identityId));
        } catch (ActorIdentityException $e) {
            self::actorIdentityError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Actor identity dogrulanamadi.');
        }
    }

    public static function actorIdentityBind(Request $request, $userId)
    {
        $admin = AuthMiddleware::authenticate($request, true);
        $body = $request->getJsonBody();
        $identityId = $body['actor_identity_id'] ?? null;

        try {
            $pdo = Connection::get();
            JsonResponse::success(ActorIdentityService::bind($pdo, $admin, $userId, $identityId));
        } catch (ActorIdentityException $e) {
            self::actorIdentityError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Actor identity kullaniciya baglanamadi.');
        }
    }

    public static function actorIdentityRead(Request $request, $userId)
    {
        $admin = AuthMiddleware::authenticate($request, true);

        try {
            $pdo = Connection::get();
            RolePermissions::assert($admin, ActorIdentityService::MANAGEMENT_PERMISSION);
            JsonResponse::success(ActorIdentityService::readForUser($pdo, $userId));
        } catch (ActorIdentityException $e) {
            self::actorIdentityError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Actor identity okunamadi.');
        }
    }

    public static function actorIdentityReadById(Request $request, $identityId)
    {
        $admin = AuthMiddleware::authenticate($request, true);

        try {
            $pdo = Connection::get();
            RolePermissions::assert($admin, ActorIdentityService::MANAGEMENT_PERMISSION);
            JsonResponse::success(ActorIdentityService::readIdentity($pdo, $identityId));
        } catch (ActorIdentityException $e) {
            self::actorIdentityError($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Actor identity okunamadi.');
        }
    }

    private static function actorIdentityError(ActorIdentityException $e)
    {
        JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
    }

    public static function subeler(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeListeleme($user);

        $pdo = self::subePdo();

        // Legacy flat endpoint, extended read model. It keeps working on a
        // pre-079 schema: the shared owner emits null relations and falls back
        // to the raw branch name for tam_ad.
        JsonResponse::success(['items' => OrganizasyonService::listSubeler($pdo)]);
    }

    public static function subeOlustur(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeYonetimi($user);
        $body = $request->getJsonBody();
        $pdo = self::subePdo();

        try {
            JsonResponse::success(
                OrganizasyonService::createSube(
                    $pdo,
                    $body,
                    null,
                    OrganizasyonAuditContext::fromRequest($request, $user)
                )
            );
        } catch (OrganizasyonException $e) {
            self::subeError($e);
        }
    }

    public static function subeGuncelle(Request $request, $subeId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeYonetimi($user);
        $body = $request->getJsonBody();
        $pdo = self::subePdo();

        try {
            JsonResponse::success(OrganizasyonService::updateSube($pdo, $subeId, $body));
        } catch (OrganizasyonException $e) {
            self::subeError($e);
        }
    }

    public static function subeSil(Request $request, $subeId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertSubeYonetimi($user);
        $pdo = self::subePdo();

        try {
            JsonResponse::success(OrganizasyonService::deleteSube($pdo, $subeId));
        } catch (OrganizasyonException $e) {
            self::subeError($e);
        }
    }

    private static function subePdo()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    private static function subeError(OrganizasyonException $e)
    {
        JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
    }

    /** @param array<string, mixed> $user */
    private static function assertSubeListeleme(array $user)
    {
        RolePermissions::assertAny($user, [
            'yonetim-paneli.view',
            'aylik-ozet.view',
            'personeller.create',
            'personeller.update',
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function assertSubeYonetimi(array $user)
    {
        RolePermissions::assert($user, 'yonetim-paneli.manage');
    }

    public static function aylikOzet(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik-ozet.view');
        OrgScope::assertRequiredAssignment($user);

        $filters = self::parseAylikOzetFilters($request, false);
        self::assertAylikSubeAccess($user, $filters['sube_id']);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        JsonResponse::success(self::buildAylikOzetPayload($pdo, $filters, $user));
    }

    public static function aylikOzetBolumOnay(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertBolumOnayPermission($user);

        $filters = self::parseAylikOzetFilters($request, true);
        self::assertAylikWriteSubeScope($user, $filters['sube_id']);
        self::assertAylikSubeAccess($user, $filters['sube_id']);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = self::buildAylikOzetWhereClause($filters, $user);
        $params = $where['params'];
        $params['son_islem'] = 'Bolum yoneticisi toplu onay verdi';

        // Resolved before the write: the canonical branch set comes from the rows the
        // scoped predicate actually owns, never from the request body sube_id.
        $canonicalSubeIds = self::resolveCanonicalAylikSubeIds($pdo, $filters, $user);
        $actorColumns = self::aylikOzetActorColumnsSupported($pdo);

        $actorSql = '';
        if ($actorColumns) {
            $actorSql = ',
                    bolum_onay_actor_user_id = :actor_user_id,
                    bolum_onay_actor_identity_id = :actor_identity_id,
                    bolum_onay_at = CURRENT_TIMESTAMP(3)';
            $params['actor_user_id'] = (int) $user['id'];
            $params['actor_identity_id'] = DualControl::resolveActorIdentityId($pdo, (int) $user['id'], $user);
        }

        $pdo->beginTransaction();
        try {
            $updateSql = 'UPDATE aylik_ozet_satirlari
                SET bolum_onay_durumu = \'BOLUM_ONAYLANDI\',
                    revize_var_mi = 0,
                    son_islem = :son_islem' . $actorSql . '
                WHERE ' . $where['sql'] . ' AND kapanis_durumu <> \'KAPANDI\'';
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($params);

            self::syncAylikKapanisState($pdo, $filters['ay'], $canonicalSubeIds);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Aylik ozet onay islemi tamamlanamadi.');
        }

        JsonResponse::success(self::buildAylikOzetPayload($pdo, $filters, $user));
    }

    public static function aylikOzetAyKapat(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertGenelYoneticiOnayPermission($user);

        $filters = self::parseAylikOzetFilters($request, true);
        self::assertAylikWriteSubeScope($user, $filters['sube_id']);
        self::assertAylikSubeAccess($user, $filters['sube_id']);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertNoPendingBolumOnay($pdo, $filters, $user);
        self::assertAylikKapanisSeparationOfDuties($pdo, $filters, $user);

        $where = self::buildAylikOzetWhereClause($filters, $user);
        $params = $where['params'];
        $params['son_islem'] = 'Genel yonetici ust onay verdi';

        $canonicalSubeIds = self::resolveCanonicalAylikSubeIds($pdo, $filters, $user);
        $actorColumns = self::aylikOzetActorColumnsSupported($pdo);

        $actorSql = '';
        if ($actorColumns) {
            $actorSql = ',
                    kapanis_actor_user_id = :actor_user_id,
                    kapanis_actor_identity_id = :actor_identity_id,
                    kapanis_at = CURRENT_TIMESTAMP(3)';
            $params['actor_user_id'] = (int) $user['id'];
            $params['actor_identity_id'] = DualControl::resolveActorIdentityId($pdo, (int) $user['id'], $user);
        }

        $pdo->beginTransaction();
        try {
            $updateSql = 'UPDATE aylik_ozet_satirlari
                SET kapanis_durumu = \'KAPANDI\',
                    son_islem = :son_islem' . $actorSql . '
                WHERE ' . $where['sql'];
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($params);

            self::syncAylikKapanisState($pdo, $filters['ay'], $canonicalSubeIds);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Aylik ozet kapanis islemi tamamlanamadi.');
        }

        JsonResponse::success(self::buildAylikOzetPayload($pdo, $filters, $user));
    }

    /**
     * @return array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool}
     */
    private static function parseAylikOzetFilters(Request $request, $fromBody)
    {
        if ($fromBody) {
            $body = $request->getJsonBody();
            $ay = trim((string) (isset($body['ay']) ? $body['ay'] : ''));
            $subeId = (int) (isset($body['sube_id']) ? $body['sube_id'] : 0);
            $departmanId = (int) (isset($body['departman_id']) ? $body['departman_id'] : 0);
            $sadeceRevizeli = filter_var(isset($body['sadece_revizeli']) ? $body['sadece_revizeli'] : false, FILTER_VALIDATE_BOOLEAN);
        } else {
            $ay = trim((string) $request->getQuery('ay', date('Y-m')));
            $subeId = (int) ($request->getQuery('sube_id', 0) ?: 0);
            $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
            $sadeceRevizeli = filter_var($request->getQuery('sadece_revizeli', false), FILTER_VALIDATE_BOOLEAN);
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            JsonResponse::badRequest('Gecersiz ay parametresi.', 'VALIDATION_ERROR', 'ay');
        }

        return [
            'ay' => $ay,
            'sube_id' => $subeId > 0 ? $subeId : 0,
            'departman_id' => $departmanId > 0 ? $departmanId : 0,
            'sadece_revizeli' => $sadeceRevizeli,
        ];
    }

    /** @param array<string, mixed> $user */
    private static function assertAylikSubeAccess(array $user, $subeId)
    {
        $subeId = (int) $subeId;
        if ($subeId <= 0) {
            return;
        }

        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) > 0 && !in_array($subeId, $allowed, true)) {
            JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertAylikWriteSubeScope(array $user, $subeId)
    {
        // Canonical empty-scope deny: a branch/unit role without an assignment must not
        // fall through to an unrestricted month-wide write.
        OrgScope::assertRequiredAssignment($user);

        $subeId = (int) $subeId;
        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) > 0 && $subeId <= 0) {
            JsonResponse::badRequest('Sube secimi zorunludur.', 'VALIDATION_ERROR', 'sube_id');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertBolumOnayPermission(array $user)
    {
        RolePermissions::assertAny($user, [
            'aylik_bolum_onayi.approve',
            'aylik-ozet.review',
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function assertGenelYoneticiOnayPermission(array $user)
    {
        RolePermissions::assertAny($user, [
            'genel_yonetici_onayi.approve',
            'aylik-ozet.executive_ack',
        ]);
    }

    /**
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     */
    private static function assertNoPendingBolumOnay(PDO $pdo, array $filters, array $user)
    {
        $where = self::buildAylikOzetWhereClause($filters, $user);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS total FROM aylik_ozet_satirlari
             WHERE ' . $where['sql'] . '
               AND kapanis_durumu <> \'KAPANDI\'
               AND bolum_onay_durumu = \'BOLUM_ONAYINDA\''
        );
        $stmt->execute($where['params']);
        $total = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($total > 0) {
            JsonResponse::error(
                409,
                'PENDING_BOLUM_ONAY',
                'Bekleyen bölüm onayları tamamlanmadan genel yönetici onayı verilemez.'
            );
        }
    }

    /**
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     * @return array{sql: string, params: array<string, mixed>}
     */
    private static function buildAylikOzetWhereClause(array $filters, array $user)
    {
        $where = ['ay = :ay'];
        $params = ['ay' => $filters['ay']];

        // The requested branch may only ever narrow inside the assignment, so the
        // allowed-list predicate is ANDed on top instead of being replaced by it. A
        // cross-branch sube_id therefore matches no row even if a caller reaches this
        // builder without the assertAylikSubeAccess gate in front of it.
        if ($filters['sube_id'] > 0) {
            $where[] = 'sube_id = :sube_id';
            $params['sube_id'] = $filters['sube_id'];
        }

        $allowedSubeIds = SubeScope::allowedSubeIds($user);
        if (count($allowedSubeIds) > 0) {
            $placeholders = [];
            foreach ($allowedSubeIds as $index => $subeId) {
                $key = 'allowed_sube_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $subeId;
            }
            $where[] = 'sube_id IN (' . implode(', ', $placeholders) . ')';
        }
        if ($filters['departman_id'] > 0) {
            $where[] = 'departman_id = :departman_id';
            $params['departman_id'] = $filters['departman_id'];
        }
        if ($filters['sadece_revizeli']) {
            $where[] = 'revize_var_mi = 1';
        }

        return [
            'sql' => implode(' AND ', $where),
            'params' => $params,
        ];
    }

    /**
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     */
    private static function buildAylikOzetPayload(PDO $pdo, array $filters, array $user)
    {
        $where = self::buildAylikOzetWhereClause($filters, $user);
        $stmt = $pdo->prepare(
            'SELECT * FROM aylik_ozet_satirlari WHERE ' . $where['sql'] . ' ORDER BY personel_id ASC'
        );
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $summary = [
            'toplam_personel' => 0,
            'toplam_devamsizlik_gun' => 0,
            'toplam_gec_kalma' => 0,
            'toplam_izinli_gelmedi' => 0,
            'toplam_izinsiz_gelmedi' => 0,
            'toplam_raporlu' => 0,
            'toplam_tesvik_tutari' => 0,
            'toplam_ceza_kesinti_tutari' => 0,
        ];
        $pending = 0;

        foreach ($rows as $row) {
            $item = [
                'personel_id' => (int) $row['personel_id'],
                'ad_soyad' => (string) $row['ad_soyad'],
                'sicil_no' => $row['sicil_no'],
                'sube' => (string) $row['sube'],
                'bolum' => (string) $row['bolum'],
                'bagli_amir_adi' => (string) ($row['bagli_amir_adi'] ?: '-'),
                'devamsizlik_gun' => (int) $row['devamsizlik_gun'],
                'gec_kalma_adet' => (int) $row['gec_kalma_adet'],
                'izinli_gelmedi' => (int) $row['izinli_gelmedi'],
                'izinsiz_gelmedi' => (int) $row['izinsiz_gelmedi'],
                'raporlu' => (int) $row['raporlu'],
                'tesvik_tutari' => (float) $row['tesvik_tutari'],
                'ceza_kesinti_tutari' => (float) $row['ceza_kesinti_tutari'],
                'bolum_onay_durumu' => (string) $row['bolum_onay_durumu'],
                'revize_var_mi' => (bool) $row['revize_var_mi'],
                'son_islem' => (string) ($row['son_islem'] ?: '-'),
                'kapanis_durumu' => (string) $row['kapanis_durumu'],
            ];
            $items[] = $item;

            $summary['toplam_personel']++;
            $summary['toplam_devamsizlik_gun'] += (int) $row['devamsizlik_gun'];
            $summary['toplam_gec_kalma'] += (int) $row['gec_kalma_adet'];
            $summary['toplam_izinli_gelmedi'] += (int) $row['izinli_gelmedi'];
            $summary['toplam_izinsiz_gelmedi'] += (int) $row['izinsiz_gelmedi'];
            $summary['toplam_raporlu'] += (int) $row['raporlu'];
            $summary['toplam_tesvik_tutari'] += (float) $row['tesvik_tutari'];
            $summary['toplam_ceza_kesinti_tutari'] += (float) $row['ceza_kesinti_tutari'];

            if ($row['bolum_onay_durumu'] === 'BOLUM_ONAYINDA') {
                $pending++;
            }
        }

        $state = self::readAylikKapanisState($pdo, $filters, $user);

        return [
            'ay' => $filters['ay'],
            'state' => $state,
            'summary' => $summary,
            'items' => $items,
            'pending_bolum_onayi' => $pending,
        ];
    }

    /**
     * State reported to the caller covers only the branches the caller can see, so a
     * branch-restricted user never receives another branch's aggregate next to its own
     * branch-filtered items.
     *
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     */
    private static function readAylikKapanisState(PDO $pdo, array $filters, array $user)
    {
        if (!self::aylikKapanisStateSubeScopeSupported($pdo)) {
            $stmt = $pdo->prepare('SELECT state FROM aylik_kapanis_state WHERE ay = :ay LIMIT 1');
            $stmt->execute(['ay' => $filters['ay']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? (string) $row['state'] : 'BOLUM_ONAYINDA';
        }

        $subeIds = self::resolveCanonicalAylikSubeIds($pdo, $filters, $user);
        if (count($subeIds) === 0) {
            return 'BOLUM_ONAYINDA';
        }

        $states = [];
        foreach ($subeIds as $subeId) {
            $scopeId = $subeId > 0 ? $subeId : 0;
            $stmt = $pdo->prepare(
                'SELECT state FROM aylik_kapanis_state WHERE ay = :ay AND sube_id = :sube_id LIMIT 1'
            );
            $stmt->execute(['ay' => $filters['ay'], 'sube_id' => $scopeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $states[] = $row ? (string) $row['state'] : 'BOLUM_ONAYINDA';
        }

        return self::foldAylikKapanisStates($states);
    }

    /**
     * Same precedence as the per-branch aggregate: KAPANDI only when every visible
     * branch is closed, otherwise the most blocking state wins.
     *
     * @param array<int, string> $states
     */
    private static function foldAylikKapanisStates(array $states)
    {
        if (count($states) === 0) {
            return 'BOLUM_ONAYINDA';
        }

        $allClosed = true;
        foreach ($states as $state) {
            if ($state !== 'KAPANDI') {
                $allClosed = false;
                break;
            }
        }
        if ($allClosed) {
            return 'KAPANDI';
        }
        if (in_array('REVIZE_ISTENDI', $states, true)) {
            return 'REVIZE_ISTENDI';
        }
        if (in_array('BOLUM_ONAYINDA', $states, true)) {
            return 'BOLUM_ONAYINDA';
        }

        return 'BOLUM_ONAYLANDI';
    }

    /**
     * Canonical branch set of a monthly write, resolved server-side from the closing
     * rows the scoped predicate owns. The request body sube_id can only narrow inside
     * the actor's already-authorized scope; it never becomes the branch identity that
     * the aggregated state is keyed on.
     *
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     * @return array<int, int>
     */
    private static function resolveCanonicalAylikSubeIds(PDO $pdo, array $filters, array $user)
    {
        $where = self::buildAylikOzetWhereClause($filters, $user);
        $stmt = $pdo->prepare(
            'SELECT DISTINCT sube_id FROM aylik_ozet_satirlari WHERE ' . $where['sql']
        );
        $stmt->execute($where['params']);

        $subeIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subeIds[] = (int) $row['sube_id'];
        }
        sort($subeIds);

        return $subeIds;
    }

    /**
     * Recomputes the persisted closing state for exactly the canonical branches the
     * caller's write touched. Each branch aggregates only its own rows, so closing one
     * branch can never move another branch's state inside the same month.
     *
     * @param array<int, int> $subeIds canonical branches resolved by resolveCanonicalAylikSubeIds
     */
    private static function syncAylikKapanisState(PDO $pdo, $ay, array $subeIds)
    {
        if (!self::aylikKapanisStateSubeScopeSupported($pdo)) {
            // Pre-079 schema has a single month-keyed row and no branch column, so the
            // legacy global aggregate is kept verbatim rather than silently narrowed.
            self::writeAylikKapanisState($pdo, $ay, null, self::computeAylikKapanisState($pdo, $ay, null));

            return;
        }

        foreach ($subeIds as $subeId) {
            $state = self::computeAylikKapanisState($pdo, $ay, $subeId);
            if ($state !== null) {
                self::writeAylikKapanisState($pdo, $ay, $subeId, $state);
            }
        }
    }

    /**
     * @param int|null $subeId null aggregates the whole month (legacy pre-079 shape)
     * @return string|null null when the scope has no row to aggregate
     */
    private static function computeAylikKapanisState(PDO $pdo, $ay, $subeId)
    {
        $sql = 'SELECT bolum_onay_durumu, kapanis_durumu FROM aylik_ozet_satirlari WHERE ay = :ay';
        $params = ['ay' => $ay];
        if ($subeId !== null) {
            $sql .= ' AND sube_id <=> :sube_id';
            $params['sube_id'] = (int) $subeId > 0 ? (int) $subeId : null;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) {
            return null;
        }

        $allClosed = true;
        $hasRevize = false;
        $hasPending = false;

        foreach ($rows as $row) {
            if ((string) $row['kapanis_durumu'] !== 'KAPANDI') {
                $allClosed = false;
            }
            if ((string) $row['bolum_onay_durumu'] === 'REVIZE_ISTENDI') {
                $hasRevize = true;
            }
            if ((string) $row['bolum_onay_durumu'] === 'BOLUM_ONAYINDA') {
                $hasPending = true;
            }
        }

        if ($allClosed) {
            return 'KAPANDI';
        }
        if ($hasRevize) {
            return 'REVIZE_ISTENDI';
        }

        return $hasPending ? 'BOLUM_ONAYINDA' : 'BOLUM_ONAYLANDI';
    }

    /** @param int|null $subeId null targets the legacy month-only row */
    private static function writeAylikKapanisState(PDO $pdo, $ay, $subeId, $state)
    {
        if ($state === null) {
            return;
        }

        if ($subeId === null) {
            $existing = $pdo->prepare('SELECT id FROM aylik_kapanis_state WHERE ay = :ay LIMIT 1');
            $existing->execute(['ay' => $ay]);
            if ($existing->fetch(PDO::FETCH_ASSOC)) {
                $update = $pdo->prepare('UPDATE aylik_kapanis_state SET state = :state WHERE ay = :ay');
                $update->execute(['state' => $state, 'ay' => $ay]);

                return;
            }

            $insert = $pdo->prepare('INSERT INTO aylik_kapanis_state (ay, state) VALUES (:ay, :state)');
            $insert->execute(['ay' => $ay, 'state' => $state]);

            return;
        }

        // sube_id = 0 is the "branch not resolved" sentinel introduced by 079.
        $scopeId = (int) $subeId > 0 ? (int) $subeId : 0;
        $existing = $pdo->prepare(
            'SELECT id FROM aylik_kapanis_state WHERE ay = :ay AND sube_id = :sube_id LIMIT 1'
        );
        $existing->execute(['ay' => $ay, 'sube_id' => $scopeId]);
        if ($existing->fetch(PDO::FETCH_ASSOC)) {
            $update = $pdo->prepare(
                'UPDATE aylik_kapanis_state SET state = :state WHERE ay = :ay AND sube_id = :sube_id'
            );
            $update->execute(['state' => $state, 'ay' => $ay, 'sube_id' => $scopeId]);

            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO aylik_kapanis_state (ay, sube_id, state) VALUES (:ay, :sube_id, :state)'
        );
        $insert->execute(['ay' => $ay, 'sube_id' => $scopeId, 'state' => $state]);
    }

    /**
     * Fail-closed separation of duties for the final month close: whoever gave a
     * section approval on the rows being closed may not also close them, and neither
     * may a second account belonging to the same actor identity.
     *
     * Rows approved before 079 carry no actor (NULL) and are treated as legacy so an
     * existing month stays closable; every approval written after 079 records an actor,
     * so no bypass window exists for new data.
     *
     * @param array{ay: string, sube_id: int, departman_id: int, sadece_revizeli: bool} $filters
     * @param array<string, mixed> $user
     */
    private static function assertAylikKapanisSeparationOfDuties(PDO $pdo, array $filters, array $user)
    {
        if (!self::aylikOzetActorColumnsSupported($pdo)) {
            return;
        }

        $where = self::buildAylikOzetWhereClause($filters, $user);
        $stmt = $pdo->prepare(
            'SELECT DISTINCT bolum_onay_actor_user_id AS approver
             FROM aylik_ozet_satirlari
             WHERE ' . $where['sql'] . '
               AND kapanis_durumu <> \'KAPANDI\'
               AND bolum_onay_actor_user_id IS NOT NULL'
        );
        $stmt->execute($where['params']);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $violation = DualControl::violation($user, $row['approver'], $pdo);
            if ($violation !== null) {
                JsonResponse::error(403, $violation['code'], $violation['message']);
            }
        }
    }

    private static function aylikKapanisStateSubeScopeSupported(PDO $pdo)
    {
        return self::columnExists($pdo, 'aylik_kapanis_state', 'sube_id');
    }

    private static function aylikOzetActorColumnsSupported(PDO $pdo)
    {
        return self::columnExists($pdo, 'aylik_ozet_satirlari', 'bolum_onay_actor_user_id')
            && self::columnExists($pdo, 'aylik_ozet_satirlari', 'kapanis_actor_user_id');
    }

    private static function columnExists(PDO $pdo, $table, $column)
    {
        try {
            // information_schema rather than SHOW COLUMNS: the latter cannot take a
            // server-side bound parameter, which production runs with.
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column
                 LIMIT 1'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            $found = $stmt->fetchColumn();
            $stmt->closeCursor();

            return $found !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @var array<int, string> */
    private static $validRoles = [
        'PERSONEL',
        'MUHASEBE',
        'IK_SORUMLUSU',
        'IK_PERSONELI',
        'BIRIM_AMIRI',
        'BOLUM_YONETICISI',
        'SUBE_YONETICISI',
        'GENEL_YONETICI',
        'SISTEM_YONETICISI',
        'AUTH_SMOKE_READONLY',
    ];

    private static function isAuthSmokeReadonlyRole($rol)
    {
        return strtoupper(trim((string) $rol)) === 'AUTH_SMOKE_READONLY';
    }

    /**
     * @param array<int, int> $subeIds
     * @param int|null $varsayilanSubeId
     */
    private static function assertAuthSmokeReadonlyContract($username, $rol, array $subeIds, $varsayilanSubeId)
    {
        if (!self::isAuthSmokeReadonlyRole($rol)) {
            return;
        }

        $username = trim((string) $username);
        if (strpos($username, 'pm_smoke_ro_') !== 0) {
            JsonResponse::error(
                400,
                'AUTH_SMOKE_USERNAME_INVALID',
                'AUTH_SMOKE_READONLY kullanici adi pm_smoke_ro_ ile baslamalidir.',
                'username'
            );
        }

        if (count($subeIds) !== 1) {
            JsonResponse::error(
                400,
                'AUTH_SMOKE_SCOPE_INVALID',
                'AUTH_SMOKE_READONLY hesabi exact bir sube gerektirir.',
                'sube_ids'
            );
        }

        $onlySube = $subeIds[0];
        if ($varsayilanSubeId === null) {
            return;
        }
        if ((int) $varsayilanSubeId !== (int) $onlySube) {
            JsonResponse::error(
                400,
                'AUTH_SMOKE_SCOPE_INVALID',
                'AUTH_SMOKE_READONLY varsayilan sube atanmis tek sube ile ayni olmalidir.',
                'varsayilan_sube_id'
            );
        }
    }

    public static function kullanicilar(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertKullaniciYonetimi($user);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $hasVarsayilan = UsersSchema::hasVarsayilanSubeId($pdo);
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $hasMustChangePassword = UsersSchema::hasMustChangePassword($pdo);
        $hasActivationRequired = UsersSchema::hasActivationRequired($pdo);
        $selectCols = ['id', 'username', 'ad_soyad', 'rol', 'durum'];
        if ($hasVarsayilan) {
            $selectCols[] = 'varsayilan_sube_id';
        }
        if ($hasPersonelId) {
            $selectCols[] = 'personel_id';
        }
        if ($hasMustChangePassword) {
            $selectCols[] = 'must_change_password';
        }
        if ($hasActivationRequired) {
            $selectCols[] = 'activation_required';
        }
        if (UsersSchema::hasActivatedAtUtc($pdo)) {
            $selectCols[] = 'activated_at_utc';
        }
        $selectSql = 'SELECT ' . implode(', ', $selectCols) . ' FROM users ORDER BY id ASC';
        $stmt = $pdo->query($selectSql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int) $row['id'];
        }
        $subeIdsByUser = self::loadSubeIdsByUserIds($pdo, $userIds);
        $bolumIdsByUser = UserOrgAssignmentSchema::loadBolumIdsByUserIds($pdo, $userIds);
        $birimIdsByUser = UserOrgAssignmentSchema::loadBirimIdsByUserIds($pdo, $userIds);
        $personelAdById = $hasPersonelId ? self::loadPersonelAdSoyadByIds($pdo, $rows) : [];

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $items[] = self::mapKullaniciRow(
                $row,
                $subeIdsByUser[$id] ?? [],
                $hasVarsayilan,
                $hasPersonelId,
                $personelAdById,
                $hasMustChangePassword,
                $bolumIdsByUser[$id] ?? [],
                $birimIdsByUser[$id] ?? [],
                UserOrgAssignmentSchema::loadUserSirketIds($pdo, $id),
                UserOrgAssignmentSchema::loadUserSgkIsverenIds($pdo, $id)
            );
        }

        JsonResponse::success(['items' => $items]);
    }

    public static function kullaniciOlustur(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertKullaniciYonetimi($user);

        $body = $request->getJsonBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $adSoyad = trim((string) ($body['ad_soyad'] ?? ''));
        $rol = strtoupper(trim((string) ($body['rol'] ?? '')));
        $durum = strtoupper(trim((string) ($body['durum'] ?? 'AKTIF')));
        $finalSubeIds = self::parseSubeIds(isset($body['sube_ids']) ? $body['sube_ids'] : []);
        $finalBolumIds = self::parseSubeIds(isset($body['bolum_ids']) ? $body['bolum_ids'] : []);
        $finalBirimIds = self::parseSubeIds(isset($body['birim_ids']) ? $body['birim_ids'] : []);
        $finalSirketIds = self::parseSubeIds(isset($body['sirket_ids']) ? $body['sirket_ids'] : []);
        $finalSgkIsverenIds = self::parseSubeIds(isset($body['sgk_isveren_ids']) ? $body['sgk_isveren_ids'] : []);
        $finalVarsayilanSubeId = self::parseOptionalInt($body['varsayilan_sube_id'] ?? null);
        $personelIdProvided = array_key_exists('personel_id', $body);
        $requestedPersonelId = $personelIdProvided ? self::parseOptionalInt($body['personel_id']) : null;

        // Secure onboarding owns PERSONEL + personel_id creation (no admin-chosen password).
        PersonelAccountOnboardingService::rejectGenericPersonelBoundCreate($body);

        if ($username === '') {
            JsonResponse::badRequest('Kullanici adi zorunludur.', 'VALIDATION_ERROR', 'username');
        }
        if ($adSoyad === '') {
            JsonResponse::badRequest('Ad soyad zorunludur.', 'VALIDATION_ERROR', 'ad_soyad');
        }
        if (!self::isValidRole($rol)) {
            JsonResponse::badRequest('Gecersiz rol.', 'VALIDATION_ERROR', 'rol');
        }
        if ($durum !== 'AKTIF' && $durum !== 'PASIF') {
            JsonResponse::badRequest('Gecersiz durum.', 'VALIDATION_ERROR', 'durum');
        }

        // No plaintext password from the admin UI: new accounts get the initial
        // password derived from their own stored name and must change it on first
        // login. An explicit password stays supported for legacy API callers.
        if ($password !== '') {
            PasswordPolicy::assertValidNewPassword($password);
            $passwordHash = PasswordHasher::hash($password);
        } else {
            $passwordHash = InitialPassword::requireHashForName($adSoyad);
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        if (self::usernameExists($pdo, $username)) {
            JsonResponse::error(409, 'DUPLICATE_USERNAME', 'Bu kullanici adi zaten kayitli.', 'username');
        }

        self::assertSubeYoneticisiRoleSchemaReady($pdo, $rol);
        self::assertSubeIdsExist($pdo, $finalSubeIds);
        self::assertBolumIdsExist($pdo, $finalBolumIds);
        self::assertBirimIdsExist($pdo, $finalBirimIds);
        self::assertHierarchyScopeAllowed($pdo, $rol, $finalSirketIds, $finalSgkIsverenIds);
        self::assertVarsayilanSubeInEffectiveScope($pdo, $finalVarsayilanSubeId, $finalSubeIds, $finalSirketIds);
        self::assertRoleOrgAssignments(
            $rol,
            $finalSubeIds,
            $finalBolumIds,
            $finalBirimIds,
            $requestedPersonelId,
            $rol === 'PERSONEL' || $personelIdProvided,
            $finalSirketIds,
            $finalSgkIsverenIds
        );
        self::assertAuthSmokeReadonlyContract($username, $rol, $finalSubeIds, $finalVarsayilanSubeId);

        $hasVarsayilan = UsersSchema::hasVarsayilanSubeId($pdo);
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $hasMustChange = UsersSchema::hasMustChangePassword($pdo);
        if ($finalVarsayilanSubeId !== null && !$hasVarsayilan) {
            JsonResponse::error(
                409,
                'SCHEMA_NOT_READY',
                'Varsayilan sube semasi hazir degil.',
                'varsayilan_sube_id'
            );
        }
        if ($personelIdProvided && $requestedPersonelId !== null && !$hasPersonelId) {
            JsonResponse::error(
                409,
                'SCHEMA_NOT_READY',
                'Personel baglama semasi hazir degil.',
                'personel_id'
            );
        }

        $actorUserId = isset($user['id']) ? (int) $user['id'] : 0;

        $pdo->beginTransaction();
        try {
            if ($hasVarsayilan) {
                $insertCols = 'username, password_hash, ad_soyad, rol, durum, varsayilan_sube_id';
                $insertVals = ':username, :password_hash, :ad_soyad, :rol, :durum, :varsayilan_sube_id';
                if ($hasMustChange) {
                    $insertCols .= ', must_change_password';
                    $insertVals .= ', 1';
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO users ($insertCols) VALUES ($insertVals)"
                );
                $stmt->execute([
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'ad_soyad' => $adSoyad,
                    'rol' => $rol,
                    'durum' => $durum,
                    'varsayilan_sube_id' => $finalVarsayilanSubeId,
                ]);
            } else {
                $insertCols = 'username, password_hash, ad_soyad, rol, durum';
                $insertVals = ':username, :password_hash, :ad_soyad, :rol, :durum';
                if ($hasMustChange) {
                    $insertCols .= ', must_change_password';
                    $insertVals .= ', 1';
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO users ($insertCols) VALUES ($insertVals)"
                );
                $stmt->execute([
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'ad_soyad' => $adSoyad,
                    'rol' => $rol,
                    'durum' => $durum,
                ]);
            }
            $userId = (int) $pdo->lastInsertId();
            self::replaceUserSubeler($pdo, $userId, $finalSubeIds);
            self::replaceUserBolumler($pdo, $userId, $finalBolumIds);
            self::replaceUserBirimler($pdo, $userId, $finalBirimIds);
            UserOrgAssignmentSchema::replaceUserSirketler($pdo, $userId, $finalSirketIds);
            UserOrgAssignmentSchema::replaceUserSgkIsverenler($pdo, $userId, $finalSgkIsverenIds);
            if ($hasPersonelId && $personelIdProvided) {
                UserPersonelBindingService::applyBinding($pdo, $userId, $requestedPersonelId, $actorUserId);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($driverCode === 1062 || stripos($e->getMessage(), 'uq_users_personel_id') !== false) {
                JsonResponse::error(
                    409,
                    'PERSONEL_ALREADY_BOUND',
                    'Bu personel kaydi baska bir kullaniciya bagli.',
                    'personel_id'
                );
            }
            JsonResponse::serverError('Kullanici kaydi olusturulamadi.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Kullanici kaydi olusturulamadi.');
        }

        $created = self::findKullaniciById($pdo, $userId);
        if ($created === null) {
            JsonResponse::serverError('Kullanici kaydi olusturulamadi.');
        }

        JsonResponse::success($created);
    }

    public static function kullaniciGuncelle(Request $request, $kullaniciId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertKullaniciYonetimi($user);

        $kullaniciId = (int) $kullaniciId;
        if ($kullaniciId <= 0) {
            JsonResponse::badRequest('Gecersiz kullanici id.', 'VALIDATION_ERROR', 'id');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $existing = self::findKullaniciRowById($pdo, $kullaniciId);
        if ($existing === null) {
            JsonResponse::notFound('Kullanici bulunamadi.');
        }

        $body = $request->getJsonBody();
        $username = array_key_exists('username', $body)
            ? trim((string) $body['username'])
            : (string) $existing['username'];
        $password = array_key_exists('password', $body) ? (string) $body['password'] : '';
        $resetToInitial = self::parseInitialPasswordResetIntent($body);
        $adSoyad = array_key_exists('ad_soyad', $body)
            ? trim((string) $body['ad_soyad'])
            : (string) $existing['ad_soyad'];
        $rol = array_key_exists('rol', $body)
            ? strtoupper(trim((string) $body['rol']))
            : (string) $existing['rol'];
        $durum = array_key_exists('durum', $body)
            ? strtoupper(trim((string) $body['durum']))
            : (string) $existing['durum'];

        $subeIdsProvided = array_key_exists('sube_ids', $body);
        $bolumIdsProvided = array_key_exists('bolum_ids', $body);
        $birimIdsProvided = array_key_exists('birim_ids', $body);
        $sirketIdsProvided = array_key_exists('sirket_ids', $body);
        $sgkIsverenIdsProvided = array_key_exists('sgk_isveren_ids', $body);
        $varsayilanProvided = array_key_exists('varsayilan_sube_id', $body);
        $requestedVarsayilan = $varsayilanProvided ? self::parseOptionalInt($body['varsayilan_sube_id']) : null;
        $personelIdProvided = array_key_exists('personel_id', $body);
        $requestedPersonelId = $personelIdProvided ? self::parseOptionalInt($body['personel_id']) : null;

        $currentSubeIds = self::loadSubeIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
        $currentBolumIds = UserOrgAssignmentSchema::loadBolumIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
        $currentBirimIds = UserOrgAssignmentSchema::loadBirimIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
        $currentSirketIds = UserOrgAssignmentSchema::loadUserSirketIds($pdo, $kullaniciId);
        $currentSgkIsverenIds = UserOrgAssignmentSchema::loadUserSgkIsverenIds($pdo, $kullaniciId);
        $currentStoredDefault = self::readStoredVarsayilanFromRow($existing);

        $finalSirketIds = $sirketIdsProvided ? self::parseSubeIds($body['sirket_ids']) : $currentSirketIds;
        $finalSgkIsverenIds = $sgkIsverenIdsProvided ? self::parseSubeIds($body['sgk_isveren_ids']) : $currentSgkIsverenIds;
        $finalSubeIds = $subeIdsProvided ? self::parseSubeIds($body['sube_ids']) : $currentSubeIds;
        $finalBolumIds = $bolumIdsProvided ? self::parseSubeIds($body['bolum_ids']) : $currentBolumIds;
        $finalBirimIds = $birimIdsProvided ? self::parseSubeIds($body['birim_ids']) : $currentBirimIds;
        $finalVarsayilanSubeId = $currentStoredDefault;
        if ($varsayilanProvided) {
            $finalVarsayilanSubeId = $requestedVarsayilan;
        } elseif ($subeIdsProvided) {
            if ($currentStoredDefault !== null && !in_array($currentStoredDefault, $finalSubeIds, true)) {
                $finalVarsayilanSubeId = null;
            }
        }

        if ($username === '') {
            JsonResponse::badRequest('Kullanici adi zorunludur.', 'VALIDATION_ERROR', 'username');
        }
        if ($adSoyad === '') {
            JsonResponse::badRequest('Ad soyad zorunludur.', 'VALIDATION_ERROR', 'ad_soyad');
        }
        if (!self::isValidRole($rol)) {
            JsonResponse::badRequest('Gecersiz rol.', 'VALIDATION_ERROR', 'rol');
        }
        if ($durum !== 'AKTIF' && $durum !== 'PASIF') {
            JsonResponse::badRequest('Gecersiz durum.', 'VALIDATION_ERROR', 'durum');
        }
        if ($username !== (string) $existing['username'] && self::usernameExists($pdo, $username, $kullaniciId)) {
            JsonResponse::error(409, 'DUPLICATE_USERNAME', 'Bu kullanici adi zaten kayitli.', 'username');
        }

        // Password writes stay one canonical owner: either the legacy explicit password
        // or the initial password reset intent, never both.
        $passwordHash = null;
        if ($password !== '' && $resetToInitial) {
            JsonResponse::badRequest(
                'Sifre alani ile baslangic sifresi sifirlama ayni istekte kullanilamaz.',
                'VALIDATION_ERROR',
                'baslangic_sifresine_sifirla'
            );
        }
        if ($password !== '') {
            PasswordPolicy::assertValidNewPassword($password);
            $passwordHash = PasswordHasher::hash($password);
        } elseif ($resetToInitial) {
            // The name is resolved from stored state only, never from the request:
            // a bound personnel record owns it, otherwise the stored user field does.
            $passwordHash = InitialPassword::requireHashForName(
                self::resolveStoredAdSoyadForInitialPassword($pdo, $existing)
            );
        }

        self::assertSubeYoneticisiRoleSchemaReady($pdo, $rol);

        if ($subeIdsProvided) {
            self::assertSubeIdsExist($pdo, $finalSubeIds);
        }
        if ($bolumIdsProvided) {
            self::assertBolumIdsExist($pdo, $finalBolumIds);
        }
        if ($birimIdsProvided) {
            self::assertBirimIdsExist($pdo, $finalBirimIds);
        }
        self::assertHierarchyScopeAllowed($pdo, $rol, $finalSirketIds, $finalSgkIsverenIds);
        self::assertVarsayilanSubeInEffectiveScope($pdo, $finalVarsayilanSubeId, $finalSubeIds, $finalSirketIds);
        $effectivePersonelId = $personelIdProvided
            ? $requestedPersonelId
            : self::readStoredPersonelIdFromRow($existing);
        self::assertRoleOrgAssignments(
            $rol,
            $finalSubeIds,
            $finalBolumIds,
            $finalBirimIds,
            $effectivePersonelId,
            true,
            $finalSirketIds,
            $finalSgkIsverenIds
        );
        self::assertAuthSmokeReadonlyContract($username, $rol, $finalSubeIds, $finalVarsayilanSubeId);

        $hasVarsayilan = UsersSchema::hasVarsayilanSubeId($pdo);
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $hasMustChange = UsersSchema::hasMustChangePassword($pdo);
        $needsVarsayilanWrite = $varsayilanProvided
            || ($subeIdsProvided && $finalVarsayilanSubeId !== $currentStoredDefault);
        if ($needsVarsayilanWrite && !$hasVarsayilan) {
            if ($finalVarsayilanSubeId !== null) {
                JsonResponse::error(
                    409,
                    'SCHEMA_NOT_READY',
                    'Varsayilan sube semasi hazir degil.',
                    'varsayilan_sube_id'
                );
            }
            // Explicit NULL / cleared default with schema absent: legacy-compatible no-op on column.
            $needsVarsayilanWrite = false;
        }
        if ($personelIdProvided && !$hasPersonelId) {
            if ($requestedPersonelId !== null) {
                JsonResponse::error(
                    409,
                    'SCHEMA_NOT_READY',
                    'Personel baglama semasi hazir degil.',
                    'personel_id'
                );
            }
            // Explicit null clear with schema absent: no-op.
            $personelIdProvided = false;
        }

        $actorUserId = isset($user['id']) ? (int) $user['id'] : 0;

        // Status, role, username and personnel binding are the access-defining
        // fields of an account: together they decide whether someone can log in,
        // as whom, and with which authority. They are audited as one event by
        // migration 082's owner, which is a different table from the revocation
        // owner in 081 — DELETE keeps writing revocations, this path keeps
        // writing changes, and neither writes into the other.
        //
        // The binding flag is read after the schema fallbacks above, so a
        // personel_id that was demoted to a no-op is not audited as a change.
        $storedPersonelId = self::readStoredPersonelIdFromRow($existing);
        $accessBefore = [
            'durum' => (string) $existing['durum'],
            'rol' => (string) $existing['rol'],
            'username' => (string) $existing['username'],
            'personel_id' => $storedPersonelId,
        ];
        $accessAfter = [
            'durum' => $durum,
            'rol' => $rol,
            'username' => $username,
            'personel_id' => ($hasPersonelId && $personelIdProvided) ? $requestedPersonelId : $storedPersonelId,
        ];
        $accessAuditContext = null;
        if (OrganizasyonAuditWriter::resolveAccessEventType($accessBefore, $accessAfter) !== null) {
            // Fail-closed before the mutation starts: an environment without
            // migration 082 must refuse the change rather than perform it
            // unaudited. Read-only calls and edits that touch none of these four
            // fields never reach this gate.
            try {
                OrganizasyonAuditWriter::assertAccessChangeReady($pdo);
                $accessAuditContext = OrganizasyonAuditContext::fromRequest($request, $user);
            } catch (OrganizasyonException $e) {
                JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
            }
        }

        // Organisation scope is the grant that decides what a user can see, so a
        // change to it is audited before/after. Fail-closed: an environment
        // without migration 080 cannot silently regrant scope.
        $scopeAxesTouched = [
            OrganizasyonAuditWriter::SCOPE_SUBE => [$subeIdsProvided, $currentSubeIds, $finalSubeIds],
            OrganizasyonAuditWriter::SCOPE_SIRKET => [$sirketIdsProvided, $currentSirketIds, $finalSirketIds],
            OrganizasyonAuditWriter::SCOPE_SGK_ISVEREN => [
                $sgkIsverenIdsProvided,
                $currentSgkIsverenIds,
                $finalSgkIsverenIds,
            ],
        ];
        $scopeChanges = [];
        foreach ($scopeAxesTouched as $scopeTuru => $axis) {
            list($provided, $before, $after) = $axis;
            if (!$provided) {
                continue;
            }
            if (OrganizasyonAuditWriter::canonicalIdList($before) === OrganizasyonAuditWriter::canonicalIdList($after)) {
                continue;
            }
            $scopeChanges[$scopeTuru] = [$before, $after];
        }
        $scopeAuditContext = null;
        if (count($scopeChanges) > 0) {
            try {
                OrganizasyonAuditWriter::assertReady($pdo, OrganizasyonAuditWriter::USER_SCOPE_TABLE);
                $scopeAuditContext = OrganizasyonAuditContext::fromRequest($request, $user);
            } catch (OrganizasyonException $e) {
                JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
            }
        }
        $scopeGerekce = isset($body['gerekce']) && is_string($body['gerekce']) && trim($body['gerekce']) !== ''
            ? trim($body['gerekce'])
            : null;

        $pdo->beginTransaction();
        try {
            $params = [
                'id' => $kullaniciId,
                'username' => $username,
                'ad_soyad' => $adSoyad,
                'rol' => $rol,
                'durum' => $durum,
            ];
            $sql = 'UPDATE users SET username = :username, ad_soyad = :ad_soyad, rol = :rol, durum = :durum';
            if ($passwordHash !== null) {
                $sql .= ', password_hash = :password_hash';
                $params['password_hash'] = $passwordHash;
                if ($hasMustChange) {
                    $sql .= ', must_change_password = 1';
                }
            }
            if ($hasVarsayilan && ($needsVarsayilanWrite || $varsayilanProvided || $subeIdsProvided)) {
                $sql .= ', varsayilan_sube_id = :varsayilan_sube_id';
                $params['varsayilan_sube_id'] = $finalVarsayilanSubeId;
            }
            $sql .= ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($subeIdsProvided) {
                self::replaceUserSubeler($pdo, $kullaniciId, $finalSubeIds);
            }
            if ($bolumIdsProvided) {
                self::replaceUserBolumler($pdo, $kullaniciId, $finalBolumIds);
            }
            if ($birimIdsProvided) {
                self::replaceUserBirimler($pdo, $kullaniciId, $finalBirimIds);
            }
            if ($sirketIdsProvided) {
                UserOrgAssignmentSchema::replaceUserSirketler($pdo, $kullaniciId, $finalSirketIds);
            }
            if ($sgkIsverenIdsProvided) {
                UserOrgAssignmentSchema::replaceUserSgkIsverenler($pdo, $kullaniciId, $finalSgkIsverenIds);
            }

            if ($hasPersonelId && $personelIdProvided) {
                UserPersonelBindingService::applyBinding(
                    $pdo,
                    $kullaniciId,
                    $requestedPersonelId,
                    $actorUserId
                );
            }

            // After the binding, so the audited after-image is the state the
            // transaction will actually commit. A failure here propagates and
            // rolls back the user row, the scope writes, the binding and the
            // credential reset together.
            if ($accessAuditContext !== null) {
                OrganizasyonAuditWriter::recordUserAccessChange(
                    $pdo,
                    $kullaniciId,
                    $accessBefore,
                    $accessAfter,
                    $accessAuditContext,
                    $scopeGerekce
                );
            }

            if ($scopeAuditContext !== null) {
                foreach ($scopeChanges as $scopeTuru => $sets) {
                    OrganizasyonAuditWriter::recordUserOrgScopeChange(
                        $pdo,
                        $kullaniciId,
                        (string) $scopeTuru,
                        $sets[0],
                        $sets[1],
                        $scopeAuditContext,
                        $scopeGerekce
                    );
                }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($driverCode === 1062 || stripos($e->getMessage(), 'uq_users_personel_id') !== false) {
                JsonResponse::error(
                    409,
                    'PERSONEL_ALREADY_BOUND',
                    'Bu personel kaydi baska bir kullaniciya bagli.',
                    'personel_id'
                );
            }
            JsonResponse::serverError('Kullanici kaydi guncellenemedi.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Kullanici kaydi guncellenemedi.');
        }

        $updated = self::findKullaniciById($pdo, $kullaniciId);
        if ($updated === null) {
            JsonResponse::serverError('Kullanici kaydi guncellenemedi.');
        }

        JsonResponse::success($updated);
    }

    /**
     * Remove a login account.
     *
     * The users row is deliberately kept. Every audit, binding and history row
     * points at it with ON DELETE RESTRICT, so a hard delete would either fail or
     * cost the attribution those records exist to provide — a deleted account
     * whose past actions can no longer be explained is worse than a revoked one.
     * What is removed is everything that makes the account usable: it is
     * deactivated (AuthMiddleware rejects a non-AKTIF user, so every issued token
     * stops working on its next request), its credential is replaced by an
     * unusable random secret, any pending activation invitation is revoked, and
     * all organisation scope grants are cleared.
     *
     * A bound personnel record is never touched: the binding is cleared through
     * its own audited owner and the personeller row survives untouched.
     *
     * Idempotent: repeating the call on an already revoked account clears
     * whatever is left and reports success without writing a second audit row it
     * has nothing to describe.
     */
    public static function kullaniciErisimKaldir(Request $request, $kullaniciId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertKullaniciYonetimi($user);

        $kullaniciId = (int) $kullaniciId;
        if ($kullaniciId <= 0) {
            JsonResponse::badRequest('Gecersiz kullanici id.', 'VALIDATION_ERROR', 'id');
        }

        $actorUserId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($actorUserId === $kullaniciId) {
            JsonResponse::error(409, 'SELF_ACCESS_REMOVAL_FORBIDDEN', 'Kendi hesabinizin erisimini kaldiramazsiniz.', 'id');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $existing = self::findKullaniciRowById($pdo, $kullaniciId);
        if ($existing === null) {
            JsonResponse::notFound('Kullanici bulunamadi.');
        }

        // An unauditable environment must not gain an account nobody can explain
        // the disappearance of. Asserted before any write, like the 080 owners.
        try {
            OrganizasyonAuditWriter::assertReady($pdo, OrganizasyonAuditWriter::USER_ACCESS_REVOKE_TABLE);
            OrganizasyonAuditWriter::assertReady($pdo, OrganizasyonAuditWriter::USER_SCOPE_TABLE);
            $auditContext = OrganizasyonAuditContext::fromRequest($request, $user);
        } catch (OrganizasyonException $e) {
            JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
        }

        $body = $request->getJsonBody();
        $gerekce = isset($body['gerekce']) && is_string($body['gerekce']) && trim($body['gerekce']) !== ''
            ? trim($body['gerekce'])
            : null;

        $oncekiDurum = strtoupper(trim((string) $existing['durum']));
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $korunanPersonelId = null;
        if ($hasPersonelId && isset($existing['personel_id']) && (int) $existing['personel_id'] > 0) {
            $korunanPersonelId = (int) $existing['personel_id'];
        }

        $currentSubeIds = self::loadSubeIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
        $currentBolumIds = UserOrgAssignmentSchema::loadBolumIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
        $currentBirimIds = UserOrgAssignmentSchema::loadBirimIdsByUserIds($pdo, [$kullaniciId])[$kullaniciId] ?? [];
        $currentSirketIds = UserOrgAssignmentSchema::loadUserSirketIds($pdo, $kullaniciId);
        $currentSgkIsverenIds = UserOrgAssignmentSchema::loadUserSgkIsverenIds($pdo, $kullaniciId);
        $temizlenenScope = count($currentSubeIds) + count($currentBolumIds) + count($currentBirimIds)
            + count($currentSirketIds) + count($currentSgkIsverenIds);

        $alreadyRevoked = $oncekiDurum !== 'AKTIF'
            && $temizlenenScope === 0
            && $korunanPersonelId === null;
        if ($alreadyRevoked) {
            JsonResponse::success(self::findKullaniciById($pdo, $kullaniciId));
        }

        $hasMustChange = UsersSchema::hasMustChangePassword($pdo);
        $hasVarsayilan = UsersSchema::hasVarsayilanSubeId($pdo);

        $pdo->beginTransaction();
        try {
            if ($korunanPersonelId !== null) {
                // Clears the link only. The personeller row is not read for
                // change and not written here.
                UserPersonelBindingService::applyBinding($pdo, $kullaniciId, null, $actorUserId);
            }

            if (count($currentSubeIds) > 0) {
                self::replaceUserSubeler($pdo, $kullaniciId, []);
            }
            if (count($currentBolumIds) > 0) {
                self::replaceUserBolumler($pdo, $kullaniciId, []);
            }
            if (count($currentBirimIds) > 0) {
                self::replaceUserBirimler($pdo, $kullaniciId, []);
            }
            if (count($currentSirketIds) > 0) {
                UserOrgAssignmentSchema::replaceUserSirketler($pdo, $kullaniciId, []);
            }
            if (count($currentSgkIsverenIds) > 0) {
                UserOrgAssignmentSchema::replaceUserSgkIsverenler($pdo, $kullaniciId, []);
            }

            $sql = 'UPDATE users SET durum = :durum, password_hash = :password_hash';
            $params = [
                'id' => $kullaniciId,
                'durum' => 'PASIF',
                // Random, never returned and never recoverable: the account keeps
                // a syntactically valid credential that nobody holds.
                'password_hash' => PasswordHasher::hash(bin2hex(random_bytes(32))),
            ];
            if ($hasMustChange) {
                $sql .= ', must_change_password = 1';
            }
            if ($hasVarsayilan) {
                $sql .= ', varsayilan_sube_id = NULL';
            }
            $sql .= ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            self::revokePendingActivationInvitations($pdo, $kullaniciId);

            $scopeAxes = [
                OrganizasyonAuditWriter::SCOPE_SUBE => $currentSubeIds,
                OrganizasyonAuditWriter::SCOPE_SIRKET => $currentSirketIds,
                OrganizasyonAuditWriter::SCOPE_SGK_ISVEREN => $currentSgkIsverenIds,
            ];
            foreach ($scopeAxes as $scopeTuru => $before) {
                OrganizasyonAuditWriter::recordUserOrgScopeChange(
                    $pdo,
                    $kullaniciId,
                    (string) $scopeTuru,
                    $before,
                    [],
                    $auditContext,
                    $gerekce
                );
            }

            OrganizasyonAuditWriter::recordUserAccessRevoke(
                $pdo,
                [
                    'target_user_id' => $kullaniciId,
                    'target_username' => (string) $existing['username'],
                    'onceki_durum' => $oncekiDurum,
                    'korunan_personel_id' => $korunanPersonelId,
                    'temizlenen_scope_satiri' => $temizlenenScope,
                ],
                $auditContext,
                $gerekce
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof OrganizasyonException) {
                JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
            }
            JsonResponse::serverError('Kullanici erisimi kaldirilamadi.');
        }

        $updated = self::findKullaniciById($pdo, $kullaniciId);
        if ($updated === null || strtoupper((string) ($updated['durum'] ?? '')) !== 'PASIF') {
            JsonResponse::serverError('Kullanici erisimi kaldirilamadi.');
        }

        JsonResponse::success($updated);
    }

    /**
     * Pending activation invitations are a second, token-shaped way into the
     * account, so they are revoked with it. Absent schema is not an error: there
     * is then nothing to revoke.
     */
    private static function revokePendingActivationInvitations(PDO $pdo, $kullaniciId)
    {
        try {
            $table = $pdo->query("SHOW TABLES LIKE 'personel_account_activation_invitations'");
            $exists = $table !== false && $table->fetch(PDO::FETCH_NUM) !== false;
            if ($table !== false) {
                $table->closeCursor();
            }
            if (!$exists) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE personel_account_activation_invitations
                SET revoked_at_utc = UTC_TIMESTAMP()
              WHERE user_id = :user_id
                AND revoked_at_utc IS NULL
                AND consumed_at_utc IS NULL'
        );
        $stmt->execute(['user_id' => (int) $kullaniciId]);
    }

    /** @param array<string, mixed> $user */
    private static function assertKullaniciYonetimi(array $user)
    {
        RolePermissions::assert($user, 'yonetim-paneli.manage');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, int> $subeIds
     * @param array<int, string> $personelAdById
     * @return array<string, mixed>
     */
    private static function mapKullaniciRow(
        array $row,
        array $subeIds,
        $hasVarsayilanColumn = false,
        $hasPersonelIdColumn = false,
        array $personelAdById = [],
        $hasMustChangePasswordColumn = false,
        array $bolumIds = [],
        array $birimIds = [],
        array $sirketIds = [],
        array $sgkIsverenIds = []
    ) {
        $rol = (string) $row['rol'];
        $storedDefault = null;
        if ($hasVarsayilanColumn) {
            $storedDefault = self::readStoredVarsayilanFromRow($row);
        }

        $personelId = null;
        $personelAdSoyad = null;
        if ($hasPersonelIdColumn) {
            $personelId = self::readStoredPersonelIdFromRow($row);
            if ($personelId !== null && isset($personelAdById[$personelId])) {
                $personelAdSoyad = $personelAdById[$personelId];
            }
        }

        $mapped = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'ad_soyad' => (string) $row['ad_soyad'],
            'rol' => $rol,
            'durum' => (string) $row['durum'],
            'sube_ids' => $subeIds,
            'bolum_ids' => $bolumIds,
            'birim_ids' => $birimIds,
            'sirket_ids' => $sirketIds,
            'sgk_isveren_ids' => $sgkIsverenIds,
            'varsayilan_sube_id' => $storedDefault,
            'telefon' => null,
            'personel_id' => $personelId,
            'personel_ad_soyad' => $personelAdSoyad,
            'kullanici_tipi' => $rol === 'GENEL_YONETICI' ? 'HARICI' : 'IC_PERSONEL',
            'notlar' => null,
        ];

        if ($hasMustChangePasswordColumn) {
            $mapped['must_change_password'] = self::readStoredMustChangePasswordFromRow($row);
        }

        if (array_key_exists('activation_required', $row)) {
            $mapped['activation_required'] = ((int) ($row['activation_required'] ?? 0)) === 1;
            if ($mapped['activation_required']) {
                $mapped['activation_status'] = 'PENDING';
            } elseif (array_key_exists('activated_at_utc', $row) && $row['activated_at_utc']) {
                $mapped['activation_status'] = 'ACTIVE';
                $mapped['activated_at_utc'] = (string) $row['activated_at_utc'];
            } else {
                $mapped['activation_status'] = 'ACTIVE';
            }
        }

        return $mapped;
    }

    /** @param array<string, mixed> $row */
    private static function readStoredMustChangePasswordFromRow(array $row)
    {
        if (!array_key_exists('must_change_password', $row) || $row['must_change_password'] === null || $row['must_change_password'] === '') {
            return false;
        }

        return ((int) $row['must_change_password']) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function readStoredVarsayilanFromRow(array $row)
    {
        if (!array_key_exists('varsayilan_sube_id', $row) || $row['varsayilan_sube_id'] === null || $row['varsayilan_sube_id'] === '') {
            return null;
        }

        $parsed = (int) $row['varsayilan_sube_id'];

        return $parsed > 0 ? $parsed : null;
    }

    /** @param array<string, mixed> $row */
    private static function readStoredPersonelIdFromRow(array $row)
    {
        if (!array_key_exists('personel_id', $row) || $row['personel_id'] === null || $row['personel_id'] === '') {
            return null;
        }

        $parsed = (int) $row['personel_id'];

        return $parsed > 0 ? $parsed : null;
    }

    /**
     * @param array<int, array<string, mixed>> $userRows
     * @return array<int, string>
     */
    private static function loadPersonelAdSoyadByIds(PDO $pdo, array $userRows)
    {
        $ids = [];
        foreach ($userRows as $row) {
            $pid = self::readStoredPersonelIdFromRow($row);
            if ($pid !== null) {
                $ids[] = $pid;
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, ad, soyad FROM personeller WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['id']] = trim((string) $row['ad'] . ' ' . (string) $row['soyad']);
        }

        return $map;
    }

    /** @param array<int, int> $userIds @return array<int, array<int, int>> */
    private static function loadSubeIdsByUserIds(PDO $pdo, array $userIds)
    {
        if (count($userIds) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT user_id, sube_id FROM user_subeler WHERE user_id IN ($placeholders) ORDER BY user_id ASC, sube_id ASC"
        );
        $stmt->execute($userIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['user_id'];
            if (!isset($map[$userId])) {
                $map[$userId] = [];
            }
            $map[$userId][] = (int) $row['sube_id'];
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    private static function findKullaniciRowById(PDO $pdo, $userId)
    {
        $hasVarsayilan = UsersSchema::hasVarsayilanSubeId($pdo);
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $hasMustChangePassword = UsersSchema::hasMustChangePassword($pdo);
        $cols = ['id', 'username', 'ad_soyad', 'rol', 'durum'];
        if ($hasVarsayilan) {
            $cols[] = 'varsayilan_sube_id';
        }
        if ($hasPersonelId) {
            $cols[] = 'personel_id';
        }
        if ($hasMustChangePassword) {
            $cols[] = 'must_change_password';
        }
        if (UsersSchema::hasActivationRequired($pdo)) {
            $cols[] = 'activation_required';
        }
        if (UsersSchema::hasActivatedAtUtc($pdo)) {
            $cols[] = 'activated_at_utc';
        }
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function findKullaniciById(PDO $pdo, $userId)
    {
        $row = self::findKullaniciRowById($pdo, $userId);
        if ($row === null) {
            return null;
        }

        $hasVarsayilan = UsersSchema::hasVarsayilanSubeId($pdo);
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $hasMustChangePassword = UsersSchema::hasMustChangePassword($pdo);
        $subeIds = self::loadSubeIdsByUserIds($pdo, [(int) $userId])[(int) $userId] ?? [];
        $bolumIds = UserOrgAssignmentSchema::loadBolumIdsByUserIds($pdo, [(int) $userId])[(int) $userId] ?? [];
        $birimIds = UserOrgAssignmentSchema::loadBirimIdsByUserIds($pdo, [(int) $userId])[(int) $userId] ?? [];
        $personelAdById = $hasPersonelId ? self::loadPersonelAdSoyadByIds($pdo, [$row]) : [];

        return self::mapKullaniciRow(
            $row,
            $subeIds,
            $hasVarsayilan,
            $hasPersonelId,
            $personelAdById,
            $hasMustChangePassword,
            $bolumIds,
            $birimIds,
            UserOrgAssignmentSchema::loadUserSirketIds($pdo, (int) $userId),
            UserOrgAssignmentSchema::loadUserSgkIsverenIds($pdo, (int) $userId)
        );
    }

    private static function usernameExists(PDO $pdo, $username, $excludeUserId = null)
    {
        $sql = 'SELECT id FROM users WHERE username = :username';
        $params = ['username' => $username];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeUserId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param mixed $value @return array<int, int> */
    private static function parseSubeIds($value)
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $parsed = (int) $item;
            if ($parsed > 0) {
                $ids[] = $parsed;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param mixed $value */
    private static function parseOptionalInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    /**
     * Boolean-only intent flag; anything other than a real boolean fails closed.
     *
     * @param array<string, mixed> $body
     */
    private static function parseInitialPasswordResetIntent(array $body)
    {
        if (!array_key_exists('baslangic_sifresine_sifirla', $body)) {
            return false;
        }

        $value = $body['baslangic_sifresine_sifirla'];
        if (!is_bool($value)) {
            JsonResponse::badRequest(
                'Baslangic sifresi sifirlama alani boolean olmalidir.',
                'VALIDATION_ERROR',
                'baslangic_sifresine_sifirla'
            );
        }

        return $value;
    }

    /**
     * Canonical stored name behind an account's initial password: the bound
     * personnel record when there is one, otherwise the stored user name.
     *
     * @param array<string, mixed> $existing
     */
    private static function resolveStoredAdSoyadForInitialPassword(PDO $pdo, array $existing)
    {
        $personelId = self::readStoredPersonelIdFromRow($existing);
        if ($personelId !== null) {
            $names = self::loadPersonelAdSoyadByIds($pdo, [$existing]);
            $personelAdSoyad = trim((string) ($names[$personelId] ?? ''));
            if ($personelAdSoyad !== '') {
                return $personelAdSoyad;
            }
        }

        return trim((string) ($existing['ad_soyad'] ?? ''));
    }

    private static function isValidRole($rol)
    {
        return in_array($rol, self::$validRoles, true);
    }

    /** @param array<int, int> $subeIds */
    private static function assertSubeIdsExist(PDO $pdo, array $subeIds)
    {
        if (count($subeIds) === 0) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($subeIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM subeler WHERE id IN ($placeholders)");
        $stmt->execute($subeIds);
        $total = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($total !== count($subeIds)) {
            JsonResponse::badRequest('Gecersiz sube secimi.', 'VALIDATION_ERROR', 'sube_ids');
        }
    }

    /**
     * Company and payroll scope grants are validated server-side, per role.
     *
     * A branch manager must never be handed a company scope: that is how a
     * branch-scoped account would silently become company-wide, so the payload
     * is rejected rather than trimmed.
     *
     * @param array<int, int> $sirketIds
     * @param array<int, int> $sgkIsverenIds
     */
    private static function assertHierarchyScopeAllowed(PDO $pdo, $rol, array $sirketIds, array $sgkIsverenIds)
    {
        if (count($sirketIds) === 0 && count($sgkIsverenIds) === 0) {
            return;
        }

        if (!UserOrgAssignmentSchema::isHierarchyScopeReady($pdo)) {
            JsonResponse::error(
                409,
                'SCHEMA_NOT_READY',
                'Sirket/SGK kapsam semasi hazir degil.',
                count($sirketIds) > 0 ? 'sirket_ids' : 'sgk_isveren_ids'
            );
        }

        if (!OrgScope::isSirketScopeEligible($rol, $sirketIds)) {
            JsonResponse::badRequest(
                'Bu rol sirket kapsami alamaz.',
                'SCOPE_NOT_ALLOWED_FOR_ROLE',
                'sirket_ids'
            );
        }
        if (!OrgScope::isSgkScopeEligible($rol, $sgkIsverenIds)) {
            JsonResponse::badRequest(
                'Bu rol SGK kapsami alamaz.',
                'SCOPE_NOT_ALLOWED_FOR_ROLE',
                'sgk_isveren_ids'
            );
        }

        self::assertScopeIdsExist($pdo, 'sirketler', $sirketIds, 'sirket_ids', 'Gecersiz sirket secimi.');
        self::assertScopeIdsExist($pdo, 'sgk_isverenler', $sgkIsverenIds, 'sgk_isveren_ids', 'Gecersiz SGK isvereni secimi.');
    }

    /** @param array<int, int> $ids */
    private static function assertScopeIdsExist(PDO $pdo, $table, array $ids, $field, $message)
    {
        if (count($ids) === 0) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        if ((int) $stmt->fetchColumn() !== count($ids)) {
            JsonResponse::badRequest($message, 'VALIDATION_ERROR', $field);
        }
    }

    private static function assertSubeYoneticisiRoleSchemaReady(PDO $pdo, $rol)
    {
        if (strtoupper(trim((string) $rol)) !== 'SUBE_YONETICISI') {
            return;
        }
        if (!UserOrgAssignmentSchema::isSubeYoneticisiRoleReady($pdo)) {
            JsonResponse::error(
                409,
                'SCHEMA_NOT_READY',
                'SUBE_YONETICISI rol semasi hazir degil.',
                'rol'
            );
        }
    }

    /** @param array<int, int> $subeIds */
    private static function replaceUserSubeler(PDO $pdo, $userId, array $subeIds)
    {
        $delete = $pdo->prepare('DELETE FROM user_subeler WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);

        if (count($subeIds) === 0) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO user_subeler (user_id, sube_id) VALUES (:user_id, :sube_id)');
        foreach ($subeIds as $subeId) {
            $insert->execute([
                'user_id' => $userId,
                'sube_id' => $subeId,
            ]);
        }
    }

    /**
     * Role ↔ organizational assignment consistency (backend authoritative).
     *
     * @param array<int, int> $subeIds
     * @param array<int, int> $bolumIds
     * @param array<int, int> $birimIds
     * @param array<int, int> $sirketIds
     * @param array<int, int> $sgkIsverenIds
     */
    private static function assertRoleOrgAssignments(
        $rol,
        array $subeIds,
        array $bolumIds,
        array $birimIds,
        $personelId,
        $personelConsidered,
        array $sirketIds = [],
        array $sgkIsverenIds = []
    ) {
        $rol = strtoupper(trim((string) $rol));

        // IK_SORUMLUSU is deliberately absent: its reach is the whole
        // organisation and comes from the role, so demanding a branch grant would
        // both contradict the model and produce grants that narrow nothing.
        if ($rol === 'SUBE_YONETICISI') {
            if (count($subeIds) === 0) {
                JsonResponse::badRequest('Bu rol icin en az bir sube atamasi zorunludur.', 'VALIDATION_ERROR', 'sube_ids');
            }
        }

        // MUHASEBE may be branch-, company-, and/or SGK-scoped. Company grants
        // resolve to live branches per request; SGK grants are a separate payroll
        // axis. At least one of the three axes must be present (matches OrgScope).
        if ($rol === 'MUHASEBE') {
            if (count($subeIds) === 0 && count($sirketIds) === 0 && count($sgkIsverenIds) === 0) {
                JsonResponse::badRequest(
                    'Bu rol icin en az bir sube, sirket veya SGK kapsami zorunludur.',
                    'VALIDATION_ERROR',
                    'sube_ids'
                );
            }
        }

        // IK_PERSONELI reads everywhere but writes only in granted companies, so
        // an empty grant would be a role with no work it can do. Requiring the
        // selection here keeps the read-only case an explicit decision rather
        // than an accident of an unfinished form.
        if (HrWriteScope::requiresWriteCompanySelection($rol) && count($sirketIds) === 0) {
            JsonResponse::badRequest(
                'Bu rol icin en az bir islem sirketi secilmelidir.',
                'VALIDATION_ERROR',
                'sirket_ids'
            );
        }

        if ($rol === 'BOLUM_YONETICISI' && count($bolumIds) === 0) {
            JsonResponse::badRequest(
                'BOLUM_YONETICISI icin en az bir bolum atamasi zorunludur.',
                'VALIDATION_ERROR',
                'bolum_ids'
            );
        }

        if ($rol === 'BIRIM_AMIRI' && count($birimIds) === 0) {
            JsonResponse::badRequest(
                'BIRIM_AMIRI icin en az bir birim atamasi zorunludur.',
                'VALIDATION_ERROR',
                'birim_ids'
            );
        }

        if ($rol === 'PERSONEL' && $personelConsidered && ($personelId === null || (int) $personelId <= 0)) {
            JsonResponse::badRequest('PERSONEL rolu icin personel baglantisi zorunludur.', 'VALIDATION_ERROR', 'personel_id');
        }

        // Global roles may omit org assignments; do not require them.
        if (in_array($rol, OrgScope::GLOBAL_ROLES, true)) {
            return;
        }
    }

    /**
     * Varsayılan şube must sit inside effective branch visibility:
     * explicit user_subeler ∪ live branches of granted companies.
     *
     * @param array<int, int> $subeIds
     * @param array<int, int> $sirketIds
     */
    private static function assertVarsayilanSubeInEffectiveScope(
        PDO $pdo,
        $varsayilanSubeId,
        array $subeIds,
        array $sirketIds
    ) {
        if ($varsayilanSubeId === null) {
            return;
        }

        $effective = $subeIds;
        if (count($sirketIds) > 0 && UserOrgAssignmentSchema::isHierarchyScopeReady($pdo)) {
            $fromCompany = UserOrgAssignmentSchema::resolveSubeIdsForSirketIds($pdo, $sirketIds);
            $merged = array_merge($effective, $fromCompany);
            $effective = [];
            foreach ($merged as $id) {
                $value = (int) $id;
                if ($value > 0 && !in_array($value, $effective, true)) {
                    $effective[] = $value;
                }
            }
        }

        if (!in_array((int) $varsayilanSubeId, $effective, true)) {
            JsonResponse::badRequest(
                'Varsayilan sube yetki verilen subeler icinde olmalidir.',
                'VALIDATION_ERROR',
                'varsayilan_sube_id'
            );
        }
    }

    /** @param array<int, int> $bolumIds */
    private static function assertBolumIdsExist(PDO $pdo, array $bolumIds)
    {
        if (count($bolumIds) === 0) {
            return;
        }
        if (!UserOrgAssignmentSchema::isReady($pdo)) {
            JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Bolum atama semasi hazir degil.', 'bolum_ids');
        }
        $placeholders = implode(', ', array_fill(0, count($bolumIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM bolumler WHERE id IN ($placeholders)");
        $stmt->execute($bolumIds);
        $total = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($total !== count($bolumIds)) {
            JsonResponse::badRequest('Gecersiz bolum secimi.', 'VALIDATION_ERROR', 'bolum_ids');
        }
    }

    /** @param array<int, int> $birimIds */
    private static function assertBirimIdsExist(PDO $pdo, array $birimIds)
    {
        if (count($birimIds) === 0) {
            return;
        }
        if (!UserOrgAssignmentSchema::isReady($pdo)) {
            JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Birim atama semasi hazir degil.', 'birim_ids');
        }
        $placeholders = implode(', ', array_fill(0, count($birimIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM birimler WHERE id IN ($placeholders)");
        $stmt->execute($birimIds);
        $total = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($total !== count($birimIds)) {
            JsonResponse::badRequest('Gecersiz birim secimi.', 'VALIDATION_ERROR', 'birim_ids');
        }
    }

    /** @param array<int, int> $bolumIds */
    private static function replaceUserBolumler(PDO $pdo, $userId, array $bolumIds)
    {
        if (!UserOrgAssignmentSchema::isReady($pdo)) {
            if (count($bolumIds) > 0) {
                JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Bolum atama semasi hazir degil.', 'bolum_ids');
            }

            return;
        }
        $delete = $pdo->prepare('DELETE FROM user_bolumler WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);
        if (count($bolumIds) === 0) {
            return;
        }
        $insert = $pdo->prepare('INSERT INTO user_bolumler (user_id, bolum_id) VALUES (:user_id, :bolum_id)');
        foreach ($bolumIds as $bolumId) {
            $insert->execute(['user_id' => $userId, 'bolum_id' => $bolumId]);
        }
    }

    /** @param array<int, int> $birimIds */
    private static function replaceUserBirimler(PDO $pdo, $userId, array $birimIds)
    {
        if (!UserOrgAssignmentSchema::isReady($pdo)) {
            if (count($birimIds) > 0) {
                JsonResponse::error(409, 'SCHEMA_NOT_READY', 'Birim atama semasi hazir degil.', 'birim_ids');
            }

            return;
        }
        $delete = $pdo->prepare('DELETE FROM user_birimler WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);
        if (count($birimIds) === 0) {
            return;
        }
        $insert = $pdo->prepare('INSERT INTO user_birimler (user_id, birim_id) VALUES (:user_id, :birim_id)');
        foreach ($birimIds as $birimId) {
            $insert->execute(['user_id' => $userId, 'birim_id' => $birimId]);
        }
    }
}
