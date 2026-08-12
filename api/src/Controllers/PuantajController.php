<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\DonemKapanisAuditService;
use Medisa\Api\Services\DonemKapanisPreflightService;
use Medisa\Api\Services\Payroll\PayrollComplianceGuard;
use Medisa\Api\Services\PuantajDonemKilidiService;
use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\PuantajDonemReopenException;
use Medisa\Api\Services\PuantajDonemReopenService;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionException;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionLedgerService;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionService;
use Medisa\Api\Services\Qr\QrPuantajCandidateReadService;
use Medisa\Api\Services\ResmiTatilTakvimProjectionService;
use PDO;

class PuantajController
{
    /** @var string[] */
    private static $gunTipleri = ['Normal_Is_Gunu', 'Hafta_Tatili_Pazar', 'UBGT_Resmi_Tatil'];

    /** @var string[] */
    private static $hareketDurumlari = ['Geldi', 'Gelmedi', 'Gec_Geldi', 'Erken_Cikti'];

    /** @var string[] */
    private static $dayanaklar = [
        'Yok_Izinsiz',
        'Ucretli_Izinli',
        'Raporlu_Hastalik',
        'Raporlu_Is_Kazasi',
        'Yillik_Izin',
        'Telafi_Calismasi',
        'Gorevde_Calisma',
    ];

    /** @var string[] */
    private static $hesapEtkileri = [
        'Tam_Yevmiye_Ver',
        'Yevmiye_Kes',
        'Ucretli_Izin',
        'Raporlu',
        'Mesai_Yaz',
        'Telafi',
    ];

    /** @var string[] */
    private static $kontrolDurumlari = ['BEKLIYOR', 'AMIR_KONTROL_ETTI'];

    /** @var string[] */
    private static $sgkEksikGunNedenTipleri = [
        'ISTIRAHAT',
        'KISMI_ISTIHDAM',
        'TAM_GUN_DEVAMSIZLIK',
        'GENEL_UCRETSIZ_IZIN',
        'BILINMIYOR',
    ];

    public static function qrAdaylari(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::badRequest('Gecersiz personel parametresi.');
        }

        $pdo = self::getConnection();
        $personel = self::loadPersonel($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        $from = trim((string) $request->getQuery('from', ''));
        $to = trim((string) $request->getQuery('to', ''));

        try {
            $payload = QrPuantajCandidateReadService::listForPersonel(
                $pdo,
                $personelId,
                (int) $personel['sube_id'],
                $from,
                $to
            );
        } catch (\InvalidArgumentException $e) {
            JsonResponse::badRequest($e->getMessage());
        } catch (\Throwable $e) {
            JsonResponse::serverError('QR puantaj adaylari okunamadi.');
        }

        JsonResponse::success($payload);
    }

    public static function qrAdayKarar(Request $request, $personelId, $candidateDate)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.update');

