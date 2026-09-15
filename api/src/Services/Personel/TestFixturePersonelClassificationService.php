<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Canonical persisted classification for non-real test/demo personel.
 * Not inferred from sicil/name patterns. Classification is persisted evidence only.
 * Generic boolean test flags are not used.
 */
class TestFixturePersonelClassificationService
{
    public const SINIF_TEST_FIXTURE = 'TEST_FIXTURE';
    public const CODE_ALREADY_CORRECT = 'ALREADY_CORRECT';

    /** Machine-verifiable evidence accepted for HTTP classification. */
    public const EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI = 'BORDRO_KAPSAM_DEMO_TEST_VERISI';

    /**
     * Legacy/internal codes — not accepted on HTTP without machine-verifiable proof.
     * Kept as constants for audit readability; HTTP path DENY.
     */
    public const EVIDENCE_LIVE_API_SMOKE_CREATE = 'LIVE_API_SMOKE_CREATE';
    public const EVIDENCE_SEED_SCHEMA_FIXTURE = 'SEED_SCHEMA_FIXTURE';
    public const EVIDENCE_MANUAL_OPS_CLASSIFIED = 'MANUAL_OPS_CLASSIFIED';

    /**
     * Evidence codes accepted by the HTTP classification owner.
     *
     * @return array<int, string>
     */
    public static function allowedHttpEvidenceKodlari()
    {
        return [
            self::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI,
        ];
    }

