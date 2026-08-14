<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;
use PDOException;
use Throwable;

/**
 * S97-B: CREATE_ONLY_ALL_OR_NOTHING personel import apply owner.
 * Salary / bordro / SGK / carryover writes are forbidden.
 *
 * Idempotency claim lives inside the same transaction as personel inserts.
 * Crash/rollback before commit leaves no durable CLAIMED row (ORPHAN_CLAIM=NO).
 */
final class PersonelImportApplyService
{
    public const CONFIRMATION_TOKEN = 'PERSONEL_IMPORT_ONAYLIYORUM';
    public const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';
    public const IDEMPOTENCY_MAX_LEN = 128;

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function apply(PDO $pdo, $csvContent, array $user, array $input, $activeSubeHeader = null)
    {
        if (!self::schemaReady($pdo)) {
            throw new PersonelImportException(
                'SCHEMA_NOT_READY',
                'Personel import apply semasi henuz hazir degil. Migration 046 uygulanmalidir.',
                409
            );
        }

        $confirmation = trim((string) ($input['confirmation'] ?? $input['onay'] ?? ''));
        if ($confirmation !== self::CONFIRMATION_TOKEN) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_CONFIRMATION_REQUIRED',
                'Import onayi zorunludur.',
                400
            );
        }

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if (
            $idempotencyKey === ''
            || strlen($idempotencyKey) > self::IDEMPOTENCY_MAX_LEN
            || preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1
        ) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_IDEMPOTENCY_KEY_INVALID',
                'idempotency_key 8-128 karakter olmali ve sadece guvenli karakterler icermelidir.',
                400
            );
        }

        $expectedManifest = strtolower(trim((string) ($input['manifest_hash'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedManifest)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_MANIFEST_REQUIRED',
                'manifest_hash zorunludur.',
                400
            );
        }

        $clientSource = strtolower(trim((string) ($input['source_sha256'] ?? '')));
        if ($clientSource !== '' && !preg_match('/^[0-9a-f]{64}$/', $clientSource)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_SOURCE_REQUIRED',
                'source_sha256 gecersiz.',
                400
            );
        }

        if (!is_string($csvContent)) {
            throw new PersonelImportException('PERSONEL_IMPORT_DOSYA_GECERSIZ', 'CSV icerigi okunamadi.', 400);
        }
        $normalizedCsv = $csvContent;
        if (strncmp($normalizedCsv, "\xEF\xBB\xBF", 3) === 0) {
            $normalizedCsv = substr($normalizedCsv, 3);
        }
        $sourceSha = hash('sha256', $normalizedCsv);
        if ($clientSource !== '' && !hash_equals($clientSource, $sourceSha)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_MANIFEST_CHANGED',
                'Dry-run source hash guncel degil. Yeniden dry-run calistirin.',
                409
            );
        }

        // Durable COMPLETED / orphan CLAIMED short-circuit before re-analysis.
        // After success, live TCs would change the analyze-time manifest.
        $existingRun = self::findRunByKey($pdo, $idempotencyKey);
        if ($existingRun !== null) {
            $samePayload = hash_equals((string) $existingRun['source_sha256'], $sourceSha)
                && hash_equals((string) $existingRun['manifest_hash'], $expectedManifest);
            if ((string) $existingRun['status'] === 'COMPLETED') {
                if (!$samePayload) {
                    throw new PersonelImportException(
                        'PERSONEL_IMPORT_IDEMPOTENCY_CONFLICT',
                        'Ayni idempotency_key farkli source/manifest ile kullanilmis.',
                        409
                    );
                }

                return self::buildSuccessResponse($pdo, (int) $existingRun['id'], true);
            }
            if ((string) $existingRun['status'] === 'CLAIMED') {
                // Durable CLAIMED should not exist under in-TX claim model.
                // Fail closed for concurrent/orphan claim; does not create personel.
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_TRANSACTION_FAILED',
                    'Ayni idempotency_key ile eszamanli import devam ediyor.',
                    409
                );
            }
        }

        $analysis = PersonelImportDryRunService::analyze($pdo, $csvContent, $user, $activeSubeHeader);
        $manifestHash = (string) $analysis['manifest_hash'];
        if (!hash_equals($sourceSha, (string) $analysis['source_sha256'])) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Source hash tutarsiz.',
                500
            );
        }

        if (!hash_equals($expectedManifest, $manifestHash)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_MANIFEST_CHANGED',
                'Dry-run manifesti guncel degil. Yeniden dry-run calistirin.',
                409
            );
        }

        $actorId = (int) ($user['id'] ?? 0);
        $actorRol = (string) ($user['rol'] ?? '');
        $activeSubeId = $analysis['active_sube_id'];

        $allCodes = [];
        foreach ($analysis['satirlar'] as $row) {
            foreach ($row['hata_kodlari'] ?? [] as $code) {
                $allCodes[] = (string) $code;
            }
        }
        if (in_array('PERSONEL_IMPORT_SUBE_SCOPE_IHLALI', $allCodes, true)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_SCOPE_FORBIDDEN',
                'Bir veya daha fazla satir icin sube yetkisi yok.',
                403
            );
        }
        if (
            in_array('PERSONEL_IMPORT_TC_MEVCUT', $allCodes, true)
            || in_array('PERSONEL_IMPORT_SICIL_MEVCUT', $allCodes, true)
        ) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_ALREADY_EXISTS',
                'Dry-run sonrasi duplicate personel olustu.',
                409
            );
        }
        if (!(bool) $analysis['can_apply'] || count($analysis['candidates']) === 0) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_NOT_APPLICABLE',
                'Import yalniz tum satirlar gecerliyken uygulanabilir.',
                422
            );
        }

        $toplamSatir = (int) $analysis['ozet']['toplam_satir'];
        $gecerliSatir = (int) $analysis['ozet']['gecerli_satir'];
        $importId = 0;

        try {
            $pdo->beginTransaction();

            // Claim + inserts + COMPLETED share one transaction.
            $claim = self::claimIdempotencyInsideTx(
                $pdo,
                $idempotencyKey,
                $sourceSha,
                $manifestHash,
                $actorId,
                $actorRol,
                $activeSubeId,
                $toplamSatir,
                $gecerliSatir
            );

            if (($claim['kind'] ?? '') === 'replay') {
                $pdo->commit();

                return self::buildSuccessResponse($pdo, (int) $claim['import_id'], true);
            }

            $importId = (int) $claim['import_id'];

            $locked = self::lockRun($pdo, $importId);
            if (!$locked || (string) ($locked['status'] ?? '') !== 'CLAIMED') {
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_TRANSACTION_FAILED',
                    'Import islemi kilitlenemedi.',
                    409
                );
            }

            // Re-validate inside transaction against current DB state.
            $reanalysis = PersonelImportDryRunService::analyze($pdo, $csvContent, $user, $activeSubeHeader);
            if (!hash_equals($manifestHash, (string) $reanalysis['manifest_hash'])) {
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_REFERENCE_CHANGED',
                    'Referans veya canonical veri degisti. Yeniden dry-run calistirin.',
                    409
                );
            }
            if (!(bool) $reanalysis['can_apply']) {
                $codes = [];
                foreach ($reanalysis['satirlar'] as $row) {
                    foreach ($row['hata_kodlari'] ?? [] as $code) {
                        $codes[] = (string) $code;
                    }
                }
                if (
                    in_array('PERSONEL_IMPORT_TC_MEVCUT', $codes, true)
                    || in_array('PERSONEL_IMPORT_SICIL_MEVCUT', $codes, true)
                ) {
                    throw new PersonelImportException(
                        'PERSONEL_IMPORT_ALREADY_EXISTS',
                        'Dry-run sonrasi duplicate personel olustu.',
                        409
                    );
                }
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_REFERENCE_CHANGED',
                    'Dry-run sonrasi dogrulama basarisiz.',
                    409
                );
            }

            $created = [];
            foreach ($reanalysis['candidates'] as $candidate) {
                $payload = $candidate['payload'];
                if (($payload['maas_tutari'] ?? null) !== null
                    || ($payload['ucret_tipi_id'] ?? null) !== null
                ) {
                    throw new PersonelImportException(
                        'PERSONEL_IMPORT_UCRET_KARARI_BEKLENIYOR',
                        'Import ucret alani olusturamaz.',
                        400
                    );
                }

                PersonelCreateService::validateCreateReferences($pdo, $payload);
                $tcValue = $payload['tc_kimlik_no'] ?? null;
                if ($tcValue !== null && $tcValue !== '' && PersonelCreateService::tcExists($pdo, (string) $tcValue)) {
                    throw new PersonelImportException(
                        'PERSONEL_IMPORT_ALREADY_EXISTS',
                        'Ayni T.C. Kimlik No ile personel zaten mevcut.',
                        409
                    );
                }
                PersonelCalisanKapsamSchema::assertReadyForDisKaynakWrite($pdo, $payload);

                $personelId = PersonelCreateService::insertPersonel($pdo, $payload);
                $created[] = [
                    'satir_no' => (int) $candidate['satir_no'],
                    'personel_id' => $personelId,
                    'sicil_no' => (string) $candidate['sicil_no'],
                    'ad' => (string) $candidate['ad'],
                    'soyad' => (string) $candidate['soyad'],
                    'tc_kimlik_no_masked' => (string) $candidate['tc_kimlik_no_masked'],
                    'row_hash' => (string) $candidate['row_hash'],
                ];
            }

            self::insertRunRows($pdo, $importId, $created);
            self::markCompleted($pdo, $importId, $created);
            $pdo->commit();

            return self::buildSuccessResponseFromCreated($importId, $sourceSha, $manifestHash, $created, false);
        } catch (PersonelImportException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Failure audit is outside the personel TX (claim already rolled back).
            self::recordFailureAuditOutsideTx(
                $pdo,
                $idempotencyKey,
                $sourceSha,
                $manifestHash,
                $actorId,
                $actorRol,
                $activeSubeId,
                $toplamSatir,
                $gecerliSatir,
                $e->getCodeString()
            );
            throw $e;
        } catch (PersonelValidationException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCodeString() === PersonelOrgLocationSchema::ERROR_CODE
                || $e->getCodeString() === PersonelCalisanKapsamSchema::ERROR_CODE
                || $e->getCodeString() === PersonelCalisanKapsamService::ERROR_SGK_YASAK
            ) {
                self::recordFailureAuditOutsideTx(
                    $pdo,
                    $idempotencyKey,
                    $sourceSha,
                    $manifestHash,
                    $actorId,
                    $actorRol,
                    $activeSubeId,
                    $toplamSatir,
                    $gecerliSatir,
                    $e->getCodeString()
                );
                throw new PersonelImportException(
                    $e->getCodeString(),
                    $e->getMessage(),
                    409
                );
            }
            self::recordFailureAuditOutsideTx(
                $pdo,
                $idempotencyKey,
                $sourceSha,
                $manifestHash,
                $actorId,
                $actorRol,
                $activeSubeId,
                $toplamSatir,
                $gecerliSatir,
                'PERSONEL_IMPORT_REFERENCE_CHANGED'
            );
            throw new PersonelImportException(
                'PERSONEL_IMPORT_REFERENCE_CHANGED',
                'Referans dogrulama basarisiz.',
                409
            );
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = (
                PersonelCreateService::isDuplicateTcException($e)
                || PersonelCreateService::isDuplicateSicilException($e)
            )
                ? 'PERSONEL_IMPORT_ALREADY_EXISTS'
                : 'PERSONEL_IMPORT_TRANSACTION_FAILED';
            self::recordFailureAuditOutsideTx(
                $pdo,
                $idempotencyKey,
                $sourceSha,
                $manifestHash,
                $actorId,
                $actorRol,
                $activeSubeId,
                $toplamSatir,
                $gecerliSatir,
                $code
            );
            if ($code === 'PERSONEL_IMPORT_ALREADY_EXISTS') {
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_ALREADY_EXISTS',
                    'TC veya sicil ile personel zaten mevcut.',
                    409
                );
            }
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Import transaction basarisiz.',
                500
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::recordFailureAuditOutsideTx(
                $pdo,
                $idempotencyKey,
                $sourceSha,
                $manifestHash,
                $actorId,
                $actorRol,
                $activeSubeId,
                $toplamSatir,
                $gecerliSatir,
                'PERSONEL_IMPORT_TRANSACTION_FAILED'
            );
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Import transaction basarisiz.',
                500
            );
        }
    }

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('personel_import_runs','personel_import_run_satirlari')"
            );
            if ($stmt === false) {
                return false;
            }

            return (int) $stmt->fetchColumn() === 2;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Claim inside an already-open transaction.
     *
     * @return array{kind: string, import_id: int}
     */
    private static function claimIdempotencyInsideTx(
        PDO $pdo,
        string $idempotencyKey,
        string $sourceSha,
        string $manifestHash,
        int $actorId,
        string $actorRol,
        $activeSubeId,
        int $toplamSatir,
        int $gecerliSatir
    ) {
        $now = self::nowMs();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO personel_import_runs (
                    idempotency_key, source_sha256, manifest_hash, schema_version,
                    actor_id, actor_rol, active_sube_id, status,
                    toplam_satir, gecerli_satir, created_count, started_at
                ) VALUES (
                    :idempotency_key, :source_sha256, :manifest_hash, :schema_version,
                    :actor_id, :actor_rol, :active_sube_id, \'CLAIMED\',
                    :toplam_satir, :gecerli_satir, 0, :started_at
                )'
            );
            $stmt->execute([
                'idempotency_key' => $idempotencyKey,
                'source_sha256' => $sourceSha,
                'manifest_hash' => $manifestHash,
                'schema_version' => PersonelImportDryRunService::SCHEMA_VERSION,
                'actor_id' => $actorId,
                'actor_rol' => $actorRol,
                'active_sube_id' => $activeSubeId,
                'toplam_satir' => $toplamSatir,
                'gecerli_satir' => $gecerliSatir,
                'started_at' => $now,
            ]);

            return ['kind' => 'claimed', 'import_id' => (int) $pdo->lastInsertId()];
        } catch (PDOException $e) {
            if (!self::isDuplicateIdempotencyException($e)) {
                throw $e;
            }
        }

        $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT * FROM personel_import_runs WHERE idempotency_key = :key LIMIT 1' . $forUpdate
        );
        $stmt->execute(['key' => $idempotencyKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Idempotency kaydi okunamadi.',
                500
            );
        }

        $samePayload = hash_equals((string) $existing['source_sha256'], $sourceSha)
            && hash_equals((string) $existing['manifest_hash'], $manifestHash);

        if ((string) $existing['status'] === 'COMPLETED') {
            if (!$samePayload) {
                throw new PersonelImportException(
                    'PERSONEL_IMPORT_IDEMPOTENCY_CONFLICT',
                    'Ayni idempotency_key farkli source/manifest ile kullanilmis.',
                    409
                );
            }

            return ['kind' => 'replay', 'import_id' => (int) $existing['id']];
        }

        if ((string) $existing['status'] === 'CLAIMED') {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Ayni idempotency_key ile eszamanli import devam ediyor.',
                409
            );
        }

        // BASARISIZ: same payload may retry by reclaiming inside this TX.
        if (!$samePayload) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_IDEMPOTENCY_CONFLICT',
                'Ayni idempotency_key farkli source/manifest ile kullanilmis.',
                409
            );
        }

        $reclaim = $pdo->prepare(
            "UPDATE personel_import_runs
             SET status = 'CLAIMED',
                 error_code = NULL,
                 created_count = 0,
                 created_personel_ids_json = NULL,
                 finished_at = NULL,
                 started_at = :started_at,
                 source_sha256 = :source_sha256,
                 manifest_hash = :manifest_hash,
                 toplam_satir = :toplam_satir,
                 gecerli_satir = :gecerli_satir
             WHERE id = :id AND status = 'BASARISIZ'"
        );
        $reclaim->execute([
            'started_at' => $now,
            'source_sha256' => $sourceSha,
            'manifest_hash' => $manifestHash,
            'toplam_satir' => $toplamSatir,
            'gecerli_satir' => $gecerliSatir,
            'id' => (int) $existing['id'],
        ]);
        if ($reclaim->rowCount() !== 1) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Idempotency reclaim basarisiz.',
                409
            );
        }

        $pdo->prepare('DELETE FROM personel_import_run_satirlari WHERE import_run_id = :id')
            ->execute(['id' => (int) $existing['id']]);

        return ['kind' => 'claimed', 'import_id' => (int) $existing['id']];
    }

    /** @return array<string, mixed>|null */
    private static function findRunByKey(PDO $pdo, string $idempotencyKey)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM personel_import_runs WHERE idempotency_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function lockRun(PDO $pdo, int $importId)
    {
        $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare('SELECT * FROM personel_import_runs WHERE id = :id LIMIT 1' . $forUpdate);
        $stmt->execute(['id' => $importId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param list<array<string, mixed>> $created */
    private static function insertRunRows(PDO $pdo, int $importId, array $created): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO personel_import_run_satirlari (
                import_run_id, satir_no, personel_id, sicil_no, tc_kimlik_no_masked, row_hash, ad, soyad
            ) VALUES (
                :import_run_id, :satir_no, :personel_id, :sicil_no, :tc_kimlik_no_masked, :row_hash, :ad, :soyad
            )'
        );
        foreach ($created as $row) {
            $stmt->execute([
                'import_run_id' => $importId,
                'satir_no' => (int) $row['satir_no'],
                'personel_id' => (int) $row['personel_id'],
                'sicil_no' => (string) $row['sicil_no'],
                'tc_kimlik_no_masked' => (string) $row['tc_kimlik_no_masked'],
                'row_hash' => (string) $row['row_hash'],
                'ad' => (string) $row['ad'],
                'soyad' => (string) $row['soyad'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $created */
    private static function markCompleted(PDO $pdo, int $importId, array $created): void
    {
        $ids = array_map(static function ($row) {
            return (int) $row['personel_id'];
        }, $created);
        $json = json_encode($ids, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare(
            "UPDATE personel_import_runs
             SET status = 'COMPLETED',
                 created_count = :created_count,
                 created_personel_ids_json = :ids_json,
                 error_code = NULL,
                 finished_at = :finished_at
             WHERE id = :id AND status = 'CLAIMED'"
        );
        $stmt->execute([
            'created_count' => count($created),
            'ids_json' => $json,
            'finished_at' => self::nowMs(),
            'id' => $importId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Import tamamlanamadi.',
                500
            );
        }
    }

    /**
     * Best-effort failure audit AFTER personel TX rollback.
     * Does not re-attempt personel inserts. No raw CSV/TC.
     * Same-payload retry can reclaim BASARISIZ inside a later TX.
     */
    private static function recordFailureAuditOutsideTx(
        PDO $pdo,
        string $idempotencyKey,
        string $sourceSha,
        string $manifestHash,
        int $actorId,
        string $actorRol,
        $activeSubeId,
        int $toplamSatir,
        int $gecerliSatir,
        string $errorCode
    ): void {
        try {
            $existing = self::findRunByKey($pdo, $idempotencyKey);
            $code = substr($errorCode, 0, 80);
            $now = self::nowMs();

            if (is_array($existing)) {
                $status = (string) ($existing['status'] ?? '');
                if ($status === 'COMPLETED') {
                    return;
                }
                if ($status === 'CLAIMED' || $status === 'BASARISIZ') {
                    $stmt = $pdo->prepare(
                        "UPDATE personel_import_runs
                         SET status = 'BASARISIZ',
                             error_code = :error_code,
                             finished_at = :finished_at,
                             created_count = 0,
                             created_personel_ids_json = NULL
                         WHERE id = :id AND status IN ('CLAIMED','BASARISIZ')"
                    );
                    $stmt->execute([
                        'error_code' => $code,
                        'finished_at' => $now,
                        'id' => (int) $existing['id'],
                    ]);

                    return;
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO personel_import_runs (
                    idempotency_key, source_sha256, manifest_hash, schema_version,
                    actor_id, actor_rol, active_sube_id, status,
                    toplam_satir, gecerli_satir, created_count, error_code, started_at, finished_at
                ) VALUES (
                    :idempotency_key, :source_sha256, :manifest_hash, :schema_version,
                    :actor_id, :actor_rol, :active_sube_id, \'BASARISIZ\',
                    :toplam_satir, :gecerli_satir, 0, :error_code, :started_at, :finished_at
                )'
            );
            $stmt->execute([
                'idempotency_key' => $idempotencyKey,
                'source_sha256' => $sourceSha,
                'manifest_hash' => $manifestHash,
                'schema_version' => PersonelImportDryRunService::SCHEMA_VERSION,
                'actor_id' => $actorId > 0 ? $actorId : 0,
                'actor_rol' => $actorRol !== '' ? $actorRol : 'UNKNOWN',
                'active_sube_id' => $activeSubeId,
                'toplam_satir' => $toplamSatir,
                'gecerli_satir' => $gecerliSatir,
                'error_code' => $code,
                'started_at' => $now,
                'finished_at' => $now,
            ]);
        } catch (Throwable $e) {
            // Best-effort; personel rollback outcome must not change.
        }
    }

    /** @return array<string, mixed> */
    private static function buildSuccessResponse(PDO $pdo, int $importId, bool $idempotentReplay)
    {
        $runStmt = $pdo->prepare('SELECT * FROM personel_import_runs WHERE id = :id LIMIT 1');
        $runStmt->execute(['id' => $importId]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run) || (string) ($run['status'] ?? '') !== 'COMPLETED') {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_TRANSACTION_FAILED',
                'Onceki import sonucu okunamadi.',
                500
            );
        }

        $rowsStmt = $pdo->prepare(
            'SELECT satir_no, personel_id, sicil_no, ad, soyad, tc_kimlik_no_masked
             FROM personel_import_run_satirlari
             WHERE import_run_id = :id
             ORDER BY satir_no ASC'
        );
        $rowsStmt->execute(['id' => $importId]);
        $created = [];
        foreach ($rowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $created[] = [
                'satir_no' => (int) $row['satir_no'],
                'personel_id' => (int) $row['personel_id'],
                'sicil_no' => (string) $row['sicil_no'],
                'ad' => (string) $row['ad'],
                'soyad' => (string) $row['soyad'],
                'tc_kimlik_no_masked' => (string) $row['tc_kimlik_no_masked'],
            ];
        }

        return [
            'import_id' => $importId,
            'status' => 'COMPLETED',
            'idempotent_replay' => $idempotentReplay,
            'source_sha256' => (string) $run['source_sha256'],
            'manifest_hash' => (string) $run['manifest_hash'],
            'created_count' => (int) $run['created_count'],
            'created' => $created,
            'yazma' => [
                'personel_write' => true,
                'salary_write' => false,
                'bordro_scope_write' => false,
                'carryover_write' => false,
                'sgk_status_write' => false,
                'wage_model_assumption' => false,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $created
     * @return array<string, mixed>
     */
    private static function buildSuccessResponseFromCreated(
        int $importId,
        string $sourceSha,
        string $manifestHash,
        array $created,
        bool $idempotentReplay
    ) {
        $publicCreated = array_map(static function (array $row) {
            return [
                'satir_no' => (int) $row['satir_no'],
                'personel_id' => (int) $row['personel_id'],
                'sicil_no' => (string) $row['sicil_no'],
                'ad' => (string) $row['ad'],
                'soyad' => (string) $row['soyad'],
                'tc_kimlik_no_masked' => (string) $row['tc_kimlik_no_masked'],
            ];
        }, $created);

        return [
            'import_id' => $importId,
            'status' => 'COMPLETED',
            'idempotent_replay' => $idempotentReplay,
            'source_sha256' => $sourceSha,
            'manifest_hash' => $manifestHash,
            'created_count' => count($publicCreated),
            'created' => $publicCreated,
            'yazma' => [
                'personel_write' => true,
                'salary_write' => false,
                'bordro_scope_write' => false,
                'carryover_write' => false,
                'sgk_status_write' => false,
                'wage_model_assumption' => false,
            ],
        ];
    }

    private static function isDuplicateIdempotencyException(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        return strpos($message, 'uq_pir_idempotency_key') !== false
            || (strpos($message, 'duplicate') !== false && strpos($message, 'idempotency') !== false);
    }

    private static function nowMs(): string
    {
        $micro = microtime(true);
        $seconds = (int) floor($micro);
        $millis = (int) round(($micro - $seconds) * 1000);
        if ($millis >= 1000) {
            $seconds++;
            $millis = 0;
        }

        return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%03d', $millis);
    }
}
