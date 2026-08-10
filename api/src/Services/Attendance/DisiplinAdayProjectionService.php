<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use PDO;
use PDOException;
use RuntimeException;

final class DisiplinAdayProjectionService
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function projectForMonth(PDO $pdo, array $user, $ay, $subeId = null, $personelId = null)
    {
        $ay = trim((string) $ay);
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            throw new RuntimeException('Gecersiz ay formati (YYYY-MM).');
        }

        $from = $ay . '-01';
        $to = date('Y-m-t', strtotime($from));
        $actorId = isset($user['id']) ? (int) $user['id'] : null;

        $personelIds = self::resolvePersonelIds($pdo, $subeId, $personelId);
        $created = 0;
        $skipped = 0;
        $items = [];

        foreach ($personelIds as $pid) {
            $rows = self::loadPuantajRows($pdo, (int) $pid, $from, $to);
            foreach ($rows as $row) {
                $candidates = self::buildDailyCandidates($row);
                foreach ($candidates as $candidate) {
                    $result = self::insertCandidate($pdo, $user, $candidate, $actorId);
                    if ($result['created']) {
                        $created++;
                        $items[] = $result['vaka'];
                    } else {
                        $skipped++;
                    }
                }
            }

            $monthly = self::buildMonthlyLateCandidate($pdo, (int) $pid, $ay, $from, $to, $rows);
            if ($monthly !== null) {
                $result = self::insertCandidate($pdo, $user, $monthly, $actorId);
                if ($result['created']) {
                    $created++;
                    $items[] = $result['vaka'];
                } else {
                    $skipped++;
                }
            }
        }

        return [
            'ay' => $ay,
            'sube_id' => $subeId !== null ? (int) $subeId : null,
            'personel_id' => $personelId !== null ? (int) $personelId : null,
            'created_count' => $created,
            'skipped_count' => $skipped,
            'items' => $items,
        ];
    }

    /** @return array<int, int> */
    private static function resolvePersonelIds(PDO $pdo, $subeId, $personelId)
    {
        if ($personelId !== null && (int) $personelId > 0) {
            return [(int) $personelId];
        }

        $sql = 'SELECT id FROM personeller WHERE 1=1';
        $params = [];
        if ($subeId !== null && (int) $subeId > 0) {
            $sql .= ' AND sube_id = :sube_id';
            $params['sube_id'] = (int) $subeId;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    /** @return array<int, array<string, mixed>> */
    private static function loadPuantajRows(PDO $pdo, $personelId, $from, $to)
    {
        $stmt = $pdo->prepare(
            'SELECT gp.*, p.sube_id
             FROM gunluk_puantaj gp
             INNER JOIN personeller p ON p.id = gp.personel_id
             WHERE gp.personel_id = :personel_id AND gp.tarih >= :from_date AND gp.tarih <= :to_date
             ORDER BY gp.tarih ASC'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'from_date' => (string) $from,
            'to_date' => (string) $to,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    public static function evaluateDailyCandidateKinds(array $row)
    {
        $kinds = [];
        $gec = isset($row['gec_kalma_dakika']) ? (int) $row['gec_kalma_dakika'] : 0;

        // Tri-state: only explicit 0 is unannounced. NULL/unknown ≠ unannounced.
        if ($gec > 0 && self::isExplicitlyUnannounced($row['durumu_bildirdi_mi'] ?? null)) {
            $kinds[] = AttendanceDisciplineCatalog::CANDIDATE_GEC_KALMA;
        }

        if (self::isUnannouncedFullDayAbsence($row)) {
            $kinds[] = AttendanceDisciplineCatalog::CANDIDATE_TAM_GUN_DEVAMSIZLIK;
        }

        return $kinds;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public static function countMonthlyLateEvents(array $rows)
    {
        $count = 0;
        foreach ($rows as $row) {
            $gec = isset($row['gec_kalma_dakika']) ? (int) $row['gec_kalma_dakika'] : 0;
            if ($gec >= AttendanceDisciplineCatalog::MONTHLY_LATE_MINUTES) {
                $count++;
            }
        }

        return $count;
    }

    public static function shouldCreateMonthlyCandidate($count)
    {
        return (int) $count >= AttendanceDisciplineCatalog::MONTHLY_LATE_EVENT_THRESHOLD;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private static function buildDailyCandidates(array $row)
    {
        $out = [];
        $personelId = (int) $row['personel_id'];
        $tarih = (string) $row['tarih'];
        $ay = substr($tarih, 0, 7);

        foreach (self::evaluateDailyCandidateKinds($row) as $kind) {
            if ($kind === AttendanceDisciplineCatalog::CANDIDATE_GEC_KALMA) {
                $gec = isset($row['gec_kalma_dakika']) ? (int) $row['gec_kalma_dakika'] : 0;
                $puantajId = isset($row['id']) ? (int) $row['id'] : 0;
                $identity = 'GEC_KALMA|' . $personelId . '|' . $tarih . '|PUANTAJ:' . $puantajId;
                $out[] = [
                    'personel_id' => $personelId,
                    'sube_id' => isset($row['sube_id']) ? (int) $row['sube_id'] : null,
                    'tarih' => $tarih,
                    'ay' => $ay,
                    'olay_turu' => AttendanceDisciplineCatalog::CANDIDATE_GEC_KALMA,
                    'raw_dakika' => $gec,
                    'gunluk_puantaj_id' => $puantajId > 0 ? $puantajId : null,
                    'gunluk_bildirim_id' => null,
                    'source_identity' => $identity,
                    'source_hash' => hash('sha256', 'DISIPLIN|' . $identity),
                ];
                continue;
            }

            if ($kind === AttendanceDisciplineCatalog::CANDIDATE_TAM_GUN_DEVAMSIZLIK) {
                $identity = 'TAM_GUN_DEVAMSIZLIK|' . $personelId . '|' . $tarih;
                $out[] = [
                    'personel_id' => $personelId,
                    'sube_id' => isset($row['sube_id']) ? (int) $row['sube_id'] : null,
                    'tarih' => $tarih,
                    'ay' => $ay,
                    'olay_turu' => AttendanceDisciplineCatalog::CANDIDATE_TAM_GUN_DEVAMSIZLIK,
                    'raw_dakika' => null,
                    'gunluk_puantaj_id' => isset($row['id']) ? (int) $row['id'] : null,
                    'gunluk_bildirim_id' => null,
                    'source_identity' => $identity,
                    'source_hash' => hash('sha256', 'DISIPLIN|' . $identity),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private static function buildMonthlyLateCandidate(PDO $pdo, $personelId, $ay, $from, $to, array $rows)
    {
        $count = self::countMonthlyLateEvents($rows);
        $subeId = null;
        foreach ($rows as $row) {
            if ($subeId === null && isset($row['sube_id'])) {
                $subeId = (int) $row['sube_id'];
            }
        }
        if (!self::shouldCreateMonthlyCandidate($count)) {
            return null;
        }

        $identity = 'MONTHLY_LATE|' . (int) $personelId . '|' . $ay . '|' . AttendanceDisciplineCatalog::MONTHLY_LATE_EVENT_THRESHOLD;

        return [
            'personel_id' => (int) $personelId,
            'sube_id' => $subeId,
            'tarih' => $to,
            'ay' => $ay,
            'olay_turu' => AttendanceDisciplineCatalog::CANDIDATE_AYLIK_TEKRARLAYAN_GEC_KALMA,
            'raw_dakika' => null,
            'gunluk_puantaj_id' => null,
            'gunluk_bildirim_id' => null,
            'source_identity' => $identity,
            'source_hash' => hash('sha256', $identity),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $candidate
     * @return array{created:bool,vaka:?array<string,mixed>}
     */
    private static function insertCandidate(PDO $pdo, array $user, array $candidate, $actorId)
    {
        $existing = self::findBySourceHash($pdo, (string) $candidate['source_hash']);
        if ($existing) {
            return ['created' => false, 'vaka' => $existing];
        }

        try {
            $pdo->beginTransaction();

            $surecStmt = $pdo->prepare(
                'INSERT INTO surecler (
                    personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                    ucretli_mi, aciklama, state
                 ) VALUES (
                    :personel_id, :surec_turu, :alt_tur, :baslangic_tarihi, :bitis_tarihi,
                    0, :aciklama, \'AKTIF\'
                 )'
            );
            $surecStmt->execute([
                'personel_id' => (int) $candidate['personel_id'],
                'surec_turu' => AttendanceDisciplineCatalog::SUREC_TURU_DISIPLIN,
                'alt_tur' => (string) $candidate['olay_turu'],
                'baslangic_tarihi' => (string) $candidate['tarih'],
                'bitis_tarihi' => (string) $candidate['tarih'],
                'aciklama' => 'Disiplin aday vakasi: ' . (string) $candidate['source_identity'],
            ]);
            $surecId = (int) $pdo->lastInsertId();

            $vakaStmt = $pdo->prepare(
                'INSERT INTO disiplin_vakalar (
                    surec_id, personel_id, sube_id, tarih, ay, olay_turu, lifecycle_state,
                    raw_dakika, gunluk_puantaj_id, gunluk_bildirim_id,
                    source_identity, source_hash, created_by
                 ) VALUES (
                    :surec_id, :personel_id, :sube_id, :tarih, :ay, :olay_turu, :lifecycle_state,
                    :raw_dakika, :gunluk_puantaj_id, :gunluk_bildirim_id,
                    :source_identity, :source_hash, :created_by
                 )'
            );
            $vakaStmt->execute([
                'surec_id' => $surecId,
                'personel_id' => (int) $candidate['personel_id'],
                'sube_id' => $candidate['sube_id'],
                'tarih' => (string) $candidate['tarih'],
                'ay' => (string) $candidate['ay'],
                'olay_turu' => (string) $candidate['olay_turu'],
                'lifecycle_state' => AttendanceDisciplineCatalog::LIFECYCLE_INCELEME_ADAYI,
                'raw_dakika' => $candidate['raw_dakika'],
                'gunluk_puantaj_id' => $candidate['gunluk_puantaj_id'],
                'gunluk_bildirim_id' => $candidate['gunluk_bildirim_id'],
                'source_identity' => (string) $candidate['source_identity'],
                'source_hash' => (string) $candidate['source_hash'],
                'created_by' => $actorId,
            ]);
            $vakaId = (int) $pdo->lastInsertId();

            $auditStmt = $pdo->prepare(
                'INSERT INTO disiplin_vaka_auditleri (
                    disiplin_vaka_id, action, from_state, to_state, actor_user_id, detail_json
                 ) VALUES (
                    :disiplin_vaka_id, :action, NULL, :to_state, :actor_user_id, :detail_json
                 )'
            );
            $auditStmt->execute([
                'disiplin_vaka_id' => $vakaId,
                'action' => 'ADAY_OLUSTUR',
                'to_state' => AttendanceDisciplineCatalog::LIFECYCLE_INCELEME_ADAYI,
                'actor_user_id' => $actorId,
                'detail_json' => json_encode([
                    'source_hash' => $candidate['source_hash'],
                    'source_identity' => $candidate['source_identity'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $dup = self::findBySourceHash($pdo, (string) $candidate['source_hash']);
            if ($dup) {
                return ['created' => false, 'vaka' => $dup];
            }
            throw new RuntimeException('Disiplin aday vakasi olusturulamadi.', 0, $e);
        }

        return [
            'created' => true,
            'vaka' => DisiplinVakaService::getById($pdo, $vakaId),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function findBySourceHash(PDO $pdo, $sourceHash)
    {
        $stmt = $pdo->prepare('SELECT id FROM disiplin_vakalar WHERE source_hash = :source_hash LIMIT 1');
        $stmt->execute(['source_hash' => (string) $sourceHash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        return DisiplinVakaService::getById($pdo, (int) $id);
    }

    /** @param array<string, mixed> $row */
    private static function isUnannouncedFullDayAbsence(array $row)
    {
        // Automatic candidate requires explicit unauthorized evidence — never fabricate from unknowns.
        if (!self::isExplicitlyUnannounced($row['durumu_bildirdi_mi'] ?? null)) {
            return false;
        }

        $dayanak = trim((string) ($row['dayanak'] ?? ''));
        if ($dayanak !== 'Yok_Izinsiz') {
            return false;
        }

        $gunTipi = strtoupper(trim((string) ($row['gun_tipi'] ?? '')));
        if ($gunTipi === 'TAM_GUN_DEVAMSIZLIK') {
            return true;
        }

        $hareket = self::normalizeToken($row['hareket_durumu'] ?? null);
        if ($hareket === 'GELMEDI' || $hareket === 'GELMEDİ') {
            $gec = isset($row['gec_kalma_dakika']) ? (int) $row['gec_kalma_dakika'] : 0;
            $erken = isset($row['erken_cikis_dakika']) ? (int) $row['erken_cikis_dakika'] : 0;
            $net = isset($row['net_calisma_suresi_dakika']) ? (int) $row['net_calisma_suresi_dakika'] : 0;

            return $gec === 0 && $erken === 0 && $net === 0;
        }

        return false;
    }

    /** Explicit 0 only — NULL/unknown is not unannounced. @param mixed $value */
    private static function isExplicitlyUnannounced($value)
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_bool($value)) {
            return $value === false;
        }

        return (int) $value === 0;
    }

    /** @param mixed $value */
    private static function isTruthy($value)
    {
        if ($value === null) {
            return false;
        }

        return (int) $value === 1;
    }

    /** @param mixed $value */
    private static function normalizeToken($value)
    {
        $token = strtoupper(trim((string) $value));
        if ($token === 'GELMEDİ') {
            return 'GELMEDI';
        }

        return $token;
    }
}
