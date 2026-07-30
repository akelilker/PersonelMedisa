<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;
use PDOException;
use RuntimeException;

/**
 * S106: Transactional SGK catalog import / submit / approve against PDO.
 */
final class SgkKatalogWriteService
{
    /**
     * @param array{id?: int, rol?: string} $actor
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function import(PDO $pdo, array $actor, array $payload): array
    {
        self::assertGenelYonetici($actor);

        $dry = SgkKatalogImportValidator::dryRun($payload);
        if (($dry['hatali_satirlar'] ?? []) !== []) {
            return self::result(400, 'SGK_KATALOG_IMPORT_GECERSIZ', 'Import dry-run hatali satir iceriyor.', [
                'dry_run' => $dry,
            ]);
        }
        if (empty($dry['import_yapilabilir_mi'])) {
            return self::result(400, SgkKatalogContracts::BLOCKER_TAMLIK, 'Import tamlik kapisi veya yapisal dogrulama gecilmedi.', [
                'dry_run' => $dry,
            ]);
        }

        $rows = $dry['canonical_payload']['rows'] ?? [];
        if ($rows === []) {
            return self::result(400, 'SGK_KATALOG_BOS_PAKET', 'Import icin en az bir gecerli satir gerekir.', [
                'dry_run' => $dry,
            ]);
        }

        $surumKodu = (string) ($rows[0]['katalog_surumu'] ?? '');
        if ($surumKodu === '') {
            return self::result(400, 'SGK_KATALOG_SURUM_EKSIK', 'katalog_surumu zorunludur.');
        }

        $payloadHash = (string) ($dry['payload_hash'] ?? '');
        $manifestSetHash = (string) ($dry['manifest_set_hash'] ?? '');
        $tamlikDurumu = (string) ($dry['tamlik']['tamlik_durumu'] ?? 'TASLAK');
        $aciklama = (string) ($payload['aciklama'] ?? 'S106 resmi kaynakli kisitli katalog import');
        $manifestMap = self::resolveManifestIds($pdo, $payload['manifests'] ?? []);

        try {
            $pdo->beginTransaction();

            $existing = self::fetchSurumByKodu($pdo, $surumKodu);
            if ($existing !== null) {
                if ((string) ($existing['state'] ?? '') === 'ONAYLANDI') {
                    $existingHash = (string) ($existing['katalog_payload_hash'] ?? '');
                    if ($existingHash !== '' && hash_equals($existingHash, $payloadHash)) {
                        $pdo->commit();

                        return self::result(200, 'SGK_KATALOG_IMPORT_IDEMPOTENT', 'Ayni hash ile onayli surum zaten mevcut.', [
                            'surum_id' => (int) $existing['id'],
                            'surum_kodu' => $surumKodu,
                            'state' => 'ONAYLANDI',
                            'payload_hash' => $payloadHash,
                            'idempotent_mi' => true,
                        ]);
                    }
                    if ($existingHash !== '' && !hash_equals($existingHash, $payloadHash)) {
                        $pdo->rollBack();

                        return self::result(409, 'SGK_KATALOG_SURUM_CAKISMA', 'Onayli surum farkli payload hash ile degistirilemez.', [
                            'surum_kodu' => $surumKodu,
                            'mevcut_hash' => $existingHash,
                            'gelen_hash' => $payloadHash,
                        ]);
                    }
                } elseif ((string) ($existing['katalog_payload_hash'] ?? '') !== ''
                    && !hash_equals((string) $existing['katalog_payload_hash'], $payloadHash)
                    && (string) ($existing['state'] ?? '') !== 'IPTAL') {
                    $pdo->rollBack();

                    return self::result(409, 'SGK_KATALOG_SURUM_CAKISMA', 'Ayni surum kodu farkli payload ile cakisiyor.', [
                        'surum_kodu' => $surumKodu,
                    ]);
                }
            }

            $versionDates = self::deriveVersionDates($rows, $payload['manifests'] ?? []);
            $actorId = (int) ($actor['id'] ?? 0);

            if ($existing === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO sgk_eksik_gun_katalog_surumleri
                     (surum_kodu, gecerlilik_baslangic, gecerlilik_bitis, tamlik_durumu, state,
                      manifest_set_hash, aciklama, hazirlayan_id, katalog_payload_hash)
                     VALUES
                     (:surum_kodu, :gecerlilik_baslangic, :gecerlilik_bitis, :tamlik_durumu, :state,
                      :manifest_set_hash, :aciklama, :hazirlayan_id, :katalog_payload_hash)'
                );
                $insert->execute([
                    'surum_kodu' => $surumKodu,
                    'gecerlilik_baslangic' => $versionDates['baslangic'],
                    'gecerlilik_bitis' => $versionDates['bitis'],
                    'tamlik_durumu' => $tamlikDurumu,
                    'state' => 'TASLAK',
                    'manifest_set_hash' => $manifestSetHash,
                    'aciklama' => $aciklama,
                    'hazirlayan_id' => $actorId > 0 ? $actorId : null,
                    'katalog_payload_hash' => $payloadHash,
                ]);
                $surumId = (int) $pdo->lastInsertId();
            } else {
                $surumId = (int) $existing['id'];
                $update = $pdo->prepare(
                    'UPDATE sgk_eksik_gun_katalog_surumleri
                     SET gecerlilik_baslangic = :gecerlilik_baslangic,
                         gecerlilik_bitis = :gecerlilik_bitis,
                         tamlik_durumu = :tamlik_durumu,
                         state = :state,
                         manifest_set_hash = :manifest_set_hash,
                         aciklama = :aciklama,
                         hazirlayan_id = :hazirlayan_id,
                         katalog_payload_hash = :katalog_payload_hash
                     WHERE id = :id'
                );
                $update->execute([
                    'gecerlilik_baslangic' => $versionDates['baslangic'],
                    'gecerlilik_bitis' => $versionDates['bitis'],
                    'tamlik_durumu' => $tamlikDurumu,
                    'state' => 'TASLAK',
                    'manifest_set_hash' => $manifestSetHash,
                    'aciklama' => $aciklama,
                    'hazirlayan_id' => $actorId > 0 ? $actorId : null,
                    'katalog_payload_hash' => $payloadHash,
                    'id' => $surumId,
                ]);
            }

            $pdo->prepare('DELETE FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = :id')
                ->execute(['id' => $surumId]);

            self::insertKodRows($pdo, $surumId, $rows, $manifestMap, $actorId);

            $pdo->commit();

            return self::result(200, 'SGK_KATALOG_IMPORT_OK', 'Katalog surumu import edildi.', [
                'surum_id' => $surumId,
                'surum_kodu' => $surumKodu,
                'state' => 'TASLAK',
                'tamlik_durumu' => $tamlikDurumu,
                'payload_hash' => $payloadHash,
                'manifest_set_hash' => $manifestSetHash,
                'satir_sayisi' => count($rows),
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::result(500, 'SGK_KATALOG_IMPORT_HATASI', 'Katalog import transaction basarisiz.');
        }
    }

    /**
     * @param array{id?: int, rol?: string} $actor
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function submit(PDO $pdo, array $actor, array $payload): array
    {
        self::assertGenelYonetici($actor);

        $surumKodu = (string) ($payload['katalog_surumu'] ?? $payload['surum_kodu'] ?? '');
        if ($surumKodu === '') {
            return self::result(400, 'SGK_KATALOG_SURUM_EKSIK', 'katalog_surumu / surum_kodu zorunludur.');
        }

        $existing = self::fetchSurumByKodu($pdo, $surumKodu);
        if ($existing === null) {
            return self::result(404, 'SGK_KATALOG_SURUM_BULUNAMADI', 'Katalog surumu bulunamadi.');
        }

        $tamlik = SgkKatalogTamlikService::evaluate($payload['tamlik_input'] ?? [
            'katalog_surumu' => $surumKodu,
            'manifests' => $payload['manifests'] ?? [],
            'kod_satirlari' => $payload['rows'] ?? [],
        ]);

        $transition = SgkKatalogOnayService::validateTransition([
            'current_state' => (string) ($existing['state'] ?? 'TASLAK'),
            'action' => 'SUBMIT',
            'tamlik' => $tamlik,
            'katalog_hash' => (string) ($existing['katalog_payload_hash'] ?? ''),
            'manifest_set_hash' => (string) ($existing['manifest_set_hash'] ?? ''),
        ]);

        if (empty($transition['allowed_mi'])) {
            return self::result(400, SgkKatalogContracts::BLOCKER_TAMLIK, 'Submit transition reddedildi.', [
                'transition' => $transition,
            ]);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE sgk_eksik_gun_katalog_surumleri
                 SET state = 'ONAY_BEKLIYOR', tamlik_durumu = :tamlik_durumu
                 WHERE id = :id"
            )->execute([
                'tamlik_durumu' => (string) ($tamlik['tamlik_durumu'] ?? 'TASLAK'),
                'id' => (int) $existing['id'],
            ]);
            $pdo->commit();

            return self::result(200, 'SGK_KATALOG_SUBMIT_OK', 'Katalog surumu onay bekliyor.', [
                'surum_id' => (int) $existing['id'],
                'surum_kodu' => $surumKodu,
                'state' => 'ONAY_BEKLIYOR',
                'tamlik_durumu' => (string) ($tamlik['tamlik_durumu'] ?? 'TASLAK'),
                'transition' => $transition,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::result(500, 'SGK_KATALOG_SUBMIT_HATASI', 'Submit transaction basarisiz.');
        }
    }

    /**
     * @param array{id?: int, rol?: string} $actor
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function approve(PDO $pdo, array $actor, array $payload): array
    {
        self::assertGenelYonetici($actor);

        $surumKodu = (string) ($payload['katalog_surumu'] ?? $payload['surum_kodu'] ?? '');
        if ($surumKodu === '') {
            return self::result(400, 'SGK_KATALOG_SURUM_EKSIK', 'katalog_surumu / surum_kodu zorunludur.');
        }

        $existing = self::fetchSurumByKodu($pdo, $surumKodu);
        if ($existing === null) {
            return self::result(404, 'SGK_KATALOG_SURUM_BULUNAMADI', 'Katalog surumu bulunamadi.');
        }

        $tamlik = SgkKatalogTamlikService::evaluate($payload['tamlik_input'] ?? [
            'katalog_surumu' => $surumKodu,
            'manifests' => $payload['manifests'] ?? [],
            'kod_satirlari' => $payload['rows'] ?? [],
        ]);

        $transition = SgkKatalogOnayService::validateTransition([
            'current_state' => (string) ($existing['state'] ?? 'TASLAK'),
            'action' => 'APPROVE',
            'actor_id' => (int) ($actor['id'] ?? 0),
            'tamlik' => $tamlik,
            'katalog_hash' => (string) ($existing['katalog_payload_hash'] ?? ''),
            'manifest_set_hash' => (string) ($existing['manifest_set_hash'] ?? ''),
            'resmi_kaynaklar_incelendi_mi' => !empty($payload['resmi_kaynaklar_incelendi_mi']),
            'belirsiz_tarihler_uydurulmadi_mi' => !empty($payload['belirsiz_tarihler_uydurulmadi_mi']),
            'kisitli_kullanim_kabul_edildi_mi' => !empty($payload['kisitli_kullanim_kabul_edildi_mi']),
        ]);

        if (empty($transition['allowed_mi'])) {
            return self::result(400, SgkKatalogContracts::BLOCKER_ATTESTATION, 'Approve transition reddedildi.', [
                'transition' => $transition,
            ]);
        }

        $actorId = (int) ($actor['id'] ?? 0);

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE sgk_eksik_gun_katalog_surumleri
                 SET state = 'ONAYLANDI',
                     tamlik_durumu = :tamlik_durumu,
                     onaylayan_id = :onaylayan_id,
                     onay_zamani = UTC_TIMESTAMP(),
                     resmi_kaynaklar_incelendi_mi = :resmi_kaynaklar_incelendi_mi,
                     belirsiz_tarihler_uydurulmadi_mi = :belirsiz_tarihler_uydurulmadi_mi,
                     kisitli_kullanim_kabul_edildi_mi = :kisitli_kullanim_kabul_edildi_mi
                 WHERE id = :id"
            )->execute([
                'tamlik_durumu' => (string) ($tamlik['tamlik_durumu'] ?? 'RESMI_KAYNAKLI_KISITLI'),
                'onaylayan_id' => $actorId > 0 ? $actorId : null,
                'resmi_kaynaklar_incelendi_mi' => !empty($payload['resmi_kaynaklar_incelendi_mi']) ? 1 : 0,
                'belirsiz_tarihler_uydurulmadi_mi' => !empty($payload['belirsiz_tarihler_uydurulmadi_mi']) ? 1 : 0,
                'kisitli_kullanim_kabul_edildi_mi' => !empty($payload['kisitli_kullanim_kabul_edildi_mi']) ? 1 : 0,
                'id' => (int) $existing['id'],
            ]);
            $pdo->commit();

            return self::result(200, 'SGK_KATALOG_APPROVE_OK', 'Katalog surumu onaylandi.', [
                'surum_id' => (int) $existing['id'],
                'surum_kodu' => $surumKodu,
                'state' => 'ONAYLANDI',
                'tamlik_durumu' => (string) ($tamlik['tamlik_durumu'] ?? 'RESMI_KAYNAKLI_KISITLI'),
                'transition' => $transition,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::result(500, 'SGK_KATALOG_APPROVE_HATASI', 'Approve transaction basarisiz.');
        }
    }

    /**
     * @param array{id?: int, rol?: string} $actor
     */
    private static function assertGenelYonetici(array $actor): void
    {
        if (strtoupper((string) ($actor['rol'] ?? '')) !== 'GENEL_YONETICI') {
            throw new RuntimeException('SGK_KATALOG_WRITE_FORBIDDEN');
        }
    }

