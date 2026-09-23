<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\HrWriteScope;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamSchema;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCompletenessService;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelGeciciGorevlendirmeService;
use Medisa\Api\Services\Personel\PersonelOperationalContextService;
use Medisa\Api\Services\Personel\PersonelIncompleteCreateService;
use Medisa\Api\Services\Personel\PersonelImportApplyService;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelImportHistoryService;
use Medisa\Api\Services\Personel\PersonelImportReferenceCatalogService;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Organizasyon\SubeReadModel;
use Medisa\Api\Services\Personel\PersonelExportService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkApplyService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkDryRunService;
use Medisa\Api\Services\Personel\PersonelOrganizasyonDegisikligiService;
use Medisa\Api\Services\Personel\PersonelKaliciSubeDegisikligiService;
use Medisa\Api\Services\Personel\PersonelOrgLocationSchema;
use Medisa\Api\Services\Personel\PersonelOrgStructureSchema;
use Medisa\Api\Services\Personel\PersonelSearchPredicate;
use Medisa\Api\Services\Personel\PersonelSicilAllocationException;
use Medisa\Api\Services\Personel\PersonelSicilAllocator;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\OfflineMutationIdempotencyService;
use Medisa\Api\Services\PersonelUcretException;
use Medisa\Api\Services\PersonelUcretService;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use PDO;

