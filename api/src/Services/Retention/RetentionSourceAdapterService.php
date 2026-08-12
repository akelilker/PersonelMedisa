<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use Medisa\Api\Services\PuantajDonemPeriodService;
use PDO;
use RuntimeException;

/**
 * Canonical per-category source identity + server fingerprint adapters.
 * Never trusts client hashes. Missing adapter → RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED.
 */
class RetentionSourceAdapterService
{
    public const CODE_NOT_IMPLEMENTED = 'RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED';

    /** Typed ONAY_AUDIT source — S3F QR puantaj candidate decision ledger. */
    public const AUDIT_SOURCE_QR_PUANTAJ_CANDIDATE_DECISION = 'QR_PUANTAJ_CANDIDATE_DECISION';

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    public static function resolve(PDO $pdo, $category, array $context)
    {
        $category = (string) $category;

        switch ($category) {
            case RetentionCategories::PERSONEL_OZLUK:
                return self::resolvePersonelOzlukLifecycle($pdo, $context);
            case RetentionCategories::ISE_GIRIS_CIKIS:
                return self::resolveIseGirisCikisLifecycle($pdo, $context);
            case RetentionCategories::PERSONEL_BELGE:
                return self::resolvePersonelBelge($pdo, $context);
            case RetentionCategories::PUANTAJ:
                return self::resolvePuantaj($pdo, $context);
            case RetentionCategories::BORDRO:
                return self::resolveBordro($pdo, $context);
            case RetentionCategories::SGK_EKSIK_GUN:
                return self::resolveSgk($pdo, $context);
            case RetentionCategories::FAZLA_CALISMA:
            case RetentionCategories::SERBEST_ZAMAN:
                return self::resolveHaftalik($pdo, $context);
            case RetentionCategories::ONAY_AUDIT:
                return self::resolveOnayAudit($pdo, $context);
            case RetentionCategories::IZIN:
            case RetentionCategories::RAPOR:
            case RetentionCategories::IS_KAZASI:
            case RetentionCategories::DISIPLIN:
                return self::resolveSurecLifecycle($pdo, $category, $context);
            case RetentionCategories::OLAY:
                return self::resolveDisiplinOlay($pdo, $context);
            case RetentionCategories::SAVUNMA:
                return self::resolveDisiplinSavunma($pdo, $context);
            default:
                throw new RuntimeException(self::CODE_NOT_IMPLEMENTED . ':' . $category);
        }
    }

    public static function isImplemented($category)
    {
        return in_array((string) $category, RetentionCategories::all(), true);
    }

    /**
     * Coverage audit map for reports/tests.
     *
     * @return array<string, array{source_resolver: string, manifest_creator: string, server_integrity_fingerprint: string}>
     */
    public static function coverageMap()
    {
        $implemented = [
            RetentionCategories::PERSONEL_OZLUK,
            RetentionCategories::ISE_GIRIS_CIKIS,
            RetentionCategories::PERSONEL_BELGE,
            RetentionCategories::PUANTAJ,
            RetentionCategories::BORDRO,
            RetentionCategories::SGK_EKSIK_GUN,
            RetentionCategories::FAZLA_CALISMA,
            RetentionCategories::SERBEST_ZAMAN,
            RetentionCategories::ONAY_AUDIT,
            RetentionCategories::IZIN,
            RetentionCategories::RAPOR,
            RetentionCategories::IS_KAZASI,
            RetentionCategories::DISIPLIN,
            RetentionCategories::OLAY,
            RetentionCategories::SAVUNMA,
        ];
        // Manifest creators are lifecycle-wired for the full catalog (MG-RET-MAN-001).
        $manifestWired = $implemented;
        $map = [];
        foreach (RetentionCategories::all() as $cat) {
            $ok = in_array($cat, $implemented, true);
            $map[$cat] = [
                'source_resolver' => $ok ? 'implemented' : 'missing',
                'manifest_creator' => in_array($cat, $manifestWired, true) ? 'implemented' : 'missing',
                'server_integrity_fingerprint' => $ok ? 'implemented' : 'missing',
            ];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolvePersonelOzlukLifecycle(PDO $pdo, array $context)
    {
        $personelId = self::personelIdFromContext($context);
        if ($personelId <= 0) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $termination = RetentionPolicyService::resolveTerminationDate($pdo, $personelId);
        if ($termination === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TERMINATION_DATE_MISSING);
        }
        $fp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, $personelId);

        return [
            'source_version_identity' => 'personel:' . $personelId . ':termination:' . $termination,
            'source_sha256' => $fp,
        ];
    }