    /** @return array<string,mixed>|null */
    private static function fetchSurumByKodu(PDO $pdo, string $surumKodu): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM sgk_eksik_gun_katalog_surumleri WHERE surum_kodu = :kodu LIMIT 1');
        $stmt->execute(['kodu' => $surumKodu]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<array<string,mixed>> $manifests
     * @return array<string,int>
     */
    private static function resolveManifestIds(PDO $pdo, array $manifests): array
    {
        $map = [];
        $stmt = $pdo->prepare('SELECT id FROM sgk_kaynak_manifestleri WHERE kaynak_id = :kid LIMIT 1');
        foreach ($manifests as $manifest) {
            $kid = (string) ($manifest['kaynak_id'] ?? $manifest['id'] ?? '');
            if ($kid === '' || isset($map[$kid])) {
                continue;
            }
            $stmt->execute(['kid' => $kid]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                $map[$kid] = (int) $id;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $manifests
     * @return array{baslangic: string, bitis: ?string}
     */
    private static function deriveVersionDates(array $rows, array $manifests): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $bas = $row['gecerlilik_baslangic'] ?? null;
            if (is_string($bas) && SgkKatalogContracts::isDate($bas)) {
                $candidates[] = $bas;
            }
            $ilk = $row['ilk_resmi_kanit_tarihi'] ?? null;
            if (is_string($ilk) && SgkKatalogContracts::isDate($ilk)) {
                $candidates[] = $ilk;
            }
        }
        foreach ($manifests as $manifest) {
            $mBas = $manifest['yururluk_baslangic'] ?? null;
            if (is_string($mBas) && SgkKatalogContracts::isDate($mBas)) {
                $candidates[] = $mBas;
            }
        }
        sort($candidates, SORT_STRING);
        $baslangic = $candidates[0] ?? '2011-01-01';

        return ['baslangic' => $baslangic, 'bitis' => null];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,int> $manifestMap
     */
    private static function insertKodRows(PDO $pdo, int $surumId, array $rows, array $manifestMap, int $actorId): void
    {
        $sql = 'INSERT INTO sgk_eksik_gun_kodlari (
            katalog_surum_id, eksik_gun_kodu, resmi_aciklama,
            gecerlilik_baslangic, gecerlilik_bitis, gecerlilik_tarih_durumu, ilk_resmi_kanit_tarihi,
            kaynak_manifest_id, belge_zorunlulugu,
            sifir_gun_sifir_kazanc_kullanilabilir_mi, kismi_sureli_sozlesme_gerekli_mi,
            tek_basina_kullanilabilir_mi, diger_nedenlerle_birlikte_kullanim, aktif_mi,
            aktiflik_durumu, sifir_gun_sifir_kazanc_durumu, belge_saklama_ibraz_durumu,
            yabanci_kullanim_durumu, portal_teyit_durumu, kosullar_json, mevzuat_kurallari_json,
            created_by
        ) VALUES (
            :katalog_surum_id, :eksik_gun_kodu, :resmi_aciklama,
            :gecerlilik_baslangic, :gecerlilik_bitis, :gecerlilik_tarih_durumu, :ilk_resmi_kanit_tarihi,
            :kaynak_manifest_id, :belge_zorunlulugu,
            :sifir_gun_sifir_kazanc_kullanilabilir_mi, :kismi_sureli_sozlesme_gerekli_mi,
            :tek_basina_kullanilabilir_mi, :diger_nedenlerle_birlikte_kullanim, :aktif_mi,
            :aktiflik_durumu, :sifir_gun_sifir_kazanc_durumu, :belge_saklama_ibraz_durumu,
            :yabanci_kullanim_durumu, :portal_teyit_durumu, :kosullar_json, :mevzuat_kurallari_json,
            :created_by
        )';
        $stmt = $pdo->prepare($sql);

        foreach ($rows as $row) {
            $manifestKey = (string) ($row['kaynak_manifest_id'] ?? '');
            if (!isset($manifestMap[$manifestKey])) {
                throw new RuntimeException('SGK_KATALOG_MANIFEST_ID_COZULEMEDI:' . $manifestKey);
            }
            $kosullar = $row['kosullar'] ?? null;
            $kosullarJson = $kosullar !== null ? json_encode($kosullar, JSON_UNESCAPED_UNICODE) : null;
            $mevzuat = $row['mevzuat_kurallari_json'] ?? null;
            $mevzuatJson = is_array($mevzuat) || is_object($mevzuat)
                ? json_encode($mevzuat, JSON_UNESCAPED_UNICODE)
                : (is_string($mevzuat) ? $mevzuat : null);

            $stmt->execute([
                'katalog_surum_id' => $surumId,
                'eksik_gun_kodu' => (string) ($row['eksik_gun_kodu'] ?? ''),
                'resmi_aciklama' => (string) ($row['resmi_aciklama'] ?? ''),
                'gecerlilik_baslangic' => $row['gecerlilik_baslangic'] ?? null,
                'gecerlilik_bitis' => $row['gecerlilik_bitis'] ?? null,
                'gecerlilik_tarih_durumu' => (string) ($row['gecerlilik_tarih_durumu'] ?? 'BELIRLENEMEDI'),
                'ilk_resmi_kanit_tarihi' => $row['ilk_resmi_kanit_tarihi'] ?? null,
                'kaynak_manifest_id' => $manifestMap[$manifestKey],
                'belge_zorunlulugu' => (string) ($row['belge_zorunlulugu'] ?? 'KOSULLU'),
                'sifir_gun_sifir_kazanc_kullanilabilir_mi' => !empty($row['sifir_gun_sifir_kazanc_kullanilabilir_mi']) ? 1 : 0,
                'kismi_sureli_sozlesme_gerekli_mi' => !empty($row['kismi_sureli_sozlesme_gerekli_mi']) ? 1 : 0,
                'tek_basina_kullanilabilir_mi' => !empty($row['tek_basina_kullanilabilir_mi']) ? 1 : 0,
                'diger_nedenlerle_birlikte_kullanim' => (string) ($row['diger_nedenlerle_birlikte_kullanim'] ?? 'KOSULLU'),
                'aktif_mi' => !empty($row['aktif_mi']) ? 1 : 0,
                'aktiflik_durumu' => (string) ($row['aktiflik_durumu'] ?? 'PORTAL_TEYIT_BEKLIYOR'),
                'sifir_gun_sifir_kazanc_durumu' => (string) ($row['sifir_gun_sifir_kazanc_durumu'] ?? 'TEYITSIZ'),
                'belge_saklama_ibraz_durumu' => (string) ($row['belge_saklama_ibraz_durumu'] ?? 'TEYITSIZ'),
                'yabanci_kullanim_durumu' => (string) ($row['yabanci_kullanim_durumu'] ?? 'TEYITSIZ'),
                'portal_teyit_durumu' => (string) ($row['portal_teyit_durumu'] ?? 'TEYIT_BEKLIYOR'),
                'kosullar_json' => $kosullarJson,
                'mevzuat_kurallari_json' => $mevzuatJson,
                'created_by' => $actorId > 0 ? $actorId : null,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function result(int $httpStatus, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'http_status' => $httpStatus,
            'code' => $code,
            'message' => $message,
            'ok' => $httpStatus >= 200 && $httpStatus < 300,
        ], $extra);
    }
}
