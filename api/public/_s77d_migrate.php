<?php
/**
 * S77-D migrate ops completed; endpoint retired.
 * Returns 410 so stale cPanel copies without FTP --delete are inert.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'GONE',
    'message' => 'S77-D migrate endpoint retired.',
], JSON_UNESCAPED_UNICODE);
