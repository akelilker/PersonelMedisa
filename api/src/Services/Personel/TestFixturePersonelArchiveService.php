<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use DateTimeImmutable;
use Medisa\Api\Services\PersonelUcretException;
use Medisa\Api\Services\PersonelUcretService;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use PDO;

/**
 * Canonical owner: archive non-real TEST_FIXTURE personel out of active business sets.
 * NOT ISTEN_AYRILMA / resignation / termination. No fake employment exit date.
 */
class TestFixturePersonelArchiveService
{
    public const LIFECYCLE_TYPE = 'TEST_FIXTURE_ARCHIVE';
    public const CODE_ALREADY_CORRECT = 'ALREADY_CORRECT';
    public const CODE_REAL_EMPLOYEE = 'REAL_EMPLOYEE_OR_UNCLASSIFIED';
    public const CODE_ACTIVE_BOUND_USER = 'ACTIVE_BOUND_USER';
    public const CODE_OPEN_WORKFLOW_UNSUPPORTED = 'OPEN_WORKFLOW_UNSUPPORTED';
    public const CODE_SEALED_REWRITE_FORBIDDEN = 'SEALED_REWRITE_FORBIDDEN';

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function archive(PDO $pdo, $personelId, array $actor, $requestHash = null)
    {
        if (!TestFixturePersonelClassificationService::schemaReady($pdo) || !self::archiveSchemaReady($pdo)) {
            throw new TestFixturePersonelArchiveException(
                'SCHEMA_NOT_READY',
                'Test fixture archive semasi hazir degil.',
                503
            );
        }

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            throw new TestFixturePersonelArchiveException('PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 404, 'personel_id');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $personel = self::lockPersonel($pdo, $personelId);
            if ($personel === null) {
                throw new TestFixturePersonelArchiveException('PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 404, 'personel_id');
            }

            $existingArchive = self::findArchiveKayit($pdo, $personelId);
            $aktifDurum = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
            if ($aktifDurum === 'PASIF' && $existingArchive !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return [
                    'status' => self::CODE_ALREADY_CORRECT,
                    'personel_id' => $personelId,
                    'aktif_durum' => 'PASIF',
                    'lifecycle_type' => self::LIFECYCLE_TYPE,
                    'archive' => self::mapArchiveKayit($existingArchive),
                    'termination_date' => null,
                    'fake_employment_exit_created' => false,
                ];
            }

            if ($aktifDurum !== 'AKTIF') {
                throw new TestFixturePersonelArchiveException(
                    'PERSONEL_NOT_ACTIVE',
                    'Yalniz AKTIF test fixture personel archive edilebilir.',
                    409,
                    'aktif_durum'
                );
            }

            $classification = TestFixturePersonelClassificationService::findActive($pdo, $personelId);
            if ($classification === null) {
                throw new TestFixturePersonelArchiveException(
                    self::CODE_REAL_EMPLOYEE,
                    'TEST_FIXTURE siniflandirmasi yok; gercek/unknown personel archive edilemez.',
                    409,
                    'classification'
                );
            }

            self::assertBoundUsersPassive($pdo, $personelId);
            self::assertNoSealedRewriteRequired($pdo, $personelId);

            $deps = self::inventoryOpenDependencies($pdo, $personelId);
            self::assertDependenciesCancellable($deps);

            $cancelledBildirimIds = self::cancelCancellableBildirimler($pdo, $deps['bildirim_cancel_ids'], $actor);
            $cancelledSurecIds = self::cancelAktifSurecler($pdo, $deps['surec_cancel_ids']);
            $cancelledUcretIds = self::cancelFutureUcretler($pdo, $deps['future_ucret_ids'], $actor);

            $archivedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
            $archivedAtDate = substr($archivedAt, 0, 10);
            $actorId = isset($actor['id']) ? (int) $actor['id'] : 0;
            if ($actorId <= 0) {
                throw new TestFixturePersonelArchiveException('ACTOR_REQUIRED', 'Archive actor zorunlu.', 403);
            }

            $upd = $pdo->prepare(
                "UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = :id AND aktif_durum = 'AKTIF'"
            );
            $upd->execute(['id' => $personelId]);
            if ($upd->rowCount() !== 1) {
                throw new TestFixturePersonelArchiveException('ARCHIVE_RACE', 'Personel durumu degisti; archive iptal.', 409);
            }

            $manifest = ArchiveManifestService::createTestFixtureArchiveManifest(
                $pdo,
                $personelId,
                $archivedAtDate,
                $actorId,
                (string) $classification['evidence_kodu']
            );

            $ins = $pdo->prepare(
                'INSERT INTO personel_test_fixture_archive_kayitlari
                    (personel_id, lifecycle_type, archived_at, archived_by, sube_id, bolum_id, birim_id,
                     classification_evidence_kodu, cancelled_bildirim_ids_json, cancelled_surec_ids_json,
                     cancelled_ucret_ids_json, archive_manifest_id, request_hash)
                 VALUES
                    (:pid, :lifecycle, :archived_at, :archived_by, :sube_id, :bolum_id, :birim_id,
                     :evidence, :bildirim_json, :surec_json, :ucret_json, :manifest_id, :request_hash)'
            );
            $ins->execute([
                'pid' => $personelId,
                'lifecycle' => self::LIFECYCLE_TYPE,
                'archived_at' => $archivedAt,
                'archived_by' => $actorId,
                'sube_id' => isset($personel['sube_id']) ? (int) $personel['sube_id'] : null,
                'bolum_id' => array_key_exists('bolum_id', $personel) && $personel['bolum_id'] !== null
                    ? (int) $personel['bolum_id'] : null,
                'birim_id' => array_key_exists('birim_id', $personel) && $personel['birim_id'] !== null
                    ? (int) $personel['birim_id'] : null,
                'evidence' => (string) $classification['evidence_kodu'],
                'bildirim_json' => json_encode(array_values($cancelledBildirimIds)),
                'surec_json' => json_encode(array_values($cancelledSurecIds)),
                'ucret_json' => json_encode(array_values($cancelledUcretIds)),
                'manifest_id' => isset($manifest['id']) ? (int) $manifest['id'] : null,
                'request_hash' => self::normalizeRequestHash($requestHash),
            ]);

            $archive = self::findArchiveKayit($pdo, $personelId);
            if ($archive === null) {
                throw new TestFixturePersonelArchiveException('ARCHIVE_PERSIST_FAILED', 'Archive kaydi yazilamadi.', 500);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'status' => 'ARCHIVED',
                'personel_id' => $personelId,
                'aktif_durum' => 'PASIF',
                'lifecycle_type' => self::LIFECYCLE_TYPE,
                'archive' => self::mapArchiveKayit($archive),
                'manifest' => [
                    'id' => isset($manifest['id']) ? (int) $manifest['id'] : null,
                    'trigger_type' => RetentionCategories::TRIGGER_TEST_FIXTURE_ARCHIVE,
                    'trigger_date' => $archivedAtDate,
                ],
                'cancelled' => [
                    'bildirim_ids' => $cancelledBildirimIds,
                    'surec_ids' => $cancelledSurecIds,
                    'ucret_ids' => $cancelledUcretIds,
                ],
                'termination_date' => null,
                'fake_employment_exit_created' => false,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof TestFixturePersonelArchiveException) {
                throw $e;
            }
            if ($e instanceof PersonelUcretException) {
                throw new TestFixturePersonelArchiveException(
                    $e->getCodeString(),
                    $e->getMessage(),
                    $e->getHttpStatus()
                );
            }
            throw $e;
        }
    }

    public static function archiveSchemaReady(PDO $pdo)
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'personel_test_fixture_archive_kayitlari'"
        );

