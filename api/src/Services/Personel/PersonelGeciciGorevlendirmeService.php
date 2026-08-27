<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Scope\OrgScope;
use PDO;

/**
 * Tarihli geçici görevlendirme owner'ı.
 * Permanent personeller org alanlarını overwrite etmez.
 * Aynı anda tek AKTIF görevlendirme (fail-closed).
 */
final class PersonelGeciciGorevlendirmeService
{
    public const DURUM_AKTIF = 'AKTIF';
    public const DURUM_SONLANDIRILDI = 'SONLANDIRILDI';
    public const DURUM_IPTAL = 'IPTAL';

    public const ERROR_CONFLICT = 'GECICI_GOREVLENDIRME_CAKISMA';
    public const ERROR_FORBIDDEN = 'GECICI_GOREVLENDIRME_YETKI_YOK';
    public const ERROR_TARGET = 'GECICI_GOREVLENDIRME_HEDEF_GECERSIZ';

    /**
     * @return array<string, mixed>|null
     */
    public static function findActive(PDO $pdo, $personelId): ?array
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0 || !PersonelGeciciGorevlendirmeSchema::isReady($pdo)) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'SELECT * FROM personel_gecici_gorevlendirmeler
             WHERE personel_id = :pid
               AND durum = :durum
               AND baslangic_at <= :now
               AND (bitis_at IS NULL OR bitis_at > :now2)
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'pid' => $personelId,
            'durum' => self::DURUM_AKTIF,
            'now' => $now,
            'now2' => $now,
        ]);
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
     * Bölüm yöneticisi assignable pool için minimal liste ayrı endpoint'te.
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
                      AND g.baslangic_at <= NOW()
                      AND (g.bitis_at IS NULL OR g.bitis_at > NOW())
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
        }
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

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
        if (PersonelGeciciGorevlendirmeSchema::isReady($pdo)) {
            $sql .= " AND p.id NOT IN (
                SELECT g.personel_id FROM personel_gecici_gorevlendirmeler g
                WHERE g.durum = 'AKTIF'
                  AND g.baslangic_at <= NOW()
                  AND (g.bitis_at IS NULL OR g.bitis_at > NOW())
              )";
        }
        $sql .= ' ORDER BY p.sicil_no ASC';
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return is_array($rows) ? $rows : [];
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
            if (strcmp($bitis, $baslangic) <= 0) {
                throw new PersonelValidationException('bitis_at', 'Bitis baslangictan sonra olmalidir.');
            }
        }

        if (!PersonelCalisanKapsamService::isDisKaynak($pdo, $personelId)) {
            throw new PersonelValidationException(
                'personel_id',
                'Gecici gorevlendirme yalniz DIS_KAYNAK personel icin kullanilir.'
            );
        }

        $bolum = self::loadBolumChain($pdo, $hedefBolumId);
        if ($bolum === null) {
            throw new PersonelValidationException('hedef_bolum_id', 'Hedef bolum bulunamadi.', self::ERROR_TARGET);
        }

        $hedefSubeId = (int) $bolum['sube_id'];
        $hedefDepartmanId = (int) $bolum['departman_id'];

        if ($hedefBirimId !== null) {
            if ($hedefBirimId <= 0) {
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

        self::assertActorMayAssignToBolum($user, $hedefBolumId, $hedefSubeId);

        if (self::hasOverlappingActive($pdo, $personelId, $baslangic, $bitis, null)) {
            throw new PersonelValidationException(
                'personel_id',
                'Ayni zaman araliginda aktif gecici gorevlendirme var.',
                self::ERROR_CONFLICT
            );
        }

        $actorId = (int) ($user['id'] ?? 0);
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

        $stmt = $pdo->prepare('SELECT * FROM personel_gecici_gorevlendirmeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PersonelValidationException('id', 'Gorevlendirme kaydi okunamadi.');
        }

        return $row;
    }

    /**
     * Soft-end: hard delete yok.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function end(PDO $pdo, array $user, $assignmentId, $endedAt = null): array
    {
        PersonelGeciciGorevlendirmeSchema::assertReady($pdo);
        RolePermissions::assert($user, 'personeller.update');
        $assignmentId = (int) $assignmentId;
        $stmt = $pdo->prepare('SELECT * FROM personel_gecici_gorevlendirmeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
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
            : date('Y-m-d H:i:s');
        if (strlen($endTs) === 10) {
            $endTs .= ' 23:59:59';
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

        $stmt->execute(['id' => $assignmentId]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);

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
     * @return array<string, mixed>|null
     */
    private static function loadBolumChain(PDO $pdo, $bolumId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT b.id AS bolum_id, b.departman_id
             FROM bolumler b
             WHERE b.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $bolumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $alt = $pdo->prepare(
            'SELECT sube_id FROM sube_departmanlar WHERE departman_id = :d ORDER BY sube_id ASC LIMIT 1'
        );
        $alt->execute(['d' => (int) $row['departman_id']]);
        $subeId = $alt->fetchColumn();
        if ($subeId === false || (int) $subeId <= 0) {
            return null;
        }

        return [
            'bolum_id' => (int) $row['bolum_id'],
            'departman_id' => (int) $row['departman_id'],
            'sube_id' => (int) $subeId,
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
