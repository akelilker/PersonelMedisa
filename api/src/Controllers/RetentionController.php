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
use Medisa\Api\Services\Retention\RetentionTargetResolver;
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
        $entityType = trim((string) $request->getQuery('entity_type', ''));
        $recordId = self::optionalInt($request->getQuery('record_id')) ?: 0;
        $personelId = self::optionalInt($request->getQuery('personel_id'));

        $periodHints = [];
        foreach (['sube_id', 'yil', 'ay', 'haftalik_kapanis_id', 'hafta_baslangic', 'parent_category'] as $key) {
            $raw = $request->getQuery($key, null);
            if ($raw === null || $raw === '') {
                continue;
            }
            if (in_array($key, ['sube_id', 'yil', 'ay', 'haftalik_kapanis_id'], true)) {
                $n = self::optionalInt($raw);
                if ($n !== null) {
                    $periodHints[$key] = $n;
                }
            } else {
                $periodHints[$key] = trim((string) $raw);
            }
        }

        $approvalStatus = null;

        try {
            $pdo = Connection::get();
            $context = RetentionTargetResolver::validateAndResolve(
                $pdo,
                $category,
                $entityType,
                $recordId,
                $personelId,
                $periodHints
            );

            // Branch scope: personel-bound and period targets must be in user scope.
            if (!empty($context['personel_id']) || !empty($context['sube_id'])) {
                $subeId = isset($context['sube_id']) ? (int) $context['sube_id'] : 0;
                if ($subeId > 0) {
                    SubeScope::assertPersonelAccess($user, $request, $subeId);
                }
            }

            $result = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, $category, $context, null);

            $talepId = self::optionalInt($request->getQuery('talep_id'));
            if ($talepId !== null) {
                $approvalStatus = self::resolveTalepStatusScoped(
                    $pdo,
                    $user,
                    $request,
                    $talepId,
                    $category,
                    $context
                );
            }
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'RETENTION_TARGET_INVALID'
                || $code === RetentionPolicyService::CODE_UNKNOWN_CATEGORY
                || $code === 'RETENTION_TARGET_PERSONEL_NOT_FOUND'
                || $code === 'RETENTION_TARGET_ENTITY_NOT_FOUND'
                || $code === 'RETENTION_TARGET_PERSONEL_MISMATCH'
                || $code === 'TARGET_MISMATCH'
            ) {
                JsonResponse::badRequest($code, $code);
            }
            if ($code === 'DESTRUCTION_REQUEST_NOT_FOUND' || $code === 'NOT_FOUND') {
                JsonResponse::notFound('Imha talebi bulunamadi.');
            }
            if ($code === 'FORBIDDEN') {
                JsonResponse::forbidden();
            }
            JsonResponse::serverError('Saklama uygunlugu degerlendirilemedi.');
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

    public static function evaluateExecution(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);

        try {
            $pdo = Connection::get();
            $result = DestructionWorkflowService::evaluateExecution($pdo, $user, $id);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'DESTRUCTION_REQUEST_NOT_FOUND') {
                JsonResponse::notFound('Imha talebi bulunamadi.');
            }
            JsonResponse::badRequest($code, $code);
        } catch (Throwable $e) {
            JsonResponse::serverError('Imha execute degerlendirmesi yapilamadi.');
        }

        JsonResponse::success($result);
    }

    public static function executeDestruction(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $pdo = Connection::get();
            $result = DestructionWorkflowService::executePhysicalDestruction($pdo, $user, $id, $body);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'DESTRUCTION_REQUEST_NOT_FOUND') {
                JsonResponse::notFound('Imha talebi bulunamadi.');
            }
            if ($code === 'DESTRUCTION_EXECUTION_DISABLED') {
                JsonResponse::error(423, $code, $code);
            }
            JsonResponse::badRequest($code, $code);
        } catch (Throwable $e) {
            JsonResponse::serverError('Fiziksel imha yurutulemedi.');
        }

        JsonResponse::success($result);
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

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     * @return string|null
     */
    private static function resolveTalepStatusScoped(
        $pdo,
        array $user,
        Request $request,
        $talepId,
        $category,
        array $context
    ) {
        $talep = DestructionWorkflowService::getById($pdo, $talepId);
        if (!$talep) {
            throw new RuntimeException('NOT_FOUND');
        }

        if ((string) ($talep['category'] ?? '') !== (string) $category
            || (string) ($talep['entity_type'] ?? '') !== (string) ($context['entity_type'] ?? '')
            || (int) ($talep['record_id'] ?? 0) !== (int) ($context['record_id'] ?? 0)
        ) {
            throw new RuntimeException('TARGET_MISMATCH');
        }

        $subeId = 0;
        if (!empty($talep['canonical_sube_id'])) {
            $subeId = (int) $talep['canonical_sube_id'];
        } elseif (!empty($talep['personel_id'])) {
            $stmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $talep['personel_id']]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $subeId = (int) $row['sube_id'];
            }
        } elseif (!empty($context['sube_id'])) {
            $subeId = (int) $context['sube_id'];
        }

        if ($subeId > 0) {
            SubeScope::assertPersonelAccess($user, $request, $subeId);
        } else {
            // Global / unresolved scope: only unrestricted (global) roles.
            $allowed = SubeScope::allowedSubeIds($user);
            if (count($allowed) > 0) {
                throw new RuntimeException('FORBIDDEN');
            }
        }

        return (string) ($talep['status'] ?? '');
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
