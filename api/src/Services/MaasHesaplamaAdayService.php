<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Payroll\FinanceKalemCatalog;
use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use Medisa\Api\Services\Payroll\MaasHesaplamaLegalParameterCatalog;
use PDO;
use PDOException;

/**
 * S77-D maas hesaplama preflight + aday orkestrasyonu.
 * Canli personel/puantaj/ucret/finans tablolari okunmaz; yalniz snapshot + devir.
 */
class MaasHesaplamaAdayService
{
    public const CONTRACT_VERSION = 'S77D_PAYROLL_CANDIDATE_V2';

    /**
     * @return array<string, mixed>
     */
    public static function buildCalculationPreflight(PDO $pdo, $snapshotId)
    {
        $bundle = self::loadSnapshotBundle($pdo, (int) $snapshotId);
        $items = [];
        $snapshot = $bundle['snapshot'];

        if ((string) $snapshot['state'] !== 'OLUSTURULDU') {
            $items[] = self::issue('BLOCKER', 'SNAPSHOT_CANCELLED', 'Snapshot iptal edilmis; hesap yapilamaz.');
        }

        $hashCheck = MaasHesaplamaSnapshotService::verifySnapshotHash($pdo, $bundle['raw_snapshot']);
        if (!$hashCheck['dogrulandi']) {
            $items[] = self::issue('BLOCKER', 'SNAPSHOT_HASH_INVALID', 'Snapshot hash dogrulanamadi.');
        } else {
            $items[] = self::issue('INFO', 'SNAPSHOT_VERIFIED', 'Snapshot hash dogrulandi.');
        }

        $mevzuat = $bundle['mevzuat_by_code'];
        $missing = [];
        foreach (MaasHesaplamaLegalParameterCatalog::requiredCodes() as $code) {
            $meta = MaasHesaplamaLegalParameterCatalog::meta($code);
            $row = isset($mevzuat[$code]) ? $mevzuat[$code] : null;
            $absent = $row === null;
            if (!$absent && $meta !== null && $meta['deger_tipi'] === 'METIN') {
                $absent = ($row['metin_deger'] ?? null) === null || trim((string) $row['metin_deger']) === '';
            } elseif (!$absent) {
                $absent = ($row['sayisal_deger'] ?? null) === null || (string) $row['sayisal_deger'] === '';
            }
            if ($absent) {
                $missing[] = $code;
                $items[] = self::issue('BLOCKER', 'LEGAL_PARAMETER_REQUIRED_MISSING', 'Zorunlu mevzuat parametresi eksik: ' . $code, 'mevzuat', null, null, ['parametre_kodu' => $code]);
            }
        }
        if (count($mevzuat) === 0) {
            $items[] = self::issue('BLOCKER', 'LEGAL_PARAMETER_REQUIRED_MISSING', 'Snapshot mevzuat seti bos.');
        }
        $items[] = self::issue('INFO', 'PARAMETER_COUNT', 'Mevzuat parametre sayisi.', 'mevzuat', null, null, ['adet' => count($mevzuat), 'eksik' => count($missing)]);
        $items[] = self::issue('INFO', 'ENGINE_VERSION', MaasHesaplamaEngine::ENGINE_VERSION);

        $personelIds = array_map(static function (array $p) {
            return (int) $p['personel_id'];
        }, $bundle['personeller']);
        $carryovers = PersonelBordroDevirService::findActiveBatch($pdo, $personelIds, (int) $snapshot['yil'], (int) $snapshot['ay']);
        $carryCount = 0;
        foreach ($bundle['personeller'] as $personel) {
            $pid = (int) $personel['personel_id'];
            $ay = (int) $snapshot['ay'];
            if ($ay === 1) {
                if (!isset($carryovers[$pid])) {
                    $carryovers[$pid] = [
                        'personel_id' => $pid,
                        'onceki_kumulatif_gelir_vergisi_matrahi' => '0.00',
                        'onceki_kumulatif_gelir_vergisi' => '0.00',
                        'onceki_kumulatif_sgk_matrahi' => null,
                        'devir_kaynagi' => 'OCAK_DEFAULT',
                        'revision_no' => 0,
                    ];
                }
                $carryCount++;
                continue;
            }
            if (!isset($carryovers[$pid])) {
                $items[] = self::issue('BLOCKER', 'PERSONNEL_CARRYOVER_MISSING', 'Personel yasal devir eksik.', 'devir', null, $pid, [], $personel['ad_soyad'] ?? null);
            } else {
                $carryCount++;
                $c = $carryovers[$pid];
                if (
                    bccomp((string) $c['onceki_kumulatif_gelir_vergisi_matrahi'], '0', 2) < 0
                    || bccomp((string) $c['onceki_kumulatif_gelir_vergisi'], '0', 2) < 0
                ) {
                    $items[] = self::issue('BLOCKER', 'PERSONNEL_CARRYOVER_INVALID', 'Devir negatif olamaz.', 'devir', (int) ($c['id'] ?? 0), $pid);
                }
            }
        }
        $items[] = self::issue('INFO', 'CARRYOVER_COUNT', 'Devir kayit sayisi.', 'devir', null, null, ['adet' => $carryCount]);
        $items[] = self::issue('INFO', 'PERSONNEL_COUNT', 'Personel sayisi.', 'personel', null, null, ['adet' => count($bundle['personeller'])]);

        // Segment / finans quick checks from snapshot only
        foreach ($bundle['personeller'] as $personel) {
            $pid = (int) $personel['personel_id'];
            $segments = $bundle['ucret_by_personel'][$pid] ?? [];
            if (count($segments) === 0) {
                $items[] = self::issue('BLOCKER', 'SALARY_SEGMENT_INVALID', 'Snapshot ucret segmenti yok.', 'ucret', null, $pid);
            } else {
                foreach ($segments as $seg) {
                    $tur = strtoupper((string) ($seg['ucret_turu'] ?? ''));
                    if (!in_array($tur, ['BRUT', 'NET'], true)) {
                        $items[] = self::issue('BLOCKER', 'SALARY_TYPE_UNSUPPORTED', 'Desteklenmeyen ucret turu.', 'ucret', null, $pid, ['ucret_turu' => $tur]);
                    }
                    if (!empty($seg['virtual_legacy'])) {
                        $items[] = self::issue('WARNING', 'LEGACY_SALARY_FALLBACK_USED', 'Legacy ucret fallback.', 'ucret', null, $pid);
                    }
                }
                if (count($segments) > 1) {
                    $items[] = self::issue('WARNING', 'MID_MONTH_SALARY_CHANGE', 'Donem ici ucret degisikligi.', 'ucret', null, $pid);
                }
            }
            foreach ($bundle['finans_by_personel'][$pid] ?? [] as $fin) {
                $tur = strtoupper((string) ($fin['kalem_turu'] ?? ''));
                if (FinanceKalemCatalog::isDuplicateSalary($tur)) {
                    $items[] = self::issue('BLOCKER', 'DUPLICATE_FINANCE_EFFECT', 'MAAS finans kalemi yasak.', 'finans', isset($fin['kayit_id']) ? (int) $fin['kayit_id'] : null, $pid);
                } elseif (FinanceKalemCatalog::classify($tur) === null) {
                    $items[] = self::issue('BLOCKER', 'FINANCE_INPUT_INVALID', 'Finans matrah sinifi eksik: ' . $tur, 'finans', isset($fin['kayit_id']) ? (int) $fin['kayit_id'] : null, $pid);
                }
            }
        }

        $existing = self::findActiveCalistirma($pdo, (int) $snapshot['id']);
        $parameterSetHash = MaasHesaplamaEngine::hashCanonical($mevzuat);
        $carryoverSetHash = MaasHesaplamaEngine::hashCanonical($carryovers);
        $sourceHash = MaasHesaplamaEngine::hashCanonical([
            'snapshot_hash' => (string) $snapshot['snapshot_hash'],
            'parameter_set_hash' => $parameterSetHash,
            'carryover_set_hash' => $carryoverSetHash,
            'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION,
        ]);
        if ($existing && (string) $existing['source_hash'] !== $sourceHash) {
            $items[] = self::issue('BLOCKER', 'EXISTING_ACTIVE_CALCULATION_SOURCE_CHANGED', 'Aktif hesap sonrasi kaynak degisti.');
        }
        if ($existing) {
            $items[] = self::issue('INFO', 'EXISTING_CALCULATION_FOUND', 'Aktif hesaplama mevcut.', 'calistirma', (int) $existing['id']);
        }

        $blockerCount = self::countSeverity($items, 'BLOCKER');
        $warningCount = self::countSeverity($items, 'WARNING');
        $infoCount = self::countSeverity($items, 'INFO');
        $calculationInputHash = MaasHesaplamaEngine::hashCanonical([
            'snapshot_id' => (int) $snapshot['id'],
            'snapshot_hash' => (string) $snapshot['snapshot_hash'],
            'source_hash' => $sourceHash,
            'blockers' => self::codeCounts($items, 'BLOCKER'),
            'warnings' => self::codeCounts($items, 'WARNING'),
            'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION,
        ]);

        return [
            'snapshot_id' => (int) $snapshot['id'],
            'sube_id' => (int) $snapshot['sube_id'],
            'yil' => (int) $snapshot['yil'],
            'ay' => (int) $snapshot['ay'],
            'donem' => (string) $snapshot['donem'],
            'hesaplanabilir_mi' => $blockerCount === 0 && (string) $snapshot['state'] === 'OLUSTURULDU' && $hashCheck['dogrulandi'],
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
            'info_count' => $infoCount,
            'items' => $items,
            'personel_summary' => array_map(static function (array $p) use ($bundle, $carryovers) {
                $pid = (int) $p['personel_id'];

                return [
                    'personel_id' => $pid,
                    'ad_soyad' => $p['ad_soyad'] ?? null,
                    'ucret_segment_sayisi' => count($bundle['ucret_by_personel'][$pid] ?? []),
                    'puantaj_kayit_sayisi' => count($bundle['puantaj_by_personel'][$pid] ?? []),
                    'finans_kalem_sayisi' => count($bundle['finans_by_personel'][$pid] ?? []),
                    'devir_var_mi' => isset($carryovers[$pid]),
                ];
            }, $bundle['personeller']),
            'parameter_summary' => [
                'zorunlu_adet' => count(MaasHesaplamaLegalParameterCatalog::requiredCodes()),
                'bulunan_adet' => count($mevzuat),
                'eksik_kodlar' => $missing,
            ],
            'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'calculation_input_hash' => $calculationInputHash,
            'source_hash' => $sourceHash,
            'parameter_set_hash' => $parameterSetHash,
            'carryover_set_hash' => $carryoverSetHash,
            'snapshot_hash' => (string) $snapshot['snapshot_hash'],
            'existing_calculation' => $existing ? [
                'id' => (int) $existing['id'],
                'revision_no' => (int) $existing['revision_no'],
                'state' => (string) $existing['state'],
                'source_hash' => (string) $existing['source_hash'],
                'result_hash' => (string) $existing['result_hash'],
            ] : null,
            // Internal only — stripped by publicPreflight()
            '_bundle' => $bundle,
            '_carryovers' => $carryovers,
        ];
    }

