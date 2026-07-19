<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;
use PDOException;

/**
 * Haftalik kapanis snapshot owner (S79-B).
 *
 * Permissions (existing RolePermissions matrix — no new keys):
 * - POST create  → puantaj.muhurle
 * - GET detail   → puantaj.view
 * - GET yillik   → puantaj.view
 *
 * Mutabakat onkosulu:
 * - En az bir haftalik_bildirim_mutabakatlari satiri (sube_id, hafta_baslangic) ve hepsi TAMAMLANDI.
 * - Genel (departman_id null) ve departman kapanisinda: kapsamdaki IPTAL-disi bildirimler
 *   mutabakata bagli olmali; acik (TASLAK/GONDERILDI/DUZELTME_ISTENDI) bildirim kalmamali.
 * - IPTAL bildirimler blocker degildir.
 */
class HaftalikKapanisController
{
    private const KAYNAK_VERSIYON = 'A2_MOTOR_V1';
    private const HAFTALIK_ESIK_DAKIKA = 2700;
    private const YILLIK_LIMIT_DAKIKA = 16200;
    private const YILLIK_YAKLASMA_ESIK_DAKIKA = 15600;

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.muhurle');

        $subeId = self::requireScope($user, $request);
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            self::validationError(null, 'Gecerli JSON body zorunludur.');
        }

        self::rejectServerOwnedOverrides($body);

        [$haftaBaslangic, $haftaBitis] = self::resolveWeek(
            $body['hafta_baslangic'] ?? null,
            $body['hafta_bitis'] ?? null
        );

        $departmanId = null;
        if (array_key_exists('departman_id', $body) && $body['departman_id'] !== null && $body['departman_id'] !== '') {
            $departmanId = self::parsePositiveInt($body['departman_id']);
            if ($departmanId === null) {
                self::validationError('departman_id', 'departman_id pozitif tam sayi olmalidir.');
            }
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);

        if ($departmanId !== null) {
            self::assertDepartmanExists($pdo, $departmanId);
        }

        try {
            $pdo->beginTransaction();

            self::assertMutabakatReady($pdo, $subeId, $haftaBaslangic, $haftaBitis, $departmanId);

            $existing = self::fetchExistingForUpdate($pdo, $subeId, $haftaBaslangic, $departmanId);
            if ($existing) {
                self::rollbackConflict(
                    $pdo,
                    'Bu sube, hafta ve departman kapsami icin haftalik kapanis zaten olusturulmus.'
                );
            }

            $personeller = self::loadPersoneller($pdo, $subeId, $departmanId);
            $puantajByPersonel = self::loadPuantajForWeek($pdo, $personeller, $haftaBaslangic, $haftaBitis);

            $actorId = self::userId($user);
            $insert = $pdo->prepare('
                INSERT INTO haftalik_kapanislar (
                    sube_id, hafta_baslangic, hafta_bitis, departman_id,
                    state, personel_sayisi, snapshot_satir_sayisi,
                    kaynak_versiyon, created_by
                ) VALUES (
                    :sube_id, :hafta_baslangic, :hafta_bitis, :departman_id,
                    :state, :personel_sayisi, :snapshot_satir_sayisi,
                    :kaynak_versiyon, :created_by
                )
            ');
            $insert->execute([
                'sube_id' => $subeId,
                'hafta_baslangic' => $haftaBaslangic,
                'hafta_bitis' => $haftaBitis,
                'departman_id' => $departmanId,
                'state' => 'KAPANDI',
                'personel_sayisi' => count($personeller),
                'snapshot_satir_sayisi' => count($personeller),
                'kaynak_versiyon' => self::KAYNAK_VERSIYON,
                'created_by' => $actorId > 0 ? $actorId : null,
            ]);
            $kapanisId = (int) $pdo->lastInsertId();

            $satirlar = [];
            $index = 0;
            foreach ($personeller as $personel) {
                $satir = self::buildSnapshotSatir(
                    $kapanisId,
                    $index,
                    $personel,
                    $haftaBaslangic,
                    $haftaBitis,
                    $departmanId,
                    $puantajByPersonel[(int) $personel['id']] ?? []
                );
                self::insertSatir($pdo, $satir);
                $satirlar[] = self::mapSatirRow($satir, true);
                $index += 1;
            }

            $pdo->commit();

            JsonResponse::success([
                'id' => $kapanisId,
                'kapanis_id' => $kapanisId,
                'hafta_baslangic' => $haftaBaslangic,
                'hafta_bitis' => $haftaBitis,
                'departman_id' => $departmanId,
                'state' => 'KAPANDI',
                'personel_sayisi' => count($personeller),
                'snapshot_satir_sayisi' => count($satirlar),
                'snapshot_satirlari' => $satirlar,
            ], [], 201);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (self::isDuplicateScopeException($e)) {
                self::conflict(
                    'Bu sube, hafta ve departman kapsami icin haftalik kapanis zaten olusturulmus.'
                );
            }
            JsonResponse::serverError('Haftalik kapanis olusturulamadi.');
        }
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');

        $kapanisId = self::parsePositiveInt($id);
        if ($kapanisId === null) {
            JsonResponse::notFound('Haftalik kapanis bulunamadi.');
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);

        $header = self::fetchKapanis($pdo, $kapanisId);
        if (!$header) {
            JsonResponse::notFound('Haftalik kapanis bulunamadi.');
        }

        self::assertReadScope($user, $request, (int) $header['sube_id']);

        $satirlar = self::fetchSatirlar($pdo, $kapanisId);
        JsonResponse::success(self::mapKapanisResponse($header, $satirlar));
    }

    public static function yillikFazlaCalisma(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');

        $personelId = self::parsePositiveInt($request->getQuery('personel_id'));
        $yil = self::parsePositiveInt($request->getQuery('yil'));
        if ($personelId === null || $personelId < 1 || $yil === null || $yil < 1) {
            JsonResponse::badRequest(
                'personel_id ve yil zorunludur ve pozitif tam sayi olmalidir.',
                'INVALID_QUERY'
            );
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);

        $personel = self::fetchPersonelSube($pdo, $personelId);
        if (!$personel) {
            JsonResponse::notFound('Personel bulunamadi.');
        }
        self::assertReadScope($user, $request, (int) $personel['sube_id']);

        $ozet = self::aggregateYillik($pdo, $personelId, $yil, (int) $personel['sube_id']);
        JsonResponse::success($ozet);
    }

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
        foreach (['haftalik_kapanislar', 'haftalik_kapanis_satirlari', 'haftalik_bildirim_mutabakatlari'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
            if (!$stmt || !$stmt->fetch()) {
                JsonResponse::serverError('Haftalik kapanis migration uygulanmadi.');
            }
        }
    }

    private static function requireScope(array $user, Request $request)
    {
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Haftalik kapanis icin aktif sube secilmelidir.');
        }

        return (int) $scope;
    }

    private static function userId(array $user)
    {
        return isset($user['id']) ? (int) $user['id'] : 0;
    }

    private static function rejectServerOwnedOverrides(array $body)
    {
        foreach ([
            'id',
            'kapanis_id',
            'sube_id',
            'state',
            'created_at',
            'created_by',
            'departman_scope_key',
            'personel_sayisi',
            'snapshot_satir_sayisi',
            'kaynak_versiyon',
            'snapshot_satirlari',
        ] as $field) {
            if (array_key_exists($field, $body)) {
                self::validationError($field, $field . ' istemci tarafindan belirlenemez.');
            }
        }
    }

    /**
     * Read surfaces: SubeScope + empty allowedSubeIds must not leak for branch-only roles.
     * GENEL/MUHASEBE (personeller.view) may read globally when allowed is empty.
     *
     * @param array<string, mixed> $user
     */
    private static function assertReadScope(array $user, Request $request, $recordSubeId)
    {
        $allowed = SubeScope::allowedSubeIds($user);
        if (count($allowed) === 0 && !RolePermissions::has($user, 'personeller.view')) {
            JsonResponse::forbidden('Sube baglami olmadan haftalik kapanis goruntulenemez.');
        }
        SubeScope::assertPersonelAccess($user, $request, (int) $recordSubeId);
    }

    private static function isDuplicateScopeException(PDOException $e)
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode === 1062) {
            return true;
        }
        $message = strtolower($e->getMessage());

        return strpos($message, 'uq_haftalik_kapanis_scope') !== false;
    }

    private static function resolveWeek($baslangicRaw, $bitisRaw)
    {
        $baslangic = trim((string) $baslangicRaw);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $baslangic);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsed
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $baslangic
        ) {
            self::validationError('hafta_baslangic', 'Hafta baslangici YYYY-MM-DD formatinda olmalidir.');
        }
        if ($parsed->format('N') !== '1') {
            self::validationError('hafta_baslangic', 'Hafta baslangici Pazartesi olmalidir.');
        }

        $expectedBitis = $parsed->modify('+6 days')->format('Y-m-d');
        if ($bitisRaw !== null && trim((string) $bitisRaw) !== '') {
            $bitis = trim((string) $bitisRaw);
            if ($bitis !== $expectedBitis) {
                self::validationError(
                    'hafta_bitis',
                    'hafta_bitis hafta_baslangic + 6 gun olmalidir (' . $expectedBitis . ').'
                );
            }
        }

        return [$baslangic, $expectedBitis];
    }

    /**
     * Mutabakat onkosulu — exact kural:
     * 1) (sube_id, hafta_baslangic) icin en az bir mutabakat satiri olmali; yoksa 409.
     * 2) Tum mutabakat satirlarinda state = TAMAMLANDI olmali; aksi 409.
     * 3) Kapsamdaki (genel=tum sube / departman filtresi) IPTAL-disi gunluk_bildirimler
     *    haftalik_mutabakat_id dolu olmali; acik state kalmamali.
     * 4) IPTAL bildirimler blocker degildir.
     * 5) Baska departman/subenin acik bildirimi bu kapsami etkilemez.
     */
    private static function assertMutabakatReady(
        PDO $pdo,
        $subeId,
        $haftaBaslangic,
        $haftaBitis,
        $departmanId
    ) {
        $stmt = $pdo->prepare('
            SELECT id, state
            FROM haftalik_bildirim_mutabakatlari
            WHERE sube_id = :sube_id
              AND hafta_baslangic = :hafta_baslangic
            FOR UPDATE
        ');
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'hafta_baslangic' => $haftaBaslangic,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 1) {
            self::rollbackConflict($pdo, 'Haftalik mutabakat bulunamadi.');
        }
        foreach ($rows as $row) {
            if (strtoupper(trim((string) $row['state'])) !== 'TAMAMLANDI') {
                self::rollbackConflict($pdo, 'Haftalik mutabakat tamamlanmamis.');
            }
        }

        $deptSql = '';
        $params = [
            'sube_id' => (int) $subeId,
            'hafta_baslangic' => $haftaBaslangic,
            'hafta_bitis' => $haftaBitis,
        ];
        if ($departmanId !== null) {
            $deptSql = ' AND departman_id = :departman_id';
            $params['departman_id'] = (int) $departmanId;
        }

        $open = $pdo->prepare('
            SELECT COUNT(*) FROM gunluk_bildirimler
            WHERE sube_id = :sube_id
              AND tarih BETWEEN :hafta_baslangic AND :hafta_bitis
              AND state IN (\'TASLAK\', \'GONDERILDI\', \'DUZELTME_ISTENDI\')
            ' . $deptSql . '
        ');
        $open->execute($params);
        if ((int) $open->fetchColumn() > 0) {
            self::rollbackConflict(
                $pdo,
                $departmanId === null
                    ? 'Sube kapsaminda mutabakat tamamlanmamis.'
                    : 'Departman kapsaminda mutabakat tamamlanmamis.'
            );
        }

        $unlinked = $pdo->prepare('
            SELECT COUNT(*) FROM gunluk_bildirimler
            WHERE sube_id = :sube_id
              AND tarih BETWEEN :hafta_baslangic AND :hafta_bitis
              AND state <> \'IPTAL\'
              AND haftalik_mutabakat_id IS NULL
            ' . $deptSql . '
        ');
        $unlinked->execute($params);
        if ((int) $unlinked->fetchColumn() > 0) {
            self::rollbackConflict(
                $pdo,
                $departmanId === null
                    ? 'Sube kapsaminda mutabakat tamamlanmamis.'
                    : 'Departman kapsaminda mutabakat tamamlanmamis.'
            );
        }
    }

    private static function fetchExistingForUpdate(PDO $pdo, $subeId, $haftaBaslangic, $departmanId)
    {
        $scopeKey = $departmanId === null ? 0 : (int) $departmanId;
        $stmt = $pdo->prepare('
            SELECT id FROM haftalik_kapanislar
            WHERE sube_id = :sube_id
              AND hafta_baslangic = :hafta_baslangic
              AND departman_scope_key = :departman_scope_key
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'hafta_baslangic' => $haftaBaslangic,
            'departman_scope_key' => $scopeKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function assertDepartmanExists(PDO $pdo, $departmanId)
    {
        $stmt = $pdo->prepare('SELECT id FROM departmanlar WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $departmanId]);
        if (!$stmt->fetchColumn()) {
            self::validationError('departman_id', 'Departman bulunamadi.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function loadPersoneller(PDO $pdo, $subeId, $departmanId)
    {
        $sql = '
            SELECT id, departman_id, dogum_tarihi
            FROM personeller
            WHERE sube_id = :sube_id
              AND aktif_durum = \'AKTIF\'
        ';
        $params = ['sube_id' => (int) $subeId];
        if ($departmanId !== null) {
            $sql .= ' AND departman_id = :departman_id';
            $params['departman_id'] = (int) $departmanId;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int, array<string, mixed>> $personeller
     * @return array<int, array<string, array<string, mixed>>>
     */
    private static function loadPuantajForWeek(PDO $pdo, array $personeller, $haftaBaslangic, $haftaBitis)
    {
        if (count($personeller) === 0) {
            return [];
        }

        $ids = [];
        foreach ($personeller as $p) {
            $ids[] = (int) $p['id'];
        }
        $placeholders = [];
        $params = [
            'hafta_baslangic' => $haftaBaslangic,
            'hafta_bitis' => $haftaBitis,
        ];
        foreach ($ids as $i => $id) {
            $key = 'p' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $pdo->prepare('
            SELECT personel_id, tarih, net_calisma_suresi_dakika
            FROM gunluk_puantaj
            WHERE personel_id IN (' . implode(',', $placeholders) . ')
              AND tarih BETWEEN :hafta_baslangic AND :hafta_bitis
            ORDER BY personel_id, tarih
        ');
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int) $row['personel_id'];
            $tarih = (string) $row['tarih'];
            $out[$pid][$tarih] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $personel
     * @param array<string, array<string, mixed>> $gunlerByTarih
     * @return array<string, mixed>
     */
    private static function buildSnapshotSatir(
        $kapanisId,
        $index,
        array $personel,
        $haftaBaslangic,
        $haftaBitis,
        $departmanId,
        array $gunlerByTarih
    ) {
        $tarihler = self::listHaftaTarihleri($haftaBaslangic);
        $toplam = 0;
        $kaynakGun = 0;
        foreach ($tarihler as $tarih) {
            if (!isset($gunlerByTarih[$tarih])) {
                continue;
            }
            $kaynakGun += 1;
            $net = $gunlerByTarih[$tarih]['net_calisma_suresi_dakika'];
            $toplam += self::safeNonNegInt($net);
        }

        $tamHafta = $kaynakGun === 7;
        $normal = min($toplam, self::HAFTALIK_ESIK_DAKIKA);
        $fazla = max($toplam - self::HAFTALIK_ESIK_DAKIKA, 0);
        $iso = self::hesaplaIsoHaftaNo($haftaBaslangic);
        $yilFallback = (int) substr($haftaBaslangic, 0, 4);
        $notlar = $tamHafta
            ? null
            : [
                'Eksik haftalik puantaj gunu (' . $kaynakGun . '/7); UBGT ve 18 yas alti haftalik uyarilari uretilmedi.',
            ];

        $personelDepartman = $personel['departman_id'] !== null ? (int) $personel['departman_id'] : null;

        return [
            'snapshot_id' => ((int) $kapanisId) * 1000 + $index + 1,
            'kapanis_id' => (int) $kapanisId,
            'personel_id' => (int) $personel['id'],
            'departman_id' => $departmanId !== null ? (int) $departmanId : $personelDepartman,
            'hafta_baslangic' => $haftaBaslangic,
            'hafta_bitis' => $haftaBitis,
            'yil' => $iso['yil'] ?? $yilFallback,
            'hafta_no' => $iso['hafta_no'] ?? null,
            'state' => 'KAPANDI',
            'kaynak_versiyon' => self::KAYNAK_VERSIYON,
            'toplam_net_dakika' => $toplam,
            'normal_calisma_dakika' => $normal,
            'fazla_calisma_dakika' => $fazla,
            'fazla_surelerle_calisma_dakika' => 0,
            'tam_hafta_verisi' => $tamHafta ? 1 : 0,
            'compliance_uyarilari' => [],
            'compliance_uyari_sayisi' => 0,
            'kritik_uyari_var_mi' => 0,
            'hesaplama_zamani' => gmdate('Y-m-d H:i:s'),
            'kaynak_gun_sayisi' => $kaynakGun,
            'notlar' => $notlar,
        ];
    }

    /** @param array<string, mixed> $satir */
    private static function insertSatir(PDO $pdo, array $satir)
    {
        $stmt = $pdo->prepare('
            INSERT INTO haftalik_kapanis_satirlari (
                kapanis_id, personel_id, departman_id,
                hafta_baslangic, hafta_bitis, yil, hafta_no,
                state, kaynak_versiyon,
                toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika,
                fazla_surelerle_calisma_dakika, tam_hafta_verisi,
                compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
                hesaplama_zamani, kaynak_gun_sayisi, notlar_json
            ) VALUES (
                :kapanis_id, :personel_id, :departman_id,
                :hafta_baslangic, :hafta_bitis, :yil, :hafta_no,
                :state, :kaynak_versiyon,
                :toplam_net_dakika, :normal_calisma_dakika, :fazla_calisma_dakika,
                :fazla_surelerle_calisma_dakika, :tam_hafta_verisi,
                :compliance_uyarilari_json, :compliance_uyari_sayisi, :kritik_uyari_var_mi,
                :hesaplama_zamani, :kaynak_gun_sayisi, :notlar_json
            )
        ');
        $stmt->execute([
            'kapanis_id' => $satir['kapanis_id'],
            'personel_id' => $satir['personel_id'],
            'departman_id' => $satir['departman_id'],
            'hafta_baslangic' => $satir['hafta_baslangic'],
            'hafta_bitis' => $satir['hafta_bitis'],
            'yil' => $satir['yil'],
            'hafta_no' => $satir['hafta_no'],
            'state' => $satir['state'],
            'kaynak_versiyon' => $satir['kaynak_versiyon'],
            'toplam_net_dakika' => $satir['toplam_net_dakika'],
            'normal_calisma_dakika' => $satir['normal_calisma_dakika'],
            'fazla_calisma_dakika' => $satir['fazla_calisma_dakika'],
            'fazla_surelerle_calisma_dakika' => $satir['fazla_surelerle_calisma_dakika'],
            'tam_hafta_verisi' => $satir['tam_hafta_verisi'],
            'compliance_uyarilari_json' => json_encode($satir['compliance_uyarilari'], JSON_UNESCAPED_UNICODE),
            'compliance_uyari_sayisi' => $satir['compliance_uyari_sayisi'],
            'kritik_uyari_var_mi' => $satir['kritik_uyari_var_mi'],
            'hesaplama_zamani' => $satir['hesaplama_zamani'],
            'kaynak_gun_sayisi' => $satir['kaynak_gun_sayisi'],
            'notlar_json' => $satir['notlar'] === null
                ? null
                : json_encode($satir['notlar'], JSON_UNESCAPED_UNICODE),
        ]);
        $satir['id'] = (int) $pdo->lastInsertId();
    }

    private static function fetchKapanis(PDO $pdo, $kapanisId)
    {
        $stmt = $pdo->prepare('SELECT * FROM haftalik_kapanislar WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $kapanisId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function fetchSatirlar(PDO $pdo, $kapanisId)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM haftalik_kapanis_satirlari
            WHERE kapanis_id = :kapanis_id
            ORDER BY id ASC
        ');
        $stmt->execute(['kapanis_id' => (int) $kapanisId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<int, array<string, mixed>> $satirlar
     * @return array<string, mixed>
     */
    private static function mapKapanisResponse(array $header, array $satirlar)
    {
        $mapped = [];
        foreach ($satirlar as $satir) {
            $mapped[] = self::mapSatirRow($satir, false);
        }
        $id = (int) $header['id'];

        return [
            'id' => $id,
            'kapanis_id' => $id,
            'hafta_baslangic' => (string) $header['hafta_baslangic'],
            'hafta_bitis' => (string) $header['hafta_bitis'],
            'departman_id' => $header['departman_id'] !== null ? (int) $header['departman_id'] : null,
            'state' => (string) $header['state'],
            'personel_sayisi' => (int) $header['personel_sayisi'],
            'snapshot_satir_sayisi' => (int) $header['snapshot_satir_sayisi'],
            'snapshot_satirlari' => $mapped,
        ];
    }

    /**
     * @param array<string, mixed> $satir
     * @return array<string, mixed>
     */
    private static function mapSatirRow(array $satir, $fromBuilder)
    {
        if ($fromBuilder) {
            return [
                'snapshot_id' => $satir['snapshot_id'],
                'kapanis_id' => $satir['kapanis_id'],
                'personel_id' => $satir['personel_id'],
                'departman_id' => $satir['departman_id'],
                'hafta_baslangic' => $satir['hafta_baslangic'],
                'hafta_bitis' => $satir['hafta_bitis'],
                'yil' => $satir['yil'],
                'hafta_no' => $satir['hafta_no'],
                'state' => $satir['state'],
                'kaynak_versiyon' => $satir['kaynak_versiyon'],
                'toplam_net_dakika' => $satir['toplam_net_dakika'],
                'normal_calisma_dakika' => $satir['normal_calisma_dakika'],
                'fazla_calisma_dakika' => $satir['fazla_calisma_dakika'],
                'fazla_surelerle_calisma_dakika' => $satir['fazla_surelerle_calisma_dakika'],
                'tam_hafta_verisi' => (bool) $satir['tam_hafta_verisi'],
                'compliance_uyarilari' => $satir['compliance_uyarilari'],
                'compliance_uyari_sayisi' => $satir['compliance_uyari_sayisi'],
                'kritik_uyari_var_mi' => (bool) $satir['kritik_uyari_var_mi'],
                'hesaplama_zamani' => self::toIso8601((string) $satir['hesaplama_zamani']),
                'kaynak_gun_sayisi' => $satir['kaynak_gun_sayisi'],
                'notlar' => $satir['notlar'],
            ];
        }

        $compliance = json_decode((string) ($satir['compliance_uyarilari_json'] ?? '[]'), true);
        if (!is_array($compliance)) {
            $compliance = [];
        }
        $notlar = null;
        if ($satir['notlar_json'] !== null && $satir['notlar_json'] !== '') {
            $decoded = json_decode((string) $satir['notlar_json'], true);
            $notlar = is_array($decoded) ? $decoded : null;
        }

        $dbId = (int) $satir['id'];
        $kapanisId = (int) $satir['kapanis_id'];

        return [
            'snapshot_id' => $dbId,
            'kapanis_id' => $kapanisId,
            'personel_id' => (int) $satir['personel_id'],
            'departman_id' => $satir['departman_id'] !== null ? (int) $satir['departman_id'] : null,
            'hafta_baslangic' => (string) $satir['hafta_baslangic'],
            'hafta_bitis' => (string) $satir['hafta_bitis'],
            'yil' => $satir['yil'] !== null ? (int) $satir['yil'] : null,
            'hafta_no' => $satir['hafta_no'] !== null ? (int) $satir['hafta_no'] : null,
            'state' => (string) $satir['state'],
            'kaynak_versiyon' => (string) $satir['kaynak_versiyon'],
            'toplam_net_dakika' => (int) $satir['toplam_net_dakika'],
            'normal_calisma_dakika' => (int) $satir['normal_calisma_dakika'],
            'fazla_calisma_dakika' => (int) $satir['fazla_calisma_dakika'],
            'fazla_surelerle_calisma_dakika' => (int) $satir['fazla_surelerle_calisma_dakika'],
            'tam_hafta_verisi' => ((int) $satir['tam_hafta_verisi']) === 1,
            'compliance_uyarilari' => $compliance,
            'compliance_uyari_sayisi' => (int) $satir['compliance_uyari_sayisi'],
            'kritik_uyari_var_mi' => ((int) $satir['kritik_uyari_var_mi']) === 1,
            'hesaplama_zamani' => self::toIso8601((string) $satir['hesaplama_zamani']),
            'kaynak_gun_sayisi' => (int) $satir['kaynak_gun_sayisi'],
            'notlar' => $notlar,
        ];
    }

    private static function aggregateYillik(PDO $pdo, $personelId, $yil, $personelSubeId)
    {
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
            $satirYil = $row['yil'] !== null ? (int) $row['yil'] : (int) substr((string) $row['hafta_baslangic'], 0, 4);
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
            $kullanilan += self::safeNonNegInt($kazanan['fazla_calisma_dakika']);
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
    }

    private static function fetchPersonelSube(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id, sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<int, string> */
    private static function listHaftaTarihleri($haftaBaslangic)
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $haftaBaslangic);
        if (!$parsed) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $out[] = $parsed->modify('+' . $i . ' days')->format('Y-m-d');
        }

        return $out;
    }

    /** @return array{yil:int,hafta_no:int}|null */
    private static function hesaplaIsoHaftaNo($tarih)
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $tarih);
        if (!$parsed) {
            return null;
        }
        $isoYil = (int) $parsed->format('o');
        $isoHafta = (int) $parsed->format('W');

        return ['yil' => $isoYil, 'hafta_no' => $isoHafta];
    }

    private static function toIso8601($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return gmdate('c');
        }
        if (strpos($value, 'T') !== false) {
            return $value;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if ($dt) {
            return $dt->format('Y-m-d\TH:i:s\Z');
        }

        return $value;
    }

    private static function safeNonNegInt($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $n = (int) $value;

        return $n < 0 ? 0 : $n;
    }

    private static function parsePositiveInt($value)
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $n = (int) $value;

            return $n >= 1 ? $n : null;
        }
        if (is_float($value) && floor($value) === $value && $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    private static function conflict($message)
    {
        JsonResponse::error(409, 'STATE_CONFLICT', $message);
    }

    private static function rollbackConflict(PDO $pdo, $message)
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        self::conflict($message);
    }
}
