<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use RuntimeException;

/**
 * Every final-close operation — the read-only snapshot and each bounded mutation —
 * runs in-process inside the single lock-protected CLI worker. No child process or
 * proc_open is involved, so the package also works on hosting where proc_open is
 * disabled. A mutation's existing HTTP controller still answers through
 * JsonResponse; FinalCloseOwners captures that response (first response wins,
 * mirroring exit) instead of letting it emit and terminate the worker.
 */
final class FinalCloseTransport
{
    public function call(string $operation, ?string $expected = null, ?int $identityId = null): array
    {
        $frame = FinalCloseOwners::invoke([
            'operation' => $operation,
            'expected' => $expected,
            'identity_id' => $identityId,
        ]);
        if (!is_array($frame) || !array_key_exists('data', $frame) || !array_key_exists('meta', $frame)
            || !array_key_exists('errors', $frame) || !is_array($frame['errors'])) {
            throw new RuntimeException('FINAL_CLOSE_OWNER_INVALID_RESPONSE');
        }
        if ($frame['errors'] !== []) {
            $code = $frame['errors'][0]['code'] ?? '';
            throw new RuntimeException(is_string($code) && preg_match('/^[A-Z0-9_]{1,100}$/D', $code) ? $code : 'FINAL_CLOSE_OWNER_FAILED');
        }
        if (!is_array($frame['data'])) { throw new RuntimeException('FINAL_CLOSE_OWNER_INVALID_DATA'); }
        return $frame['data'];
    }
}
