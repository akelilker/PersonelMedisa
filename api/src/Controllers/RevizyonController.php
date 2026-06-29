<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;

class RevizyonController
{
    public static function talepleri(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success(['items' => []]);
    }

    public static function corrections(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        JsonResponse::success(['items' => []]);
    }
}
