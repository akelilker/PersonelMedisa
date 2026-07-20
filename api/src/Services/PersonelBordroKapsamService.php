<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Http\JsonResponse;
use PDO;
use PDOException;

/**
 * S84-R2 tarih etkili personel bordro kapsam owner'i.
 * Hard-code demo filtresi yok. Yalniz ONAYLANDI+HARIC kayitlar donem setinden cikarir.
 */
class PersonelBordroKapsamService
{
    public const CONTRACT_VERSION = 'S84R2_PAYROLL_SCOPE_V1';

    public const NEDEN_KODLARI = [
        'DEMO_TEST_VERISI',
        'BORDRO_DISI_STATU',
        'HARICI_BORDRO',
        'DIGER_ONAYLI_NEDEN',
    ];

    /**
     * @return array<int, true> personel_id => true
     */
    public static function listExcludedPersonelIds(PDO $pdo, $subeId, $donemBaslangic, $donemBitis)
    {
        if (!self::tableExists($pdo, 'personel_bordro_kapsamlari')) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT personel_id
             FROM personel_bordro_kapsamlari
             WHERE sube_id = :sube
               AND state = 'ONAYLANDI'
               AND durum = 'HARIC'
               AND gecerlilik_baslangic <= :bitis
               AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)"
        );
        $stmt->execute([
            'sube' => (int) $subeId,
            'baslangic' => (string) $donemBaslangic,
            'bitis' => (string) $donemBitis,
        ]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['personel_id']] = true;
        }

        return $out;
    }

    public static function isExcludedForPeriod(PDO $pdo, $personelId, $subeId, $donemBaslangic, $donemBitis)
    {
        $map = self::listExcludedPersonelIds($pdo, $subeId, $donemBaslangic, $donemBitis);

        return isset($map[(int) $personelId]);
    }

    /**
     * Donem icin ONAYLANDI HARIC kayitlarinin deterministic fingerprint'i (source hash parcasi).
     */
    public static function scopeFingerprintForPeriod(PDO $pdo, $subeId, $donemBaslangic, $donemBitis)
    {
        if (!self::tableExists($pdo, 'personel_bordro_kapsamlari')) {
            return self::emptyScopeFingerprint();
        }
        $stmt = $pdo->prepare(
            "SELECT id, personel_id, durum, neden_kodu, gecerlilik_baslangic, gecerlilik_bitis, state
             FROM personel_bordro_kapsamlari
             WHERE sube_id = :sube
               AND state = 'ONAYLANDI'
               AND gecerlilik_baslangic <= :bitis
               AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)
             ORDER BY personel_id ASC, id ASC"
        );
        $stmt->execute([
            'sube' => (int) $subeId,
            'baslangic' => (string) $donemBaslangic,
            'bitis' => (string) $donemBitis,
        ]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'personel_id' => (int) $row['personel_id'],
                'durum' => (string) $row['durum'],
                'neden_kodu' => (string) $row['neden_kodu'],
                'gecerlilik_baslangic' => (string) $row['gecerlilik_baslangic'],
                'gecerlilik_bitis' => $row['gecerlilik_bitis'] !== null ? (string) $row['gecerlilik_bitis'] : null,
                'state' => (string) $row['state'],
            ];
        }

        return hash('sha256', json_encode([
            'contract' => self::CONTRACT_VERSION,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function emptyScopeFingerprint()
    {
        return hash('sha256', json_encode([
            'contract' => self::CONTRACT_VERSION,
            'rows' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForPersonel(PDO $pdo, $personelId)
    {
        if (!self::tableExists($pdo, 'personel_bordro_kapsamlari')) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT k.*, p.sicil_no, p.ad, p.soyad
             FROM personel_bordro_kapsamlari k
             INNER JOIN personeller p ON p.id = k.personel_id
             WHERE k.personel_id = :pid
             ORDER BY k.gecerlilik_baslangic DESC, k.id DESC"
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::serialize($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function dryRun(PDO $pdo, array $payload, array $user)
    {
        $personel = self::requirePersonel($pdo, (int) ($payload['personel_id'] ?? 0));
        $normalized = self::normalizePayload($payload, $personel);
        self::assertNoOverlap($pdo, (int) $personel['id'], $normalized, null);

        $donemBaslangic = (string) ($payload['donem_baslangic'] ?? $normalized['gecerlilik_baslangic']);
        $donemBitis = (string) ($payload['donem_bitis'] ?? ($normalized['gecerlilik_bitis'] ?? $normalized['gecerlilik_baslangic']));
        if (isset($payload['yil'], $payload['ay'])) {
            $yil = (int) $payload['yil'];
            $ay = (int) $payload['ay'];
            $donemBaslangic = sprintf('%04d-%02d-01', $yil, $ay);
            $donemBitis = date('Y-m-t', strtotime($donemBaslangic));
        }

        $wouldExclude = $normalized['durum'] === 'HARIC'
            && in_array((string) ($payload['preview_state'] ?? 'ONAYLANDI'), ['ONAYLANDI'], true);

        $existingExcluded = self::isExcludedForPeriod(
            $pdo,
            (int) $personel['id'],
            (int) $personel['sube_id'],
            $donemBaslangic,
            $donemBitis
        );

        $activeSnapshot = null;
        if (self::tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            $yil = (int) substr($donemBaslangic, 0, 4);
            $ay = (int) substr($donemBaslangic, 5, 2);
            $s = $pdo->prepare(
                "SELECT id, snapshot_hash, source_hash, revision_no, state
                 FROM maas_hesaplama_donem_snapshotlari
                 WHERE sube_id = :sube AND yil = :yil AND ay = :ay AND state = 'OLUSTURULDU'
                 ORDER BY revision_no DESC, id DESC LIMIT 1"
            );
            $s->execute(['sube' => (int) $personel['sube_id'], 'yil' => $yil, 'ay' => $ay]);
            $activeSnapshot = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $sealedCount = 0;
        if (self::tableExists($pdo, 'puantaj_aylik_muhur_satirlari') && self::tableExists($pdo, 'puantaj_aylik_muhurleri')) {
            $m = $pdo->prepare(
                "SELECT COUNT(*) FROM puantaj_aylik_muhur_satirlari s
                 INNER JOIN puantaj_aylik_muhurleri m ON m.id = s.muhur_id
                 WHERE s.personel_id = :pid AND m.sube_id = :sube
                   AND m.yil = :yil AND m.ay = :ay AND m.durum = 'MUHURLENDI'"
            );
            $m->execute([
                'pid' => (int) $personel['id'],
                'sube' => (int) $personel['sube_id'],
                'yil' => (int) substr($donemBaslangic, 0, 4),
                'ay' => (int) substr($donemBaslangic, 5, 2),
            ]);
            $sealedCount = (int) $m->fetchColumn();
        }

        $previewHash = hash('sha256', json_encode([
            'contract' => self::CONTRACT_VERSION,
            'personel_id' => (int) $personel['id'],
            'normalized' => $normalized,
            'donem_baslangic' => $donemBaslangic,
            'donem_bitis' => $donemBitis,
            'actor_id' => (int) ($user['id'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $revisionRequired = $wouldExclude && $activeSnapshot !== null;

        return [
            'ok' => true,
            'contract_version' => self::CONTRACT_VERSION,
            'write_performed' => false,
            'dry_run_hash' => $previewHash,
            'personel' => [
                'id' => (int) $personel['id'],
                'sicil_no' => (string) $personel['sicil_no'],
                'ad_soyad' => trim((string) $personel['ad'] . ' ' . (string) $personel['soyad']),
                'sube_id' => (int) $personel['sube_id'],
            ],
            'proposed' => $normalized,
            'donem' => [
                'baslangic' => $donemBaslangic,
                'bitis' => $donemBitis,
            ],
            'effects' => [
                'currently_excluded' => $existingExcluded,
                'would_exclude_from_new_snapshot' => $wouldExclude || $existingExcluded,
                'muhur_satiri_var_mi' => $sealedCount > 0,
                'muhur_satir_sayisi' => $sealedCount,
                'existing_snapshot_unchanged' => true,
                'existing_snapshot' => $activeSnapshot ? [
                    'id' => (int) $activeSnapshot['id'],
                    'snapshot_hash' => (string) $activeSnapshot['snapshot_hash'],
                    'source_hash' => (string) $activeSnapshot['source_hash'],
                    'revision_no' => (int) $activeSnapshot['revision_no'],
                ] : null,
                'source_hash_would_change' => $wouldExclude && !$existingExcluded,
                'explicit_snapshot_revision_required' => $revisionRequired,
                'carryover_blocker_suppressed' => $wouldExclude || $existingExcluded,
                'net_maas_blocker_suppressed' => $wouldExclude || $existingExcluded,
                'candidate_item_excluded' => $wouldExclude || $existingExcluded,
            ],
            'warnings' => $revisionRequired
                ? ['Aktif snapshot var; kapsam ONAYLANDI HARIC sonrasi EXISTING_ACTIVE_SNAPSHOT_SOURCE_CHANGED / explicit cancel+revision gerekir.']
                : [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, array $payload, array $user)
    {
        $personel = self::requirePersonel($pdo, (int) ($payload['personel_id'] ?? 0));
        $normalized = self::normalizePayload($payload, $personel);
        $dryRunHash = isset($payload['dry_run_hash']) ? (string) $payload['dry_run_hash'] : '';
        if ($dryRunHash === '') {
            JsonResponse::error(422, 'DRY_RUN_HASH_REQUIRED', 'Commit oncesi dry-run hash zorunlu.');
        }
        $preview = self::dryRun($pdo, array_merge($payload, [
            'preview_state' => 'ONAYLANDI',
            'donem_baslangic' => $normalized['gecerlilik_baslangic'],
            'donem_bitis' => $normalized['gecerlilik_bitis'] ?? $normalized['gecerlilik_baslangic'],
        ]), $user);
        if (!hash_equals((string) $preview['dry_run_hash'], $dryRunHash)) {
            JsonResponse::error(409, 'DRY_RUN_STALE', 'Dry-run hash guncel degil; yeniden dry-run calistirin.');
        }

        $rol = strtoupper((string) ($user['rol'] ?? ''));
        $initialState = 'TASLAK';
        if ($rol === 'GENEL_YONETICI' && !empty($payload['direkt_onayla'])) {
            $initialState = 'ONAYLANDI';
        }

        if ($normalized['neden_kodu'] === 'DEMO_TEST_VERISI' && $rol !== 'GENEL_YONETICI') {
            JsonResponse::error(403, 'FORBIDDEN', 'DEMO_TEST_VERISI yalniz GENEL_YONETICI tarafindan secilebilir.');
        }

        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            self::assertNoOverlap($pdo, (int) $personel['id'], $normalized, null);
            $stmt = $pdo->prepare(
                "INSERT INTO personel_bordro_kapsamlari (
                    personel_id, sube_id, durum, neden_kodu, aciklama,
                    gecerlilik_baslangic, gecerlilik_bitis, state,
                    hazirlayan_id, onaylayan_id, onay_zamani,
                    parent_kapsam_id, created_by, updated_by
                 ) VALUES (
                    :personel_id, :sube_id, :durum, :neden_kodu, :aciklama,
                    :gecerlilik_baslangic, :gecerlilik_bitis, :state,
                    :hazirlayan_id, :onaylayan_id, :onay_zamani,
                    :parent_kapsam_id, :created_by, :updated_by
                 )"
            );
            $actorId = (int) ($user['id'] ?? 0);
            $onaylayan = $initialState === 'ONAYLANDI' ? $actorId : null;
            $stmt->execute([
                'personel_id' => (int) $personel['id'],
                'sube_id' => (int) $personel['sube_id'],
                'durum' => $normalized['durum'],
                'neden_kodu' => $normalized['neden_kodu'],
                'aciklama' => $normalized['aciklama'],
                'gecerlilik_baslangic' => $normalized['gecerlilik_baslangic'],
                'gecerlilik_bitis' => $normalized['gecerlilik_bitis'],
                'state' => $initialState,
                'hazirlayan_id' => $actorId > 0 ? $actorId : null,
                'onaylayan_id' => $onaylayan,
                'onay_zamani' => $initialState === 'ONAYLANDI' ? date('Y-m-d H:i:s') : null,
                'parent_kapsam_id' => $normalized['parent_kapsam_id'],
                'created_by' => $actorId > 0 ? $actorId : null,
                'updated_by' => $actorId > 0 ? $actorId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $row = self::fetchById($pdo, $id);
            self::audit($pdo, $id, (int) $personel['id'], 'CREATE', null, $row, $user, $dryRunHash);
            if ($ownsTx) {
                $pdo->commit();
            }

            return self::serialize($row);
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException) {
                throw $e;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function submit(PDO $pdo, $kapsamId, array $user)
    {
        $row = self::fetchById($pdo, (int) $kapsamId);
        if (!$row) {
            JsonResponse::error(404, 'NOT_FOUND', 'Kapsam kaydi bulunamadi.');
        }
        if ((string) $row['state'] !== 'TASLAK') {
            JsonResponse::error(409, 'INVALID_STATE', 'Yalniz TASLAK kayit onaya gonderilebilir.');
        }
        $pdo->prepare("UPDATE personel_bordro_kapsamlari SET state = 'ONAY_BEKLIYOR', updated_by = :u WHERE id = :id")
            ->execute(['u' => (int) ($user['id'] ?? 0), 'id' => (int) $kapsamId]);
        $next = self::fetchById($pdo, (int) $kapsamId);
        self::audit($pdo, (int) $kapsamId, (int) $row['personel_id'], 'SUBMIT', $row, $next, $user, null);

        return self::serialize($next);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function approve(PDO $pdo, $kapsamId, array $user)
    {
        $row = self::fetchById($pdo, (int) $kapsamId);
        if (!$row) {
            JsonResponse::error(404, 'NOT_FOUND', 'Kapsam kaydi bulunamadi.');
        }
        if (!in_array((string) $row['state'], ['TASLAK', 'ONAY_BEKLIYOR'], true)) {
            JsonResponse::error(409, 'INVALID_STATE', 'Bu kayit onaylanamaz.');
        }
        if ((string) $row['neden_kodu'] === 'DEMO_TEST_VERISI' && strtoupper((string) ($user['rol'] ?? '')) !== 'GENEL_YONETICI') {
            JsonResponse::error(403, 'FORBIDDEN', 'DEMO_TEST_VERISI onayi yalniz GENEL_YONETICI.');
        }
        $normalized = [
            'gecerlilik_baslangic' => (string) $row['gecerlilik_baslangic'],
            'gecerlilik_bitis' => $row['gecerlilik_bitis'] !== null ? (string) $row['gecerlilik_bitis'] : null,
            'durum' => (string) $row['durum'],
        ];
        self::assertNoOverlap($pdo, (int) $row['personel_id'], $normalized, (int) $kapsamId);
        $pdo->prepare(
            "UPDATE personel_bordro_kapsamlari
             SET state = 'ONAYLANDI', onaylayan_id = :o, onay_zamani = NOW(), updated_by = :u
             WHERE id = :id"
        )->execute([
            'o' => (int) ($user['id'] ?? 0),
            'u' => (int) ($user['id'] ?? 0),
            'id' => (int) $kapsamId,
        ]);
        $next = self::fetchById($pdo, (int) $kapsamId);
        self::audit($pdo, (int) $kapsamId, (int) $row['personel_id'], 'APPROVE', $row, $next, $user, null);

        return self::serialize($next);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function cancel(PDO $pdo, $kapsamId, $neden, array $user)
    {
        $row = self::fetchById($pdo, (int) $kapsamId);
        if (!$row) {
            JsonResponse::error(404, 'NOT_FOUND', 'Kapsam kaydi bulunamadi.');
        }
        if ((string) $row['state'] === 'IPTAL') {
            JsonResponse::error(409, 'ALREADY_CANCELLED', 'Kayit zaten iptal.');
        }
        $neden = trim((string) $neden);
        if (mb_strlen($neden) < 3) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'Iptal nedeni zorunlu.');
        }
        $pdo->prepare(
            "UPDATE personel_bordro_kapsamlari
             SET state = 'IPTAL', iptal_eden_id = :i, iptal_zamani = NOW(), iptal_nedeni = :n, updated_by = :u
             WHERE id = :id"
        )->execute([
            'i' => (int) ($user['id'] ?? 0),
            'n' => $neden,
            'u' => (int) ($user['id'] ?? 0),
            'id' => (int) $kapsamId,
        ]);
        $next = self::fetchById($pdo, (int) $kapsamId);
        self::audit($pdo, (int) $kapsamId, (int) $row['personel_id'], 'CANCEL', $row, $next, $user, null);

        return self::serialize($next);
    }

    /**
     * @param array<string, mixed> $personel
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array $payload, array $personel)
    {
        $durum = strtoupper(trim((string) ($payload['durum'] ?? '')));
        if (!in_array($durum, ['DAHIL', 'HARIC'], true)) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'durum DAHIL veya HARIC olmali.');
        }
        $neden = strtoupper(trim((string) ($payload['neden_kodu'] ?? '')));
        if (!in_array($neden, self::NEDEN_KODLARI, true)) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'neden_kodu gecersiz.');
        }
        $aciklama = trim((string) ($payload['aciklama'] ?? ''));
        if (mb_strlen($aciklama) < 3) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'aciklama zorunlu (min 3 karakter).');
        }
        if ($durum === 'HARIC' && $aciklama === '') {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'HARIC kaydi aciklama olmadan olusturulamaz.');
        }
        $bas = (string) ($payload['gecerlilik_baslangic'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bas)) {
            JsonResponse::error(422, 'VALIDATION_ERROR', 'gecerlilik_baslangic YYYY-MM-DD olmali.');
        }
        $bit = $payload['gecerlilik_bitis'] ?? null;
        if ($bit !== null && $bit !== '') {
            $bit = (string) $bit;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bit)) {
                JsonResponse::error(422, 'VALIDATION_ERROR', 'gecerlilik_bitis YYYY-MM-DD olmali.');
            }
            if ($bit < $bas) {
                JsonResponse::error(422, 'VALIDATION_ERROR', 'gecerlilik_bitis baslangictan once olamaz.');
            }
        } else {
            $bit = null;
        }

        return [
            'personel_id' => (int) $personel['id'],
            'sube_id' => (int) $personel['sube_id'],
            'durum' => $durum,
            'neden_kodu' => $neden,
            'aciklama' => $aciklama,
            'gecerlilik_baslangic' => $bas,
            'gecerlilik_bitis' => $bit,
            'parent_kapsam_id' => isset($payload['parent_kapsam_id']) ? (int) $payload['parent_kapsam_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private static function assertNoOverlap(PDO $pdo, $personelId, array $normalized, $excludeId)
    {
        if (!self::tableExists($pdo, 'personel_bordro_kapsamlari')) {
            return;
        }
        $sql = "SELECT id FROM personel_bordro_kapsamlari
                WHERE personel_id = :pid
                  AND state = 'ONAYLANDI'
                  AND gecerlilik_baslangic <= COALESCE(:bitis, '9999-12-31')
                  AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :bas)";
        $params = [
            'pid' => (int) $personelId,
            'bas' => $normalized['gecerlilik_baslangic'],
            'bitis' => $normalized['gecerlilik_bitis'],
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :ex';
            $params['ex'] = (int) $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::error(409, 'KAPSAM_OVERLAP', 'Ayni personel icin cakisan onayli kapsam araligi var.');
        }
    }

    /** @return array<string, mixed> */
    private static function requirePersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id, sube_id, sicil_no, ad, soyad, aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::error(404, 'PERSONEL_NOT_FOUND', 'Personel bulunamadi.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private static function fetchById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare(
            "SELECT k.*, p.sicil_no, p.ad, p.soyad
             FROM personel_bordro_kapsamlari k
             INNER JOIN personeller p ON p.id = k.personel_id
             WHERE k.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed>|null $onceki
     * @param array<string, mixed>|null $sonraki
     * @param array<string, mixed> $user
     */
    private static function audit(PDO $pdo, $kapsamId, $personelId, $aksiyon, $onceki, $sonraki, array $user, $requestHash)
    {
        if (!self::tableExists($pdo, 'personel_bordro_kapsam_auditleri')) {
            return;
        }
        $pdo->prepare(
            "INSERT INTO personel_bordro_kapsam_auditleri (
                kapsam_id, personel_id, aksiyon, onceki_snapshot, sonraki_snapshot,
                actor_id, actor_rol, request_hash
             ) VALUES (
                :kapsam_id, :personel_id, :aksiyon, :onceki, :sonraki,
                :actor_id, :actor_rol, :request_hash
             )"
        )->execute([
            'kapsam_id' => $kapsamId,
            'personel_id' => $personelId,
            'aksiyon' => $aksiyon,
            'onceki' => $onceki ? json_encode(self::serialize($onceki), JSON_UNESCAPED_UNICODE) : null,
            'sonraki' => $sonraki ? json_encode(self::serialize($sonraki), JSON_UNESCAPED_UNICODE) : null,
            'actor_id' => isset($user['id']) ? (int) $user['id'] : null,
            'actor_rol' => isset($user['rol']) ? (string) $user['rol'] : null,
            'request_hash' => $requestHash,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function serialize(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'sube_id' => (int) $row['sube_id'],
            'sicil_no' => isset($row['sicil_no']) ? (string) $row['sicil_no'] : null,
            'ad_soyad' => isset($row['ad'], $row['soyad'])
                ? trim((string) $row['ad'] . ' ' . (string) $row['soyad'])
                : null,
            'durum' => (string) $row['durum'],
            'neden_kodu' => (string) $row['neden_kodu'],
            'aciklama' => (string) $row['aciklama'],
            'gecerlilik_baslangic' => (string) $row['gecerlilik_baslangic'],
            'gecerlilik_bitis' => $row['gecerlilik_bitis'] !== null ? (string) $row['gecerlilik_bitis'] : null,
            'state' => (string) $row['state'],
            'hazirlayan_id' => $row['hazirlayan_id'] !== null ? (int) $row['hazirlayan_id'] : null,
            'onaylayan_id' => $row['onaylayan_id'] !== null ? (int) $row['onaylayan_id'] : null,
            'onay_zamani' => $row['onay_zamani'] !== null ? (string) $row['onay_zamani'] : null,
            'iptal_eden_id' => $row['iptal_eden_id'] !== null ? (int) $row['iptal_eden_id'] : null,
            'iptal_zamani' => $row['iptal_zamani'] !== null ? (string) $row['iptal_zamani'] : null,
            'iptal_nedeni' => $row['iptal_nedeni'] !== null ? (string) $row['iptal_nedeni'] : null,
            'parent_kapsam_id' => $row['parent_kapsam_id'] !== null ? (int) $row['parent_kapsam_id'] : null,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            'contract_version' => self::CONTRACT_VERSION,
        ];
    }

    private static function tableExists(PDO $pdo, $table)
    {
        static $cache = [];
        $key = (string) $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute(['t' => $key]);
            $cache[$key] = (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            // SQLite test ortami
            try {
                $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $key) . '` LIMIT 1');
                $cache[$key] = true;
            } catch (\Throwable $e2) {
                $cache[$key] = false;
            }
        }

        return $cache[$key];
    }
}
