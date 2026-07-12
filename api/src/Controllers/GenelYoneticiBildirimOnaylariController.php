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

class GenelYoneticiBildirimOnaylariController
{
    public static function summary(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'genel_yonetici_bildirim_onayi.view');

        [$ay, $ayBaslangic, $ayBitis] = self::resolveMonth(trim((string) $request->getQuery('ay', '')));
        $subeId = self::requireScope($user, $request);
        $amirId = self::requireAmirId($request->getQuery('birim_amiri_user_id'));

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        self::assertAmirScope($pdo, $subeId, $amirId);

        $payload = self::buildSummaryPayload($pdo, $subeId, $amirId, $ay, $ayBaslangic, $ayBitis);
        JsonResponse::success($payload);
    }

    public static function approve(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'genel_yonetici_bildirim_onayi.approve');

        $body = $request->getJsonBody();
        [$ay, $ayBaslangic, $ayBitis] = self::resolveMonth(trim((string) ($body['ay'] ?? '')));
        $subeId = self::requireScope($user, $request);
        $amirId = self::requireAmirId($body['birim_amiri_user_id'] ?? null);
        $aciklama = isset($body['aciklama']) ? trim((string) $body['aciklama']) : null;
        if ($aciklama === '') {
            $aciklama = null;
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        self::assertAmirScope($pdo, $subeId, $amirId);

        try {
            $pdo->beginTransaction();

            $existingGy = self::fetchExistingGy($pdo, $subeId, $amirId, $ay, true);
            if ($existingGy) {
                self::rollbackConflict($pdo, 'GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT', 'Bu ay icin genel yonetici ust onayi zaten mevcut.');
            }

            $aylikOnay = self::fetchAylikBildirimOnay($pdo, $subeId, $amirId, $ay);
            if (!$aylikOnay) {
                self::rollbackValidation($pdo, 'AYLIK_BILDIRIM_ONAYI_GEREKLI', 'Aylik bildirim onayi bulunamadi.');
            }

            if (strtoupper(trim((string) $aylikOnay['state'])) !== 'TAMAMLANDI') {
                self::rollbackValidation($pdo, 'AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI', 'Aylik bildirim onayi tamamlanmamis.');
            }

            $context = self::buildMonthContext(
                $pdo,
                $subeId,
                $amirId,
                (string) $aylikOnay['ay_baslangic'],
                (string) $aylikOnay['ay_bitis']
            );
            $invariantError = self::validateAylikInvariant($context);
            if ($invariantError !== null) {
                self::rollbackValidation($pdo, $invariantError['code'], $invariantError['message']);
            }

            $insert = $pdo->prepare('
                INSERT INTO genel_yonetici_bildirim_onaylari (
                    sube_id, birim_amiri_user_id, ay, aylik_bildirim_onayi_id,
                    state, onaylayan_user_id, onaylandi_at, aciklama
                ) VALUES (
                    :sube_id, :birim_amiri_user_id, :ay, :aylik_bildirim_onayi_id,
                    :state, :onaylayan_user_id, NOW(), :aciklama
                )
            ');
            $insert->execute([
                'sube_id' => $subeId,
                'birim_amiri_user_id' => $amirId,
                'ay' => $ay,
                'aylik_bildirim_onayi_id' => (int) $aylikOnay['id'],
                'state' => 'TAMAMLANDI',
                'onaylayan_user_id' => self::userId($user),
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
                self::conflict('GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT', 'Bu ay icin genel yonetici ust onayi zaten mevcut.');
            }
            JsonResponse::serverError('Genel yonetici bildirim onayi olusturulamadi.');
        }
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'genel_yonetici_bildirim_onayi.view');

        $onayId = self::positiveInt($id);
        if ($onayId === null) {
            JsonResponse::notFound('Genel yonetici bildirim onayi bulunamadi.');
        }

        $pdo = self::connection();
        self::assertTablesReady($pdo);
        $detail = self::buildDetail($pdo, $onayId);
        if (!$detail) {
            JsonResponse::notFound('Genel yonetici bildirim onayi bulunamadi.');
        }

        SubeScope::assertPersonelAccess($user, $request, (int) $detail['sube_id']);
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
        foreach (['aylik_bildirim_onaylari', 'genel_yonetici_bildirim_onaylari'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
            if (!$stmt || !$stmt->fetch()) {
                JsonResponse::serverError('Genel yonetici bildirim onayi migration uygulanmadi.');
            }
        }
    }

    private static function requireScope(array $user, Request $request)
    {
        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null) {
            self::validationError('sube_id', 'Genel yonetici bildirim onayi icin aktif sube secilmelidir.');
        }
        return (int) $scope;
    }

    private static function requireAmirId($value)
    {
        $amirId = self::positiveInt($value);
        if ($amirId === null) {
            self::validationError('birim_amiri_user_id', 'Birim amiri secimi zorunludur.');
        }
        return (int) $amirId;
    }

    private static function assertAmirScope(PDO $pdo, $subeId, $amirId)
    {
        $stmt = $pdo->prepare('
            SELECT 1
            FROM users u
            INNER JOIN user_subeler us ON us.user_id = u.id
            WHERE u.id = :user_id
              AND u.rol = :rol
              AND u.durum = :durum
              AND us.sube_id = :sube_id
            LIMIT 1
        ');
        $stmt->execute([
            'user_id' => (int) $amirId,
            'rol' => 'BIRIM_AMIRI',
            'durum' => 'AKTIF',
            'sube_id' => (int) $subeId,
        ]);
        if (!$stmt->fetchColumn()) {
            JsonResponse::forbidden('Secili birim amiri bu sube icin yetkili degil.');
        }
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

    private static function buildSummaryPayload(PDO $pdo, $subeId, $amirId, $ay, $ayBaslangic, $ayBitis)
    {
        $existingGy = self::fetchExistingGy($pdo, $subeId, $amirId, $ay);
        $aylikOnay = self::fetchAylikBildirimOnay($pdo, $subeId, $amirId, $ay);
        $context = self::buildMonthContext($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis);
        [$canApprove, $blockReason] = self::approvalState($existingGy, $aylikOnay, $context);

        return [
            'ay' => $ay,
            'ay_baslangic' => $ayBaslangic,
            'ay_bitis' => $ayBitis,
            'sube_id' => $subeId,
            'birim_amiri_user_id' => $amirId,
            'counts' => [
                'toplam_bildirim' => $context['counts']['toplam_bildirim'],
                'mutabakata_alinan' => $context['counts']['mutabakata_alinan'],
                'eksik_hafta' => $context['counts']['eksik_hafta'],
            ],
            'aylik_bildirim_onayi' => $aylikOnay ? self::mapAylikOnaySummary($aylikOnay) : null,
            'genel_yonetici_bildirim_onayi' => $existingGy ? self::mapGyOnaySummary($existingGy) : null,
            'onay_verilebilir_mi' => $canApprove,
            'blok_nedeni' => $blockReason,
        ];
    }

    private static function approvalState($existingGy, $aylikOnay, array $context)
    {
        if ($existingGy) {
            return [false, 'ZATEN_ONAYLANDI'];
        }

        if (!$aylikOnay) {
            return [false, 'AYLIK_BILDIRIM_ONAYI_GEREKLI'];
        }

        if (strtoupper(trim((string) $aylikOnay['state'])) !== 'TAMAMLANDI') {
            return [false, 'AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI'];
        }

        $invariantError = self::validateAylikInvariant($context);
        if ($invariantError !== null) {
            return [false, $invariantError['code']];
        }

        return [true, null];
    }

    private static function validateAylikInvariant(array $context)
    {
        $counts = $context['counts'];
        if ($counts['eksik_hafta'] > 0) {
            return [
                'code' => 'EKSIK_HAFTA_VAR',
                'message' => 'Ayda eksik haftalik mutabakat bulunuyor.',
            ];
        }
        if ($counts['taslak'] > 0 || $counts['duzeltme_istendi'] > 0 || $counts['gonderildi'] > 0) {
            return [
                'code' => 'AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI',
                'message' => 'Aylik bildirim onayi onkosullari saglanmiyor.',
            ];
        }
        if ($counts['mutabakata_alinan'] < 1 || $counts['toplam_bildirim'] < 1) {
            return [
                'code' => 'AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI',
                'message' => 'Aylik bildirim onayi onkosullari saglanmiyor.',
            ];
        }

        return null;
    }

    private static function fetchAylikBildirimOnay(PDO $pdo, $subeId, $amirId, $ay)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM aylik_bildirim_onaylari
            WHERE sube_id = :sube_id
              AND birim_amiri_user_id = :birim_amiri_user_id
              AND ay = :ay
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([
            'sube_id' => $subeId,
            'birim_amiri_user_id' => $amirId,
            'ay' => $ay,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function fetchExistingGy(PDO $pdo, $subeId, $amirId, $ay, $forUpdate = false)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM genel_yonetici_bildirim_onaylari
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
        $stmt = $pdo->prepare('SELECT * FROM genel_yonetici_bildirim_onaylari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return self::mapGyOnayDetail($row);
    }

    private static function fetchBildirimler(PDO $pdo, $subeId, $amirId, $ayBaslangic, $ayBitis)
    {
        $stmt = $pdo->prepare('
            SELECT id, tarih, state, haftalik_mutabakat_id
            FROM gunluk_bildirimler
            WHERE sube_id = :sube_id
              AND created_by = :created_by
              AND tarih BETWEEN :ay_baslangic AND :ay_bitis
        ');
        $stmt->execute([
            'sube_id' => $subeId,
            'created_by' => $amirId,
            'ay_baslangic' => $ayBaslangic,
            'ay_bitis' => $ayBitis,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    private static function buildMonthContext(PDO $pdo, $subeId, $amirId, $ayBaslangic, $ayBitis)
    {
        $rows = self::fetchBildirimler($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis);
        $weeks = self::listWeeksIntersectingMonth($ayBaslangic, $ayBitis);
        $weekStarts = array_map(static function (array $week) {
            return $week['hafta_baslangic'];
        }, $weeks);
        $mutabakatMap = self::fetchMutabakatMap($pdo, $subeId, $amirId, $weekStarts);

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
            $mutabakat = $mutabakatMap[$weekStart] ?? null;
            if ($bildirimSayisi > 0 && $mutabakat === null) {
                $counts['eksik_hafta']++;
            } elseif ($mutabakat !== null) {
                $counts['mutabakatli_hafta']++;
            }
        }

        return ['counts' => $counts];
    }

    private static function mapAylikOnaySummary(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'state' => (string) $row['state'],
            'onaylandi_at' => $row['onaylandi_at'],
        ];
    }

    private static function mapGyOnaySummary(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'state' => (string) $row['state'],
            'onaylayan_user_id' => (int) $row['onaylayan_user_id'],
            'onaylandi_at' => $row['onaylandi_at'],
            'aciklama' => $row['aciklama'],
        ];
    }

    private static function mapGyOnayDetail(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'sube_id' => (int) $row['sube_id'],
            'birim_amiri_user_id' => (int) $row['birim_amiri_user_id'],
            'ay' => (string) $row['ay'],
            'aylik_bildirim_onayi_id' => (int) $row['aylik_bildirim_onayi_id'],
            'state' => (string) $row['state'],
            'onaylayan_user_id' => (int) $row['onaylayan_user_id'],
            'onaylandi_at' => $row['onaylandi_at'],
            'aciklama' => $row['aciklama'],
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
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;
        return $id > 0 && (string) $id === trim((string) $value) ? $id : null;
    }

    private static function validationError($field, $message)
    {
        JsonResponse::error(422, 'VALIDATION_ERROR', $message, $field);
    }

    private static function conflict($code, $message)
    {
        JsonResponse::error(409, $code, $message);
    }

    private static function rollbackConflict(PDO $pdo, $code, $message)
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        self::conflict($code, $message);
    }

    private static function rollbackValidation(PDO $pdo, $code, $message)
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        JsonResponse::error(422, $code, $message);
    }
}
