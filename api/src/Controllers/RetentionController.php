<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use RuntimeException;
use Throwable;

class RetentionController
{
    public static function eligibility(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'retention.view');

        $category = trim((string) $request->getQuery('category', ''));
        $context = [
            'personel_id' => self::optionalInt($request->getQuery('personel_id')),
            'entity_type' => trim((string) $request->getQuery('entity_type', '')),
            'record_id' => self::optionalInt($request->getQuery('record_id')) ?: 0,
            'sube_id' => self::optionalInt($request->getQuery('sube_id')),
            'yil' => self::optionalInt($request->getQuery('yil')),
            'ay' => self::optionalInt($request->getQuery('ay')),
            'as_of' => trim((string) $request->getQuery('as_of', '')) ?: null,
            'gm_approved' => strtolower((string) $request->getQuery('gm_approved', '0')) === '1',
            'has_gm_approval' => strtolower((string) $request->getQuery('gm_approved', '0')) === '1',
        ];
        if ($context['as_of'] === null) {
            unset($context['as_of']);
        }

        try {
            $pdo = Connection::get();
            $result = RetentionPolicyService::evaluateDestructionEligibility($pdo, $category, $context);
        } catch (Throwable $e) {
            JsonResponse::serverError('Saklama uygunlugu degerlendirilemedi.');
        }

        JsonResponse::success([
            'eligibility' => $result,
            'policy_note' => RetentionCategories::POLICY_NOTE,
        ]);
    }

    public static function requestDestruction(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $pdo = Connection::get();
            $result = DestructionWorkflowService::requestDestruction($pdo, $user, $body);
        } catch (RuntimeException $e) {
            JsonResponse::badRequest($e->getMessage(), $e->getMessage());
        } catch (Throwable $e) {
            JsonResponse::serverError('Imha talebi olusturulamadi.');
        }

        JsonResponse::success($result);
    }

    public static function approveDestruction(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        $reason = trim((string) ($body['approval_reason'] ?? $body['reason'] ?? ''));
        $approve = !isset($body['approve']) || (bool) $body['approve'];

        try {
            $pdo = Connection::get();
            $item = DestructionWorkflowService::approveDestruction($pdo, $user, $id, $reason, $approve);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'DESTRUCTION_REQUEST_NOT_FOUND') {
                JsonResponse::notFound('Imha talebi bulunamadi.');
            }
            JsonResponse::badRequest($code, $code);
        } catch (Throwable $e) {
            JsonResponse::serverError('Imha onayi islenemedi.');
        }

        JsonResponse::success(['item' => $item]);
    }

    public static function listRequests(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'retention.destruction.view',
            'retention.destruction.request',
            'retention.destruction.approve',
            'retention.view',
        ]);

        try {
            $pdo = Connection::get();
        } catch (Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $status = trim((string) $request->getQuery('status', ''));
        JsonResponse::success([
            'items' => DestructionWorkflowService::listRequests($pdo, $status !== '' ? $status : null),
            'policy_note' => RetentionCategories::POLICY_NOTE,
        ]);
    }

    public static function listAudits(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'retention.destruction.view',
            'arsiv.audit.view',
            'retention.destruction.approve',
        ]);

        try {
            $pdo = Connection::get();
        } catch (Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $limit = (int) ($request->getQuery('limit', 200) ?: 200);
        JsonResponse::success(['items' => DestructionWorkflowService::listAudits($pdo, $limit)]);
    }

    /** @param mixed $value */
    private static function optionalInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
}