    /** @param array<string, mixed> $preflight @return array<string, mixed> */
    public static function publicPreflight(array $preflight)
    {
        unset($preflight['_bundle'], $preflight['_carryovers']);

        return $preflight;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{calistirma: array<string, mixed>, idempotent: bool, audit: array<string, mixed>|null}
     */
    public static function createCalculation(PDO $pdo, $snapshotId, $expectedInputHash, $engineVersion, array $user)
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                return self::createCalculationOnce($pdo, (int) $snapshotId, (string) $expectedInputHash, (string) $engineVersion, $user);
            } catch (PDOException $e) {
                if ($attempts >= 4 || !self::isRetryable($e)) {
                    throw $e;
                }
                usleep(25000 * $attempts);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{calistirma: array<string, mixed>, idempotent: bool, audit: array<string, mixed>|null}
     */
    private static function createCalculationOnce(PDO $pdo, $snapshotId, $expectedInputHash, $engineVersion, array $user)
    {
        if ($engineVersion !== '' && $engineVersion !== MaasHesaplamaEngine::ENGINE_VERSION) {
            throw new MaasHesaplamaException('PAYROLL_CALCULATION_INPUT_INVALID', 'engine_version uyusmuyor.', 400);
        }

        $pdo->beginTransaction();
        try {
            $raw = MaasHesaplamaSnapshotService::fetchSnapshotRow($pdo, $snapshotId, true);
            if (!$raw) {
                $pdo->rollBack();
                throw new MaasHesaplamaException('PAYROLL_CALCULATION_SNAPSHOT_INVALID', 'Snapshot bulunamadi.', 404);
            }
            PuantajDonemKilidiService::acquire($pdo, (int) $raw['sube_id'], (int) $raw['yil'], (int) $raw['ay']);

            $preflight = self::buildCalculationPreflight($pdo, $snapshotId);
            $requestHash = hash('sha256', json_encode([
                'actor' => (int) ($user['id'] ?? 0),
                'snapshot_id' => $snapshotId,
                'expected_calculation_input_hash' => $expectedInputHash,
                'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION,
            ], JSON_UNESCAPED_UNICODE));

            if ((int) $preflight['blocker_count'] > 0 || !$preflight['hesaplanabilir_mi']) {
                // Ignore EXISTING_ACTIVE_CALCULATION_SOURCE_CHANGED for generic blocked if handled below
                $codes = self::codeCounts($preflight['items'], 'BLOCKER');
                unset($codes['EXISTING_ACTIVE_CALCULATION_SOURCE_CHANGED']);
                if (count($codes) > 0) {
                    $audit = self::writeAudit($pdo, null, $snapshotId, $preflight, $user, $requestHash, 'PREFLIGHT_BLOCKED', 'BLOCKED');
                    $pdo->commit();
                    throw new MaasHesaplamaException('PAYROLL_CALCULATION_PREFLIGHT_BLOCKED', 'Hesaplama preflight blocker iceriyor.', 409, [
                        'audit' => $audit,
                        'blocker_codes' => array_keys($codes),
                    ]);
                }
            }

            $existing = $preflight['existing_calculation'];
            if ($existing) {
                $row = self::fetchCalistirma($pdo, (int) $existing['id']);
                if ($row && (string) $row['source_hash'] === (string) $preflight['source_hash']) {
                    $audit = self::writeAudit($pdo, (int) $row['id'], $snapshotId, $preflight, $user, $requestHash, 'CALCULATION_IDEMPOTENT', 'EXISTING');
                    $pdo->commit();

                    return [
                        'calistirma' => self::mapCalistirma($row),
                        'idempotent' => true,
                        'audit' => $audit,
                    ];
                }
                $audit = self::writeAudit($pdo, $existing ? (int) $existing['id'] : null, $snapshotId, $preflight, $user, $requestHash, 'CALCULATION_FAILED', 'CONFLICT');
                $pdo->commit();
                throw new MaasHesaplamaException('PAYROLL_CALCULATION_SOURCE_CHANGED', 'Aktif hesap sonrasi kaynak degisti.', 409, ['audit' => $audit]);
            }

            if ($expectedInputHash === '' || !hash_equals((string) $preflight['calculation_input_hash'], $expectedInputHash)) {
                $audit = self::writeAudit($pdo, null, $snapshotId, $preflight, $user, $requestHash, 'PREFLIGHT_BLOCKED', 'CONFLICT');
                $pdo->commit();
                throw new MaasHesaplamaException('PAYROLL_CALCULATION_PREFLIGHT_STALE', 'Preflight hash guncel degil.', 409, [
                    'audit' => $audit,
                    'guncel_calculation_input_hash' => (string) $preflight['calculation_input_hash'],
                ]);
            }

            $bundle = $preflight['_bundle'];
            $carryovers = $preflight['_carryovers'];
            $results = [];
            foreach ($bundle['personeller'] as $personel) {
                $pid = (int) $personel['personel_id'];
                $engineInput = [
                    'personel' => $personel,
                    'ucret_segmentleri' => $bundle['ucret_by_personel'][$pid] ?? [],
                    'puantajlar' => $bundle['puantaj_by_personel'][$pid] ?? [],
                    'izinler' => $bundle['izin_by_personel'][$pid] ?? [],
                    'etki_adaylari' => $bundle['etki_by_personel'][$pid] ?? [],
                    'finanslar' => $bundle['finans_by_personel'][$pid] ?? [],
                    'mevzuat' => $bundle['mevzuat_by_code'],
                    'carryover' => $carryovers[$pid],
                    'donem_baslangic' => (string) $bundle['snapshot']['donem_baslangic'],
                    'donem_bitis' => (string) $bundle['snapshot']['donem_bitis'],
                ];
                $calc = MaasHesaplamaEngine::calculate($engineInput);
                if (empty($calc['ok'])) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $failedAudit = null;
                    try {
                        $auditPdo = \Medisa\Api\Database\Connection::get();
                        $auditPdo->beginTransaction();
                        $failedAudit = self::writeAudit(
                            $auditPdo,
                            null,
                            $snapshotId,
                            $preflight,
                            $user,
                            $requestHash,
                            'CALCULATION_FAILED',
                            'FAILED'
                        );
                        $auditPdo->commit();
                    } catch (\Throwable $ignored) {
                    }
                    throw new MaasHesaplamaException(
                        (string) ($calc['error_code'] ?? 'PAYROLL_CALCULATION_INPUT_INVALID'),
                        (string) ($calc['error_message'] ?? 'Hesaplama basarisiz.'),
                        409,
                        ['personel_id' => $pid, 'audit' => $failedAudit]
                    );
                }
                $results[$pid] = $calc;
            }

            $revisionStmt = $pdo->prepare('SELECT MAX(revision_no) AS m, (SELECT id FROM maas_hesaplama_calistirmalari WHERE snapshot_id = :s2 ORDER BY revision_no DESC, id DESC LIMIT 1) AS last_id FROM maas_hesaplama_calistirmalari WHERE snapshot_id = :s');
            $revisionStmt->execute(['s' => $snapshotId, 's2' => $snapshotId]);
            $revRow = $revisionStmt->fetch(PDO::FETCH_ASSOC) ?: ['m' => null, 'last_id' => null];
            $revisionNo = ((int) ($revRow['m'] ?? 0)) + 1;
            $parentId = $revRow['last_id'] !== null ? (int) $revRow['last_id'] : null;

            $resultHash = MaasHesaplamaEngine::hashCanonical(array_map(static function (array $r) {
                return $r['result_hash'];
            }, $results));

            $ins = $pdo->prepare(
                'INSERT INTO maas_hesaplama_calistirmalari (
                    snapshot_id, sube_id, yil, ay, revision_no, parent_calistirma_id, state,
                    engine_version, contract_version, snapshot_hash, parameter_set_hash, carryover_set_hash,
                    request_hash, source_hash, result_hash, calculation_input_hash,
                    personel_sayisi, basarili_aday_sayisi, hatali_aday_sayisi, blocker_count, warning_count, created_by
                 ) VALUES (
                    :snapshot_id, :sube_id, :yil, :ay, :revision_no, :parent_id, \'HESAPLANDI\',
                    :engine_version, :contract_version, :snapshot_hash, :parameter_set_hash, :carryover_set_hash,
                    :request_hash, :source_hash, :result_hash, :calculation_input_hash,
                    :personel_sayisi, :basarili, 0, 0, :warning_count, :created_by
                 )'
            );
            $ins->execute([
                'snapshot_id' => $snapshotId,
                'sube_id' => (int) $bundle['snapshot']['sube_id'],
                'yil' => (int) $bundle['snapshot']['yil'],
                'ay' => (int) $bundle['snapshot']['ay'],
                'revision_no' => $revisionNo,
                'parent_id' => $parentId,
                'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION,
                'contract_version' => self::CONTRACT_VERSION,
                'snapshot_hash' => (string) $bundle['snapshot']['snapshot_hash'],
                'parameter_set_hash' => (string) $preflight['parameter_set_hash'],
                'carryover_set_hash' => (string) $preflight['carryover_set_hash'],
                'request_hash' => $requestHash,
                'source_hash' => (string) $preflight['source_hash'],
                'result_hash' => $resultHash,
                'calculation_input_hash' => (string) $preflight['calculation_input_hash'],
                'personel_sayisi' => count($results),
                'basarili' => count($results),
                'warning_count' => (int) $preflight['warning_count'],
                'created_by' => isset($user['id']) ? (int) $user['id'] : null,
            ]);
            $calistirmaId = (int) $pdo->lastInsertId();

            $insAday = $pdo->prepare(
                'INSERT INTO maas_hesaplama_adaylari (
                    calistirma_id, personel_snapshot_id, personel_id, revision_no, state, ucret_turu, para_birimi,
                    hedef_net_tutar, sozlesme_brut_tutar, hesaplanan_brut_tutar, sgk_matrahi, gelir_vergisi_matrahi,
                    damga_vergisi_matrahi, sgk_isci_primi, issizlik_isci_primi, gelir_vergisi, damga_vergisi,
                    toplam_ek_odeme, toplam_kesinti, net_odenecek, sonraki_kumulatif_vergi_matrahi,
                    input_hash, result_hash, engine_version, carryover_json, solver_json
                 ) VALUES (
                    :calistirma_id, :personel_snapshot_id, :personel_id, 1, \'HESAPLANDI\', :ucret_turu, :para_birimi,
                    :hedef_net, :sozlesme_brut, :hesaplanan_brut, :sgk_matrah, :gv_matrah,
                    :damga_matrah, :sgk, :issizlik, :gv, :damga,
                    :ek, :kesinti, :net, :sonraki_matrah,
                    :input_hash, :result_hash, :engine_version, :carryover_json, :solver_json
                 )'
            );
            $insKalem = $pdo->prepare(
                'INSERT INTO maas_hesaplama_aday_kalemleri (
                    aday_id, sira_no, kalem_grubu, kalem_kodu, yon, miktar, birim, oran, matrah, tutar,
                    kaynak_turu, kaynak_id, aciklama, payload_json, payload_hash
                 ) VALUES (
                    :aday_id, :sira_no, :kalem_grubu, :kalem_kodu, :yon, :miktar, :birim, :oran, :matrah, :tutar,
                    :kaynak_turu, :kaynak_id, :aciklama, :payload_json, :payload_hash
                 )'
            );

            foreach ($bundle['personeller'] as $personel) {
                $pid = (int) $personel['personel_id'];
                $calc = $results[$pid];
                $ozet = $calc['ozet'];
                $insAday->execute([
                    'calistirma_id' => $calistirmaId,
                    'personel_snapshot_id' => (int) $personel['personel_snapshot_id'],
                    'personel_id' => $pid,
                    'ucret_turu' => $ozet['ucret_turu'],
                    'para_birimi' => $ozet['para_birimi'],
                    'hedef_net' => $ozet['hedef_net_tutar'],
                    'sozlesme_brut' => $ozet['sozlesme_brut_tutar'],
                    'hesaplanan_brut' => $ozet['hesaplanan_brut_tutar'],
                    'sgk_matrah' => $ozet['sgk_matrahi'],
                    'gv_matrah' => $ozet['gelir_vergisi_matrahi'],
                    'damga_matrah' => $ozet['damga_vergisi_matrahi'],
                    'sgk' => $ozet['sgk_isci_primi'],
                    'issizlik' => $ozet['issizlik_isci_primi'],
                    'gv' => $ozet['gelir_vergisi'],
                    'damga' => $ozet['damga_vergisi'],
                    'ek' => $ozet['toplam_ek_odeme'],
                    'kesinti' => $ozet['toplam_kesinti'],
                    'net' => $ozet['net_odenecek'],
                    'sonraki_matrah' => $ozet['sonraki_kumulatif_vergi_matrahi'],
                    'input_hash' => $calc['input_hash'],
                    'result_hash' => $calc['result_hash'],
                    'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION,
                    'carryover_json' => json_encode($calc['carryover_snapshot'], JSON_UNESCAPED_UNICODE),
                    'solver_json' => $calc['solver'] ? json_encode($calc['solver'], JSON_UNESCAPED_UNICODE) : null,
                ]);
                $adayId = (int) $pdo->lastInsertId();
                foreach ($calc['kalemler'] as $kalem) {
                    $insKalem->execute([
                        'aday_id' => $adayId,
                        'sira_no' => (int) $kalem['sira_no'],
                        'kalem_grubu' => (string) $kalem['kalem_grubu'],
                        'kalem_kodu' => (string) $kalem['kalem_kodu'],
                        'yon' => (string) $kalem['yon'],
                        'miktar' => $kalem['miktar'],
                        'birim' => $kalem['birim'],
                        'oran' => $kalem['oran'],
                        'matrah' => $kalem['matrah'],
                        'tutar' => (string) $kalem['tutar'],
                        'kaynak_turu' => $kalem['kaynak_turu'],
                        'kaynak_id' => $kalem['kaynak_id'],
                        'aciklama' => $kalem['aciklama'],
                        'payload_json' => json_encode($kalem['payload_json'], JSON_UNESCAPED_UNICODE),
                        'payload_hash' => (string) $kalem['payload_hash'],
                    ]);
                }
            }

            $row = self::fetchCalistirma($pdo, $calistirmaId);
            $audit = self::writeAudit($pdo, $calistirmaId, $snapshotId, $preflight, $user, $requestHash, 'CALCULATION_CREATE', 'CREATED', $resultHash);
            $pdo->commit();

            return [
                'calistirma' => self::mapCalistirma($row),
                'idempotent' => false,
                'audit' => $audit,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{calistirma: array<string, mixed>, idempotent: bool, audit: array<string, mixed>|null}
     */
    public static function cancelCalculation(PDO $pdo, $calistirmaId, $neden, array $user)
    {
        $neden = trim((string) $neden);
        if ($neden === '') {
            throw new MaasHesaplamaException('VALIDATION_ERROR', 'Iptal nedeni zorunludur.', 400);
        }
        $pdo->beginTransaction();
        try {
            $row = self::fetchCalistirma($pdo, (int) $calistirmaId, true);
            if (!$row) {
                $pdo->rollBack();
                throw new MaasHesaplamaException('PAYROLL_CALCULATION_NOT_FOUND', 'Calistirma bulunamadi.', 404);
            }
            PuantajDonemKilidiService::acquire($pdo, (int) $row['sube_id'], (int) $row['yil'], (int) $row['ay']);
            $requestHash = hash('sha256', json_encode([
                'actor' => (int) ($user['id'] ?? 0),
                'calistirma_id' => (int) $calistirmaId,
                'neden' => $neden,
            ], JSON_UNESCAPED_UNICODE));

            if ((string) $row['state'] === 'IPTAL') {
                $audit = self::writeCancelAudit($pdo, $row, $user, $requestHash, $neden);
                $pdo->commit();

                return ['calistirma' => self::mapCalistirma($row), 'idempotent' => true, 'audit' => $audit];
            }

            $pdo->prepare(
                "UPDATE maas_hesaplama_calistirmalari
                 SET state = 'IPTAL', iptal_edildi_by = :a, iptal_edildi_at = CURRENT_TIMESTAMP, iptal_nedeni = :n
                 WHERE id = :id"
            )->execute(['a' => isset($user['id']) ? (int) $user['id'] : null, 'n' => $neden, 'id' => (int) $calistirmaId]);

            $after = self::fetchCalistirma($pdo, (int) $calistirmaId);
            $audit = self::writeCancelAudit($pdo, $after ?: $row, $user, $requestHash, $neden);
            $pdo->commit();

            return ['calistirma' => self::mapCalistirma($after ?: $row), 'idempotent' => false, 'audit' => $audit];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function listCalistirmalar(PDO $pdo, $subeId, $yil = null, $ay = null)
    {
        $where = ['sube_id = :s'];
        $params = ['s' => (int) $subeId];
        if ($yil !== null) {
            $where[] = 'yil = :y';
            $params['y'] = (int) $yil;
        }
        if ($ay !== null) {
            $where[] = 'ay = :a';
            $params['a'] = (int) $ay;
        }
        $stmt = $pdo->prepare('SELECT * FROM maas_hesaplama_calistirmalari WHERE ' . implode(' AND ', $where) . ' ORDER BY yil DESC, ay DESC, revision_no DESC, id DESC');
        $stmt->execute($params);

        return array_map([self::class, 'mapCalistirma'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public static function getCalistirmaDetail(PDO $pdo, $id)
    {
        $row = self::fetchCalistirma($pdo, (int) $id);
        if (!$row) {
            return null;
        }

        return self::mapCalistirma($row);
    }

    /** @return array<int, array<string, mixed>> */
    public static function listAdaylar(PDO $pdo, $calistirmaId)
    {
        $stmt = $pdo->prepare('SELECT * FROM maas_hesaplama_adaylari WHERE calistirma_id = :id ORDER BY personel_id ASC');
        $stmt->execute(['id' => (int) $calistirmaId]);

        return array_map([self::class, 'mapAday'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public static function getAday(PDO $pdo, $adayId)
    {
        $stmt = $pdo->prepare('SELECT * FROM maas_hesaplama_adaylari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $adayId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapAday($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listKalemler(PDO $pdo, $adayId)
    {
        $stmt = $pdo->prepare('SELECT * FROM maas_hesaplama_aday_kalemleri WHERE aday_id = :id ORDER BY sira_no ASC');
        $stmt->execute(['id' => (int) $adayId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'aday_id' => (int) $row['aday_id'],
                'sira_no' => (int) $row['sira_no'],
                'kalem_grubu' => (string) $row['kalem_grubu'],
                'kalem_kodu' => (string) $row['kalem_kodu'],
                'yon' => (string) $row['yon'],
                'miktar' => $row['miktar'] !== null ? (string) $row['miktar'] : null,
                'birim' => $row['birim'] !== null ? (string) $row['birim'] : null,
                'oran' => $row['oran'] !== null ? (string) $row['oran'] : null,
                'matrah' => $row['matrah'] !== null ? (string) $row['matrah'] : null,
                'tutar' => (string) $row['tutar'],
                'kaynak_turu' => $row['kaynak_turu'] !== null ? (string) $row['kaynak_turu'] : null,
                'kaynak_id' => $row['kaynak_id'] !== null ? (int) $row['kaynak_id'] : null,
                'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
                'payload_hash' => (string) $row['payload_hash'],
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listAudits(PDO $pdo, $calistirmaId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, calistirma_id, snapshot_id, sube_id, yil, ay, aksiyon, sonuc, actor_id, actor_rol,
                    request_hash, calculation_input_hash, source_hash, result_hash, blocker_count, warning_count, created_at
             FROM maas_hesaplama_auditleri WHERE calistirma_id = :id ORDER BY id DESC LIMIT 100'
        );
        $stmt->execute(['id' => (int) $calistirmaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Snapshot bundle (NO live personel/puantaj/ucret/finans reads)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function loadSnapshotBundle(PDO $pdo, $snapshotId)
    {
        $raw = MaasHesaplamaSnapshotService::fetchSnapshotRow($pdo, $snapshotId, false);
        if (!$raw) {
            throw new MaasHesaplamaException('PAYROLL_CALCULATION_SNAPSHOT_INVALID', 'Snapshot bulunamadi.', 404);
        }
        $snapshot = MaasHesaplamaSnapshotService::mapSnapshotRow($raw);

        $pStmt = $pdo->prepare('SELECT * FROM maas_hesaplama_personel_snapshotlari WHERE donem_snapshot_id = :id ORDER BY personel_id ASC');
        $pStmt->execute(['id' => $snapshotId]);
        $personeller = [];
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) $row['personel_snapshot_json'], true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $decoded['personel_snapshot_id'] = (int) $row['id'];
            $decoded['personel_id'] = (int) $row['personel_id'];
            $decoded['istihdam_baslangic'] = (string) $row['istihdam_baslangic'];
            $decoded['istihdam_bitis'] = $row['istihdam_bitis'] !== null ? (string) $row['istihdam_bitis'] : null;
            $personeller[] = $decoded;
        }

        $gStmt = $pdo->prepare('SELECT * FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = :id ORDER BY id ASC');
        $gStmt->execute(['id' => $snapshotId]);
        $ucretBy = [];
        $puantajBy = [];
        $izinBy = [];
        $etkiBy = [];
        $finansBy = [];
        $mevzuatBy = [];
        foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $payload = json_decode((string) $g['payload_json'], true);
            if (!is_array($payload)) {
                continue;
            }
            $tur = (string) $g['kaynak_turu'];
            $psId = $g['personel_snapshot_id'] !== null ? (int) $g['personel_snapshot_id'] : null;
            $personelId = null;
            foreach ($personeller as $p) {
                if ($psId !== null && (int) $p['personel_snapshot_id'] === $psId) {
                    $personelId = (int) $p['personel_id'];
                    break;
                }
            }
            if ($personelId === null && isset($payload['personel_id'])) {
                $personelId = (int) $payload['personel_id'];
            }
            if ($tur === 'UCRET' && $personelId !== null) {
                $ucretBy[$personelId][] = $payload;
            } elseif ($tur === 'PUANTAJ' && $personelId !== null) {
                $puantajBy[$personelId][] = $payload;
            } elseif ($tur === 'IZIN' && $personelId !== null) {
                $izinBy[$personelId][] = $payload;
            } elseif ($tur === 'ETKI_ADAYI' && $personelId !== null) {
                $etkiBy[$personelId][] = $payload;
            } elseif ($tur === 'FINANS' && $personelId !== null) {
                $finansBy[$personelId][] = $payload;
            } elseif ($tur === 'MEVZUAT') {
                $code = (string) ($payload['parametre_kodu'] ?? '');
                if ($code !== '') {
                    $mevzuatBy[$code] = $payload;
                }
            }
        }

        return [
            'raw_snapshot' => $raw,
            'snapshot' => $snapshot,
            'personeller' => $personeller,
            'ucret_by_personel' => $ucretBy,
            'puantaj_by_personel' => $puantajBy,
            'izin_by_personel' => $izinBy,
            'etki_by_personel' => $etkiBy,
            'finans_by_personel' => $finansBy,
            'mevzuat_by_code' => $mevzuatBy,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function fetchCalistirma(PDO $pdo, $id, $forUpdate = false)
    {
        $sql = 'SELECT * FROM maas_hesaplama_calistirmalari WHERE id = :id LIMIT 1';
        if ($forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function findActiveCalistirma(PDO $pdo, $snapshotId)
    {
        $stmt = $pdo->prepare("SELECT * FROM maas_hesaplama_calistirmalari WHERE snapshot_id = :s AND state = 'HESAPLANDI' LIMIT 1");
        $stmt->execute(['s' => (int) $snapshotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function mapCalistirma(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'calistirma_id' => (int) $row['id'],
            'snapshot_id' => (int) $row['snapshot_id'],
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'revision_no' => (int) $row['revision_no'],
            'parent_calistirma_id' => $row['parent_calistirma_id'] !== null ? (int) $row['parent_calistirma_id'] : null,
            'state' => (string) $row['state'],
            'engine_version' => (string) $row['engine_version'],
            'contract_version' => (string) $row['contract_version'],
            'snapshot_hash' => (string) $row['snapshot_hash'],
            'parameter_set_hash' => (string) $row['parameter_set_hash'],
            'carryover_set_hash' => (string) $row['carryover_set_hash'],
            'source_hash' => (string) $row['source_hash'],
            'result_hash' => (string) $row['result_hash'],
            'calculation_input_hash' => (string) $row['calculation_input_hash'],
            'personel_sayisi' => (int) $row['personel_sayisi'],
            'basarili_aday_sayisi' => (int) $row['basarili_aday_sayisi'],
            'hatali_aday_sayisi' => (int) $row['hatali_aday_sayisi'],
            'blocker_count' => (int) $row['blocker_count'],
            'warning_count' => (int) $row['warning_count'],
            'created_at' => (string) $row['created_at'],
            'iptal_edildi_at' => $row['iptal_edildi_at'] !== null ? (string) $row['iptal_edildi_at'] : null,
            'iptal_nedeni' => $row['iptal_nedeni'] !== null ? (string) $row['iptal_nedeni'] : null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function mapAday(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'aday_id' => (int) $row['id'],
            'calistirma_id' => (int) $row['calistirma_id'],
            'personel_snapshot_id' => (int) $row['personel_snapshot_id'],
            'personel_id' => (int) $row['personel_id'],
            'revision_no' => (int) $row['revision_no'],
            'state' => (string) $row['state'],
            'ucret_turu' => (string) $row['ucret_turu'],
            'para_birimi' => (string) $row['para_birimi'],
            'hedef_net_tutar' => $row['hedef_net_tutar'] !== null ? (string) $row['hedef_net_tutar'] : null,
            'sozlesme_brut_tutar' => $row['sozlesme_brut_tutar'] !== null ? (string) $row['sozlesme_brut_tutar'] : null,
            'hesaplanan_brut_tutar' => (string) $row['hesaplanan_brut_tutar'],
            'sgk_matrahi' => (string) $row['sgk_matrahi'],
            'gelir_vergisi_matrahi' => (string) $row['gelir_vergisi_matrahi'],
            'damga_vergisi_matrahi' => (string) $row['damga_vergisi_matrahi'],
            'sgk_isci_primi' => (string) $row['sgk_isci_primi'],
            'issizlik_isci_primi' => (string) $row['issizlik_isci_primi'],
            'gelir_vergisi' => (string) $row['gelir_vergisi'],
            'damga_vergisi' => (string) $row['damga_vergisi'],
            'toplam_ek_odeme' => (string) $row['toplam_ek_odeme'],
            'toplam_kesinti' => (string) $row['toplam_kesinti'],
            'net_odenecek' => (string) $row['net_odenecek'],
            'sonraki_kumulatif_vergi_matrahi' => (string) $row['sonraki_kumulatif_vergi_matrahi'],
            'input_hash' => (string) $row['input_hash'],
            'result_hash' => (string) $row['result_hash'],
            'engine_version' => (string) $row['engine_version'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $preflight
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private static function writeAudit(PDO $pdo, $calistirmaId, $snapshotId, array $preflight, array $user, $requestHash, $aksiyon, $sonuc, $resultHash = null)
    {
        $existing = self::findAudit($pdo, (int) $preflight['sube_id'], (int) $preflight['yil'], (int) $preflight['ay'], $aksiyon, $requestHash);
        if ($existing) {
            return self::mapAudit($existing);
        }
        $payload = [
            'calculation_input_hash' => $preflight['calculation_input_hash'] ?? null,
            'source_hash' => $preflight['source_hash'] ?? null,
            'blocker_count' => (int) ($preflight['blocker_count'] ?? 0),
            'warning_count' => (int) ($preflight['warning_count'] ?? 0),
            'engine_version' => MaasHesaplamaEngine::ENGINE_VERSION,
        ];
        try {
            $pdo->prepare(
                'INSERT INTO maas_hesaplama_auditleri (
                    calistirma_id, snapshot_id, sube_id, yil, ay, aksiyon, sonuc,
                    actor_id, actor_rol, request_hash, calculation_input_hash, source_hash, result_hash,
                    blocker_count, warning_count, snapshot_json
                 ) VALUES (
                    :calistirma_id, :snapshot_id, :sube_id, :yil, :ay, :aksiyon, :sonuc,
                    :actor_id, :actor_rol, :request_hash, :input_hash, :source_hash, :result_hash,
                    :blocker_count, :warning_count, :snapshot_json
                 )'
            )->execute([
                'calistirma_id' => $calistirmaId,
                'snapshot_id' => (int) $snapshotId,
                'sube_id' => (int) $preflight['sube_id'],
                'yil' => (int) $preflight['yil'],
                'ay' => (int) $preflight['ay'],
                'aksiyon' => (string) $aksiyon,
                'sonuc' => (string) $sonuc,
                'actor_id' => (int) ($user['id'] ?? 0),
                'actor_rol' => isset($user['rol']) ? (string) $user['rol'] : null,
                'request_hash' => (string) $requestHash,
                'input_hash' => (string) ($preflight['calculation_input_hash'] ?? ''),
                'source_hash' => (string) ($preflight['source_hash'] ?? ''),
                'result_hash' => $resultHash !== null ? (string) $resultHash : hash('sha256', (string) $sonuc),
                'blocker_count' => (int) ($preflight['blocker_count'] ?? 0),
                'warning_count' => (int) ($preflight['warning_count'] ?? 0),
                'snapshot_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        return self::mapAudit(self::findAudit($pdo, (int) $preflight['sube_id'], (int) $preflight['yil'], (int) $preflight['ay'], $aksiyon, $requestHash));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $user
     */
    private static function writeCancelAudit(PDO $pdo, array $row, array $user, $requestHash, $neden)
    {
        $preflight = [
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'calculation_input_hash' => (string) $row['calculation_input_hash'],
            'source_hash' => (string) $row['source_hash'],
            'blocker_count' => 0,
            'warning_count' => 0,
        ];

        return self::writeAudit($pdo, (int) $row['id'], (int) $row['snapshot_id'], $preflight, $user, $requestHash, 'CALCULATION_CANCEL', 'CANCELLED', (string) $row['result_hash']);
    }

    /** @return array<string, mixed>|null */
    private static function findAudit(PDO $pdo, $subeId, $yil, $ay, $aksiyon, $requestHash)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM maas_hesaplama_auditleri
             WHERE sube_id = :s AND yil = :y AND ay = :a AND aksiyon = :aksiyon AND request_hash = :r LIMIT 1'
        );
        $stmt->execute(['s' => $subeId, 'y' => $yil, 'a' => $ay, 'aksiyon' => $aksiyon, 'r' => $requestHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed>|null $row */
    private static function mapAudit($row)
    {
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'aksiyon' => (string) $row['aksiyon'],
            'sonuc' => (string) $row['sonuc'],
            'calistirma_id' => $row['calistirma_id'] !== null ? (int) $row['calistirma_id'] : null,
            'request_hash' => (string) $row['request_hash'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function issue($severity, $code, $message, $recordType = null, $recordId = null, $personelId = null, array $metadata = [], $personelAdi = null)
    {
        return [
            'severity' => (string) $severity,
            'code' => (string) $code,
            'message' => (string) $message,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'personel_id' => $personelId,
            'personel_adi' => $personelAdi,
            'metadata' => $metadata,
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function countSeverity(array $items, $severity)
    {
        $n = 0;
        foreach ($items as $item) {
            if ((string) $item['severity'] === (string) $severity) {
                $n++;
            }
        }

        return $n;
    }

    /** @param array<int, array<string, mixed>> $items @return array<string, int> */
    private static function codeCounts(array $items, $severity)
    {
        $out = [];
        foreach ($items as $item) {
            if ((string) $item['severity'] !== (string) $severity) {
                continue;
            }
            $code = (string) $item['code'];
            $out[$code] = ($out[$code] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    private static function isRetryable(PDOException $e)
    {
        $code = (string) $e->getCode();
        $driver = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

        return $code === '40001' || $driver === 1213 || $driver === 1205
            || stripos($e->getMessage(), 'Deadlock') !== false
            || stripos($e->getMessage(), 'Lock wait timeout') !== false;
    }
}
