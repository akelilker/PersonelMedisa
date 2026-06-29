<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;

class BildirimlerController
{
    public static function list(Request $request)
    {
        AuthMiddleware::authenticate($request, true);

        $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
        $limit = max(1, min(250, (int) ($request->getQuery('limit', 8) ?: 8)));

        JsonResponse::success(
            ['items' => []],
            [
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'total_pages' => 1,
            ]
        );
    }
}
