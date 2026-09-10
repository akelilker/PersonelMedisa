<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

use RuntimeException;

/**
 * Internal control-flow signal used only inside JsonResponse::beginCapture().
 *
 * It mirrors the exit() a normal web response performs without terminating the
 * long-lived CLI worker, so a trusted in-process owner can capture the response
 * and keep running. The payload itself lives on JsonResponse (first response
 * wins); this type only unwinds execution and is never surfaced to a client.
 */
final class ResponseCaptured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('RESPONSE_CAPTURED');
    }
}
