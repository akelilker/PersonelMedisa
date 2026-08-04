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
        self::assertPrepare($pdo, $actor);

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
        self::assertPrepare($pdo, $actor);

        $surumKodu = (string) ($payload['katalog_surumu'] ?? $payload['surum_kodu'] ?? '');
        if ($surumKodu === '') {
            return self::result(400, 'SGK_KATALOG_SURUM_EKSIK', 'katalog_surumu / surum_kodu zorunludur.');
        }

        $existing = self::fetchSurumByKodu($pdo, $surumKodu);
        if ($existing === null) {
            return self::result(404, 'SGK_KATALOG_SURUM_BULUNAMADI', 'Katalog surumu bulunamadi.');
        }

        $tamlik = self::resolveTamlikForTransition($pdo, $existing, $payload);

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
        self::assertApprove($pdo, $actor);

        $surumKodu = (string) ($payload['katalog_surumu'] ?? $payload['surum_kodu'] ?? '');
        if ($surumKodu === '') {
            return self::result(400, 'SGK_KATALOG_SURUM_EKSIK', 'katalog_surumu / surum_kodu zorunludur.');
        }

        $existing = self::fetchSurumByKodu($pdo, $surumKodu);
        if ($existing === null) {
            return self::result(404, 'SGK_KATALOG_SURUM_BULUNAMADI', 'Katalog surumu bulunamadi.');
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $hazirlayanId = (int) ($existing['hazirlayan_id'] ?? 0);
        $self = SgkKararPaketiAuthz::denySelfApproval($actor, $hazirlayanId);
        if (empty($self['ok'])) {
            return self::result(403, (string) $self['code'], (string) $self['message']);
        }
        $samePerson = SgkKararPaketiAuthz::denySamePerson($pdo, $actor, $hazirlayanId);
        if (empty($samePerson['ok'])) {
            return self::result(403, (string) $samePerson['code'], (string) $samePerson['message']);
        }

        if (self::hasApprovedCatalogOverlap($pdo, $existing)) {
            return self::result(409, 'SGK_KATALOG_TARIH_CAKISMA', 'Onay oncesi baska ONAYLANDI katalog ile tarih cakismasi var.');
        }

        $tamlik = self::resolveTamlikForTransition($pdo, $existing, $payload);

        $transition = SgkKatalogOnayService::validateTransition([
            'current_state' => (string) ($existing['state'] ?? 'TASLAK'),
            'action' => 'APPROVE',
            'actor_id' => $actorId,
            'hazirlayan_id' => $hazirlayanId > 0 ? $hazirlayanId : null,
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
     * @param array{id?: int, rol?: string, username?: string, durum?: string, sube_ids?: list<int>} $actor
     */
    private static function assertPrepare(PDO $pdo, array $actor): void
    {
        SgkKararPaketiAuthz::assertPrepare($pdo, $actor);
    }

    /**
     * @param array{id?: int, rol?: string, username?: string, durum?: string, sube_ids?: list<int>, actor_identity_id?: int|null, actor_identity_status?: string|null} $actor
     */
    private static function assertApprove(PDO $pdo, array $actor): void
    {
        SgkKararPaketiAuthz::assertApprove($pdo, $actor);
    }

    /** @param array<string,mixed> $existing */
    private static function hasApprovedCatalogOverlap(PDO $pdo, array $existing): bool
    {
        $id = (int) ($existing['id'] ?? 0);
        $bas = (string) ($existing['gecerlilik_baslangic'] ?? '');
        if ($id <= 0 || $bas === '' || !SgkKatalogContracts::isDate($bas)) {
            return false;
        }
        $bit = $existing['gecerlilik_bitis'] ?? null;
        $end = is_string($bit) && $bit !== '' ? $bit : '9999-12-31';

        $excludeIds = [$id];
        $aciklama = (string) ($existing['aciklama'] ?? '');
        if (preg_match('/parent=([A-Za-z0-9._-]+)/', $aciklama, $matches) === 1) {
            $parent = self::fetchSurumByKodu($pdo, (string) $matches[1]);
            if ($parent !== null) {
                $excludeIds[] = (int) ($parent['id'] ?? 0);
            }
        }
        $excludeIds = array_values(array_unique(array_filter($excludeIds, static fn (int $v) => $v > 0)));
        $notIn = implode(',', array_fill(0, count($excludeIds), '?'));

        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM sgk_eksik_gun_katalog_surumleri
                 WHERE state = 'ONAYLANDI' AND id NOT IN ($notIn)
                   AND gecerlilik_baslangic <= ?
                   AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= ?)
                 LIMIT 1"
            );
            $params = array_merge($excludeIds, [$end, $bas]);
            $stmt->execute($params);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return false;
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
     * Panel/read path: return stored ONAYLANDI catalog tamlik without re-evaluating an empty package.
     *
     * @return array<string,mixed>|null
     */
    public static function storedApprovedTamlik(PDO $pdo): ?array
    {
        try {
            $stmt = $pdo->query(
                "SELECT * FROM sgk_eksik_gun_katalog_surumleri
                 WHERE state = 'ONAYLANDI'
                   AND tamlik_durumu IN ('RESMI_KAYNAKLI_KISITLI', 'DOGRULANMIS_TAM')
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $existing = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        } catch (PDOException $e) {
            return null;
        }
        if (!is_array($existing)) {
            return null;
        }

        $snapshot = self::resolveTamlikForTransition($pdo, $existing, []);
        $snapshot['kaynak_sayisi'] = self::countManifestLinks($pdo, (int) ($existing['id'] ?? 0));
        $snapshot['katalog_payload_hash'] = (string) ($existing['katalog_payload_hash'] ?? '');
        $snapshot['state'] = (string) ($existing['state'] ?? '');

        return $snapshot;
    }

    /**
     * Submit/approve may send only katalog_surumu.
     * Explicit kod/package rows trigger fresh tamlik evaluation (initial catalog import path).
     * Controller-injected manifests alone must NOT force empty-package re-evaluation —
     * that breaks esleme successor submit which already carries persisted parent evidence.
     * Request manifests are never the security owner for successor transitions.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function resolveTamlikForTransition(PDO $pdo, array $existing, array $payload): array
    {
        if (isset($payload['tamlik_input']) && is_array($payload['tamlik_input'])) {
            return SgkKatalogTamlikService::evaluate($payload['tamlik_input']);
        }

        // Require explicit package rows/kodlar — manifests-only (controller inject) uses stored path.
        $hasExplicitRows = !empty($payload['rows']) || !empty($payload['kod_satirlari']);
        if ($hasExplicitRows) {
            $flags = is_array($payload['tamlik'] ?? null) ? $payload['tamlik'] : [];
            $kodSatirlari = !empty($payload['rows']) && is_array($payload['rows'])
                ? $payload['rows']
                : (is_array($payload['kod_satirlari'] ?? null) ? $payload['kod_satirlari'] : []);

            return SgkKatalogTamlikService::evaluate(array_merge($flags, [
                'katalog_surumu' => (string) ($existing['surum_kodu'] ?? ''),
                'manifests' => $payload['manifests'] ?? [],
                'kod_satirlari' => $kodSatirlari,
            ]));
        }

        return self::resolveStoredSurumTamlik($pdo, $existing);
    }

    /**
     * Trust persisted surum tamlik + kod snapshot. For esleme successors, also require
     * approved parent catalog evidence and combined source-hash continuity.
     *
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private static function resolveStoredSurumTamlik(PDO $pdo, array $existing): array
    {
        $stored = strtoupper((string) ($existing['tamlik_durumu'] ?? 'TASLAK'));
        $approved = in_array($stored, ['RESMI_KAYNAKLI_KISITLI', 'DOGRULANMIS_TAM'], true);
        $surumId = (int) ($existing['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = :id');
        $stmt->execute(['id' => $surumId]);
        $kodSayisi = (int) $stmt->fetchColumn();

        $eslemeSayisi = 0;
        if ($surumId > 0) {
            try {
                $eslemeStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM sgk_surec_neden_eslemeleri WHERE katalog_surum_id = :id'
                );
                $eslemeStmt->execute(['id' => $surumId]);
                $eslemeSayisi = (int) $eslemeStmt->fetchColumn();
            } catch (PDOException $e) {
                $eslemeSayisi = 0;
            }
        }

        $blockers = [];
        if (!$approved || $kodSayisi <= 0) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Kayitli surum tamlik/kod snapshot submit/approve icin yetersiz.',
                'Once gecerli resmi paket import edin.'
            );
        }

        if ($eslemeSayisi > 0) {
            $parentEvidence = self::assertEslemeSuccessorParentEvidence($pdo, $existing);
            if (empty($parentEvidence['ok'])) {
                foreach ($parentEvidence['blockers'] as $blocker) {
                    $blockers[] = $blocker;
                }
            }
        }

        $onaylanabilir = $approved && $kodSayisi > 0 && $blockers === [];

        return [
            'tamlik_durumu' => $stored,
            'katalog_surumu' => (string) ($existing['surum_kodu'] ?? ''),
            'manifest_set_hash' => (string) ($existing['manifest_set_hash'] ?? ''),
            'kod_sayisi' => $kodSayisi,
            'esleme_sayisi' => $eslemeSayisi,
            'blocker_kodlari' => array_values(array_map(static function (array $b) {
                return $b['code'];
            }, $blockers)),
            'blocker_detaylari' => $blockers,
            'onaylanabilir_mi' => $onaylanabilir,
            'dogrulanmis_tam_secilebilir_mi' => $stored === 'DOGRULANMIS_TAM',
            'import_yazma_aktif_mi' => $approved,
            'approve_aktif_mi' => $approved,
            'successor_evidence_owner' => $eslemeSayisi > 0 ? 'PERSISTED_APPROVED_PARENT_CATALOG' : 'STORED_SURUM_SNAPSHOT',
        ];
    }

    /**
     * Esleme successor packages store parent + esleme hash in aciklama at import time.
     * Completeness owner is the persisted approved parent — not request manifests.
     *
     * @param array<string,mixed> $existing
     * @return array{ok: bool, blockers: list<array<string,mixed>>}
     */
    private static function assertEslemeSuccessorParentEvidence(PDO $pdo, array $existing): array
    {
        $blockers = [];
        $aciklama = (string) ($existing['aciklama'] ?? '');
        $parentKodu = '';
        $eslemeHash = '';
        if (preg_match('/parent=([A-Za-z0-9._\\-]+)/', $aciklama, $parentMatch)) {
            $parentKodu = (string) $parentMatch[1];
        }
        if (preg_match('/esleme=([a-f0-9]{64})/i', $aciklama, $eslemeMatch)) {
            $eslemeHash = strtolower((string) $eslemeMatch[1]);
        }

        if ($parentKodu === '') {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Esleme successor parent iliskisi cozulemedi.',
                'Parent katalog referansi kayitli aciklamada bulunamadi.'
            );

            return ['ok' => false, 'blockers' => $blockers];
        }

        $parent = self::fetchSurumByKodu($pdo, $parentKodu);
        if ($parent === null) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Esleme successor parent katalog bulunamadi.',
                'Parent katalog kaydini dogrulayin.'
            );

            return ['ok' => false, 'blockers' => $blockers];
        }

        if ((string) ($parent['state'] ?? '') !== 'ONAYLANDI') {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Esleme successor parent katalog ONAYLANDI degil.',
                'Parent katalog onayli olmadan successor submit/approve yapilamaz.'
            );
        }

        $parentTamlik = strtoupper((string) ($parent['tamlik_durumu'] ?? ''));
        if (!in_array($parentTamlik, ['RESMI_KAYNAKLI_KISITLI', 'DOGRULANMIS_TAM'], true)) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Esleme successor parent katalog tamlik kaniti eksik.',
                'Parent katalog RESMI_KAYNAKLI_KISITLI veya DOGRULANMIS_TAM olmalidir.'
            );
        }

        $parentHash = (string) ($parent['katalog_payload_hash'] ?? '');
        $successorHash = (string) ($existing['katalog_payload_hash'] ?? '');
        if ($eslemeHash !== '' && $parentHash !== '' && $successorHash !== '') {
            $expected = hash('sha256', $parentHash . '|' . $eslemeHash);
            if (!hash_equals($expected, $successorHash)) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_TAMLIK,
                    'Esleme successor parent source-hash uyusmuyor.',
                    'Parent katalog hash drift; yeni successor import gerekli.'
                );
            }
        }

        return ['ok' => $blockers === [], 'blockers' => $blockers];
    }

    private static function countManifestLinks(PDO $pdo, int $surumId): int
    {
        if ($surumId <= 0) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT kaynak_manifest_id) FROM sgk_eksik_gun_kodlari
                 WHERE katalog_surum_id = :id AND kaynak_manifest_id IS NOT NULL'
            );
            $stmt->execute(['id' => $surumId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
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