        $personelId = (int) $personelId;
        $tarih = self::normalizeDate($candidateDate);
        if ($personelId <= 0 || $tarih === null) {
            JsonResponse::badRequest('Gecersiz personel veya tarih parametresi.');
        }

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        $allowed = ['action', 'candidate_hash', 'request_nonce', 'gerekce'];
        $filtered = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $filtered[$key] = $body[$key];
            }
        }

        $pdo = self::getConnection();
        $personel = self::loadPersonel($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId < 1) {
            JsonResponse::error(401, 'UNAUTHORIZED', 'Kullanici kimligi dogrulanamadi.');
        }

        try {
            $result = QrPuantajCandidateDecisionService::decide(
                $pdo,
                $personelId,
                (int) $personel['sube_id'],
                $tarih,
                $userId,
                $filtered
            );
            JsonResponse::success($result);
        } catch (QrPuantajCandidateDecisionException $e) {
            $meta = $e->getMeta();
            $field = is_array($meta) && isset($meta['field']) ? (string) $meta['field'] : null;
            JsonResponse::error(
                $e->getHttpStatus(),
                $e->getErrorCode(),
                $e->getMessage(),
                $field,
                is_array($meta) ? $meta : []
            );
        } catch (\InvalidArgumentException $e) {
            JsonResponse::badRequest($e->getMessage());
        } catch (\Throwable $e) {
            JsonResponse::serverError('QR puantaj aday karari kaydedilemedi.');
        }
    }

    public static function qrAdayKararlar(Request $request, $personelId, $candidateDate)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');

        $personelId = (int) $personelId;
        $tarih = self::normalizeDate($candidateDate);
        if ($personelId <= 0 || $tarih === null) {
            JsonResponse::badRequest('Gecersiz personel veya tarih parametresi.');
        }

        $pdo = self::getConnection();
        $personel = self::loadPersonel($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        try {
            QrPuantajCandidateDecisionLedgerService::assertSchemaReady($pdo);
            $rows = QrPuantajCandidateDecisionLedgerService::listForPersonelDate($pdo, $personelId, $tarih);
            $items = [];
            foreach ($rows as $row) {
                $items[] = QrPuantajCandidateDecisionLedgerService::mapPublic($row);
            }
            JsonResponse::success([
                'personel_id' => $personelId,
                'candidate_date' => $tarih,
                'items' => $items,
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'QR_PUANTAJ_DECISION_LEDGER_MISSING') {
                JsonResponse::success([
                    'personel_id' => $personelId,
                    'candidate_date' => $tarih,
                    'items' => [],
                ]);
            }
            JsonResponse::serverError('QR puantaj karar gecmisi okunamadi.');
        } catch (\Throwable $e) {
            JsonResponse::serverError('QR puantaj karar gecmisi okunamadi.');
        }
    }

    public static function detail(Request $request, $personelId, $tarih)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.view');
        $personelId = (int) $personelId;
        $tarih = self::normalizeDate($tarih);

        if ($personelId <= 0 || $tarih === null) {
            JsonResponse::badRequest('Gecersiz puantaj parametreleri.');
        }

        $pdo = self::getConnection();
        $personel = self::loadPersonel($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        $row = self::findPuantajRow($pdo, $personelId, $tarih);
        if (!$row) {
            JsonResponse::success(null);
        }

        JsonResponse::success(self::mapRow($row));
    }

    public static function upsert(Request $request, $personelId, $tarih)
    {
        $user = AuthMiddleware::authenticate($request, true);
        $payload = $request->getJsonBody();
        self::assertUpsertPermission($user, $payload);

        $personelId = (int) $personelId;
        $tarih = self::normalizeDate($tarih);
        if ($personelId <= 0 || $tarih === null) {
            JsonResponse::badRequest('Gecersiz puantaj parametreleri.');
        }

        $pdo = self::getConnection();
        $personel = self::loadPersonel($pdo, $personelId);
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        try {
            $pdo->beginTransaction();
            $periodLock = PuantajDonemKilidiService::acquireForDate($pdo, (int) $personel['sube_id'], $tarih);
            try {
                PuantajDonemPeriodService::assertCanonicalWriteAllowed(
                    $pdo,
                    (int) $periodLock['sube_id'],
                    (int) $periodLock['yil'],
                    (int) $periodLock['ay']
                );
            } catch (PuantajDonemReopenException $lockEx) {
                $pdo->rollBack();
                JsonResponse::error($lockEx->getCode(), $lockEx->getErrorCode(), $lockEx->getMessage(), null, $lockEx->getMeta());
            }

            $existing = self::findPuantajRow($pdo, $personelId, $tarih);
            if ($existing && (string) ($existing['state'] ?? 'ACIK') === 'MUHURLENDI'
                && !PuantajDonemPeriodService::isPeriodReopened(
                    $pdo,
                    (int) $periodLock['sube_id'],
                    (int) $periodLock['yil'],
                    (int) $periodLock['ay']
                )
            ) {
                $pdo->rollBack();
                JsonResponse::error(409, 'PERIOD_LOCKED', 'Bu donem muhurlenmis, puantaj kaydi guncellenemez.');
            }

            $values = self::buildUpsertValues($payload, $existing ?: [], $personelId, $tarih);
            $values = self::applyTatilProjection($pdo, $values);
            self::assertAgeCompliance($personel, $values, $tarih);
            if ($existing) {
                self::updatePuantajRow($pdo, (int) $existing['id'], $values);
            } else {
                self::insertPuantajRow($pdo, $values);
            }

            $row = self::findPuantajRow($pdo, $personelId, $tarih);
            $pdo->commit();
            JsonResponse::success(self::mapRow($row ?: $values));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Puantaj kaydi guncellenemedi.');
        }
    }

    public static function muhurleAylik(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.muhurle');

        $payload = $request->getJsonBody();
        $yil = self::readRequiredInt($payload, 'yil', 2000, 2100);
        $ay = self::readRequiredInt($payload, 'ay', 1, 12);
        $donem = sprintf('%04d-%02d', $yil, $ay);

        $pdo = self::getConnection();
        $subeId = SubeScope::resolveScope($user, $request);
        if ($subeId === null) {
            JsonResponse::badRequest('Muhurlenecek donem icin aktif sube secilmelidir.', 'VALIDATION_ERROR', 'sube_id');
        }

        self::assertSubeExists($pdo, (int) $subeId);

        $firstDay = sprintf('%04d-%02d-01', $yil, $ay);
        $lastDay = date('Y-m-t', strtotime($firstDay));
        $requestHashPayload = self::canonicalizeMuhurPayload($payload);

        try {
            $pdo->beginTransaction();

            PuantajDonemKilidiService::acquire($pdo, (int) $subeId, $yil, $ay);
            $periodState = PuantajDonemPeriodService::resolvePeriodState($pdo, (int) $subeId, $yil, $ay);
            if ($periodState === PuantajDonemPeriodService::STATE_REOPENED
                || $periodState === PuantajDonemPeriodService::STATE_REOPEN_PENDING
            ) {
                $pdo->rollBack();
                JsonResponse::error(
                    409,
                    $periodState === PuantajDonemPeriodService::STATE_REOPENED ? 'PERIOD_REOPENED' : 'PERIOD_LOCKED',
                    $periodState === PuantajDonemPeriodService::STATE_REOPENED
                        ? 'Reopen oturumunda ilk muhurleme yerine reseal kullanin.'
                        : 'Reopen talebi beklerken muhurleme yapilamaz.'
                );
            }
            $existing = self::findMonthlySeal($pdo, (int) $subeId, $yil, $ay);
            if ($existing) {
                $pdo->commit();
                JsonResponse::success([
                    'muhur_id' => (int) $existing['id'],
                    'sube_id' => (int) $existing['sube_id'],
                    'yil' => (int) $existing['yil'],
                    'ay' => (int) $existing['ay'],
                    'donem' => (string) $existing['donem'],
                    'durum' => (string) $existing['durum'],
                    'muhurlenen_kayit_sayisi' => 0,
                ]);
            }

            $preflight = DonemKapanisPreflightService::evaluate($pdo, (int) $subeId, $yil, $ay);
            $requestHash = DonemKapanisAuditService::computeRequestHash(
                $user,
                (int) $subeId,
                $yil,
                $ay,
                $requestHashPayload,
                (string) ($preflight['preflight_hash'] ?? '')
            );

            if ((int) ($preflight['blocker_count'] ?? 0) > 0) {
                $audit = DonemKapanisAuditService::recordBlocked(
                    $pdo,
                    $preflight,
                    $user,
                    (int) $subeId,
                    $yil,
                    $ay,
                    $requestHash
                );
                $pdo->commit();
                self::periodCloseBlocked($preflight, $audit);
            }

            $revisionNo = PuantajDonemPeriodService::nextRevisionNo($pdo, (int) $subeId, $yil, $ay);
            $muhurId = self::insertSealHeader(
                $pdo,
                (int) $subeId,
                $yil,
                $ay,
                $donem,
                $revisionNo,
                isset($user['id']) ? (int) $user['id'] : null,
                null,
                null
            );

            $rows = self::selectRowsForSeal($pdo, (int) $subeId, $firstDay, $lastDay);
            self::insertSealRows($pdo, $muhurId, $rows);

            $ids = array_map(static function ($row) {
                return (int) $row['id'];
            }, $rows);
            if ($ids) {
                self::markRowsSealed($pdo, $muhurId, $ids);
            }

            $sourceHash = self::computeSealSourceHash($rows);
            self::finalizeSealHeader($pdo, $muhurId, count($rows), $sourceHash);

            \Medisa\Api\Services\Retention\ArchiveManifestService::requireManifestSideEffect($pdo, static function () use ($pdo, $subeId, $yil, $ay, $muhurId, $user) {
                \Medisa\Api\Services\Retention\ArchiveManifestService::createPuantajPeriodManifests(
                    $pdo,
                    (int) $subeId,
                    (int) $yil,
                    (int) $ay,
                    (int) $muhurId,
                    isset($user['id']) ? (int) $user['id'] : 0
                );
            });

            $audit = DonemKapanisAuditService::recordSuccess(
                $pdo,
                $preflight,
                $user,
                (int) $subeId,
                $yil,
                $ay,
                $muhurId,
                $requestHash
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Puantaj donemi muhurlenemedi.');
        }

        JsonResponse::success([
            'muhur_id' => $muhurId,
            'sube_id' => (int) $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => $donem,
            'durum' => 'MUHURLENDI',
            'muhurlenen_kayit_sayisi' => count($rows),
            'preflight_hash' => (string) ($preflight['preflight_hash'] ?? ''),
            'audit' => self::mapCloseAuditSummary($audit),
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function canonicalizeMuhurPayload(array $payload)
    {
        $allowed = ['yil', 'ay'];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = $payload[$key];
            }
        }
        ksort($out);

        return $out;
    }

    /** @param array<string, mixed>|null $audit @return array<string, mixed>|null */
    private static function mapCloseAuditSummary($audit)
    {
        if (!is_array($audit)) {
            return null;
        }

        return [
            'id' => (int) ($audit['id'] ?? 0),
            'action' => (string) ($audit['action'] ?? ''),
            'result_state' => (string) ($audit['result_state'] ?? ''),
            'muhur_id' => isset($audit['muhur_id']) && $audit['muhur_id'] !== null ? (int) $audit['muhur_id'] : null,
            'blocker_count' => (int) ($audit['blocker_count'] ?? 0),
            'warning_count' => (int) ($audit['warning_count'] ?? 0),
            'preflight_hash' => (string) ($audit['preflight_hash'] ?? ''),
            'request_hash' => (string) ($audit['request_hash'] ?? ''),
            'result_hash' => (string) ($audit['result_hash'] ?? ''),
            'created_at' => (string) ($audit['created_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $preflight @param array<string, mixed>|null $audit */
    private static function periodCloseBlocked(array $preflight, $audit)
    {
        $blockerCodes = [];
        foreach ($preflight['blockers'] ?? [] as $issue) {
            if (is_array($issue) && isset($issue['code'])) {
                $blockerCodes[] = (string) $issue['code'];
            }
        }
        $blockerCodes = array_values(array_unique($blockerCodes));
        sort($blockerCodes);

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(409);
        }

        echo json_encode([
            'data' => [
                'blocker_count' => (int) ($preflight['blocker_count'] ?? 0),
                'blocker_codes' => $blockerCodes,
                'preflight_hash' => (string) ($preflight['preflight_hash'] ?? ''),
                'generated_at' => (string) ($preflight['generated_at'] ?? ''),
                'audit' => self::mapCloseAuditSummary(is_array($audit) ? $audit : null),
            ],
            'meta' => [],
            'errors' => [[
                'code' => 'PERIOD_CLOSE_BLOCKED',
                'message' => 'Donem kapanisi engellendi.',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @return PDO */
    private static function getConnection()
    {
        try {
            return Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function isAmirKontrolOnlyPayload(array $payload)
    {
        return count($payload) === 1
            && array_key_exists('kontrol_durumu', $payload)
            && (string) $payload['kontrol_durumu'] === 'AMIR_KONTROL_ETTI';
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $payload */
    private static function assertUpsertPermission(array $user, array $payload)
    {
        if (self::isAmirKontrolOnlyPayload($payload)) {
            RolePermissions::assertAny($user, ['puantaj.amir_kontrol', 'puantaj.update']);
            return;
        }

        RolePermissions::assert($user, 'puantaj.update');
    }

    private static function normalizeDate($value)
    {
        $date = rawurldecode((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $parts = explode('-', $date);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private static function loadPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id, sube_id, dogum_tarihi FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$personel) {
            JsonResponse::notFound('Personel bulunamadi.');
        }

        return $personel;
    }

    /**
     * 18 yas alti / dogum tarihi hard-block (gece bandi veya Mesai_Yaz).
     *
     * @param array<string, mixed> $personel
     * @param array<string, mixed> $values
     */
    private static function assertAgeCompliance(array $personel, array $values, string $tarih): void
    {
        $isOvertime = self::valuesInvolveOvertime($values);
        $isNight = self::geceBandinaGiriyor(
            isset($values['giris_saati']) ? (string) $values['giris_saati'] : null,
            isset($values['cikis_saati']) ? (string) $values['cikis_saati'] : null
        );

        if (!$isOvertime && !$isNight) {
            return;
        }

        $dobRaw = $personel['dogum_tarihi'] ?? null;
        $dob = null;
        if ($dobRaw !== null && trim((string) $dobRaw) !== '') {
            $dob = substr(trim((string) $dobRaw), 0, 10);
        }

        $age = PayrollComplianceGuard::resolveUnder18($dob, $tarih);
        if ($age['missing_dob']) {
            JsonResponse::error(
                422,
                PayrollComplianceGuard::BLOCKER_DOGUM_TARIHI_REQUIRED,
                'Dogum tarihi olmadan fazla calisma veya gece calismasi islemi yapilamaz.'
            );
        }

        if (!$age['under_18']) {
            return;
        }

        if ($isNight) {
            JsonResponse::error(
                409,
                PayrollComplianceGuard::BLOCKER_ONSEKIZ_YAS_GECE,
                '18 yasini doldurmamis personelde gece calismasi yapilamaz.'
            );
        }

        JsonResponse::error(
            409,
            PayrollComplianceGuard::BLOCKER_ONSEKIZ_YAS_FAZLA_CALISMA,
            '18 yasini doldurmamis personelde fazla calisma (Mesai_Yaz) yapilamaz.'
        );
    }

    /** @param array<string, mixed> $values */
    private static function valuesInvolveOvertime(array $values): bool
    {
        $hesap = isset($values['hesap_etkisi']) ? (string) $values['hesap_etkisi'] : '';
        if ($hesap === 'Mesai_Yaz') {
            return true;
        }

        // Esdeger mesai alanlari (gelecek kolon / legacy alias)
        foreach (['mesai_dakika', 'fazla_mesai_dakika', 'fazla_calisma_dakika'] as $field) {
            if (isset($values[$field]) && (int) $values[$field] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * TS geceBandinaGiriyor parity: gece bandi [00:00, 06:00) U [20:00, 24:00).
     * Cikis giristen erkense vardiya gece yarimini asar ve cikis ertesi gune tasinir.
     */
    private static function geceBandinaGiriyor(?string $giris, ?string $cikis): bool
    {
        $aralik = self::normalizeCalismaAraligi($giris, $cikis);
        if ($aralik !== null) {
            foreach ([0, 24 * 60] as $gunBaslangici) {
                if (self::zamanAraligiKesisimDakika(
                    $aralik['baslangic'],
                    $aralik['bitis'],
                    $gunBaslangici,
                    $gunBaslangici + (6 * 60)
                ) > 0) {
                    return true;
                }
                if (self::zamanAraligiKesisimDakika(
                    $aralik['baslangic'],
                    $aralik['bitis'],
                    $gunBaslangici + (20 * 60),
                    $gunBaslangici + (24 * 60)
                ) > 0) {
                    return true;
                }
            }

            return false;
        }

        $girisMin = self::parseTimeToMinutes($giris);
        $cikisMin = self::parseTimeToMinutes($cikis);

        return ($girisMin !== null && $girisMin < 6 * 60)
            || ($cikisMin !== null && $cikisMin >= 20 * 60);
    }

    /** @return array{baslangic:int, bitis:int}|null */
    private static function normalizeCalismaAraligi(?string $giris, ?string $cikis): ?array
    {
        $girisMin = self::parseTimeToMinutes($giris);
        $cikisMin = self::parseTimeToMinutes($cikis);
        if ($girisMin === null || $cikisMin === null) {
            return null;
        }

        return [
            'baslangic' => $girisMin,
            'bitis' => $cikisMin < $girisMin ? $cikisMin + (24 * 60) : $cikisMin,
        ];
    }

    private static function zamanAraligiKesisimDakika(int $aralikBas, int $aralikBit, int $bandBas, int $bandBit): int
    {
        return max(0, min($aralikBit, $bandBit) - max($aralikBas, $bandBas));
    }

    private static function parseTimeToMinutes(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m)) {
            return null;
        }
        $hours = (int) $m[1];
        $minutes = (int) $m[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    private static function assertSubeExists(PDO $pdo, $subeId)
    {
        $stmt = $pdo->prepare('SELECT id FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $subeId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::badRequest('Secili sube bulunamadi.', 'VALIDATION_ERROR', 'sube_id');
        }
    }

    /** @return array<string, mixed>|false */
    private static function findPuantajRow(PDO $pdo, $personelId, $tarih)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM gunluk_puantaj WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1'
        );
        $stmt->execute([
            'personel_id' => $personelId,
            'tarih' => $tarih,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function assertPeriodOpen(PDO $pdo, $subeId, $tarih)
    {
        $period = self::periodFromDate($tarih);
        $seal = self::findMonthlySeal($pdo, $subeId, (int) $period['yil'], (int) $period['ay']);
        if ($seal) {
            JsonResponse::error(409, 'PERIOD_LOCKED', 'Bu donem muhurlenmis, puantaj kaydi guncellenemez.');
        }
    }

    /** @return array<string, int> */
    private static function periodFromDate($tarih)
    {
        return [
            'yil' => (int) substr($tarih, 0, 4),
            'ay' => (int) substr($tarih, 5, 2),
        ];
    }

    /** @return array<string, mixed>|false */
    private static function findMonthlySeal(PDO $pdo, $subeId, $yil, $ay)
    {
        $row = PuantajDonemPeriodService::findEffectiveSeal($pdo, $subeId, $yil, $ay);

        return $row ?: false;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function computeSealSourceHash(array $rows)
    {
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'personel_id' => (int) ($row['personel_id'] ?? 0),
                'tarih' => (string) ($row['tarih'] ?? ''),
                'gun_tipi' => (string) ($row['gun_tipi'] ?? ''),
                'hareket_durumu' => (string) ($row['hareket_durumu'] ?? ''),
                'dayanak' => (string) ($row['dayanak'] ?? ''),
                'net_calisma_suresi_dakika' => (int) ($row['net_calisma_suresi_dakika'] ?? 0),
                'hafta_tatili_hak_kazandi_mi' => $row['hafta_tatili_hak_kazandi_mi'] ?? null,
                'sgk_eksik_gun_neden_tipi' => $row['sgk_eksik_gun_neden_tipi'] ?? null,
            ];
        }

        return PuantajDonemPeriodService::hashCanonical($payload);
    }

    public static function reopenRequest(Request $request, $yil, $ay)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_reopen.request');
        $payload = $request->getJsonBody();
        $yil = (int) $yil;
        $ay = (int) $ay;
        $pdo = self::getConnection();
        $subeId = self::resolvePeriodSube($user, $request, $payload);

        try {
            $pdo->beginTransaction();
            $result = PuantajDonemReopenService::createReopenRequest(
                $pdo,
                $user,
                $subeId,
                $yil,
                $ay,
                (string) ($payload['gerekce'] ?? '')
            );
            $pdo->commit();
            JsonResponse::success($result, [], 201);
        } catch (PuantajDonemReopenException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getCode(), $e->getErrorCode(), $e->getMessage(), null, $e->getMeta());
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Reopen talebi olusturulamadi.');
        }
    }

    public static function reopenApprove(Request $request, $yil, $ay)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_reopen.approve');
        $payload = $request->getJsonBody();
        $talepId = (int) ($payload['talep_id'] ?? 0);
        if ($talepId < 1) {
            JsonResponse::badRequest('talep_id zorunludur.', 'VALIDATION_ERROR', 'talep_id');
        }
        $pdo = self::getConnection();
        $subeId = self::resolvePeriodSube($user, $request, $payload);

        try {
            $pdo->beginTransaction();
            $result = PuantajDonemReopenService::approveReopenRequest(
                $pdo,
                $user,
                $subeId,
                (int) $yil,
                (int) $ay,
                $talepId,
                $payload['onay_notu'] ?? null
            );
            $pdo->commit();
            JsonResponse::success($result);
        } catch (PuantajDonemReopenException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getCode(), $e->getErrorCode(), $e->getMessage(), null, $e->getMeta());
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Reopen talebi onaylanamadi.');
        }
    }

    public static function reopenReject(Request $request, $yil, $ay)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_reopen.approve');
        $payload = $request->getJsonBody();
        $talepId = (int) ($payload['talep_id'] ?? 0);
        if ($talepId < 1) {
            JsonResponse::badRequest('talep_id zorunludur.', 'VALIDATION_ERROR', 'talep_id');
        }
        $pdo = self::getConnection();
        $subeId = self::resolvePeriodSube($user, $request, $payload);

        try {
            $pdo->beginTransaction();
            $result = PuantajDonemReopenService::rejectReopenRequest(
                $pdo,
                $user,
                $subeId,
                (int) $yil,
                (int) $ay,
                $talepId,
                (string) ($payload['rejection_reason'] ?? '')
            );
            $pdo->commit();
            JsonResponse::success($result);
        } catch (PuantajDonemReopenException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getCode(), $e->getErrorCode(), $e->getMessage(), null, $e->getMeta());
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Reopen talebi reddedilemedi.');
        }
    }

    public static function resealDonem(Request $request, $yil, $ay)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_reseal');
        $payload = $request->getJsonBody();
        $yil = (int) $yil;
        $ay = (int) $ay;
        $pdo = self::getConnection();
        $subeId = self::resolvePeriodSube($user, $request, $payload);
        $expectedPrevious = (int) ($payload['expected_previous_seal_id'] ?? 0);

        try {
            $pdo->beginTransaction();
            $result = PuantajDonemReopenService::reseal(
                $pdo,
                $user,
                $subeId,
                $yil,
                $ay,
                (string) ($payload['neden'] ?? ''),
                $expectedPrevious,
                static function (PDO $pdo, $subeId, $yil, $ay, $revisionNo, $parentMuhurId, $reopenTalepId) use ($user) {
                    return self::createSealRevisionCopy(
                        $pdo,
                        $user,
                        (int) $subeId,
                        (int) $yil,
                        (int) $ay,
                        (int) $revisionNo,
                        (int) $parentMuhurId,
                        (int) $reopenTalepId
                    );
                }
            );
            $pdo->commit();
            JsonResponse::success($result, [], 201);
        } catch (PuantajDonemReopenException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::error($e->getCode(), $e->getErrorCode(), $e->getMessage(), null, $e->getMeta());
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            JsonResponse::serverError('Donem yeniden muhurlenemedi.');
        }
    }

    public static function sealHistory(Request $request, $yil, $ay)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'puantaj.donem_seal.history');
        $pdo = self::getConnection();
        $subeId = self::resolvePeriodSube($user, $request, []);
        JsonResponse::success(PuantajDonemReopenService::sealHistoryPayload($pdo, $subeId, (int) $yil, (int) $ay));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     */
    private static function resolvePeriodSube(array $user, Request $request, array $payload)
    {
        $scoped = SubeScope::resolveScope($user, $request);
        if ($scoped !== null) {
            return (int) $scoped;
        }
        $fromBody = (int) ($payload['sube_id'] ?? 0);
        if ($fromBody > 0) {
            SubeScope::assertPersonelAccess($user, $request, $fromBody);

            return $fromBody;
        }
        JsonResponse::badRequest('Aktif sube secilmelidir.', 'VALIDATION_ERROR', 'sube_id');
    }

    /**
     * @param array<string, mixed> $user
     * @return array{rows: list<array<string, mixed>>, source_hash: string, muhur_id: int}
     */
    private static function createSealRevisionCopy(
        PDO $pdo,
        array $user,
        $subeId,
        $yil,
        $ay,
        $revisionNo,
        $parentMuhurId,
        $reopenTalepId
    ) {
        $donem = sprintf('%04d-%02d', $yil, $ay);
        $firstDay = sprintf('%04d-%02d-01', $yil, $ay);
        $lastDay = date('Y-m-t', strtotime($firstDay));

        $muhurId = self::insertSealHeader(
            $pdo,
            (int) $subeId,
            (int) $yil,
            (int) $ay,
            $donem,
            (int) $revisionNo,
            isset($user['id']) ? (int) $user['id'] : null,
            (int) $parentMuhurId,
            (int) $reopenTalepId
        );

        $rows = self::selectRowsForSeal($pdo, (int) $subeId, $firstDay, $lastDay);
        self::insertSealRows($pdo, $muhurId, $rows);
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $rows);
        if ($ids) {
            self::markRowsSealed($pdo, $muhurId, $ids);
        }
        $sourceHash = self::computeSealSourceHash($rows);
        self::finalizeSealHeader($pdo, $muhurId, count($rows), $sourceHash);

        \Medisa\Api\Services\Retention\ArchiveManifestService::requireManifestSideEffect($pdo, static function () use ($pdo, $subeId, $yil, $ay, $muhurId, $user) {
            \Medisa\Api\Services\Retention\ArchiveManifestService::createPuantajPeriodManifests(
                $pdo,
                (int) $subeId,
                (int) $yil,
                (int) $ay,
                (int) $muhurId,
                isset($user['id']) ? (int) $user['id'] : 0
            );
        });

        return [
            'rows' => $rows,
            'source_hash' => $sourceHash,
            'muhur_id' => $muhurId,
        ];
    }

    /** @return int */
    private static function insertSealHeader(
        PDO $pdo,
        $subeId,
        $yil,
        $ay,
        $donem,
        $revisionNo,
        $createdBy,
        $parentMuhurId,
        $reopenTalepId
    ) {
        try {
            $insertSeal = $pdo->prepare(
                'INSERT INTO puantaj_aylik_muhurleri (
                    sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi,
                    created_by, parent_muhur_id, reopen_talep_id
                 ) VALUES (
                    :sube_id, :yil, :ay, :revision_no, :donem, :durum, 0,
                    :created_by, :parent_muhur_id, :reopen_talep_id
                 )'
            );
            $insertSeal->execute([
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'revision_no' => (int) $revisionNo,
                'donem' => (string) $donem,
                'durum' => 'MUHURLENDI',
                'created_by' => $createdBy,
                'parent_muhur_id' => $parentMuhurId,
                'reopen_talep_id' => $reopenTalepId,
            ]);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            $unknownColumn = stripos($msg, 'Unknown column') !== false
                || stripos($msg, 'no such column') !== false;
            if (!$unknownColumn) {
                throw $e;
            }
            // Yalniz pre-044 gelistirme semasi: revision kolonlari yoksa fallback.
            $insertSeal = $pdo->prepare(
                'INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
                 VALUES (:sube_id, :yil, :ay, :donem, :durum, 0, :created_by)'
            );
            $insertSeal->execute([
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'donem' => (string) $donem,
                'durum' => 'MUHURLENDI',
                'created_by' => $createdBy,
            ]);
        }

        return (int) $pdo->lastInsertId();
    }

    private static function finalizeSealHeader(PDO $pdo, $muhurId, $count, $sourceHash)
    {
        try {
            $upd = $pdo->prepare(
                'UPDATE puantaj_aylik_muhurleri
                 SET muhurlenen_kayit_sayisi = :cnt, source_hash = :hash
                 WHERE id = :id'
            );
            $upd->execute(['cnt' => (int) $count, 'hash' => (string) $sourceHash, 'id' => (int) $muhurId]);
        } catch (\Throwable $e) {
            $upd = $pdo->prepare(
                'UPDATE puantaj_aylik_muhurleri SET muhurlenen_kayit_sayisi = :cnt WHERE id = :id'
            );
            $upd->execute(['cnt' => (int) $count, 'id' => (int) $muhurId]);
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $existing @return array<string, mixed> */
    private static function buildUpsertValues(array $payload, array $existing, $personelId, $tarih)
    {
        return [
            'personel_id' => $personelId,
            'tarih' => $tarih,
            'state' => 'ACIK',
            'gun_tipi' => self::readEnum($payload, 'gun_tipi', self::$gunTipleri, self::existingValue($existing, 'gun_tipi')),
            'hareket_durumu' => self::readEnum(
                $payload,
                'hareket_durumu',
                self::$hareketDurumlari,
                self::existingValue($existing, 'hareket_durumu')
            ),
            'dayanak' => self::readEnum($payload, 'dayanak', self::$dayanaklar, self::existingValue($existing, 'dayanak')),
            'durumu_bildirdi_mi' => self::readBoolean($payload, 'durumu_bildirdi_mi', self::existingValue($existing, 'durumu_bildirdi_mi')),
            'durum_bildirim_aciklamasi' => self::readText(
                $payload,
                'durum_bildirim_aciklamasi',
                self::existingValue($existing, 'durum_bildirim_aciklamasi')
            ),
            'hesap_etkisi' => self::readEnum($payload, 'hesap_etkisi', self::$hesapEtkileri, self::existingValue($existing, 'hesap_etkisi')),
            'sgk_eksik_gun_neden_tipi' => self::readEnum(
                $payload,
                'sgk_eksik_gun_neden_tipi',
                self::$sgkEksikGunNedenTipleri,
                self::existingValue($existing, 'sgk_eksik_gun_neden_tipi')
            ),
            'beklenen_giris_saati' => self::readTime($payload, 'beklenen_giris_saati', self::existingValue($existing, 'beklenen_giris_saati')),
            'beklenen_cikis_saati' => self::readTime($payload, 'beklenen_cikis_saati', self::existingValue($existing, 'beklenen_cikis_saati')),
            'giris_saati' => self::readTime($payload, 'giris_saati', self::existingValue($existing, 'giris_saati')),
            'cikis_saati' => self::readTime($payload, 'cikis_saati', self::existingValue($existing, 'cikis_saati')),
            'gec_kalma_dakika' => self::readNullableInt(
                $payload,
                'gec_kalma_dakika',
                self::existingValue($existing, 'gec_kalma_dakika')
            ),
            'erken_cikis_dakika' => self::readNullableInt(
                $payload,
                'erken_cikis_dakika',
                self::existingValue($existing, 'erken_cikis_dakika')
            ),
            'gercek_mola_dakika' => self::readNullableInt($payload, 'gercek_mola_dakika', self::existingValue($existing, 'gercek_mola_dakika')),
            'hesaplanan_mola_dakika' => self::readNullableInt(
                $payload,
                'hesaplanan_mola_dakika',
                self::existingValue($existing, 'hesaplanan_mola_dakika')
            ),
            'net_calisma_suresi_dakika' => self::readNullableInt(
                $payload,
                'net_calisma_suresi_dakika',
                self::existingValue($existing, 'net_calisma_suresi_dakika')
            ),
            'gunluk_brut_sure_dakika' => self::readNullableInt(
                $payload,
                'gunluk_brut_sure_dakika',
                self::existingValue($existing, 'gunluk_brut_sure_dakika')
            ),
            'hafta_tatili_hak_kazandi_mi' => self::readBoolean(
                $payload,
                'hafta_tatili_hak_kazandi_mi',
                self::existingValue($existing, 'hafta_tatili_hak_kazandi_mi')
            ),
            'tatil_donemi_brut_calisma_dakika' => self::readNullableInt(
                $payload,
                'tatil_donemi_brut_calisma_dakika',
                self::existingValue($existing, 'tatil_donemi_brut_calisma_dakika')
            ),
            'tatil_donemi_ara_dinlenme_dakika' => self::readNullableInt(
                $payload,
                'tatil_donemi_ara_dinlenme_dakika',
                self::existingValue($existing, 'tatil_donemi_ara_dinlenme_dakika')
            ),
            'tatil_donemi_net_calisma_dakika' => self::readNullableInt(
                $payload,
                'tatil_donemi_net_calisma_dakika',
                self::existingValue($existing, 'tatil_donemi_net_calisma_dakika')
            ),
            'kontrol_durumu' => self::readEnum(
                $payload,
                'kontrol_durumu',
                self::$kontrolDurumlari,
                self::existingValue($existing, 'kontrol_durumu') ?: 'BEKLIYOR'
            ),
            'kaynak' => self::readText($payload, 'kaynak', self::existingValue($existing, 'kaynak')),
            'aciklama' => self::readText($payload, 'aciklama', self::existingValue($existing, 'aciklama')),
            'muhur_id' => null,
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function applyTatilProjection(PDO $pdo, array $values)
    {
        $projection = ResmiTatilTakvimProjectionService::projectForPuantajRow($pdo, $values);
        foreach (self::$tatilProjectionColumns as $column) {
            $values[$column] = $projection[$column] ?? null;
        }

        return $values;
    }

    /** @var string[] */
    private static $tatilProjectionColumns = [
        'tatil_takvim_id',
        'tatil_turu',
        'tatil_gun_kapsami',
        'tatil_interval_baslangic',
        'tatil_interval_bitis',
        'tatil_siniflandirma_durumu',
        'tatil_snapshot_hash',
        'tatil_kaynak_referansi',
        'tatil_donemi_brut_calisma_dakika',
        'tatil_donemi_ara_dinlenme_dakika',
        'tatil_donemi_net_calisma_dakika',
    ];

    /** @param array<string, mixed> $row */
    private static function existingValue(array $row, $key)
    {
        return array_key_exists($key, $row) ? $row[$key] : null;
    }

    /** @param array<string, mixed> $payload @param string[] $allowed */
    private static function readEnum(array $payload, $field, array $allowed, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        if (!in_array($value, $allowed, true)) {
            JsonResponse::badRequest('Gecersiz puantaj alani.', 'VALIDATION_ERROR', $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function readTime(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            JsonResponse::badRequest('Gecersiz saat formati.', 'VALIDATION_ERROR', $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function readNullableInt(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback === null ? null : (int) $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (int) $value < 0) {
            JsonResponse::badRequest('Gecersiz sayisal puantaj alani.', 'VALIDATION_ERROR', $field);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $payload */
    private static function readBoolean(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            if ($fallback === null) {
                return null;
            }
            return (int) $fallback === 1 || $fallback === true ? 1 : 0;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
            return (int) $value;
        }

        if ($value === 'true' || $value === 'false') {
            return $value === 'true' ? 1 : 0;
        }

        JsonResponse::badRequest('Gecersiz boolean puantaj alani.', 'VALIDATION_ERROR', $field);
    }

    /** @param array<string, mixed> $payload */
    private static function readText(array $payload, $field, $fallback)
    {
        if (!array_key_exists($field, $payload)) {
            return $fallback;
        }

        $value = $payload[$field];
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    /** @param array<string, mixed> $payload */
    private static function readRequiredInt(array $payload, $field, $min, $max)
    {
        if (!array_key_exists($field, $payload) || !is_numeric($payload[$field])) {
            JsonResponse::badRequest('Gecersiz puantaj parametresi.', 'VALIDATION_ERROR', $field);
        }

        $value = (int) $payload[$field];
        if ($value < $min || $value > $max) {
            JsonResponse::badRequest('Gecersiz puantaj parametresi.', 'VALIDATION_ERROR', $field);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function insertPuantajRow(PDO $pdo, array $values)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO gunluk_puantaj
             (personel_id, tarih, state, gun_tipi, hareket_durumu, dayanak, durumu_bildirdi_mi,
              durum_bildirim_aciklamasi, hesap_etkisi, sgk_eksik_gun_neden_tipi, beklenen_giris_saati, beklenen_cikis_saati,
              giris_saati, cikis_saati, gec_kalma_dakika, erken_cikis_dakika, gercek_mola_dakika, hesaplanan_mola_dakika,
              net_calisma_suresi_dakika, gunluk_brut_sure_dakika, hafta_tatili_hak_kazandi_mi,
              kontrol_durumu, kaynak, aciklama, muhur_id,
              tatil_takvim_id, tatil_turu, tatil_gun_kapsami, tatil_interval_baslangic, tatil_interval_bitis,
              tatil_siniflandirma_durumu, tatil_snapshot_hash, tatil_kaynak_referansi,
              tatil_donemi_brut_calisma_dakika, tatil_donemi_ara_dinlenme_dakika, tatil_donemi_net_calisma_dakika)
             VALUES
             (:personel_id, :tarih, :state, :gun_tipi, :hareket_durumu, :dayanak, :durumu_bildirdi_mi,
              :durum_bildirim_aciklamasi, :hesap_etkisi, :sgk_eksik_gun_neden_tipi, :beklenen_giris_saati, :beklenen_cikis_saati,
              :giris_saati, :cikis_saati, :gec_kalma_dakika, :erken_cikis_dakika, :gercek_mola_dakika, :hesaplanan_mola_dakika,
              :net_calisma_suresi_dakika, :gunluk_brut_sure_dakika, :hafta_tatili_hak_kazandi_mi,
              :kontrol_durumu, :kaynak, :aciklama, :muhur_id,
              :tatil_takvim_id, :tatil_turu, :tatil_gun_kapsami, :tatil_interval_baslangic, :tatil_interval_bitis,
              :tatil_siniflandirma_durumu, :tatil_snapshot_hash, :tatil_kaynak_referansi,
              :tatil_donemi_brut_calisma_dakika, :tatil_donemi_ara_dinlenme_dakika, :tatil_donemi_net_calisma_dakika)'
        );
        $stmt->execute($values);
    }

    /** @param array<string, mixed> $values */
    private static function updatePuantajRow(PDO $pdo, $id, array $values)
    {
        $bindValues = [
            'id' => $id,
            'state' => $values['state'],
            'gun_tipi' => $values['gun_tipi'],
            'hareket_durumu' => $values['hareket_durumu'],
            'dayanak' => $values['dayanak'],
            'durumu_bildirdi_mi' => $values['durumu_bildirdi_mi'],
            'durum_bildirim_aciklamasi' => $values['durum_bildirim_aciklamasi'],
            'hesap_etkisi' => $values['hesap_etkisi'],
            'sgk_eksik_gun_neden_tipi' => $values['sgk_eksik_gun_neden_tipi'],
            'beklenen_giris_saati' => $values['beklenen_giris_saati'],
            'beklenen_cikis_saati' => $values['beklenen_cikis_saati'],
            'giris_saati' => $values['giris_saati'],
            'cikis_saati' => $values['cikis_saati'],
            'gec_kalma_dakika' => $values['gec_kalma_dakika'],
            'erken_cikis_dakika' => $values['erken_cikis_dakika'],
            'gercek_mola_dakika' => $values['gercek_mola_dakika'],
            'hesaplanan_mola_dakika' => $values['hesaplanan_mola_dakika'],
            'net_calisma_suresi_dakika' => $values['net_calisma_suresi_dakika'],
            'gunluk_brut_sure_dakika' => $values['gunluk_brut_sure_dakika'],
            'hafta_tatili_hak_kazandi_mi' => $values['hafta_tatili_hak_kazandi_mi'],
            'kontrol_durumu' => $values['kontrol_durumu'],
            'kaynak' => $values['kaynak'],
            'aciklama' => $values['aciklama'],
            'muhur_id' => $values['muhur_id'],
        ];
        foreach (self::$tatilProjectionColumns as $column) {
            $bindValues[$column] = $values[$column] ?? null;
        }
        $stmt = $pdo->prepare(
            'UPDATE gunluk_puantaj
             SET state = :state,
                 gun_tipi = :gun_tipi,
                 hareket_durumu = :hareket_durumu,
                 dayanak = :dayanak,
                 durumu_bildirdi_mi = :durumu_bildirdi_mi,
                 durum_bildirim_aciklamasi = :durum_bildirim_aciklamasi,
                 hesap_etkisi = :hesap_etkisi,
                 sgk_eksik_gun_neden_tipi = :sgk_eksik_gun_neden_tipi,
                 beklenen_giris_saati = :beklenen_giris_saati,
                 beklenen_cikis_saati = :beklenen_cikis_saati,
                 giris_saati = :giris_saati,
                 cikis_saati = :cikis_saati,
                 gec_kalma_dakika = :gec_kalma_dakika,
                 erken_cikis_dakika = :erken_cikis_dakika,
                 gercek_mola_dakika = :gercek_mola_dakika,
                 hesaplanan_mola_dakika = :hesaplanan_mola_dakika,
                 net_calisma_suresi_dakika = :net_calisma_suresi_dakika,
                 gunluk_brut_sure_dakika = :gunluk_brut_sure_dakika,
                 hafta_tatili_hak_kazandi_mi = :hafta_tatili_hak_kazandi_mi,
                 kontrol_durumu = :kontrol_durumu,
                 kaynak = :kaynak,
                 aciklama = :aciklama,
                 muhur_id = :muhur_id,
                 tatil_takvim_id = :tatil_takvim_id,
                 tatil_turu = :tatil_turu,
                 tatil_gun_kapsami = :tatil_gun_kapsami,
                 tatil_interval_baslangic = :tatil_interval_baslangic,
                 tatil_interval_bitis = :tatil_interval_bitis,
                 tatil_siniflandirma_durumu = :tatil_siniflandirma_durumu,
                 tatil_snapshot_hash = :tatil_snapshot_hash,
                 tatil_kaynak_referansi = :tatil_kaynak_referansi,
                 tatil_donemi_brut_calisma_dakika = :tatil_donemi_brut_calisma_dakika,
                 tatil_donemi_ara_dinlenme_dakika = :tatil_donemi_ara_dinlenme_dakika,
                 tatil_donemi_net_calisma_dakika = :tatil_donemi_net_calisma_dakika,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute($bindValues);
    }

    /** @return array<int, array<string, mixed>> */
    private static function selectRowsForSeal(PDO $pdo, $subeId, $firstDay, $lastDay)
    {
        $stmt = $pdo->prepare(
            'SELECT gp.*
             FROM gunluk_puantaj gp
             INNER JOIN personeller p ON p.id = gp.personel_id
             WHERE p.sube_id = :sube_id
               AND gp.tarih BETWEEN :first_day AND :last_day
               AND gp.state <> :sealed_state
             ORDER BY gp.tarih ASC, gp.personel_id ASC'
        );
        $stmt->execute([
            'sube_id' => $subeId,
            'first_day' => $firstDay,
            'last_day' => $lastDay,
            'sealed_state' => 'MUHURLENDI',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function insertSealRows(PDO $pdo, $muhurId, array $rows)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO puantaj_aylik_muhur_satirlari
             (muhur_id, personel_id, tarih, gun_tipi, hareket_durumu, dayanak, durumu_bildirdi_mi,
              durum_bildirim_aciklamasi, hesap_etkisi, sgk_eksik_gun_neden_tipi, beklenen_giris_saati, beklenen_cikis_saati,
              giris_saati, cikis_saati, gec_kalma_dakika, erken_cikis_dakika, gercek_mola_dakika, hesaplanan_mola_dakika,
              net_calisma_suresi_dakika, gunluk_brut_sure_dakika, hafta_tatili_hak_kazandi_mi,
              kontrol_durumu, kaynak, aciklama,
              tatil_takvim_id, tatil_turu, tatil_gun_kapsami, tatil_interval_baslangic, tatil_interval_bitis,
              tatil_siniflandirma_durumu, tatil_snapshot_hash, tatil_kaynak_referansi,
              tatil_donemi_brut_calisma_dakika, tatil_donemi_ara_dinlenme_dakika, tatil_donemi_net_calisma_dakika)
             VALUES
             (:muhur_id, :personel_id, :tarih, :gun_tipi, :hareket_durumu, :dayanak, :durumu_bildirdi_mi,
              :durum_bildirim_aciklamasi, :hesap_etkisi, :sgk_eksik_gun_neden_tipi, :beklenen_giris_saati, :beklenen_cikis_saati,
              :giris_saati, :cikis_saati, :gec_kalma_dakika, :erken_cikis_dakika, :gercek_mola_dakika, :hesaplanan_mola_dakika,
              :net_calisma_suresi_dakika, :gunluk_brut_sure_dakika, :hafta_tatili_hak_kazandi_mi,
              :kontrol_durumu, :kaynak, :aciklama,
              :tatil_takvim_id, :tatil_turu, :tatil_gun_kapsami, :tatil_interval_baslangic, :tatil_interval_bitis,
              :tatil_siniflandirma_durumu, :tatil_snapshot_hash, :tatil_kaynak_referansi,
              :tatil_donemi_brut_calisma_dakika, :tatil_donemi_ara_dinlenme_dakika, :tatil_donemi_net_calisma_dakika)'
        );

        foreach ($rows as $row) {
            $bind = [
                'muhur_id' => $muhurId,
                'personel_id' => (int) $row['personel_id'],
                'tarih' => $row['tarih'],
                'gun_tipi' => $row['gun_tipi'],
                'hareket_durumu' => $row['hareket_durumu'],
                'dayanak' => $row['dayanak'],
                'durumu_bildirdi_mi' => $row['durumu_bildirdi_mi'],
                'durum_bildirim_aciklamasi' => $row['durum_bildirim_aciklamasi'],
                'hesap_etkisi' => $row['hesap_etkisi'],
                'sgk_eksik_gun_neden_tipi' => $row['sgk_eksik_gun_neden_tipi'] ?? null,
                'beklenen_giris_saati' => $row['beklenen_giris_saati'],
                'beklenen_cikis_saati' => $row['beklenen_cikis_saati'],
                'giris_saati' => $row['giris_saati'],
                'cikis_saati' => $row['cikis_saati'],
                'gec_kalma_dakika' => $row['gec_kalma_dakika'] ?? null,
                'erken_cikis_dakika' => $row['erken_cikis_dakika'] ?? null,
                'gercek_mola_dakika' => $row['gercek_mola_dakika'],
                'hesaplanan_mola_dakika' => $row['hesaplanan_mola_dakika'],
                'net_calisma_suresi_dakika' => $row['net_calisma_suresi_dakika'],
                'gunluk_brut_sure_dakika' => $row['gunluk_brut_sure_dakika'],
                'hafta_tatili_hak_kazandi_mi' => $row['hafta_tatili_hak_kazandi_mi'],
                'kontrol_durumu' => $row['kontrol_durumu'] ?: 'BEKLIYOR',
                'kaynak' => $row['kaynak'],
                'aciklama' => $row['aciklama'],
            ];
            foreach (self::$tatilProjectionColumns as $column) {
                $bind[$column] = $row[$column] ?? null;
            }
            $stmt->execute($bind);
        }
    }

    /** @param int[] $ids */
    private static function markRowsSealed(PDO $pdo, $muhurId, array $ids)
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            'UPDATE gunluk_puantaj
             SET state = ?, muhur_id = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge(['MUHURLENDI', $muhurId], $ids));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapRow(array $row)
    {
        return [
            'personel_id' => (int) $row['personel_id'],
            'tarih' => (string) $row['tarih'],
            'gun_tipi' => $row['gun_tipi'],
            'hareket_durumu' => $row['hareket_durumu'],
            'dayanak' => $row['dayanak'],
            'durumu_bildirdi_mi' => self::mapNullableBool($row['durumu_bildirdi_mi'] ?? null),
            'durum_bildirim_aciklamasi' => $row['durum_bildirim_aciklamasi'] ?? null,
            'hesap_etkisi' => $row['hesap_etkisi'],
            'sgk_eksik_gun_neden_tipi' => $row['sgk_eksik_gun_neden_tipi'] ?? null,
            'beklenen_giris_saati' => $row['beklenen_giris_saati'] ?? null,
            'beklenen_cikis_saati' => $row['beklenen_cikis_saati'] ?? null,
            'giris_saati' => $row['giris_saati'],
            'cikis_saati' => $row['cikis_saati'],
            'gec_kalma_dakika' => self::mapNullableInt($row['gec_kalma_dakika'] ?? null),
            'erken_cikis_dakika' => self::mapNullableInt($row['erken_cikis_dakika'] ?? null),
            'gercek_mola_dakika' => self::mapNullableInt($row['gercek_mola_dakika'] ?? null),
            'hesaplanan_mola_dakika' => self::mapNullableInt($row['hesaplanan_mola_dakika'] ?? null),
            'net_calisma_suresi_dakika' => self::mapNullableInt($row['net_calisma_suresi_dakika'] ?? null),
            'gunluk_brut_sure_dakika' => self::mapNullableInt($row['gunluk_brut_sure_dakika'] ?? null),
            'hafta_tatili_hak_kazandi_mi' => self::mapNullableBool($row['hafta_tatili_hak_kazandi_mi'] ?? null),
            'state' => $row['state'] ?? 'ACIK',
            'kontrol_durumu' => $row['kontrol_durumu'] ?: 'BEKLIYOR',
            'compliance_uyarilari' => [],
            'tatil_takvim_id' => isset($row['tatil_takvim_id']) && $row['tatil_takvim_id'] !== null
                ? (int) $row['tatil_takvim_id'] : null,
            'tatil_turu' => $row['tatil_turu'] ?? null,
            'tatil_gun_kapsami' => $row['tatil_gun_kapsami'] ?? null,
            'tatil_interval_baslangic' => $row['tatil_interval_baslangic'] ?? null,
            'tatil_interval_bitis' => $row['tatil_interval_bitis'] ?? null,
            'tatil_siniflandirma_durumu' => $row['tatil_siniflandirma_durumu'] ?? null,
            'tatil_snapshot_hash' => $row['tatil_snapshot_hash'] ?? null,
            'tatil_kaynak_referansi' => $row['tatil_kaynak_referansi'] ?? null,
            'tatil_donemi_brut_calisma_dakika' => self::mapNullableInt($row['tatil_donemi_brut_calisma_dakika'] ?? null),
            'tatil_donemi_ara_dinlenme_dakika' => self::mapNullableInt($row['tatil_donemi_ara_dinlenme_dakika'] ?? null),
            'tatil_donemi_net_calisma_dakika' => self::mapNullableInt($row['tatil_donemi_net_calisma_dakika'] ?? null),
        ];
    }

    private static function mapNullableInt($value)
    {
        return $value === null ? null : (int) $value;
    }

    private static function mapNullableBool($value)
    {
        if ($value === null) {
            return null;
        }

        return (int) $value === 1;
    }
}