class PersonellerController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'personeller.view',
            'personeller.view.sube',
        ]);
        $scope = SubeScope::resolveScope($user, $request);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 10) ?: 10)));
        // Normalization, tokenization and case folding belong to PersonelSearchPredicate.
        $search = PersonelSearchPredicate::normalize($request->getQuery('search', ''));
        // Without arsiv.view: always AKTIF (blocks pasif|tum bypass).
        $aktiflik = PersonelArchiveGate::effectiveListAktiflik(
            $user,
            (string) $request->getQuery('aktiflik', 'tum')
        );
        $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
        $personelTipiId = (int) ($request->getQuery('personel_tipi_id', 0) ?: 0);
        // Fiili çalışma yeri filtresi (şube yetkisinden bağımsız daraltma).
        $calismaLokasyonuId = (int) ($request->getQuery('calisma_lokasyonu_id', 0) ?: 0);
        $calisanKapsami = strtoupper(trim((string) $request->getQuery('calisan_kapsami', '')));
        $eksikBilgiRaw = strtolower(trim((string) $request->getQuery('eksik_bilgi', '')));
        $eksikBilgiOnly = in_array($eksikBilgiRaw, ['1', 'true', 'yes', 'eksik', 'missing'], true);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $where = ['1=1'];
        $params = [];

        $role = OrgScope::normalizeRole($user);
        $unitScoped = in_array($role, OrgScope::BOLUM_ASSIGNMENT_ROLES, true)
            || in_array($role, OrgScope::BIRIM_ASSIGNMENT_ROLES, true);
        if ($unitScoped && !PersonelOrgStructureSchema::hasPersonelScopeColumns($pdo)) {
            // Old schemas cannot prove unit membership; fail closed without referencing missing columns.
            $where[] = '1=0';
        } else {
            OrgScope::appendPersonelOrgFilter($where, $params, $user, $scope, 'p', 'org', $pdo);
        }

        if ($aktiflik === 'aktif') {
            $where[] = "p.aktif_durum = 'AKTIF'";
        } elseif ($aktiflik === 'pasif') {
            $where[] = "p.aktif_durum = 'PASIF'";
        }

        if ($departmanId > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = $departmanId;
        }

        if ($personelTipiId > 0) {
            $where[] = 'p.personel_tipi_id = :personel_tipi_id';
            $params['personel_tipi_id'] = $personelTipiId;
        }

        // "Fabrikada kimler çalışıyor?" sorgusu: fiili çalışma yeri filtresi.
        // Bordro/SGK kaynağı (şirket) farklı olsa bile fiilen bu lokasyonda
        // çalışan yetki kapsamındaki tüm personel sonuçta görünür. Yetki
        // daraltması yukarıdaki OrgScope filtresinde kalır; bu filtre yalnız
        // sonucu daraltır, kapsamı genişletmez.
        if ($calismaLokasyonuId > 0) {
            if (!PersonelOrgLocationSchema::isReady($pdo)) {
                $where[] = '1=0';
            } else {
                $where[] = 'p.calisma_lokasyonu_id = :calisma_lokasyonu_id';
                $params['calisma_lokasyonu_id'] = $calismaLokasyonuId;
            }
        }

        if ($calisanKapsami === PersonelCalisanKapsamService::IC_PERSONEL
            || $calisanKapsami === PersonelCalisanKapsamService::DIS_KAYNAK
        ) {
            if (!PersonelCalisanKapsamSchema::isReady($pdo)) {
                if ($calisanKapsami === PersonelCalisanKapsamService::DIS_KAYNAK) {
                    $where[] = '1=0';
                }
            } else {
                $where[] = 'p.calisan_kapsami = :calisan_kapsami';
                $params['calisan_kapsami'] = $calisanKapsami;
            }
        }

        $includeOrgNames = PersonelOrgStructureSchema::hasPersonelScopeColumns($pdo);
        PersonelSearchPredicate::append($where, $params, $search, 'p', 'search', $pdo, $includeOrgNames);
        // TEST_FIXTURE personel is not a real employee: keep it out of the active/passive list,
        // its counts and every filter result through the canonical retention/archive owner.
        PersonelArchiveGate::appendOperationalExclusion($pdo, $where);

        $missingPredicate = PersonelCompletenessService::sqlHasMissingPredicate(
            'p',
            PersonelOrgStructureSchema::hasPersonelScopeColumns($pdo),
            PersonelCalisanKapsamSchema::isReady($pdo),
            PersonelOrgLocationSchema::isReady($pdo)
        );
        if ($eksikBilgiOnly) {
            $where[] = $missingPredicate;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // Aggregate of incompleteness within scoped base filters (before eksik_bilgi-only clamp).
        $missingCountWhere = $where;
        if ($eksikBilgiOnly) {
            array_pop($missingCountWhere);
        }
        $missingCountWhere[] = $missingPredicate;
        $missingCountSql = implode(' AND ', $missingCountWhere);
        $missingCountStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $missingCountSql");
        $missingCountStmt->execute($params);
        $missingPersonelTotal = (int) ($missingCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $offset = ($page - 1) * $limit;
        $select = self::personelSelectSql($pdo);

        $sortRaw = strtolower(trim((string) ($request->getQuery('sort', $request->getQuery('sort_by', '')) ?: '')));
        $dirRaw = strtolower(trim((string) ($request->getQuery('dir', $request->getQuery('sort_dir', 'asc')) ?: 'asc')));
        $dirSql = $dirRaw === 'desc' ? 'DESC' : 'ASC';
        $hasOrg = PersonelOrgStructureSchema::hasPersonelScopeColumns($pdo);
        $orderExpressions = [
            'ad' => "CONCAT_WS(' ', p.ad, p.soyad)",
            'sube' => 's.ad',
            'bolum' => $hasOrg ? "CONCAT_WS(' ', b.ad, bi.ad)" : 'd.ad',
            'gorev' => 'g.ad',
            'statu' => 'pt.ad',
        ];
        $orderExpr = $orderExpressions[$sortRaw] ?? 'p.id';
        $orderBySql = $orderExpr . ' ' . $dirSql . ', p.id ASC';

        $sql = "
            SELECT {$select['columns']}
            FROM personeller p
            {$select['joins']}
            WHERE $whereSql
            ORDER BY {$orderBySql}
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapPersonelRow($row, $user, false);
        }

        PersonelArchiveGate::maybeWriteListAudit($pdo, $user, $items, '/personeller');

        JsonResponse::success(
            ['items' => $items],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
                'missing_personel_total' => $missingPersonelTotal,
            ]
        );
    }

    /**
     * Narrow CLI read for the final-close preimage only.
     *
     * The personnel screen read (personelSelectSql + fetchPersonelRowById) joins the
     * display read models, so a screen-level dependency (departman/gorev/personel
     * tipi, SGK employer, derived branch name) could decide whether a bounded
     * production preflight may run. The canonical model stores no company on the
     * personnel row — a person's company derives from the branch — so this reader
     * takes the canonical personnel row itself (the invariant source) plus the
     * branch's company column under one explicit alias, in a single statement.
     * A branchless person keeps a NULL company: nothing is backfilled and no
     * read-model fallback runs.
     */
    public static function finalCloseRead(int $personelId): array
    {
        if (PHP_SAPI !== 'cli' || !in_array($personelId, \Medisa\Api\Services\Operations\FinalClosePackage::PERSONNEL, true)) {
            throw new \RuntimeException('FINAL_CLOSE_PERSONNEL_FORBIDDEN');
        }
        $pdo = Connection::get();
        try {
            // p.* keeps the whole canonical personnel row available to the invariant
            // hash; the alias keeps the joined company out of that row's namespace.
            $stmt = $pdo->prepare(
                'SELECT p.*, s.sirket_id AS final_close_sirket_id'
                . ' FROM personeller p'
                . ' LEFT JOIN subeler s ON s.id = p.sube_id'
                . ' WHERE p.id = :id'
                . ' LIMIT 1'
            );
            $stmt->execute(['id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $error) {
            // A canonical column that cannot be read is a bounded projection failure,
            // never a partially derived or guessed preimage.
            throw new \RuntimeException('FINAL_CLOSE_PERSONNEL_PROJECTION_INCOMPLETE');
        }
        if (!$row) {
            throw new \RuntimeException('FINAL_CLOSE_PERSONNEL_MISSING');
        }
        foreach (['id', 'ad', 'soyad', 'aktif_durum', 'sube_id', 'final_close_sirket_id', 'calisma_lokasyonu_id'] as $column) {
            if (!array_key_exists($column, $row)) {
                throw new \RuntimeException('FINAL_CLOSE_PERSONNEL_PROJECTION_INCOMPLETE');
            }
        }
        // Public projection: only the approved preimage fields leave this owner. The
        // company is the branch's canonical column and a NULL branch stays NULL.
        $result = [
            'id' => (int) $row['id'],
            'ad' => $row['ad'],
            'soyad' => $row['soyad'],
            'aktif_durum' => $row['aktif_durum'],
            'sube_id' => $row['sube_id'] === null ? null : (int) $row['sube_id'],
            'sirket_id' => $row['final_close_sirket_id'] === null ? null : (int) $row['final_close_sirket_id'],
            'calisma_lokasyonu_id' => $row['calisma_lokasyonu_id'] === null ? null : (int) $row['calisma_lokasyonu_id'],
        ];
        $result['invariant_hash'] = self::finalClosePersonnelInvariantHash($row);

        return $result;
    }

    /**
     * Canonical personnel invariant input: every column the personeller row owns,
     * minus the fields this package mutates. The branch join is not part of the
     * personnel invariant, so its alias is always dropped — the branch relation is
     * attested by the branch preimage owner, never by the personnel-row hash.
     *
     * @param array<string, mixed> $row canonical personnel row (p.*) with the join alias
     * @return array<string, mixed>
     */
    private static function finalClosePersonnelHashInput(array $row): array
    {
        unset($row['final_close_sirket_id']);
        // Hash only: unrelated identity/contact/salary data never leaves this owner.
        foreach (['ad', 'soyad', 'calisma_lokasyonu_id', 'calisma_lokasyonu_adi', 'updated_at'] as $key) {
            unset($row[$key]);
        }
        ksort($row);

        return $row;
    }

    /** @param array<string, mixed> $row canonical personnel row (p.*) with the join alias */
    private static function finalClosePersonnelInvariantHash(array $row): string
    {
        return hash('sha256', json_encode(self::finalClosePersonnelHashInput($row), JSON_THROW_ON_ERROR));
    }

    public static function detail(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.detail.view');
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $columns = PersonelOrgStructureSchema::personelScopeProjection($pdo);
        $stmt = $pdo->prepare("SELECT {$columns}, aktif_durum FROM personeller WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $personelId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            JsonResponse::notFound();
        }

        // Normal personel detail access: a TEST_FIXTURE personel is not a user-facing record.
        // Retention/audit owners read the row through their own owners, not through this surface.
        if (PersonelArchiveGate::isOperationallyHidden($pdo, $personelId)) {
            JsonResponse::notFound();
        }

        PersonelArchiveGate::assertDetailAccess($user, $exists);
        SubeScope::assertPersonelAccess($user, $request, $exists, $pdo);

        $select = self::personelSelectSql($pdo);
        $sql = "
            SELECT {$select['columns']}
            FROM personeller p
            {$select['joins']}
            WHERE p.id = :id
            LIMIT 1
        ";
        $detailStmt = $pdo->prepare($sql);
        $detailStmt->execute(['id' => $personelId]);
        $row = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::notFound();
        }

        PersonelArchiveGate::writeViewAuditIfPasif($pdo, $user, $row, '/personeller/{id}');
        $mapped = self::mapPersonelRow($row, $user);
        if (strtoupper((string) $row['aktif_durum']) === 'PASIF') {
            $markers = PersonelArchiveGate::buildArchiveMarkers($pdo, $row);
            $mapped['arsiv_modu'] = true;
            $mapped['policy_note'] = $markers['policy_note'];
            $mapped['retention_summary'] = $markers['retention_summary'];
            $mapped['legal_hold_active'] = $markers['legal_hold_active'];
            $mapped['read_only_archive'] = true;
        }

        JsonResponse::success($mapped);
    }

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user, 'personeller.create');

        $body = $request->getJsonBody();
        $hasSalary = self::hasSalaryField($body);
        if ($hasSalary && !RolePermissions::has($user, 'personeller.ucret.manage')) {
            JsonResponse::error(403, 'SALARY_ACCESS_FORBIDDEN', 'Ucret bilgisi yonetme yetkiniz yok.');
        }
        $incompleteIntent = PersonelIncompleteCreateService::hasIntent($body);
        if ($incompleteIntent) {
            try {
                PersonelIncompleteCreateService::assertAuthorized($user);
            } catch (PersonelValidationException $e) {
                JsonResponse::error(403, $e->getCodeString(), $e->getMessage(), $e->getField());
            }
        }
        try {
            $payload = $incompleteIntent
                ? PersonelIncompleteCreateService::normalizePayload($body)
                : PersonelCanonicalValidator::normalizeAndValidateCreatePayload($body);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(422, $e->getCodeString(), $e->getMessage(), $e->getField());
        }
        if ($hasSalary && ($payload['maas_tutari'] === null || (float) $payload['maas_tutari'] <= 0)) {
            JsonResponse::error(400, 'SALARY_AMOUNT_INVALID', 'Ücret tutarı sıfırdan büyük olmalıdır.', 'maas_tutari');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        self::assertCreateSubeScope($user, $request, $payload['sube_id']);
        if (PersonelOrgLocationSchema::payloadRequestsOrgFields($payload)
            && !PersonelOrgLocationSchema::isReady($pdo)
        ) {
            JsonResponse::error(
                409,
                PersonelOrgLocationSchema::ERROR_CODE,
                'Org location schema hazir degil; sgk_isveren_id / calisma_lokasyonu_id yazilamaz.'
            );
        }
        if (PersonelOrgStructureSchema::payloadRequestsOrgStructureFields($payload)
            && !PersonelOrgStructureSchema::isReady($pdo)
        ) {
            JsonResponse::error(
                409,
                PersonelOrgStructureSchema::ERROR_CODE,
                'Org structure schema hazir degil; bolum_id / birim_id / pozisyon_id yazilamaz.'
            );
        }
        try {
            PersonelCalisanKapsamSchema::assertReadyForDisKaynakWrite($pdo, $payload);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(409, $e->getCodeString(), $e->getMessage(), $e->getField());
        }
        if (($payload['calisan_kapsami'] ?? PersonelCalisanKapsamService::IC_PERSONEL)
            === PersonelCalisanKapsamService::DIS_KAYNAK
            && $hasSalary
        ) {
            JsonResponse::error(
                409,
                PersonelCalisanKapsamService::ERROR_OPERASYON,
                'DIS_KAYNAK personeline maas/bordro kaydi olusturulamaz.',
                'calisan_kapsami'
            );
        }
        try {
            PersonelCreateService::validateCreateReferences($pdo, $payload);
        } catch (PersonelValidationException $e) {
            $code = $e->getCodeString();
            $status = (
                $code === PersonelOrgLocationSchema::ERROR_CODE
                || $code === PersonelOrgStructureSchema::ERROR_CODE
                || $code === PersonelCalisanKapsamSchema::ERROR_CODE
            ) ? 409 : 422;
            JsonResponse::error($status, $code, $e->getMessage(), $e->getField());
        }
        self::assertTcAvailable($pdo, $payload['tc_kimlik_no'] ?? null);
        if (!PersonelSicilAllocator::isAutoRequest($payload['sicil_no'] ?? null)) {
            self::assertSicilAvailable($pdo, (string) $payload['sicil_no']);
        }

        $actorId = (int) ($user['id'] ?? 0);
        $idemKey = OfflineMutationIdempotencyService::readKey($request);
        $idemScope = 'personeller.create';
        $idemHash = OfflineMutationIdempotencyService::hashPayload([
            'op' => $idemScope,
            'payload' => $payload,
        ]);
        if ($idemKey !== null) {
            $replay = OfflineMutationIdempotencyService::findCompletedReplay(
                $pdo,
                $actorId,
                $idemScope,
                $idemKey,
                $idemHash
            );
            if (is_array($replay) && !empty($replay['result_entity_id'])) {
                $existing = self::fetchPersonelRowById($pdo, (int) $replay['result_entity_id']);
                if ($existing) {
                    JsonResponse::success(self::mapPersonelRow($existing, $user), [], (int) ($replay['http_status'] ?? 201));
                }
            }
        }

        $pdo->beginTransaction();
        try {
            if ($idemKey !== null) {
                $claimedReplay = OfflineMutationIdempotencyService::claimInTransaction(
                    $pdo,
                    $actorId,
                    $idemScope,
                    $idemKey,
                    $idemHash
                );
                if (is_array($claimedReplay) && !empty($claimedReplay['result_entity_id'])) {
                    $pdo->commit();
                    $existing = self::fetchPersonelRowById($pdo, (int) $claimedReplay['result_entity_id']);
                    if ($existing) {
                        JsonResponse::success(
                            self::mapPersonelRow($existing, $user),
                            [],
                            (int) ($claimedReplay['http_status'] ?? 201)
                        );
                    }
                    JsonResponse::serverError('Idempotent replay sonucu yuklenemedi.');
                }
            }

            $insertId = PersonelCreateService::insertPersonel($pdo, $payload);
            if ($hasSalary && $payload['maas_tutari'] !== null) {
                PersonelUcretService::createSalaryRecord($pdo, $insertId, [
                    'ucret_tutari' => $payload['maas_tutari'],
                    'ucret_turu' => 'NET',
                    'para_birimi' => 'TRY',
                    'gecerlilik_baslangic' => $payload['ise_giris_tarihi'],
                    'kaynak' => 'MANUEL',
                ], $user);
            }
            $row = self::fetchPersonelRowById($pdo, $insertId);
            if (!$row) {
                $pdo->rollBack();
                JsonResponse::serverError('Kayit olusturulamadi.');
            }

            if ($idemKey !== null) {
                OfflineMutationIdempotencyService::completeInTransaction(
                    $pdo,
                    $actorId,
                    $idemScope,
                    $idemKey,
                    201,
                    'personel',
                    $insertId,
                    null
                );
            }

            $pdo->commit();
            JsonResponse::success(self::mapPersonelRow($row, $user), [], 201);
        } catch (PersonelSicilAllocationException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error(409, $e->getCodeString(), $e->getMessage(), $e->getField());
        } catch (PersonelUcretException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (PersonelCreateService::isDuplicateTcException($e) || self::isDuplicateTcException($e)) {
                self::duplicateTcResponse();
            }
            if (PersonelCreateService::isDuplicateSicilException($e) || self::isDuplicateSicilException($e)) {
                self::duplicateSicilResponse();
            }

            JsonResponse::serverError('Kayit olusturulamadi.');
        }
    }

    public static function update(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user, 'personeller.update');

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        $body = $request->getJsonBody();
        $hasSalary = self::hasSalaryField($body);
        if ($hasSalary && !RolePermissions::has($user, 'personeller.ucret.manage')) {
            JsonResponse::error(403, 'SALARY_ACCESS_FORBIDDEN', 'Ucret bilgisi yonetme yetkiniz yok.');
        }
        try {
            $payload = PersonelCanonicalValidator::normalizeAndValidateUpdatePayload($body);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(422, $e->getCodeString(), $e->getMessage(), $e->getField());
        }
        if ($hasSalary && (!array_key_exists('maas_tutari', $payload) || $payload['maas_tutari'] === null || (float) $payload['maas_tutari'] <= 0)) {
            JsonResponse::error(400, 'SALARY_AMOUNT_INVALID', 'Ücret tutarı sıfırdan büyük olmalıdır.', 'maas_tutari');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $current = self::fetchPersonelRowById($pdo, $personelId);
        if (!$current) {
            JsonResponse::notFound();
        }

        PersonelArchiveGate::assertBusinessWriteAllowed($pdo, $personelId);
        self::assertUpdateSubeScope($user, $request, $current, $payload);
        self::assertAktifDurumNotChanged($current, $payload);
        try {
            PersonelOrganizasyonDegisikligiService::assertNotChangedViaGenericPut($current, $payload);
        } catch (OrganizasyonException $e) {
            JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
        }
        // Fail-closed: even same-value tracked org / sube keys never write via generic PUT.
        $payload = PersonelOrganizasyonDegisikligiService::stripProtectedOrgFieldsFromGenericPut($payload);
        if (PersonelOrgLocationSchema::payloadRequestsOrgFields($payload)
            && !PersonelOrgLocationSchema::isReady($pdo)
        ) {
            JsonResponse::error(
                409,
                PersonelOrgLocationSchema::ERROR_CODE,
                'Org location schema hazir degil; sgk_isveren_id / calisma_lokasyonu_id yazilamaz.'
            );
        }
        if (PersonelOrgStructureSchema::payloadRequestsOrgStructureFields($payload)
            && !PersonelOrgStructureSchema::isReady($pdo)
        ) {
            JsonResponse::error(
                409,
                PersonelOrgStructureSchema::ERROR_CODE,
                'Org structure schema hazir degil; bolum_id / birim_id / pozisyon_id yazilamaz.'
            );
        }
        self::validateUpdateReferences($pdo, $payload, $current);

        $resultingKapsam = array_key_exists('calisan_kapsami', $payload)
            ? (string) $payload['calisan_kapsami']
            : PersonelCalisanKapsamService::resolveFromRow($current);
        try {
            PersonelCalisanKapsamSchema::assertReadyForDisKaynakWrite($pdo, [
                'calisan_kapsami' => $resultingKapsam,
            ]);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(409, $e->getCodeString(), $e->getMessage(), $e->getField());
        }
        $resultingTc = array_key_exists('tc_kimlik_no', $payload)
            ? $payload['tc_kimlik_no']
            : ($current['tc_kimlik_no'] ?? null);
        if ($resultingKapsam === PersonelCalisanKapsamService::IC_PERSONEL) {
            try {
                PersonelCalisanKapsamService::assertInternalIdentityComplete([
                    'tc_kimlik_no' => $resultingTc,
                    'soyad' => array_key_exists('soyad', $payload) ? $payload['soyad'] : ($current['soyad'] ?? null),
                    'dogum_tarihi' => array_key_exists('dogum_tarihi', $payload)
                        ? $payload['dogum_tarihi']
                        : ($current['dogum_tarihi'] ?? null),
                    'telefon' => array_key_exists('telefon', $payload) ? $payload['telefon'] : ($current['telefon'] ?? null),
                ]);
            } catch (PersonelValidationException $e) {
                JsonResponse::error(422, $e->getCodeString(), $e->getMessage(), $e->getField());
            }
        }
        if ($resultingKapsam === PersonelCalisanKapsamService::DIS_KAYNAK) {
            // SGK/bordro kaynağı fiili organizasyon şubesinden bağımsızdır: DIS
            // personelde başka şirketin AKTİF SGK işvereni geçerlidir, bu yüzden
            // değer burada sıfırlanmaz. Gerçek bordro/maaş üretimi hâlâ kapalıdır.
            if ($hasSalary) {
                JsonResponse::error(
                    409,
                    PersonelCalisanKapsamService::ERROR_OPERASYON,
                    'DIS_KAYNAK personeline maas/bordro kaydi olusturulamaz.',
                    'calisan_kapsami'
                );
            }
        }

        if (array_key_exists('tc_kimlik_no', $payload) && $payload['tc_kimlik_no'] !== null && $payload['tc_kimlik_no'] !== '') {
            self::assertTcAvailableForUpdate($pdo, $payload['tc_kimlik_no'], $personelId);
        }
        if (array_key_exists('sicil_no', $payload)) {
            self::assertSicilAvailable($pdo, (string) $payload['sicil_no'], $personelId);
        }

        $salaryChanged = $hasSalary
            && array_key_exists('maas_tutari', $payload)
            && (float) $payload['maas_tutari'] !== (float) ($current['maas_tutari'] ?? 0);
        $salaryAmount = $payload['maas_tutari'] ?? null;
        unset($payload['maas_tutari']);

        $actorId = (int) ($user['id'] ?? 0);
        $idemKey = OfflineMutationIdempotencyService::readKey($request);
        $idemScope = 'personeller.update:' . $personelId;
        $idemHash = OfflineMutationIdempotencyService::hashPayload([
            'op' => $idemScope,
            'personel_id' => $personelId,
            'payload' => $payload,
            'salary' => $salaryChanged ? $salaryAmount : null,
        ]);
        if ($idemKey !== null) {
            $replay = OfflineMutationIdempotencyService::findCompletedReplay(
                $pdo,
                $actorId,
                $idemScope,
                $idemKey,
                $idemHash
            );
            if (is_array($replay)) {
                $existing = self::fetchPersonelRowById($pdo, $personelId);
                if ($existing) {
                    JsonResponse::success(self::mapPersonelRow($existing, $user), [], (int) ($replay['http_status'] ?? 200));
                }
            }
        }

        $pdo->beginTransaction();
        try {
            if ($idemKey !== null) {
                $claimedReplay = OfflineMutationIdempotencyService::claimInTransaction(
                    $pdo,
                    $actorId,
                    $idemScope,
                    $idemKey,
                    $idemHash
                );
                if (is_array($claimedReplay)) {
                    $pdo->commit();
                    $existing = self::fetchPersonelRowById($pdo, $personelId);
                    if ($existing) {
                        JsonResponse::success(
                            self::mapPersonelRow($existing, $user),
                            [],
                            (int) ($claimedReplay['http_status'] ?? 200)
                        );
                    }
                }
            }

            self::updatePersonelRow($pdo, $personelId, $payload);
            if ($salaryChanged) {
                PersonelUcretService::createSalaryRecord($pdo, $personelId, [
                    'ucret_tutari' => $salaryAmount,
                    'ucret_turu' => 'NET',
                    'para_birimi' => 'TRY',
                    'gecerlilik_baslangic' => isset($body['effective_date']) && trim((string) $body['effective_date']) !== ''
                        ? trim((string) $body['effective_date'])
                        : date('Y-m-d'),
                    'kaynak' => 'MANUEL',
                ], $user);
            }
            $row = self::fetchPersonelRowById($pdo, $personelId);
            if (!$row) {
                $pdo->rollBack();
                JsonResponse::serverError('Kayit guncellenemedi.');
            }

            if ($idemKey !== null) {
                OfflineMutationIdempotencyService::completeInTransaction(
                    $pdo,
                    $actorId,
                    $idemScope,
                    $idemKey,
                    200,
                    'personel',
                    $personelId,
                    null
                );
            }

            $pdo->commit();
            JsonResponse::success(self::mapPersonelRow($row, $user));
        } catch (PersonelUcretException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (self::isDuplicateTcException($e)) {
                self::duplicateTcResponse();
            }
            if (PersonelCreateService::isDuplicateSicilException($e) || self::isDuplicateSicilException($e)) {
                self::duplicateSicilResponse();
            }

            JsonResponse::serverError('Kayit guncellenemedi.');
        }
    }

    public static function exportXlsx(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, ['personeller.view', 'personeller.view.sube']);

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $activeSube = SubeScope::resolveScope($user, $request);
        $deploySha = self::resolveDeploySha();

        try {
            $result = PersonelExportService::buildWorkbook($pdo, $user, $activeSube, $deploySha);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'PERSONEL_EXPORT_RECONCILE_FAILED') !== false) {
                JsonResponse::error(409, 'PERSONEL_EXPORT_RECONCILE_FAILED', 'Export satir sayisi dogrulanamadi.');
            }
            JsonResponse::serverError('Personel export uretilemedi.');
        }

        $filename = 'personel-export-' . gmdate('Y-m-d') . '.xlsx';
        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Personel-Export-Row-Count: ' . (int) ($result['meta']['row_count'] ?? 0));
            header('X-Personel-Export-Scope: ' . (string) ($result['meta']['filter_scope'] ?? ''));
            http_response_code(200);
        }
        echo $result['binary'];
        exit;
    }

    public static function lifecycleBulkDryRun(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, ['personeller.create', 'personeller.update']);

        $body = $request->getJsonBody();
        $rows = $body['rows'] ?? null;
        if (!is_array($rows)) {
            JsonResponse::error(422, 'ROWS_REQUIRED', 'Lifecycle dry-run icin rows dizisi zorunludur.');
        }
        $deployedSha = trim((string) ($body['deployed_sha'] ?? ''));

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $activeSube = SubeScope::resolveScope($user, $request);
        try {
            $result = PersonelLifecycleBulkDryRunService::dryRun(
                $pdo,
                $user,
                $request,
                $rows,
                $activeSube,
                $deployedSha !== '' ? $deployedSha : null
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        JsonResponse::success($result);
    }

    public static function lifecycleBulkApply(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        $body = $request->getJsonBody();
        $rows = $body['rows'] ?? null;
        if (!is_array($rows)) {
            JsonResponse::error(422, 'ROWS_REQUIRED', 'Lifecycle apply icin rows dizisi zorunludur.');
        }
        $dryRunChecksum = trim((string) ($body['dry_run_checksum'] ?? ''));
        $preimageChecksum = trim((string) ($body['preimage_checksum'] ?? ''));
        $deployedSha = trim((string) ($body['deployed_sha'] ?? ''));
        if ($dryRunChecksum === '' || $preimageChecksum === '') {
            JsonResponse::error(422, 'CHECKSUM_REQUIRED', 'Dry-run checksum ve preimage checksum zorunludur.');
        }
        if ($deployedSha === '' || !preg_match('/^[a-f0-9]{40}$/i', $deployedSha)) {
            JsonResponse::error(422, 'DEPLOYED_SHA_REQUIRED', 'deployed_sha zorunludur.');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $activeSube = SubeScope::resolveScope($user, $request);
        try {
            $result = PersonelLifecycleBulkApplyService::apply(
                $pdo,
                $user,
                $request,
                $rows,
                $dryRunChecksum,
                $preimageChecksum,
                $deployedSha,
                $activeSube
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        JsonResponse::success($result);
    }

    public static function organizasyonDegisikligi(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::assertWriteRole($user, 'personeller.update');

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        PersonelArchiveGate::assertBusinessWriteAllowed($pdo, $personelId);
        $body = $request->getJsonBody();
        $idemKey = OfflineMutationIdempotencyService::readKey($request);
        $auditContext = OrganizasyonAuditContext::fromRequest($request, $user, $idemKey);

        try {
            $result = PersonelOrganizasyonDegisikligiService::apply(
                $pdo,
                $user,
                $request,
                $personelId,
                $body,
                $auditContext
            );
        } catch (OrganizasyonException $e) {
            JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
        }

        $row = self::fetchPersonelRowById($pdo, $personelId);
        if (!$row) {
            JsonResponse::notFound();
        }

        JsonResponse::success([
            'organizasyon' => $result,
            'personel' => self::mapPersonelRow($row, $user),
        ]);
    }

    private static function resolveDeploySha()
    {
        $fromEnv = getenv('MEDISA_DEPLOY_SHA');
        if (is_string($fromEnv) && preg_match('/^[a-f0-9]{40}$/i', trim($fromEnv))) {
            return strtolower(trim($fromEnv));
        }
        $path = getenv('MEDISA_DEPLOY_SHA_PATH');
        if (is_string($path) && is_readable($path)) {
            $raw = trim((string) file_get_contents($path));
            if (preg_match('/^[a-f0-9]{40}$/i', $raw)) {
                return strtolower($raw);
            }
        }

        return 'unknown';
    }

    public static function importTemplate(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.create');

        $csv = PersonelImportDryRunService::buildTemplateCsv();
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="personel-import-sablon.csv"');
            http_response_code(200);
        }
        echo $csv;
        exit;
    }

    public static function importReferencesCsv(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.create');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $activeSube = $request->getHeader('x-active-sube-id');

        try {
            $result = PersonelImportReferenceCatalogService::buildExport($pdo, $user, $activeSube);
        } catch (PersonelImportException $e) {
            $message = $e->getMessage();
            if (preg_match('/SQLSTATE|stack|trace|mysqli|PDO/i', $message)) {
                $message = 'Personel import referans paketi hazirlanamadi.';
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $message);
        }

        $csv = (string) $result['csv'];
        if (
            preg_match('/\btc_kimlik_no\b/i', $csv)
            || preg_match('/idempotency_key/i', $csv)
            || preg_match('/\d{11}/', $csv)
        ) {
            JsonResponse::serverError('Personel import referans response scrub hatasi.');
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header(
                'Content-Disposition: attachment; filename="'
                . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $result['filename'])
                . '"'
            );
            header(PersonelImportReferenceCatalogService::SHA_HEADER . ': ' . $result['sha256']);
            header('ETag: "' . $result['sha256'] . '"');
            http_response_code(200);
        }
        echo $csv;
        exit;
    }

    public static function importDryRun(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.create');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $csvContent = self::readImportCsvContent($request);
        $activeSube = $request->getHeader('x-active-sube-id');

        try {
            $result = PersonelImportDryRunService::dryRun($pdo, $csvContent, $user, $activeSube);
        } catch (PersonelImportException $e) {
            $message = $e->getMessage();
            if (preg_match('/\d{11}/', $message)) {
                $message = 'Personel import dogrulama hatasi.';
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $message);
        }

        JsonResponse::success($result);
    }

    public static function importApply(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $csvContent = self::readImportCsvContent($request);
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        // Multipart form fields may carry apply metadata alongside file upload.
        foreach (['manifest_hash', 'idempotency_key', 'confirmation', 'onay'] as $field) {
            if ((!isset($body[$field]) || $body[$field] === '') && isset($_POST[$field])) {
                $body[$field] = $_POST[$field];
            }
        }
        $activeSube = $request->getHeader('x-active-sube-id');

        try {
            $result = PersonelImportApplyService::apply($pdo, $csvContent, $user, $body, $activeSube);
        } catch (PersonelImportException $e) {
            $message = $e->getMessage();
            if (preg_match('/\d{11}/', $message)) {
                $message = 'Personel import apply hatasi.';
            }
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $message);
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && preg_match('/"tc_kimlik_no"\s*:/', $encoded)) {
            JsonResponse::serverError('Personel import response scrub hatasi.');
        }

        JsonResponse::success($result, [], 201);
    }

    public static function importRunsList(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);
        $query = [
            'cursor' => $request->getQuery('cursor'),
            'limit' => $request->getQuery('limit'),
            'status' => $request->getQuery('status'),
            'date_from' => $request->getQuery('date_from'),
            'date_to' => $request->getQuery('date_to'),
        ];

        try {
            $result = PersonelImportHistoryService::listRuns(
                $pdo,
                $user,
                $query,
                $scope,
                $allowedSubeIds
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && (
            preg_match('/"tc_kimlik_no"\s*:/', $encoded)
            || preg_match('/\btc_sha256\b/', $encoded)
            || preg_match('/"idempotency_key"\s*:/', $encoded)
        )) {
            JsonResponse::serverError('Personel import history response scrub hatasi.');
        }

        JsonResponse::success(
            ['items' => $result['items']],
            ['next_cursor' => $result['next_cursor']]
        );
    }

    public static function importRunDetail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        try {
            $result = PersonelImportHistoryService::getRun(
                $pdo,
                $user,
                $id,
                $scope,
                $allowedSubeIds
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && (
            preg_match('/"tc_kimlik_no"\s*:/', $encoded)
            || preg_match('/\btc_sha256\b/', $encoded)
            || preg_match('/"idempotency_key"\s*:/', $encoded)
        )) {
            JsonResponse::serverError('Personel import history response scrub hatasi.');
        }

        JsonResponse::success($result);
    }

    public static function importRunEvidenceCsv(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.import.apply');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $scope = SubeScope::resolveScope($user, $request);
        $allowedSubeIds = SubeScope::allowedSubeIds($user);

        try {
            $result = PersonelImportHistoryService::buildEvidenceCsv(
                $pdo,
                $user,
                $id,
                $scope,
                $allowedSubeIds
            );
        } catch (PersonelImportException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header(
                'Content-Disposition: attachment; filename="'
                . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $result['filename'])
                . '"'
            );
            http_response_code(200);
        }
        echo $result['csv'];
        exit;
    }

    /** @return string */
    private static function readImportCsvContent(Request $request)
    {
        if (isset($_FILES['file']) && is_array($_FILES['file'])) {
            $file = $_FILES['file'];
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi yuklenemedi.');
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size > PersonelImportDryRunService::MAX_BYTES) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_BOYUTU', 'CSV dosyasi en fazla 2 MB olabilir.');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi yuklenemedi.');
            }
            $content = file_get_contents($tmp);
            if ($content === false) {
                JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi okunamadi.');
            }

            return $content;
        }

        $body = $request->getJsonBody();
        if (isset($body['csv']) && is_string($body['csv'])) {
            return $body['csv'];
        }
        if (isset($body['csv_text']) && is_string($body['csv_text'])) {
            return $body['csv_text'];
        }

        $contentType = strtolower((string) $request->getHeader('content-type', ''));
        if (strpos($contentType, 'text/csv') !== false || strpos($contentType, 'text/plain') !== false) {
            return $request->getRawBody();
        }

        JsonResponse::error(400, 'PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV dosyasi veya csv alani zorunludur.');
    }

    /**
     * Personnel write gate.
     *
     * The permission matrix is the primary answer, so a role that holds
     * personeller.create/update — such as the İK roles — is no longer excluded by
     * a role list that predates the matrix. The historical role list is kept as
     * an additional allowance so no role that could write before this pack loses
     * access here.
     *
     * @param array<string, mixed> $user
     * @param string $permission
     */
    private static function assertWriteRole(array $user, $permission)
    {
        if (RolePermissions::has($user, $permission)) {
            return;
        }

        $legacyRoles = ['GENEL_YONETICI', 'BOLUM_YONETICISI', 'MUHASEBE'];
        if (in_array(OrgScope::normalizeRole($user), $legacyRoles, true)) {
            return;
        }

        JsonResponse::forbidden();
    }

    /** @param array<string, mixed> $user */
    private static function assertCreateSubeScope(array $user, Request $request, $subeId)
    {
        // Unassigned DIS_KAYNAK (sube_id NULL) is a legitimate central pool record
        // (migration 076: no placeholder branch). Only unrestricted roles may create it,
        // and the active-branch header must not be compared against a missing branch.
        if ($subeId === null || (int) $subeId <= 0) {
            if (!OrgScope::isUnrestricted($user)) {
                JsonResponse::forbidden('Sube atanmamis personel olusturma yetkiniz yok.');
            }

            return;
        }

        $subeId = (int) $subeId;
        $headerSube = self::parseHeaderPositiveInt($request->getHeader('x-active-sube-id'));
        if ($headerSube !== null && $headerSube !== $subeId) {
            JsonResponse::forbidden();
        }

        // İK reaches every branch by role, so a legacy explicit grant must not
        // narrow the target branch. Whether it may actually be written is the
        // separate company-write question.
        if (OrgScope::isOrganizationGlobalRead($user)) {
            HrWriteScope::assertSubeWritable($user, $subeId);

            return;
        }

        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0) {
            return;
        }

        if (!in_array($subeId, $allowed, true)) {
            JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $current
     * @param array<string, mixed> $payload
     */
    private static function assertUpdateSubeScope(array $user, Request $request, array $current, array $payload)
    {
        $currentSubeId = (int) $current['sube_id'];
        SubeScope::assertPersonelAccess($user, $request, $current);

        if (!array_key_exists('sube_id', $payload)) {
            return;
        }

        $targetSubeId = (int) $payload['sube_id'];
        self::assertCreateSubeScope($user, $request, $targetSubeId);

        if ($targetSubeId !== $currentSubeId) {
            JsonResponse::forbidden();
        }
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $payload */
    private static function assertAktifDurumNotChanged(array $current, array $payload)
    {
        if (!array_key_exists('aktif_durum', $payload)) {
            return;
        }

        if ((string) $payload['aktif_durum'] !== (string) $current['aktif_durum']) {
            self::validationError('aktif_durum', 'Aktif durum bu endpoint ile degistirilemez.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateCreateReferences(PDO $pdo, array $payload)
    {
        if (!self::existsActiveRecord($pdo, 'subeler', (int) $payload['sube_id'])) {
            self::validationError('sube_id', 'Gecersiz sube.');
        }
        if (!self::existsActiveRecord($pdo, 'departmanlar', (int) $payload['departman_id'])) {
            self::validationError('departman_id', 'Gecersiz departman.');
        }
        if (!self::existsActiveRecord($pdo, 'gorevler', (int) $payload['gorev_id'])) {
            self::validationError('gorev_id', 'Gecersiz gorev.');
        }
        if (!self::existsActiveRecord($pdo, 'personel_tipleri', (int) $payload['personel_tipi_id'])) {
            self::validationError('personel_tipi_id', 'Gecersiz personel tipi.');
        }

        $bagliAmirId = $payload['bagli_amir_id'];
        if ($bagliAmirId !== null) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => (int) $bagliAmirId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                self::validationError('bagli_amir_id', 'Gecersiz bagli amir.');
            }
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $current */
    private static function validateUpdateReferences(PDO $pdo, array $payload, array $current = [])
    {
        if (array_key_exists('sube_id', $payload) && !self::existsActiveRecord($pdo, 'subeler', (int) $payload['sube_id'])) {
            self::validationError('sube_id', 'Gecersiz sube.');
        }
        if (array_key_exists('departman_id', $payload) && $payload['departman_id'] !== null && !self::existsActiveRecord($pdo, 'departmanlar', (int) $payload['departman_id'])) {
            self::validationError('departman_id', 'Gecersiz departman.');
        }
        if (array_key_exists('gorev_id', $payload) && $payload['gorev_id'] !== null && !self::existsActiveRecord($pdo, 'gorevler', (int) $payload['gorev_id'])) {
            self::validationError('gorev_id', 'Gecersiz gorev.');
        }
        if (array_key_exists('personel_tipi_id', $payload) && $payload['personel_tipi_id'] !== null && !self::existsActiveRecord($pdo, 'personel_tipleri', (int) $payload['personel_tipi_id'])) {
            self::validationError('personel_tipi_id', 'Gecersiz personel tipi.');
        }

        if (array_key_exists('sgk_isveren_id', $payload) && $payload['sgk_isveren_id'] !== null) {
            if (!PersonelOrgLocationSchema::existsActiveSgkIsveren($pdo, (int) $payload['sgk_isveren_id'])) {
                self::validationError('sgk_isveren_id', 'Gecersiz SGK isveren.');
            }
        }
        if (array_key_exists('calisma_lokasyonu_id', $payload) && $payload['calisma_lokasyonu_id'] !== null) {
            if (!PersonelOrgLocationSchema::existsActiveCalismaLokasyonu($pdo, (int) $payload['calisma_lokasyonu_id'])) {
                self::validationError('calisma_lokasyonu_id', 'Gecersiz calisma lokasyonu.');
            }
        }

        if (array_key_exists('bolum_id', $payload) && $payload['bolum_id'] !== null) {
            if (!PersonelOrgStructureSchema::existsActiveBolum($pdo, (int) $payload['bolum_id'])) {
                self::validationError('bolum_id', 'Gecersiz bolum.');
            }
        }
        if (array_key_exists('birim_id', $payload) && $payload['birim_id'] !== null) {
            if (!PersonelOrgStructureSchema::existsActiveBirim($pdo, (int) $payload['birim_id'])) {
                self::validationError('birim_id', 'Gecersiz birim.');
            }
        }
        if (array_key_exists('pozisyon_id', $payload) && $payload['pozisyon_id'] !== null) {
            if (!PersonelOrgStructureSchema::existsActivePozisyon($pdo, (int) $payload['pozisyon_id'])) {
                self::validationError('pozisyon_id', 'Gecersiz pozisyon.');
            }
        }

        if (PersonelOrgStructureSchema::isReady($pdo)
            && (
                PersonelOrgStructureSchema::payloadRequestsOrgStructureFields($payload)
                || array_key_exists('departman_id', $payload)
            )
        ) {
            try {
                PersonelOrgStructureSchema::assertHierarchyConsistent(
                    $pdo,
                    PersonelOrgStructureSchema::mergeEffectiveOrgState($current, $payload)
                );
            } catch (PersonelValidationException $e) {
                JsonResponse::error(422, $e->getCodeString(), $e->getMessage(), $e->getField());
            }
        }

        if (array_key_exists('bagli_amir_id', $payload) && $payload['bagli_amir_id'] !== null) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
            $stmt->execute(['id' => (int) $payload['bagli_amir_id']]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                self::validationError('bagli_amir_id', 'Gecersiz bagli amir.');
            }
        }
    }

    private static function assertTcAvailable(PDO $pdo, $tcKimlikNo)
    {
        $tc = trim((string) $tcKimlikNo);
        if ($tc === '') {
            return;
        }
        if (PersonelCreateService::tcExists($pdo, $tc)) {
            self::duplicateTcResponse();
        }
    }

    private static function assertSicilAvailable(PDO $pdo, $sicilNo, $exceptPersonelId = null)
    {
        if (PersonelCreateService::sicilExists($pdo, (string) $sicilNo, $exceptPersonelId)) {
            self::duplicateSicilResponse();
        }
    }

    private static function assertTcAvailableForUpdate(PDO $pdo, $tcKimlikNo, $personelId)
    {
        $tc = trim((string) $tcKimlikNo);
        if ($tc === '') {
            return;
        }
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc_kimlik_no AND id <> :id LIMIT 1');
        $stmt->execute([
            'tc_kimlik_no' => $tc,
            'id' => (int) $personelId,
        ]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            self::duplicateTcResponse();
        }
    }

    /** @param array<string, mixed> $payload */
    private static function updatePersonelRow(PDO $pdo, $personelId, array $payload)
    {
        if (count($payload) === 0) {
            return;
        }

        $allowedColumns = [
            'tc_kimlik_no',
            'ad',
            'soyad',
            'dogum_tarihi',
            'telefon',
            'acil_durum_kisi',
            'acil_durum_telefon',
            'sicil_no',
            'ise_giris_tarihi',
            'sube_id',
            'sgk_isveren_id',
            'calisma_lokasyonu_id',
            'departman_id',
            'bolum_id',
            'birim_id',
            'gorev_id',
            'pozisyon_id',
            'personel_tipi_id',
            'bagli_amir_id',
            'aktif_durum',
            'calisan_kapsami',
            'dogum_yeri',
            'kan_grubu',
            'ucret_tipi_id',
            'maas_tutari',
            'prim_kurali_id',
        ];

        if (!PersonelCalisanKapsamSchema::isReady($pdo)) {
            $allowedColumns = array_values(array_filter(
                $allowedColumns,
                static function (string $c): bool {
                    return $c !== 'calisan_kapsami';
                }
            ));
        }
        if (!PersonelOrgLocationSchema::isReady($pdo)) {
            $allowedColumns = array_values(array_filter(
                $allowedColumns,
                static function (string $c): bool {
                    return $c !== 'sgk_isveren_id' && $c !== 'calisma_lokasyonu_id';
                }
            ));
        }
        if (!PersonelOrgStructureSchema::isReady($pdo)) {
            $allowedColumns = array_values(array_filter(
                $allowedColumns,
                static function (string $c): bool {
                    return $c !== 'bolum_id' && $c !== 'birim_id' && $c !== 'pozisyon_id';
                }
            ));
        }

        $set = [];
        $params = ['id' => (int) $personelId];
        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }

            $set[] = $column . ' = :' . $column;
            $params[$column] = $payload[$column];
        }

        if (count($set) === 0) {
            return;
        }

        $stmt = $pdo->prepare('UPDATE personeller SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Shared display name for the personnel record's branch, delegated to the
     * single read-model owner. Null branch stays null.
     *
     * @param array<string, mixed> $row
     * @return string|null
     */
    private static function subeGosterimAdi(array $row)
    {
        $subeAd = $row['sube_adi'] ?? null;
        if ($subeAd === null || $subeAd === '') {
            return $subeAd;
        }

        return SubeReadModel::tamAd(
            array_key_exists('sube_sirket_adi', $row) ? $row['sube_sirket_adi'] : null,
            (string) $subeAd
        );
    }

    /** @return array{columns:string,joins:string} */
    private static function personelSelectSql(PDO $pdo)
    {
        $columns = 'p.*, s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi';
        $joins = "
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
        ";
        if (OrganizasyonSchema::isSchemaReady($pdo)) {
            // Personnel screens are a shared surface: they show the derived
            // company-qualified branch name, not the raw short name.
            $columns .= ', sirket_of_sube.ad AS sube_sirket_adi';
            $joins .= "
            LEFT JOIN sirketler sirket_of_sube ON sirket_of_sube.id = s.sirket_id
            ";
        }
        if (PersonelOrgLocationSchema::isReady($pdo)) {
            $columns .= ', si.ad AS sgk_isveren_adi, cl.ad AS calisma_lokasyonu_adi';
            $joins .= "
            LEFT JOIN sgk_isverenler si ON si.id = p.sgk_isveren_id
            LEFT JOIN calisma_lokasyonlari cl ON cl.id = p.calisma_lokasyonu_id
            ";
        }
        if (PersonelOrgStructureSchema::isReady($pdo)) {
            $hasKisaKod = PersonelOrgStructureSchema::hasKisaKodColumns($pdo);
            $kisaCols = $hasKisaKod
                ? ', b.kisa_kod AS bolum_kisa_kod, bi.kisa_kod AS birim_kisa_kod'
                : '';
            $columns .= ', b.ad AS bolum_adi, bi.ad AS birim_adi, poz.ad AS pozisyon_adi' . $kisaCols;
            $joins .= "
            LEFT JOIN bolumler b ON b.id = p.bolum_id
            LEFT JOIN birimler bi ON bi.id = p.birim_id
            LEFT JOIN pozisyonlar poz ON poz.id = p.pozisyon_id
            ";
        }

        return ['columns' => $columns, 'joins' => $joins];
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonelRowById(PDO $pdo, $personelId)
    {
        $select = self::personelSelectSql($pdo);
        $sql = "
            SELECT {$select['columns']}
            FROM personeller p
            {$select['joins']}
            WHERE p.id = :id
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function existsActiveRecord(PDO $pdo, $table, $id)
    {
        $allowedTables = ['subeler', 'departmanlar', 'gorevler', 'personel_tipleri'];
        if (!in_array($table, $allowedTables, true)) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT id FROM $table WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => (int) $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function duplicateTcResponse()
    {
        JsonResponse::error(409, 'DUPLICATE_TC_KIMLIK_NO', 'Bu T.C. Kimlik No ile kayıt açılamaz.', 'tc_kimlik_no');
    }

    private static function duplicateSicilResponse()
    {
        JsonResponse::error(409, 'DUPLICATE_SICIL_NO', 'Bu sicil no ile kayıt açılamaz.', 'sicil_no');
    }

    private static function isDuplicateTcException(\PDOException $e)
    {
        if ($e->getCode() !== '23000') {
            return false;
        }

        $errorInfo = $e->errorInfo;
        if (!is_array($errorInfo) || !isset($errorInfo[1]) || (int) $errorInfo[1] !== 1062) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return strpos($message, 'uq_personeller_tc') !== false || strpos($message, 'tc_kimlik_no') !== false;
    }

    private static function isDuplicateSicilException(\PDOException $e)
    {
        if ($e->getCode() !== '23000') {
            return false;
        }

        $errorInfo = $e->errorInfo;
        if (!is_array($errorInfo) || !isset($errorInfo[1]) || (int) $errorInfo[1] !== 1062) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return strpos($message, 'uq_personeller_sicil') !== false || strpos($message, 'sicil_no') !== false;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    /** @param mixed $value */
    private static function parseHeaderPositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;
        return $parsed > 0 ? $parsed : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $user
     * @param bool $includeCompletenessFields Detail responses include full missing_fields; list stays light.
     * @return array<string, mixed>
     */
    private static function mapPersonelRow(array $row, array $user, $includeCompletenessFields = true)
    {
        $ucretTipiId = $row['ucret_tipi_id'] !== null ? (int) $row['ucret_tipi_id'] : null;
        $primKuraliId = $row['prim_kurali_id'] !== null ? (int) $row['prim_kurali_id'] : null;
        $ucretTipiAdlari = [1 => 'Aylik', 2 => 'Gunluk', 3 => 'Saatlik'];
        $primKuraliAdlari = [1 => 'Devamsizlik Primi Yok', 2 => 'Tam Prim', 3 => 'Kismi Prim'];
        $maasTutari = $row['maas_tutari'] !== null ? (float) $row['maas_tutari'] : null;

        $mapped = [
            'id' => (int) $row['id'],
            'tc_kimlik_no' => $row['tc_kimlik_no'] !== null && $row['tc_kimlik_no'] !== ''
                ? (string) $row['tc_kimlik_no']
                : null,
            'ad' => (string) $row['ad'],
            'soyad' => $row['soyad'] !== null && $row['soyad'] !== '' ? (string) $row['soyad'] : null,
            'aktif_durum' => (string) $row['aktif_durum'],
            'calisan_kapsami' => PersonelCalisanKapsamService::resolveFromRow($row),
            'sube_id' => $row['sube_id'] !== null && (int) $row['sube_id'] > 0
                ? (int) $row['sube_id']
                : null,
            'sgk_isveren_id' => array_key_exists('sgk_isveren_id', $row) && $row['sgk_isveren_id'] !== null
                ? (int) $row['sgk_isveren_id']
                : null,
            'calisma_lokasyonu_id' => array_key_exists('calisma_lokasyonu_id', $row) && $row['calisma_lokasyonu_id'] !== null
                ? (int) $row['calisma_lokasyonu_id']
                : null,
            'telefon' => $row['telefon'] !== null && $row['telefon'] !== '' ? (string) $row['telefon'] : null,
            'dogum_tarihi' => $row['dogum_tarihi'] !== null && $row['dogum_tarihi'] !== ''
                ? (string) $row['dogum_tarihi']
                : null,
            'sicil_no' => $row['sicil_no'],
            'dogum_yeri' => $row['dogum_yeri'],
            'kan_grubu' => $row['kan_grubu'],
            'ise_giris_tarihi' => $row['ise_giris_tarihi'],
            'acil_durum_kisi' => $row['acil_durum_kisi'],
            'acil_durum_telefon' => $row['acil_durum_telefon'],
            'departman_id' => $row['departman_id'] !== null ? (int) $row['departman_id'] : null,
            'bolum_id' => array_key_exists('bolum_id', $row) && $row['bolum_id'] !== null
                ? (int) $row['bolum_id']
                : null,
            'birim_id' => array_key_exists('birim_id', $row) && $row['birim_id'] !== null
                ? (int) $row['birim_id']
                : null,
            'gorev_id' => $row['gorev_id'] !== null ? (int) $row['gorev_id'] : null,
            'pozisyon_id' => array_key_exists('pozisyon_id', $row) && $row['pozisyon_id'] !== null
                ? (int) $row['pozisyon_id']
                : null,
            'personel_tipi_id' => $row['personel_tipi_id'] !== null ? (int) $row['personel_tipi_id'] : null,
            'bagli_amir_id' => $row['bagli_amir_id'] !== null ? (int) $row['bagli_amir_id'] : null,
            'sube_adi' => self::subeGosterimAdi($row),
            'sube_kisa_adi' => $row['sube_adi'],
            'sgk_isveren_adi' => array_key_exists('sgk_isveren_adi', $row) ? $row['sgk_isveren_adi'] : null,
            'calisma_lokasyonu_adi' => array_key_exists('calisma_lokasyonu_adi', $row)
                ? $row['calisma_lokasyonu_adi']
                : null,
            'departman_adi' => $row['departman_adi'],
            'bolum_adi' => array_key_exists('bolum_adi', $row) ? $row['bolum_adi'] : null,
            'bolum_kisa_kod' => array_key_exists('bolum_kisa_kod', $row) && is_string($row['bolum_kisa_kod'])
                && trim($row['bolum_kisa_kod']) !== ''
                ? trim($row['bolum_kisa_kod'])
                : null,
            'birim_adi' => array_key_exists('birim_adi', $row) ? $row['birim_adi'] : null,
            'birim_kisa_kod' => array_key_exists('birim_kisa_kod', $row) && is_string($row['birim_kisa_kod'])
                && trim($row['birim_kisa_kod']) !== ''
                ? trim($row['birim_kisa_kod'])
                : null,
            'gorev_adi' => $row['gorev_adi'],
            'pozisyon_adi' => array_key_exists('pozisyon_adi', $row) ? $row['pozisyon_adi'] : null,
            'personel_tipi_adi' => $row['personel_tipi_adi'],
            'referans_adlari' => [
                'sube' => self::subeGosterimAdi($row),
                'sgk_isveren' => array_key_exists('sgk_isveren_adi', $row) ? $row['sgk_isveren_adi'] : null,
                'calisma_lokasyonu' => array_key_exists('calisma_lokasyonu_adi', $row)
                    ? $row['calisma_lokasyonu_adi']
                    : null,
                'departman' => $row['departman_adi'],
                'bolum' => array_key_exists('bolum_adi', $row) ? $row['bolum_adi'] : null,
                'birim' => array_key_exists('birim_adi', $row) ? $row['birim_adi'] : null,
                'gorev' => $row['gorev_adi'],
                'pozisyon' => array_key_exists('pozisyon_adi', $row) ? $row['pozisyon_adi'] : null,
                'personel_tipi' => $row['personel_tipi_adi'],
            ],
            'ucret_tipi_id' => $ucretTipiId,
            'maas_tutari' => $maasTutari,
            'net_maas_tutari' => $maasTutari,
            'prim_kurali_id' => $primKuraliId,
            'ucret_tipi_adi' => $ucretTipiId !== null && isset($ucretTipiAdlari[$ucretTipiId])
                ? $ucretTipiAdlari[$ucretTipiId]
                : null,
            'prim_kurali_adi' => $primKuraliId !== null && isset($primKuraliAdlari[$primKuraliId])
                ? $primKuraliAdlari[$primKuraliId]
                : null,
        ];

        if (!RolePermissions::has($user, 'personeller.ucret.view')) {
            unset($mapped['maas_tutari'], $mapped['net_maas_tutari'], $mapped['brut_maas_tutari']);
        }

        $mapped['completeness'] = PersonelCompletenessService::evaluate(
            $mapped,
            (bool) $includeCompletenessFields
        );

        try {
            $pdo = Connection::get();
            $opCtx = PersonelOperationalContextService::resolve($pdo, (int) $mapped['id']);
            $mapped['org_status'] = $opCtx['org_status'];
            $mapped['operational_context'] = [
                'has_operational_scope' => $opCtx['has_operational_scope'],
                'source' => $opCtx['source'],
                'effective' => $opCtx['effective'],
                'active_assignment_id' => isset($opCtx['active_assignment']['id'])
                    ? (int) $opCtx['active_assignment']['id']
                    : null,
            ];
            if ($mapped['calisan_kapsami'] === PersonelCalisanKapsamService::DIS_KAYNAK) {
                $mapped['info_only_notice'] =
                    'HARİCİ PERSONEL — BİLGİ AMAÇLIDIR / ÜCRET VE SGK TAHAKKUKU OLUŞTURMAZ';
            }
        } catch (\Throwable $e) {
            $mapped['org_status'] = PersonelOperationalContextService::ORG_BAGLANTISIZ;
        }

        return $mapped;
    }

    public static function listGeciciGorevlendirmeler(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'personeller.detail.view');
        $pdo = Connection::get();
        $personelId = (int) $personelId;
        $stmt = $pdo->prepare('SELECT id, sube_id, bolum_id, birim_id, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            JsonResponse::notFound();
        }
        SubeScope::assertPersonelAccess($user, $request, $exists, $pdo);
        $rows = PersonelGeciciGorevlendirmeService::listHistory($pdo, $personelId);
        JsonResponse::success(['items' => $rows]);
    }

    public static function createGeciciGorevlendirme(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $pdo = Connection::get();
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        $body['personel_id'] = (int) $personelId;
        try {
            $row = PersonelGeciciGorevlendirmeService::create($pdo, $user, $body);
            JsonResponse::success(['item' => $row], [], 201);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(409, $e->getCodeString() ?: 'VALIDATION_ERROR', $e->getMessage(), $e->getField());
        }
    }

    public static function endGeciciGorevlendirme(Request $request, $personelId, $assignmentId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $pdo = Connection::get();
        $body = $request->getJsonBody();
        $endedAt = is_array($body) ? ($body['bitis_at'] ?? $body['ended_at'] ?? null) : null;
        try {
            $row = PersonelGeciciGorevlendirmeService::end($pdo, $user, $assignmentId, $endedAt);
            JsonResponse::success(['item' => $row]);
        } catch (PersonelValidationException $e) {
            JsonResponse::error(409, $e->getCodeString() ?: 'VALIDATION_ERROR', $e->getMessage(), $e->getField());
        }
    }

    /**
     * Permanent branch change. Deliberately separate from update(): that path
     * still forbids moving anybody between branches and stays that way.
     */
    public static function kaliciSubeDegisikligi(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);

        try {
            PersonelKaliciSubeDegisikligiService::assertRole($user);
        } catch (OrganizasyonException $e) {
            JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
        }

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::notFound();
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        PersonelArchiveGate::assertBusinessWriteAllowed($pdo, $personelId);

        $actorId = (int) ($user['id'] ?? 0);
        $idemKey = OfflineMutationIdempotencyService::readKey($request);
        $idemScope = 'personeller.kalici-sube-degisikligi:' . $personelId;
        $idemHash = OfflineMutationIdempotencyService::hashPayload([
            'op' => $idemScope,
            'personel_id' => $personelId,
            'payload' => $body,
        ]);

        try {
            $auditContext = OrganizasyonAuditContext::fromRequest($request, $user, $idemKey);
        } catch (OrganizasyonException $e) {
            JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
        }

        if ($idemKey !== null) {
            $replay = OfflineMutationIdempotencyService::findCompletedReplay(
                $pdo,
                $actorId,
                $idemScope,
                $idemKey,
                $idemHash
            );
            if (is_array($replay)) {
                self::respondKaliciSubeDegisikligi($pdo, $user, $personelId, (int) ($replay['http_status'] ?? 200));
            }
        }

        $claim = null;
        $complete = null;
        if ($idemKey !== null) {
            $claim = function (PDO $tx) use ($actorId, $idemScope, $idemKey, $idemHash) {
                return OfflineMutationIdempotencyService::claimInTransaction(
                    $tx,
                    $actorId,
                    $idemScope,
                    $idemKey,
                    $idemHash
                );
            };
            $complete = function (PDO $tx) use ($actorId, $idemScope, $idemKey, $personelId) {
                OfflineMutationIdempotencyService::completeInTransaction(
                    $tx,
                    $actorId,
                    $idemScope,
                    $idemKey,
                    200,
                    'personel',
                    $personelId,
                    null
                );
            };
        }

        try {
            $result = PersonelKaliciSubeDegisikligiService::apply(
                $pdo,
                $user,
                $personelId,
                $body,
                $auditContext,
                $claim,
                $complete
            );
        } catch (OrganizasyonException $e) {
            JsonResponse::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->field);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Kalici sube degisikligi uygulanamadi.');
        }

        self::respondKaliciSubeDegisikligi(
            $pdo,
            $user,
            $personelId,
            200,
            $result['replay'] ? [] : ['audit_id' => $result['audit_id']]
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $meta
     */
    private static function respondKaliciSubeDegisikligi(PDO $pdo, array $user, $personelId, $status, array $meta = [])
    {
        $row = self::fetchPersonelRowById($pdo, $personelId);
        if (!$row) {
            JsonResponse::serverError('Kalici sube degisikligi dogrulanamadi.');
        }

        JsonResponse::success(self::mapPersonelRow($row, $user), $meta, (int) $status);
    }

    public static function listDisKaynakAssignablePool(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $pdo = Connection::get();
        $items = PersonelGeciciGorevlendirmeService::listAssignablePoolForBolumManager($pdo, $user);
        JsonResponse::success(['items' => $items]);
    }

    public static function listDisKaynakUnassignedPool(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $pdo = Connection::get();
        $items = PersonelGeciciGorevlendirmeService::listUnassignedDisPool($pdo, $user);
        JsonResponse::success(['items' => $items]);
    }

    /** @param array<string, mixed> $body */
    private static function hasSalaryField(array $body)
    {
        return array_key_exists('maas_tutari', $body)
            || array_key_exists('net_maas_tutari', $body)
            || array_key_exists('brut_maas_tutari', $body);
    }
}
