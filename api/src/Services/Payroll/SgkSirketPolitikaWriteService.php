<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;
use PDOException;

/**
 * S98: Company SGK policy draft import / submit / approve (dual-control, overlap guard).
 */
final class SgkSirketPolitikaWriteService
{
    public const CONFIRMATION_TEXT = 'SGK_POLITIKA_DRAFT_ONAY';

    /**
     * @param array{id?: int, rol?: string} $actor
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function import(PDO $pdo, array $actor, array $payload): array
    {
        self::assertPrepare($pdo, $actor);

        if ((string) ($payload['confirmation_text'] ?? '') !== self::CONFIRMATION_TEXT) {
            return self::result(400, 'SGK_POLITIKA_ONAY_METNI_GECERSIZ', 'confirmation_text SGK_POLITIKA_DRAFT_ONAY olmalidir.');
        }

        $expectedHash = (string) ($payload['politika_hash'] ?? '');
        $dry = SgkSirketPolitikaImportValidator::dryRun($pdo, $payload);
        if (($dry['hatali_satirlar'] ?? []) !== []) {
            return self::result(400, 'SGK_POLITIKA_IMPORT_GECERSIZ', 'Politika dry-run hatali.', ['dry_run' => $dry]);
        }
        if (empty($dry['import_yapilabilir_mi'])) {
            return self::result(400, 'SGK_POLITIKA_IMPORT_HAZIR_DEGIL', 'Politika paketi import icin hazir degil.', ['dry_run' => $dry]);
        }
        if ($expectedHash === '' || !hash_equals($expectedHash, (string) ($dry['politika_hash'] ?? ''))) {
            return self::result(409, 'SGK_POLITIKA_HASH_UYUSMAZligi', 'politika_hash dry-run ile eslesmiyor.', ['dry_run' => $dry]);
        }

        $canonical = $dry['canonical_payload'] ?? [];
        $subeId = (int) ($canonical['sube_id'] ?? 0);
        SgkKararPaketiAuthz::assertSubeScope($actor, $subeId);
        $surumKodu = (string) ($canonical['surum_kodu'] ?? '');
        $politikaHash = (string) ($dry['politika_hash'] ?? '');
        $actorId = (int) ($actor['id'] ?? 0);

        $existing = self::fetchSurum($pdo, $subeId, $surumKodu);
        if ($existing !== null && (string) ($existing['state'] ?? '') === 'ONAYLANDI') {
            if ((string) ($existing['politika_hash'] ?? '') === $politikaHash) {
                return self::result(200, 'SGK_POLITIKA_IMPORT_IDEMPOTENT', 'Ayni hash ile onayli politika zaten mevcut.', [
                    'surum_id' => (int) $existing['id'],
                    'surum_kodu' => $surumKodu,
                    'state' => 'ONAYLANDI',
                    'politika_hash' => $politikaHash,
                    'idempotent_mi' => true,
                ]);
            }

            return self::result(409, 'SGK_POLITIKA_ONAYLI_DEGISTIRILEMEZ', 'Onayli politika surumu degistirilemez.');
        }

        if ($existing !== null
            && (string) ($existing['state'] ?? '') === 'TASLAK'
            && (string) ($existing['politika_hash'] ?? '') === $politikaHash) {
            return self::result(200, 'SGK_POLITIKA_IMPORT_IDEMPOTENT', 'Ayni TASLAK politika zaten mevcut.', [
                'surum_id' => (int) $existing['id'],
                'surum_kodu' => $surumKodu,
                'state' => 'TASLAK',
                'politika_hash' => $politikaHash,
                'idempotent_mi' => true,
            ]);
        }

        try {
            $pdo->beginTransaction();

            if ($existing === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO sgk_sirket_politika_surumleri
                     (sube_id, surum_kodu, gecerlilik_baslangic, gecerlilik_bitis, bildirim_donem_tipi,
                      state, politika_hash, aciklama, hazirlayan_id)
                     VALUES
                     (:sube_id, :surum_kodu, :gecerlilik_baslangic, :gecerlilik_bitis, :bildirim_donem_tipi,
                      :state, :politika_hash, :aciklama, :hazirlayan_id)'
                );
                $insert->execute([
                    'sube_id' => $subeId,
                    'surum_kodu' => $surumKodu,
                    'gecerlilik_baslangic' => $canonical['gecerlilik_baslangic'],
                    'gecerlilik_bitis' => $canonical['gecerlilik_bitis'],
                    'bildirim_donem_tipi' => $canonical['bildirim_donem_tipi'],
                    'state' => 'TASLAK',
                    'politika_hash' => $politikaHash,
                    'aciklama' => (string) ($canonical['aciklama'] ?? ''),
                    'hazirlayan_id' => $actorId > 0 ? $actorId : null,
                ]);
                $surumId = (int) $pdo->lastInsertId();
            } else {
                $surumId = (int) $existing['id'];
                $pdo->prepare(
                    'UPDATE sgk_sirket_politika_surumleri
                     SET gecerlilik_baslangic = :bas, gecerlilik_bitis = :bit, bildirim_donem_tipi = :donem,
                         state = :state, politika_hash = :hash, aciklama = :aciklama, hazirlayan_id = :hazirlayan,
                         onaylayan_id = NULL, onay_zamani = NULL
                     WHERE id = :id'
                )->execute([
                    'bas' => $canonical['gecerlilik_baslangic'],
                    'bit' => $canonical['gecerlilik_bitis'],
                    'donem' => $canonical['bildirim_donem_tipi'],
                    'state' => 'TASLAK',
                    'hash' => $politikaHash,
                    'aciklama' => (string) ($canonical['aciklama'] ?? ''),
                    'hazirlayan_id' => $actorId > 0 ? $actorId : null,
                    'id' => $surumId,
                ]);
            }

            $pdo->prepare('DELETE FROM sgk_sirket_politika_degerleri WHERE politika_surum_id = :id')
                ->execute(['id' => $surumId]);
            self::insertDegerler($pdo, $surumId, (array) ($canonical['degerler'] ?? []));

            $pdo->commit();

            return self::result(200, 'SGK_POLITIKA_IMPORT_OK', 'Sirket SGK politikasi TASLAK olarak kaydedildi.', [
                'surum_id' => $surumId,
                'surum_kodu' => $surumKodu,
                'sube_id' => $subeId,
                'state' => 'TASLAK',
                'politika_hash' => $politikaHash,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::result(500, 'SGK_POLITIKA_IMPORT_HATASI', 'Politika import transaction basarisiz.');
        }
    }

    /**
     * @param array{id?: int, rol?: string} $actor
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function submit(PDO $pdo, array $actor, array $payload): array
    {
        self::assertPrepare($pdo, $actor);

        $surum = self::resolveSurum($pdo, $payload);
        if ($surum === null) {
            return self::result(404, 'SGK_POLITIKA_SURUM_BULUNAMADI', 'Politika surumu bulunamadi.');
        }
        SgkKararPaketiAuthz::assertSubeScope($actor, (int) ($surum['sube_id'] ?? 0));
        if ((string) ($surum['state'] ?? '') !== 'TASLAK') {
            return self::result(400, 'SGK_POLITIKA_SUBMIT_STATE', 'Submit yalniz TASLAK uzerinden.');
        }

        $expectedHash = (string) ($payload['politika_hash'] ?? '');
        $storedHash = (string) ($surum['politika_hash'] ?? '');
        if ($expectedHash !== '' && $storedHash !== '' && !hash_equals($storedHash, $expectedHash)) {
            return self::result(409, 'SGK_POLITIKA_HASH_KILIDI', 'politika_hash kayitli surum ile eslesmiyor.');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE sgk_sirket_politika_surumleri SET state = 'ONAY_BEKLIYOR' WHERE id = :id AND state = 'TASLAK'"
            )->execute(['id' => (int) $surum['id']]);
            if ($pdo->prepare("SELECT state FROM sgk_sirket_politika_surumleri WHERE id = :id")->execute(['id' => (int) $surum['id']]) === false) {
                // no-op
            }
            $check = $pdo->prepare('SELECT state FROM sgk_sirket_politika_surumleri WHERE id = :id');
            $check->execute(['id' => (int) $surum['id']]);
            $state = (string) $check->fetchColumn();
            if ($state !== 'ONAY_BEKLIYOR') {
                $pdo->rollBack();

                return self::result(409, 'SGK_POLITIKA_SUBMIT_RACE', 'Submit state guncellenemedi.');
            }
            $pdo->commit();

            return self::result(200, 'SGK_POLITIKA_SUBMIT_OK', 'Politika onay bekliyor.', [
                'surum_id' => (int) $surum['id'],
                'surum_kodu' => (string) ($surum['surum_kodu'] ?? ''),
                'state' => 'ONAY_BEKLIYOR',
                'politika_hash' => $storedHash,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::result(500, 'SGK_POLITIKA_SUBMIT_HATASI', 'Politika submit basarisiz.');
        }
    }

    /**
     * @param array{id?: int, rol?: string} $actor
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function approve(PDO $pdo, array $actor, array $payload): array
    {
        self::assertApprove($pdo, $actor);

        $surum = self::resolveSurum($pdo, $payload);
        if ($surum === null) {
            return self::result(404, 'SGK_POLITIKA_SURUM_BULUNAMADI', 'Politika surumu bulunamadi.');
        }
        SgkKararPaketiAuthz::assertSubeScope($actor, (int) ($surum['sube_id'] ?? 0));
        if ((string) ($surum['state'] ?? '') !== 'ONAY_BEKLIYOR') {
            return self::result(400, 'SGK_POLITIKA_APPROVE_STATE', 'Approve yalniz ONAY_BEKLIYOR uzerinden.');
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $hazirlayanId = (int) ($surum['hazirlayan_id'] ?? 0);
        $self = SgkKararPaketiAuthz::denySelfApproval($actor, $hazirlayanId);
        if (empty($self['ok'])) {
            return self::result(403, (string) $self['code'], (string) $self['message']);
        }
        $samePerson = SgkKararPaketiAuthz::denySamePerson($pdo, $actor, $hazirlayanId);
        if (empty($samePerson['ok'])) {
            return self::result(403, (string) $samePerson['code'], (string) $samePerson['message']);
        }

        $expectedHash = (string) ($payload['politika_hash'] ?? '');
        $storedHash = (string) ($surum['politika_hash'] ?? '');
        if ($expectedHash !== '' && $storedHash !== '' && !hash_equals($storedHash, $expectedHash)) {
            return self::result(409, 'SGK_POLITIKA_HASH_KILIDI', 'politika_hash kayitli surum ile eslesmiyor.');
        }

        $subeId = (int) ($surum['sube_id'] ?? 0);
        $bas = (string) ($surum['gecerlilik_baslangic'] ?? '');
        $bit = $surum['gecerlilik_bitis'] ?? null;
        if (SgkSirketPolitikaImportValidator::dryRun($pdo, [
            'sube_id' => $subeId,
            'surum_kodu' => (string) ($surum['surum_kodu'] ?? ''),
            'gecerlilik_baslangic' => $bas,
            'gecerlilik_bitis' => $bit,
            'bildirim_donem_tipi' => (string) ($surum['bildirim_donem_tipi'] ?? ''),
            'degerler' => self::loadDegerler($pdo, (int) $surum['id']),
        ])['overlap_var_mi'] ?? false) {
            return self::result(409, 'SGK_POLITIKA_TARIH_CAKISMA', 'Onay oncesi tarih cakismasi tespit edildi.');
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "UPDATE sgk_sirket_politika_surumleri
                 SET state = 'ONAYLANDI', onaylayan_id = :onaylayan, onay_zamani = UTC_TIMESTAMP()
                 WHERE id = :id AND state = 'ONAY_BEKLIYOR'"
            );
            $stmt->execute([
                'onaylayan' => $actorId > 0 ? $actorId : null,
                'id' => (int) $surum['id'],
            ]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();

                return self::result(409, 'SGK_POLITIKA_APPROVE_RACE', 'Politika baska bir islem tarafindan guncellendi.');
            }
            $pdo->commit();

            return self::result(200, 'SGK_POLITIKA_APPROVE_OK', 'Sirket SGK politikasi onaylandi.', [
                'surum_id' => (int) $surum['id'],
                'surum_kodu' => (string) ($surum['surum_kodu'] ?? ''),
                'state' => 'ONAYLANDI',
                'politika_hash' => $storedHash,
                'onaylayan_id' => $actorId > 0 ? $actorId : null,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return self::result(500, 'SGK_POLITIKA_APPROVE_HATASI', 'Politika approve basarisiz.');
        }
    }

    /**
     * @param array{id?: int, rol?: string, username?: string, durum?: string, sube_ids?: list<int>} $actor
     */
    private static function assertPrepare(PDO $pdo, array $actor): void
    {
        SgkKararPaketiAuthz::assertPrepare($pdo, $actor);
    }

