<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class PuantajController
{
    /** @var string[] */
    private static $writeRoles = ['GENEL_YONETICI', 'BOLUM_YONETICISI', 'MUHASEBE'];

    /** @var string[] */
    private static $muhurRoles = ['GENEL_YONETICI', 'BOLUM_YONETICISI'];

    /** @var string[] */
    private static $gunTipleri = ['Normal_Is_Gunu', 'Hafta_Tatili_Pazar', 'UBGT_Resmi_Tatil'];

    /** @var string[] */
    private static $hareketDurumlari = ['Geldi', 'Gelmedi', 'Gec_Geldi', 'Erken_Cikti'];

    /** @var string[] */
    private static $dayanaklar = [
        'Yok_Izinsiz',
        'Ucretli_Izinli',
        'Raporlu_Hastalik',
        'Raporlu_Is_Kazasi',
        'Yillik_Izin',
        'Telafi_Calismasi',
    ];

    /** @var string[] */
    private static $hesapEtkileri = [
        'Tam_Yevmiye_Ver',
        'Yevmiye_Kes',
        'Ucretli_Izin',
        'Raporlu',
        'Mesai_Yaz',
        'Telafi',
    ];

    /** @var string[] */
    private static $kontrolDurumlari = ['BEKLIYOR', 'AMIR_KONTROL_ETTI'];

    public static function detail(Request $request, $personelId, $tarih)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $personelId = (int) $personelId;
        $tarih = self::normalizeDate($tarih);

        if ($personelId <= 0 || $tarih === null) {
            JsonResponse::badRequest('Gecersiz puantaj parametreleri.');
        }

        $pdo = self::getConnection();
        $personel = self::loadPersonel($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        $row = self::findPuantajRow($pdo, $personelId, $tarih);
        if (!$row) {
            JsonResponse::success(null);
        }

        JsonResponse::success(self::mapRow($row));
    }

    public static function upsert(Request $request, $personelId, $tarih)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::requireRole($user, self::$writeRoles);

        $personelId = (int) $personelId;
        $tarih = self::normalizeDate($tarih);
        if ($personelId <= 0 || $tarih === null) {
            JsonResponse::badRequest('Gecersiz puantaj parametreleri.');
        }

        $pdo = self::getConnection();
        $personel = self::loadPersonel($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);
        self::assertPeriodOpen($pdo, (int) $personel['sube_id'], $tarih);

        $existing = self::findPuantajRow($pdo, $personelId, $tarih);
        if ($existing && (string) ($existing['state'] ?? 'ACIK') === 'MUHURLENDI') {
            JsonResponse::error(409, 'PERIOD_LOCKED', 'Bu donem muhurlenmis, puantaj kaydi guncellenemez.');
        }

        $payload = $request->getJsonBody();
        $values = self::buildUpsertValues($payload, $existing ?: [], $personelId, $tarih);

        if ($existing) {
            self::updatePuantajRow($pdo, (int) $existing['id'], $values);
        } else {
            self::insertPuantajRow($pdo, $values);
        }

        $row = self::findPuantajRow($pdo, $personelId, $tarih);
        JsonResponse::success(self::mapRow($row ?: $values));
    }

    public static function muhurleAylik(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        self::requireRole($user, self::$muhurRoles);

        $payload = $request->getJsonBody();
        $yil = self::readRequiredInt($payload, 'yil', 2000, 2100);
        $ay = self::readRequiredInt($payload, 'ay', 1, 12);
        $donem = sprintf('%04d-%02d', $yil, $ay);

        $pdo = self::getConnection();
        $subeId = SubeScope::resolveScope($user, $request);
        if ($subeId === null) {
            JsonResponse::badRequest('Muhurlenecek donem icin aktif sube secilmelidir.', 'VALIDATION_ERROR', 'sube_id');
        }

        self::assertSubeExists($pdo, (int) $subeId);

        $existing = self::findMonthlySeal($pdo, (int) $subeId, $yil, $ay);
        if ($existing) {
            JsonResponse::success([
                'muhur_id' => (int) $existing['id'],
                'sube_id' => (int) $existing['sube_id'],
                'yil' => (int) $existing['yil'],
                'ay' => (int) $existing['ay'],
                'donem' => (string) $existing['donem'],
                'durum' => (string) $existing['durum'],
                'muhurlenen_kayit_sayisi' => 0,
            ]);
        }

        $firstDay = sprintf('%04d-%02d-01', $yil, $ay);
        $lastDay = date('Y-m-t', strtotime($firstDay));

        try {
            $pdo->beginTransaction();

            $insertSeal = $pdo->prepare(
                'INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
                 VALUES (:sube_id, :yil, :ay, :donem, :durum, 0, :created_by)'
            );
            $insertSeal->execute([
                'sube_id' => (int) $subeId,
                'yil' => $yil,
                'ay' => $ay,
                'donem' => $donem,
                'durum' => 'MUHURLENDI',
                'created_by' => isset($user['id']) ? (int) $user['id'] : null,
            ]);
            $muhurId = (int) $pdo->lastInsertId();

            $rows = self::selectRowsForSeal($pdo, (int) $subeId, $firstDay, $lastDay);
            self::insertSealRows($pdo, $muhurId, $rows);

            $ids = array_map(static function ($row) {
                return (int) $row['id'];
            }, $rows);
            if ($ids) {
                self::markRowsSealed($pdo, $muhurId, $ids);
            }

            $updateSeal = $pdo->prepare(
                'UPDATE puantaj_aylik_muhurleri
                 SET muhurlenen_kayit_sayisi = :muhurlenen_kayit_sayisi
                 WHERE id = :id'
            );
            $updateSeal->execute([
                'muhurlenen_kayit_sayisi' => count($rows),
                'id' => $muhurId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Puantaj donemi muhurlenemedi.');
        }

        JsonResponse::success([
            'muhur_id' => $muhurId,
            'sube_id' => (int) $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => $donem,
            'durum' => 'MUHURLENDI',
            'muhurlenen_kayit_sayisi' => count($rows),
        ]);
    }

    /** @return PDO */
    private static function getConnection()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    /** @param array<string, mixed> $user @param string[] $roles */
    private static function requireRole(array $user, array $roles)
    {
        $role = (string) ($user['rol'] ?? '');
        if (!in_array($role, $roles, true)) {
            JsonResponse::forbidden('Bu islem icin yetkin yok.');
        }
    }

    private static function normalizeDate($value)
    {
        $date = rawurldecode((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $parts = explode('-', $date);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private static function loadPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id, sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$personel) {
            JsonResponse::notFound('Personel bulunamadi.');
        }

        return $personel;
    }

    private static function assertSubeExists(PDO $pdo, $subeId)
    {
        $stmt = $pdo->prepare('SELECT id FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $subeId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::badRequest('Secili sube bulunamadi.', 'VALIDATION_ERROR', 'sube_id');
        }
    }

    /** @return array<string, mixed>|false */
    private static function findPuantajRow(PDO $pdo, $personelId, $tarih)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM gunluk_puantaj WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1'
        );
        $stmt->execute([
            'personel_id' => $personelId,
            'tarih' => $tarih,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function assertPeriodOpen(PDO $pdo, $subeId, $tarih)
    {
        $period = self::periodFromDate($tarih);
        $seal = self::findMonthlySeal($pdo, $subeId, (int) $period['yil'], (int) $period['ay']);
        if ($seal) {
            JsonResponse::error(409, 'PERIOD_LOCKED', 'Bu donem muhurlenmis, puantaj kaydi guncellenemez.');
        }
    }

    /** @return array<string, int> */
    private static function periodFromDate($tarih)
    {
        return [
            'yil' => (int) substr($tarih, 0, 4),
            'ay' => (int) substr($tarih, 5, 2),
        ];
    }

    /** @return array<string, mixed>|false */
    private static function findMonthlySeal(PDO $pdo, $subeId, $yil, $ay)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM puantaj_aylik_muhurleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
             LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $existing @return array<string, mixed> */
    private static function buildUpsertValues(array $payload, array $existing, $personelId, $tarih)
    {
        return [
            'personel_id' => $personelId,
            'tarih' => $tarih,
            'state' => 'ACIK',
            'gun_tipi' => self::readEnum($payload, 'gun_tipi', self::$gunTipleri, self::existingValue($existing, 'gun_tipi')),
            'hareket_durumu' => self::readEnum(
                $payload,
                'hareket_durumu',
                self::$hareketDurumlari,
                self::existingValue($existing, 'hareket_durumu')
            ),
            'dayanak' => self::readEnum($payload, 'dayanak', self::$dayanaklar, self::existingValue($existing, 'dayanak')),
            'durumu_bildirdi_mi' => self::readBoolean($payload, 'durumu_bildirdi_mi', self::existingValue($existing, 'durumu_bildirdi_mi')),
            'durum_bildirim_aciklamasi' => self::readText(
                $payload,
                'durum_bildirim_aciklamasi',
                self::existingValue($existing, 'durum_bildirim_aciklamasi')
            ),
            'hesap_etkisi' => self::readEnum($payload, 'hesap_etkisi', self::$hesapEtkileri, self::existingValue($existing, 'hesap_etkisi')),
            'beklenen_giris_saati' => self::readTime($payload, 'beklenen_giris_saati', self::existingValue($existing, 'beklenen_giris_saati')),
            'beklenen_cikis_saati' => self::readTime($payload, 'beklenen_cikis_saati', self::existingValue($existing, 'beklenen_cikis_saati')),
            'giris_saati' => self::readTime($payload, 'giris_saati', self::existingValue($existing, 'giris_saati')),
            'cikis_saati' => self::readTime($payload, 'cikis_saati', self::existingValue($existing, 'cikis_saati')),
            'gercek_mola_dakika' => self::readNullableInt($payload, 'gercek_mola_dakika', self::existingValue($existing, 'gercek_mola_dakika')),
            'hesaplanan_mola_dakika' => self::readNullableInt(
                $payload,
                'hesaplanan_mola_dakika',
                self::existingValue($existing, 'hesaplanan_mola_dakika')
            ),
            'net_calisma_suresi_dakika' => self::readNullableInt(
                $payload,
                'net_calisma_suresi_dakika',
                self::existingValue($existing, 'net_calisma_suresi_dakika')
            ),
            'gunluk_brut_sure_dakika' => self::readNullableInt(
                $payload,
                'gunluk_brut_sure_dakika',
                self::existingValue($existing, 'gunluk_brut_sure_dakika')
            ),
            'hafta_tatili_hak_kazandi_mi' => self::readBoolean(
                $payload,
                'hafta_tatili_hak_kazandi_mi',
                self::existingValue($existing, 'hafta_tatili_hak_kazandi_mi')
            ),
            'kontrol_durumu' => self::readEnum(
                $payload,
                'kontrol_durumu',
                self::$kontrolDurumlari,
                self::existingValue($existing, 'kontrol_durumu') ?: 'BEKLIYOR'
            ),
            'kaynak' => self::readText($payload, 'kaynak', self::existingValue($existing, 'kaynak')),
            'aciklama' => self::readText($payload, 'aciklama', self::existingValue($existing, 'aciklama')),
            'muhur_id' => null,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function existingValue(array $row, $key)
    {
        return array_key_exists($key, $row) ? $row[$key] : null;
    }

    /** @param array<string, mixed> $payload @param string[] $allowed */
    private static function readEnum(array $payload, $field, array $allowed, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        if (!in_array($value, $allowed, true)) {
            JsonResponse::badRequest('Gecersiz puantaj alani.', 'VALIDATION_ERROR', $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function readTime(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            JsonResponse::badRequest('Gecersiz saat formati.', 'VALIDATION_ERROR', $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function readNullableInt(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback === null ? null : (int) $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (int) $value < 0) {
            JsonResponse::badRequest('Gecersiz sayisal puantaj alani.', 'VALIDATION_ERROR', $field);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $payload */
    private static function readBoolean(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            if ($fallback === null) {
                return null;
            }
            return (int) $fallback === 1 || $fallback === true ? 1 : 0;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
            return (int) $value;
        }

        if ($value === 'true' || $value === 'false') {
            return $value === 'true' ? 1 : 0;
        }

        JsonResponse::badRequest('Gecersiz boolean puantaj alani.', 'VALIDATION_ERROR', $field);
    }

    /** @param array<string, mixed> $payload */
    private static function readText(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    /** @param array<string, mixed> $payload */
    private static function readRequiredInt(array $payload, $field, $min, $max)
    {
        if (!array_key_exists($field, $payload) || !is_numeric($payload[$field])) {
            JsonResponse::badRequest('Gecersiz puantaj parametresi.', 'VALIDATION_ERROR', $field);
        }

        $value = (int) $payload[$field];
        if ($value < $min || $value > $max) {
            JsonResponse::badRequest('Gecersiz puantaj parametresi.', 'VALIDATION_ERROR', $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function insertPuantajRow(PDO $pdo, array $values)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO gunluk_puantaj
             (personel_id, tarih, state, gun_tipi, hareket_durumu, dayanak, durumu_bildirdi_mi,
              durum_bildirim_aciklamasi, hesap_etkisi, beklenen_giris_saati, beklenen_cikis_saati,
              giris_saati, cikis_saati, gercek_mola_dakika, hesaplanan_mola_dakika,
              net_calisma_suresi_dakika, gunluk_brut_sure_dakika, hafta_tatili_hak_kazandi_mi,
              kontrol_durumu, kaynak, aciklama, muhur_id)
             VALUES
             (:personel_id, :tarih, :state, :gun_tipi, :hareket_durumu, :dayanak, :durumu_bildirdi_mi,
              :durum_bildirim_aciklamasi, :hesap_etkisi, :beklenen_giris_saati, :beklenen_cikis_saati,
              :giris_saati, :cikis_saati, :gercek_mola_dakika, :hesaplanan_mola_dakika,
              :net_calisma_suresi_dakika, :gunluk_brut_sure_dakika, :hafta_tatili_hak_kazandi_mi,
              :kontrol_durumu, :kaynak, :aciklama, :muhur_id)'
        );
        $stmt->execute($values);
    }

    /** @param array<string, mixed> $values */
    private static function updatePuantajRow(PDO $pdo, $id, array $values)
    {
        $values['id'] = $id;
        $stmt = $pdo->prepare(
            'UPDATE gunluk_puantaj
             SET state = :state,
                 gun_tipi = :gun_tipi,
                 hareket_durumu = :hareket_durumu,
                 dayanak = :dayanak,
                 durumu_bildirdi_mi = :durumu_bildirdi_mi,
                 durum_bildirim_aciklamasi = :durum_bildirim_aciklamasi,
                 hesap_etkisi = :hesap_etkisi,
                 beklenen_giris_saati = :beklenen_giris_saati,
                 beklenen_cikis_saati = :beklenen_cikis_saati,
                 giris_saati = :giris_saati,
                 cikis_saati = :cikis_saati,
                 gercek_mola_dakika = :gercek_mola_dakika,
                 hesaplanan_mola_dakika = :hesaplanan_mola_dakika,
                 net_calisma_suresi_dakika = :net_calisma_suresi_dakika,
                 gunluk_brut_sure_dakika = :gunluk_brut_sure_dakika,
                 hafta_tatili_hak_kazandi_mi = :hafta_tatili_hak_kazandi_mi,
                 kontrol_durumu = :kontrol_durumu,
                 kaynak = :kaynak,
                 aciklama = :aciklama,
                 muhur_id = :muhur_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute($values);
    }

    /** @return array<int, array<string, mixed>> */
    private static function selectRowsForSeal(PDO $pdo, $subeId, $firstDay, $lastDay)
    {
        $stmt = $pdo->prepare(
            'SELECT gp.*
             FROM gunluk_puantaj gp
             INNER JOIN personeller p ON p.id = gp.personel_id
             WHERE p.sube_id = :sube_id
               AND gp.tarih BETWEEN :first_day AND :last_day
               AND gp.state <> :sealed_state
             ORDER BY gp.tarih ASC, gp.personel_id ASC'
        );
        $stmt->execute([
            'sube_id' => $subeId,
            'first_day' => $firstDay,
            'last_day' => $lastDay,
            'sealed_state' => 'MUHURLENDI',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function insertSealRows(PDO $pdo, $muhurId, array $rows)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO puantaj_aylik_muhur_satirlari
             (muhur_id, personel_id, tarih, gun_tipi, hareket_durumu, dayanak, durumu_bildirdi_mi,
              durum_bildirim_aciklamasi, hesap_etkisi, beklenen_giris_saati, beklenen_cikis_saati,
              giris_saati, cikis_saati, gercek_mola_dakika, hesaplanan_mola_dakika,
              net_calisma_suresi_dakika, gunluk_brut_sure_dakika, hafta_tatili_hak_kazandi_mi,
              kontrol_durumu, kaynak, aciklama)
             VALUES
             (:muhur_id, :personel_id, :tarih, :gun_tipi, :hareket_durumu, :dayanak, :durumu_bildirdi_mi,
              :durum_bildirim_aciklamasi, :hesap_etkisi, :beklenen_giris_saati, :beklenen_cikis_saati,
              :giris_saati, :cikis_saati, :gercek_mola_dakika, :hesaplanan_mola_dakika,
              :net_calisma_suresi_dakika, :gunluk_brut_sure_dakika, :hafta_tatili_hak_kazandi_mi,
              :kontrol_durumu, :kaynak, :aciklama)'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'muhur_id' => $muhurId,
                'personel_id' => (int) $row['personel_id'],
                'tarih' => $row['tarih'],
                'gun_tipi' => $row['gun_tipi'],
                'hareket_durumu' => $row['hareket_durumu'],
                'dayanak' => $row['dayanak'],
                'durumu_bildirdi_mi' => $row['durumu_bildirdi_mi'],
                'durum_bildirim_aciklamasi' => $row['durum_bildirim_aciklamasi'],
                'hesap_etkisi' => $row['hesap_etkisi'],
                'beklenen_giris_saati' => $row['beklenen_giris_saati'],
                'beklenen_cikis_saati' => $row['beklenen_cikis_saati'],
                'giris_saati' => $row['giris_saati'],
                'cikis_saati' => $row['cikis_saati'],
                'gercek_mola_dakika' => $row['gercek_mola_dakika'],
                'hesaplanan_mola_dakika' => $row['hesaplanan_mola_dakika'],
                'net_calisma_suresi_dakika' => $row['net_calisma_suresi_dakika'],
                'gunluk_brut_sure_dakika' => $row['gunluk_brut_sure_dakika'],
                'hafta_tatili_hak_kazandi_mi' => $row['hafta_tatili_hak_kazandi_mi'],
                'kontrol_durumu' => $row['kontrol_durumu'] ?: 'BEKLIYOR',
                'kaynak' => $row['kaynak'],
                'aciklama' => $row['aciklama'],
            ]);
        }
    }

    /** @param int[] $ids */
    private static function markRowsSealed(PDO $pdo, $muhurId, array $ids)
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            'UPDATE gunluk_puantaj
             SET state = ?, muhur_id = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge(['MUHURLENDI', $muhurId], $ids));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapRow(array $row)
    {
        return [
            'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'],
            'gun_tipi' => $row['gun_tipi'],
            'hareket_durumu' => $row['hareket_durumu'],
            'dayanak' => $row['dayanak'],
            'durumu_bildirdi_mi' => self::mapNullableBool($row['durumu_bildirdi_mi'] ?? null),
            'durum_bildirim_aciklamasi' => $row['durum_bildirim_aciklamasi'] ?? null,
            'hesap_etkisi' => $row['hesap_etkisi'],
            'beklenen_giris_saati' => $row['beklenen_giris_saati'] ?? null,
            'beklenen_cikis_saati' => $row['beklenen_cikis_saati'] ?? null,
            'giris_saati' => $row['giris_saati'],
            'cikis_saati' => $row['cikis_saati'],
            'gercek_mola_dakika' => self::mapNullableInt($row['gercek_mola_dakika'] ?? null),
            'hesaplanan_mola_dakika' => self::mapNullableInt($row['hesaplanan_mola_dakika'] ?? null),
            'net_calisma_suresi_dakika' => self::mapNullableInt($row['net_calisma_suresi_dakika'] ?? null),
            'gunluk_brut_sure_dakika' => self::mapNullableInt($row['gunluk_brut_sure_dakika'] ?? null),
            'hafta_tatili_hak_kazandi_mi' => self::mapNullableBool($row['hafta_tatili_hak_kazandi_mi'] ?? null),
            'state' => $row['state'] ?? 'ACIK',
            'kontrol_durumu' => $row['kontrol_durumu'] ?: 'BEKLIYOR',
            'compliance_uyarilari' => [],
        ];
    }

    private static function mapNullableInt($value)
    {
        return $value === null ? null : (int) $value;
    }

    private static function mapNullableBool($value)
    {
        if ($value === null) {
            return null;
        }

        return (int) $value === 1;
    }
}