    /**
     * ISE_GIRIS_CIKIS: QR-aware identity + fingerprint when present/new;
     * same-termination pre-S3C legacy identity uses ozluk fingerprint semantics
     * so existing manifests are not false-CHANGED / false-missing-current.
     *
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveIseGirisCikisLifecycle(PDO $pdo, array $context)
    {
        $personelId = self::personelIdFromContext($context);
        if ($personelId <= 0) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $termination = RetentionPolicyService::resolveTerminationDate($pdo, $personelId);
        if ($termination === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TERMINATION_DATE_MISSING);
        }

        $qrAwareIdentity = ArchiveManifestService::qrAwareIseGirisCikisIdentity($personelId, $termination);
        $qrAwareManifest = ArchiveManifestService::findBySourceIdentity(
            $pdo,
            'personel',
            $personelId,
            RetentionCategories::ISE_GIRIS_CIKIS,
            $qrAwareIdentity
        );
        if ($qrAwareManifest) {
            return [
                'source_version_identity' => $qrAwareIdentity,
                'source_sha256' => ArchiveManifestService::computeIseGirisCikisFingerprint($pdo, $personelId),
            ];
        }

        $legacyIdentity = ArchiveManifestService::legacyIseGirisCikisIdentity($personelId, $termination);
        $legacyManifest = ArchiveManifestService::findBySourceIdentity(
            $pdo,
            'personel',
            $personelId,
            RetentionCategories::ISE_GIRIS_CIKIS,
            $legacyIdentity
        );
        if ($legacyManifest) {
            // Legacy ISE manifests were fingerprinted with personel ozluk fields.
            return [
                'source_version_identity' => $legacyIdentity,
                'source_sha256' => ArchiveManifestService::computePersonelOzlukFingerprint($pdo, $personelId),
            ];
        }

        // No current-lifecycle manifest yet → mint/verify against QR-aware contract.
        return [
            'source_version_identity' => $qrAwareIdentity,
            'source_sha256' => ArchiveManifestService::computeIseGirisCikisFingerprint($pdo, $personelId),
        ];
    }

    /**
     * @deprecated Kept for call-site compatibility during S3C transition; prefer explicit resolvers.
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolvePersonelLifecycle(PDO $pdo, array $context)
    {
        return self::resolvePersonelOzlukLifecycle($pdo, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolvePersonelBelge(PDO $pdo, array $context)
    {
        $recordId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        if ($recordId <= 0 || !self::tableExists($pdo, 'personel_belge_dosya_surumleri')) {
            throw new RuntimeException(self::CODE_NOT_IMPLEMENTED . ':' . RetentionCategories::PERSONEL_BELGE);
        }

        // Canonical owner: aktif dosya sürümü SHA256 (PersonelBelgeKayitRepository).
        $stmt = $pdo->prepare(
            'SELECT id, surec_id, surum_no, sha256
             FROM personel_belge_dosya_surumleri
             WHERE surec_id = :id AND aktif_mi = 1
             ORDER BY surum_no DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['id' => $recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['sha256'])) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        return [
            'source_version_identity' => sprintf(
                'belge_surec:%d:surum:%d:sha256:%s',
                $recordId,
                (int) ($row['surum_no'] ?? 0),
                strtolower((string) $row['sha256'])
            ),
            'source_sha256' => strtolower((string) $row['sha256']),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolvePuantaj(PDO $pdo, array $context)
    {
        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
        $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $seal = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay);
        if ($seal === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }
        $sealId = (int) ($seal['id'] ?? 0);
        $created = (string) ($seal['created_at'] ?? '');
        $identity = sprintf('puantaj_seal:%d:sube:%d:%d:%02d', $sealId, $subeId, $yil, $ay);

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash('sha256', $identity . '|' . $created),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveBordro(PDO $pdo, array $context)
    {
        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
        $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        if (!self::tableExists($pdo, 'maas_hesaplama_calistirmalari')) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }
        $stmt = $pdo->prepare(
            "SELECT id, revision_no, kesinlestirme_at
             FROM maas_hesaplama_calistirmalari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND state = 'HESAPLANDI'
               AND bordro_onay_durumu = 'KESINLESTI'
             ORDER BY revision_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }
        $identity = sprintf(
            'bordro_run:%d:rev:%d:sube:%d:%d:%02d',
            (int) $row['id'],
            (int) ($row['revision_no'] ?? 0),
            $subeId,
            $yil,
            $ay
        );

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash('sha256', $identity . '|' . (string) ($row['kesinlestirme_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveSgk(PDO $pdo, array $context)
    {
        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $yil = isset($context['yil']) ? (int) $context['yil'] : 0;
        $ay = isset($context['ay']) ? (int) $context['ay'] : 0;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        if (!self::tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }
        $stmt = $pdo->prepare(
            "SELECT id, revision_no, cutoff_at
             FROM maas_hesaplama_donem_snapshotlari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND state = 'OLUSTURULDU'
             ORDER BY revision_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }
        $identity = sprintf(
            'sgk_snapshot:%d:rev:%d:sube:%d:%d:%02d',
            (int) $row['id'],
            (int) ($row['revision_no'] ?? 0),
            $subeId,
            $yil,
            $ay
        );

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash('sha256', $identity . '|' . (string) ($row['cutoff_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveHaftalik(PDO $pdo, array $context)
    {
        if (!self::tableExists($pdo, 'haftalik_kapanislar')) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }
        $row = self::loadCanonicalHaftalik($pdo, $context);
        $identity = sprintf(
            'haftalik_kapanis:%d:sube:%d:%s',
            (int) $row['id'],
            (int) $row['sube_id'],
            (string) $row['hafta_baslangic']
        );

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash('sha256', $identity . '|' . (string) ($row['hafta_bitis'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function loadCanonicalHaftalik(PDO $pdo, array $context)
    {
        $haftalikId = isset($context['haftalik_kapanis_id']) ? (int) $context['haftalik_kapanis_id'] : 0;
        $haftaBaslangic = isset($context['hafta_baslangic']) ? trim((string) $context['hafta_baslangic']) : '';
        $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;

        if ($haftalikId > 0) {
            $stmt = $pdo->prepare(
                "SELECT id, sube_id, hafta_baslangic, hafta_bitis, state
                 FROM haftalik_kapanislar WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $haftalikId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string) ($row['state'] ?? '') !== 'KAPANDI') {
                throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
            }
            if ($subeId > 0 && (int) $row['sube_id'] !== $subeId) {
                throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
            }
            if ($haftaBaslangic !== '' && (string) $row['hafta_baslangic'] !== $haftaBaslangic) {
                throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
            }

            return $row;
        }

        if ($haftaBaslangic === '' || $subeId <= 0) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $stmt = $pdo->prepare(
            "SELECT id, sube_id, hafta_baslangic, hafta_bitis, state
             FROM haftalik_kapanislar
             WHERE hafta_baslangic = :hb AND sube_id = :sube_id AND state = 'KAPANDI'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['hb' => $haftaBaslangic, 'sube_id' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(RetentionPolicyService::CODE_PERIOD_NOT_CLOSED);
        }

        return $row;
    }

    /**
     * Generic ONAY_AUDIT derives from parent category.
     * Typed S3F path (audit_source_type=QR_PUANTAJ_CANDIDATE_DECISION) folds ledger material
     * without requiring a sealed PUANTAJ period (MG-RET-S3F-001).
     *
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveOnayAudit(PDO $pdo, array $context)
    {
        $auditSource = isset($context['audit_source_type'])
            ? strtoupper(trim((string) $context['audit_source_type']))
            : '';
        if ($auditSource === self::AUDIT_SOURCE_QR_PUANTAJ_CANDIDATE_DECISION) {
            return self::resolveQrPuantajCandidateDecisionOnayAudit($pdo, $context);
        }

        $parent = isset($context['parent_category']) ? trim((string) $context['parent_category']) : '';
        if ($parent === '' || $parent === RetentionCategories::ONAY_AUDIT || !RetentionCategories::isKnown($parent)) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $parentSource = self::resolve($pdo, $parent, $context);

        return [
            'source_version_identity' => 'onay_audit:parent:' . $parent . ':' . $parentSource['source_version_identity'],
            'source_sha256' => $parentSource['source_sha256'] !== null
                ? hash('sha256', 'onay_audit|' . $parent . '|' . $parentSource['source_sha256'])
                : null,
        ];
    }

    /**
     * Server-owned S3F ledger → ONAY_AUDIT identity + fingerprint.
     * Client cannot supply ledger fields; row is loaded by ledger_id.
     *
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveQrPuantajCandidateDecisionOnayAudit(PDO $pdo, array $context)
    {
        $parent = isset($context['parent_category']) ? trim((string) $context['parent_category']) : '';
        if ($parent === '') {
            $parent = RetentionCategories::PUANTAJ;
        }
        if ($parent !== RetentionCategories::PUANTAJ) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $ledgerId = isset($context['ledger_id']) ? (int) $context['ledger_id'] : 0;
        if ($ledgerId <= 0) {
            $ledgerId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        }
        if ($ledgerId <= 0 || !self::tableExists($pdo, 'qr_puantaj_candidate_decision_ledger')) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $stmt = $pdo->prepare(
            'SELECT id, personel_id, sube_id, candidate_date, candidate_hash, decision_type,
                    decision_reason, puantaj_id, algorithm_version, interval_algorithm_version,
                    decision_algorithm_version, decided_by_user_id, request_nonce,
                    supersedes_decision_id, previous_decision_hash, decision_hash, created_at
             FROM qr_puantaj_candidate_decision_ledger
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $ledgerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
        }

        $ctxPersonel = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        $ctxSube = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
        $ctxDate = isset($context['candidate_date']) ? trim((string) $context['candidate_date']) : '';
        if ($ctxPersonel > 0 && $ctxPersonel !== (int) $row['personel_id']) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        if ($ctxSube > 0 && $ctxSube !== (int) $row['sube_id']) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        if ($ctxDate !== '' && $ctxDate !== substr((string) $row['candidate_date'], 0, 10)) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $decisionHash = strtolower(trim((string) ($row['decision_hash'] ?? '')));
        if ($decisionHash === '' || !preg_match('/^[0-9a-f]{64}$/', $decisionHash)) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        $ledgerFp = self::computeQrPuantajDecisionLedgerFingerprint($row);
        $identity = sprintf(
            'onay_audit:qr_pc_decision:%d:parent:%s:dh:%s',
            (int) $row['id'],
            RetentionCategories::PUANTAJ,
            $decisionHash
        );

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash(
                'sha256',
                'onay_audit|' . RetentionCategories::PUANTAJ . '|qr_pc_decision|' . $ledgerFp
            ),
        ];
    }

    /**
     * Canonical retention fingerprint material for an S3F ledger row (immutable audit fields).
     * Uses stored decision_hash + stable identity fields — never client-supplied hashes.
     *
     * @param array<string, mixed> $row
     */
    public static function computeQrPuantajDecisionLedgerFingerprint(array $row)
    {
        $payload = implode('|', [
            (string) ((int) ($row['id'] ?? 0)),
            (string) ((int) ($row['personel_id'] ?? 0)),
            (string) ((int) ($row['sube_id'] ?? 0)),
            substr((string) ($row['candidate_date'] ?? ''), 0, 10),
            strtolower((string) ($row['candidate_hash'] ?? '')),
            strtoupper((string) ($row['decision_type'] ?? '')),
            (string) ($row['decision_reason'] ?? ''),
            isset($row['puantaj_id']) && $row['puantaj_id'] !== null ? (string) ((int) $row['puantaj_id']) : '',
            (string) ($row['algorithm_version'] ?? ''),
            (string) ($row['interval_algorithm_version'] ?? ''),
            (string) ($row['decision_algorithm_version'] ?? ''),
            (string) ((int) ($row['decided_by_user_id'] ?? 0)),
            strtolower((string) ($row['request_nonce'] ?? '')),
            isset($row['supersedes_decision_id']) && $row['supersedes_decision_id'] !== null
                ? (string) ((int) $row['supersedes_decision_id'])
                : '',
            isset($row['previous_decision_hash']) && $row['previous_decision_hash'] !== null
                ? strtolower((string) $row['previous_decision_hash'])
                : '',
            strtolower((string) ($row['decision_hash'] ?? '')),
            self::normalizeLedgerCreatedAt((string) ($row['created_at'] ?? '')),
        ]);

        return hash('sha256', $payload);
    }

