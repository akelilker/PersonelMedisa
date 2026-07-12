<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\BildirimPuantajEtkiProjectionService;
use PDO;

class BildirimPuantajEtkiAdaylariController
{
    private const TABLE = 'onayli_bildirim_puantaj_etki_adaylari';
    private const MAX_LIMIT = 250;

    public static function summary(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.bildirim_etki.view');

        $gyId = self::requireGyId($request->getQuery('genel_yonetici_bildirim_onayi_id'));
        $pdo = self::connection();
        self::assertTablesReady($pdo);

        $gy = self::fetchGyById($pdo, $gyId);
        if (!$gy) {
            JsonResponse::success(self::emptySummaryPayload($gyId));
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $gy['sube_id']);
        JsonResponse::success(self::buildSummaryPayload($pdo, $gy));
    }

    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.bildirim_etki.view');

        [$ay] = self::resolveMonth(trim((string) $request->getQuery('ay', '')));
        $subeId = self::requireScope($user, $request);
        $amirId = self::requireAmirId($request->getQuery('birim_amiri_user_id'));

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->getQuery('limit', 20) ?: 20)));

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        self::assertAmirScope($pdo, $subeId, $amirId);

        $gy = self::fetchGyByContext($pdo, $subeId, $amirId, $ay);
        if (!$gy) {
            JsonResponse::success(['items' => []], self::emptyPagination($page, $limit));
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $gy['sube_id']);

        $where = ['a.genel_yonetici_bildirim_onayi_id = :gy_id'];
        $params = ['gy_id' => (int) $gy['id']];

        $personelId = self::positiveInt($request->getQuery('personel_id'));
        if ($personelId !== null) {
            $where[] = 'a.personel_id = :personel_id';
            $params['personel_id'] = $personelId;
        }

        $state = strtoupper(trim((string) $request->getQuery('state', '')));
        if ($state !== '') {
            $where[] = 'a.state = :state';
            $params['state'] = $state;
        }

        $etkiTuru = strtoupper(trim((string) $request->getQuery('etki_turu', '')));
        if ($etkiTuru !== '') {
            $where[] = 'a.etki_turu = :etki_turu';
            $params['etki_turu'] = $etkiTuru;
        }

        $whereSql = implode(' AND ', $where);
        $fromSql = ' FROM ' . self::TABLE . ' a WHERE ' . $whereSql;

        try {
            $total = self::countRows($pdo, $fromSql, $params);
            $offset = ($page - 1) * $limit;
            $stmt = $pdo->prepare(
                'SELECT a.*' . $fromSql . ' ORDER BY a.tarih ASC, a.id ASC LIMIT :limit OFFSET :offset'
            );
            self::bindParams($stmt, $params);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            JsonResponse::serverError('Puantaj etki adaylari listelenemedi.');
        }

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapListRow($row);
        }

        JsonResponse::success(
            ['items' => $items],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
                'has_next_page' => $page * $limit < $total,
                'has_prev_page' => $page > 1,
            ]
        );
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.bildirim_etki.view');

        $adayId = self::positiveInt($id);
        if ($adayId === null) {
            JsonResponse::notFound('Puantaj etki adayi bulunamadi.');
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        $row = self::fetchAdayById($pdo, $adayId);
        if (!$row) {
            JsonResponse::notFound('Puantaj etki adayi bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $row['sube_id']);
        JsonResponse::success(self::mapDetailRow($row));
    }

    public static function generate(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.bildirim_etki.generate');

        $body = $request->getJsonBody();
        $gyId = self::positiveInt($body['genel_yonetici_bildirim_onayi_id'] ?? null);
        if ($gyId === null) {
            self::validationError('genel_yonetici_bildirim_onayi_id', 'Genel yonetici bildirim onayi secilmelidir.');
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);

        try {
            $pdo->beginTransaction();

            $gy = self::fetchGyById($pdo, $gyId, true);
            if (!$gy) {
                self::rollbackValidation($pdo, 'GENEL_YONETICI_ONAYI_GEREKLI', 'Genel yonetici bildirim onayi bulunamadi.');
            }

            SubeScope::assertPersonelAccess($user, $request, (int) $gy['sube_id']);
            self::assertGyReadyForGenerate($pdo, $gy);

            $sources = self::fetchEligibleSources($pdo, $gy);
            if (count($sources) === 0) {
                self::rollbackValidation($pdo, 'ONAYLI_GUNLUK_BILDIRIM_BULUNAMADI', 'Onayli gunluk bildirim bulunamadi.');
            }

            $existingIds = self::fetchExistingGunlukIds($pdo, $gyId);
            $createdCount = 0;
            $dayGroups = self::groupSourcesByPersonelDate($sources);
            $dayConflicts = [];
            foreach ($dayGroups as $group) {
                $dayConflicts = array_replace($dayConflicts, BildirimPuantajEtkiProjectionService::evaluateDayCompatibility($group));
            }

            $puantajMap = self::fetchPuantajIdMap($pdo, $sources);
            $surecMap = self::fetchResmiSurecMap($pdo, $sources);

            $chain = [
                'genel_yonetici_bildirim_onayi_id' => (int) $gy['id'],
                'aylik_bildirim_onayi_id' => (int) $gy['aylik_bildirim_onayi_id'],
                'birim_amiri_user_id' => (int) $gy['birim_amiri_user_id'],
            ];

            foreach ($sources as $bildirim) {
                $gunlukId = (int) $bildirim['id'];
                if (isset($existingIds[$gunlukId])) {
                    continue;
                }

                $personelId = (int) $bildirim['personel_id'];
                $tarih = (string) $bildirim['tarih'];
                $dayKey = $personelId . '|' . $tarih;
                $dayRows = $dayGroups[$dayKey] ?? [$bildirim];
                $dayTurleri = array_map(static function (array $row) {
                    return (string) $row['bildirim_turu'];
                }, $dayRows);

                $context = [
                    'has_puantaj_row' => isset($puantajMap[$dayKey]),
                    'multi_conflict_code' => $dayConflicts[$gunlukId] ?? '',
                    'day_bildirim_turleri' => $dayTurleri,
                    'resmi_surecler' => $surecMap[$dayKey] ?? [],
                ];

                $projection = BildirimPuantajEtkiProjectionService::projectCandidate($bildirim, $context);
                $matchedSurec = isset($projection['matched_surec']) ? $projection['matched_surec'] : null;
                unset($projection['matched_surec']);

                $puantajOzet = isset($puantajMap[$dayKey])
                    ? ['id' => $puantajMap[$dayKey], 'personel_id' => $personelId, 'tarih' => $tarih]
                    : null;
                $snapshot = BildirimPuantajEtkiProjectionService::buildSourceSnapshot(
                    $bildirim,
                    $chain,
                    $puantajOzet,
                    $matchedSurec
                );
                $hash = BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot);

                try {
                    self::insertAday(
                        $pdo,
                        $gy,
                        $bildirim,
                        $projection,
                        $snapshot,
                        $hash,
                        self::userId($user),
                        isset($puantajMap[$dayKey]) ? (int) $puantajMap[$dayKey] : null,
                        $matchedSurec
                    );
                    $createdCount += 1;
                } catch (\PDOException $e) {
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                JsonResponse::error(409, 'CONFLICT', 'Puantaj etki adayi zaten mevcut.');
            }
            JsonResponse::serverError('Puantaj etki adaylari olusturulamadi.');
        }

        $existingCount = count(self::fetchExistingGunlukIds($pdo, $gyId));
        $stateCounts = self::fetchAdayCounts($pdo, $gyId);
        $statusCode = $createdCount > 0 ? 201 : 200;

        JsonResponse::success([
            'genel_yonetici_bildirim_onayi_id' => $gyId,
            'source_count' => count($sources),
            'created_count' => $createdCount,
            'existing_count' => $existingCount,
            'hazir_count' => $stateCounts['hazir'],
            'inceleme_gerekli_count' => $stateCounts['inceleme_gerekli'],
        ], [], $statusCode);
    }

    /** @return PDO */
    private static function connection()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    private static function assertTablesReady(PDO $pdo)
    {
        foreach ([self::TABLE, 'genel_yonetici_bildirim_onaylari', 'gunluk_bildirimler'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
            if (!$stmt || !$stmt->fetch()) {
                JsonResponse::serverError('Puantaj etki adayi migration uygulanmadi.');
            }
        }
    }

    /** @param array<string, mixed> $gy */
    private static function buildSummaryPayload(PDO $pdo, array $gy)
    {
        [$ayBaslangic, $ayBitis] = self::monthBounds((string) $gy['ay']);
        $yil = (int) substr((string) $gy['ay'], 0, 4);
        $ay = (int) substr((string) $gy['ay'], 5, 2);
        $muhur = self::findMonthlySeal($pdo, (int) $gy['sube_id'], $yil, $ay);
        $sourceCount = self::countEligibleSources($pdo, $gy);
        $counts = self::fetchAdayCounts($pdo, (int) $gy['id']);
        [$hazirlanabilir, $blokNedeni] = self::resolveGenerateAvailability($gy, $sourceCount, $muhur !== false);

        return [
            'context' => [
                'genel_yonetici_bildirim_onayi_id' => (int) $gy['id'],
                'ay' => (string) $gy['ay'],
                'ay_baslangic' => $ayBaslangic,
                'ay_bitis' => $ayBitis,
                'sube_id' => (int) $gy['sube_id'],
                'birim_amiri_user_id' => (int) $gy['birim_amiri_user_id'],
                'aylik_bildirim_onayi_id' => (int) $gy['aylik_bildirim_onayi_id'],
                'onaylandi_at' => $gy['onaylandi_at'],
            ],
            'genel_yonetici_bildirim_onayi' => self::mapGySummary($gy),
            'kaynak_bildirim_sayisi' => $sourceCount,
            'aday_sayilari' => $counts,
            'muhur_durumu' => $muhur ? 'MUHURLENDI' : 'ACIK',
            'hazirlanabilir_mi' => $hazirlanabilir,
            'blok_nedeni' => $blokNedeni,
        ];
    }

    /** @return array<string, mixed> */
    private static function emptySummaryPayload($gyId)
    {
        return [
            'context' => [
                'genel_yonetici_bildirim_onayi_id' => (int) $gyId,
                'ay' => null,
                'ay_baslangic' => null,
                'ay_bitis' => null,
                'sube_id' => null,
                'birim_amiri_user_id' => null,
                'aylik_bildirim_onayi_id' => null,
                'onaylandi_at' => null,
            ],
            'genel_yonetici_bildirim_onayi' => null,
            'kaynak_bildirim_sayisi' => 0,
            'aday_sayilari' => self::emptyAdayCounts(),
            'muhur_durumu' => 'ACIK',
            'hazirlanabilir_mi' => false,
            'blok_nedeni' => 'GENEL_YONETICI_ONAYI_GEREKLI',
        ];
    }

    /** @param array<string, mixed> $gy */
    private static function assertGyReadyForGenerate(PDO $pdo, array $gy)
    {
        if (strtoupper(trim((string) $gy['state'])) !== 'TAMAMLANDI') {
            self::rollbackValidation($pdo, 'GENEL_YONETICI_ONAYI_TAMAMLANMADI', 'Genel yonetici bildirim onayi tamamlanmamis.');
        }

        $aylik = self::fetchAylikOnayById($pdo, (int) $gy['aylik_bildirim_onayi_id']);
        if (!$aylik) {
            self::rollbackValidation($pdo, 'AYLIK_BILDIRIM_ONAYI_GEREKLI', 'Aylik bildirim onayi bulunamadi.');
        }
        if (strtoupper(trim((string) $aylik['state'])) !== 'TAMAMLANDI') {
            self::rollbackValidation($pdo, 'AYLIK_BILDIRIM_ONAYI_GEREKLI', 'Aylik bildirim onayi tamamlanmamis.');
        }
        if ((int) $aylik['sube_id'] !== (int) $gy['sube_id']
            || (int) $aylik['birim_amiri_user_id'] !== (int) $gy['birim_amiri_user_id']
            || (string) $aylik['ay'] !== (string) $gy['ay']) {
            self::rollbackValidation($pdo, 'AYLIK_BILDIRIM_ONAYI_GEREKLI', 'Aylik bildirim onayi baglami uyusmuyor.');
        }

        $yil = (int) substr((string) $gy['ay'], 0, 4);
        $ay = (int) substr((string) $gy['ay'], 5, 2);
        if (self::findMonthlySeal($pdo, (int) $gy['sube_id'], $yil, $ay)) {
            self::rollbackConflict($pdo, 'PERIOD_LOCKED', 'Bu donem muhurlenmis, puantaj etki adayi olusturulamaz.');
        }
    }

    /** @return array<string, int> */
    private static function emptyAdayCounts()
    {
        return [
            'toplam' => 0,
            'hazir' => 0,
            'inceleme_gerekli' => 0,
            'uygulandi' => 0,
            'yok_sayildi' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyPagination($page, $limit)
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => 0,
            'total_pages' => 1,
            'has_next_page' => false,
            'has_prev_page' => false,
        ];
    }

    /** @param array<string, mixed> $gy @return array{0: bool, 1: string|null} */
    private static function resolveGenerateAvailability(array $gy, $sourceCount, $muhurVar)
    {
        if ($muhurVar) {
            return [false, 'PERIOD_LOCKED'];
        }
        if (strtoupper(trim((string) $gy['state'])) !== 'TAMAMLANDI') {
            return [false, 'GENEL_YONETICI_ONAYI_TAMAMLANMADI'];
        }
        if ($sourceCount < 1) {
            return [false, 'ONAYLI_GUNLUK_BILDIRIM_BULUNAMADI'];
        }

        return [true, null];
    }

    /** @param array<string, mixed> $gy */
    private static function mapGySummary(array $gy)
    {
        return [
            'id' => (int) $gy['id'],
            'state' => (string) $gy['state'],
            'onaylandi_at' => $gy['onaylandi_at'],
            'aylik_bildirim_onayi_id' => (int) $gy['aylik_bildirim_onayi_id'],
        ];
    }

    /** @param array<string, mixed> $params */
    private static function countRows(PDO $pdo, $fromSql, array $params)
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS total ' . $fromSql);
        self::bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0);
    }

    /** @param array<string, mixed> $params */
    private static function bindParams(\PDOStatement $stmt, array $params)
    {
        foreach ($params as $key => $value) {
            if ($value === null) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function mapListRow(array $row)
    {
        return array_merge([
            'id' => (int) $row['id'],
            'genel_yonetici_bildirim_onayi_id' => (int) $row['genel_yonetici_bildirim_onayi_id'],
            'gunluk_bildirim_id' => (int) $row['gunluk_bildirim_id'],
            'personel_id' => (int) $row['personel_id'],
            'sube_id' => (int) $row['sube_id'],
            'birim_amiri_user_id' => (int) $row['birim_amiri_user_id'],
            'ay' => (string) $row['ay'],
            'tarih' => (string) $row['tarih'],
            'bildirim_turu' => (string) $row['bildirim_turu'],
            'etki_turu' => (string) $row['etki_turu'],
            'etki_miktari' => $row['etki_miktari'] !== null ? (int) $row['etki_miktari'] : null,
            'etki_birimi' => $row['etki_birimi'] !== null ? (string) $row['etki_birimi'] : null,
            'state' => (string) $row['state'],
            'conflict_code' => $row['conflict_code'] !== null ? (string) $row['conflict_code'] : null,
            'source_priority' => (string) $row['source_priority'],
            'created_at' => (string) $row['created_at'],
        ], self::mapKararListFields($row));
    }

    /** @param array<string, mixed> $row */
    private static function mapDetailRow(array $row)
    {
        return array_merge([
            'id' => (int) $row['id'],
            'genel_yonetici_bildirim_onayi_id' => (int) $row['genel_yonetici_bildirim_onayi_id'],
            'aylik_bildirim_onayi_id' => (int) $row['aylik_bildirim_onayi_id'],
            'gunluk_bildirim_id' => (int) $row['gunluk_bildirim_id'],
            'sube_id' => (int) $row['sube_id'],
            'birim_amiri_user_id' => (int) $row['birim_amiri_user_id'],
            'ay' => (string) $row['ay'],
            'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'],
            'bildirim_turu' => (string) $row['bildirim_turu'],
            'bildirim_alt_tur' => $row['bildirim_alt_tur'] !== null ? (string) $row['bildirim_alt_tur'] : null,
            'bildirim_dakika' => $row['bildirim_dakika'] !== null ? (int) $row['bildirim_dakika'] : null,
            'bildirim_aciklama' => $row['bildirim_aciklama'] !== null ? (string) $row['bildirim_aciklama'] : null,
            'bildirim_created_at' => (string) $row['bildirim_created_at'],
            'bildirim_updated_at' => (string) $row['bildirim_updated_at'],
            'etki_turu' => (string) $row['etki_turu'],
            'etki_miktari' => $row['etki_miktari'] !== null ? (int) $row['etki_miktari'] : null,
            'etki_birimi' => $row['etki_birimi'] !== null ? (string) $row['etki_birimi'] : null,
            'state' => (string) $row['state'],
            'conflict_code' => $row['conflict_code'] !== null ? (string) $row['conflict_code'] : null,
            'conflict_detail' => self::decodeJsonField($row['conflict_detail'] ?? null),
            'resmi_surec_id' => $row['resmi_surec_id'] !== null ? (int) $row['resmi_surec_id'] : null,
            'resmi_surec_turu' => $row['resmi_surec_turu'] !== null ? (string) $row['resmi_surec_turu'] : null,
            'resmi_surec_alt_tur' => $row['resmi_surec_alt_tur'] !== null ? (string) $row['resmi_surec_alt_tur'] : null,
            'ucretli_mi_snapshot' => $row['ucretli_mi_snapshot'] !== null ? (bool) ((int) $row['ucretli_mi_snapshot']) : null,
            'mevcut_puantaj_id' => $row['mevcut_puantaj_id'] !== null ? (int) $row['mevcut_puantaj_id'] : null,
            'source_priority' => (string) $row['source_priority'],
            'created_by' => (int) $row['created_by'],
            'source_snapshot' => self::decodeJsonField($row['source_snapshot'] ?? null),
            'source_hash' => $row['source_hash'] !== null ? (string) $row['source_hash'] : null,
            'projection_version' => $row['projection_version'] !== null ? (string) $row['projection_version'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ], self::mapKararDetailFields($row));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapKararListFields(array $row)
    {
        return [
            'karar_veren_user_id' => $row['karar_veren_user_id'] !== null ? (int) $row['karar_veren_user_id'] : null,
            'karar_zamani' => $row['karar_zamani'] !== null ? (string) $row['karar_zamani'] : null,
            'uygulanan_puantaj_id' => $row['uygulanan_puantaj_id'] !== null ? (int) $row['uygulanan_puantaj_id'] : null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapKararDetailFields(array $row)
    {
        return [
            'karar_veren_user_id' => $row['karar_veren_user_id'] !== null ? (int) $row['karar_veren_user_id'] : null,
            'karar_zamani' => $row['karar_zamani'] !== null ? (string) $row['karar_zamani'] : null,
            'karar_gerekcesi' => $row['karar_gerekcesi'] !== null ? (string) $row['karar_gerekcesi'] : null,
            'uygulanan_puantaj_id' => $row['uygulanan_puantaj_id'] !== null ? (int) $row['uygulanan_puantaj_id'] : null,
            'onceki_puantaj_snapshot' => self::decodeJsonField($row['onceki_puantaj_snapshot'] ?? null),
            'sonraki_puantaj_snapshot' => self::decodeJsonField($row['sonraki_puantaj_snapshot'] ?? null),
            'uygulama_hash' => $row['uygulama_hash'] !== null ? (string) $row['uygulama_hash'] : null,
        ];
    }

    /** @return mixed */
    private static function decodeJsonField($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $gy
     * @param array<string, mixed> $bildirim
     * @param array<string, mixed> $projection
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed>|null $matchedSurec
     */
    private static function insertAday(
        PDO $pdo,
        array $gy,
        array $bildirim,
        array $projection,
        array $snapshot,
        $hash,
        $userId,
        $mevcutPuantajId,
        $matchedSurec
    ) {
        $stmt = $pdo->prepare('
            INSERT INTO ' . self::TABLE . ' (
                genel_yonetici_bildirim_onayi_id, aylik_bildirim_onayi_id, gunluk_bildirim_id,
                sube_id, birim_amiri_user_id, ay, personel_id, tarih,
                bildirim_turu, bildirim_alt_tur, bildirim_dakika, bildirim_aciklama,
                bildirim_created_at, bildirim_updated_at,
                etki_turu, etki_miktari, etki_birimi, state, conflict_code, conflict_detail,
                resmi_surec_id, resmi_surec_turu, resmi_surec_alt_tur, ucretli_mi_snapshot,
                mevcut_puantaj_id, source_priority, created_by,
                source_snapshot, source_hash, projection_version
            ) VALUES (
                :genel_yonetici_bildirim_onayi_id, :aylik_bildirim_onayi_id, :gunluk_bildirim_id,
                :sube_id, :birim_amiri_user_id, :ay, :personel_id, :tarih,
                :bildirim_turu, :bildirim_alt_tur, :bildirim_dakika, :bildirim_aciklama,
                :bildirim_created_at, :bildirim_updated_at,
                :etki_turu, :etki_miktari, :etki_birimi, :state, :conflict_code, :conflict_detail,
                :resmi_surec_id, :resmi_surec_turu, :resmi_surec_alt_tur, :ucretli_mi_snapshot,
                :mevcut_puantaj_id, :source_priority, :created_by,
                :source_snapshot, :source_hash, :projection_version
            )
        ');
        $stmt->execute([
            'genel_yonetici_bildirim_onayi_id' => (int) $gy['id'],
            'aylik_bildirim_onayi_id' => (int) $gy['aylik_bildirim_onayi_id'],
            'gunluk_bildirim_id' => (int) $bildirim['id'],
            'sube_id' => (int) $bildirim['sube_id'],
            'birim_amiri_user_id' => (int) $gy['birim_amiri_user_id'],
            'ay' => (string) $gy['ay'],
            'personel_id' => (int) $bildirim['personel_id'],
            'tarih' => (string) $bildirim['tarih'],
            'bildirim_turu' => (string) $bildirim['bildirim_turu'],
            'bildirim_alt_tur' => $bildirim['alt_tur'] !== null ? (string) $bildirim['alt_tur'] : null,
            'bildirim_dakika' => $bildirim['dakika'] !== null && $bildirim['dakika'] !== '' ? (int) $bildirim['dakika'] : null,
            'bildirim_aciklama' => $bildirim['aciklama'] !== null ? (string) $bildirim['aciklama'] : null,
            'bildirim_created_at' => (string) $bildirim['created_at'],
            'bildirim_updated_at' => (string) $bildirim['updated_at'],
            'etki_turu' => (string) $projection['etki_turu'],
            'etki_miktari' => $projection['etki_miktari'],
            'etki_birimi' => $projection['etki_birimi'],
            'state' => (string) $projection['state'],
            'conflict_code' => $projection['conflict_code'],
            'conflict_detail' => $projection['conflict_detail'] !== null
                ? json_encode($projection['conflict_detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'resmi_surec_id' => $matchedSurec !== null ? (int) $matchedSurec['id'] : null,
            'resmi_surec_turu' => $matchedSurec !== null ? (string) $matchedSurec['surec_turu'] : null,
            'resmi_surec_alt_tur' => $matchedSurec !== null && $matchedSurec['alt_tur'] !== null
                ? (string) $matchedSurec['alt_tur']
                : null,
            'ucretli_mi_snapshot' => $matchedSurec !== null ? ((int) $matchedSurec['ucretli_mi']) : null,
            'mevcut_puantaj_id' => $mevcutPuantajId,
            'source_priority' => BildirimPuantajEtkiProjectionService::SOURCE_PRIORITY_BILDIRIM,
            'created_by' => $userId,
            'source_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_hash' => $hash,
            'projection_version' => BildirimPuantajEtkiProjectionService::PROJECTION_VERSION,
        ]);
    }

    /** @param array<int, array<string, mixed>> $sources @return array<string, array<int, array<string, mixed>>> */
    private static function groupSourcesByPersonelDate(array $sources)
    {
        $groups = [];
        foreach ($sources as $row) {
            $key = (int) $row['personel_id'] . '|' . (string) $row['tarih'];
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        return $groups;
    }

    /** @param array<int, array<string, mixed>> $sources @return array<string, int> */
    private static function fetchPuantajIdMap(PDO $pdo, array $sources)
    {
        if (count($sources) === 0) {
            return [];
        }

        $clauses = [];
        $params = [];
        foreach ($sources as $index => $row) {
            $clauses[] = '(personel_id = :p' . $index . ' AND tarih = :t' . $index . ')';
            $params['p' . $index] = (int) $row['personel_id'];
            $params['t' . $index] = (string) $row['tarih'];
        }

        $stmt = $pdo->prepare(
            'SELECT id, personel_id, tarih FROM gunluk_puantaj WHERE ' . implode(' OR ', $clauses)
        );
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['personel_id'] . '|' . (string) $row['tarih']] = (int) $row['id'];
        }

        return $map;
    }

    /** @param array<int, array<string, mixed>> $sources @return array<string, array<int, array<string, mixed>>> */
    private static function fetchResmiSurecMap(PDO $pdo, array $sources)
    {
        if (count($sources) === 0) {
            return [];
        }

        $personelIds = [];
        $minDate = null;
        $maxDate = null;
        foreach ($sources as $row) {
            $personelIds[(int) $row['personel_id']] = true;
            $tarih = (string) $row['tarih'];
            if ($minDate === null || $tarih < $minDate) {
                $minDate = $tarih;
            }
            if ($maxDate === null || $tarih > $maxDate) {
                $maxDate = $tarih;
            }
        }

        $ids = array_keys($personelIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('
            SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, ucretli_mi, state
            FROM surecler
            WHERE personel_id IN (' . $placeholders . ')
              AND state = ?
              AND baslangic_tarihi <= ?
              AND (bitis_tarihi IS NULL OR bitis_tarihi >= ?)
        ');
        $stmt->execute(array_merge($ids, ['AKTIF', $maxDate, $minDate]));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $surec) {
            $personelId = (int) $surec['personel_id'];
            foreach ($sources as $row) {
                if ((int) $row['personel_id'] !== $personelId) {
                    continue;
                }
                $tarih = (string) $row['tarih'];
                if (!self::surecCoversDate($surec, $tarih)) {
                    continue;
                }
                $key = $personelId . '|' . $tarih;
                if (!isset($map[$key])) {
                    $map[$key] = [];
                }
                $map[$key][] = $surec;
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $surec */
    private static function surecCoversDate(array $surec, $tarih)
    {
        $baslangic = (string) $surec['baslangic_tarihi'];
        $bitis = $surec['bitis_tarihi'] !== null ? (string) $surec['bitis_tarihi'] : $baslangic;

        return $tarih >= $baslangic && $tarih <= $bitis;
    }

    /** @param array<string, mixed> $gy @return array<int, array<string, mixed>> */
    private static function fetchEligibleSources(PDO $pdo, array $gy)
    {
        [$ayBaslangic, $ayBitis] = self::monthBounds((string) $gy['ay']);
        $cutoff = (string) $gy['onaylandi_at'];

        $stmt = $pdo->prepare('
            SELECT *
            FROM gunluk_bildirimler
            WHERE sube_id = :sube_id
              AND created_by = :created_by
              AND tarih BETWEEN :ay_baslangic AND :ay_bitis
              AND state = :state
              AND haftalik_mutabakat_id IS NOT NULL
              AND created_at <= :cutoff_created
              AND updated_at <= :cutoff_updated
            ORDER BY tarih ASC, id ASC
        ');
        $stmt->execute([
            'sube_id' => (int) $gy['sube_id'],
            'created_by' => (int) $gy['birim_amiri_user_id'],
            'ay_baslangic' => $ayBaslangic,
            'ay_bitis' => $ayBitis,
            'state' => 'HAFTALIK_MUTABAKATA_ALINDI',
            'cutoff_created' => $cutoff,
            'cutoff_updated' => $cutoff,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $gy */
    private static function countEligibleSources(PDO $pdo, array $gy)
    {
        return count(self::fetchEligibleSources($pdo, $gy));
    }

    /** @return array<int, true> */
    private static function fetchExistingGunlukIds(PDO $pdo, $gyId)
    {
        $stmt = $pdo->prepare('
            SELECT gunluk_bildirim_id
            FROM ' . self::TABLE . '
            WHERE genel_yonetici_bildirim_onayi_id = :gy_id
        ');
        $stmt->execute(['gy_id' => (int) $gyId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['gunluk_bildirim_id']] = true;
        }

        return $map;
    }

    /** @return array<string, int> */
    private static function fetchAdayCounts(PDO $pdo, $gyId)
    {
        $stmt = $pdo->prepare('
            SELECT state, COUNT(*) AS total
            FROM ' . self::TABLE . '
            WHERE genel_yonetici_bildirim_onayi_id = :gy_id
            GROUP BY state
        ');
        $stmt->execute(['gy_id' => (int) $gyId]);
        $counts = self::emptyAdayCounts();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state = strtoupper(trim((string) $row['state']));
            $total = (int) $row['total'];
            $counts['toplam'] += $total;
            if ($state === 'HAZIR') {
                $counts['hazir'] = $total;
            } elseif ($state === 'INCELEME_GEREKLI') {
                $counts['inceleme_gerekli'] = $total;
            } elseif ($state === 'UYGULANDI') {
                $counts['uygulandi'] = $total;
            } elseif ($state === 'YOK_SAYILDI') {
                $counts['yok_sayildi'] = $total;
            }
        }

        return $counts;
    }

    /** @return array<string, mixed>|false */
    private static function fetchAdayById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    private static function fetchGyById(PDO $pdo, $id, $forUpdate = false)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM genel_yonetici_bildirim_onaylari
            WHERE id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => (int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private static function fetchGyByContext(PDO $pdo, $subeId, $amirId, $ay)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM genel_yonetici_bildirim_onaylari
            WHERE sube_id = :sube_id
              AND birim_amiri_user_id = :birim_amiri_user_id
              AND ay = :ay
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'birim_amiri_user_id' => (int) $amirId,
            'ay' => $ay,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|false */
    private static function fetchAylikOnayById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM aylik_bildirim_onaylari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false */
    private static function findMonthlySeal(PDO $pdo, $subeId, $yil, $ay)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM puantaj_aylik_muhurleri
            WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
            LIMIT 1
        ');
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array{0: string, 1: string} */
    private static function monthBounds($ay)
    {
        $year = (int) substr($ay, 0, 4);
        $month = (int) substr($ay, 5, 2);
        $baslangic = sprintf('%04d-%02d-01', $year, $month);
        $bitis = (new \DateTimeImmutable($baslangic))->modify('last day of this month')->format('Y-m-d');

        return [$baslangic, $bitis];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function resolveMonth($value)
    {
        $ay = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            self::validationError('ay', 'Ay parametresi YYYY-MM formatinda olmalidir.');
        }
        [$baslangic, $bitis] = self::monthBounds($ay);

        return [$ay, $baslangic, $bitis];
    }

    private static function requireGyId($value)
    {
        $gyId = self::positiveInt($value);
        if ($gyId === null) {
            self::validationError('genel_yonetici_bildirim_onayi_id', 'Genel yonetici bildirim onayi secilmelidir.');
        }

        return (int) $gyId;
    }

    /** @param array<string, mixed> $user */
    private static function requireScope(array $user, Request $request)
    {
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Puantaj etki adayi icin aktif sube secilmelidir.');
        }

        return (int) $scope;
    }

    private static function requireAmirId($value)
    {
        $amirId = self::positiveInt($value);
        if ($amirId === null) {
            self::validationError('birim_amiri_user_id', 'Birim amiri secimi zorunludur.');
        }

        return (int) $amirId;
    }

    private static function assertAmirScope(PDO $pdo, $subeId, $amirId)
    {
        $stmt = $pdo->prepare('
            SELECT 1
            FROM users u
            INNER JOIN user_subeler us ON us.user_id = u.id
            WHERE u.id = :user_id
              AND u.rol = :rol
              AND u.durum = :durum
              AND us.sube_id = :sube_id
            LIMIT 1
        ');
        $stmt->execute([
            'user_id' => (int) $amirId,
            'rol' => 'BIRIM_AMIRI',
            'durum' => 'AKTIF',
            'sube_id' => (int) $subeId,
        ]);
        if (!$stmt->fetchColumn()) {
            JsonResponse::forbidden('Secili birim amiri bu sube icin yetkili degil.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function userId(array $user)
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) {
            JsonResponse::unauthorized();
        }

        return $id;
    }

    private static function positiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 && (string) $id === trim((string) $value) ? $id : null;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    private static function conflict($code, $message)
    {
        JsonResponse::error(409, $code, $message);
    }

    private static function rollbackConflict(PDO $pdo, $code, $message)
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        self::conflict($code, $message);
    }

    private static function rollbackValidation(PDO $pdo, $code, $message)
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(422, $code, $message);
    }
}
