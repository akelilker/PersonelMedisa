<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use PDO;
use RuntimeException;

/**
 * Canonical organisation domain owner: sirket -> (sgk_isveren | sube) -> lokasyon.
 *
 * Every branch read in the application goes through readSube()/listSubeler() so
 * the derived tam_ad has exactly one definition, and every branch write goes
 * through createSube()/updateSube() so the in-company short-name rule is
 * enforced once instead of being copied into each controller.
 *
 * The legacy flat endpoints and the new nested endpoints are two routes into
 * these same functions; they cannot drift apart.
 */
final class OrganizasyonService
{
    private const DURUMLAR = ['AKTIF', 'PASIF'];

    private const SUBE_DELETE_BLOCKED_MESSAGE = 'Şubede Kayıtlı Personel Gözükmektedir. Kayıtlı Personel Varken Silme İşlemi Yapılamaz.';

    // ---------------------------------------------------------------- companies

    /** @return array<int, array<string, mixed>> */
    public static function listSirketler(PDO $pdo): array
    {
        self::assertSchemaReady($pdo);

        $stmt = $pdo->query(
            'SELECT c.id, c.kod, c.ad, c.durum,
                    (SELECT COUNT(*) FROM subeler s WHERE s.sirket_id = c.id) AS sube_sayisi
             FROM sirketler c
             ORDER BY c.ad ASC, c.id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::mapSirketRow($row);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    public static function readSirket(PDO $pdo, $sirketId): array
    {
        self::assertSchemaReady($pdo);
        $sirket = self::findSirket($pdo, self::parseId($sirketId, 'id'));
        if ($sirket === null) {
            throw OrganizasyonException::notFound('Şirket bulunamadı.');
        }

        return $sirket;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function createSirket(PDO $pdo, array $body): array
    {
        self::assertSchemaReady($pdo);

        $kod = self::requireText($body, 'kod', 'Şirket kodu zorunludur.', 32);
        $ad = self::requireText($body, 'ad', 'Şirket adı zorunludur.', 191);
        $durum = self::parseDurum($body);

        self::assertSirketKodUnique($pdo, $kod, null);
        self::assertSirketAdUnique($pdo, $ad, null);

        $stmt = $pdo->prepare('INSERT INTO sirketler (kod, ad, durum) VALUES (:kod, :ad, :durum)');
        $stmt->execute(['kod' => $kod, 'ad' => $ad, 'durum' => $durum]);

        return self::readSirket($pdo, (int) $pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function updateSirket(PDO $pdo, $sirketId, array $body): array
    {
        self::assertSchemaReady($pdo);
        $id = self::parseId($sirketId, 'id');
        $existing = self::findSirket($pdo, $id);
        if ($existing === null) {
            throw OrganizasyonException::notFound('Şirket bulunamadı.');
        }

        // kod is the stable technical identity: renaming it would silently
        // repoint every external reference that resolves a company by code.
        self::assertKodImmutable($body, (string) $existing['kod'], 'Şirket kodu değiştirilemez.');

        $ad = array_key_exists('ad', $body)
            ? self::requireText($body, 'ad', 'Şirket adı zorunludur.', 191)
            : (string) $existing['ad'];
        $durum = array_key_exists('durum', $body) ? self::parseDurum($body) : (string) $existing['durum'];

        if (SubeReadModel::normalizeName($ad) !== SubeReadModel::normalizeName((string) $existing['ad'])) {
            self::assertSirketAdUnique($pdo, $ad, $id);
        }

        $stmt = $pdo->prepare('UPDATE sirketler SET ad = :ad, durum = :durum WHERE id = :id');
        $stmt->execute(['id' => $id, 'ad' => $ad, 'durum' => $durum]);

        return self::readSirket($pdo, $id);
    }

    /** @return array<string, mixed> */
    public static function deleteSirket(PDO $pdo, $sirketId): array
    {
        self::assertSchemaReady($pdo);
        $id = self::parseId($sirketId, 'id');
        if (self::findSirket($pdo, $id) === null) {
            throw OrganizasyonException::notFound('Şirket bulunamadı.');
        }

        $subeCount = self::countWhere($pdo, 'SELECT COUNT(*) FROM subeler WHERE sirket_id = :id', ['id' => $id]);
        $sgkCount = self::countWhere($pdo, 'SELECT COUNT(*) FROM sgk_isverenler WHERE sirket_id = :id', ['id' => $id]);
        if ($subeCount > 0 || $sgkCount > 0) {
            throw OrganizasyonException::conflict(
                'SIRKET_HAS_DEPENDENTS',
                'Şirkete bağlı şube veya SGK işvereni bulunduğu için silinemez. Şirketi pasife alabilirsiniz.'
            );
        }

        $stmt = $pdo->prepare('DELETE FROM sirketler WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return ['id' => $id, 'deleted' => true];
    }

    // ----------------------------------------------------------------- branches

    /**
     * Shared branch read model. $sirketId narrows to one company; null returns
     * the whole flat list, which is what the legacy endpoint needs.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listSubeler(PDO $pdo, ?int $sirketId = null): array
    {
        if ($sirketId !== null) {
            self::assertSchemaReady($pdo);
        }

        $params = [];
        $where = '';
        if ($sirketId !== null) {
            $where = ' WHERE s.sirket_id = :sirket_id';
            $params['sirket_id'] = $sirketId;
        }

        $sql = 'SELECT ' . SubeReadModel::selectColumns($pdo)
            . ', GROUP_CONCAT(sd.departman_id ORDER BY sd.departman_id ASC) AS departman_ids'
            . ', GROUP_CONCAT(d.ad ORDER BY sd.departman_id ASC) AS departman_adlari'
            . ' FROM subeler s'
            . SubeReadModel::joinSql($pdo)
            . ' LEFT JOIN sube_departmanlar sd ON sd.sube_id = s.id'
            . ' LEFT JOIN departmanlar d ON d.id = sd.departman_id'
            . $where
            . ' GROUP BY ' . SubeReadModel::groupBySql($pdo)
            . ' ORDER BY s.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = SubeReadModel::mapRow($row);
        }

        return self::attachSorumluYoneticiPayloads(
            $pdo,
            self::attachMuhasebeYetkiPayloads($pdo, $items)
        );
    }

    /**
     * Bounded branch preimage read for the final-close package only.
     *
     * The general UI read model (listSubeler) is deliberately not reused here: its
     * departman GROUP BY and muhasebe/manager payload joins describe the whole
     * branch catalogue for the screens, so an unrelated screen-level dependency
     * could decide whether a bounded production preflight may run. This reader
     * answers exactly three questions for the named targets — does the branch
     * exist, is it AKTIF, which manager ids are recorded — and it fails closed
     * with a bounded code instead of letting driver text escape.
     *
     * Every runtime surface of this owner answers a bounded single-token code, so
     * a caller that wraps unknown throwables (FinalCloseSnapshot::read()) can never
     * fall back to its generic stage code for an attributable failure:
     * FINAL_CLOSE_BRANCH_TARGET_INVALID, FINAL_CLOSE_MANAGER_SCHEMA_CHECK_FAILED,
     * FINAL_CLOSE_MANAGER_SCHEMA_REQUIRED, FINAL_CLOSE_BRANCH_TABLE_READ_FAILED,
     * FINAL_CLOSE_MANAGER_MAP_READ_FAILED, FINAL_CLOSE_MANAGER_MAP_NORMALIZE_FAILED,
     * FINAL_CLOSE_BRANCH_ROW_NORMALIZE_FAILED, FINAL_CLOSE_BRANCH_ASSERTION_FAILED
     * and FINAL_CLOSE_BRANCH_MISSING. Raw driver/SQL text is never attached.
     *
     * @param array<int, int> $subeIds only the compiled target branch ids
     * @return array<int, array{id: int, durum: string, sorumlu_yonetici_user_ids: array<int, int>}>
     */
    public static function readFinalCloseBranchPreimage(PDO $pdo, array $subeIds): array
    {
        $ids = [];
        foreach ($subeIds as $subeId) {
            $id = is_int($subeId) ? $subeId : 0;
            if ($id <= 0) {
                throw new RuntimeException('FINAL_CLOSE_BRANCH_TARGET_INVALID');
            }
            $ids[$id] = $id;
        }
        if (count($ids) === 0) {
            throw new RuntimeException('FINAL_CLOSE_BRANCH_TARGET_INVALID');
        }

        // Narrow stage: the schema-ready probe of the manager owner. A probe that
        // cannot answer at all (a missing owner file or an engine-level error at
        // the call site, before the probe body runs) is attributed here and never
        // to a read of the target branch.
        try {
            $schemaReady = SubeSorumluYoneticiSchema::isReady($pdo);
        } catch (\Throwable $error) {
            throw new RuntimeException('FINAL_CLOSE_MANAGER_SCHEMA_CHECK_FAILED');
        }
        // The manager axis is the only relation this preimage owns. An unready
        // schema must block the preflight instead of reading "no managers".
        if ($schemaReady !== true) {
            throw new RuntimeException('FINAL_CLOSE_MANAGER_SCHEMA_REQUIRED');
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $subeId) {
            $key = 'fcs' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $subeId;
        }
        // Existence and status come from the organization owner itself; only the
        // projected columns are read, because no UI join is needed to attest a
        // target branch. A failure of this one read is attributed to the target
        // table, so it cannot be mistaken for an unknown branch.
        try {
            $stmt = $pdo->prepare(
                'SELECT id, durum FROM subeler WHERE id IN (' . implode(', ', $placeholders) . ') ORDER BY id ASC'
            );
            // A silent-mode driver answers false instead of throwing, so the
            // statement shape is checked as well as the thrown failure.
            $rows = $stmt !== false && $stmt->execute($params) === true
                ? $stmt->fetchAll(PDO::FETCH_ASSOC)
                : false;
        } catch (\Throwable $error) {
            $rows = false;
        }
        if (!is_array($rows)) {
            throw new RuntimeException('FINAL_CLOSE_BRANCH_TABLE_READ_FAILED');
        }

        // The manager map is its own canonical read: a failure there must never be
        // reported as a missing target branch or as "no managers recorded".
        try {
            $managerIds = SubeSorumluYoneticiSchema::loadSubeUserMap($pdo, array_values($ids));
        } catch (\Throwable $error) {
            throw new RuntimeException('FINAL_CLOSE_MANAGER_MAP_READ_FAILED');
        }

        // Narrow stage: manager-map result normalization. A map entry that is not
        // the owned list shape must fail closed here instead of degrading into
        // "no managers recorded" or escaping as a bare PHP Error.
        try {
            foreach ($ids as $id) {
                $managerIds[$id] = array_values($managerIds[$id] ?? []);
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('FINAL_CLOSE_MANAGER_MAP_NORMALIZE_FAILED');
        }

        // Narrow stage: target row normalization. A row that is not the owned
        // shape is a read defect and must never be reported as a missing target.
        try {
            $branches = [];
            foreach ($rows as $row) {
                if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
                    throw new RuntimeException('FINAL_CLOSE_BRANCH_ROW_NORMALIZE_FAILED');
                }
                $id = (int) $row['id'];
                $branches[$id] = [
                    'id' => $id,
                    'durum' => strtoupper(trim((string) ($row['durum'] ?? ''))),
                    'sorumlu_yonetici_user_ids' => array_values($managerIds[$id] ?? []),
                ];
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('FINAL_CLOSE_BRANCH_ROW_NORMALIZE_FAILED');
        }

        // Narrow stage: target assertion. Only a target the read did not answer
        // for is a missing branch; anything unexpected here keeps its own code.
        $missing = [];
        try {
            foreach ($ids as $id) {
                if (!isset($branches[$id])) {
                    $missing[] = $id;
                }
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('FINAL_CLOSE_BRANCH_ASSERTION_FAILED');
        }
        if (count($missing) > 0) {
            throw new RuntimeException('FINAL_CLOSE_BRANCH_MISSING');
        }

        return $branches;
    }

    /** @return array<string, mixed>|null */
    public static function findSube(PDO $pdo, int $subeId): ?array
    {
        $sql = 'SELECT ' . SubeReadModel::selectColumns($pdo)
            . ', GROUP_CONCAT(sd.departman_id ORDER BY sd.departman_id ASC) AS departman_ids'
            . ', GROUP_CONCAT(d.ad ORDER BY sd.departman_id ASC) AS departman_adlari'
            . ' FROM subeler s'
            . SubeReadModel::joinSql($pdo)
            . ' LEFT JOIN sube_departmanlar sd ON sd.sube_id = s.id'
            . ' LEFT JOIN departmanlar d ON d.id = sd.departman_id'
            . ' WHERE s.id = :id'
            . ' GROUP BY ' . SubeReadModel::groupBySql($pdo)
            . ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $items = self::attachSorumluYoneticiPayloads(
            $pdo,
            self::attachMuhasebeYetkiPayloads($pdo, [SubeReadModel::mapRow($row)])
        );

        return $items[0] ?? null;
    }

    /** @return array<string, mixed> */
    public static function readSube(PDO $pdo, $subeId, ?int $parentSirketId = null): array
    {
        $sube = self::findSube($pdo, self::parseId($subeId, 'id'));
        if ($sube === null) {
            throw OrganizasyonException::notFound('Şube bulunamadı.');
        }
        if ($parentSirketId !== null) {
            self::assertBelongsToSirket($sube, $parentSirketId);
        }

        return $sube;
    }

    /**
     * @param array<string, mixed> $body
     * @param int|null $parentSirketId company from the nested route, never from the payload
     * @param OrganizasyonAuditContext|null $auditContext
     *        Supplied by every authenticated route. Null is reserved for
     *        fixtures and schema harnesses that have no actor to attribute the
     *        write to; when it is present the audit row is mandatory and its
     *        failure rolls the branch back.
     * @return array<string, mixed>
     */
    public static function createSube(
        PDO $pdo,
        array $body,
        ?int $parentSirketId = null,
        ?OrganizasyonAuditContext $auditContext = null
    ): array {
        self::rejectDerivedFields($body);
        self::rejectPayloadSirketId($body, $parentSirketId);

        if ($parentSirketId !== null) {
            self::assertSchemaReady($pdo);
            if (self::findSirket($pdo, $parentSirketId) === null) {
                throw OrganizasyonException::notFound('Şirket bulunamadı.');
            }
        }

        $kod = self::requireText($body, 'kod', 'Şube kodu zorunludur.', 32);
        $ad = self::requireText($body, 'ad', 'Şube kısa adı zorunludur.', 120);
        $durum = self::parseDurum($body);
        $departmanIds = self::parseDepartmanIds($body['departman_ids'] ?? []);
        $sgkIsverenId = self::parseNullableId($body, 'sgk_isveren_id');
        $muhasebePlan = self::parseMuhasebeYetkiPlan($body);
        $sorumluPlan = self::parseSorumluYoneticiPlan($body);

        self::assertDepartmanIdsExist($pdo, $departmanIds);
        self::assertSubeKodUnique($pdo, $kod, null);
        self::assertSubeAdUniqueInSirket($pdo, $ad, $parentSirketId, null);
        self::assertSgkIsverenConsistent($pdo, $sgkIsverenId, $parentSirketId);
        if ($muhasebePlan !== null) {
            self::assertMuhasebeYetkiPlanValid($pdo, $muhasebePlan);
        }
        if ($sorumluPlan !== null) {
            self::assertSorumluYoneticiPlanValid($pdo, $sorumluPlan);
        }

        $columns = ['kod', 'ad', 'durum'];
        $params = ['kod' => $kod, 'ad' => $ad, 'durum' => $durum];
        if (OrganizasyonSchema::isSchemaReady($pdo)) {
            $columns[] = 'sirket_id';
            $params['sirket_id'] = $parentSirketId;
        }
        if ($sgkIsverenId !== null) {
            $columns[] = 'sgk_isveren_id';
            $params['sgk_isveren_id'] = $sgkIsverenId;
        }

        // An unauditable environment must not gain a branch it cannot explain.
        if ($auditContext !== null) {
            OrganizasyonAuditWriter::assertReady($pdo, OrganizasyonAuditWriter::SUBE_CREATE_TABLE);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO subeler (' . implode(', ', $columns) . ') VALUES (:'
                . implode(', :', $columns) . ')'
            );
            $stmt->execute($params);
            $subeId = (int) $pdo->lastInsertId();
            self::replaceSubeDepartmanlar($pdo, $subeId, $departmanIds);
            if ($muhasebePlan !== null) {
                SubeMuhasebeYetkiSchema::replaceForSube(
                    $pdo,
                    $subeId,
                    $muhasebePlan['enabled'] ? $muhasebePlan['user_ids'] : []
                );
            }
            if ($sorumluPlan !== null) {
                SubeSorumluYoneticiSchema::replaceForSube($pdo, $subeId, $sorumluPlan);
            }
            if ($auditContext !== null) {
                OrganizasyonAuditWriter::recordSubeOlusturma($pdo, [
                    'sube_id' => $subeId,
                    'sirket_id' => $params['sirket_id'] ?? null,
                    'kod' => $kod,
                    'ad' => $ad,
                    'durum' => $durum,
                    'sgk_isveren_id' => $sgkIsverenId,
                    'departman_ids' => $departmanIds,
                ], $auditContext);
            }
            $pdo->commit();
        } catch (OrganizasyonException $e) {
            $pdo->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new OrganizasyonException(500, 'INTERNAL_ERROR', 'Şube kaydı oluşturulamadı.');
        }

        return self::readSube($pdo, $subeId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function updateSube(PDO $pdo, $subeId, array $body, ?int $parentSirketId = null): array
    {
        self::rejectDerivedFields($body);
        self::rejectPayloadSirketId($body, $parentSirketId);

        $id = self::parseId($subeId, 'id');
        $existing = self::findSube($pdo, $id);
        if ($existing === null) {
            throw OrganizasyonException::notFound('Şube bulunamadı.');
        }
        if ($parentSirketId !== null) {
            self::assertSchemaReady($pdo);
            self::assertBelongsToSirket($existing, $parentSirketId);
        }

        self::assertKodImmutable($body, (string) $existing['kod'], 'Şube kodu değiştirilemez.');

        $ad = array_key_exists('ad', $body)
            ? self::requireText($body, 'ad', 'Şube kısa adı zorunludur.', 120)
            : (string) $existing['ad'];
        $durum = array_key_exists('durum', $body) ? self::parseDurum($body) : (string) $existing['durum'];
        $departmanIds = array_key_exists('departman_ids', $body)
            ? self::parseDepartmanIds($body['departman_ids'])
            : null;
        $muhasebePlan = self::parseMuhasebeYetkiPlan($body);
        $sorumluPlan = self::parseSorumluYoneticiPlan($body);

        $currentSirketId = isset($existing['sirket']['id']) ? (int) $existing['sirket']['id'] : null;
        $sgkIsverenId = array_key_exists('sgk_isveren_id', $body)
            ? self::parseNullableId($body, 'sgk_isveren_id')
            : (isset($existing['sgk_isveren']['id']) ? (int) $existing['sgk_isveren']['id'] : null);

        if ($departmanIds !== null) {
            self::assertDepartmanIdsExist($pdo, $departmanIds);
        }
        if (SubeReadModel::normalizeName($ad) !== SubeReadModel::normalizeName((string) $existing['ad'])) {
            self::assertSubeAdUniqueInSirket($pdo, $ad, $currentSirketId, $id);
        }
        self::assertSgkIsverenConsistent($pdo, $sgkIsverenId, $currentSirketId);
        if ($muhasebePlan !== null) {
            self::assertMuhasebeYetkiPlanValid($pdo, $muhasebePlan);
        }
        if ($sorumluPlan !== null) {
            self::assertSorumluYoneticiPlanValid($pdo, $sorumluPlan);
        }

        $sets = ['ad = :ad', 'durum = :durum'];
        $params = ['id' => $id, 'ad' => $ad, 'durum' => $durum];
        if (array_key_exists('sgk_isveren_id', $body)) {
            $sets[] = 'sgk_isveren_id = :sgk_isveren_id';
            $params['sgk_isveren_id'] = $sgkIsverenId;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE subeler SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($params);
            if ($departmanIds !== null) {
                self::replaceSubeDepartmanlar($pdo, $id, $departmanIds);
            }
            if ($muhasebePlan !== null) {
                SubeMuhasebeYetkiSchema::replaceForSube(
                    $pdo,
                    $id,
                    $muhasebePlan['enabled'] ? $muhasebePlan['user_ids'] : []
                );
            }
            if ($sorumluPlan !== null) {
                SubeSorumluYoneticiSchema::replaceForSube($pdo, $id, $sorumluPlan);
            }
            $pdo->commit();
        } catch (OrganizasyonException $e) {
            $pdo->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new OrganizasyonException(500, 'INTERNAL_ERROR', 'Şube kaydı güncellenemedi.');
        }

        return self::readSube($pdo, $id);
    }

    /** @return array<string, mixed> */
    public static function deleteSube(PDO $pdo, $subeId, ?int $parentSirketId = null): array
    {
        $id = self::parseId($subeId, 'id');
        $existing = self::findSube($pdo, $id);
        if ($existing === null) {
            throw OrganizasyonException::notFound('Şube bulunamadı.');
        }
        if ($parentSirketId !== null) {
            self::assertSchemaReady($pdo);
            self::assertBelongsToSirket($existing, $parentSirketId);
        }

        $personelCount = self::countWhere(
            $pdo,
            'SELECT COUNT(*) FROM personeller WHERE sube_id = :id',
            ['id' => $id]
        );
        if ($personelCount > 0) {
            throw OrganizasyonException::conflict('SUBE_HAS_PERSONEL', self::SUBE_DELETE_BLOCKED_MESSAGE);
        }

        if (OrganizasyonSchema::isSchemaReady($pdo)) {
            $locationCount = self::countWhere(
                $pdo,
                'SELECT COUNT(*) FROM calisma_lokasyonlari WHERE sube_id = :id',
                ['id' => $id]
            );
            if ($locationCount > 0) {
                throw OrganizasyonException::conflict(
                    'SUBE_HAS_LOKASYON',
                    'Şubeye bağlı çalışma lokasyonu bulunduğu için silinemez.'
                );
            }
        }

        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM sube_departmanlar WHERE sube_id = :id');
            $delete->execute(['id' => $id]);
            $delete = $pdo->prepare('DELETE FROM subeler WHERE id = :id');
            $delete->execute(['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new OrganizasyonException(500, 'INTERNAL_ERROR', 'Şube kaydı silinemedi.');
        }

        return ['id' => $id, 'deleted' => true];
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $sube */
    private static function assertBelongsToSirket(array $sube, int $parentSirketId): void
    {
        $owner = isset($sube['sirket']['id']) ? (int) $sube['sirket']['id'] : 0;
        if ($owner !== $parentSirketId) {
            throw OrganizasyonException::notFound('Şube bu şirkete bağlı değil.');
        }
    }

    /**
     * tam_ad is a derived read field. Accepting it on write would create a second,
     * immediately stale source of truth for the displayed name.
     *
     * @param array<string, mixed> $body
     */
    private static function rejectDerivedFields(array $body): void
    {
        if (array_key_exists('tam_ad', $body)) {
            throw OrganizasyonException::validation(
                'tam_ad türetilmiş bir okuma alanıdır ve gönderilemez. Şube kısa adını `ad` alanıyla gönderin.',
                'tam_ad'
            );
        }
    }

    /** @param array<string, mixed> $body */
    private static function rejectPayloadSirketId(array $body, ?int $parentSirketId): void
    {
        if (!array_key_exists('sirket_id', $body)) {
            return;
        }
        // The parent company is route/state context, never a form field: a payload
        // company is either redundant or an attempt to move the branch sideways.
        throw OrganizasyonException::validation(
            'Şirket bağlamı route üzerinden gelir; payload içinde sirket_id gönderilemez.',
            'sirket_id'
        );
    }

    private static function assertSchemaReady(PDO $pdo): void
    {
        if (!OrganizasyonSchema::isSchemaReady($pdo)) {
            throw OrganizasyonException::schemaNotReady();
        }
    }

    /** @return array<string, mixed>|null */
    private static function findSirket(PDO $pdo, int $sirketId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.kod, c.ad, c.durum,
                    (SELECT COUNT(*) FROM subeler s WHERE s.sirket_id = c.id) AS sube_sayisi
             FROM sirketler c WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $sirketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapSirketRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapSirketRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kod' => (string) $row['kod'],
            'ad' => (string) $row['ad'],
            'durum' => (string) $row['durum'],
            'sube_sayisi' => (int) ($row['sube_sayisi'] ?? 0),
        ];
    }

    private static function assertSirketKodUnique(PDO $pdo, string $kod, ?int $excludeId): void
    {
        if (self::existsWithNormalized($pdo, 'sirketler', 'kod', $kod, $excludeId)) {
            throw OrganizasyonException::conflict('DUPLICATE_SIRKET_KOD', 'Bu şirket kodu zaten kayıtlı.', 'kod');
        }
    }

    private static function assertSirketAdUnique(PDO $pdo, string $ad, ?int $excludeId): void
    {
        if (self::existsWithNormalized($pdo, 'sirketler', 'ad', $ad, $excludeId)) {
            throw OrganizasyonException::conflict('DUPLICATE_SIRKET_AD', 'Bu şirket adı zaten kayıtlı.', 'ad');
        }
    }

    private static function assertSubeKodUnique(PDO $pdo, string $kod, ?int $excludeId): void
    {
        if (self::existsWithNormalized($pdo, 'subeler', 'kod', $kod, $excludeId)) {
            throw OrganizasyonException::conflict('DUPLICATE_SUBE_KOD', 'Bu şube kodu zaten kayıtlı.', 'kod');
        }
    }

    /**
     * Short branch names are unique per company, not globally: "Ankara" is a
     * legitimate branch of both Medisa and Karyapı. Unmapped legacy branches
     * (sirket_id NULL) form their own comparison group so the pre-hierarchy
     * behaviour is preserved until production mapping happens.
     */
    private static function assertSubeAdUniqueInSirket(PDO $pdo, string $ad, ?int $sirketId, ?int $excludeId): void
    {
        $ready = OrganizasyonSchema::isSchemaReady($pdo);
        $sql = 'SELECT s.ad FROM subeler s';
        $params = [];

        if ($ready) {
            $sql .= $sirketId === null ? ' WHERE s.sirket_id IS NULL' : ' WHERE s.sirket_id = :sirket_id';
            if ($sirketId !== null) {
                $params['sirket_id'] = $sirketId;
            }
        } else {
            $sql .= ' WHERE 1 = 1';
        }

        if ($excludeId !== null) {
            $sql .= ' AND s.id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $target = SubeReadModel::normalizeName($ad);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (SubeReadModel::normalizeName((string) $row['ad']) === $target) {
                throw OrganizasyonException::conflict(
                    'DUPLICATE_SUBE_AD',
                    $sirketId === null
                        ? 'Bu şube kısa adı zaten kayıtlı.'
                        : 'Bu şirkette aynı kısa ada sahip bir şube zaten var. Kısa ad şirket içinde benzersiz olmalıdır.',
                    'ad'
                );
            }
        }
    }

    /**
     * A branch and its payroll employer must sit under the same company. The
     * relation is never guessed from a name or from a sibling branch.
     */
    private static function assertSgkIsverenConsistent(PDO $pdo, ?int $sgkIsverenId, ?int $sirketId): void
    {
        if ($sgkIsverenId === null) {
            return;
        }

        $ready = OrganizasyonSchema::isSchemaReady($pdo);
        $stmt = $pdo->prepare(
            'SELECT id' . ($ready ? ', sirket_id' : '') . ' FROM sgk_isverenler WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $sgkIsverenId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw OrganizasyonException::validation('Geçersiz SGK işvereni seçimi.', 'sgk_isveren_id');
        }

        if (!$ready || $sirketId === null) {
            return;
        }

        $employerSirketId = ($row['sirket_id'] === null || $row['sirket_id'] === '')
            ? null
            : (int) $row['sirket_id'];
        if ($employerSirketId !== null && $employerSirketId !== $sirketId) {
            throw OrganizasyonException::conflict(
                'SGK_ISVEREN_SIRKET_MISMATCH',
                'Seçilen SGK işvereni bu şirkete bağlı değil.',
                'sgk_isveren_id'
            );
        }
    }

    private static function existsWithNormalized(
        PDO $pdo,
        string $table,
        string $column,
        string $value,
        ?int $excludeId
    ): bool {
        $sql = "SELECT $column AS value FROM $table";
        $params = [];
        if ($excludeId !== null) {
            $sql .= ' WHERE id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $target = SubeReadModel::normalizeName($value);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (SubeReadModel::normalizeName((string) $row['value']) === $target) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $body */
    private static function assertKodImmutable(array $body, string $currentKod, string $message): void
    {
        if (!array_key_exists('kod', $body)) {
            return;
        }
        if (trim((string) $body['kod']) !== $currentKod) {
            throw OrganizasyonException::conflict('KOD_IMMUTABLE', $message, 'kod');
        }
    }

    /** @param array<string, mixed> $body */
    private static function requireText(array $body, string $field, string $message, int $maxLength): string
    {
        $value = trim((string) ($body[$field] ?? ''));
        if ($value === '') {
            throw OrganizasyonException::validation($message, $field);
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $maxLength) {
            throw OrganizasyonException::validation(
                'Bu alan en fazla ' . $maxLength . ' karakter olabilir.',
                $field
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function parseDurum(array $body): string
    {
        $durum = strtoupper(trim((string) ($body['durum'] ?? 'AKTIF')));
        if (!in_array($durum, self::DURUMLAR, true)) {
            throw OrganizasyonException::validation('Geçersiz durum.', 'durum');
        }

        return $durum;
    }

    /** @param mixed $value */
    private static function parseId($value, string $field): int
    {
        $parsed = (int) $value;
        if ($parsed <= 0) {
            throw OrganizasyonException::validation('Geçersiz kayıt id.', $field);
        }

        return $parsed;
    }

    /** @param array<string, mixed> $body */
    private static function parseNullableId(array $body, string $field): ?int
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = (int) $value;
        if ($parsed <= 0) {
            throw OrganizasyonException::validation('Geçersiz kayıt seçimi.', $field);
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    public static function parseDepartmanIds($value): array
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

    /** @param array<int, int> $departmanIds */
    private static function assertDepartmanIdsExist(PDO $pdo, array $departmanIds): void
    {
        if (count($departmanIds) === 0) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($departmanIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departmanlar WHERE id IN ($placeholders)");
        $stmt->execute($departmanIds);
        if ((int) $stmt->fetchColumn() !== count($departmanIds)) {
            throw OrganizasyonException::validation('Geçersiz departman seçimi.', 'departman_ids');
        }
    }

    /** @param array<int, int> $departmanIds */
    private static function replaceSubeDepartmanlar(PDO $pdo, int $subeId, array $departmanIds): void
    {
        $delete = $pdo->prepare('DELETE FROM sube_departmanlar WHERE sube_id = :sube_id');
        $delete->execute(['sube_id' => $subeId]);

        if (count($departmanIds) === 0) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (:sube_id, :departman_id)'
        );
        foreach ($departmanIds as $departmanId) {
            $insert->execute(['sube_id' => $subeId, 'departman_id' => $departmanId]);
        }
    }

    /**
     * Attach branch accounting ACL fields. Missing schema → restriction disabled.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function attachMuhasebeYetkiPayloads(PDO $pdo, array $items): array
    {
        if (count($items) === 0) {
            return $items;
        }

        $map = [];
        if (SubeMuhasebeYetkiSchema::isReady($pdo)) {
            $subeIds = [];
            foreach ($items as $item) {
                $subeId = (int) ($item['id'] ?? 0);
                if ($subeId > 0) {
                    $subeIds[] = $subeId;
                }
            }
            $map = SubeMuhasebeYetkiSchema::loadRestrictedSubeUserMap($pdo, $subeIds);
        }

        foreach ($items as &$item) {
            $subeId = (int) ($item['id'] ?? 0);
            $userIds = $map[$subeId] ?? [];
            $item['muhasebe_kisit_aktif'] = count($userIds) > 0;
            $item['muhasebe_yetkili_user_ids'] = $userIds;
            $item['muhasebe_yetkilileri'] = self::loadMuhasebeYetkiliStatuses($pdo, $userIds);
        }
        unset($item);

        return $items;
    }

    /**
     * Preserve ACL rows even when a selected user later becomes ineligible;
     * surface eligibility so the edit screen can fail-closed without silent delete.
     *
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private static function loadMuhasebeYetkiliStatuses(PDO $pdo, array $userIds): array
    {
        if (count($userIds) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, username, ad_soyad, rol, durum
             FROM users
             WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_values($userIds));
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rol = strtoupper(trim((string) ($row['rol'] ?? '')));
            $durum = strtoupper(trim((string) ($row['durum'] ?? '')));
            $byId[$id] = [
                'id' => $id,
                'username' => (string) ($row['username'] ?? ''),
                'ad_soyad' => (string) ($row['ad_soyad'] ?? ''),
                'rol' => $rol,
                'durum' => $durum,
                'eligible' => $rol === 'MUHASEBE' && $durum === 'AKTIF',
            ];
        }

        $ordered = [];
        foreach ($userIds as $userId) {
            if (isset($byId[$userId])) {
                $ordered[] = $byId[$userId];
                continue;
            }
            $ordered[] = [
                'id' => $userId,
                'username' => '',
                'ad_soyad' => '',
                'rol' => '',
                'durum' => '',
                'eligible' => false,
            ];
        }

        return $ordered;
    }

    /**
     * null = payload omitted (preserve existing ACL).
     * enabled=false clears rows. enabled=true requires ≥1 eligible MUHASEBE user.
     *
     * @param array<string, mixed> $body
     * @return array{enabled: bool, user_ids: array<int, int>}|null
     */
    private static function parseMuhasebeYetkiPlan(array $body): ?array
    {
        $hasFlag = array_key_exists('muhasebe_kisit_aktif', $body);
        $hasIds = array_key_exists('muhasebe_yetkili_user_ids', $body);
        if (!$hasFlag && !$hasIds) {
            return null;
        }

        $enabled = $hasFlag
            ? filter_var($body['muhasebe_kisit_aktif'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        if ($enabled === null && $hasFlag) {
            throw OrganizasyonException::validation(
                'muhasebe_kisit_aktif alanı true/false olmalıdır.',
                'muhasebe_kisit_aktif'
            );
        }

        $userIds = $hasIds ? self::parseDepartmanIds($body['muhasebe_yetkili_user_ids']) : [];

        if ($enabled === null) {
            // IDs alone: non-empty enables, empty disables.
            $enabled = count($userIds) > 0;
        }

        if (!$enabled) {
            return ['enabled' => false, 'user_ids' => []];
        }

        return ['enabled' => true, 'user_ids' => $userIds];
    }

    /**
     * @param array{enabled: bool, user_ids: array<int, int>} $plan
     */
    private static function assertMuhasebeYetkiPlanValid(PDO $pdo, array $plan): void
    {
        if (!$plan['enabled']) {
            if (!SubeMuhasebeYetkiSchema::isReady($pdo)) {
                // Clearing a restriction that cannot exist is a no-op once schema lands.
                return;
            }

            return;
        }

        if (!SubeMuhasebeYetkiSchema::isReady($pdo)) {
            throw OrganizasyonException::conflict(
                'SUBE_MUHASEBE_YETKILERI_TABLE_MISSING',
                'Şube muhasebe yetkilisi kısıtı bu ortamda henüz kullanılamaz.'
            );
        }

        if (count($plan['user_ids']) === 0) {
            throw OrganizasyonException::validation(
                'Muhasebe kısıtı açıkken en az bir muhasebe yetkilisi seçilmelidir.',
                'muhasebe_yetkili_user_ids'
            );
        }

        $placeholders = implode(', ', array_fill(0, count($plan['user_ids']), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, rol, durum FROM users WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_values($plan['user_ids']));
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $rol = strtoupper(trim((string) ($row['rol'] ?? '')));
            $durum = strtoupper(trim((string) ($row['durum'] ?? '')));
            if ($id <= 0 || $rol !== 'MUHASEBE' || $durum !== 'AKTIF') {
                throw OrganizasyonException::validation(
                    'Yalnız aktif MUHASEBE kullanıcıları seçilebilir.',
                    'muhasebe_yetkili_user_ids'
                );
            }
            $found[$id] = true;
        }

        foreach ($plan['user_ids'] as $userId) {
            if (!isset($found[$userId])) {
                throw OrganizasyonException::validation(
                    'Yalnız aktif MUHASEBE kullanıcıları seçilebilir.',
                    'muhasebe_yetkili_user_ids'
                );
            }
        }
    }

    /**
     * Attach durable manager-responsibility payloads. Missing schema → zero managers.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function attachSorumluYoneticiPayloads(PDO $pdo, array $items): array
    {
        if (count($items) === 0) {
            return $items;
        }

        $map = [];
        if (SubeSorumluYoneticiSchema::isReady($pdo)) {
            $subeIds = [];
            foreach ($items as $item) {
                $subeId = (int) ($item['id'] ?? 0);
                if ($subeId > 0) {
                    $subeIds[] = $subeId;
                }
            }
            $map = SubeSorumluYoneticiSchema::loadSubeUserMap($pdo, $subeIds);
        }

        foreach ($items as &$item) {
            $subeId = (int) ($item['id'] ?? 0);
            $userIds = $map[$subeId] ?? [];
            $item['sorumlu_yonetici_user_ids'] = $userIds;
            $item['sorumlu_yoneticiler'] = self::loadSorumluYoneticiStatuses($pdo, $userIds);
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private static function loadSorumluYoneticiStatuses(PDO $pdo, array $userIds): array
    {
        if (count($userIds) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, username, ad_soyad, rol, durum
             FROM users
             WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_values($userIds));
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rol = strtoupper(trim((string) ($row['rol'] ?? '')));
            $durum = strtoupper(trim((string) ($row['durum'] ?? '')));
            $byId[$id] = [
                'id' => $id,
                'username' => (string) ($row['username'] ?? ''),
                'ad_soyad' => (string) ($row['ad_soyad'] ?? ''),
                'rol' => $rol,
                'durum' => $durum,
                'eligible' => $durum === 'AKTIF' && SubeSorumluYoneticiSchema::isEligibleRole($rol),
            ];
        }

        $ordered = [];
        foreach ($userIds as $userId) {
            if (isset($byId[$userId])) {
                $ordered[] = $byId[$userId];
                continue;
            }
            $ordered[] = [
                'id' => $userId,
                'username' => '',
                'ad_soyad' => '',
                'rol' => '',
                'durum' => '',
                'eligible' => false,
            ];
        }

        return $ordered;
    }

    /**
     * null = payload omitted (preserve existing managers).
     * Present array (including empty) replaces the assignment set.
     *
     * @param array<string, mixed> $body
     * @return array<int, int>|null
     */
    private static function parseSorumluYoneticiPlan(array $body): ?array
    {
        if (!array_key_exists('sorumlu_yonetici_user_ids', $body)) {
            return null;
        }

        return self::parseDepartmanIds($body['sorumlu_yonetici_user_ids']);
    }

    /**
     * @param array<int, int> $userIds
     */
    private static function assertSorumluYoneticiPlanValid(PDO $pdo, array $userIds): void
    {
        if (!SubeSorumluYoneticiSchema::isReady($pdo)) {
            throw OrganizasyonException::conflict(
                'SUBE_SORUMLU_YONETICILER_TABLE_MISSING',
                'Şube sorumlu yönetici ataması bu ortamda henüz kullanılamaz.'
            );
        }

        if (count($userIds) === 0) {
            // Zero managers is a valid product state.
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, rol, durum FROM users WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_values($userIds));
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $rol = strtoupper(trim((string) ($row['rol'] ?? '')));
            $durum = strtoupper(trim((string) ($row['durum'] ?? '')));
            if ($id <= 0 || $durum !== 'AKTIF' || !SubeSorumluYoneticiSchema::isEligibleRole($rol)) {
                throw OrganizasyonException::validation(
                    'Sorumlu yönetici yalnız uygun aktif kullanıcılar arasından seçilebilir.',
                    'sorumlu_yonetici_user_ids'
                );
            }
            $found[$id] = true;
        }

        foreach ($userIds as $userId) {
            if (!isset($found[$userId])) {
                throw OrganizasyonException::validation(
                    'Sorumlu yönetici yalnız uygun aktif kullanıcılar arasından seçilebilir.',
                    'sorumlu_yonetici_user_ids'
                );
            }
        }
    }

    /** @param array<string, mixed> $params */
    private static function countWhere(PDO $pdo, string $sql, array $params): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
