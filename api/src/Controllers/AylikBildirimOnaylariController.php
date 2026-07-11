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

class AylikBildirimOnaylariController
{
    public static function summary(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik_bildirim_onayi.view');

        $ay = trim((string) $request->getQuery('ay', ''));
        [$ayBaslangic, $ayBitis] = self::resolveMonth($ay);
        $subeId = self::requireScope($user, $request);
        $amirId = self::resolveAmirId($user, $request);

        $pdo = self::connection();
        self::assertTablesReady($pdo);

        $payload = self::buildSummaryPayload($pdo, $subeId, $amirId, $ay, $ayBaslangic, $ayBitis);
        JsonResponse::success($payload);
    }

    public static function approve(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik_bildirim_onayi.approve');
        if (strtoupper(trim((string) ($user['rol'] ?? ''))) !== 'BIRIM_AMIRI') {
            JsonResponse::forbidden('Yalnizca birim amiri kendi ayini onaylayabilir.');
        }

        $body = $request->getJsonBody();
        $ay = trim((string) ($body['ay'] ?? ''));
        [$ayBaslangic, $ayBitis] = self::resolveMonth($ay);
        $subeId = self::requireScope($user, $request);
        $amirId = self::userId($user);
        $aciklama = isset($body['aciklama']) ? trim((string) $body['aciklama']) : null;
        if ($aciklama === '') {
            $aciklama = null;
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);

        try {
            $pdo->beginTransaction();
            $existing = self::fetchExisting($pdo, $subeId, $amirId, $ay, true);
            if ($existing) {
                self::rollbackConflict($pdo, 'Bu ay icin aylik bildirim onayi zaten mevcut.');
            }

            $context = self::buildMonthContext($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, true);
            [$canApprove, $blockReason] = self::approvalState($context, null);
            if (!$canApprove) {
                self::rollbackConflict($pdo, (string) $blockReason);
            }

            $insert = $pdo->prepare('
                INSERT INTO aylik_bildirim_onaylari (
                    sube_id, birim_amiri_user_id, ay, ay_baslangic, ay_bitis,
                    state, onaylayan_user_id, onaylandi_at, aciklama
                ) VALUES (
                    :sube_id, :birim_amiri_user_id, :ay, :ay_baslangic, :ay_bitis,
                    :state, :onaylayan_user_id, NOW(), :aciklama
                )
            ');
            $insert->execute([
                'sube_id' => $subeId,
                'birim_amiri_user_id' => $amirId,
                'ay' => $ay,
                'ay_baslangic' => $ayBaslangic,
                'ay_bitis' => $ayBitis,
                'state' => 'TAMAMLANDI',
                'onaylayan_user_id' => $amirId,
                'aciklama' => $aciklama,
            ]);
            $onayId = (int) $pdo->lastInsertId();

            $detail = self::buildDetail($pdo, $onayId);
            $pdo->commit();
            JsonResponse::success($detail, [], 201);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                self::conflict('Bu ay icin aylik bildirim onayi zaten mevcut.');
            }
            JsonResponse::serverError('Aylik bildirim onayi olusturulamadi.');
        }
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'aylik_bildirim_onayi.view');

