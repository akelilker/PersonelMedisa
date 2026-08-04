<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;
use PDOException;

/**
 * S98: Transactional süreç→SGK mapping import into TASLAK successor catalog (parent ONAYLANDI immutable).
 */
final class SgkSurecEslemeWriteService
{
    public const CONFIRMATION_TEXT = 'SUREC_ESLEME_DRAFT_ONAY';

    /**
     * @param array{id?: int, rol?: string} $actor
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function import(PDO $pdo, array $actor, array $payload): array
    {
        self::assertPrepare($pdo, $actor);

        if ((string) ($payload['confirmation_text'] ?? '') !== self::CONFIRMATION_TEXT) {
            return self::result(400, 'SGK_ESLEME_ONAY_METNI_GECERSIZ', 'confirmation_text SUREC_ESLEME_DRAFT_ONAY olmalidir.');
        }

        $expectedHash = (string) ($payload['esleme_payload_hash'] ?? '');
        $dry = SgkSurecEslemeImportValidator::dryRun($pdo, $payload);
        if (($dry['hatali_satirlar'] ?? []) !== []) {
            return self::result(400, 'SGK_ESLEME_IMPORT_GECERSIZ', 'Esleme dry-run hatali satir iceriyor.', ['dry_run' => $dry]);
        }
        if (empty($dry['apply_yapilabilir_mi'])) {
            return self::result(400, 'SGK_ESLEME_IMPORT_HAZIR_DEGIL', 'Tum karar satirlari tamamlanmadan import yapilamaz.', ['dry_run' => $dry]);
        }
        if ($expectedHash === '' || !hash_equals($expectedHash, (string) ($dry['esleme_payload_hash'] ?? ''))) {
            return self::result(409, 'SGK_ESLEME_HASH_UYUSMAZligi', 'esleme_payload_hash dry-run ile eslesmiyor.', ['dry_run' => $dry]);
        }

        $parent = $dry['parent_surum'] ?? null;
        if (!is_array($parent)) {
            return self::result(400, 'SGK_ESLEME_PARENT_EKSIK', 'parent_surum_kodu zorunludur.');
        }

        $parentRow = self::fetchSurumByKodu($pdo, (string) $parent['surum_kodu']);
        if ($parentRow === null || (string) ($parentRow['state'] ?? '') !== 'ONAYLANDI') {
            return self::result(400, 'SGK_ESLEME_PARENT_ONAYLI_DEGIL', 'Parent katalog ONAYLANDI olmalidir.');
        }

        $parentId = (int) $parentRow['id'];
        $parentPayloadHash = (string) ($parentRow['katalog_payload_hash'] ?? '');
        $eslemeHash = (string) $dry['esleme_payload_hash'];
        $successorKodu = (string) ($payload['successor_surum_kodu'] ?? '');
        if ($successorKodu === '') {
            $successorKodu = (string) $parent['surum_kodu'] . '-ESLEME-' . substr($eslemeHash, 0, 8);
        }

        $combinedHash = hash('sha256', $parentPayloadHash . '|' . $eslemeHash);
        $canonicalRows = $dry['canonical_rows'] ?? [];
        $actorId = (int) ($actor['id'] ?? 0);

        $existingSuccessor = self::fetchSurumByKodu($pdo, $successorKodu);
        if ($existingSuccessor !== null && (string) ($existingSuccessor['state'] ?? '') === 'ONAYLANDI') {
            $existingHash = (string) ($existingSuccessor['katalog_payload_hash'] ?? '');
            if ($existingHash !== '' && hash_equals($existingHash, $combinedHash)) {
                return self::result(200, 'SGK_ESLEME_IMPORT_IDEMPOTENT', 'Ayni hash ile onayli successor zaten mevcut.', [
                    'surum_id' => (int) $existingSuccessor['id'],
                    'surum_kodu' => $successorKodu,
                    'state' => 'ONAYLANDI',
                    'katalog_payload_hash' => $combinedHash,
                    'esleme_payload_hash' => $eslemeHash,
                    'idempotent_mi' => true,
                ]);
            }

            return self::result(409, 'SGK_ESLEME_SUCCESSOR_ONAYLI', 'Onayli successor degistirilemez; yeni surum kodu kullanin.');
        }

        if ($existingSuccessor !== null
            && (string) ($existingSuccessor['state'] ?? '') === 'TASLAK'
            && (string) ($existingSuccessor['katalog_payload_hash'] ?? '') === $combinedHash) {
            return self::result(200, 'SGK_ESLEME_IMPORT_IDEMPOTENT', 'Ayni TASLAK successor zaten mevcut.', [
                'surum_id' => (int) $existingSuccessor['id'],
                'surum_kodu' => $successorKodu,
                'state' => 'TASLAK',
                'katalog_payload_hash' => $combinedHash,
                'esleme_payload_hash' => $eslemeHash,
                'idempotent_mi' => true,
            ]);
        }

        try {
            $pdo->beginTransaction();

            $tamlik = (string) ($parentRow['tamlik_durumu'] ?? 'RESMI_KAYNAKLI_KISITLI');
            if (!in_array($tamlik, ['RESMI_KAYNAKLI_KISITLI', 'DOGRULANMIS_TAM'], true)) {
                $tamlik = 'RESMI_KAYNAKLI_KISITLI';
            }

            if ($existingSuccessor === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO sgk_eksik_gun_katalog_surumleri
                     (surum_kodu, gecerlilik_baslangic, gecerlilik_bitis, tamlik_durumu, state,
                      manifest_set_hash, aciklama, hazirlayan_id, katalog_payload_hash)
                     VALUES
                     (:surum_kodu, :gecerlilik_baslangic, :gecerlilik_bitis, :tamlik_durumu, :state,
                      :manifest_set_hash, :aciklama, :hazirlayan_id, :katalog_payload_hash)'
                );
                $insert->execute([
                    'surum_kodu' => $successorKodu,
                    'gecerlilik_baslangic' => $parentRow['gecerlilik_baslangic'],
                    'gecerlilik_bitis' => $parentRow['gecerlilik_bitis'],
                    'tamlik_durumu' => $tamlik,
                    'state' => 'TASLAK',
                    'manifest_set_hash' => (string) ($parentRow['manifest_set_hash'] ?? str_repeat('0', 64)),
                    'aciklama' => 'S98 successor esleme import parent=' . (string) $parentRow['surum_kodu'] . ' esleme=' . $eslemeHash,
                    'hazirlayan_id' => $actorId > 0 ? $actorId : null,
                    'katalog_payload_hash' => $combinedHash,
                ]);
                $successorId = (int) $pdo->lastInsertId();
            } else {
                $successorId = (int) $existingSuccessor['id'];
                if ((string) ($existingSuccessor['state'] ?? '') === 'ONAYLANDI') {
                    $pdo->rollBack();

                    return self::result(409, 'SGK_ESLEME_SUCCESSOR_ONAYLI', 'Onayli successor degistirilemez.');
                }
                $pdo->prepare(
                    'UPDATE sgk_eksik_gun_katalog_surumleri
                     SET gecerlilik_baslangic = :bas, gecerlilik_bitis = :bit, tamlik_durumu = :tamlik,
                         state = :state, manifest_set_hash = :manifest, aciklama = :aciklama,
                         hazirlayan_id = :hazirlayan, katalog_payload_hash = :hash
                     WHERE id = :id'
                )->execute([
                    'bas' => $parentRow['gecerlilik_baslangic'],
                    'bit' => $parentRow['gecerlilik_bitis'],
                    'tamlik' => $tamlik,
                    'state' => 'TASLAK',
                    'manifest' => (string) ($parentRow['manifest_set_hash'] ?? str_repeat('0', 64)),
                    'aciklama' => 'S98 successor esleme import parent=' . (string) $parentRow['surum_kodu'] . ' esleme=' . $eslemeHash,
                    'hazirlayan_id' => $actorId > 0 ? $actorId : null,
                    'hash' => $combinedHash,
                    'id' => $successorId,
                ]);
            }

            // Never touch parent rows — only successor clone + mappings.
            $pdo->prepare('DELETE FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = :id')->execute(['id' => $successorId]);
            $pdo->prepare('DELETE FROM sgk_surec_neden_eslemeleri WHERE katalog_surum_id = :id')->execute(['id' => $successorId]);

            self::cloneParentCodes($pdo, $parentId, $successorId, $actorId);
            self::insertMappings($pdo, $successorId, $canonicalRows, $actorId);

            $pdo->commit();

            return self::result(200, 'SGK_ESLEME_IMPORT_OK', 'Successor katalog ve eslemeler TASLAK olarak kaydedildi.', [
                'surum_id' => $successorId,
                'surum_kodu' => $successorKodu,
                'parent_surum_kodu' => (string) $parentRow['surum_kodu'],
                'state' => 'TASLAK',
                'katalog_payload_hash' => $combinedHash,
                'esleme_payload_hash' => $eslemeHash,
                'esleme_sayisi' => count($canonicalRows),
                'parent_immutable_mi' => true,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::result(500, 'SGK_ESLEME_IMPORT_HATASI', 'Esleme import transaction basarisiz.');
        }
    }

    /**
     * @param array{id?: int, rol?: string, username?: string, durum?: string, sube_ids?: list<int>, personel_id?: int|null} $actor
     */
    private static function assertPrepare(PDO $pdo, array $actor): void
    {
        SgkKararPaketiAuthz::assertPrepare($pdo, $actor);
    }

