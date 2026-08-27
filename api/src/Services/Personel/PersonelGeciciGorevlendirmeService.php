<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Scope\OrgScope;
use PDO;
use PDOException;

/**
 * Tarihli geçici görevlendirme owner'ı.
 * Permanent personeller org alanlarını overwrite etmez.
 * Aynı anda tek AKTIF görevlendirme (fail-closed, transaction + FOR UPDATE).
 * hedef_sube_id explicit zorunlu; silent sube fallback YOK.
 */
final class PersonelGeciciGorevlendirmeService
{
    public const DURUM_AKTIF = 'AKTIF';
    public const DURUM_SONLANDIRILDI = 'SONLANDIRILDI';
    public const DURUM_IPTAL = 'IPTAL';

    public const ERROR_CONFLICT = 'GECICI_GOREVLENDIRME_CAKISMA';
    public const ERROR_FORBIDDEN = 'GECICI_GOREVLENDIRME_YETKI_YOK';
    public const ERROR_TARGET = 'GECICI_GOREVLENDIRME_HEDEF_GECERSIZ';

    public const TZ_BUSINESS = 'Europe/Istanbul';

    /**
     * Business-now (Europe/Istanbul). Assignment wall-clock karşılaştırması için canonical.
     */
    public static function businessNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ_BUSINESS)))
            ->format('Y-m-d H:i:s');
    }

    /**
     * UTC veya local datetime string → Istanbul business datetime (Y-m-d H:i:s).
     *
     * @param mixed $timestamp
     */
    public static function toBusinessDateTime($timestamp): string
    {
        $raw = trim((string) $timestamp);
        if ($raw === '') {
            return self::businessNow();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $raw .= ' 00:00:00';
        }
        try {
            // Mikro saniyeli UTC (QR occurred_at_utc) → Istanbul.
            if (preg_match('/Z$|[+-]\d{2}:?\d{2}$/i', $raw)
                || stripos($raw, 'UTC') !== false
                || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $raw)
            ) {
                $normalized = str_replace('T', ' ', $raw);
                $normalized = preg_replace('/Z$/i', '', $normalized) ?? $normalized;
                $hasOffset = (bool) preg_match('/[+-]\d{2}:?\d{2}$/', $normalized);
                if (!$hasOffset) {
                    $dt = new \DateTimeImmutable($normalized, new \DateTimeZone('UTC'));
                } else {
                    $dt = new \DateTimeImmutable($normalized);
                }

                return $dt->setTimezone(new \DateTimeZone(self::TZ_BUSINESS))->format('Y-m-d H:i:s');
            }
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone(self::TZ_BUSINESS));

            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return self::businessNow();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findActive(PDO $pdo, $personelId): ?array
    {
        return self::findActiveAt($pdo, $personelId, self::businessNow());
    }

    /**
     * Aktif görevlendirme at-time (business datetime veya UTC timestamp).
     * Current path: yalnız AKTIF.
     *
     * @param mixed $at
     * @return array<string, mixed>|null
     */
    public static function findActiveAt(PDO $pdo, $personelId, $at): ?array
    {
        return self::findCoveringAt($pdo, $personelId, $at, true);
    }

    /**
     * Zaman penceresini kapsayan görevlendirme (tarihsel correction için).
     * AKTIF + SONLANDIRILDI (IPTAL hariç). Hard delete yok.
     *
     * @param mixed $at
     * @return array<string, mixed>|null
     */
    public static function findCoveringAt(PDO $pdo, $personelId, $at, $aktifOnly = false): ?array
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0 || !PersonelGeciciGorevlendirmeSchema::isReady($pdo)) {
            return null;
        }
        $atBiz = self::toBusinessDateTime($at);
        if ($aktifOnly) {
            $sql = 'SELECT * FROM personel_gecici_gorevlendirmeler
                    WHERE personel_id = :pid
                      AND durum = :durum
                      AND baslangic_at <= :at
                      AND (bitis_at IS NULL OR bitis_at > :at2)
                    ORDER BY id DESC
                    LIMIT 1';
            $params = [
                'pid' => $personelId,
                'durum' => self::DURUM_AKTIF,
                'at' => $atBiz,
                'at2' => $atBiz,
            ];
        } else {
            $sql = 'SELECT * FROM personel_gecici_gorevlendirmeler
                    WHERE personel_id = :pid
                      AND durum IN (:aktif, :son)
                      AND baslangic_at <= :at
                      AND (bitis_at IS NULL OR bitis_at > :at2)
                    ORDER BY id DESC
                    LIMIT 1';
            $params = [
                'pid' => $personelId,
                'aktif' => self::DURUM_AKTIF,
                'son' => self::DURUM_SONLANDIRILDI,
                'at' => $atBiz,
                'at2' => $atBiz,
            ];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listHistory(PDO $pdo, $personelId): array
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0 || !PersonelGeciciGorevlendirmeSchema::isReady($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM personel_gecici_gorevlendirmeler
             WHERE personel_id = :pid
             ORDER BY id DESC'
        );
        $stmt->execute(['pid' => $personelId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Bağlantısız DIS havuzu — yalnız global/İK merkezi görünüm.
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public static function listUnassignedDisPool(PDO $pdo, array $user): array
    {
        if (!PersonelCalisanKapsamSchema::isReady($pdo)) {
            return [];
        }
        $role = OrgScope::normalizeRole($user);
        $allowedCentral = in_array($role, ['GENEL_YONETICI', 'SISTEM_YONETICISI', 'IK_SORUMLUSU'], true);
        if (!$allowedCentral) {
            JsonResponse::forbidden('Dis kaynak havuzu icin yetki yok.');
        }

        $sql = "SELECT p.id, p.sicil_no, p.ad, p.soyad, p.calisan_kapsami,
                       p.sube_id, p.departman_id, p.bolum_id, p.birim_id
                FROM personeller p
                WHERE p.calisan_kapsami = 'DIS_KAYNAK'
                  AND p.aktif_durum = 'AKTIF'
                  AND IFNULL(p.bolum_id, 0) <= 0
                  AND IFNULL(p.birim_id, 0) <= 0
                  AND p.id NOT IN (
                    SELECT g.personel_id FROM personel_gecici_gorevlendirmeler g
                    WHERE g.durum = 'AKTIF'
                      AND g.baslangic_at <= :now
                      AND (g.bitis_at IS NULL OR g.bitis_at > :now2)
                  )
                ORDER BY p.sicil_no ASC";
        if (!PersonelGeciciGorevlendirmeSchema::isReady($pdo)) {
            $sql = "SELECT p.id, p.sicil_no, p.ad, p.soyad, p.calisan_kapsami,
                           p.sube_id, p.departman_id, p.bolum_id, p.birim_id
                    FROM personeller p
                    WHERE p.calisan_kapsami = 'DIS_KAYNAK'
                      AND p.aktif_durum = 'AKTIF'
                      AND IFNULL(p.bolum_id, 0) <= 0
                      AND IFNULL(p.birim_id, 0) <= 0
                    ORDER BY p.sicil_no ASC";
            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            return is_array($rows) ? $rows : [];
        }
        $now = self::businessNow();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['now' => $now, 'now2' => $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Bölüm yöneticisi için görevlendirilebilir minimal pool (bağlantısız DIS).
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public static function listAssignablePoolForBolumManager(PDO $pdo, array $user): array
    {
        OrgScope::assertRequiredAssignment($user);
        $role = OrgScope::normalizeRole($user);
        if ($role !== 'BOLUM_YONETICISI' && !OrgScope::isUnrestricted($user)
            && $role !== 'IK_SORUMLUSU' && $role !== 'SUBE_YONETICISI') {
            JsonResponse::forbidden('Gorevlendirme havuzu icin yetki yok.');
        }
        if (!PersonelCalisanKapsamSchema::isReady($pdo)) {
            return [];
        }

        $sql = "SELECT p.id, p.sicil_no, p.ad, p.soyad, p.calisan_kapsami
                FROM personeller p
                WHERE p.calisan_kapsami = 'DIS_KAYNAK'
                  AND p.aktif_durum = 'AKTIF'
                  AND IFNULL(p.bolum_id, 0) <= 0
                  AND IFNULL(p.birim_id, 0) <= 0";
        $params = [];
        if (PersonelGeciciGorevlendirmeSchema::isReady($pdo)) {
            $sql .= " AND p.id NOT IN (
                SELECT g.personel_id FROM personel_gecici_gorevlendirmeler g
                WHERE g.durum = 'AKTIF'
                  AND g.baslangic_at <= :now
                  AND (g.bitis_at IS NULL OR g.bitis_at > :now2)
              )";
            $now = self::businessNow();
            $params['now'] = $now;
            $params['now2'] = $now;
        }
        $sql .= ' ORDER BY p.sicil_no ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Effective şube eşleşmesi (permanent OR aktif görevlendirme).
     * 076 yoksa yalnız permanent. Fatal SQL üretmez.
     * Named params her kullanım için tekildir (native prepare uyumu).
     *
     * @param string $subeParamBound param adı (ör. sube_id) — ':' olmadan; değer params'ta olmalı
     * @param string|null $asOfParam optional business-time param; null → inline businessNow bind key
     */
    public static function sqlPersonelMatchesEffectiveSube(
        PDO $pdo,
        $alias,
        $subeParam,
        array &$params,
        $asOfParam = null,
        $paramPrefix = 'pgg_sube'
    ): string {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
        if ($safe === '') {
            $safe = 'p';
        }
        $subeParam = ltrim((string) $subeParam, ':');
        $subeVal = array_key_exists($subeParam, $params) ? (int) $params[$subeParam] : 0;
        $permKey = $paramPrefix . '_perm_sube';
        $params[$permKey] = $subeVal;
        // Native prepare: kullanılmayan named param bırakma.
        if ($subeParam !== $permKey) {
            unset($params[$subeParam]);
        }
        $permanent = $safe . '.sube_id = :' . $permKey;

        if (!PersonelGeciciGorevlendirmeSchema::isReady($pdo)) {
            return $permanent;
        }

        $asgSubeKey = $paramPrefix . '_asg_sube';
        $params[$asgSubeKey] = $subeVal;

        $nowKey = $asOfParam !== null && $asOfParam !== ''
            ? ltrim((string) $asOfParam, ':')
            : ($paramPrefix . '_now');
        if ($asOfParam === null || $asOfParam === '') {
            $params[$nowKey] = self::businessNow();
        }
        $nowKey2 = $paramPrefix . '_now_b';
        $params[$nowKey2] = array_key_exists($nowKey, $params)
            ? $params[$nowKey]
            : self::businessNow();

        return '(' . $permanent . ' OR EXISTS (
            SELECT 1 FROM personel_gecici_gorevlendirmeler g
            WHERE g.personel_id = ' . $safe . '.id
              AND g.durum = \'AKTIF\'
              AND g.baslangic_at <= :' . $nowKey . '
              AND (g.bitis_at IS NULL OR g.bitis_at > :' . $nowKey2 . ')
              AND g.hedef_sube_id = :' . $asgSubeKey . '
        ))';
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, array $user, array $body): array
    {
        PersonelGeciciGorevlendirmeSchema::assertReady($pdo);
        RolePermissions::assert($user, 'personeller.update');

        $personelId = (int) ($body['personel_id'] ?? 0);
        $hedefSubeId = (int) ($body['hedef_sube_id'] ?? 0);
        $hedefBolumId = (int) ($body['hedef_bolum_id'] ?? 0);
        $hedefBirimId = array_key_exists('hedef_birim_id', $body)
            ? (($body['hedef_birim_id'] === null || $body['hedef_birim_id'] === '')
                ? null
                : (int) $body['hedef_birim_id'])
            : null;
        $baslangic = trim((string) ($body['baslangic_at'] ?? $body['baslangic_tarihi'] ?? ''));
        $bitisRaw = $body['bitis_at'] ?? $body['bitis_tarihi'] ?? null;
        $bitis = ($bitisRaw === null || trim((string) $bitisRaw) === '')
            ? null
            : trim((string) $bitisRaw);

        if ($personelId <= 0) {
            throw new PersonelValidationException('personel_id', 'Personel zorunludur.');
        }
        if ($hedefSubeId <= 0) {
            throw new PersonelValidationException(
                'hedef_sube_id',
                'Hedef sube zorunludur (explicit).',
                self::ERROR_TARGET
            );
        }
        if ($hedefBolumId <= 0) {
            throw new PersonelValidationException('hedef_bolum_id', 'Hedef bolum zorunludur.');
        }
        if ($baslangic === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $baslangic)) {
            throw new PersonelValidationException('baslangic_at', 'Gecerli baslangic zamani zorunludur.');
        }
        if (strlen($baslangic) === 10) {
            $baslangic .= ' 00:00:00';
        }
        if ($bitis !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $bitis)) {
                throw new PersonelValidationException('bitis_at', 'Gecerli bitis zamani giriniz.');
            }
            if (strlen($bitis) === 10) {
                $bitis .= ' 23:59:59';
            }
            // Canonical: bitis >= baslangic
            if (strcmp($bitis, $baslangic) < 0) {
                throw new PersonelValidationException('bitis_at', 'Bitis baslangictan once olamaz.');
            }
        }

        if (!PersonelCalisanKapsamService::isDisKaynak($pdo, $personelId)) {
            throw new PersonelValidationException(
                'personel_id',
                'Gecici gorevlendirme yalniz DIS_KAYNAK personel icin kullanilir.'
            );
        }

        $chain = self::validateHedefChain($pdo, $hedefSubeId, $hedefBolumId, $hedefBirimId);
        $hedefDepartmanId = (int) $chain['departman_id'];

        self::assertActorMayAssignToBolum($user, $hedefBolumId, $hedefSubeId);

        $actorId = (int) ($user['id'] ?? 0);
        $startedTxn = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTxn = true;
            }

            // Personel satırını kilitle — paralel overlap yarışını fail-closed kapatır.
            $lock = $pdo->prepare('SELECT id FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE');
            $lock->execute(['id' => $personelId]);
            if (!$lock->fetchColumn()) {
                throw new PersonelValidationException('personel_id', 'Personel bulunamadi.');
            }

            if (self::hasOverlappingActive($pdo, $personelId, $baslangic, $bitis, null)) {
                throw new PersonelValidationException(
                    'personel_id',
                    'Ayni zaman araliginda aktif gecici gorevlendirme var.',
                    self::ERROR_CONFLICT
                );
            }

            $ins = $pdo->prepare(
                'INSERT INTO personel_gecici_gorevlendirmeler
                 (personel_id, hedef_sube_id, hedef_departman_id, hedef_bolum_id, hedef_birim_id,
                  baslangic_at, bitis_at, durum, olusturan_user_id, created_at)
                 VALUES
                 (:pid, :sube, :dep, :bolum, :birim, :bas, :bit, :durum, :uid, NOW())'
            );
            $ins->execute([
                'pid' => $personelId,
                'sube' => $hedefSubeId,
                'dep' => $hedefDepartmanId,
                'bolum' => $hedefBolumId,
                'birim' => $hedefBirimId,
                'bas' => $baslangic,
                'bit' => $bitis,
                'durum' => self::DURUM_AKTIF,
                'uid' => $actorId > 0 ? $actorId : null,
            ]);
            $id = (int) $pdo->lastInsertId();

            if ($startedTxn) {
                $pdo->commit();
            }
        } catch (PersonelValidationException $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (PDOException $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $stmt = $pdo->prepare('SELECT * FROM personel_gecici_gorevlendirmeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PersonelValidationException('id', 'Gorevlendirme kaydi okunamadi.');
        }

        return $row;
    }

    /**
     * Soft-end: hard delete yok. İkinci end reject. bitis >= baslangic.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function end(PDO $pdo, array $user, $assignmentId, $endedAt = null): array
    {
        PersonelGeciciGorevlendirmeSchema::assertReady($pdo);
        RolePermissions::assert($user, 'personeller.update');
        $assignmentId = (int) $assignmentId;

        $startedTxn = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTxn = true;
            }
            $stmt = $pdo->prepare(
                'SELECT * FROM personel_gecici_gorevlendirmeler WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $assignmentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                if ($startedTxn && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                JsonResponse::error(404, 'NOT_FOUND', 'Gorevlendirme bulunamadi.');
            }
            if ((string) $row['durum'] !== self::DURUM_AKTIF) {
                throw new PersonelValidationException('durum', 'Gorevlendirme zaten aktif degil.');
            }

            self::assertActorMayAssignToBolum(
                $user,
                (int) $row['hedef_bolum_id'],
                (int) $row['hedef_sube_id']
            );

            $endTs = $endedAt !== null && trim((string) $endedAt) !== ''
                ? trim((string) $endedAt)
                : self::businessNow();
            if (strlen($endTs) === 10) {
                $endTs .= ' 23:59:59';
            }
            $baslangic = (string) $row['baslangic_at'];
            if (strcmp($endTs, $baslangic) < 0) {
                throw new PersonelValidationException('bitis_at', 'Bitis baslangictan once olamaz.');
            }

            $actorId = (int) ($user['id'] ?? 0);
            $upd = $pdo->prepare(
                'UPDATE personel_gecici_gorevlendirmeler
                 SET durum = :durum, bitis_at = :bit, sonlandiran_user_id = :uid, ended_at = NOW()
                 WHERE id = :id AND durum = :aktif'
            );
            $upd->execute([
                'durum' => self::DURUM_SONLANDIRILDI,
                'bit' => $endTs,
                'uid' => $actorId > 0 ? $actorId : null,
                'id' => $assignmentId,
                'aktif' => self::DURUM_AKTIF,
            ]);
            if ($upd->rowCount() < 1) {
                throw new PersonelValidationException('durum', 'Gorevlendirme zaten aktif degil.');
            }

            if ($startedTxn) {
                $pdo->commit();
            }
        } catch (PersonelValidationException $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($startedTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $freshStmt = $pdo->prepare('SELECT * FROM personel_gecici_gorevlendirmeler WHERE id = :id LIMIT 1');
        $freshStmt->execute(['id' => $assignmentId]);
        $fresh = $freshStmt->fetch(PDO::FETCH_ASSOC);

        return is_array($fresh) ? $fresh : $row;
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function assertActorMayAssignToBolum(array $user, $bolumId, $subeId): void
    {
        $bolumId = (int) $bolumId;
        $subeId = (int) $subeId;
        OrgScope::assertRequiredAssignment($user);
        $role = OrgScope::normalizeRole($user);

        if (OrgScope::isUnrestricted($user) || $role === 'IK_SORUMLUSU') {
            return;
        }

        if ($role === 'BOLUM_YONETICISI') {
            $allowed = OrgScope::allowedBolumIds($user);
            if (!in_array($bolumId, $allowed, true)) {
                JsonResponse::error(403, self::ERROR_FORBIDDEN, 'Yalniz kendi bolumune gorevlendirme yapilabilir.');
            }

            return;
        }

        if ($role === 'SUBE_YONETICISI') {
            $allowedSube = OrgScope::allowedSubeIds($user);
            if (!in_array($subeId, $allowedSube, true)) {
                JsonResponse::error(403, self::ERROR_FORBIDDEN, 'Sube kapsami disinda gorevlendirme yapilamaz.');
            }

            return;
        }

        // BIRIM_AMIRI kendi başına başka bölüme kişi çekemez.
        JsonResponse::error(403, self::ERROR_FORBIDDEN, 'Bu rol gecici gorevlendirme olusturamaz.');
    }

    private static function hasOverlappingActive(
        PDO $pdo,
        $personelId,
        $baslangic,
        $bitis,
        $excludeId
    ): bool {
        $params = [
            'pid' => (int) $personelId,
            'durum' => self::DURUM_AKTIF,
            'bas' => $baslangic,
        ];
        // Overlap: existing.baslangic < new.bitis AND (existing.bitis IS NULL OR existing.bitis > new.baslangic)
        $sql = 'SELECT id FROM personel_gecici_gorevlendirmeler
                WHERE personel_id = :pid AND durum = :durum
                  AND baslangic_at < IFNULL(:bit, \'9999-12-31 23:59:59\')
                  AND (bitis_at IS NULL OR bitis_at > :bas)';
        $params['bit'] = $bitis;
        if ($excludeId !== null) {
            $sql .= ' AND id <> :ex';
            $params['ex'] = (int) $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Fail-closed zincir:
     * hedef_sube_id → sube_departmanlar → hedef_bolum_id departmanı → (opsiyonel) birim aynı bölüm.
     *
     * @return array{bolum_id:int,departman_id:int,sube_id:int}
     */
    private static function validateHedefChain(PDO $pdo, $hedefSubeId, $hedefBolumId, $hedefBirimId): array
    {
        $hedefSubeId = (int) $hedefSubeId;
        $hedefBolumId = (int) $hedefBolumId;

        $bolumStmt = $pdo->prepare(
            'SELECT b.id AS bolum_id, b.departman_id
             FROM bolumler b
             WHERE b.id = :id
             LIMIT 1'
        );
        $bolumStmt->execute(['id' => $hedefBolumId]);
        $bolum = $bolumStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($bolum)) {
            throw new PersonelValidationException('hedef_bolum_id', 'Hedef bolum bulunamadi.', self::ERROR_TARGET);
        }
        $departmanId = (int) $bolum['departman_id'];
        if ($departmanId <= 0) {
            throw new PersonelValidationException(
                'hedef_bolum_id',
                'Hedef bolumun departmani yok.',
                self::ERROR_TARGET
            );
        }

        $link = $pdo->prepare(
            'SELECT sube_id FROM sube_departmanlar
             WHERE sube_id = :sube AND departman_id = :dep
             LIMIT 1'
        );
        $link->execute(['sube' => $hedefSubeId, 'dep' => $departmanId]);
        if ($link->fetchColumn() === false) {
            throw new PersonelValidationException(
                'hedef_sube_id',
                'Hedef sube, bolumun departmani ile bagli degil.',
                self::ERROR_TARGET
            );
        }

        if ($hedefBirimId !== null) {
            if ((int) $hedefBirimId <= 0) {
                throw new PersonelValidationException('hedef_birim_id', 'Gecersiz birim.', self::ERROR_TARGET);
            }
            $birim = self::loadBirim($pdo, $hedefBirimId);
            if ($birim === null || (int) $birim['bolum_id'] !== $hedefBolumId) {
                throw new PersonelValidationException(
                    'hedef_birim_id',
                    'Birim hedef bolum ile tutarsiz.',
                    self::ERROR_TARGET
                );
            }
        }

        return [
            'bolum_id' => $hedefBolumId,
            'departman_id' => $departmanId,
            'sube_id' => $hedefSubeId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadBirim(PDO $pdo, $birimId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, bolum_id FROM birimler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $birimId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