        $onayId = self::positiveInt($id);
        if ($onayId === null) {
            JsonResponse::notFound('Aylik bildirim onayi bulunamadi.');
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        $detail = self::buildDetail($pdo, $onayId);
        if (!$detail) {
            JsonResponse::notFound('Aylik bildirim onayi bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $detail['onay']['sube_id']);
        if (strtoupper(trim((string) ($user['rol'] ?? ''))) === 'BIRIM_AMIRI'
            && (int) $detail['onay']['birim_amiri_user_id'] !== self::userId($user)) {
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
        foreach (['gunluk_bildirimler', 'haftalik_bildirim_mutabakatlari', 'aylik_bildirim_onaylari'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
            if (!$stmt || !$stmt->fetch()) {
                JsonResponse::serverError('Aylik bildirim onayi migration uygulanmadi.');
            }
        }
    }

    private static function requireScope(array $user, Request $request)
    {
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Aylik bildirim onayi icin aktif sube secilmelidir.');
        }
        return (int) $scope;
    }

    private static function resolveAmirId(array $user, Request $request)
    {
        $role = strtoupper(trim((string) ($user['rol'] ?? '')));
        if ($role === 'BIRIM_AMIRI') {
            return self::userId($user);
        }

        $requested = self::parsePositiveInt($request->getQuery('birim_amiri_user_id'));
        return $requested;
    }

    private static function resolveMonth($value)
    {
        $ay = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            self::validationError('ay', 'Ay parametresi YYYY-MM formatinda olmalidir.');
        }

        $parts = explode('-', $ay);
        $year = (int) $parts[0];
        $month = (int) $parts[1];
        if ($month < 1 || $month > 12) {
            self::validationError('ay', 'Ay parametresi YYYY-MM formatinda olmalidir.');
        }

        $ayBaslangic = sprintf('%04d-%02d-01', $year, $month);
        $ayBitis = (new \DateTimeImmutable($ayBaslangic))->modify('last day of this month')->format('Y-m-d');

        return [$ay, $ayBaslangic, $ayBitis];
    }

    private static function listWeeksIntersectingMonth($ayBaslangic, $ayBitis)
    {
        $start = new \DateTimeImmutable($ayBaslangic);
        $end = new \DateTimeImmutable($ayBitis);
        $day = (int) $start->format('N');
        $monday = $start->modify('-' . ($day - 1) . ' days');

        $weeks = [];
        while ($monday <= $end) {
            $weeks[] = [
                'hafta_baslangic' => $monday->format('Y-m-d'),
                'hafta_bitis' => $monday->modify('+6 days')->format('Y-m-d'),
            ];
            $monday = $monday->modify('+7 days');
        }

        return $weeks;
    }

    private static function fetchBildirimler(PDO $pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $forUpdate = false)
    {
        $ownerWhere = $amirId !== null ? ' AND created_by = :created_by' : '';
        $sql = '
            SELECT id, tarih, state, haftalik_mutabakat_id
            FROM gunluk_bildirimler
            WHERE sube_id = :sube_id' . $ownerWhere . '
              AND tarih BETWEEN :ay_baslangic AND :ay_bitis
        ' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $params = [
            'sube_id' => $subeId,
            'ay_baslangic' => $ayBaslangic,
            'ay_bitis' => $ayBitis,
        ];
        if ($amirId !== null) {
            $params['created_by'] = $amirId;
        }
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function fetchMutabakatMap(PDO $pdo, $subeId, $amirId, array $weekStarts)
    {
        if (count($weekStarts) === 0) {
            return [];
        }

        $placeholders = [];
        $params = [
            'sube_id' => $subeId,
            'birim_amiri_user_id' => $amirId,
        ];
        foreach ($weekStarts as $index => $weekStart) {
            $key = 'week_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $weekStart;
        }

        $stmt = $pdo->prepare('
            SELECT * FROM haftalik_bildirim_mutabakatlari
            WHERE sube_id = :sube_id
              AND birim_amiri_user_id = :birim_amiri_user_id
              AND hafta_baslangic IN (' . implode(', ', $placeholders) . ')
        ');
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['hafta_baslangic']] = $row;
        }

        return $map;
    }

    private static function buildMonthContext(PDO $pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $forUpdate = false)
    {
        $rows = self::fetchBildirimler($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $forUpdate);
        $weeks = self::listWeeksIntersectingMonth($ayBaslangic, $ayBitis);
        $weekStarts = array_map(static function (array $week) {
            return $week['hafta_baslangic'];
        }, $weeks);
        $mutabakatMap = $amirId !== null
            ? self::fetchMutabakatMap($pdo, $subeId, $amirId, $weekStarts)
            : [];

        $counts = [
            'toplam_bildirim' => 0,
            'mutabakata_alinan' => 0,
            'mutabakatli_hafta' => 0,
            'eksik_hafta' => 0,
            'taslak' => 0,
            'duzeltme_istendi' => 0,
            'gonderildi' => 0,
        ];
        $stateMap = [
            'TASLAK' => 'taslak',
            'GONDERILDI' => 'gonderildi',
            'DUZELTME_ISTENDI' => 'duzeltme_istendi',
            'HAFTALIK_MUTABAKATA_ALINDI' => 'mutabakata_alinan',
        ];

        foreach ($rows as $row) {
            $state = strtoupper(trim((string) $row['state']));
            if ($state === 'IPTAL') {
                continue;
            }
            $counts['toplam_bildirim']++;
            if (isset($stateMap[$state])) {
                $counts[$stateMap[$state]]++;
            }
        }

        $haftalar = [];
        foreach ($weeks as $week) {
            $weekStart = $week['hafta_baslangic'];
            $weekEnd = $week['hafta_bitis'];
            $weekRows = array_filter($rows, static function (array $row) use ($weekStart, $weekEnd, $ayBaslangic, $ayBitis) {
                $tarih = (string) $row['tarih'];
                if ($tarih < $ayBaslangic || $tarih > $ayBitis) {
                    return false;
                }
                return $tarih >= $weekStart && $tarih <= $weekEnd && strtoupper((string) $row['state']) !== 'IPTAL';
            });

            $bildirimSayisi = count($weekRows);
            $mutabakataAlinan = 0;
            foreach ($weekRows as $row) {
                if (strtoupper((string) $row['state']) === 'HAFTALIK_MUTABAKATA_ALINDI') {
                    $mutabakataAlinan++;
                }
            }

            $mutabakat = $mutabakatMap[$weekStart] ?? null;
            $eksikMi = $bildirimSayisi > 0 && $mutabakat === null;
            $blokNedeni = null;
            if ($eksikMi) {
                $blokNedeni = 'Haftalik mutabakat eksik.';
                $counts['eksik_hafta']++;
            } elseif ($mutabakat !== null) {
                $counts['mutabakatli_hafta']++;
            }

            $haftalar[] = [
                'hafta_baslangic' => $weekStart,
                'hafta_bitis' => $weekEnd,
                'mutabakat_id' => $mutabakat ? (int) $mutabakat['id'] : null,
                'state' => $mutabakat ? (string) $mutabakat['state'] : null,
                'bildirim_sayisi' => $bildirimSayisi,
                'mutabakata_alinan_sayisi' => $mutabakataAlinan,
                'eksik_mi' => $eksikMi,
                'blok_nedeni' => $blokNedeni,
            ];
        }

        return [
            'counts' => $counts,
            'haftalar' => $haftalar,
        ];
    }

    private static function approvalState(array $context, $existing)
    {
        if ($existing) {
            return [false, 'Bu ay icin aylik bildirim onayi zaten mevcut.'];
        }

        $counts = $context['counts'];
        if ($counts['taslak'] > 0) {
            return [false, 'Ayda taslak bildirim bulunuyor.'];
        }
        if ($counts['duzeltme_istendi'] > 0) {
            return [false, 'Ayda duzeltme bekleyen bildirim bulunuyor.'];
        }
        if ($counts['gonderildi'] > 0) {
            return [false, 'Ayda haftalik mutabakata alinmamis gonderilmis bildirim bulunuyor.'];
        }
        if ($counts['mutabakata_alinan'] < 1) {
            return [false, 'Aylik onaya alinacak mutabakata alinmis bildirim bulunamadi.'];
        }
        if ($counts['eksik_hafta'] > 0) {
            return [false, 'Ayda eksik haftalik mutabakat bulunuyor.'];
        }
        if ($counts['toplam_bildirim'] < 1) {
            return [false, 'Aylik onaya alinacak bildirim bulunamadi.'];
        }

        return [true, null];
    }

    private static function buildSummaryPayload(PDO $pdo, $subeId, $amirId, $ay, $ayBaslangic, $ayBitis)
    {
        $existing = $amirId !== null ? self::fetchExisting($pdo, $subeId, $amirId, $ay) : null;
        $context = $amirId !== null
            ? self::buildMonthContext($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis)
            : ['counts' => self::emptyCounts(), 'haftalar' => []];
        [$canApprove, $blockReason] = $amirId !== null
            ? self::approvalState($context, $existing)
            : [false, 'Birim amiri secimi zorunludur.'];

        return [
            'ay' => $ay,
            'ay_baslangic' => $ayBaslangic,
            'ay_bitis' => $ayBitis,
            'sube_id' => $subeId,
            'birim_amiri_user_id' => $amirId,
            'haftalar' => $context['haftalar'],
            'counts' => $context['counts'],
            'onaylanabilir_mi' => $canApprove,
            'blok_nedeni' => $blockReason,
            'mevcut_onay_id' => $existing ? (int) $existing['id'] : null,
        ];
    }

    private static function emptyCounts()
    {
        return [
            'toplam_bildirim' => 0,
            'mutabakata_alinan' => 0,
            'mutabakatli_hafta' => 0,
            'eksik_hafta' => 0,
            'taslak' => 0,
            'duzeltme_istendi' => 0,
            'gonderildi' => 0,
        ];
    }

    private static function fetchExisting(PDO $pdo, $subeId, $amirId, $ay, $forUpdate = false)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM aylik_bildirim_onaylari
            WHERE sube_id = :sube_id
              AND birim_amiri_user_id = :birim_amiri_user_id
              AND ay = :ay
            ORDER BY id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([
            'sube_id' => $subeId,
            'birim_amiri_user_id' => $amirId,
            'ay' => $ay,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function buildDetail(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM aylik_bildirim_onaylari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $subeId = (int) $row['sube_id'];
        $amirId = (int) $row['birim_amiri_user_id'];
        $ayBaslangic = (string) $row['ay_baslangic'];
        $ayBitis = (string) $row['ay_bitis'];
        $context = self::buildMonthContext($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis);

        $mutabakatIds = [];
        foreach ($context['haftalar'] as $week) {
            if ($week['mutabakat_id'] !== null) {
                $mutabakatIds[] = (int) $week['mutabakat_id'];
            }
        }
        $mutabakatlar = self::fetchMutabakatlarByIds($pdo, $mutabakatIds);

        return [
            'onay' => self::mapOnay($row),
            'haftalar' => $context['haftalar'],
            'haftalik_mutabakatlar' => $mutabakatlar,
            'counts' => $context['counts'],
        ];
    }

    private static function fetchMutabakatlarByIds(PDO $pdo, array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return (int) $id > 0;
        })));
        if (count($ids) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('SELECT * FROM haftalik_bildirim_mutabakatlari WHERE id IN (' . $placeholders . ') ORDER BY hafta_baslangic');
        $stmt->execute($ids);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::mapMutabakat($row);
        }

