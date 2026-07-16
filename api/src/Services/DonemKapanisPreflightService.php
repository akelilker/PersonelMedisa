<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

class DonemKapanisPreflightService
{
    public const SCHEMA_VERSION = 'S76_PERIOD_CLOSE_PREFLIGHT_V1';
    public const SEVERITY_BLOCKER = 'BLOCKER';
    public const SEVERITY_WARNING = 'WARNING';
    public const SEVERITY_INFO = 'INFO';

    /**
     * @param array{departman_id?: int|null, personel_id?: int|null} $filters
     * @param int|null $restrictAmirId BIRIM_AMIRI scope
     * @return array<string, mixed>
     */
    public static function evaluate(PDO $pdo, $subeId, $yil, $ay, array $filters = [], $restrictAmirId = null)
    {
        [$donem, $ayBaslangic, $ayBitis] = BildirimDonemContextService::monthBounds($yil, $ay);
        $muhur = self::findMonthlySeal($pdo, (int) $subeId, (int) $yil, (int) $ay);
        $muhurState = $muhur ? 'MUHURLENDI' : 'ACIK';
        $donemState = $muhur ? 'MUHURLU' : 'ACIK';

        $blockers = [];
        $warnings = [];
        $infos = [];

        if ($muhur) {
            $infos = array_merge($infos, self::buildInfoMetrics($pdo, (int) $subeId, $donem, $ayBaslangic, $ayBitis, $filters, $restrictAmirId));

            return self::assemblePayload(
                $pdo,
                (int) $subeId,
                (int) $yil,
                (int) $ay,
                $donem,
                $donemState,
                $muhurState,
                $muhur ? (int) $muhur['id'] : null,
                [],
                $warnings,
                $infos
            );
        }

        $amirIds = $restrictAmirId !== null
            ? [(int) $restrictAmirId]
            : BildirimDonemContextService::listAmirsWithBildirimActivity($pdo, (int) $subeId, $ayBaslangic, $ayBitis, $filters);

        $hasNotificationActivity = count($amirIds) > 0;

        if ($hasNotificationActivity) {
            $blockers = array_merge($blockers, self::evaluateNotificationBlockers($pdo, (int) $subeId, $donem, $ayBaslangic, $ayBitis, $amirIds, $filters));
        } else {
            $infos[] = self::issue(
                'NO_NOTIFICATION_ACTIVITY',
                self::SEVERITY_INFO,
                'bildirim',
                'Bildirim aktivitesi yok',
                'Bu donemde bildirim zinciri kaydi bulunmuyor.',
                0,
                'MUHASEBE',
                '/bildirimler',
                'bildirimler.view',
                [],
                []
            );
        }

        $blockers = array_merge($blockers, self::evaluateCandidateBlockers($pdo, (int) $subeId, $donem, $amirIds));
        $blockers = array_merge($blockers, self::evaluatePuantajBlockers($pdo, (int) $subeId, $ayBaslangic, $ayBitis, $filters));

        $warnings = array_merge($warnings, self::evaluateWarnings($pdo, (int) $subeId, $donem, $ayBaslangic, $ayBitis, $filters, $hasNotificationActivity));
        $infos = array_merge($infos, self::buildInfoMetrics($pdo, (int) $subeId, $donem, $ayBaslangic, $ayBitis, $filters, $restrictAmirId));

        return self::assemblePayload(
            $pdo,
            (int) $subeId,
            (int) $yil,
            (int) $ay,
            $donem,
            $donemState,
            $muhurState,
            null,
            $blockers,
            $warnings,
            $infos
        );
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @param array<int, array<string, mixed>> $blockers
     * @param array<int, array<string, mixed>> $warnings
     * @param array<int, array<string, mixed>> $infos
     */
    private static function assemblePayload(
        PDO $pdo,
        $subeId,
        $yil,
        $ay,
        $donem,
        $donemState,
        $muhurState,
        $muhurId,
        array $blockers,
        array $warnings,
        array $infos
    ) {
        $sube = self::fetchSube($pdo, $subeId);
        $blockerCount = count($blockers);
        $warningCount = count($warnings);
        $infoCount = count($infos);
        $kapanabilirMi = $muhurState !== 'MUHURLENDI' && $blockerCount === 0;

        $candidateCounts = self::fetchCandidateStateCounts($pdo, $subeId, $donem);
        $notificationCounts = self::aggregateNotificationCounts($blockers, $warnings, $infos);
        $puantajCounts = self::fetchPuantajCounts($pdo, $subeId, $yil, $ay);
        $financeReadiness = self::fetchFinanceReadiness($pdo, $subeId, $donem);

        $hashPayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem_state' => $donemState,
            'muhur_state' => $muhurState,
            'blockers' => self::codesWithCounts($blockers),
            'warnings' => self::codesWithCounts($warnings),
            'candidate_state_counts' => $candidateCounts,
            'notification_chain_counts' => $notificationCounts,
            'puantaj_counts' => $puantajCounts,
            'finance_readiness' => $financeReadiness,
        ];
        $preflightHash = hash('sha256', json_encode(self::canonicalize($hashPayload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'sube' => $sube,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => $donem,
            'donem_state' => $donemState,
            'muhur_state' => $muhurState,
            'muhur_id' => $muhurId,
            'kapanabilir_mi' => $kapanabilirMi,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
            'info_count' => $infoCount,
            'kategori_sayaclari' => self::categoryCounts($blockers, $warnings, $infos),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'infos' => $infos,
            'candidate_state_counts' => $candidateCounts,
            'notification_chain_counts' => $notificationCounts,
            'puantaj_counts' => $puantajCounts,
            'finance_readiness' => $financeReadiness,
            'preflight_hash' => $preflightHash,
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
        ];
    }

    /** @param array<int, int> $amirIds @param array<string, mixed> $filters */
    private static function evaluateNotificationBlockers(PDO $pdo, $subeId, $donem, $ayBaslangic, $ayBitis, array $amirIds, array $filters)
    {
        $issues = [];
        $draftIds = [];
        $weeklyIncomplete = 0;
        $monthlyIncomplete = 0;
        $gyIncomplete = 0;

        foreach ($amirIds as $amirId) {
            $context = BildirimDonemContextService::buildMonthContext($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $filters);
            $counts = $context['counts'];
            if ($counts['taslak'] > 0 || $counts['duzeltme_istendi'] > 0) {
                $rows = BildirimDonemContextService::fetchBildirimler($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $filters);
                foreach ($rows as $row) {
                    $state = strtoupper((string) $row['state']);
                    if ($state === 'TASLAK' || $state === 'DUZELTME_ISTENDI') {
                        $draftIds[] = (int) $row['id'];
                    }
                }
            }
            if ($counts['gonderildi'] > 0 || $counts['eksik_hafta'] > 0) {
                $weeklyIncomplete += $counts['gonderildi'] + $counts['eksik_hafta'];
            }

            $aylik = BildirimDonemContextService::fetchAylikOnay($pdo, $subeId, $amirId, $donem);
            if ($counts['toplam_bildirim'] > 0 && (!$aylik || strtoupper((string) $aylik['state']) !== 'TAMAMLANDI')) {
                $monthlyIncomplete++;
            }

            if ($aylik && strtoupper((string) $aylik['state']) === 'TAMAMLANDI') {
                $gy = BildirimDonemContextService::fetchGyOnay($pdo, $subeId, $amirId, $donem);
                if (!$gy || strtoupper((string) $gy['state']) !== 'TAMAMLANDI') {
                    $gyIncomplete++;
                }
            }
        }

        $draftIds = array_values(array_unique($draftIds));
        if (count($draftIds) > 0) {
            $issues[] = self::issue(
                'NOTIF_DRAFT_OR_CORRECTION',
                self::SEVERITY_BLOCKER,
                'bildirim',
                'Taslak veya duzeltme bekleyen bildirim',
                'Ayda taslak veya duzeltme bekleyen bildirim kaydi var.',
                count($draftIds),
                'BIRIM_AMIRI',
                '/bildirimler',
                'bildirimler.view',
                array_slice($draftIds, 0, 50),
                ['birim_amiri_ids' => $amirIds]
            );
        }
        if ($weeklyIncomplete > 0) {
            $issues[] = self::issue(
                'NOTIF_WEEKLY_INCOMPLETE',
                self::SEVERITY_BLOCKER,
                'bildirim',
                'Haftalik mutabakat eksik',
                'Gonderilmis fakat mutabakata alinmamis bildirim veya eksik haftalik mutabakat var.',
                $weeklyIncomplete,
                'BIRIM_AMIRI',
                '/bildirimler',
                'haftalik_mutabakat.view',
                [],
                ['birim_amiri_ids' => $amirIds]
            );
        }
        if ($monthlyIncomplete > 0) {
            $issues[] = self::issue(
                'NOTIF_MONTHLY_INCOMPLETE',
                self::SEVERITY_BLOCKER,
                'bildirim',
                'Aylik bildirim onayi eksik',
                'Tamamlanmamis aylik bildirim onayi var.',
                $monthlyIncomplete,
                'BIRIM_AMIRI',
                '/bildirimler',
                'aylik_bildirim_onayi.view',
                [],
                ['birim_amiri_ids' => $amirIds]
            );
        }
        if ($gyIncomplete > 0) {
            $issues[] = self::issue(
                'NOTIF_GY_INCOMPLETE',
                self::SEVERITY_BLOCKER,
                'bildirim',
                'Genel Yonetici ust onayi eksik',
                'Tamamlanmis aylik onay icin Genel Yonetici ust onayi eksik.',
                $gyIncomplete,
                'GENEL_YONETICI',
                '/yonetim',
                'genel_yonetici_bildirim_onayi.view',
                [],
                ['birim_amiri_ids' => $amirIds]
            );
        }

        return $issues;
    }

    /** @param array<int, int> $amirIds */
    private static function evaluateCandidateBlockers(PDO $pdo, $subeId, $donem, array $amirIds)
    {
        $issues = [];
        $hazir = self::countCandidatesByState($pdo, $subeId, $donem, 'HAZIR');
        $inceleme = self::countCandidatesByState($pdo, $subeId, $donem, 'INCELEME_GEREKLI');

        foreach ($amirIds as $amirId) {
            $gy = BildirimDonemContextService::fetchGyOnay($pdo, $subeId, $amirId, $donem);
            if (!$gy || strtoupper((string) $gy['state']) !== 'TAMAMLANDI') {
                continue;
            }
            $sourceCount = BildirimDonemContextService::countEligibleGySources($pdo, (int) $gy['id']);
            $candidateCount = self::countCandidatesForGy($pdo, (int) $gy['id']);
            if ($sourceCount > 0 && $candidateCount === 0) {
                $issues[] = self::issue(
                    'CANDIDATE_GENERATION_MISSING',
                    self::SEVERITY_BLOCKER,
                    'etki_adayi',
                    'Etki adayi uretilmemis',
                    'Genel Yonetici onayi tamam ancak etki adayi uretilmemis.',
                    1,
                    'MUHASEBE',
                    '/puantaj',
                    'puantaj.bildirim_etki.generate',
                    [(int) $gy['id']],
                    ['birim_amiri_user_id' => $amirId]
                );
            }
        }

        if ($hazir > 0) {
            $issues[] = self::issue(
                'CANDIDATE_HAZIR_PENDING',
                self::SEVERITY_BLOCKER,
                'etki_adayi',
                'Hazir etki adayi bekliyor',
                'Uygulanmayi bekleyen HAZIR etki adayi var.',
                $hazir,
                'MUHASEBE',
                '/puantaj',
                'puantaj.bildirim_etki.apply',
                [],
                []
            );
        }
        if ($inceleme > 0) {
            $issues[] = self::issue(
                'CANDIDATE_INCELEME_PENDING',
                self::SEVERITY_BLOCKER,
                'etki_adayi',
                'Inceleme gerektiren aday',
                'Manuel inceleme bekleyen etki adayi var.',
                $inceleme,
                'MUHASEBE',
                '/puantaj',
                'puantaj.bildirim_etki.apply',
                [],
                []
            );
        }

        return $issues;
    }

    /** @param array<string, mixed> $filters */
    private static function evaluatePuantajBlockers(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters)
    {
        $where = [
            'p.sube_id = :sube_id',
            'gp.tarih BETWEEN :bas AND :bit',
            "gp.state <> 'MUHURLENDI'",
            "gp.kontrol_durumu = 'BEKLIYOR'",
        ];
        $params = ['sube_id' => $subeId, 'bas' => $ayBaslangic, 'bit' => $ayBitis];
        if (isset($filters['departman_id']) && (int) $filters['departman_id'] > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = 'gp.personel_id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }

        $stmt = $pdo->prepare(
            'SELECT gp.id FROM gunluk_puantaj gp
             INNER JOIN personeller p ON p.id = gp.personel_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY gp.id ASC LIMIT 100'
        );
        $stmt->execute($params);
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        if (count($ids) === 0) {
            return [];
        }

        return [self::issue(
            'PUANTAJ_CONTROL_PENDING',
            self::SEVERITY_BLOCKER,
            'puantaj',
            'Amir kontrolu bekleyen puantaj',
            'Kontrol durumu BEKLIYOR olan puantaj satirlari var.',
            count($ids),
            'BIRIM_AMIRI',
            '/puantaj',
            'puantaj.amir_kontrol',
            $ids,
            []
        )];
    }

    /** @param array<string, mixed> $filters */
    private static function evaluateWarnings(PDO $pdo, $subeId, $donem, $ayBaslangic, $ayBitis, array $filters, $hasNotificationActivity)
    {
        $warnings = [];

        $salaryIds = self::fetchSalaryMissingPersonelIds($pdo, $subeId, $filters);
        if (count($salaryIds) > 0) {
            $warnings[] = self::issue(
                'FINANCE_SALARY_MISSING',
                self::SEVERITY_WARNING,
                'finans',
                'Eksik maas bilgisi',
                'Aktif personelde maas bilgisi eksik.',
                count($salaryIds),
                'MUHASEBE',
                '/personeller',
                'personeller.detail.view',
                array_slice($salaryIds, 0, 50),
                []
            );
        }

        $noPuantajCount = self::countActivePersonelWithoutPuantaj($pdo, $subeId, $ayBaslangic, $ayBitis, $filters);
        if ($noPuantajCount > 0) {
            $warnings[] = self::issue(
                'PUANTAJ_DAY_ROW_MISSING',
                self::SEVERITY_WARNING,
                'puantaj',
                'Puantaj satiri olmayan personel',
                'Donemde hic puantaj satiri olmayan aktif personel var.',
                $noPuantajCount,
                'MUHASEBE',
                '/puantaj',
                'puantaj.view',
                [],
                []
            );
        }

        $warnings[] = self::issue(
            'FINANCE_OPEN_AFTER_SEAL_RISK',
            self::SEVERITY_WARNING,
            'finans',
            'Finans kayitlari muhur sonrasi acik',
            'Finans adaylari puantaj muhuru ile otomatik kilitlenmez.',
            1,
            'MUHASEBE',
            '/finans',
            'finans.view',
            [],
            []
        );

        if (self::legacyAylikOzetOpen($pdo, $donem, $subeId)) {
            $warnings[] = self::issue(
                'LEGACY_AYLIK_OZET_OPEN',
                self::SEVERITY_WARNING,
                'legacy',
                'Legacy aylik ozet acik',
                'Legacy aylik ozet kapanisi canonical puantaj muhurunden bagimsizdir.',
                1,
                'GENEL_YONETICI',
                '/raporlar',
                'aylik-ozet.view',
                [],
                []
            );
        }

        $manualCount = self::countManualPuantajWithoutNote($pdo, $subeId, $ayBaslangic, $ayBitis, $filters);
        if ($manualCount > 0) {
            $warnings[] = self::issue(
                'PUANTAJ_MANUAL_NO_NOTE',
                self::SEVERITY_WARNING,
                'puantaj',
                'Aciklamasiz manuel puantaj',
                'Manuel kaynakli aciklamasiz puantaj satiri var.',
                $manualCount,
                'MUHASEBE',
                '/puantaj',
                'puantaj.view',
                [],
                []
            );
        }

        return $warnings;
    }

    /** @param array<string, mixed> $filters */
    private static function buildInfoMetrics(PDO $pdo, $subeId, $donem, $ayBaslangic, $ayBitis, array $filters, $restrictAmirId)
    {
        $counts = self::fetchCandidateStateCounts($pdo, $subeId, $donem);
        $infos = [];
        $infos[] = self::issue('CANDIDATE_APPLIED_COUNT', self::SEVERITY_INFO, 'etki_adayi', 'Uygulanan aday', 'Uygulanan etki adayi sayisi.', (int) ($counts['UYGULANDI'] ?? 0), 'MUHASEBE', '/puantaj', 'puantaj.bildirim_etki.view', [], []);
        $infos[] = self::issue('CANDIDATE_DISMISSED_COUNT', self::SEVERITY_INFO, 'etki_adayi', 'Yok sayilan aday', 'Yok sayilan etki adayi sayisi.', (int) ($counts['YOK_SAYILDI'] ?? 0), 'MUHASEBE', '/puantaj', 'puantaj.bildirim_etki.view', [], []);
        $infos[] = self::issue('PERIOD_NOTIFICATION_COUNT', self::SEVERITY_INFO, 'bildirim', 'Bildirim sayisi', 'Donem bildirim kaydi sayisi.', self::countNotifications($pdo, $subeId, $ayBaslangic, $ayBitis, $filters, $restrictAmirId), 'MUHASEBE', '/bildirimler', 'bildirimler.view', [], []);
        $puantajCounts = self::fetchPuantajCounts($pdo, $subeId, (int) substr($donem, 0, 4), (int) substr($donem, 5, 2));
        $infos[] = self::issue('PERIOD_PUANTAJ_COUNT', self::SEVERITY_INFO, 'puantaj', 'Puantaj satiri', 'Donem puantaj satir sayisi.', (int) ($puantajCounts['toplam_satir'] ?? 0), 'MUHASEBE', '/puantaj', 'puantaj.view', [], []);

        return $infos;
    }

    /** @param array<int, mixed> $recordIds @param array<string, mixed> $metadata */
    private static function issue($code, $severity, $domain, $title, $message, $count, $ownerRole, $actionRoute, $actionPermission, array $recordIds, array $metadata)
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'domain' => $domain,
            'title' => $title,
            'message' => $message,
            'count' => $count,
            'owner_role' => $ownerRole,
            'action_route' => $actionRoute,
            'action_permission' => $actionPermission,
            'record_ids' => $recordIds,
            'metadata' => $metadata,
        ];
    }

    private static function findMonthlySeal(PDO $pdo, $subeId, $yil, $ay)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM puantaj_aylik_muhurleri WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay LIMIT 1'
        );
        $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function fetchSube(PDO $pdo, $subeId)
    {
        $stmt = $pdo->prepare('SELECT id, kod, ad FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['id' => $subeId, 'kod' => null, 'ad' => null];
        }

        return ['id' => (int) $row['id'], 'kod' => (string) $row['kod'], 'ad' => (string) $row['ad']];
    }

