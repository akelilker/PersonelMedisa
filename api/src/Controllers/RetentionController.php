<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
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

        // as_of / gm_approved query params are intentionally ignored (never trusted).
        $category = trim((string) $request->getQuery('category', ''));
        $context = [
            'personel_id' => self::optionalInt($request->getQuery('personel_id')),
            'entity_type' => trim((string) $request->getQuery('entity_type', '')),
            'record_id' => self::optionalInt($request->getQuery('record_id')) ?: 0,
            'sube_id' => self::optionalInt($request->getQuery('sube_id')),
            'yil' => self::optionalInt($request->getQuery('yil')),
            'ay' => self::optionalInt($request->getQuery('ay')),
            'parent_category' => trim((string) $request->getQuery('parent_category', '')) ?: null,
            'haftalik_kapanis_id' => self::optionalInt($request->getQuery('haftalik_kapanis_id')),
            'hafta_baslangic' => trim((string) $request->getQuery('hafta_baslangic', '')) ?: null,
        ];
        if ($context['parent_category'] === null) {
            unset($context['parent_category']);
        }
        if ($context['hafta_baslangic'] === null) {
            unset($context['hafta_baslangic']);
        }

        try {
            $pdo = Connection::get();
            if (($context['entity_type'] === 'personel' || $context['entity_type'] === 'personeller')
                && (int) $context['record_id'] > 0
            ) {
                $fp = \Medisa\Api\Services\Retention\ArchiveManifestService::computePersonelOzlukFingerprint(
                    $pdo,
                    (int) $context['record_id']
                );
                if ($fp !== null) {
                    $context['current_sha256'] = $fp;
                }
            }
            $result = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, $category, $context, null);

            $approvalStatus = null;
            $talepId = self::optionalInt($request->getQuery('talep_id'));
            if ($talepId !== null) {
                $talep = DestructionWorkflowService::getById($pdo, $talepId);
                $approvalStatus = $talep ? (string) ($talep['status'] ?? '') : null;
            }
        } catch (Throwable $e) {
            JsonResponse::serverError('Saklama uygunlugu degerlendirilemedi.');
        }

        JsonResponse::success([
            'eligibility' => $result,
            'approval_status' => $approvalStatus,
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
        $allowed = SubeScope::allowedSubeIds($user);
        JsonResponse::success([
            'items' => DestructionWorkflowService::listRequests(
                $pdo,
                $status !== '' ? $status : null,
                count($allowed) > 0 ? $allowed : null
            ),
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
        $allowed = SubeScope::allowedSubeIds($user);
        JsonResponse::success([
            'items' => DestructionWorkflowService::listAudits(
                $pdo,
                $limit,
                count($allowed) > 0 ? $allowed : null
            ),
        ]);
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