    /** @return array<string,mixed>|null */
    private static function fetchSurumByKodu(PDO $pdo, string $kodu): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM sgk_eksik_gun_katalog_surumleri WHERE surum_kodu = :kodu LIMIT 1');
        $stmt->execute(['kodu' => $kodu]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function cloneParentCodes(PDO $pdo, int $parentId, int $successorId, int $actorId): void
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
        )
        SELECT
            :successor_id, eksik_gun_kodu, resmi_aciklama,
            gecerlilik_baslangic, gecerlilik_bitis, gecerlilik_tarih_durumu, ilk_resmi_kanit_tarihi,
            kaynak_manifest_id, belge_zorunlulugu,
            sifir_gun_sifir_kazanc_kullanilabilir_mi, kismi_sureli_sozlesme_gerekli_mi,
            tek_basina_kullanilabilir_mi, diger_nedenlerle_birlikte_kullanim, aktif_mi,
            aktiflik_durumu, sifir_gun_sifir_kazanc_durumu, belge_saklama_ibraz_durumu,
            yabanci_kullanim_durumu, portal_teyit_durumu, kosullar_json, mevzuat_kurallari_json,
            :created_by
        FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = :parent_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'successor_id' => $successorId,
            'parent_id' => $parentId,
            'created_by' => $actorId > 0 ? $actorId : null,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function insertMappings(PDO $pdo, int $successorId, array $rows, int $actorId): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO sgk_surec_neden_eslemeleri
             (katalog_surum_id, surec_turu, alt_tur, canonical_surec_turu, eksik_gun_kodu,
              prim_gunu_etkisi, kosullar_json, kaynak_manifest_id, aktif_mi, created_by)
             VALUES
             (:katalog_surum_id, :surec_turu, :alt_tur, :canonical_surec_turu, :eksik_gun_kodu,
              :prim_gunu_etkisi, :kosullar_json, :kaynak_manifest_id, 1, :created_by)'
        );
        foreach ($rows as $row) {
            $kosullar = $row['kosullar_json'] ?? null;
            $kosullarJson = $kosullar !== null ? json_encode($kosullar, JSON_UNESCAPED_UNICODE) : null;
            $stmt->execute([
                'katalog_surum_id' => $successorId,
                'surec_turu' => (string) $row['surec_turu'],
                'alt_tur' => (string) $row['alt_tur'],
                'canonical_surec_turu' => (string) $row['canonical_surec_turu'],
                'eksik_gun_kodu' => isset($row['eksik_gun_kodu']) && $row['eksik_gun_kodu'] !== null && $row['eksik_gun_kodu'] !== ''
                    ? (string) $row['eksik_gun_kodu']
                    : null,
                'prim_gunu_etkisi' => (string) $row['prim_gunu_etkisi'],
                'kosullar_json' => $kosullarJson,
                'kaynak_manifest_id' => (int) $row['kaynak_manifest_id'],
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