    private static function fetchCandidateStateCounts(PDO $pdo, $subeId, $donem)
    {
        $stmt = $pdo->prepare(
            'SELECT state, COUNT(*) AS cnt FROM onayli_bildirim_puantaj_etki_adaylari
             WHERE sube_id = :sube_id AND ay = :ay GROUP BY state'
        );
        $stmt->execute(['sube_id' => $subeId, 'ay' => $donem]);
        $out = ['HAZIR' => 0, 'INCELEME_GEREKLI' => 0, 'UYGULANDI' => 0, 'YOK_SAYILDI' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['state']] = (int) $row['cnt'];
        }

        return $out;
    }

    private static function countCandidatesByState(PDO $pdo, $subeId, $donem, $state)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM onayli_bildirim_puantaj_etki_adaylari
             WHERE sube_id = :sube_id AND ay = :ay AND state = :state'
        );
        $stmt->execute(['sube_id' => $subeId, 'ay' => $donem, 'state' => $state]);

        return (int) $stmt->fetchColumn();
    }

    private static function countCandidatesForGy(PDO $pdo, $gyId)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM onayli_bildirim_puantaj_etki_adaylari WHERE genel_yonetici_bildirim_onayi_id = :gy_id'
        );
        $stmt->execute(['gy_id' => $gyId]);

        return (int) $stmt->fetchColumn();
    }

    private static function fetchPuantajCounts(PDO $pdo, $subeId, $yil, $ay)
    {
        $firstDay = sprintf('%04d-%02d-01', $yil, $ay);
        $lastDay = date('Y-m-t', strtotime($firstDay));
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS toplam,
                    SUM(CASE WHEN gp.kontrol_durumu = \'BEKLIYOR\' THEN 1 ELSE 0 END) AS bekleyen
             FROM gunluk_puantaj gp
             INNER JOIN personeller p ON p.id = gp.personel_id
             WHERE p.sube_id = :sube_id AND gp.tarih BETWEEN :bas AND :bit'
        );
        $stmt->execute(['sube_id' => $subeId, 'bas' => $firstDay, 'bit' => $lastDay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['toplam' => 0, 'bekleyen' => 0];

        return ['toplam_satir' => (int) $row['toplam'], 'kontrol_bekleyen' => (int) $row['bekleyen']];
    }

    private static function fetchFinanceReadiness(PDO $pdo, $subeId, $donem)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM personeller WHERE sube_id = :sube_id AND aktif_durum = \'AKTIF\'
             AND (maas_tutari IS NULL OR maas_tutari <= 0)'
        );
        $stmt->execute(['sube_id' => $subeId]);
        $missingSalary = (int) $stmt->fetchColumn();

        return ['eksik_maas_sayisi' => $missingSalary, 'finans_kayit_sayisi' => self::countFinansKayit($pdo, $subeId, $donem)];
    }

    private static function countFinansKayit(PDO $pdo, $subeId, $donem)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ek_odeme_kesinti fk
             INNER JOIN personeller p ON p.id = fk.personel_id
             WHERE p.sube_id = :sube_id AND fk.donem = :donem AND fk.state = \'AKTIF\''
        );
        $stmt->execute(['sube_id' => $subeId, 'donem' => $donem]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $filters */
    private static function fetchSalaryMissingPersonelIds(PDO $pdo, $subeId, array $filters)
    {
        $where = ['sube_id = :sube_id', "aktif_durum = 'AKTIF'", '(maas_tutari IS NULL OR maas_tutari <= 0)'];
        $params = ['sube_id' => $subeId];
        if (isset($filters['departman_id']) && (int) $filters['departman_id'] > 0) {
            $where[] = 'departman_id = :departman_id';
            $params['departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = 'id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        return array_map(static function ($row) {
            return (int) $row['id'];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $filters */
    private static function countActivePersonelWithoutPuantaj(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters)
    {
        $where = ['p.sube_id = :sube_id', "p.aktif_durum = 'AKTIF'"];
        $params = ['sube_id' => $subeId, 'bas' => $ayBaslangic, 'bit' => $ayBitis];
        if (isset($filters['departman_id']) && (int) $filters['departman_id'] > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = 'p.id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM personeller p
             WHERE ' . implode(' AND ', $where) . '
               AND NOT EXISTS (
                 SELECT 1 FROM gunluk_puantaj gp
                 WHERE gp.personel_id = p.id AND gp.tarih BETWEEN :bas AND :bit
               )'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $filters */
    private static function countManualPuantajWithoutNote(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters)
    {
        $where = [
            'p.sube_id = :sube_id',
            'gp.tarih BETWEEN :bas AND :bit',
            "gp.kaynak = 'MANUEL'",
            '(gp.aciklama IS NULL OR TRIM(gp.aciklama) = \'\')',
        ];
        $params = ['sube_id' => $subeId, 'bas' => $ayBaslangic, 'bit' => $ayBitis];
        if (isset($filters['departman_id']) && (int) $filters['departman_id'] > 0) {
            $where[] = 'p.departman_id = :departman_id';
            $params['departman_id'] = (int) $filters['departman_id'];
        }
        if (isset($filters['personel_id']) && (int) $filters['personel_id'] > 0) {
            $where[] = 'gp.personel_id = :personel_id';
            $params['personel_id'] = (int) $filters['personel_id'];
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM gunluk_puantaj gp INNER JOIN personeller p ON p.id = gp.personel_id WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private static function legacyAylikOzetOpen(PDO $pdo, $donem, $subeId)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM aylik_ozet_satirlari
             WHERE ay = :ay AND sube_id = :sube_id AND kapanis_durumu <> \'KAPANDI\''
        );
        $stmt->execute(['ay' => $donem, 'sube_id' => $subeId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $filters */
    private static function countNotifications(PDO $pdo, $subeId, $ayBaslangic, $ayBitis, array $filters, $restrictAmirId)
    {
        $amirId = $restrictAmirId !== null ? (int) $restrictAmirId : null;
        $rows = BildirimDonemContextService::fetchBildirimler($pdo, $subeId, $amirId, $ayBaslangic, $ayBitis, $filters);
        $count = 0;
        foreach ($rows as $row) {
            if (strtoupper((string) $row['state']) !== 'IPTAL') {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<int, array<string, mixed>> $blockers @param array<int, array<string, mixed>> $warnings @param array<int, array<string, mixed>> $infos */
    private static function categoryCounts(array $blockers, array $warnings, array $infos)
    {
        $cats = [];
        foreach (array_merge($blockers, $warnings, $infos) as $issue) {
            $domain = (string) $issue['domain'];
            if (!isset($cats[$domain])) {
                $cats[$domain] = 0;
            }
            $cats[$domain] += (int) $issue['count'];
        }

        return $cats;
    }

    /** @param array<int, array<string, mixed>> $issues */
    private static function codesWithCounts(array $issues)
    {
        $out = [];
        foreach ($issues as $issue) {
            $out[(string) $issue['code']] = (int) $issue['count'];
        }
        ksort($out);

        return $out;
    }

    /** @param array<int, array<string, mixed>> $blockers @param array<int, array<string, mixed>> $warnings @param array<int, array<string, mixed>> $infos */
    private static function aggregateNotificationCounts(array $blockers, array $warnings, array $infos)
    {
        $sum = 0;
        foreach (array_merge($blockers, $warnings, $infos) as $issue) {
            if ((string) $issue['domain'] === 'bildirim') {
                $sum += (int) $issue['count'];
            }
        }

        return ['toplam' => $sum];
    }

    /** @param mixed $value @return mixed */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === range(0, count($value) - 1)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
