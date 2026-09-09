<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use RuntimeException;

/**
 * Snapshot/preflight reads run in-process inside the single lock-protected worker.
 * Each mutation operation keeps one short-lived PHP process per existing HTTP
 * controller (JsonResponse exits), and fails closed when proc_open is unavailable.
 */
final class FinalCloseTransport
{
    private $apiDirectory;

    public function __construct(string $apiDirectory) { $this->apiDirectory = $apiDirectory; }

    public function call(string $operation, ?string $expected = null, ?int $identityId = null): array
    {
        // Read-only canonical snapshot: same FinalCloseOwners invocation the child
        // used, executed in-process. Safe because snapshot reads never emit a
        // JsonResponse/exit and never mutate; this removes the proc_open runtime
        // dependency for the entire preflight and every postcheck snapshot refresh.
        if ($operation === 'snapshot') {
            return $this->callSnapshotInProcess();
        }
        if (!function_exists('proc_open')) { throw new RuntimeException('FINAL_CLOSE_PROC_OPEN_UNAVAILABLE'); }
        $process = proc_open([PHP_BINARY, $this->apiDirectory . '/bin/final-close-owner.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('FINAL_CLOSE_CHILD_START_FAILED'); }
        fwrite($pipes[0], json_encode(['operation' => $operation, 'expected' => $expected, 'identity_id' => $identityId], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = ''; $deadline = microtime(true) + 60;
        do {
            $output .= stream_get_contents($pipes[1]);
            // Drain stderr without retaining or logging database/configuration details.
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (strlen($output) > 1048576 || microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                throw new RuntimeException('FINAL_CLOSE_OWNER_TIMEOUT_OR_OUTPUT_LIMIT');
            }
            if ($status['running']) { usleep(10000); }
        } while ($status['running']);
        $output .= stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $result = json_decode($output, true);
        if (!is_array($result) || !array_key_exists('data', $result) || !isset($result['errors'])) {
            throw new RuntimeException('FINAL_CLOSE_OWNER_INVALID_RESPONSE');
        }
        if ($result['errors'] !== []) {
            $code = $result['errors'][0]['code'] ?? '';
            throw new RuntimeException(is_string($code) && preg_match('/^[A-Z0-9_]{1,100}$/D', $code) ? $code : 'FINAL_CLOSE_OWNER_FAILED');
        }
        if (!is_array($result['data'])) { throw new RuntimeException('FINAL_CLOSE_OWNER_INVALID_DATA'); }
        return $result['data'];
    }

    /**
     * In-process read-only owner invocation. Frame shape and bounded codes mirror
     * the child path exactly; the operation never mutates so no exit boundary or
     * JsonResponse capture is required.
     */
    private function callSnapshotInProcess(): array
    {
        $data = FinalCloseOwners::invoke(['operation' => 'snapshot', 'expected' => null, 'identity_id' => null]);
        if (!is_array($data)) {
            throw new RuntimeException('FINAL_CLOSE_OWNER_INVALID_DATA');
        }
        return $data;
    }
}