    private static function normalizeLedgerCreatedAt($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(\.(\d{1,6}))?$/', $raw, $m)) {
            $frac = isset($m[3]) ? str_pad($m[3], 6, '0') : '000000';

            return $m[1] . '.' . $frac;
        }

        return $raw;
    }

    /**
     * OLAY: canonical owner = disiplin_vakalar (attendance discipline event).
     *
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveDisiplinOlay(PDO $pdo, array $context)
    {
        $row = self::loadDisiplinVaka($pdo, $context);
        $identity = sprintf(
            'disiplin_vaka:%d:olay:%s:state:%s',
            (int) $row['id'],
            (string) ($row['olay_turu'] ?? ''),
            (string) ($row['lifecycle_state'] ?? '')
        );
        $fpPayload = implode('|', [
            $identity,
            (string) ((int) ($row['personel_id'] ?? 0)),
            (string) ((int) ($row['surec_id'] ?? 0)),
            substr((string) ($row['tarih'] ?? ''), 0, 10),
            strtolower((string) ($row['source_hash'] ?? '')),
            (string) ($row['nihai_karar'] ?? ''),
            (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
        ]);

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash('sha256', $fpPayload),
        ];
    }

    /**
     * SAVUNMA: same disiplin_vakalar row; fingerprint focuses on defense lifecycle fields.
     *
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveDisiplinSavunma(PDO $pdo, array $context)
    {
        $row = self::loadDisiplinVaka($pdo, $context);
        $identity = sprintf(
            'disiplin_vaka:%d:savunma:state:%s',
            (int) $row['id'],
            (string) ($row['lifecycle_state'] ?? '')
        );
        $fpPayload = implode('|', [
            $identity,
            (string) ((int) ($row['personel_id'] ?? 0)),
            substr((string) ($row['savunma_talep_tarihi'] ?? ''), 0, 10),
            (string) ($row['savunma_deadline_at'] ?? ''),
            (string) ($row['savunma_yer'] ?? ''),
            (string) ($row['savunma_konu'] ?? ''),
            isset($row['savunma_belge_surec_id']) && $row['savunma_belge_surec_id'] !== null
                ? (string) ((int) $row['savunma_belge_surec_id'])
                : '',
            (string) ($row['savunma_received_at'] ?? ''),
            (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
        ]);

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash('sha256', $fpPayload),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function loadDisiplinVaka(PDO $pdo, array $context)
    {
        if (!self::tableExists($pdo, 'disiplin_vakalar')) {
            throw new RuntimeException(self::CODE_NOT_IMPLEMENTED . ':' . RetentionCategories::OLAY);
        }

        $recordId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        $entityType = isset($context['entity_type']) ? strtolower((string) $context['entity_type']) : '';
        $vakaId = isset($context['disiplin_vaka_id']) ? (int) $context['disiplin_vaka_id'] : 0;

        if ($vakaId <= 0 && in_array($entityType, ['disiplin_vaka', 'disiplin_vakalar'], true) && $recordId > 0) {
            $vakaId = $recordId;
        }

        $row = null;
        if ($vakaId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM disiplin_vakalar WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $vakaId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif (in_array($entityType, ['surec', 'surecler'], true) && $recordId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM disiplin_vakalar WHERE surec_id = :surec_id LIMIT 1');
            $stmt->execute(['surec_id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$row) {
            throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
        }

        $ctxPersonel = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($ctxPersonel > 0 && $ctxPersonel !== (int) $row['personel_id']) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{source_version_identity: string, source_sha256: string|null}
     */
    private static function resolveSurecLifecycle(PDO $pdo, $category, array $context)
    {
        $entityType = isset($context['entity_type']) ? strtolower((string) $context['entity_type']) : '';
        $recordId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        if (!in_array($entityType, ['surec', 'surecler'], true) || $recordId <= 0) {
            throw new RuntimeException(self::CODE_NOT_IMPLEMENTED . ':' . $category);
        }
        if (!self::tableExists($pdo, 'surecler')) {
            throw new RuntimeException(self::CODE_NOT_IMPLEMENTED . ':' . $category);
        }
        $stmt = $pdo->prepare(
            'SELECT id, personel_id, surec_turu, baslangic_tarihi, state, updated_at, created_at
             FROM surecler WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('RETENTION_TARGET_ENTITY_NOT_FOUND');
        }
        $identity = sprintf(
            'surec:%d:tur:%s:baslangic:%s:state:%s',
            (int) $row['id'],
            (string) ($row['surec_turu'] ?? ''),
            substr((string) ($row['baslangic_tarihi'] ?? ''), 0, 10),
            (string) ($row['state'] ?? '')
        );
        $fpPayload = $identity . '|' . (string) ($row['updated_at'] ?? $row['created_at'] ?? '');

        return [
            'source_version_identity' => $identity,
            'source_sha256' => hash('sha256', $fpPayload),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function personelIdFromContext(array $context)
    {
        if (isset($context['personel_id']) && (int) $context['personel_id'] > 0) {
            return (int) $context['personel_id'];
        }
        $entityType = isset($context['entity_type']) ? strtolower((string) $context['entity_type']) : '';
        if (in_array($entityType, ['personel', 'personeller'], true) && isset($context['record_id'])) {
            return (int) $context['record_id'];
        }

        return 0;
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }
}
