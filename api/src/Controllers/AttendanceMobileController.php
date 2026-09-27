<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Qr\QrAttendanceCorrectionService;
use Medisa\Api\Services\Qr\QrAttendanceException;
use Medisa\Api\Services\Qr\QrAttendanceTodayService;
use Medisa\Api\Services\SelfService\PersonelInboxNotificationService;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityException;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;
use Medisa\Api\Services\SelfService\SelfPersonelContext;

/**
 * Mobile attendance today + correction + inbox surfaces.
 */
class AttendanceMobileController
{
    public static function today(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.view');
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
        try {
            JsonResponse::success(QrAttendanceTodayService::today($pdo, $user));
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bugun durumu yuklenemedi.');
        }
    }

    public static function listCorrections(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.attendance.correct');
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
        try {
            JsonResponse::success(QrAttendanceCorrectionService::listForSelf($pdo, $user));
        } catch (QrAttendanceException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Duzeltme talepleri yuklenemedi.');
        }
    }

    public static function createCorrection(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'self_service.attendance.correct');
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        try {
            $result = QrAttendanceCorrectionService::createRequest($pdo, $user, $body);
            JsonResponse::success($result, [], 201);
        } catch (PersonelMobileCapabilityException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage());
        } catch (QrAttendanceException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Duzeltme talebi olusturulamadi.');
        }
    }

    public static function decideCorrection(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'attendance.correction.decide');
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        // Ignore client approver spoof fields.
        unset($body['approver_user_id'], $body['assigned_approver_user_id']);
        try {
            // Process due reminders opportunistically for this approver session.
            try {
                QrAttendanceCorrectionService::processDueReminders($pdo, 20);
            } catch (\Throwable $e) {
                // non-blocking
            }
            $result = QrAttendanceCorrectionService::decide($pdo, $user, $id, $body);
            JsonResponse::success($result);
        } catch (QrAttendanceException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        } catch (\Throwable $e) {
            JsonResponse::serverError('Duzeltme karari kaydedilemedi.');
        }
    }

    public static function inbox(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'self_service.view',
            'attendance.correction.decide',
            'puantaj.view',
        ]);
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
        try {
            try {
                QrAttendanceCorrectionService::processDueReminders($pdo, 20);
            } catch (\Throwable $e) {
                // non-blocking
            }
            $items = PersonelInboxNotificationService::listForUser($pdo, (int) $user['id'], 50);
            $popups = PersonelInboxNotificationService::pendingPopupsForUser($pdo, (int) $user['id']);
            JsonResponse::success([
                'items' => $items,
                'pending_popups' => $popups,
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'PERSONEL_INBOX_SCHEMA_NOT_READY') {
                JsonResponse::success(['items' => [], 'pending_popups' => []]);
            }
            JsonResponse::serverError('Bildirimler yuklenemedi.');
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bildirimler yuklenemedi.');
        }
    }

    public static function ackPopup(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, [
            'self_service.view',
            'attendance.correction.decide',
            'puantaj.view',
        ]);
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
        try {
            $ok = PersonelInboxNotificationService::ackPopup($pdo, (int) $user['id'], $id);
            JsonResponse::success(['acked' => $ok]);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Bildirim isaretlenemedi.');
        }
    }
}
