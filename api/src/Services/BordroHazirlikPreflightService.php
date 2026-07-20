<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use PDO;

/**
 * S82/S83 actionable bordro hazirlik preflight + business-data readiness owner'i.
 */
class BordroHazirlikPreflightService
{
    public const CONTRACT_VERSION = 'S83_BORDRO_BUSINESS_DATA_READINESS_V1';

    /** @return array<string, mixed> */
    public static function build(PDO $pdo, $subeId, $yil, $ay)
    {
        $snapshotPreflight = MaasHesaplamaSnapshotService::buildPreflight($pdo, (int) $subeId, (int) $yil, (int) $ay);
        $items = self::enrichItems($snapshotPreflight['items'] ?? []);
        $existingSnapshot = $snapshotPreflight['existing_snapshot'] ?? null;
        $calcPreflight = null;
        if ($existingSnapshot && isset($existingSnapshot['id'])) {
            $calcPreflight = MaasHesaplamaAdayService::buildCalculationPreflight($pdo, (int) $existingSnapshot['id']);
            $items = array_merge($items, self::enrichItems($calcPreflight['items'] ?? []));
        }

        $donemBaslangic = (string) $snapshotPreflight['donem_baslangic'];
        $donemBitis = (string) $snapshotPreflight['donem_bitis'];
        $policy = SirketCalismaPolitikasiService::resolveApprovedForPeriod($pdo, $donemBaslangic, $donemBitis);
        if ($policy['politika'] === null) {
            $items[] = self::actionableIssue(
                'BLOCKER',
                'BUSINESS_POLICY_REQUIRED',
                'Onaylı şirket çalışma politikası bulunamadı.',
                'sirket_politikasi',
                null,
                null,
                [],
                '/raporlar?panel=bordro-hazirlik&tab=politika'
            );
        } else {
            $missingPolicy = [];
            foreach (SirketCalismaPolitikasiCatalog::requiredCodes() as $code) {
                if (!isset($policy['degerler_by_code'][$code])) {
                    $missingPolicy[] = $code;
                }
            }
            if (count($missingPolicy) > 0) {
                $items[] = self::actionableIssue(
                    'BLOCKER',
                    'BUSINESS_POLICY_INCOMPLETE',
                    'Onaylı şirket politikasında eksik parametreler var.',
                    'sirket_politikasi',
                    (int) $policy['politika']['id'],
                    null,
                    ['eksik_kodlar' => $missingPolicy],
                    '/raporlar?panel=bordro-hazirlik&tab=politika'
                );
            } else {
                $items[] = self::actionableIssue(
                    'INFO',
                    'BUSINESS_POLICY_APPROVED',
                    'Onaylı şirket çalışma politikası mevcut.',
                    'sirket_politikasi',
                    (int) $policy['politika']['id'],
                    null,
                    ['policy_version_hash' => $policy['policy_version_hash']],
                    '/raporlar?panel=bordro-hazirlik&tab=politika'
                );
            }
        }

        $gyFinal = self::checkGenelYoneticiFinalApproval($pdo, (int) $subeId, (int) $yil, (int) $ay);
        if (!$gyFinal['tamam']) {
            $items[] = self::actionableIssue(
                'BLOCKER',
                'S81_GENEL_YONETICI_FINAL_ONAY_EKSIK',
                'Genel yönetici final onayı tamamlanmamış.',
                'onay_zinciri',
                null,
                null,
                $gyFinal,
                '/bildirimler'
            );
        } else {
            $items[] = self::actionableIssue(
                'INFO',
                'S81_GENEL_YONETICI_FINAL_ONAY_TAMAM',
                'Genel yönetici final onayı tamam.',
                'onay_zinciri',
                $gyFinal['genel_yonetici_bildirim_onayi_id'] ?? null,
                null,
                $gyFinal,
                '/bildirimler'
            );
        }

        $personelIds = array_map(static function (array $row) {
            return (int) $row['personel_id'];
        }, $snapshotPreflight['personel_summary'] ?? []);
        $projection = MaasHesaplamaCorrectionProjectionService::buildProjection(
            $pdo,
            (int) $subeId,
            $personelIds,
            $donemBaslangic,
            $donemBitis
        );
        foreach ($projection['blocker_items'] as $blocker) {
            $items[] = self::enrichItem($blocker);
        }
        if ($projection['active_count'] > 0) {
            $items[] = self::actionableIssue(
                'INFO',
                'CORRECTION_PROJECTION_READY',
                'Aktif correction projection hazır.',
                'correction',
                null,
                null,
                ['adet' => $projection['active_count']],
                '/revizyon-merkezi'
            );
        }

        $netMaasCount = self::countNetMaasEksikleri($pdo, (int) $subeId, $donemBitis, null);
        $devirCount = self::countDevirEksikleri($pdo, (int) $subeId, (int) $yil, (int) $ay);

        $blockerCount = self::countSeverity($items, 'BLOCKER');
        $warningCount = self::countSeverity($items, 'WARNING');
        $infoCount = self::countSeverity($items, 'INFO');
        $hesaplanabilir = $blockerCount === 0;

        $readinessDomains = self::buildReadinessDomains(
            $items,
            $gyFinal,
            $policy,
            $projection,
            $snapshotPreflight,
            $calcPreflight,
            $netMaasCount,
            $devirCount
        );
        $candidateGate = self::buildCandidateGate($hesaplanabilir, $items, $readinessDomains);

        return [
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'donem' => (string) $snapshotPreflight['donem'],
            'donem_baslangic' => $donemBaslangic,
            'donem_bitis' => $donemBitis,
            'hesaplanabilir_mi' => $hesaplanabilir,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
            'info_count' => $infoCount,
            'items' => $items,
            'readiness_domains' => $readinessDomains,
            'candidate_gate' => $candidateGate,
            'snapshot_preflight' => [
                'snapshot_olusturulabilir_mi' => (bool) ($snapshotPreflight['snapshot_olusturulabilir_mi'] ?? false),
                'existing_snapshot' => $existingSnapshot,
                'preflight_hash' => (string) ($snapshotPreflight['preflight_hash'] ?? ''),
            ],
            'calculation_preflight' => $calcPreflight ? MaasHesaplamaAdayService::publicPreflight($calcPreflight) : null,
            'policy_summary' => [
                'onayli_politika_id' => $policy['politika']['id'] ?? null,
                'policy_version_hash' => $policy['policy_version_hash'],
                'zorunlu_adet' => count(SirketCalismaPolitikasiCatalog::requiredCodes()),
            ],
            'correction_projection_hash' => $projection['projection_hash'],
            'mevzuat_kaynak' => 'mevzuat_parametreleri',
            'sirket_politikasi_kaynak' => 'sirket_calisma_politikalari',
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * Net maas / ucret hazirlik eksikleri (JOIN, N+1 yok).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function listNetMaasEksikleri(PDO $pdo, $subeId, $yil, $ay, $departmanId = null)
    {
        $donemBitis = sprintf('%04d-%02d-%02d', (int) $yil, (int) $ay, (int) cal_days_in_month(CAL_GREGORIAN, (int) $ay, (int) $yil));
        $donemBaslangic = sprintf('%04d-%02d-01', (int) $yil, (int) $ay);
        $sql = "SELECT p.id, p.ad, p.soyad, p.sicil_no, p.ise_giris_tarihi, p.maas_tutari,
                       s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi,
                       (SELECT MAX(s2.baslangic_tarihi)
                          FROM surecler s2
                         WHERE s2.personel_id = p.id AND s2.surec_turu = 'ISTEN_AYRILMA' AND s2.state = 'AKTIF'
                       ) AS isten_ayrilma_tarihi,
                       (SELECT COUNT(*) FROM personel_ucret_gecmisi u
                         WHERE u.personel_id = p.id AND u.state = 'AKTIF'
                           AND u.gecerlilik_baslangic <= :donem_bitis
                           AND (u.gecerlilik_bitis IS NULL OR u.gecerlilik_bitis >= p.ise_giris_tarihi)
                       ) AS ucret_kayit_sayisi
                FROM personeller p
                LEFT JOIN subeler s ON s.id = p.sube_id
                LEFT JOIN departmanlar d ON d.id = p.departman_id
                LEFT JOIN gorevler g ON g.id = p.gorev_id
                WHERE p.sube_id = :sube_id
                  AND p.aktif_durum = 'AKTIF'
                  AND p.ise_giris_tarihi <= :donem_bitis2";
        $params = [
            'sube_id' => (int) $subeId,
            'donem_bitis' => $donemBitis,
            'donem_bitis2' => $donemBitis,
        ];
        if ($departmanId !== null) {
            $sql .= ' AND p.departman_id = :departman_id';
            $params['departman_id'] = (int) $departmanId;
        }
        $sql .= ' ORDER BY p.ad ASC, p.soyad ASC';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $stmt = $pdo->prepare(
                "SELECT p.id, p.ad, p.soyad, p.sicil_no, p.ise_giris_tarihi, p.maas_tutari,
                        s.ad AS sube_adi, d.ad AS departman_adi, NULL AS gorev_adi,
                        NULL AS isten_ayrilma_tarihi,
                        (SELECT COUNT(*) FROM personel_ucret_gecmisi u
                          WHERE u.personel_id = p.id AND u.state = 'AKTIF') AS ucret_kayit_sayisi
                 FROM personeller p
                 LEFT JOIN subeler s ON s.id = p.sube_id
                 LEFT JOIN departmanlar d ON d.id = p.departman_id
                 WHERE p.sube_id = :sube_id AND p.aktif_durum = 'AKTIF'
                   AND p.ise_giris_tarihi <= :donem_bitis
                 ORDER BY p.ad ASC, p.soyad ASC"
            );
            $stmt->execute([
                'sube_id' => (int) $subeId,
                'donem_bitis' => $donemBitis,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($departmanId !== null) {
                $rows = array_values(array_filter($rows, static function (array $row) use ($departmanId) {
                    return isset($row['departman_id']) && (int) $row['departman_id'] === (int) $departmanId;
                }));
            }
        }

        $items = [];
        $excluded = PersonelBordroKapsamService::listExcludedPersonelIds(
            $pdo,
            (int) $subeId,
            $donemBaslangic,
            $donemBitis
        );
        foreach ($rows as $row) {
            $pid = (int) $row['id'];
            if (isset($excluded[$pid])) {
                continue;
            }
            $cikis = $row['isten_ayrilma_tarihi'] ?? null;
            if ($cikis !== null && (string) $cikis < $donemBaslangic) {
                continue;
            }
            $classified = self::classifyNetMaasDurumu($row);
            if ($classified['net_maas_durumu'] === null) {
                continue;
            }
            $items[] = [
                'ad_soyad' => trim((string) $row['ad'] . ' ' . (string) $row['soyad']),
                'sicil_no' => (string) $row['sicil_no'],
                'sube_adi' => (string) ($row['sube_adi'] ?? ''),
                'departman_adi' => (string) ($row['departman_adi'] ?? ''),
                'gorev_adi' => (string) ($row['gorev_adi'] ?? ''),
                'ise_giris_tarihi' => $row['ise_giris_tarihi'] !== null ? (string) $row['ise_giris_tarihi'] : null,
                'isten_ayrilma' => $cikis !== null ? (string) $cikis : null,
                'net_maas_durumu' => $classified['net_maas_durumu'],
                'legacy_maas_durumu' => $classified['legacy_maas_durumu'],
                'action_link' => '/personeller/' . (int) $row['id'] . '?tab=genel-bilgiler',
            ];
        }

        return ['items' => $items, 'total' => count($items)];
    }

    /** @return array{net_maas_durumu: string|null, legacy_maas_durumu: string} */
    public static function classifyNetMaasDurumu(array $row)
    {
        $raw = $row['maas_tutari'] ?? null;
        $ucretCount = (int) ($row['ucret_kayit_sayisi'] ?? 0);
        $legacy = 'YOK';
        if ($raw === null || $raw === '') {
            $legacy = 'NULL';
        } elseif ((float) $raw < 0) {
            $legacy = 'NEGATIF';
        } elseif ((float) $raw == 0.0) {
            $legacy = 'SIFIR';
        } else {
            $legacy = 'VAR';
        }

        if ($ucretCount > 0 && $legacy === 'VAR') {
            return ['net_maas_durumu' => null, 'legacy_maas_durumu' => $legacy];
        }
        if ($ucretCount > 0) {
            // History exists; card-level maas may still be odd but engine uses history.
            return ['net_maas_durumu' => null, 'legacy_maas_durumu' => $legacy];
        }

        if ($raw === null || $raw === '') {
            return ['net_maas_durumu' => 'NULL', 'legacy_maas_durumu' => $legacy];
        }
        if ((float) $raw < 0) {
            return ['net_maas_durumu' => 'NEGATIF', 'legacy_maas_durumu' => $legacy];
        }
        if ((float) $raw == 0.0) {
            return ['net_maas_durumu' => 'SIFIR', 'legacy_maas_durumu' => $legacy];
        }

        return ['net_maas_durumu' => 'LEGACY_ONLY', 'legacy_maas_durumu' => $legacy];
    }

    private static function countNetMaasEksikleri(PDO $pdo, $subeId, $donemBitis, $departmanId)
    {
        try {
            $list = self::listNetMaasEksikleri(
                $pdo,
                $subeId,
                (int) substr((string) $donemBitis, 0, 4),
                (int) substr((string) $donemBitis, 5, 2),
                $departmanId
            );

            return (int) $list['total'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function countDevirEksikleri(PDO $pdo, $subeId, $yil, $ay)
    {
        if ((int) $ay <= 1) {
            return 0;
        }
        try {
            $donemBaslangic = sprintf('%04d-%02d-01', (int) $yil, (int) $ay);
            $donemBitis = sprintf('%04d-%02d-%02d', (int) $yil, (int) $ay, (int) cal_days_in_month(CAL_GREGORIAN, (int) $ay, (int) $yil));
            $excluded = PersonelBordroKapsamService::listExcludedPersonelIds(
                $pdo,
                (int) $subeId,
                $donemBaslangic,
                $donemBitis
            );
            $stmt = $pdo->prepare(
                "SELECT p.id FROM personeller p
                 WHERE p.sube_id = :sube AND p.aktif_durum = 'AKTIF'
                   AND NOT EXISTS (
                     SELECT 1 FROM personel_bordro_devirleri d
                     WHERE d.personel_id = p.id AND d.yil = :yil AND d.ay = :ay AND d.state = 'AKTIF'
                   )"
            );
            $stmt->execute(['sube' => (int) $subeId, 'yil' => (int) $yil, 'ay' => (int) $ay]);
            $count = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($excluded[(int) $row['id']])) {
                    continue;
                }
                $count++;
            }

            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $gyFinal
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $projection
     * @param array<string, mixed> $snapshotPreflight
     * @param array<string, mixed>|null $calcPreflight
     * @return array<int, array<string, mixed>>
     */
    private static function buildReadinessDomains(
        array $items,
        array $gyFinal,
        array $policy,
        array $projection,
        array $snapshotPreflight,
        $calcPreflight,
        $netMaasCount,
        $devirCount
    ) {
        $domains = [];

        $s81Blockers = self::filterCodes($items, ['S81_GENEL_YONETICI_FINAL_ONAY_EKSIK']);
        $s81Status = $gyFinal['tamam'] ? 'HAZIR' : 'BLOKE';
        $domains[] = self::domain(
            's81_final_onay',
            'S81 Final Onay',
            $s81Status,
            count($s81Blockers),
            0,
            $gyFinal['tamam']
                ? 'Genel yönetici final onayı tamam.'
                : ('Final onay eksik: ' . (string) ($gyFinal['neden'] ?? 'ONAY_KAYDI_YOK')),
            '/bildirimler',
            ['S81_GENEL_YONETICI_FINAL_ONAY_EKSIK']
        );

        $mevzuatCodes = ['LEGAL_PARAMETER_REQUIRED_MISSING', 'LEGAL_PARAMETER_SET_EMPTY', 'LEGAL_PARAMETER_OVERLAP_DATA_ERROR', 'LEGAL_PARAMETER_COVERAGE_PARTIAL'];
        $mevzuatItems = self::filterCodes($items, $mevzuatCodes);
        $mevzuatBlockers = self::filterSeverity($mevzuatItems, 'BLOCKER');
        $mevzuatStatus = count($mevzuatBlockers) > 0 ? 'BLOKE' : (count($mevzuatItems) > 0 ? 'İNCELEME_GEREKLİ' : 'HAZIR');
        $mevzuatEksikKodlar = [];
        foreach ($mevzuatItems as $mi) {
            $meta = is_array($mi['metadata'] ?? null) ? $mi['metadata'] : [];
            if (isset($meta['parametre_kodu']) && (string) $meta['parametre_kodu'] !== '') {
                $mevzuatEksikKodlar[] = (string) $meta['parametre_kodu'];
            }
            if (isset($meta['eksik_kodlar']) && is_array($meta['eksik_kodlar'])) {
                foreach ($meta['eksik_kodlar'] as $kod) {
                    if ((string) $kod !== '') {
                        $mevzuatEksikKodlar[] = (string) $kod;
                    }
                }
            }
            if (isset($mi['eksik_kodlar']) && is_array($mi['eksik_kodlar'])) {
                foreach ($mi['eksik_kodlar'] as $kod) {
                    if ((string) $kod !== '') {
                        $mevzuatEksikKodlar[] = (string) $kod;
                    }
                }
            }
        }
        $domains[] = self::domain(
            'mevzuat_parametreleri',
            'Mevzuat Parametreleri',
            $mevzuatStatus,
            count($mevzuatEksikKodlar) > 0 ? count(array_unique($mevzuatEksikKodlar)) : count($mevzuatItems),
            0,
            count($mevzuatItems) === 0
                ? 'Mevzuat parametreleri preflight açısından sorun göstermiyor.'
                : 'Mevzuat parametrelerinde eksik veya uyarı var; yönetim panelinden kontrol edin.',
            '/yonetim-paneli?tab=mevzuat',
            array_values(array_unique(array_map(static function (array $i) {
                return (string) $i['code'];
            }, $mevzuatItems))),
            $mevzuatEksikKodlar
        );

        $policyCodes = ['BUSINESS_POLICY_REQUIRED', 'BUSINESS_POLICY_INCOMPLETE', 'COMPANY_POLICY_MISSING'];
        $policyItems = self::filterCodes($items, $policyCodes);
        $policyOk = $policy['politika'] !== null && count($policyItems) === 0;
        $eksikKodlar = [];
        foreach ($policyItems as $pi) {
            if (isset($pi['metadata']['eksik_kodlar']) && is_array($pi['metadata']['eksik_kodlar'])) {
                $eksikKodlar = array_merge($eksikKodlar, $pi['metadata']['eksik_kodlar']);
            }
        }
        $domains[] = self::domain(
            'sirket_calisma_politikasi',
            'Şirket Çalışma Politikası',
            $policyOk ? 'HAZIR' : 'BLOKE',
            count($eksikKodlar) > 0 ? count($eksikKodlar) : count($policyItems),
            0,
            $policyOk
                ? 'Onaylı şirket çalışma politikası mevcut.'
                : 'Onaylı şirket politikası yok veya zorunlu parametreler eksik.',
            '/raporlar?panel=bordro-hazirlik&tab=politika',
            array_values(array_unique(array_map(static function (array $i) {
                return (string) $i['code'];
            }, $policyItems))),
            $eksikKodlar
        );

        $salaryCodes = ['SALARY_MISSING', 'SALARY_SEGMENT_INVALID', 'SALARY_TYPE_UNSUPPORTED', 'SALARY_OVERLAP_DATA_ERROR', 'SALARY_COVERAGE_GAP', 'NET_SALARY_REQUIRED', 'LEGACY_SALARY_FALLBACK_USED'];
        $salaryItems = self::filterCodes($items, $salaryCodes);
        $salaryBlockers = self::filterSeverity($salaryItems, 'BLOCKER');
        $salaryPersonel = self::uniquePersonelCount($salaryItems);
        $netCount = max((int) $netMaasCount, count($salaryBlockers));
        $salaryStatus = count($salaryBlockers) > 0 || $netCount > 0
            ? (count($salaryBlockers) > 0 ? 'BLOKE' : 'EKSİK')
            : (count(self::filterSeverity($salaryItems, 'WARNING')) > 0 ? 'İNCELEME_GEREKLİ' : 'HAZIR');
        $domains[] = self::domain(
            'net_maas',
            'Net Maaş / Ücret',
            $salaryStatus,
            $netCount,
            max($salaryPersonel, $netCount),
            $salaryStatus === 'HAZIR'
                ? 'Personel net maaş / ücret kayıtları hazır.'
                : 'Net maaş veya ücret geçmişi eksik personeller var; personel kartından tamamlayın.',
            '/raporlar?panel=bordro-hazirlik&tab=veri-hazirlik',
            array_values(array_unique(array_map(static function (array $i) {
                return (string) $i['code'];
            }, $salaryItems)))
        );

        $devirCodes = ['PERSONNEL_CARRYOVER_MISSING', 'PERSONNEL_CARRYOVER_INVALID'];
        $devirItems = self::filterCodes($items, $devirCodes);
        $devirEksik = max((int) $devirCount, count($devirItems));
        $devirStatus = $devirEksik > 0 ? (count(self::filterSeverity($devirItems, 'BLOCKER')) > 0 ? 'BLOKE' : 'EKSİK') : 'HAZIR';
        $domains[] = self::domain(
            'bordro_devir',
            'Bordro Devir',
            $devirStatus,
            $devirEksik,
            $devirEksik,
            $devirStatus === 'HAZIR'
                ? 'Yasal devir kayıtları tamam.'
                : 'Önceki dönem kumulatif vergi/SGK devirleri eksik; şablon ile yükleyin.',
            '/raporlar?panel=bordro-hazirlik&tab=devir',
            array_values(array_unique(array_map(static function (array $i) {
                return (string) $i['code'];
            }, $devirItems)))
        );

        $corrCodes = ['CORRECTION_SOURCE_CONFLICT', 'CORRECTION_PROJECTION_READY'];
        $corrItems = self::filterCodes($items, $corrCodes);
        $corrBlockers = self::filterSeverity($corrItems, 'BLOCKER');
        $corrActive = (int) ($projection['active_count'] ?? 0);
        $corrStatus = count($corrBlockers) > 0 ? 'BLOKE' : ($corrActive > 0 ? 'İNCELEME_GEREKLİ' : 'HAZIR');
        $domains[] = self::domain(
            'acik_revizyon_correction',
            'Açık Revizyon / Correction',
            $corrStatus,
            count($corrBlockers) + (count($projection['blocker_items'] ?? [])),
            $corrActive,
            $corrStatus === 'HAZIR'
                ? 'Açık correction çatışması yok.'
                : 'Aktif correction veya kaynak çatışması var; revizyon merkezinden kontrol edin.',
            '/revizyon-merkezi',
            array_values(array_unique(array_map(static function (array $i) {
                return (string) $i['code'];
            }, array_merge($corrItems, $projection['blocker_items'] ?? []))))
        );

        $etkiCodes = ['UNRESOLVED_IMPACT_CANDIDATE', 'FINANCE_CONFLICT_UNRESOLVED', 'CANDIDATE_PENDING', 'ETKI_ADAY_PENDING', 'CANDIDATE_HAZIR_PENDING', 'CANDIDATE_INCELEME_PENDING'];
        $etkiItems = self::filterCodes($items, $etkiCodes);
        $etkiBlockers = self::filterSeverity($etkiItems, 'BLOCKER');
        $etkiStatus = count($etkiBlockers) > 0 ? 'BLOKE' : (count($etkiItems) > 0 ? 'İNCELEME_GEREKLİ' : 'HAZIR');
        $domains[] = self::domain(
            'puantaj_etki_adaylari',
            'Puantaj Etki Adayları',
            $etkiStatus,
            count($etkiItems),
            self::uniquePersonelCount($etkiItems),
            $etkiStatus === 'HAZIR'
                ? 'Çözülmemiş etki adayı yok.'
                : 'Çözülmemiş puantaj etki adayları var; etki adayı panelinden kapatın.',
            '/raporlar?panel=etki-adayi',
            array_values(array_unique(array_map(static function (array $i) {
                return (string) $i['code'];
            }, $etkiItems)))
        );

        $scopeItems = self::filterCodes($items, ['PAYROLL_SCOPE_EXCLUDED']);
        $domains[] = self::domain(
            'bordro_kapsam',
            'Bordro Kapsam',
            count($scopeItems) > 0 ? 'BİLGİ' : 'HAZIR',
            0,
            self::uniquePersonelCount($scopeItems),
            count($scopeItems) > 0
                ? 'Dönemde HARIC kapsamlı personel var; yeni snapshot setine alınmaz (bilgi).'
                : 'Dönemde HARIC kapsam kaydı yok.',
            '/raporlar?panel=bordro-hazirlik&tab=veri-hazirlik',
            ['PAYROLL_SCOPE_EXCLUDED']
        );

        $adayCodes = ['PERIOD_NOT_SEALED', 'PERIOD_SEAL_INVALID'];
        $adayItems = self::filterCodes($items, $adayCodes);
        $snapshotOk = (bool) ($snapshotPreflight['snapshot_olusturulabilir_mi'] ?? false)
            || !empty($snapshotPreflight['existing_snapshot']);
        $calcOk = $calcPreflight === null || (bool) ($calcPreflight['hesaplanabilir_mi'] ?? false);
        $adayBlockers = self::filterSeverity($adayItems, 'BLOCKER');
        $adayStatus = count($adayBlockers) > 0 || !$snapshotOk || !$calcOk ? 'BLOKE' : 'HAZIR';
        $domains[] = self::domain(
            'maas_adayi_hazirlik',
            'Maaş Adayı Hazırlık',
            $adayStatus,
            count($adayBlockers),
            0,
            $adayStatus === 'HAZIR'
                ? 'Maaş adayı üretimi için dönem hazır.'
                : 'Dönem mühürü veya hesaplama ön koşulları eksik.',
            '/raporlar?panel=bordro-hazirlik&tab=hesaplama',
            array_values(array_unique(array_map(static function (array $i) {
                return (string) $i['code'];
            }, $adayItems)))
        );

        return $domains;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $domains
     * @return array<string, mixed>
     */
    private static function buildCandidateGate($hesaplanabilir, array $items, array $domains)
    {
        $checks = [];
        $nedenler = [];
        foreach ($domains as $domain) {
            $ok = (string) ($domain['status'] ?? '') === 'HAZIR';
            $checks[] = [
                'key' => (string) $domain['key'],
                'ok' => $ok,
                'mesaj' => (string) $domain['aciklama'],
            ];
            if (!$ok && (string) $domain['status'] === 'BLOKE') {
                $nedenler[] = (string) $domain['label'] . ': ' . (string) $domain['aciklama'];
            }
        }
        $blockerItems = self::filterSeverity($items, 'BLOCKER');
        foreach ($blockerItems as $blocker) {
            $msg = (string) ($blocker['kullanici_mesaji'] ?? $blocker['message'] ?? $blocker['code']);
            if (!in_array($msg, $nedenler, true)) {
                $nedenler[] = $msg;
            }
        }

        $aktif = (bool) $hesaplanabilir;

        return [
            'aktif' => $aktif,
            'disabled_nedenleri' => $aktif ? [] : array_values(array_unique($nedenler)),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<int, string> $blockerCodes
     * @param array<int, string> $eksikKodlar
     * @return array<string, mixed>
     */
    private static function domain(
        $key,
        $label,
        $status,
        $eksikKayit,
        $etkilenenPersonel,
        $aciklama,
        $actionLink,
        array $blockerCodes = [],
        array $eksikKodlar = []
    ) {
        $out = [
            'key' => (string) $key,
            'label' => (string) $label,
            'status' => (string) $status,
            'eksik_kayit_sayisi' => (int) $eksikKayit,
            'etkilenen_personel_sayisi' => (int) $etkilenenPersonel,
            'aciklama' => (string) $aciklama,
            'action_link' => (string) $actionLink,
            'blocker_codes' => array_values($blockerCodes),
        ];
        if (count($eksikKodlar) > 0) {
            $out['eksik_kodlar'] = array_values(array_unique($eksikKodlar));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function checkGenelYoneticiFinalApproval(PDO $pdo, $subeId, $yil, $ay)
    {
        $donem = sprintf('%04d-%02d', (int) $yil, (int) $ay);
        try {
            // Canonical S81 schema: state=TAMAMLANDI, timestamp=onaylandi_at (migration 008).
            $stmt = $pdo->prepare(
                "SELECT gy.id, gy.state, gy.onaylandi_at
                 FROM genel_yonetici_bildirim_onaylari gy
                 WHERE gy.sube_id = :sube_id AND gy.ay = :ay AND gy.state = 'TAMAMLANDI'
                 ORDER BY gy.onaylandi_at DESC, gy.id DESC LIMIT 1"
            );
            $stmt->execute(['sube_id' => (int) $subeId, 'ay' => $donem]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['tamam' => false, 'neden' => 'ONAY_KAYDI_YOK'];
            }

            return [
                'tamam' => true,
                'genel_yonetici_bildirim_onayi_id' => (int) $row['id'],
                'onay_zamani' => (string) ($row['onaylandi_at'] ?? ''),
                'neden' => null,
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $tableMissing = stripos($msg, "doesn't exist") !== false
                || stripos($msg, 'no such table') !== false
                || stripos($msg, 'Base table or view not found') !== false
                || stripos($msg, '42S02') !== false;
            return [
                'tamam' => false,
                'neden' => $tableMissing ? 'ONAY_TABLOSU_YOK' : 'ONAY_KAYDI_YOK',
            ];
        }
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private static function enrichItems(array $items)
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = self::enrichItem($item);
        }

        return $out;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function enrichItem(array $item)
    {
        $code = (string) ($item['code'] ?? '');
        // NET_SALARY_REQUIRED is an actionable alias for SALARY_MISSING deep-links.
        if ($code === 'SALARY_MISSING') {
            $item['alias_code'] = 'NET_SALARY_REQUIRED';
        }
        $item['action_link'] = self::actionLinkForCode($code, $item);
        $item['etkilenen_personel_sayisi'] = self::affectedPersonelCount($item);
        $item['etkilenen_kayit_sayisi'] = self::affectedRecordCount($item);
        $item['kullanici_mesaji'] = self::kullaniciMesajiForCode($code, $item);
        $meta = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        if (isset($meta['eksik_kodlar']) && is_array($meta['eksik_kodlar'])) {
            $item['eksik_kodlar'] = array_values($meta['eksik_kodlar']);
        } elseif ($code === 'BUSINESS_POLICY_INCOMPLETE' && isset($meta['eksik_kodlar'])) {
            $item['eksik_kodlar'] = array_values((array) $meta['eksik_kodlar']);
        }

        return $item;
    }

    private static function kullaniciMesajiForCode($code, array $item)
    {
        $personel = trim((string) ($item['personel_adi'] ?? ''));
        $prefix = $personel !== '' ? $personel . ': ' : '';
        switch ($code) {
            case 'BUSINESS_POLICY_REQUIRED':
                return 'Onaylı şirket çalışma politikası yok. Politika sekmesinden taslak oluşturup GY onayına gönderin.';
            case 'BUSINESS_POLICY_INCOMPLETE':
                return 'Politikada zorunlu parametreler eksik. Eksik kodları tamamlayıp yeniden onaylatın.';
            case 'NET_SALARY_REQUIRED':
            case 'SALARY_MISSING':
                return $prefix . 'Net maaş / ücret kaydı eksik. Personel kartından ücret bilgisini girin.';
            case 'LEGACY_SALARY_FALLBACK_USED':
                return $prefix . 'Ücret geçmişi yok; yalnızca legacy maaş kullanılıyor. Ücret geçmişi eklemeniz önerilir.';
            case 'PERSONNEL_CARRYOVER_MISSING':
                return $prefix . 'Yasal bordro devri eksik. Devir şablonunu indirip yükleyin.';
            case 'PERSONNEL_CARRYOVER_INVALID':
                return $prefix . 'Devir değerleri geçersiz (negatif olamaz).';
            case 'LEGAL_PARAMETER_REQUIRED_MISSING':
            case 'LEGAL_PARAMETER_SET_EMPTY':
                return 'Mevzuat parametreleri eksik. Yönetim paneli > Mevzuat sekmesinden kontrol edin.';
            case 'PERIOD_NOT_SEALED':
                return 'Dönem mühürlenmemiş. Dönem kapanış panelinden mühürleyin.';
            case 'UNRESOLVED_IMPACT_CANDIDATE':
            case 'FINANCE_CONFLICT_UNRESOLVED':
            case 'CANDIDATE_PENDING':
            case 'ETKI_ADAY_PENDING':
                return 'Çözülmemiş puantaj etki adayı var. Etki adayı panelinden inceleyip kapatın.';
            case 'S81_GENEL_YONETICI_FINAL_ONAY_EKSIK':
                $neden = (string) (($item['metadata']['neden'] ?? '') ?: '');
                if ($neden === 'ONAY_TABLOSU_YOK') {
                    return 'S81 final onay tablosu bulunamadı (ONAY_TABLOSU_YOK). Bildirimler / migrasyon durumunu kontrol edin.';
                }

                return 'Genel yönetici final onayı yok (ONAY_KAYDI_YOK). Bildirimler ekranından final onayı tamamlayın.';
            case 'CORRECTION_SOURCE_CONFLICT':
                return 'Açık revizyon / correction çatışması var. Revizyon merkezinden çözün.';
            case 'PAYROLL_SCOPE_EXCLUDED':
                return $prefix . 'Bu dönemde bordro kapsamı HARIC; yeni snapshot/revision setine alınmaz. Carryover ve net maaş eksikleri üretilmez.';
            default:
                return (string) ($item['message'] ?? $code);
        }
    }

    private static function actionLinkForCode($code, array $item)
    {
        switch ($code) {
            case 'BUSINESS_POLICY_REQUIRED':
            case 'BUSINESS_POLICY_INCOMPLETE':
            case 'COMPANY_POLICY_MISSING':
                return '/raporlar?panel=bordro-hazirlik&tab=politika';
            case 'PERSONNEL_CARRYOVER_MISSING':
            case 'PERSONNEL_CARRYOVER_INVALID':
                return '/raporlar?panel=bordro-hazirlik&tab=devir';
            case 'SALARY_MISSING':
            case 'NET_SALARY_REQUIRED':
            case 'SALARY_SEGMENT_INVALID':
            case 'SALARY_TYPE_UNSUPPORTED':
            case 'SALARY_OVERLAP_DATA_ERROR':
            case 'SALARY_COVERAGE_GAP':
            case 'LEGACY_SALARY_FALLBACK_USED':
                $pid = (int) ($item['personel_id'] ?? 0);
                if ($pid > 0) {
                    return '/personeller/' . $pid . '?tab=genel-bilgiler';
                }

                return '/raporlar?panel=bordro-hazirlik&tab=veri-hazirlik';
            case 'LEGAL_PARAMETER_REQUIRED_MISSING':
            case 'LEGAL_PARAMETER_SET_EMPTY':
            case 'LEGAL_PARAMETER_OVERLAP_DATA_ERROR':
            case 'LEGAL_PARAMETER_COVERAGE_PARTIAL':
                return '/yonetim-paneli?tab=mevzuat';
            case 'PERIOD_NOT_SEALED':
            case 'PERIOD_SEAL_INVALID':
                return '/raporlar?panel=donem-kapanis';
            case 'CANDIDATE_PENDING':
            case 'ETKI_ADAY_PENDING':
            case 'UNRESOLVED_IMPACT_CANDIDATE':
            case 'FINANCE_CONFLICT_UNRESOLVED':
            case 'CANDIDATE_HAZIR_PENDING':
            case 'CANDIDATE_INCELEME_PENDING':
                return '/raporlar?panel=etki-adayi';
            case 'S81_GENEL_YONETICI_FINAL_ONAY_EKSIK':
                return '/bildirimler';
            case 'CORRECTION_SOURCE_CONFLICT':
                return '/revizyon-merkezi';
            case 'PAYROLL_SCOPE_EXCLUDED':
                $pid = (int) ($item['personel_id'] ?? 0);
                if ($pid > 0) {
                    return '/personeller/' . $pid . '?tab=genel-bilgiler';
                }

                return '/raporlar?panel=bordro-hazirlik&tab=veri-hazirlik';
            default:
                return '/raporlar?panel=bordro-hazirlik&tab=veri-hazirlik';
        }
    }

    private static function affectedPersonelCount(array $item)
    {
        if (isset($item['personel_id']) && $item['personel_id'] !== null) {
            return 1;
        }
        $meta = $item['metadata'] ?? [];

        return isset($meta['adet']) ? (int) $meta['adet'] : 0;
    }

    private static function affectedRecordCount(array $item)
    {
        return isset($item['record_id']) && $item['record_id'] !== null ? 1 : 0;
    }

    /** @param array<string, mixed> $metadata */
    private static function actionableIssue(
        $severity,
        $code,
        $message,
        $recordType = null,
        $recordId = null,
        $personelId = null,
        array $metadata = [],
        $actionLink = null
    ) {
        $item = [
            'severity' => (string) $severity,
            'code' => (string) $code,
            'message' => (string) $message,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'personel_id' => $personelId,
            'personel_adi' => null,
            'metadata' => $metadata,
            'action_link' => $actionLink,
            'etkilenen_personel_sayisi' => $personelId !== null ? 1 : 0,
            'etkilenen_kayit_sayisi' => $recordId !== null ? 1 : 0,
        ];

        return self::enrichItem($item);
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function countSeverity(array $items, $severity)
    {
        $n = 0;
        foreach ($items as $item) {
            if ((string) ($item['severity'] ?? '') === (string) $severity) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $codes
     * @return array<int, array<string, mixed>>
     */
    private static function filterCodes(array $items, array $codes)
    {
        $set = array_fill_keys($codes, true);
        $out = [];
        foreach ($items as $item) {
            if (isset($set[(string) ($item['code'] ?? '')])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function filterSeverity(array $items, $severity)
    {
        $out = [];
        foreach ($items as $item) {
            if ((string) ($item['severity'] ?? '') === (string) $severity) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function uniquePersonelCount(array $items)
    {
        $ids = [];
        foreach ($items as $item) {
            if (isset($item['personel_id']) && $item['personel_id'] !== null) {
                $ids[(int) $item['personel_id']] = true;
            }
        }

        return count($ids);
    }
}
