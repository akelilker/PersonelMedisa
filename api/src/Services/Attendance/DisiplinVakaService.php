<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use Medisa\Api\Auth\RolePermissions;
use PDO;
use PDOException;
use RuntimeException;

final class DisiplinVakaService
{
    private const TABLE = 'disiplin_vakalar';
    private const AUDIT_TABLE = 'disiplin_vaka_auditleri';

    /** @return array<string, mixed>|null */
    public static function getById(PDO $pdo, $id)
    {
        $vaka = self::fetchRow($pdo, (int) $id);
        if (!$vaka) {
            return null;
        }
        self::resolveDeadlineState($pdo, $vaka, null);

        return self::fetchRow($pdo, (int) $id);
    }

    /** @return array<string, mixed>|null */
    public static function getBySurecId(PDO $pdo, $surecId)
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE surec_id = :surec_id LIMIT 1');
        $stmt->execute(['surec_id' => (int) $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $vaka = self::mapRow($row);
        self::resolveDeadlineState($pdo, $vaka, null);

        return self::getById($pdo, (int) $vaka['id']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listOpen(PDO $pdo, $subeId = null, $personelId = null, $ay = null)
    {
        $where = ['lifecycle_state NOT IN (\'KAPANDI\', \'ISLEMSIZ_KAPATILDI\')'];
        $params = [];
        if ($subeId !== null && (int) $subeId > 0) {
            $where[] = 'sube_id = :sube_id';
            $params['sube_id'] = (int) $subeId;
        }
        if ($personelId !== null && (int) $personelId > 0) {
            $where[] = 'personel_id = :personel_id';
            $params['personel_id'] = (int) $personelId;
        }
        if ($ay !== null && trim((string) $ay) !== '') {
            $where[] = 'ay = :ay';
            $params['ay'] = trim((string) $ay);
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY tarih DESC, id DESC'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped = self::mapRow($row);
            self::resolveDeadlineState($pdo, $mapped, null);
            $refreshed = self::fetchRow($pdo, (int) $mapped['id']);
            if ($refreshed) {
                $items[] = $refreshed;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function ikReview(PDO $pdo, array $user, $vakaId, $note = null)
    {
        self::assertIkReviewActor($user);
        $vaka = self::requireVaka($pdo, (int) $vakaId);
        self::resolveDeadlineState($pdo, $vaka, null);
        $vaka = self::requireVaka($pdo, (int) $vakaId);

        self::assertTransition(
            (string) $vaka['lifecycle_state'],
            AttendanceDisciplineCatalog::LIFECYCLE_IK_INCELEME,
            [
                AttendanceDisciplineCatalog::LIFECYCLE_INCELEME_ADAYI,
                AttendanceDisciplineCatalog::LIFECYCLE_IK_INCELEME,
            ]
        );

        return self::transition(
            $pdo,
            $vaka,
            AttendanceDisciplineCatalog::LIFECYCLE_IK_INCELEME,
            'IK_INCELEME',
            isset($user['id']) ? (int) $user['id'] : null,
            ['note' => $note]
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function requestDefense(PDO $pdo, array $user, $vakaId, array $payload)
    {
        if (!RolePermissions::has($user, 'disiplin.defense_manage')) {
            throw new RuntimeException('Savunma yonetim yetkisi yok.');
        }

        $deadlineAt = trim((string) ($payload['deadline_at'] ?? ''));
        $yer = trim((string) ($payload['yer'] ?? ''));
        $konu = trim((string) ($payload['konu'] ?? ''));
        if ($deadlineAt === '' || $yer === '' || $konu === '') {
            throw new RuntimeException('Savunma talebi icin deadline_at, yer ve konu zorunludur.');
        }
        $deadlineTs = strtotime($deadlineAt);
        if ($deadlineTs === false) {
            throw new RuntimeException('VALIDATION_ERROR: deadline_at gecerli bir tarih/saat olmali.');
        }
        if ($deadlineTs <= time()) {
            throw new RuntimeException('VALIDATION_ERROR: deadline_at gelecekte olmali.');
        }

        $vaka = self::requireVaka($pdo, (int) $vakaId);
        self::resolveDeadlineState($pdo, $vaka, null);
        $vaka = self::requireVaka($pdo, (int) $vakaId);

        self::assertTransition(
            (string) $vaka['lifecycle_state'],
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR,
            [
                AttendanceDisciplineCatalog::LIFECYCLE_IK_INCELEME,
                AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR,
            ]
        );

        $stmt = $pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET savunma_talep_tarihi = CURDATE(),
                 savunma_deadline_at = :deadline_at,
                 savunma_yer = :yer,
                 savunma_konu = :konu,
                 savunma_isteyen_user_id = :isteyen_user_id,
                 lifecycle_state = :lifecycle_state
             WHERE id = :id'
        );
        $stmt->execute([
            'deadline_at' => $deadlineAt,
            'yer' => $yer,
            'konu' => $konu,
            'isteyen_user_id' => isset($user['id']) ? (int) $user['id'] : null,
            'lifecycle_state' => AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR,
            'id' => (int) $vaka['id'],
        ]);

        self::writeAudit(
            $pdo,
            (int) $vaka['id'],
            'SAVUNMA_TALEP',
            (string) $vaka['lifecycle_state'],
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR,
            isset($user['id']) ? (int) $user['id'] : null,
            ['deadline_at' => $deadlineAt, 'yer' => $yer, 'konu' => $konu]
        );

        return self::requireVaka($pdo, (int) $vakaId);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function attachDefenseBelge(PDO $pdo, array $user, $vakaId, $belgeSurecId)
    {
        if (!RolePermissions::has($user, 'disiplin.defense_manage')) {
            throw new RuntimeException('Savunma yonetim yetkisi yok.');
        }

        $belgeSurecId = (int) $belgeSurecId;
        if ($belgeSurecId < 1) {
            throw new RuntimeException('Gecersiz belge surec id.');
        }
        self::assertBelgeSurec($pdo, $belgeSurecId, (int) (self::requireVaka($pdo, (int) $vakaId)['personel_id']));

        $vaka = self::requireVaka($pdo, (int) $vakaId);
        self::resolveDeadlineState($pdo, $vaka, null);
        $vaka = self::requireVaka($pdo, (int) $vakaId);

        if ((string) $vaka['lifecycle_state'] !== AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR
            && (string) $vaka['lifecycle_state'] !== AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI
        ) {
            throw new RuntimeException('Savunma belgesi bu asamada eklenemez.');
        }

        $from = (string) $vaka['lifecycle_state'];
        $to = AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_ALINDI;
        $stmt = $pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET savunma_belge_surec_id = :belge_surec_id,
                 savunma_received_at = CURRENT_TIMESTAMP,
                 lifecycle_state = :lifecycle_state
             WHERE id = :id'
        );
        $stmt->execute([
            'belge_surec_id' => $belgeSurecId,
            'lifecycle_state' => $to,
            'id' => (int) $vaka['id'],
        ]);

        self::writeAudit(
            $pdo,
            (int) $vaka['id'],
            'SAVUNMA_BELGE',
            $from,
            $to,
            isset($user['id']) ? (int) $user['id'] : null,
            ['belge_surec_id' => $belgeSurecId]
        );

        self::advanceToKararBekliyor($pdo, (int) $vaka['id'], isset($user['id']) ? (int) $user['id'] : null);

        return self::requireVaka($pdo, (int) $vakaId);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function finalDecision(PDO $pdo, array $user, $vakaId, $nihaiKarar, $gerekce = null)
    {
        self::assertFinalDecisionActor($user);
        $nihaiKarar = strtoupper(trim((string) $nihaiKarar));
        if (!in_array($nihaiKarar, AttendanceDisciplineCatalog::nihaiKararTurleri(), true)) {
            throw new RuntimeException('Gecersiz nihai karar.');
        }

        $vaka = self::requireVaka($pdo, (int) $vakaId);
        self::resolveDeadlineState($pdo, $vaka, null);
        $vaka = self::requireVaka($pdo, (int) $vakaId);

        self::assertTransition(
            (string) $vaka['lifecycle_state'],
            AttendanceDisciplineCatalog::LIFECYCLE_KARAR_VERILDI,
            [
                AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR,
                AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_ALINDI,
                AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI,
            ]
        );

        $from = (string) $vaka['lifecycle_state'];
        $stmt = $pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET nihai_karar = :nihai_karar,
                 nihai_karar_gerekce = :gerekce,
                 nihai_karar_veren_user_id = :veren_user_id,
                 nihai_karar_at = CURRENT_TIMESTAMP,
                 lifecycle_state = :lifecycle_state
             WHERE id = :id'
        );
        $stmt->execute([
            'nihai_karar' => $nihaiKarar,
            'gerekce' => $gerekce,
            'veren_user_id' => isset($user['id']) ? (int) $user['id'] : null,
            'lifecycle_state' => AttendanceDisciplineCatalog::LIFECYCLE_KARAR_VERILDI,
            'id' => (int) $vaka['id'],
        ]);

        self::writeAudit(
            $pdo,
            (int) $vaka['id'],
            'NIHAI_KARAR',
            $from,
            AttendanceDisciplineCatalog::LIFECYCLE_KARAR_VERILDI,
            isset($user['id']) ? (int) $user['id'] : null,
            ['nihai_karar' => $nihaiKarar, 'gerekce' => $gerekce]
        );

        return self::closeIfTerminal($pdo, (int) $vakaId, isset($user['id']) ? (int) $user['id'] : null);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function closeNoAction(PDO $pdo, array $user, $vakaId, $gerekce = null)
    {
        self::assertFinalDecisionActor($user);

        $vaka = self::requireVaka($pdo, (int) $vakaId);
        $from = (string) $vaka['lifecycle_state'];
        $earlyStates = [
            AttendanceDisciplineCatalog::LIFECYCLE_INCELEME_ADAYI,
            AttendanceDisciplineCatalog::LIFECYCLE_IK_INCELEME,
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR,
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_ALINDI,
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI,
            AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR,
        ];
        if (!in_array($from, $earlyStates, true)) {
            throw new RuntimeException('Vaka bu asamada islemsiz kapatilamaz.');
        }

        $stmt = $pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET lifecycle_state = :lifecycle_state,
                 nihai_karar = :nihai_karar,
                 nihai_karar_gerekce = :gerekce,
                 nihai_karar_veren_user_id = :veren_user_id,
                 nihai_karar_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'lifecycle_state' => AttendanceDisciplineCatalog::LIFECYCLE_ISLEMSIZ_KAPATILDI,
            'nihai_karar' => AttendanceDisciplineCatalog::NIHAI_KARAR_NO_ACTION,
            'gerekce' => $gerekce,
            'veren_user_id' => isset($user['id']) ? (int) $user['id'] : null,
            'id' => (int) $vaka['id'],
        ]);

        self::writeAudit(
            $pdo,
            (int) $vaka['id'],
            'ISLEMSIZ_KAPAT',
            $from,
            AttendanceDisciplineCatalog::LIFECYCLE_ISLEMSIZ_KAPATILDI,
            isset($user['id']) ? (int) $user['id'] : null,
            ['gerekce' => $gerekce, 'nihai_karar' => AttendanceDisciplineCatalog::NIHAI_KARAR_NO_ACTION]
        );

        return self::requireVaka($pdo, (int) $vakaId);
    }

    /**
     * @param array<string, mixed> $vaka
     */
    public static function resolveDeadlineState(PDO $pdo, array $vaka, $actorUserId)
    {
        if ((string) $vaka['lifecycle_state'] !== AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR) {
            return $vaka;
        }
        if (!empty($vaka['savunma_belge_surec_id']) || !empty($vaka['savunma_received_at'])) {
            return $vaka;
        }
        $deadline = (string) ($vaka['savunma_deadline_at'] ?? '');
        if ($deadline === '') {
            return $vaka;
        }
        if (strtotime($deadline) === false || time() <= strtotime($deadline)) {
            return $vaka;
        }

        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET lifecycle_state = :to_state
                 WHERE id = :id AND lifecycle_state = :from_state'
            );
            $stmt->execute([
                'to_state' => AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI,
                'from_state' => AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR,
                'id' => (int) $vaka['id'],
            ]);
            if ($stmt->rowCount() > 0) {
                self::writeAudit(
                    $pdo,
                    (int) $vaka['id'],
                    'SAVUNMA_DEADLINE_GECDI',
                    AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR,
                    AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI,
                    $actorUserId,
                    ['deadline_at' => $deadline]
                );
                // Stay at SAVUNMA_SUNULMADI: no automatic punishment / forfeiture.
                // Late defense may still arrive; BOLUM may decide from this state.
            }
            if ($ownsTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $vaka;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listAudits(PDO $pdo, $vakaId)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::AUDIT_TABLE . '
             WHERE disiplin_vaka_id = :vaka_id ORDER BY id ASC'
        );
        $stmt->execute(['vaka_id' => (int) $vakaId]);

        return array_map(static function (array $row) {
            return [
                'id' => (int) $row['id'],
                'disiplin_vaka_id' => (int) $row['disiplin_vaka_id'],
                'action' => (string) $row['action'],
                'from_state' => $row['from_state'] !== null ? (string) $row['from_state'] : null,
                'to_state' => $row['to_state'] !== null ? (string) $row['to_state'] : null,
                'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
                'detail_json' => $row['detail_json'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function tableExists(PDO $pdo)
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
        );

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * @param array<string, mixed> $vaka
     * @param array<string, mixed>|null $detail
     * @return array<string, mixed>
     */
    private static function transition(PDO $pdo, array $vaka, $toState, $action, $actorUserId, $detail = null)
    {
        $from = (string) $vaka['lifecycle_state'];
        if ($from === $toState) {
            return $vaka;
        }

        $stmt = $pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET lifecycle_state = :to_state WHERE id = :id'
        );
        $stmt->execute(['to_state' => $toState, 'id' => (int) $vaka['id']]);
        self::writeAudit($pdo, (int) $vaka['id'], $action, $from, $toState, $actorUserId, $detail);

        return self::requireVaka($pdo, (int) $vaka['id']);
    }

    /** @return array<string, mixed> */
    private static function closeIfTerminal(PDO $pdo, $vakaId, $actorUserId)
    {
        $vaka = self::requireVaka($pdo, (int) $vakaId);
        if ((string) $vaka['lifecycle_state'] !== AttendanceDisciplineCatalog::LIFECYCLE_KARAR_VERILDI) {
            return $vaka;
        }

        $from = (string) $vaka['lifecycle_state'];
        $stmt = $pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET lifecycle_state = :to_state WHERE id = :id'
        );
        $stmt->execute([
            'to_state' => AttendanceDisciplineCatalog::LIFECYCLE_KAPANDI,
            'id' => (int) $vaka['id'],
        ]);
        self::writeAudit(
            $pdo,
            (int) $vaka['id'],
            'KAPAT',
            $from,
            AttendanceDisciplineCatalog::LIFECYCLE_KAPANDI,
            $actorUserId,
            ['nihai_karar' => $vaka['nihai_karar']]
        );

        return self::requireVaka($pdo, (int) $vakaId);
    }

    /** @param array<int, string> $allowedFrom */
    private static function assertTransition($current, $target, array $allowedFrom)
    {
        if (!in_array($current, $allowedFrom, true)) {
            throw new RuntimeException('Gecersiz lifecycle gecisi: ' . $current . ' -> ' . $target);
        }
    }

    /** @return array<string, mixed> */
    private static function requireVaka(PDO $pdo, $id)
    {
        $row = self::fetchRow($pdo, (int) $id);
        if (!$row) {
            throw new RuntimeException('Disiplin vakasi bulunamadi.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private static function fetchRow(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapRow($row) : null;
    }

    private static function advanceToKararBekliyor(PDO $pdo, $vakaId, $actorUserId)
    {
        $vaka = self::fetchRow($pdo, (int) $vakaId);
        if (!$vaka) {
            return;
        }
        $from = (string) $vaka['lifecycle_state'];
        if (!in_array($from, [
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_ALINDI,
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI,
        ], true)) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET lifecycle_state = :to_state WHERE id = :id AND lifecycle_state = :from_state'
        );
        $stmt->execute([
            'to_state' => AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR,
            'from_state' => $from,
            'id' => (int) $vakaId,
        ]);
        if ($stmt->rowCount() > 0) {
            self::writeAudit(
                $pdo,
                (int) $vakaId,
                'KARAR_BEKLIYOR',
                $from,
                AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR,
                $actorUserId,
                null
            );
        }
    }

    private static function assertBelgeSurec(PDO $pdo, $surecId, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, personel_id, surec_turu, state FROM surecler WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Belge sureci bulunamadi.');
        }
        if ((int) $row['personel_id'] !== (int) $personelId) {
            throw new RuntimeException('Belge sureci personel ile uyusmuyor.');
        }
        if (strtoupper((string) $row['surec_turu']) !== 'BELGE') {
            throw new RuntimeException('Savunma belgesi BELGE surec turu olmalidir.');
        }
        if (strtoupper((string) $row['state']) === 'IPTAL') {
            throw new RuntimeException('Iptal edilmis belge sureci baglanamaz.');
        }
    }

    /** @param array<string, mixed>|null $detail */
    private static function writeAudit(PDO $pdo, $vakaId, $action, $fromState, $toState, $actorUserId, $detail)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::AUDIT_TABLE . ' (
                disiplin_vaka_id, action, from_state, to_state, actor_user_id, detail_json
             ) VALUES (
                :disiplin_vaka_id, :action, :from_state, :to_state, :actor_user_id, :detail_json
             )'
        );
        $stmt->execute([
            'disiplin_vaka_id' => (int) $vakaId,
            'action' => (string) $action,
            'from_state' => $fromState,
            'to_state' => $toState,
            'actor_user_id' => $actorUserId,
            'detail_json' => $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function assertIkReviewActor(array $user)
    {
        if (!RolePermissions::has($user, 'disiplin.review')) {
            throw new RuntimeException('IK inceleme yetkisi yok.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertFinalDecisionActor(array $user)
    {
        $role = strtoupper(trim((string) ($user['rol'] ?? '')));
        if (!in_array($role, AttendanceDisciplineCatalog::finalDecisionRoles(), true)) {
            throw new RuntimeException('Nihai karar yetkisi yok.');
        }
        if (!RolePermissions::has($user, 'disiplin.final_decision')) {
            throw new RuntimeException('Nihai karar yetkisi yok.');
        }
    }

    /** @param array<string, mixed> $row */
    private static function mapRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'surec_id' => (int) $row['surec_id'],
            'personel_id' => (int) $row['personel_id'],
            'sube_id' => $row['sube_id'] !== null ? (int) $row['sube_id'] : null,
            'tarih' => (string) $row['tarih'],
            'ay' => (string) $row['ay'],
            'olay_turu' => (string) $row['olay_turu'],
            'lifecycle_state' => (string) $row['lifecycle_state'],
            'raw_dakika' => $row['raw_dakika'] !== null ? (int) $row['raw_dakika'] : null,
            'gunluk_puantaj_id' => $row['gunluk_puantaj_id'] !== null ? (int) $row['gunluk_puantaj_id'] : null,
            'gunluk_bildirim_id' => $row['gunluk_bildirim_id'] !== null ? (int) $row['gunluk_bildirim_id'] : null,
            'source_identity' => (string) $row['source_identity'],
            'source_hash' => (string) $row['source_hash'],
            'savunma_talep_tarihi' => $row['savunma_talep_tarihi'] !== null ? (string) $row['savunma_talep_tarihi'] : null,
            'savunma_deadline_at' => $row['savunma_deadline_at'] !== null ? (string) $row['savunma_deadline_at'] : null,
            'savunma_yer' => $row['savunma_yer'] !== null ? (string) $row['savunma_yer'] : null,
            'savunma_konu' => $row['savunma_konu'] !== null ? (string) $row['savunma_konu'] : null,
            'savunma_isteyen_user_id' => $row['savunma_isteyen_user_id'] !== null ? (int) $row['savunma_isteyen_user_id'] : null,
            'savunma_belge_surec_id' => $row['savunma_belge_surec_id'] !== null ? (int) $row['savunma_belge_surec_id'] : null,
            'savunma_received_at' => $row['savunma_received_at'] !== null ? (string) $row['savunma_received_at'] : null,
            'nihai_karar' => $row['nihai_karar'] !== null ? (string) $row['nihai_karar'] : null,
            'nihai_karar_gerekce' => $row['nihai_karar_gerekce'] !== null ? (string) $row['nihai_karar_gerekce'] : null,
            'nihai_karar_veren_user_id' => $row['nihai_karar_veren_user_id'] !== null ? (int) $row['nihai_karar_veren_user_id'] : null,
            'nihai_karar_at' => $row['nihai_karar_at'] !== null ? (string) $row['nihai_karar_at'] : null,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
