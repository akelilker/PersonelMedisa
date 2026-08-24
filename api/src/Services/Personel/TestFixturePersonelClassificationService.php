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

    /** Accepted auditable evidence codes (not production personel IDs). */
    public const EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI = 'BORDRO_KAPSAM_DEMO_TEST_VERISI';
    public const EVIDENCE_LIVE_API_SMOKE_CREATE = 'LIVE_API_SMOKE_CREATE';
    public const EVIDENCE_SEED_SCHEMA_FIXTURE = 'SEED_SCHEMA_FIXTURE';
    public const EVIDENCE_MANUAL_OPS_CLASSIFIED = 'MANUAL_OPS_CLASSIFIED';

    /**
     * @return array<int, string>
     */
    public static function allowedEvidenceKodlari()
    {
        return [
            self::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI,
            self::EVIDENCE_LIVE_API_SMOKE_CREATE,
            self::EVIDENCE_SEED_SCHEMA_FIXTURE,
            self::EVIDENCE_MANUAL_OPS_CLASSIFIED,
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
     * Persist TEST_FIXTURE classification. Idempotent when same evidence.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function classify(PDO $pdo, $personelId, $evidenceKodu, array $actor, $evidenceRef = null, $aciklama = null)
    {
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
        if (!in_array($evidenceKodu, self::allowedEvidenceKodlari(), true)) {
            throw new TestFixturePersonelArchiveException(
                'INVALID_EVIDENCE_KODU',
                'Gecersiz test fixture evidence kodu.',
                422,
                'evidence_kodu'
            );
        }

        $personel = self::lockPersonel($pdo, $personelId);
        if ($personel === null) {
            throw new TestFixturePersonelArchiveException('PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 404, 'personel_id');
        }

        if ($evidenceKodu === self::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI) {
            self::assertDemoTestKapsamEvidence($pdo, $personelId);
        }

        $existing = self::findActive($pdo, $personelId);
        if ($existing !== null) {
            if ((string) $existing['evidence_kodu'] === $evidenceKodu) {
                return $existing;
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

        return $row;
    }

    private static function assertDemoTestKapsamEvidence(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
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
}
