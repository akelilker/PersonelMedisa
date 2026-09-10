<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require dirname(__DIR__) . '/src/bootstrap.php';

// No argv payloads (process listings) and no web-accessible credential transport.
try {
    $input = stream_get_contents(STDIN, 1048577);
    if (!is_string($input) || strlen($input) > 1048576) { throw new RuntimeException('FINAL_CLOSE_FRAME_TOO_LARGE'); }
    $frame = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($frame)) { throw new RuntimeException('FINAL_CLOSE_FRAME_INVALID'); }
    $data = \Medisa\Api\Services\Operations\FinalCloseOwners::invoke($frame);
    // FinalCloseOwners::invoke now always returns the canonical response frame
    // (['data' => ..., 'meta' => [], 'errors' => [...]]); the controller's
    // JsonResponse is captured there instead of emitted/exiting, so this CLI
    // wrapper emits the frame unchanged. The worker no longer spawns this binary
    // (no proc_open), but it stays a compatible, web-inaccessible CLI entry.
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
} catch (\Throwable $error) {
    // Never emit exception messages from PDO, secrets, or personnel payloads.
    $reason = preg_match('/^FINAL_CLOSE_[A-Z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'FINAL_CLOSE_OWNER_FAILED';
    echo json_encode(['data' => null, 'meta' => [], 'errors' => [['code' => $reason]]]);
    exit(1);
}