    public static function schemaReady(PDO $pdo)
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'personel_test_fixture_siniflandirmalari'"
        );

        return $stmt !== false && (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findActive(PDO $pdo, $personelId)
    {
        if (!self::schemaReady($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM personel_test_fixture_siniflandirmalari
             WHERE personel_id = :pid AND state = 'AKTIF' AND sinif = :sinif
             LIMIT 1"
        );
        $stmt->execute([
            'pid' => (int) $personelId,
            'sinif' => self::SINIF_TEST_FIXTURE,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function isActiveTestFixture(PDO $pdo, $personelId)
    {
        return self::findActive($pdo, $personelId) !== null;
    }

    /**
     * Canonical operational (user-facing) visibility exclusion predicate.
     *
     * A personel carrying an AKTIF TEST_FIXTURE classification is not a real employee, so it must
     * never be returned by an operational surface: aktif/pasif lists, archive/personel search,
     * counts and filter results, selection pools, normal detail access, exports and daily rosters.
     *
     * Retention/audit owners (retention imha, archive manifests, classification owner itself) read
     * the personel row directly and are deliberately NOT filtered by this predicate, so historical
     * payroll snapshots and sealed puantaj evidence keep resolving `personeller.id`.
     *
     * Returns null when the classification schema is absent, so a non-migrated environment never
     * breaks its read queries (classification evidence simply does not exist there yet).
     *
     * @param mixed $alias
     * @return string|null
     */
    public static function sqlOperationalVisibilityExclusion(PDO $pdo, $alias = 'p')
    {
        if (!self::schemaReady($pdo)) {
            return null;
        }
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias);
        if (!is_string($safe) || $safe === '') {
            $safe = 'p';
        }

        return "NOT EXISTS (SELECT 1 FROM personel_test_fixture_siniflandirmalari c"
            . " WHERE c.personel_id = {$safe}.id"
            . " AND c.sinif = '" . self::SINIF_TEST_FIXTURE . "'"
            . " AND c.state = 'AKTIF')";
    }

    /**
     * Single-row equivalent of sqlOperationalVisibilityExclusion for detail surfaces.
     *
     * @param mixed $personelId
     */
    public static function isOperationallyHidden(PDO $pdo, $personelId)
    {
        return self::isActiveTestFixture($pdo, $personelId);
    }

    /**
     * Persist TEST_FIXTURE classification via HTTP-safe evidence contract.
     * Idempotent when same evidence already AKTIF.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function classifyViaHttp(PDO $pdo, $personelId, $evidenceKodu, array $actor, $evidenceRef = null, $aciklama = null)
    {
        $evidenceKodu = strtoupper(trim((string) $evidenceKodu));
        if (!in_array($evidenceKodu, self::allowedHttpEvidenceKodlari(), true)) {
            throw new TestFixturePersonelArchiveException(
                'EVIDENCE_NOT_HTTP_ELIGIBLE',
                'Bu evidence kodu HTTP classification icin kabul edilmez.',
                422,
                'evidence_kodu'
            );
        }

        return self::classify($pdo, $personelId, $evidenceKodu, $actor, $evidenceRef, $aciklama, true);
    }

    /**
     * Persist TEST_FIXTURE classification after machine-verifiable evidence checks.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function classify(
        PDO $pdo,
        $personelId,
        $evidenceKodu,
        array $actor,
        $evidenceRef = null,
        $aciklama = null,
        $httpPath = false
    ) {
        if (!self::schemaReady($pdo)) {
            throw new TestFixturePersonelArchiveException(
                'SCHEMA_NOT_READY',
                'Test fixture siniflandirma semasi hazir degil.',
                503
            );
        }

        $personelId = (int) $personelId;
        $evidenceKodu = strtoupper(trim((string) $evidenceKodu));
        if ($personelId <= 0) {
            throw new TestFixturePersonelArchiveException('PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 404, 'personel_id');
        }

        if ($httpPath && !in_array($evidenceKodu, self::allowedHttpEvidenceKodlari(), true)) {
            throw new TestFixturePersonelArchiveException(
                'EVIDENCE_NOT_HTTP_ELIGIBLE',
                'Bu evidence kodu HTTP classification icin kabul edilmez.',
                422,
                'evidence_kodu'
            );
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

            self::assertEvidenceMachineVerifiable($pdo, $personelId, $evidenceKodu, $evidenceRef);

            $existing = self::findActive($pdo, $personelId);
            if ($existing !== null) {
                if ((string) $existing['evidence_kodu'] === $evidenceKodu) {
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }

                    return array_merge(self::mapClassification($existing), [
                        'status' => self::CODE_ALREADY_CORRECT,
                    ]);
                }
                throw new TestFixturePersonelArchiveException(
                    'CLASSIFICATION_CONFLICT',
                    'Personel zaten farkli evidence ile TEST_FIXTURE siniflandirilmis.',
                    409
                );
            }

            $actorId = isset($actor['id']) ? (int) $actor['id'] : 0;
            $ref = $evidenceRef !== null ? trim((string) $evidenceRef) : null;
            if ($ref === '') {
                $ref = null;
            }
            $note = $aciklama !== null ? trim((string) $aciklama) : null;
            if ($note === '') {
                $note = null;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO personel_test_fixture_siniflandirmalari
                    (personel_id, sinif, evidence_kodu, evidence_ref, state, classified_by, classified_at, aciklama)
                 VALUES
                    (:pid, :sinif, :evidence_kodu, :evidence_ref, \'AKTIF\', :classified_by, NOW(3), :aciklama)'
            );
            $stmt->execute([
                'pid' => $personelId,
                'sinif' => self::SINIF_TEST_FIXTURE,
                'evidence_kodu' => $evidenceKodu,
                'evidence_ref' => $ref,
                'classified_by' => $actorId > 0 ? $actorId : null,
                'aciklama' => $note,
            ]);

            $row = self::findActive($pdo, $personelId);
            if ($row === null) {
                throw new TestFixturePersonelArchiveException('CLASSIFY_FAILED', 'Siniflandirma kaydedilemedi.', 500);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return array_merge(self::mapClassification($row), [
                'status' => 'CLASSIFIED',
            ]);
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fail-closed evidence gate. Unknown codes DENY.
     *
     * @param mixed $evidenceRef
     */
    private static function assertEvidenceMachineVerifiable(PDO $pdo, $personelId, $evidenceKodu, $evidenceRef)
    {
        if ($evidenceKodu === self::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI) {
            self::assertDemoTestKapsamEvidence($pdo, $personelId);

            return;
        }

        // Unverifiable / soft codes are never accepted (HTTP or service).
        if (in_array($evidenceKodu, [
            self::EVIDENCE_LIVE_API_SMOKE_CREATE,
            self::EVIDENCE_SEED_SCHEMA_FIXTURE,
            self::EVIDENCE_MANUAL_OPS_CLASSIFIED,
        ], true)) {
            throw new TestFixturePersonelArchiveException(
                'EVIDENCE_UNVERIFIABLE',
                'Evidence kodu makine-dogrulanabilir degil; classification fail-closed.',
                422,
                'evidence_kodu'
            );
        }

        throw new TestFixturePersonelArchiveException(
            'INVALID_EVIDENCE_KODU',
            'Gecersiz test fixture evidence kodu.',
            422,
            'evidence_kodu'
        );
    }

    private static function assertDemoTestKapsamEvidence(PDO $pdo, $personelId)
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personel_bordro_kapsamlari'"
        );
        if ($stmt === false || (int) $stmt->fetchColumn() !== 1) {
            throw new TestFixturePersonelArchiveException(
                'EVIDENCE_UNAVAILABLE',
                'DEMO_TEST_VERISI kapsam tablosu yok.',
                409,
                'evidence_kodu'
            );
        }
        $q = $pdo->prepare(
            "SELECT id FROM personel_bordro_kapsamlari
             WHERE personel_id = :pid
               AND neden_kodu = 'DEMO_TEST_VERISI'
               AND state = 'ONAYLANDI'
             LIMIT 1"
        );
        $q->execute(['pid' => (int) $personelId]);
        if (!$q->fetch(PDO::FETCH_ASSOC)) {
            throw new TestFixturePersonelArchiveException(
                'EVIDENCE_MISSING',
                'ONAYLANDI DEMO_TEST_VERISI bordro kapsam kaniti yok.',
                409,
                'evidence_kodu'
            );
        }
    }

    /** @return array<string, mixed>|null */
    private static function lockPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapClassification(array $row)
    {
        return [
            'personel_id' => (int) $row['personel_id'],
            'sinif' => (string) $row['sinif'],
            'evidence_kodu' => (string) $row['evidence_kodu'],
            'evidence_ref' => $row['evidence_ref'] !== null ? (string) $row['evidence_ref'] : null,
            'state' => (string) $row['state'],
            'classified_by' => $row['classified_by'] !== null ? (int) $row['classified_by'] : null,
            'classified_at' => (string) $row['classified_at'],
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
        ];
    }
}