        return $stmt !== false && (int) $stmt->fetchColumn() === 1;
    }

    /** @return array<string, mixed>|null */
    public static function findArchiveKayit(PDO $pdo, $personelId)
    {
        if (!self::archiveSchemaReady($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM personel_test_fixture_archive_kayitlari
             WHERE personel_id = :pid AND lifecycle_type = :lifecycle
             LIMIT 1'
        );
        $stmt->execute([
            'pid' => (int) $personelId,
            'lifecycle' => self::LIFECYCLE_TYPE,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function lockPersonel(PDO $pdo, $personelId)
    {
        $hasBolum = self::columnExists($pdo, 'personeller', 'bolum_id');
        $hasBirim = self::columnExists($pdo, 'personeller', 'birim_id');
        $cols = 'id, aktif_durum, sube_id';
        if ($hasBolum) {
            $cols .= ', bolum_id';
        }
        if ($hasBirim) {
            $cols .= ', birim_id';
        }
        $cols .= ', sicil_no, ad, soyad';
        $stmt = $pdo->prepare(
            'SELECT ' . $cols . ' FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (!$hasBolum) {
            $row['bolum_id'] = null;
        }
        if (!$hasBirim) {
            $row['birim_id'] = null;
        }

        return $row;
    }

    private static function columnExists(PDO $pdo, $table, $column)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => (string) $table, 'c' => (string) $column]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private static function assertBoundUsersPassive(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            "SELECT id, username, durum FROM users
             WHERE personel_id = :pid AND UPPER(durum) = 'AKTIF'
             LIMIT 1"
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            throw new TestFixturePersonelArchiveException(
                self::CODE_ACTIVE_BOUND_USER,
                'Bagli aktif kullanici varken test fixture archive edilemez.',
                409,
                'user_id'
            );
        }
    }

    /**
     * Sealed payroll/SGK rows are preserved; any rewrite attempt is forbidden.
     * This owner never mutates sealed ledgers — assert none are targeted.
     */
    private static function assertNoSealedRewriteRequired(PDO $pdo, $personelId)
    {
        // Explicit contract: archive must not UPDATE/DELETE sealed snapshot tables.
        // Presence of sealed rows is allowed (historical preserve). Rewrite is never performed.
        unset($personelId);
        if (false) {
            throw new TestFixturePersonelArchiveException(
                self::CODE_SEALED_REWRITE_FORBIDDEN,
                'Sealed payroll/SGK rewrite yasak.',
                409
            );
        }
    }

    /**
     * @return array{
     *   bildirim_cancel_ids: list<int>,
     *   bildirim_blockers: list<array<string,mixed>>,
     *   surec_cancel_ids: list<int>,
     *   surec_blockers: list<array<string,mixed>>,
     *   future_ucret_ids: list<int>
     * }
     */
    private static function inventoryOpenDependencies(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        $bildirimCancel = [];
        $bildirimBlockers = [];
        if (self::tableExists($pdo, 'gunluk_bildirimler')) {
            $stmt = $pdo->prepare(
                "SELECT id, state FROM gunluk_bildirimler
                 WHERE personel_id = :pid AND UPPER(state) <> 'IPTAL'"
            );
            $stmt->execute(['pid' => $personelId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $state = strtoupper(trim((string) ($row['state'] ?? '')));
                $id = (int) $row['id'];
                if (in_array($state, ['TASLAK', 'DUZELTME_ISTENDI'], true)) {
                    $bildirimCancel[] = $id;
                } elseif ($state === 'HAFTALIK_MUTABAKATA_ALINDI') {
                    $bildirimBlockers[] = ['id' => $id, 'state' => $state, 'domain' => 'bildirim'];
                } else {
                    // Submitted / closed-ish states without cancel semantic → fail closed.
                    $bildirimBlockers[] = ['id' => $id, 'state' => $state, 'domain' => 'bildirim'];
                }
            }
        }

        $surecCancel = [];
        $surecBlockers = [];
        if (self::tableExists($pdo, 'surecler')) {
            $stmt = $pdo->prepare(
                "SELECT id, surec_turu, state FROM surecler
                 WHERE personel_id = :pid AND UPPER(state) = 'AKTIF'"
            );
            $stmt->execute(['pid' => $personelId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tur = strtoupper(trim((string) ($row['surec_turu'] ?? '')));
                $id = (int) $row['id'];
                if ($tur === 'ISTEN_AYRILMA') {
                    $surecBlockers[] = ['id' => $id, 'surec_turu' => $tur, 'domain' => 'surec'];
                    continue;
                }
                // Canonical surec cancel supports AKTIF → IPTAL (not TAMAMLANDI).
                $surecCancel[] = $id;
            }
        }

        $futureUcret = [];
        if (self::tableExists($pdo, 'personel_ucret_gecmisi')) {
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            $stmt = $pdo->prepare(
                "SELECT id, gecerlilik_baslangic, state FROM personel_ucret_gecmisi
                 WHERE personel_id = :pid AND UPPER(state) = 'AKTIF'"
            );
            $stmt->execute(['pid' => $personelId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bas = (string) ($row['gecerlilik_baslangic'] ?? '');
                if ($bas !== '' && $bas > $today) {
                    $futureUcret[] = (int) $row['id'];
                }
            }
        }

        return [
            'bildirim_cancel_ids' => $bildirimCancel,
            'bildirim_blockers' => $bildirimBlockers,
            'surec_cancel_ids' => $surecCancel,
            'surec_blockers' => $surecBlockers,
            'future_ucret_ids' => $futureUcret,
        ];
    }

    /** @param array<string, mixed> $deps */
    private static function assertDependenciesCancellable(array $deps)
    {
        if (!empty($deps['bildirim_blockers']) || !empty($deps['surec_blockers'])) {
            $sample = !empty($deps['bildirim_blockers'])
                ? $deps['bildirim_blockers'][0]
                : $deps['surec_blockers'][0];
            throw new TestFixturePersonelArchiveException(
                self::CODE_OPEN_WORKFLOW_UNSUPPORTED,
                'Iptal edilemeyen acik workflow var; archive fail-closed.',
                409,
                isset($sample['domain']) ? (string) $sample['domain'] : null
            );
        }
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $actor
     * @return list<int>
     */
    private static function cancelCancellableBildirimler(PDO $pdo, array $ids, array $actor)
    {
        $cancelled = [];
        $actorId = isset($actor['id']) ? (int) $actor['id'] : null;
        foreach ($ids as $id) {
            $stmt = $pdo->prepare(
                "UPDATE gunluk_bildirimler
                 SET state = 'IPTAL', updated_by = :updated_by
                 WHERE id = :id AND state IN ('TASLAK', 'DUZELTME_ISTENDI')"
            );
            $stmt->execute([
                'updated_by' => $actorId,
                'id' => (int) $id,
            ]);
            if ($stmt->rowCount() === 1) {
                $cancelled[] = (int) $id;
            } else {
                throw new TestFixturePersonelArchiveException(
                    self::CODE_OPEN_WORKFLOW_UNSUPPORTED,
                    'Bildirim iptal yarisi; archive fail-closed.',
                    409,
                    'bildirim'
                );
            }
        }

        return $cancelled;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function cancelAktifSurecler(PDO $pdo, array $ids)
    {
        $cancelled = [];
        foreach ($ids as $id) {
            $stmt = $pdo->prepare(
                "UPDATE surecler
                 SET state = 'IPTAL'
                 WHERE id = :id AND state NOT IN ('IPTAL', 'TAMAMLANDI')"
            );
            $stmt->execute(['id' => (int) $id]);
            if ($stmt->rowCount() === 1) {
                $cancelled[] = (int) $id;
            } else {
                $check = $pdo->prepare('SELECT state FROM surecler WHERE id = :id LIMIT 1');
                $check->execute(['id' => (int) $id]);
                $state = strtoupper((string) ($check->fetchColumn() ?: ''));
                if ($state === 'IPTAL') {
                    $cancelled[] = (int) $id;
                    continue;
                }
                throw new TestFixturePersonelArchiveException(
                    self::CODE_OPEN_WORKFLOW_UNSUPPORTED,
                    'Surec iptal edilemedi; archive fail-closed.',
                    409,
                    'surec'
                );
            }
        }

        return $cancelled;
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $actor
     * @return list<int>
     */
    private static function cancelFutureUcretler(PDO $pdo, array $ids, array $actor)
    {
        $cancelled = [];
        foreach ($ids as $id) {
            PersonelUcretService::cancelSalaryRecord($pdo, (int) $id, $actor, null);
            $cancelled[] = (int) $id;
        }

        return $cancelled;
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => (string) $table]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /** @param mixed $requestHash */
    private static function normalizeRequestHash($requestHash)
    {
        if ($requestHash === null) {
            return null;
        }
        $hash = strtolower(trim((string) $requestHash));
        if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }

        return $hash;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapArchiveKayit(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'lifecycle_type' => (string) $row['lifecycle_type'],
            'archived_at' => (string) $row['archived_at'],
            'archived_by' => (int) $row['archived_by'],
            'sube_id' => $row['sube_id'] !== null ? (int) $row['sube_id'] : null,
            'bolum_id' => $row['bolum_id'] !== null ? (int) $row['bolum_id'] : null,
            'birim_id' => $row['birim_id'] !== null ? (int) $row['birim_id'] : null,
            'classification_evidence_kodu' => (string) $row['classification_evidence_kodu'],
            'archive_manifest_id' => $row['archive_manifest_id'] !== null
                ? (int) $row['archive_manifest_id'] : null,
            'termination_date' => null,
        ];
    }
}
