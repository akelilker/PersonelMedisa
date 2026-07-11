<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class HaftalikBildirimMutabakatlariController
{
    public static function summary(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'haftalik_mutabakat.view');
        [$haftaBaslangic, $haftaBitis] = self::resolveWeek($request->getQuery('hafta_baslangic'));
        $subeId = self::requireScope($user, $request);
        $currentUserId = self::userId($user);
        $amirId = strtoupper(trim((string) ($user['rol'] ?? ''))) === 'BIRIM_AMIRI'
            ? $currentUserId
            : null;

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        $counts = self::fetchCounts($pdo, $subeId, $amirId, $haftaBaslangic, $haftaBitis);
        $existing = self::fetchExisting($pdo, $subeId, $amirId, $haftaBaslangic);
        [$canApprove, $blockReason] = self::approvalState($counts, $existing);

        JsonResponse::success([
            'hafta_baslangic' => $haftaBaslangic,
            'hafta_bitis' => $haftaBitis,
            'sube_id' => $subeId,
            'birim_amiri_user_id' => $amirId !== null
                ? $amirId
                : ($existing ? (int) $existing['birim_amiri_user_id'] : null),
            'counts' => $counts,
            'onaylanabilir_mi' => $canApprove,
            'blok_nedeni' => $blockReason,
            'mevcut_mutabakat_id' => $existing ? (int) $existing['id'] : null,
        ]);
    }

    public static function approve(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'haftalik_mutabakat.approve');
        if (strtoupper(trim((string) ($user['rol'] ?? ''))) !== 'BIRIM_AMIRI') {
            JsonResponse::forbidden('Yalnizca birim amiri kendi haftasini onaylayabilir.');
        }

        $body = $request->getJsonBody();
        [$haftaBaslangic, $haftaBitis] = self::resolveWeek($body['hafta_baslangic'] ?? null);
        $subeId = self::requireScope($user, $request);
        $amirId = self::userId($user);
        $pdo = self::connection();
        self::assertTablesReady($pdo);

        try {
            $pdo->beginTransaction();
            $existing = self::fetchExisting($pdo, $subeId, $amirId, $haftaBaslangic, true);
            if ($existing) {
                self::rollbackConflict($pdo, 'Bu hafta icin mutabakat zaten mevcut.');
            }

            $counts = self::fetchCounts($pdo, $subeId, $amirId, $haftaBaslangic, $haftaBitis, true);
            [$canApprove, $blockReason] = self::approvalState($counts, null);
            if (!$canApprove) {
                self::rollbackConflict($pdo, (string) $blockReason);
            }

            $insert = $pdo->prepare('
                INSERT INTO haftalik_bildirim_mutabakatlari (
                    sube_id, birim_amiri_user_id, hafta_baslangic, hafta_bitis,
                    state, onaylayan_user_id, onaylandi_at
                ) VALUES (
                    :sube_id, :birim_amiri_user_id, :hafta_baslangic, :hafta_bitis,
                    :state, :onaylayan_user_id, NOW()
                )
            ');
            $insert->execute([
                'sube_id' => $subeId,
                'birim_amiri_user_id' => $amirId,
                'hafta_baslangic' => $haftaBaslangic,
                'hafta_bitis' => $haftaBitis,
                'state' => 'TAMAMLANDI',
                'onaylayan_user_id' => $amirId,
            ]);
            $mutabakatId = (int) $pdo->lastInsertId();

            $update = $pdo->prepare('
                UPDATE gunluk_bildirimler
                SET haftalik_mutabakat_id = :mutabakat_id,
                    state = :next_state,
                    updated_by = :updated_by
                WHERE sube_id = :sube_id
                  AND created_by = :created_by
                  AND tarih BETWEEN :hafta_baslangic AND :hafta_bitis
                  AND state = :current_state
                  AND haftalik_mutabakat_id IS NULL
            ');
            $update->execute([
                'mutabakat_id' => $mutabakatId,
                'next_state' => 'HAFTALIK_MUTABAKATA_ALINDI',
                'updated_by' => $amirId,
                'sube_id' => $subeId,
                'created_by' => $amirId,
                'hafta_baslangic' => $haftaBaslangic,
                'hafta_bitis' => $haftaBitis,
                'current_state' => 'GONDERILDI',
            ]);
            $linkedCount = $update->rowCount();
            if ($linkedCount < 1) {
                self::rollbackConflict($pdo, 'Mutabakata alinacak gonderilmis bildirim bulunamadi.');
            }

            $detail = self::buildDetail($pdo, $mutabakatId);
            $pdo->commit();
            $detail['baglanan_kayit_sayisi'] = $linkedCount;
            JsonResponse::success($detail, [], 201);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                self::conflict('Bu hafta icin mutabakat zaten mevcut.');
            }
            JsonResponse::serverError('Haftalik mutabakat olusturulamadi.');
        }
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'haftalik_mutabakat.view');
        $mutabakatId = self::positiveInt($id);
        if ($mutabakatId === null) {
            JsonResponse::notFound('Haftalik mutabakat bulunamadi.');
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        $detail = self::buildDetail($pdo, $mutabakatId);
        if (!$detail) {
            JsonResponse::notFound('Haftalik mutabakat bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $detail['mutabakat']['sube_id']);
        if (strtoupper(trim((string) ($user['rol'] ?? ''))) === 'BIRIM_AMIRI'
            && (int) $detail['mutabakat']['birim_amiri_user_id'] !== self::userId($user)) {
            JsonResponse::forbidden();
        }
        JsonResponse::success($detail);
    }

    private static function connection()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    private static function assertTablesReady(PDO $pdo)
    {
        foreach (['gunluk_bildirimler', 'haftalik_bildirim_mutabakatlari'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
            if (!$stmt || !$stmt->fetch()) {
                JsonResponse::serverError('Haftalik mutabakat migration uygulanmadi.');
            }
        }
    }

    private static function requireScope(array $user, Request $request)
    {
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Haftalik mutabakat icin aktif sube secilmelidir.');
        }
        return (int) $scope;
    }

    private static function resolveWeek($value)
    {
        $date = trim((string) $value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            self::validationError('hafta_baslangic', 'Hafta baslangici YYYY-MM-DD formatinda olmalidir.');
        }
        if ($parsed->format('N') !== '1') {
            self::validationError('hafta_baslangic', 'Hafta baslangici Pazartesi olmalidir.');
        }
        return [$date, $parsed->modify('+6 days')->format('Y-m-d')];
    }

    private static function fetchCounts(PDO $pdo, $subeId, $amirId, $start, $end, $forUpdate = false)
    {
        $ownerWhere = $amirId !== null ? ' AND created_by = :created_by' : '';
        $sql = $forUpdate ? '
            SELECT state
            FROM gunluk_bildirimler
            WHERE sube_id = :sube_id' . $ownerWhere . '
              AND tarih BETWEEN :hafta_baslangic AND :hafta_bitis
            FOR UPDATE' : '
            SELECT state, COUNT(*) AS adet
            FROM gunluk_bildirimler
            WHERE sube_id = :sube_id' . $ownerWhere . '
              AND tarih BETWEEN :hafta_baslangic AND :hafta_bitis
            GROUP BY state';
        $stmt = $pdo->prepare($sql);
        $params = [
            'sube_id' => $subeId,
            'hafta_baslangic' => $start,
            'hafta_bitis' => $end,
        ];
        if ($amirId !== null) {
            $params['created_by'] = $amirId;
        }
        $stmt->execute($params);
        $counts = [
            'toplam' => 0, 'taslak' => 0, 'gonderildi' => 0,
            'duzeltme_istendi' => 0, 'haftalik_mutabakata_alindi' => 0, 'iptal' => 0,
        ];
        $map = [
            'TASLAK' => 'taslak', 'GONDERILDI' => 'gonderildi',
            'DUZELTME_ISTENDI' => 'duzeltme_istendi',
            'HAFTALIK_MUTABAKATA_ALINDI' => 'haftalik_mutabakata_alindi', 'IPTAL' => 'iptal',
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $count = $forUpdate ? 1 : (int) $row['adet'];
            $counts['toplam'] += $count;
            if (isset($map[$row['state']])) {
                $counts[$map[$row['state']]] += $count;
            }
        }
        return $counts;
    }

    private static function approvalState(array $counts, $existing)
    {
        if ($existing) return [false, 'Bu hafta icin mutabakat zaten mevcut.'];
        if ($counts['taslak'] > 0) return [false, 'Haftada taslak bildirim bulunuyor.'];
        if ($counts['duzeltme_istendi'] > 0) return [false, 'Haftada duzeltme bekleyen bildirim bulunuyor.'];
        if ($counts['haftalik_mutabakata_alindi'] > 0) return [false, 'Haftadaki bildirimler daha once mutabakata alinmis.'];
        if ($counts['gonderildi'] < 1) return [false, 'Mutabakata alinacak gonderilmis bildirim bulunamadi.'];
        return [true, null];
    }

    private static function fetchExisting(PDO $pdo, $subeId, $amirId, $start, $forUpdate = false)
    {
        $ownerWhere = $amirId !== null ? ' AND birim_amiri_user_id = :amir_id' : '';
        $stmt = $pdo->prepare('
            SELECT * FROM haftalik_bildirim_mutabakatlari
            WHERE sube_id = :sube_id' . $ownerWhere . '
              AND hafta_baslangic = :hafta_baslangic ORDER BY id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $params = ['sube_id' => $subeId, 'hafta_baslangic' => $start];
        if ($amirId !== null) {
            $params['amir_id'] = $amirId;
        }
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function buildDetail(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM haftalik_bildirim_mutabakatlari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $itemsStmt = $pdo->prepare('SELECT * FROM gunluk_bildirimler WHERE haftalik_mutabakat_id = :id ORDER BY tarih, id');
        $itemsStmt->execute(['id' => $id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::mapBildirim($item);
        }
        return [
            'mutabakat' => self::mapMutabakat($row),
            'gunluk_bildirimler' => $mapped,
            'counts' => ['toplam' => count($mapped), 'baglanan' => count($mapped)],
        ];
    }

    private static function mapMutabakat(array $row)
    {
        return [
            'id' => (int) $row['id'], 'sube_id' => (int) $row['sube_id'],
            'birim_amiri_user_id' => (int) $row['birim_amiri_user_id'],
            'hafta_baslangic' => (string) $row['hafta_baslangic'], 'hafta_bitis' => (string) $row['hafta_bitis'],
            'state' => (string) $row['state'], 'onaylayan_user_id' => (int) $row['onaylayan_user_id'],
            'onaylandi_at' => $row['onaylandi_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    private static function mapBildirim(array $row)
    {
        return [
            'id' => (int) $row['id'], 'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'], 'sube_id' => (int) $row['sube_id'],
            'departman_id' => $row['departman_id'] !== null ? (int) $row['departman_id'] : null,
            'bildirim_turu' => (string) $row['bildirim_turu'], 'aciklama' => $row['aciklama'],
            'state' => (string) $row['state'], 'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'haftalik_mutabakat_id' => $row['haftalik_mutabakat_id'] !== null ? (int) $row['haftalik_mutabakat_id'] : null,
        ];
    }

    private static function userId(array $user)
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) JsonResponse::unauthorized();
        return $id;
    }

    private static function positiveInt($value)
    {
        $id = (int) $value;
        return $id > 0 && (string) $id === trim((string) $value) ? $id : null;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    private static function conflict($message)
    {
        JsonResponse::error(409, 'CONFLICT', $message);
    }

    private static function rollbackConflict(PDO $pdo, $message)
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        self::conflict($message);
    }
}