    /**
     * @param array{id?: int, rol?: string, username?: string, durum?: string, sube_ids?: list<int>, actor_identity_id?: int|null, actor_identity_status?: string|null} $actor
     */
    private static function assertApprove(PDO $pdo, array $actor): void
    {
        SgkKararPaketiAuthz::assertApprove($pdo, $actor);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    private static function resolveSurum(PDO $pdo, array $payload): ?array
    {
        if (isset($payload['surum_id']) && (int) $payload['surum_id'] > 0) {
            $stmt = $pdo->prepare('SELECT * FROM sgk_sirket_politika_surumleri WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $payload['surum_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        }
        $subeId = (int) ($payload['sube_id'] ?? 0);
        $kodu = trim((string) ($payload['surum_kodu'] ?? ''));
        if ($subeId <= 0 || $kodu === '') {
            return null;
        }

        return self::fetchSurum($pdo, $subeId, $kodu);
    }

    /** @return array<string,mixed>|null */
    private static function fetchSurum(PDO $pdo, int $subeId, string $surumKodu): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM sgk_sirket_politika_surumleri WHERE sube_id = :sube AND surum_kodu = :kodu LIMIT 1'
        );
        $stmt->execute(['sube' => $subeId, 'kodu' => $surumKodu]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, string> */
    private static function loadDegerler(PDO $pdo, int $surumId): array
    {
        $stmt = $pdo->prepare('SELECT politika_kodu, deger FROM sgk_sirket_politika_degerleri WHERE politika_surum_id = :id');
        $stmt->execute(['id' => $surumId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['politika_kodu']] = (string) $row['deger'];
        }

        return $out;
    }

    /** @param array<string, string> $degerler */
    private static function insertDegerler(PDO $pdo, int $surumId, array $degerler): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO sgk_sirket_politika_degerleri (politika_surum_id, politika_kodu, deger_turu, deger)
             VALUES (:surum_id, :kod, :tur, :deger)'
        );
        foreach ($degerler as $code => $value) {
            $def = SgkSirketPolitikaCatalog::definition((string) $code);
            if ($def === null) {
                continue;
            }
            $stmt->execute([
                'surum_id' => $surumId,
                'kod' => (string) $code,
                'tur' => (string) ($def['deger_turu'] ?? 'METIN'),
                'deger' => (string) $value,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function result(int $httpStatus, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'http_status' => $httpStatus,
            'code' => $code,
            'message' => $message,
            'ok' => $httpStatus >= 200 && $httpStatus < 300,
        ], $extra);
    }
}
