<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use Medisa\Api\Database\MigrationBackupService;
use RuntimeException;

/** Bounded orchestration, never a replacement for a domain mutation owner. */
final class FinalCloseService
{
    public static function run(array $request, string $apiDirectory, string $publishedSha): array
    {
        FinalClosePackage::validateRequest($request, $publishedSha);
        $transport = new FinalCloseTransport($apiDirectory);
        $snapshot = $transport->call('snapshot');
        FinalCloseSnapshot::assertApprovedPreimage($snapshot);
        $checksum = FinalCloseSnapshot::checksum($snapshot);
        $directory = MigrationBackupService::operationsDirectory($apiDirectory);
        $preflightPath = $directory . '/final-close-preflight-' . $publishedSha . '-' . $checksum . '.json';
        $report = [
            'request_id' => $request['request_id'], 'mode' => $request['mode'],
            'package_id' => FinalClosePackage::ID, 'deployed_sha' => $publishedSha,
            'preflight_checksum' => $checksum, 'result' => 'PASS',
            'operations' => FinalCloseOwners::operations(), 'readiness_blockers' => [],
            'production_mutation_count' => 0,
        ];
        foreach ([10, 11, 110] as $id) {
            if (!empty($snapshot['users'][$id]['must_change_password'])) {
                $report['readiness_blockers'][] = 'USER_' . $id . '_PASSWORD_CHANGE_REQUIRED';
            }
        }
        if ($request['mode'] === 'FINAL_CLOSE_PREFLIGHT') {
            self::writeEvidence($preflightPath, ['created_at' => time(), 'deployed_sha' => $publishedSha,
                'checksum' => $checksum, 'snapshot' => $snapshot, 'report' => $report]);
            return $report;
        }
        if (!hash_equals($checksum, $request['preflight_checksum'])) {
            throw new RuntimeException('FINAL_CLOSE_PREFLIGHT_DRIFT');
        }
        $evidence = json_decode((string) @file_get_contents($preflightPath), true);
        if (!is_array($evidence) || ($evidence['deployed_sha'] ?? null) !== $publishedSha
            || ($evidence['created_at'] ?? 0) < time() - 1800
            || ($evidence['created_at'] ?? PHP_INT_MAX) > time() + 60
            || FinalCloseSnapshot::checksum($evidence['snapshot'] ?? []) !== $checksum) {
            throw new RuntimeException('FINAL_CLOSE_FRESH_PREFLIGHT_REQUIRED');
        }
        // Exclusive marker survives failures. Recovery never silently replays a partially
        // applied package, steals another run, or rolls back successful independent writes.
        $marker = $directory . '/final-close-apply-' . FinalClosePackage::ID . '.json';
        $handle = @fopen($marker, 'x');
        if ($handle === false) { throw new RuntimeException('FINAL_CLOSE_ALREADY_ATTEMPTED_REVIEW_RECEIPTS'); }
        $backup = ['request' => $request, 'preimage' => $snapshot,
            'rollback' => 'Manual canonical-owner recovery only; never restore unrelated successful groups.'];
        $bytes = json_encode($backup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $written = fwrite($handle, $bytes); fflush($handle); fclose($handle); chmod($marker, 0600);
        if ($written !== strlen($bytes) || !hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $marker))) {
            throw new RuntimeException('FINAL_CLOSE_BACKUP_READBACK_FAILED');
        }
        $report['backup_checksum'] = hash('sha256', $bytes);
        $report['backup_outside_webroot'] = true;
        $report['groups'] = [];
        $identityId = null;
        foreach (FinalCloseOwners::operations() as $operation) {
            $before = $snapshot;
            try {
                $result = $transport->call($operation, FinalCloseSnapshot::checksum($before), $identityId);
                $snapshot = $transport->call('snapshot');
                FinalClosePostcheck::verify($operation, $before, $snapshot, $result, $identityId);
                if ($operation === 'identity_create') { $identityId = (int) $result['actor_identity_id']; }
                $report['groups'][$operation] = ['result' => 'PASS', 'post_checksum' => FinalCloseSnapshot::checksum($snapshot)];
                ++$report['production_mutation_count'];
            } catch (\Throwable $error) {
                $code = preg_match('/^[A-Z0-9_]{1,100}$/D', $error->getMessage()) ? $error->getMessage() : 'FINAL_CLOSE_OPERATION_FAILED';
                $report['groups'][$operation] = ['result' => 'BLOCKED', 'reason' => $code];
                $report['result'] = 'PARTIAL_OR_BLOCKED';
                $snapshot = $transport->call('snapshot');
                if (FinalCloseSnapshot::checksum($snapshot) !== FinalCloseSnapshot::checksum($before)) {
                    $report['result'] = 'REQUIRES_RECONCILIATION';
                    self::writeEvidence($marker . '.receipt.json', $report);
                    return $report;
                }
            }
            // Persist each independent outcome before starting the next owner call.
            self::writeEvidence($marker . '.receipt.json', $report);
        }
        return $report;
    }

    private static function writeEvidence(string $path, array $evidence): void
    {
        $encoded = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
            throw new RuntimeException('FINAL_CLOSE_EVIDENCE_WRITE_FAILED');
        }
        chmod($temporary, 0600);
        if (!hash_equals(hash('sha256', $encoded), (string) hash_file('sha256', $temporary)) || !rename($temporary, $path)) {
            throw new RuntimeException('FINAL_CLOSE_EVIDENCE_READBACK_FAILED');
        }
    }
}