        return $items;
    }

    private static function mapOnay(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'sube_id' => (int) $row['sube_id'],
            'birim_amiri_user_id' => (int) $row['birim_amiri_user_id'],
            'ay' => (string) $row['ay'],
            'ay_baslangic' => (string) $row['ay_baslangic'],
            'ay_bitis' => (string) $row['ay_bitis'],
            'state' => (string) $row['state'],
            'onaylayan_user_id' => (int) $row['onaylayan_user_id'],
            'onaylandi_at' => $row['onaylandi_at'],
            'aciklama' => $row['aciklama'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function mapMutabakat(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'sube_id' => (int) $row['sube_id'],
            'birim_amiri_user_id' => (int) $row['birim_amiri_user_id'],
            'hafta_baslangic' => (string) $row['hafta_baslangic'],
            'hafta_bitis' => (string) $row['hafta_bitis'],
            'state' => (string) $row['state'],
            'onaylayan_user_id' => (int) $row['onaylayan_user_id'],
            'onaylandi_at' => $row['onaylandi_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function userId(array $user)
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) {
            JsonResponse::unauthorized();
        }
        return $id;
    }

    private static function positiveInt($value)
    {
        $id = (int) $value;
        return $id > 0 && (string) $id === trim((string) $value) ? $id : null;
    }

    private static function parsePositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = (int) $value;
        return $parsed > 0 ? $parsed : null;
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
