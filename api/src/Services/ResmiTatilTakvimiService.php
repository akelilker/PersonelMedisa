<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;
use PDOException;

class ResmiTatilTakvimiException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private $context;

    /** @param array<string, mixed> $context */
    public function __construct($code, $message, $httpStatus = 400, array $context = [])
    {
        parent::__construct((string) $message, (int) $httpStatus);
        $this->context = array_merge(['code' => (string) $code], $context);
    }

    public function getErrorCode()
    {
        return (string) ($this->context['code'] ?? 'TATIL_TAKVIM_ERROR');
    }

    /** @return array<string, mixed> */
    public function getContext()
    {
        return $this->context;
    }

    public function getHttpStatus()
    {
        $code = (int) $this->getCode();

        return $code >= 400 && $code < 600 ? $code : 400;
    }
}

/**
 * S88 resmi tatil takvimi owner (UBGT gun kapsami).
 */
class ResmiTatilTakvimiService
{
    /** @var string[] */
    private static $gunKapsamlari = ['TAM_GUN', 'YARIM_GUN'];

    /** @var string[] */
    private static $durumlar = ['TASLAK', 'AKTIF', 'IPTAL'];

    /** @return array<int, array<string, mixed>> */
    public static function list(PDO $pdo, $durum = null, $tatilTuru = null, $tarihBas = null, $tarihBit = null, $gunKapsami = null)
    {
        $sql = 'SELECT r.*, u.ad_soyad AS yapan_ad
                FROM resmi_tatil_takvimi r
                LEFT JOIN users u ON u.id = r.yapan_kullanici_id
                WHERE 1=1';
        $params = [];
        if ($durum !== null && trim((string) $durum) !== '') {
            $sql .= ' AND r.durum = :durum';
            $params['durum'] = strtoupper(trim((string) $durum));
        }
        if ($tatilTuru !== null && trim((string) $tatilTuru) !== '') {
            $sql .= ' AND r.tatil_turu = :tur';
            $params['tur'] = strtoupper(trim((string) $tatilTuru));
        }
        if ($gunKapsami !== null && trim((string) $gunKapsami) !== '') {
            $sql .= ' AND r.gun_kapsami = :kapsam';
            $params['kapsam'] = strtoupper(trim((string) $gunKapsami));
        }
        if ($tarihBas !== null && trim((string) $tarihBas) !== '') {
            $sql .= ' AND r.tarih >= :tarih_bas';
            $params['tarih_bas'] = self::validDate($tarihBas);
        }
        if ($tarihBit !== null && trim((string) $tarihBit) !== '') {
            $sql .= ' AND r.tarih <= :tarih_bit';
            $params['tarih_bit'] = self::validDate($tarihBit);
        }
        $sql .= ' ORDER BY r.tarih DESC, r.revizyon_no DESC, r.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public static function get(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare(
            'SELECT r.*, u.ad_soyad AS yapan_ad
             FROM resmi_tatil_takvimi r
             LEFT JOIN users u ON u.id = r.yapan_kullanici_id
             WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapRow($row) : null;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor */
    public static function create(PDO $pdo, array $payload, array $actor, $requestHash = null)
    {
        $data = self::normalizePayload($payload, false);
        $pdo->beginTransaction();
        try {
            $id = self::insertRow($pdo, $data, 'TASLAK', null, 1, $actor);
            $detail = self::get($pdo, $id);
            self::audit($pdo, 'CREATE', null, $detail, $actor, $requestHash, $id);
            $pdo->commit();

            return $detail;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor */
    public static function update(PDO $pdo, $id, array $payload, array $actor, $requestHash = null)
    {
        $data = self::normalizePayload($payload, true);
        $pdo->beginTransaction();
        try {
            $row = self::fetchForUpdate($pdo, (int) $id);
            if (!$row) {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_NOT_FOUND', 'Kayit bulunamadi.', 404);
            }
            if ((string) $row['durum'] !== 'TASLAK') {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_NOT_EDITABLE', 'Yalniz taslak kayit duzenlenebilir.', 409);
            }
            $onceki = self::mapRow($row);
            $upd = $pdo->prepare(
                'UPDATE resmi_tatil_takvimi SET
                    tarih = :tarih, tatil_kodu = :kod, tatil_adi = :adi, tatil_turu = :tur,
                    gun_kapsami = :kapsam, tatil_interval_baslangic = :bas, tatil_interval_bitis = :bit,
                    kaynak_turu = :kaynak_turu, kaynak_referansi = :kaynak_ref, kaynak_tarihi = :kaynak_tarih,
                    aciklama = :aciklama, yapan_kullanici_id = :yapan
                 WHERE id = :id'
            );
            $upd->execute([
                'tarih' => $data['tarih'],
                'kod' => $data['tatil_kodu'],
                'adi' => $data['tatil_adi'],
                'tur' => $data['tatil_turu'],
                'kapsam' => $data['gun_kapsami'],
                'bas' => $data['tatil_interval_baslangic'],
                'bit' => $data['tatil_interval_bitis'],
                'kaynak_turu' => $data['kaynak_turu'],
                'kaynak_ref' => $data['kaynak_referansi'],
                'kaynak_tarih' => $data['kaynak_tarihi'],
                'aciklama' => $data['aciklama'],
                'yapan' => self::actorId($actor),
                'id' => (int) $id,
            ]);
            $sonraki = self::get($pdo, (int) $id);
            self::audit($pdo, 'UPDATE', $onceki, $sonraki, $actor, $requestHash, (int) $id);
            $pdo->commit();

            return $sonraki;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $actor */
    public static function activate(PDO $pdo, $id, array $actor, $requestHash = null)
    {
        $pdo->beginTransaction();
        try {
            $row = self::fetchForUpdate($pdo, (int) $id);
            if (!$row) {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_NOT_FOUND', 'Kayit bulunamadi.', 404);
            }
            if ((string) $row['durum'] !== 'TASLAK') {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_INVALID_STATE', 'Yalniz taslak kayit aktiflestirilebilir.', 409);
            }
            if ((string) $row['tatil_turu'] === 'UBGT') {
                $conflict = self::detectActiveConflicts($pdo, (string) $row['tarih']);
                if ($conflict > 0) {
                    throw new ResmiTatilTakvimiException(
                        'TATIL_TAKVIM_AKTIF_CAKISMA',
                        'Bu tarihte aktif UBGT kaydi zaten mevcut.',
                        409,
                        ['aktif_sayisi' => $conflict]
                    );
                }
            }
            $onceki = self::mapRow($row);
            $pdo->prepare("UPDATE resmi_tatil_takvimi SET durum = 'AKTIF', yapan_kullanici_id = :yapan WHERE id = :id")
                ->execute(['yapan' => self::actorId($actor), 'id' => (int) $id]);
            $sonraki = self::get($pdo, (int) $id);
            self::audit($pdo, 'ACTIVATE', $onceki, $sonraki, $actor, $requestHash, (int) $id);
            $pdo->commit();

            return $sonraki;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_AKTIF_CAKISMA', 'Bu tarihte aktif UBGT kaydi zaten mevcut.', 409);
            }
            throw $e;
        }
    }

    /**
     * Aktif kaydi iptal edip yeni aktif revizyon olusturur.
     *
     * @param array<string, mixed> $payload @param array<string, mixed> $actor
     */
    public static function revise(PDO $pdo, $id, array $payload, array $actor, $requestHash = null)
    {
        $gerekce = trim((string) ($payload['iptal_gerekcesi'] ?? $payload['gerekce'] ?? ''));
        if ($gerekce === '') {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'Revizyon gerekcesi zorunludur.', 400);
        }
        $pdo->beginTransaction();
        try {
            $row = self::fetchForUpdate($pdo, (int) $id);
            if (!$row) {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_NOT_FOUND', 'Kayit bulunamadi.', 404);
            }
            if ((string) $row['durum'] !== 'AKTIF') {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_INVALID_STATE', 'Yalniz aktif kayit revize edilebilir.', 409);
            }
            $data = self::normalizePayload(array_merge(self::mapRow($row), $payload), true);
            $onceki = self::mapRow($row);
            self::markCancelled($pdo, (int) $id, $gerekce, $actor);
            $iptalSonraki = self::get($pdo, (int) $id);
            self::audit($pdo, 'CANCEL', $onceki, $iptalSonraki, $actor, $requestHash, (int) $id);
            $revizyonNo = (int) $row['revizyon_no'] + 1;
            $newId = self::insertRow($pdo, $data, 'AKTIF', (int) $id, $revizyonNo, $actor);
            $yeni = self::get($pdo, $newId);
            self::audit($pdo, 'REVISE', $onceki, $yeni, $actor, $requestHash, $newId);
            $pdo->commit();

            return $yeni;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_AKTIF_CAKISMA', 'Revizyon aktiflestirilemedi: tarih cakismasi.', 409);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $actor */
    public static function cancel(PDO $pdo, $id, $gerekce, array $actor, $requestHash = null)
    {
        $gerekce = trim((string) $gerekce);
        if ($gerekce === '') {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'Iptal gerekcesi zorunludur.', 400);
        }
        $pdo->beginTransaction();
        try {
            $row = self::fetchForUpdate($pdo, (int) $id);
            if (!$row) {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_NOT_FOUND', 'Kayit bulunamadi.', 404);
            }
            if ((string) $row['durum'] === 'IPTAL') {
                throw new ResmiTatilTakvimiException('TATIL_TAKVIM_INVALID_STATE', 'Kayit zaten iptal.', 409);
            }
            $onceki = self::mapRow($row);
            self::markCancelled($pdo, (int) $id, $gerekce, $actor);
            $sonraki = self::get($pdo, (int) $id);
            self::audit($pdo, 'CANCEL', $onceki, $sonraki, $actor, $requestHash, (int) $id);
            $pdo->commit();

            return $sonraki;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public static function resolveActiveForDate(PDO $pdo, $date, $tatilTuru = 'UBGT')
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM resmi_tatil_takvimi
             WHERE tarih = :tarih AND durum = 'AKTIF' AND tatil_turu = :tur
             ORDER BY revizyon_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['tarih' => self::validDate($date), 'tur' => strtoupper(trim((string) $tatilTuru))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapRow($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listActiveForDate(PDO $pdo, $date, $tatilTuru = 'UBGT')
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM resmi_tatil_takvimi
             WHERE tarih = :tarih AND durum = 'AKTIF' AND tatil_turu = :tur
             ORDER BY revizyon_no DESC, id DESC"
        );
        $stmt->execute(['tarih' => self::validDate($date), 'tur' => strtoupper(trim((string) $tatilTuru))]);

        return array_map([self::class, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function detectActiveConflicts(PDO $pdo, $date)
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM resmi_tatil_takvimi
             WHERE tarih = :tarih AND durum = 'AKTIF' AND tatil_turu = 'UBGT'"
        );
        $stmt->execute(['tarih' => self::validDate($date)]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Read-only donem envanteri + UBGT siniflandirma ozeti (PII yok).
     *
     * @return array<string, mixed>
     */
    public static function envanterOzet(PDO $pdo, $yil, $ay)
    {
        $yil = (int) $yil;
        $ay = (int) $ay;
        if ($yil < 2000 || $yil > 2100 || $ay < 1 || $ay > 12) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'Gecersiz donem.', 400);
        }
        $firstDay = sprintf('%04d-%02d-01', $yil, $ay);
        $lastDay = date('Y-m-t', strtotime($firstDay));
        $stmt = $pdo->prepare(
            "SELECT durum, tatil_turu, gun_kapsami, COUNT(*) AS adet
             FROM resmi_tatil_takvimi
             WHERE tarih BETWEEN :bas AND :bit
             GROUP BY durum, tatil_turu, gun_kapsami"
        );
        $stmt->execute(['bas' => $firstDay, 'bit' => $lastDay]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ozet = [
            'yil' => $yil,
            'ay' => $ay,
            'donem' => sprintf('%04d-%02d', $yil, $ay),
            'toplam' => 0,
            'aktif' => 0,
            'taslak' => 0,
            'iptal' => 0,
            'aktif_ubgt_tam_gun' => 0,
            'aktif_ubgt_yarim_gun' => 0,
            'siniflandirma' => self::siniflandirmaRaporu($pdo, $firstDay, $lastDay),
        ];
        foreach ($rows as $row) {
            $adet = (int) $row['adet'];
            $ozet['toplam'] += $adet;
            $durum = (string) $row['durum'];
            if ($durum === 'AKTIF') {
                $ozet['aktif'] += $adet;
                if ((string) $row['tatil_turu'] === 'UBGT') {
                    if ((string) $row['gun_kapsami'] === 'TAM_GUN') {
                        $ozet['aktif_ubgt_tam_gun'] += $adet;
                    } elseif ((string) $row['gun_kapsami'] === 'YARIM_GUN') {
                        $ozet['aktif_ubgt_yarim_gun'] += $adet;
                    }
                }
            } elseif ($durum === 'TASLAK') {
                $ozet['taslak'] += $adet;
            } elseif ($durum === 'IPTAL') {
                $ozet['iptal'] += $adet;
            }
        }

        return $ozet;
    }

    /**
     * Hassas veri icermez: kisi adi / TCKN / ucret yok.
     *
     * @return array<string, int>
     */
    public static function siniflandirmaRaporu(PDO $pdo, $firstDay, $lastDay)
    {
        $rapor = [
            'toplam_ubgt_satiri' => 0,
            'tam_gun' => 0,
            'yarim_gun' => 0,
            'bilinmiyor' => 0,
            'cakisma' => 0,
            'kaynak_eksik' => 0,
            'ht_ubgt' => 0,
            'muhurlu' => 0,
            'muhursuz' => 0,
            'policy_activation_blocker' => 0,
        ];

        $hasGpColumns = self::tableHasColumn($pdo, 'gunluk_puantaj', 'tatil_siniflandirma_durumu');
        if (!$hasGpColumns) {
            return $rapor;
        }

        $stmt = $pdo->prepare(
            "SELECT
                gun_tipi,
                tatil_gun_kapsami,
                tatil_siniflandirma_durumu,
                tatil_takvim_id,
                muhur_id
             FROM gunluk_puantaj
             WHERE tarih BETWEEN :bas AND :bit
               AND (
                 gun_tipi = 'UBGT_Resmi_Tatil'
                 OR (gun_tipi = 'Hafta_Tatili_Pazar' AND tatil_takvim_id IS NOT NULL)
               )"
        );
        $stmt->execute(['bas' => $firstDay, 'bit' => $lastDay]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gunTipi = (string) ($row['gun_tipi'] ?? '');
            $sinif = strtoupper((string) ($row['tatil_siniflandirma_durumu'] ?? ''));
            $kapsam = strtoupper((string) ($row['tatil_gun_kapsami'] ?? ''));
            $muhurlu = isset($row['muhur_id']) && $row['muhur_id'] !== null;

            if ($gunTipi === 'Hafta_Tatili_Pazar') {
                $rapor['ht_ubgt']++;
            } else {
                $rapor['toplam_ubgt_satiri']++;
                if ($sinif === 'DOGRULANDI' && $kapsam === 'TAM_GUN') {
                    $rapor['tam_gun']++;
                } elseif ($sinif === 'DOGRULANDI' && $kapsam === 'YARIM_GUN') {
                    $rapor['yarim_gun']++;
                } elseif ($sinif === 'CAKISMA') {
                    $rapor['cakisma']++;
                } elseif ($sinif === 'KAYNAK_EKSIK') {
                    $rapor['kaynak_eksik']++;
                } else {
                    $rapor['bilinmiyor']++;
                }
            }

            if ($muhurlu) {
                $rapor['muhurlu']++;
            } else {
                $rapor['muhursuz']++;
            }

            // Policy activation: eksik/cakisma kapsam veya yarim-gun odeme politikasi kapali.
            $isBlocker = $gunTipi === 'UBGT_Resmi_Tatil' && (
                $sinif === 'CAKISMA'
                || $sinif === 'KAYNAK_EKSIK'
                || $sinif === 'BILINMIYOR'
                || $sinif === ''
                || ($sinif === 'DOGRULANDI' && $kapsam === 'YARIM_GUN')
            );
            if ($isBlocker) {
                $rapor['policy_activation_blocker']++;
            }
        }

        return $rapor;
    }

    /**
     * Revizyon zinciri + audit (PII yok: TCKN/ucret/puantaj yok).
     *
     * @return array{items: array<int, array<string, mixed>>, auditler: array<int, array<string, mixed>>}
     */
    public static function history(PDO $pdo, $id)
    {
        $seed = self::fetch($pdo, (int) $id);
        if (!$seed) {
            throw new ResmiTatilTakvimiException('TATIL_TAKVIM_NOT_FOUND', 'Kayit bulunamadi.', 404);
        }

        $rootId = (int) $seed['id'];
        $cursor = $seed;
        while ($cursor['onceki_kayit_id'] !== null) {
            $prev = self::fetch($pdo, (int) $cursor['onceki_kayit_id']);
            if (!$prev) {
                break;
            }
            $rootId = (int) $prev['id'];
            $cursor = $prev;
        }

        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $pdo->prepare(
                "SELECT id FROM resmi_tatil_takvimi WHERE onceki_kayit_id IN ($placeholders)"
            );
            $stmt->execute($frontier);
            $next = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
                $childId = (int) $childId;
                if (!in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $next[] = $childId;
                }
            }
            $frontier = $next;
        }

        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $rowsStmt = $pdo->prepare(
            "SELECT r.*, u.ad_soyad AS yapan_ad
             FROM resmi_tatil_takvimi r
             LEFT JOIN users u ON u.id = r.yapan_kullanici_id
             WHERE r.id IN ($idPlaceholders)
             ORDER BY r.revizyon_no ASC, r.id ASC"
        );
        $rowsStmt->execute($ids);
        $items = array_map([self::class, 'mapRow'], $rowsStmt->fetchAll(PDO::FETCH_ASSOC));

        $auditStmt = $pdo->prepare(
            "SELECT a.id, a.kayit_id, a.aksiyon, a.actor_id, a.actor_rol, a.request_hash, a.created_at,
                    u.ad_soyad AS actor_ad
             FROM resmi_tatil_takvim_auditleri a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.kayit_id IN ($idPlaceholders)
             ORDER BY a.created_at ASC, a.id ASC"
        );
        $auditStmt->execute($ids);
        $auditler = [];
        foreach ($auditStmt->fetchAll(PDO::FETCH_ASSOC) as $audit) {
            $auditler[] = [
                'id' => (int) $audit['id'],
                'kayit_id' => (int) $audit['kayit_id'],
                'aksiyon' => (string) $audit['aksiyon'],
                'actor_id' => $audit['actor_id'] !== null ? (int) $audit['actor_id'] : null,
                'actor_rol' => $audit['actor_rol'] !== null ? (string) $audit['actor_rol'] : null,
                'actor_ad' => $audit['actor_ad'] !== null ? (string) $audit['actor_ad'] : null,
                'request_hash' => $audit['request_hash'] !== null ? (string) $audit['request_hash'] : null,
                'created_at' => (string) $audit['created_at'],
            ];
        }

        return ['items' => $items, 'auditler' => $auditler];
    }

    /**
     * Read-only projection onizleme. Tabloya yazmaz.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function projectionPreview(PDO $pdo, array $user, array $payload, $scopeSubeId = null, array $allowedSubeIds = [])
    {
        $tarihBas = isset($payload['tarih_bas']) && trim((string) $payload['tarih_bas']) !== ''
            ? self::validDate($payload['tarih_bas'])
            : (isset($payload['tarih']) ? self::validDate($payload['tarih']) : null);
        $tarihBit = isset($payload['tarih_bit']) && trim((string) $payload['tarih_bit']) !== ''
            ? self::validDate($payload['tarih_bit'])
            : $tarihBas;
        if ($tarihBas === null || $tarihBit === null) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'tarih veya tarih_bas/tarih_bit zorunludur.', 400);
        }
        if ($tarihBit < $tarihBas) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'tarih_bit tarih_bas\'tan kucuk olamaz.', 400);
        }

        $previewModu = strtoupper(trim((string) ($payload['preview_modu'] ?? 'OZET')));
        if ($previewModu !== 'OZET' && $previewModu !== 'DETAYSIZ') {
            $previewModu = 'OZET';
        }

        $hasColumns = self::tableHasColumn($pdo, 'gunluk_puantaj', 'tatil_siniflandirma_durumu');
        $ozet = [
            'tarih_bas' => $tarihBas,
            'tarih_bit' => $tarihBit,
            'preview_modu' => $previewModu,
            'read_only' => true,
            'policy_aktif_degil' => true,
            'toplam_satir' => 0,
            'dogrulandi' => 0,
            'kaynak_eksik' => 0,
            'cakisma' => 0,
            'bilinmiyor' => 0,
            'tam_gun' => 0,
            'yarim_gun' => 0,
            'ht_ubgt' => 0,
            'interval_olcumu_eksik' => 0,
            'policy_blocker' => 0,
            'muhurlu' => 0,
            'muhursuz' => 0,
            'muhur_projection_eksik' => 0,
            'tam_gun_aktivasyona_hazir' => 0,
            'yarim_gun_odeme_politikasi_bekliyor' => 0,
            'genel_sistem_hazir' => false,
        ];

        if (!$hasColumns) {
            return $ozet;
        }

        $sql = "SELECT gp.tarih, gp.gun_tipi, gp.muhur_id, gp.giris_saati, gp.cikis_saati,
                       gp.gercek_mola_dakika, gp.net_calisma_suresi_dakika,
                       gp.tatil_siniflandirma_durumu AS mevcut_sinif
                FROM gunluk_puantaj gp
                INNER JOIN personeller p ON p.id = gp.personel_id
                WHERE gp.tarih BETWEEN :bas AND :bit
                  AND (
                    gp.gun_tipi = 'UBGT_Resmi_Tatil'
                    OR gp.gun_tipi = 'Hafta_Tatili_Pazar'
                  )";
        $params = ['bas' => $tarihBas, 'bit' => $tarihBit];
        if ($scopeSubeId !== null) {
            $sql .= ' AND p.sube_id = :sube';
            $params['sube'] = (int) $scopeSubeId;
        } elseif (count($allowedSubeIds) > 0) {
            $placeholders = [];
            foreach (array_values($allowedSubeIds) as $i => $sid) {
                $key = 'sube_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $sid;
            }
            $sql .= ' AND p.sube_id IN (' . implode(',', $placeholders) . ')';
        }
        if (isset($payload['personel_id']) && (int) $payload['personel_id'] > 0) {
            $sql .= ' AND gp.personel_id = :personel';
            $params['personel'] = (int) $payload['personel_id'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $projection = ResmiTatilTakvimProjectionService::projectForPuantajRow($pdo, $row);
            $sinif = strtoupper((string) ($projection['tatil_siniflandirma_durumu'] ?? ''));
            $kapsam = strtoupper((string) ($projection['tatil_gun_kapsami'] ?? ''));
            $gunTipi = (string) ($row['gun_tipi'] ?? '');
            $muhurlu = isset($row['muhur_id']) && $row['muhur_id'] !== null;

            if ($gunTipi === 'Hafta_Tatili_Pazar' && empty($projection['ht_ubgt_ayni_gun_mi']) && $sinif === '') {
                continue;
            }

            $ozet['toplam_satir']++;
            if ($muhurlu) {
                $ozet['muhurlu']++;
                $mevcut = strtoupper((string) ($row['mevcut_sinif'] ?? ''));
                if ($mevcut === '' || $mevcut === 'BILINMIYOR' || $mevcut === 'KAYNAK_EKSIK') {
                    $ozet['muhur_projection_eksik']++;
                }
            } else {
                $ozet['muhursuz']++;
            }

            if (!empty($projection['ht_ubgt_ayni_gun_mi'])) {
                $ozet['ht_ubgt']++;
            }

            if ($sinif === 'DOGRULANDI') {
                $ozet['dogrulandi']++;
                if ($kapsam === 'TAM_GUN') {
                    $ozet['tam_gun']++;
                    $ozet['tam_gun_aktivasyona_hazir']++;
                } elseif ($kapsam === 'YARIM_GUN') {
                    $ozet['yarim_gun']++;
                    $ozet['yarim_gun_odeme_politikasi_bekliyor']++;
                    $ozet['policy_blocker']++;
                    if (($projection['tatil_donemi_net_calisma_dakika'] ?? null) === null) {
                        $ozet['interval_olcumu_eksik']++;
                    }
                }
            } elseif ($sinif === 'KAYNAK_EKSIK') {
                $ozet['kaynak_eksik']++;
                $ozet['policy_blocker']++;
            } elseif ($sinif === 'CAKISMA') {
                $ozet['cakisma']++;
                $ozet['policy_blocker']++;
            } elseif ($sinif !== '') {
                $ozet['bilinmiyor']++;
                $ozet['policy_blocker']++;
            } elseif ($gunTipi === 'UBGT_Resmi_Tatil') {
                $ozet['bilinmiyor']++;
                $ozet['policy_blocker']++;
            }
        }

        $ozet['genel_sistem_hazir'] = false;

        return $ozet;
    }

    private static function tableHasColumn(PDO $pdo, $table, $column)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => (string) $table, 'c' => (string) $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    private static function insertRow(PDO $pdo, array $data, $durum, $oncekiKayitId, $revizyonNo, array $actor)
    {
        $ins = $pdo->prepare(
            'INSERT INTO resmi_tatil_takvimi (
                tarih, tatil_kodu, tatil_adi, tatil_turu, gun_kapsami,
                tatil_interval_baslangic, tatil_interval_bitis, durum,
                kaynak_turu, kaynak_referansi, kaynak_tarihi, aciklama,
                revizyon_no, onceki_kayit_id, yapan_kullanici_id
             ) VALUES (
                :tarih, :kod, :adi, :tur, :kapsam,
                :bas, :bit, :durum,
                :kaynak_turu, :kaynak_ref, :kaynak_tarih, :aciklama,
                :revizyon, :onceki, :yapan
             )'
        );
        $ins->execute([
            'tarih' => $data['tarih'],
            'kod' => $data['tatil_kodu'],
            'adi' => $data['tatil_adi'],
            'tur' => $data['tatil_turu'],
            'kapsam' => $data['gun_kapsami'],
            'bas' => $data['tatil_interval_baslangic'],
            'bit' => $data['tatil_interval_bitis'],
            'durum' => $durum,
            'kaynak_turu' => $data['kaynak_turu'],
            'kaynak_ref' => $data['kaynak_referansi'],
            'kaynak_tarih' => $data['kaynak_tarihi'],
            'aciklama' => $data['aciklama'],
            'revizyon' => (int) $revizyonNo,
            'onceki' => $oncekiKayitId,
            'yapan' => self::actorId($actor),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, mixed> $actor */
    private static function markCancelled(PDO $pdo, $id, $gerekce, array $actor)
    {
        $pdo->prepare(
            "UPDATE resmi_tatil_takvimi SET
                durum = 'IPTAL',
                iptal_edildi_at = NOW(),
                iptal_eden_kullanici_id = :iptal_eden,
                iptal_gerekcesi = :gerekce
             WHERE id = :id"
        )->execute([
            'iptal_eden' => self::actorId($actor),
            'gerekce' => $gerekce,
            'id' => (int) $id,
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function normalizePayload(array $payload, $partial)
    {
        $tarih = array_key_exists('tarih', $payload)
            ? trim((string) $payload['tarih'])
            : ($partial ? null : '');
        if ($tarih !== null && $tarih !== '') {
            $tarih = self::validDate($tarih);
        } elseif (!$partial) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'tarih zorunludur.', 400);
        }

        $tatilKodu = array_key_exists('tatil_kodu', $payload)
            ? trim((string) $payload['tatil_kodu'])
            : ($partial ? null : '');
        if ($tatilKodu !== null && $tatilKodu === '' && !$partial) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'tatil_kodu zorunludur.', 400);
        }

        $tatilAdi = array_key_exists('tatil_adi', $payload)
            ? trim((string) $payload['tatil_adi'])
            : ($partial ? null : '');
        if ($tatilAdi !== null && $tatilAdi === '' && !$partial) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'tatil_adi zorunludur.', 400);
        }

        $tatilTuru = strtoupper(trim((string) ($payload['tatil_turu'] ?? 'UBGT')));
        if ($tatilTuru !== 'UBGT' && $tatilTuru !== 'DIGER') {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'tatil_turu gecersiz.', 400);
        }

        $gunKapsami = array_key_exists('gun_kapsami', $payload)
            ? strtoupper(trim((string) $payload['gun_kapsami']))
            : ($partial ? null : '');
        if ($gunKapsami !== null && !in_array($gunKapsami, self::$gunKapsamlari, true)) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'gun_kapsami TAM_GUN veya YARIM_GUN olmalidir.', 400);
        }
        if ($gunKapsami === null && !$partial) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'gun_kapsami zorunludur.', 400);
        }

        $intervalBas = array_key_exists('tatil_interval_baslangic', $payload)
            ? self::optionalTime($payload['tatil_interval_baslangic'])
            : null;
        $intervalBit = array_key_exists('tatil_interval_bitis', $payload)
            ? self::optionalTime($payload['tatil_interval_bitis'])
            : null;

        if ($gunKapsami === 'TAM_GUN') {
            if ($intervalBas !== null || $intervalBit !== null) {
                throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'TAM_GUN icin interval alanlari bos olmalidir.', 400);
            }
        } elseif ($gunKapsami === 'YARIM_GUN') {
            if ($intervalBas === null || $intervalBit === null) {
                throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'YARIM_GUN icin interval baslangic/bitis zorunludur.', 400);
            }
            if ($intervalBas >= $intervalBit) {
                throw new ResmiTatilTakvimiException(
                    'VALIDATION_ERROR',
                    'YARIM_GUN interval bitis baslangictan buyuk olmalidir (gece yarısı gecisi desteklenmez).',
                    400
                );
            }
        }

        $kaynakTuru = array_key_exists('kaynak_turu', $payload)
            ? trim((string) $payload['kaynak_turu'])
            : ($partial ? null : '');
        if ($kaynakTuru !== null && $kaynakTuru === '' && !$partial) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'kaynak_turu zorunludur.', 400);
        }

        $kaynakRef = array_key_exists('kaynak_referansi', $payload)
            ? trim((string) $payload['kaynak_referansi'])
            : ($partial ? null : '');
        if ($kaynakRef !== null && $kaynakRef === '' && !$partial) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'kaynak_referansi zorunludur.', 400);
        }

        $kaynakTarih = array_key_exists('kaynak_tarihi', $payload)
            ? self::optionalDate($payload['kaynak_tarihi'])
            : null;
        $aciklama = array_key_exists('aciklama', $payload)
            ? (trim((string) $payload['aciklama']) ?: null)
            : null;

        return [
            'tarih' => $tarih,
            'tatil_kodu' => $tatilKodu,
            'tatil_adi' => $tatilAdi,
            'tatil_turu' => $tatilTuru,
            'gun_kapsami' => $gunKapsami,
            'tatil_interval_baslangic' => $intervalBas,
            'tatil_interval_bitis' => $intervalBit,
            'kaynak_turu' => $kaynakTuru,
            'kaynak_referansi' => $kaynakRef,
            'kaynak_tarihi' => $kaynakTarih,
            'aciklama' => $aciklama,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function fetch(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM resmi_tatil_takvimi WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function fetchForUpdate(PDO $pdo, $id)
    {
        $sql = 'SELECT * FROM resmi_tatil_takvimi WHERE id = :id LIMIT 1';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'tarih' => (string) $row['tarih'],
            'tatil_kodu' => (string) $row['tatil_kodu'],
            'tatil_adi' => (string) $row['tatil_adi'],
            'tatil_turu' => (string) $row['tatil_turu'],
            'gun_kapsami' => (string) $row['gun_kapsami'],
            'tatil_interval_baslangic' => $row['tatil_interval_baslangic'] !== null
                ? (string) $row['tatil_interval_baslangic'] : null,
            'tatil_interval_bitis' => $row['tatil_interval_bitis'] !== null
                ? (string) $row['tatil_interval_bitis'] : null,
            'durum' => (string) $row['durum'],
            'kaynak_turu' => (string) $row['kaynak_turu'],
            'kaynak_referansi' => (string) $row['kaynak_referansi'],
            'kaynak_tarihi' => $row['kaynak_tarihi'] !== null ? (string) $row['kaynak_tarihi'] : null,
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'revizyon_no' => (int) $row['revizyon_no'],
            'onceki_kayit_id' => $row['onceki_kayit_id'] !== null ? (int) $row['onceki_kayit_id'] : null,
            'yapan_kullanici_id' => $row['yapan_kullanici_id'] !== null ? (int) $row['yapan_kullanici_id'] : null,
            'yapan_ad' => $row['yapan_ad'] ?? null,
            'iptal_edildi_at' => $row['iptal_edildi_at'] ?? null,
            'iptal_eden_kullanici_id' => $row['iptal_eden_kullanici_id'] !== null
                ? (int) $row['iptal_eden_kullanici_id'] : null,
            'iptal_gerekcesi' => $row['iptal_gerekcesi'] !== null ? (string) $row['iptal_gerekcesi'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed>|null $onceki @param array<string, mixed>|null $sonraki */
    private static function audit(PDO $pdo, $aksiyon, $onceki, $sonraki, array $actor, $requestHash, $kayitId)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO resmi_tatil_takvim_auditleri (
                kayit_id, aksiyon, onceki_snapshot, sonraki_snapshot, actor_id, actor_rol, request_hash
             ) VALUES (:kayit, :aksiyon, :onceki, :sonraki, :actor, :rol, :hash)'
        );
        $stmt->execute([
            'kayit' => $kayitId,
            'aksiyon' => (string) $aksiyon,
            'onceki' => $onceki ? json_encode($onceki, JSON_UNESCAPED_UNICODE) : null,
            'sonraki' => $sonraki ? json_encode($sonraki, JSON_UNESCAPED_UNICODE) : null,
            'actor' => self::actorId($actor),
            'rol' => isset($actor['rol']) ? (string) $actor['rol'] : null,
            'hash' => $requestHash,
        ]);
    }

    /** @param array<string, mixed> $actor */
    private static function actorId(array $actor)
    {
        return isset($actor['id']) ? (int) $actor['id'] : null;
    }

    private static function validDate($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'Gecersiz tarih.', 400);
        }

        return $value;
    }

    private static function optionalDate($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::validDate($value);
    }

    private static function optionalTime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            throw new ResmiTatilTakvimiException('VALIDATION_ERROR', 'Gecersiz saat formati.', 400);
        }
        if (strlen($value) === 5) {
            return $value . ':00';
        }

        return $value;
    }
}
