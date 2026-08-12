<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Services\PuantajDonemKilidiService;
use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\PuantajDonemReopenException;
use PDO;
use PDOException;

/**
 * Orchestrates APPLY_EXISTING / KEEP_CANONICAL / REOPEN_REVIEW with locks + ledger.
 */
class QrPuantajCandidateDecisionService
{
    private const NONCE_RE = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';
    private const REASON_MIN = 5;
    private const REASON_MAX = 1000;

    /**
     * @param array<string,mixed> $body Allow-listed body fields only (caller filtered)
     * @return array<string,mixed>
     */
    public static function decide(
        PDO $pdo,
        $personelId,
        $subeId,
        $candidateDate,
        $userId,
        array $body
    ) {
        QrAttendanceEventService::assertSchemaReady($pdo);
        QrPuantajCandidateDecisionLedgerService::assertSchemaReady($pdo);

        $personelId = (int) $personelId;
        $subeId = (int) $subeId;
        $userId = (int) $userId;
        $candidateDate = self::normalizeDate($candidateDate);

        $action = strtoupper(trim((string) ($body['action'] ?? '')));
        $submittedHash = strtolower(trim((string) ($body['candidate_hash'] ?? '')));
        $nonce = trim((string) ($body['request_nonce'] ?? ''));
        $reason = isset($body['gerekce']) ? trim((string) $body['gerekce']) : '';

        if (!QrPuantajCandidateDecisionPolicy::isKnownAction($action)) {
            throw new QrPuantajCandidateDecisionException(
                'VALIDATION_ERROR',
                'Gecersiz action.',
                400,
                ['field' => 'action']
            );
        }
        if ($submittedHash === '' || !preg_match('/^[0-9a-f]{64}$/', $submittedHash)) {
            throw new QrPuantajCandidateDecisionException(
                'VALIDATION_ERROR',
                'candidate_hash gecersiz.',
                400,
                ['field' => 'candidate_hash']
            );
        }
        if ($nonce === '' || !preg_match(self::NONCE_RE, $nonce)) {
            throw new QrPuantajCandidateDecisionException(
                'VALIDATION_ERROR',
                'request_nonce gecersiz.',
                400,
                ['field' => 'request_nonce']
            );
        }
        if (strlen($reason) < self::REASON_MIN || strlen($reason) > self::REASON_MAX) {
            throw new QrPuantajCandidateDecisionException(
                'VALIDATION_ERROR',
                'gerekce 5-1000 karakter olmalidir.',
                400,
                ['field' => 'gerekce']
            );
        }

        $existingNonce = QrPuantajCandidateDecisionLedgerService::findByUserNonce($pdo, $userId, $nonce);
        if ($existingNonce !== null) {
            return self::resolveIdempotentRetry($existingNonce, $action, $submittedHash, $reason, $personelId, $candidateDate);
        }

        $pdo->beginTransaction();
        try {
            // Period lock first (APPLY requires write assert; KEEP/REOPEN still serialize on period+row)
            $periodLock = PuantajDonemKilidiService::acquireForDate($pdo, $subeId, $candidateDate);

            $canonical = QrPuantajCandidateApplyService::fetchForUpdate($pdo, $personelId, $candidateDate);

            // Test-only overlap hold for concurrent race runners (never set in production).
            $raceHoldMs = (int) (getenv('S3F_RACE_HOLD_MS') ?: '0');
            if ($raceHoldMs > 0) {
                usleep($raceHoldMs * 1000);
            }

            // Post-lock nonce recheck: concurrent exact retry must resolve idempotently
            // after the winner commits, before recompute/stale evaluation.
            $lockedNonce = QrPuantajCandidateDecisionLedgerService::findByUserNonce($pdo, $userId, $nonce);
            if ($lockedNonce !== null) {
                $pdo->rollBack();

                return self::resolveIdempotentRetry(
                    $lockedNonce,
                    $action,
                    $submittedHash,
                    $reason,
                    $personelId,
                    $candidateDate
                );
            }

            // Recompute candidate inside transaction after locks
            $item = self::recomputeSingleCandidate($pdo, $personelId, $subeId, $candidateDate);
            if ($item === null) {
                $pdo->rollBack();
                throw new QrPuantajCandidateDecisionException(
                    'QR_CANDIDATE_NOT_FOUND',
                    'Bu tarih icin QR puantaj adayi bulunamadi.',
                    404
                );
            }

            $currentHash = QrPuantajCandidateHashService::compute($personelId, $subeId, $item);
            $item['candidate_hash'] = $currentHash;

            if (!hash_equals($submittedHash, $currentHash)) {
                $pdo->rollBack();
                throw new QrPuantajCandidateDecisionException(
                    QrPuantajCandidateDecisionPolicy::BLOCK_STALE,
                    'QR aday durumu degismis. Listeyi yenileyip tekrar deneyin.',
                    409,
                    ['refresh_required' => true]
                );
            }

            $latest = QrPuantajCandidateDecisionLedgerService::findLatestForCandidateHash(
                $pdo,
                $personelId,
                $candidateDate,
                $currentHash
            );
            $review = QrPuantajCandidateDecisionPolicy::buildReviewOverlay($item, $latest);

            if ($action === QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING) {
                $result = self::executeApply(
                    $pdo,
                    $periodLock,
                    $personelId,
                    $subeId,
                    $candidateDate,
                    $userId,
                    $nonce,
                    $reason,
                    $item,
                    $currentHash,
                    $canonical,
                    $latest,
                    $review
                );
            } elseif ($action === QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL) {
                $result = self::executeKeep(
                    $pdo,
                    $personelId,
                    $subeId,
                    $candidateDate,
                    $userId,
                    $nonce,
                    $reason,
                    $item,
                    $currentHash,
                    $canonical,
                    $latest,
                    $review
                );
            } else {
                $result = self::executeReopen(
                    $pdo,
                    $personelId,
                    $subeId,
                    $candidateDate,
                    $userId,
                    $nonce,
                    $reason,
                    $item,
                    $currentHash,
                    $canonical,
                    $latest,
                    $review
                );
            }

            $pdo->commit();

            return $result;
        } catch (QrPuantajCandidateDecisionException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (PuantajDonemReopenException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new QrPuantajCandidateDecisionException(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getCode() ?: 409,
                $e->getMeta()
            );
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($driverCode === 1062) {
                $again = QrPuantajCandidateDecisionLedgerService::findByUserNonce($pdo, $userId, $nonce);
                if ($again !== null) {
                    return self::resolveIdempotentRetry(
                        $again,
                        $action,
                        $submittedHash,
                        $reason,
                        $personelId,
                        $candidateDate
                    );
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $periodLock
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $canonical
     * @param array<string,mixed>|null $latest
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    private static function executeApply(
        PDO $pdo,
        array $periodLock,
        $personelId,
        $subeId,
        $candidateDate,
        $userId,
        $nonce,
        $reason,
        array $item,
        $currentHash,
        $canonical,
        $latest,
        array $review
    ) {
        if (empty($review['can_apply'])) {
            if (($item['comparison_status'] ?? '') === QrPuantajCandidateProjectionService::COMPARE_NO_CANONICAL_ROW
                || $canonical === null
            ) {
                throw new QrPuantajCandidateDecisionException(
                    QrPuantajCandidateDecisionPolicy::BLOCK_NO_ROW,
                    'QR apply mevcut gunluk_puantaj satiri gerektirir; otomatik olusturma yok.',
                    409
                );
            }
            if (($review['blocking_code'] ?? '') === QrPuantajCandidateDecisionPolicy::BLOCK_KEEP_ACTIVE) {
                throw new QrPuantajCandidateDecisionException(
                    QrPuantajCandidateDecisionPolicy::BLOCK_DECISION_CONFLICT,
                    'KEEP_CANONICAL aktif. Once incelemeyi yeniden acin.',
                    409
                );
            }
            throw new QrPuantajCandidateDecisionException(
                (string) ($review['blocking_code'] ?? 'QR_APPLY_NOT_ALLOWED'),
                'QR saatleri bu aday icin uygulanamaz.',
                409
            );
        }

        PuantajDonemPeriodService::assertCanonicalWriteAllowed(
            $pdo,
            (int) $periodLock['sube_id'],
            (int) $periodLock['yil'],
            (int) $periodLock['ay']
        );

        if ($canonical === null) {
            throw new QrPuantajCandidateDecisionException(
                QrPuantajCandidateDecisionPolicy::BLOCK_NO_ROW,
                'QR apply mevcut gunluk_puantaj satiri gerektirir; otomatik olusturma yok.',
                409
            );
        }

        $guard = QrPuantajCandidateDecisionPolicy::evaluateDependentFieldGuard($canonical);
        if (!$guard['ok']) {
            throw new QrPuantajCandidateDecisionException(
                QrPuantajCandidateDecisionPolicy::BLOCK_DEPENDENT_FIELDS,
                'Bagimli turetilmis alanlar manuel inceleme gerektirir; QR apply engellendi.',
                409,
                ['populated_fields' => $guard['populated']]
            );
        }

        $proposed = is_array($item['proposed'] ?? null) ? $item['proposed'] : [];
        $beforeSnap = QrPuantajCandidateApplyService::buildPuantajSnapshot($canonical);
        $afterRow = QrPuantajCandidateApplyService::applyEntryExit(
            $pdo,
            $canonical,
            $proposed['giris_saati'] ?? null,
            $proposed['cikis_saati'] ?? null
        );
        $afterSnap = QrPuantajCandidateApplyService::buildPuantajSnapshot($afterRow);

        $ledger = self::appendDecision(
            $pdo,
            $personelId,
            $subeId,
            $candidateDate,
            $currentHash,
            QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            $reason,
            (int) $afterRow['id'],
            $item,
            $beforeSnap,
            $afterSnap,
            $userId,
            $nonce,
            $latest
        );

        return self::successPayload($ledger, false, $beforeSnap, $afterSnap);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $canonical
     * @param array<string,mixed>|null $latest
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    private static function executeKeep(
        PDO $pdo,
        $personelId,
        $subeId,
        $candidateDate,
        $userId,
        $nonce,
        $reason,
        array $item,
        $currentHash,
        $canonical,
        $latest,
        array $review
    ) {
        if (empty($review['can_keep_canonical'])) {
            throw new QrPuantajCandidateDecisionException(
                (string) ($review['blocking_code'] ?? 'QR_KEEP_NOT_ALLOWED'),
                'Mevcut puantaj bu aday icin korunamaz.',
                409
            );
        }

        $beforeSnap = $canonical !== null
            ? QrPuantajCandidateApplyService::buildPuantajSnapshot($canonical)
            : null;

        $ledger = self::appendDecision(
            $pdo,
            $personelId,
            $subeId,
            $candidateDate,
            $currentHash,
            QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
            $reason,
            $canonical !== null ? (int) $canonical['id'] : null,
            $item,
            $beforeSnap,
            null,
            $userId,
            $nonce,
            $latest
        );

        return self::successPayload($ledger, false, $beforeSnap, null);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $canonical
     * @param array<string,mixed>|null $latest
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    private static function executeReopen(
        PDO $pdo,
        $personelId,
        $subeId,
        $candidateDate,
        $userId,
        $nonce,
        $reason,
        array $item,
        $currentHash,
        $canonical,
        $latest,
        array $review
    ) {
        if (empty($review['can_reopen_review'])) {
            throw new QrPuantajCandidateDecisionException(
                QrPuantajCandidateDecisionPolicy::BLOCK_DECISION_CONFLICT,
                'Inceleme yeniden acilamaz. Son karar KEEP_CANONICAL olmalidir.',
                409
            );
        }

        $beforeSnap = $canonical !== null
            ? QrPuantajCandidateApplyService::buildPuantajSnapshot($canonical)
            : null;

        $ledger = self::appendDecision(
            $pdo,
            $personelId,
            $subeId,
            $candidateDate,
            $currentHash,
            QrPuantajCandidateDecisionPolicy::ACTION_REOPEN_REVIEW,
            $reason,
            $canonical !== null ? (int) $canonical['id'] : null,
            $item,
            $beforeSnap,
            null,
            $userId,
            $nonce,
            $latest
        );

        return self::successPayload($ledger, false, $beforeSnap, null);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $beforeSnap
     * @param array<string,mixed>|null $afterSnap
     * @param array<string,mixed>|null $latest
     * @return array<string,mixed>
     */
    private static function appendDecision(
        PDO $pdo,
        $personelId,
        $subeId,
        $candidateDate,
        $currentHash,
        $decisionType,
        $reason,
        $puantajId,
        array $item,
        $beforeSnap,
        $afterSnap,
        $userId,
        $nonce,
        $latest
    ) {
        $previousHash = null;
        $supersedesId = null;
        if (is_array($latest)) {
            $previousHash = (string) ($latest['decision_hash'] ?? '');
            $supersedesId = (int) ($latest['id'] ?? 0);
            if ($supersedesId < 1) {
                $supersedesId = null;
            }
            if ($previousHash === '') {
                $previousHash = null;
            }
        }

        $snapshot = self::buildCandidateSnapshot($item, $currentHash);

        return QrPuantajCandidateDecisionLedgerService::append($pdo, [
            'personel_id' => (int) $personelId,
            'sube_id' => (int) $subeId,
            'candidate_date' => (string) $candidateDate,
            'candidate_hash' => (string) $currentHash,
            'decision_type' => (string) $decisionType,
            'decision_reason' => (string) $reason,
            'puantaj_id' => $puantajId,
            'algorithm_version' => QrPuantajCandidateProjectionService::ALGORITHM_VERSION,
            'interval_algorithm_version' => QrPuantajCandidateProjectionService::INTERVAL_ALGORITHM_VERSION,
            'decision_algorithm_version' => QrPuantajCandidateDecisionPolicy::DECISION_ALGORITHM_VERSION,
            'candidate_snapshot' => $snapshot,
            'before_puantaj_snapshot' => $beforeSnap,
            'after_puantaj_snapshot' => $afterSnap,
            'decided_by_user_id' => (int) $userId,
            'request_nonce' => (string) $nonce,
            'supersedes_decision_id' => $supersedesId,
            'previous_decision_hash' => $previousHash,
        ]);
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function buildCandidateSnapshot(array $item, $candidateHash)
    {
        return [
            'candidate_hash' => (string) $candidateHash,
            'candidate_date' => (string) ($item['candidate_date'] ?? ''),
            'classification' => (string) ($item['classification'] ?? ''),
            'comparison_status' => (string) ($item['comparison_status'] ?? ''),
            'proposed' => $item['proposed'] ?? null,
            'canonical' => $item['canonical'] ?? null,
            'period' => $item['period'] ?? null,
            'provenance' => [
                'algorithm_version' => QrPuantajCandidateProjectionService::ALGORITHM_VERSION,
                'interval_algorithm_version' => QrPuantajCandidateProjectionService::INTERVAL_ALGORITHM_VERSION,
                'source_event_ids' => is_array($item['provenance']['source_event_ids'] ?? null)
                    ? $item['provenance']['source_event_ids']
                    : [],
                'source_max_event_id' => $item['provenance']['source_max_event_id'] ?? null,
                'source_interval_count' => $item['provenance']['source_interval_count'] ?? 0,
                'source_anomaly_count' => $item['provenance']['source_anomaly_count'] ?? 0,
                'qr_matched_seconds' => $item['provenance']['qr_matched_seconds'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $ledger
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @return array<string,mixed>
     */
    private static function successPayload(array $ledger, $idempotent, $before, $after)
    {
        return [
            'decision_id' => (int) ($ledger['id'] ?? 0),
            'decision_type' => (string) ($ledger['decision_type'] ?? ''),
            'candidate_hash' => (string) ($ledger['candidate_hash'] ?? ''),
            'decision_hash' => (string) ($ledger['decision_hash'] ?? ''),
            'puantaj_id' => isset($ledger['puantaj_id']) && $ledger['puantaj_id'] !== null
                ? (int) $ledger['puantaj_id']
                : null,
            'idempotent' => (bool) $idempotent,
            'before' => $before,
            'after' => $after,
            'created_at' => (string) ($ledger['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private static function resolveIdempotentRetry(
        array $existing,
        $action,
        $submittedHash,
        $reason,
        $personelId,
        $candidateDate
    ) {
        $same = strtoupper((string) ($existing['decision_type'] ?? '')) === strtoupper((string) $action)
            && hash_equals(strtolower((string) ($existing['candidate_hash'] ?? '')), strtolower((string) $submittedHash))
            && (string) ($existing['decision_reason'] ?? '') === (string) $reason
            && (int) ($existing['personel_id'] ?? 0) === (int) $personelId
            && (string) ($existing['candidate_date'] ?? '') === (string) $candidateDate;

        if (!$same) {
            throw new QrPuantajCandidateDecisionException(
                QrPuantajCandidateDecisionPolicy::BLOCK_IDEMPOTENCY,
                'Ayni request_nonce farkli karar istegi ile kullanilamaz.',
                409,
                ['field' => 'request_nonce']
            );
        }

        if (!QrPuantajCandidateDecisionLedgerService::verifyDecisionHash($existing)) {
            throw new QrPuantajCandidateDecisionException(
                'QR_DECISION_INTEGRITY_MISMATCH',
                'Karar kaydi butunlugu dogrulanamadi.',
                500
            );
        }

        $before = QrPuantajCandidateDecisionLedgerService::mapPublic($existing)['before_puantaj_snapshot'];
        $after = QrPuantajCandidateDecisionLedgerService::mapPublic($existing)['after_puantaj_snapshot'];

        return self::successPayload($existing, true, $before, $after);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function recomputeSingleCandidate(PDO $pdo, $personelId, $subeId, $candidateDate)
    {
        $payload = QrPuantajCandidateReadService::listForPersonel(
            $pdo,
            $personelId,
            $subeId,
            $candidateDate,
            $candidateDate
        );
        foreach ($payload['items'] as $item) {
            if ((string) ($item['candidate_date'] ?? '') === (string) $candidateDate) {
                return $item;
            }
        }

        return null;
    }

    private static function normalizeDate($value)
    {
        $raw = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new QrPuantajCandidateDecisionException(
                'VALIDATION_ERROR',
                'Gecersiz tarih.',
                400,
                ['field' => 'candidate_date']
            );
        }
        $parts = explode('-', $raw);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw new QrPuantajCandidateDecisionException(
                'VALIDATION_ERROR',
                'Gecersiz takvim tarihi.',
                400,
                ['field' => 'candidate_date']
            );
        }

        return $raw;
    }
}
