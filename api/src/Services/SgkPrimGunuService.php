<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Payroll\SgkPrimGunuEngine;
use PDO;
use PDOException;

/** S85-B SGK kaynak cozumleme, snapshot persistence ve read-only query owner'i. */
final class SgkPrimGunuService
{
    /** @return array<string, mixed> */
    public static function calculateResolution(PDO $pdo, array $resolution)
    {
        $periodStart = (string) $resolution['donem_baslangic'];
        $periodEnd = (string) $resolution['donem_bitis'];
        $catalog = self::loadCatalog($pdo, $periodStart, $periodEnd);
        $companyPolicy = self::loadCompanyPolicy($pdo, (int) $resolution['sube_id'], $periodStart, $periodEnd);
        $statuses = self::loadPersonnelStatuses($pdo, array_keys($resolution['personeller']), $periodStart, $periodEnd);
        $mapping = self::loadProcessMappings($pdo, $catalog['surum_id'] ?? null);
        $documents = self::loadDocuments($pdo, $resolution['izinler']);
        $disabilityFinance = self::loadDisabilityFinance(
            $pdo,
            $resolution['izinler'],
            (string) ($resolution['donem'] ?? substr($periodStart, 0, 7))
        );
        [$dailyLower, $dailyUpper, $limitVersion] = self::resolvePekLimits($resolution['legal']);

        $attendanceByPerson = [];
        foreach ($resolution['attendance']['rows'] ?? [] as $row) {
            $attendanceByPerson[(int) $row['personel_id']][] = MaasHesaplamaSnapshotService::attendancePayload($row);
        }
        $processByPerson = [];
        foreach ($resolution['izinler'] ?? [] as $row) {
            $process = MaasHesaplamaSnapshotService::leavePayload($row);
            $rawKey = self::mappingKey((string) $process['surec_turu'], $process['alt_tur']);
            $wildcardKey = self::mappingKey((string) $process['surec_turu'], null);
            $map = $mapping[$rawKey] ?? $mapping[$wildcardKey] ?? null;
            if (is_array($map)) {
                $process['canonical_surec_turu'] = (string) $map['canonical_surec_turu'];
                $process['eksik_gun_kodu'] = $map['eksik_gun_kodu'] !== null ? (string) $map['eksik_gun_kodu'] : null;
                $process['prim_gunu_etkisi'] = (string) $map['prim_gunu_etkisi'];
                $conditions = json_decode((string) ($map['kosullar_json'] ?? ''), true);
                if (is_array($conditions) && isset($conditions['cozulmus_prim_gunu_etkisi'])) {
                    $process['cozulmus_prim_gunu_etkisi'] = (string) $conditions['cozulmus_prim_gunu_etkisi'];
                }
            } else {
                $process['canonical_surec_turu'] = 'DIGER_MANUEL_INCELEME';
                $process['prim_gunu_etkisi'] = 'MANUEL';
                $process['eksik_gun_kodu'] = null;
            }
            $docRows = $documents[(int) $process['surec_id']] ?? [];
            $process['kaynak_belge_idleri'] = array_values(array_map(static function (array $doc) {
                return (int) $doc['id'];
            }, $docRows));
            $process['belge_dogrulandi_mi'] = count($docRows) > 0 && count(array_filter($docRows, [self::class, 'isVerifiedDocument'])) === count($docRows);
            $process['belge_iptal_mi'] = count(array_filter($docRows, static function (array $doc) {
                return (string) $doc['dogrulama_durumu'] === 'IPTAL';
            })) > 0;
            $process['belge_hash_uyusmazligi_mi'] = count(array_filter($docRows, static function (array $doc) {
                return preg_match('/^[0-9a-f]{64}$/', (string) $doc['dosya_hash']) !== 1;
            })) > 0;
            $processByPerson[(int) $process['personel_id']][] = $process;
        }

        $results = [];
        $items = [];
        foreach ($resolution['personeller'] as $personelId => $personel) {
            $statusRows = $statuses[(int) $personelId] ?? [];
            $status = count($statusRows) === 1 ? $statusRows[0] : null;
            $personelInput = $personel;
            $personelInput['ucret_modeli'] = self::wageModel($personel['ucret_tipi_id'] ?? null);
            $personelInput['sigortalilik_statusu'] = $status['sigortalilik_statusu'] ?? null;
            $personelInput['sozlesme_turu'] = $status['sozlesme_turu'] ?? null;

            $processes = $processByPerson[(int) $personelId] ?? [];
            $reportPresent = count(array_filter($processes, static function (array $process) {
                return in_array((string) ($process['canonical_surec_turu'] ?? ''), ['HASTALIK', 'IS_KAZASI', 'MESLEK_HASTALIGI', 'ANALIK'], true);
            })) > 0;
            $financeSummary = [];
            foreach ($processes as $process) {
                $processId = (int) ($process['surec_id'] ?? 0);
                if ($processId > 0 && isset($disabilityFinance[$processId])) {
                    $financeSummary[] = $disabilityFinance[$processId];
                }
            }
            $allowanceStatus = self::allowanceStatus($reportPresent, $financeSummary);
            $policyHash = null;
            if ($companyPolicy['politika'] !== null
                && (!$reportPresent || isset($companyPolicy['degerler']['SGK_ODENEK_MAHSUP_MODU']))) {
                $policyHash = (string) $companyPolicy['politika']['politika_hash'];
            }

            $result = SgkPrimGunuEngine::calculate([
                'donem_baslangic' => $periodStart,
                'donem_bitis' => $periodEnd,
                'bildirim_donem_tipi' => $status !== null && (string) $status['bildirim_donem_tipi'] !== 'SIRKET_POLITIKASINDAN'
                    ? (string) $status['bildirim_donem_tipi']
                    : ($companyPolicy['politika']['bildirim_donem_tipi'] ?? null),
                'personel' => $personelInput,
                'puantajlar' => $attendanceByPerson[(int) $personelId] ?? [],
                'surecler' => $processes,
                'katalog' => $catalog,
                'sirket_politikasi_gerekli_mi' => $reportPresent,
                'sirket_politika_surum_id' => $companyPolicy['politika']['id'] ?? null,
                'sirket_politika_hash' => $policyHash,
                'sgk_odenek_durumu' => $allowanceStatus,
                'is_goremezlik_finans_ozeti' => $financeSummary,
                'gunluk_alt_sinir' => $dailyLower,
                'gunluk_ust_sinir' => $dailyUpper,
                'sinir_mevzuat_surumu' => $limitVersion,
                // 0 prim gununde true/false bilinmiyorsa engine fail-closed kalir; tahmin uretilmez.
                'sifir_kazanc_mi' => null,
            ]);
            if (count($statusRows) !== 1) {
                $result = self::appendBlocker($result, [
                    'severity' => 'BLOCKER',
                    'code' => 'SGK_PRIM_GUNU_HESAPLANAMADI',
                    'message' => count($statusRows) === 0
                        ? 'Donemi kapsayan onayli sigortalilik/sozlesme surumu yok.'
                        : 'Donemi kapsayan birden fazla sigortalilik/sozlesme surumu var.',
                    'domain' => 'SGK',
                    'tarih_baslangic' => $periodStart,
                    'tarih_bitis' => $periodEnd,
                    'kaynak_surec_id' => null,
                    'kaynak_belge_id' => null,
                    'cozum_onerisi' => 'Tek bir onayli ve tarih etkili sigortalilik surumu belirleyin.',
                ]);
            }
            $results[(int) $personelId] = $result;
            foreach ($result['blocker_detaylari'] as $blocker) {
                $items[] = self::preflightIssue($blocker, (int) $personelId, $personel['ad_soyad'] ?? null);
            }
        }

        return [
            'results_by_personel' => $results,
            'items' => $items,
            'catalog' => $catalog,
            'company_policy' => $companyPolicy,
            'source_hash' => SgkPrimGunuEngine::hashCanonical(array_map(static function (array $result) {
                return $result['sgk_hesap_hash'];
            }, $results)),
        ];
    }

    /** @param array<int, int> $personelSnapshotIds */
    public static function persistSnapshotRows(PDO $pdo, $snapshotId, array $personelSnapshotIds, array $results, array $auditContext)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO maas_hesaplama_sgk_snapshotlari (
                donem_snapshot_id, personel_snapshot_id, personel_id,
                hesaplanan_prim_gunu, eksik_gun_sayisi, eksik_gun_kodu, eksik_gun_aciklamasi,
                kaynak_surec_idleri_json, kaynak_puantaj_idleri_json, kaynak_belge_idleri_json,
                katalog_surum_id, katalog_surumu, kaynak_manifest_hash, sgk_hesap_hash,
                gunluk_karar_dokumu_hash, gunluk_karar_dokumu_json,
                manuel_inceleme_gerekli_mi, blocker_kodlari_json, blocker_detaylari_json,
                ucret_modeli, ilk_iki_gun_politika_ozeti_json,
                sirket_politika_surum_id, sirket_politika_hash, sgk_odenek_durumu,
                is_goremezlik_finans_ozeti_json,
                gunluk_alt_sinir, gunluk_ust_sinir, donem_alt_sinir, donem_ust_sinir,
                sinir_mevzuat_surumu, source_hash
             ) VALUES (
                :donem_snapshot_id, :personel_snapshot_id, :personel_id,
                :prim_gunu, :eksik_gun, :eksik_kod, :eksik_aciklama,
                :surecler_json, :puantajlar_json, :belgeler_json,
                :katalog_id, :katalog_surumu, :manifest_hash, :hesap_hash,
                :gunluk_hash, :gunluk_json,
                :manuel, :blocker_kodlari_json, :blocker_detaylari_json,
                :ucret_modeli, :ilk_iki_gun_json,
                :politika_id, :politika_hash, :odenek_durumu,
                :is_goremezlik_finans_json,
                :gunluk_alt, :gunluk_ust, :donem_alt, :donem_ust,
                :sinir_surumu, :source_hash
             )'
        );
        $auditStmt = $pdo->prepare(
            'INSERT INTO sgk_hesap_auditleri (
                donem_snapshot_id, personel_id, yil, ay, aksiyon, sonuc,
                request_hash, source_hash, result_hash, blocker_kodlari_json, actor_id
             ) VALUES (
                :donem_snapshot_id, :personel_id, :yil, :ay, \'SNAPSHOT_CREATE\', \'CREATED\',
                :request_hash, :source_hash, :result_hash, :blocker_kodlari_json, :actor_id
             )'
        );
        foreach ($results as $personelId => $result) {
            if (!isset($personelSnapshotIds[$personelId])) {
                throw new MaasHesaplamaException('PAYROLL_SOURCE_INCONSISTENT', 'SGK sonucu personel snapshotina baglanamadi.', 500);
            }
            $stmt->execute([
                'donem_snapshot_id' => (int) $snapshotId,
                'personel_snapshot_id' => (int) $personelSnapshotIds[$personelId],
                'personel_id' => (int) $personelId,
                'prim_gunu' => $result['hesaplanan_prim_gunu'],
                'eksik_gun' => $result['eksik_gun_sayisi'],
                'eksik_kod' => $result['eksik_gun_kodu'],
                'eksik_aciklama' => $result['eksik_gun_aciklamasi'],
                'surecler_json' => self::json($result['kaynak_surec_idleri']),
                'puantajlar_json' => self::json($result['kaynak_puantaj_idleri']),
                'belgeler_json' => self::json($result['kaynak_belge_idleri']),
                'katalog_id' => $result['katalog_surum_id'],
                'katalog_surumu' => $result['katalog_surumu'],
                'manifest_hash' => $result['kaynak_manifest_hash'],
                'hesap_hash' => $result['sgk_hesap_hash'],
                'gunluk_hash' => $result['gunluk_karar_dokumu_hash'],
                'gunluk_json' => self::json($result['gunluk_karar_dokumu']),
                'manuel' => !empty($result['manuel_inceleme_gerekli_mi']) ? 1 : 0,
                'blocker_kodlari_json' => self::json($result['blocker_kodlari']),
                'blocker_detaylari_json' => self::json($result['blocker_detaylari']),
                'ucret_modeli' => $result['ucret_modeli'],
                'ilk_iki_gun_json' => self::json($result['ilk_iki_gun_politika_ozeti']),
                'politika_id' => $result['sirket_politika_surum_id'],
                'politika_hash' => $result['sirket_politika_hash'],
                'odenek_durumu' => $result['sgk_odenek_durumu'],
                'is_goremezlik_finans_json' => self::json($result['is_goremezlik_finans_ozeti']),
                'gunluk_alt' => $result['gunluk_alt_sinir'],
                'gunluk_ust' => $result['gunluk_ust_sinir'],
                'donem_alt' => $result['donem_alt_sinir'],
                'donem_ust' => $result['donem_ust_sinir'],
                'sinir_surumu' => $result['sinir_mevzuat_surumu'],
                'source_hash' => $result['source_hash'],
            ]);
            $auditStmt->execute([
                'donem_snapshot_id' => (int) $snapshotId,
                'personel_id' => (int) $personelId,
                'yil' => (int) $auditContext['yil'],
                'ay' => (int) $auditContext['ay'],
                'request_hash' => (string) $auditContext['request_hash'],
                'source_hash' => (string) $result['source_hash'],
                'result_hash' => (string) $result['sgk_hesap_hash'],
                'blocker_kodlari_json' => self::json($result['blocker_kodlari']),
                'actor_id' => $auditContext['actor_id'] !== null ? (int) $auditContext['actor_id'] : null,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function loadSnapshotResults(PDO $pdo, $snapshotId)
    {
        $stmt = $pdo->prepare('SELECT * FROM maas_hesaplama_sgk_snapshotlari WHERE donem_snapshot_id = :id ORDER BY personel_id ASC');
        $stmt->execute(['id' => (int) $snapshotId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['personel_id']] = self::mapSnapshotRow($row);
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listCanonicalResults(PDO $pdo, $subeId, $yil, $ay, $personelId = null)
    {
        $where = ["ds.state = 'OLUSTURULDU'", 'ds.sube_id = :sube_id', 'ds.yil = :yil', 'ds.ay = :ay'];
        $params = ['sube_id' => (int) $subeId, 'yil' => (int) $yil, 'ay' => (int) $ay];
        if ($personelId !== null) {
            $where[] = 'sgk.personel_id = :personel_id';
            $params['personel_id'] = (int) $personelId;
        }
        $stmt = $pdo->prepare(
            'SELECT sgk.*, ds.yil, ds.ay, ds.donem, ds.revision_no AS snapshot_revision_no,
                    ds.id AS snapshot_id, p.ad, p.soyad, p.sicil_no
             FROM maas_hesaplama_sgk_snapshotlari sgk
             INNER JOIN maas_hesaplama_donem_snapshotlari ds ON ds.id = sgk.donem_snapshot_id
             INNER JOIN personeller p ON p.id = sgk.personel_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY sgk.personel_id ASC'
        );
        $stmt->execute($params);

        return array_map([self::class, 'mapSnapshotRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private static function loadCatalog(PDO $pdo, $from, $to)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM sgk_eksik_gun_katalog_surumleri
                 WHERE state = 'ONAYLANDI' AND tamlik_durumu = 'DOGRULANMIS_TAM'
                   AND gecerlilik_baslangic <= :bitis
                   AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)
                 ORDER BY gecerlilik_baslangic DESC, id DESC"
            );
            $stmt->execute(['baslangic' => $from, 'bitis' => $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = [];
        }
        if (count($rows) !== 1) {
            return [
                'surum_id' => null,
                'surum_kodu' => null,
                'state' => 'GECERSIZ',
                'tamlik_durumu' => 'TASLAK',
                'manifest_hash' => null,
                'kodlar' => [],
                'cakismalar' => [],
            ];
        }
        $version = $rows[0];
        $codesStmt = $pdo->prepare(
            'SELECT * FROM sgk_eksik_gun_kodlari
             WHERE katalog_surum_id = :id AND aktif_mi = 1
               AND gecerlilik_baslangic <= :bitis
               AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)
             ORDER BY eksik_gun_kodu ASC'
        );
        $codesStmt->execute(['id' => (int) $version['id'], 'baslangic' => $from, 'bitis' => $to]);
        $codes = [];
        foreach ($codesStmt->fetchAll(PDO::FETCH_ASSOC) as $code) {
            $codes[(string) $code['eksik_gun_kodu']] = [
                'resmi_aciklama' => (string) $code['resmi_aciklama'],
                'belge_zorunlulugu' => (string) $code['belge_zorunlulugu'],
                'sifir_gun_sifir_kazanc_kullanilabilir_mi' => (bool) $code['sifir_gun_sifir_kazanc_kullanilabilir_mi'],
                'kismi_sureli_sozlesme_gerekli_mi' => (bool) $code['kismi_sureli_sozlesme_gerekli_mi'],
                'tek_basina_kullanilabilir_mi' => (bool) $code['tek_basina_kullanilabilir_mi'],
                'diger_nedenlerle_birlikte_kullanim' => (string) $code['diger_nedenlerle_birlikte_kullanim'],
                'aktif_mi' => (bool) $code['aktif_mi'],
            ];
        }
        $conflictStmt = $pdo->prepare('SELECT * FROM sgk_eksik_gun_kod_cakismalari WHERE katalog_surum_id = :id AND aktif_mi = 1');
        $conflictStmt->execute(['id' => (int) $version['id']]);
        $conflicts = [];
        foreach ($conflictStmt->fetchAll(PDO::FETCH_ASSOC) as $conflict) {
            $conflicts[(string) $conflict['kaynak_kod_set_hash']] = [
                'sonuc_eksik_gun_kodu' => (string) $conflict['sonuc_eksik_gun_kodu'],
            ];
        }

        return [
            'surum_id' => (int) $version['id'],
            'surum_kodu' => (string) $version['surum_kodu'],
            'state' => (string) $version['state'],
            'tamlik_durumu' => (string) $version['tamlik_durumu'],
            'manifest_hash' => (string) $version['manifest_set_hash'],
            'kodlar' => $codes,
            'cakismalar' => $conflicts,
        ];
    }

    /** @return array<string, mixed> */
    private static function loadCompanyPolicy(PDO $pdo, $subeId, $from, $to)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM sgk_sirket_politika_surumleri
                 WHERE sube_id = :sube_id AND state = 'ONAYLANDI'
                   AND gecerlilik_baslangic <= :bitis
                   AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :baslangic)
                 ORDER BY gecerlilik_baslangic DESC, id DESC"
            );
            $stmt->execute(['sube_id' => $subeId, 'baslangic' => $from, 'bitis' => $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = [];
        }
        if (count($rows) !== 1) {
            return ['politika' => null, 'degerler' => []];
        }
        $policy = $rows[0];
        $valueStmt = $pdo->prepare('SELECT politika_kodu, deger FROM sgk_sirket_politika_degerleri WHERE politika_surum_id = :id ORDER BY politika_kodu ASC');
        $valueStmt->execute(['id' => (int) $policy['id']]);
        $values = [];
        foreach ($valueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string) $row['politika_kodu']] = (string) $row['deger'];
        }

        return ['politika' => $policy, 'degerler' => $values];
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private static function loadPersonnelStatuses(PDO $pdo, array $personelIds, $from, $to)
    {
        if (count($personelIds) === 0) {
            return [];
        }
        try {
            $placeholders = implode(', ', array_fill(0, count($personelIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT * FROM sgk_personel_sigortalilik_surumleri
                 WHERE personel_id IN ($placeholders) AND state = 'ONAYLANDI'
                   AND gecerlilik_baslangic <= ?
                   AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= ?)
                 ORDER BY personel_id ASC, gecerlilik_baslangic DESC, id DESC"
            );
            $stmt->execute(array_merge(array_map('intval', $personelIds), [$to, $from]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['personel_id']][] = $row;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private static function loadProcessMappings(PDO $pdo, $catalogId)
    {
        if ($catalogId === null) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM sgk_surec_neden_eslemeleri WHERE katalog_surum_id = :id AND aktif_mi = 1 ORDER BY id ASC');
        $stmt->execute(['id' => (int) $catalogId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[self::mappingKey((string) $row['surec_turu'], $row['alt_tur'])] = $row;
        }

        return $out;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private static function loadDocuments(PDO $pdo, array $processRows)
    {
        $ids = array_values(array_unique(array_map(static function (array $row) {
            return (int) $row['id'];
        }, $processRows)));
        if (count($ids) === 0) {
            return [];
        }
        try {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT b.*, l.surec_id
                 FROM sgk_belge_surec_baglantilari l
                 INNER JOIN sgk_eksik_gun_belgeleri b ON b.id = l.belge_id
                 WHERE l.surec_id IN ($placeholders)
                 ORDER BY l.surec_id ASC, b.id ASC"
            );
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['surec_id']][] = $row;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> surec_id => approved finance separation */
    private static function loadDisabilityFinance(PDO $pdo, array $processRows, $donem)
    {
        $ids = array_values(array_unique(array_map(static function (array $row) {
            return (int) $row['id'];
        }, $processRows)));
        if (count($ids) === 0) {
            return [];
        }
        try {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, personel_id, donem, surec_id, sgk_hak_durumu,
                        sgk_fiili_odenen_tutar, sgk_tahmini_odenen_tutar, tahmin_durumu,
                        isveren_ucret_koruma_tutari, isveren_tamamlayici_odeme_tutari,
                        mahsup_iade_tutari, bordro_kesinti_tutari, para_birimi,
                        source_hash, kaynak_belge_id, revision_no
                 FROM sgk_is_goremezlik_finans_kayitlari
                 WHERE surec_id IN ($placeholders) AND donem = ? AND state = 'ONAYLANDI'
                 ORDER BY surec_id ASC, revision_no DESC, id DESC"
            );
            $stmt->execute(array_merge($ids, [(string) $donem]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $processId = (int) $row['surec_id'];
            if (isset($out[$processId])) {
                continue;
            }
            $out[$processId] = [
                'kayit_id' => (int) $row['id'],
                'surec_id' => $processId,
                'sgk_hak_durumu' => (string) $row['sgk_hak_durumu'],
                'sgk_fiili_odenen_tutar' => $row['sgk_fiili_odenen_tutar'] !== null ? (string) $row['sgk_fiili_odenen_tutar'] : null,
                'sgk_tahmini_odenen_tutar' => $row['sgk_tahmini_odenen_tutar'] !== null ? (string) $row['sgk_tahmini_odenen_tutar'] : null,
                'tahmin_durumu' => (string) $row['tahmin_durumu'],
                'isveren_ucret_koruma_tutari' => $row['isveren_ucret_koruma_tutari'] !== null ? (string) $row['isveren_ucret_koruma_tutari'] : null,
                'isveren_tamamlayici_odeme_tutari' => $row['isveren_tamamlayici_odeme_tutari'] !== null ? (string) $row['isveren_tamamlayici_odeme_tutari'] : null,
                'mahsup_iade_tutari' => $row['mahsup_iade_tutari'] !== null ? (string) $row['mahsup_iade_tutari'] : null,
                'bordro_kesinti_tutari' => $row['bordro_kesinti_tutari'] !== null ? (string) $row['bordro_kesinti_tutari'] : null,
                'para_birimi' => (string) $row['para_birimi'],
                'source_hash' => (string) $row['source_hash'],
                'kaynak_belge_id' => $row['kaynak_belge_id'] !== null ? (int) $row['kaynak_belge_id'] : null,
                'revision_no' => (int) $row['revision_no'],
            ];
        }

        return $out;
    }

    private static function allowanceStatus($reportPresent, array $summary)
    {
        if (!$reportPresent) {
            return 'UYGULANMAZ';
        }
        foreach ($summary as $row) {
            if ($row['sgk_fiili_odenen_tutar'] !== null && (string) $row['tahmin_durumu'] === 'KESIN') {
                return 'FIILI_TUTAR';
            }
        }
        foreach ($summary as $row) {
            if ($row['sgk_tahmini_odenen_tutar'] !== null) {
                return in_array((string) $row['tahmin_durumu'], ['TAHMINI', 'MANUEL_DOGRULAMA'], true)
                    ? (string) $row['tahmin_durumu'] : 'KESINLESMEMIS';
            }
        }

        return 'KESINLESMEMIS';
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null} */
    private static function resolvePekLimits(array $legal)
    {
        $byCode = [];
        foreach ($legal as $row) {
            $byCode[(string) ($row['parametre_kodu'] ?? '')] = $row;
        }
        $lower = isset($byCode['SGK_GUNLUK_TABAN']['sayisal_deger']) ? (string) $byCode['SGK_GUNLUK_TABAN']['sayisal_deger'] : null;
        $upper = isset($byCode['SGK_GUNLUK_TAVAN']['sayisal_deger']) ? (string) $byCode['SGK_GUNLUK_TAVAN']['sayisal_deger'] : null;
        $version = $lower !== null && $upper !== null ? SgkPrimGunuEngine::hashCanonical([
            'alt' => MaasHesaplamaSnapshotService::legalPayloadStatic($byCode['SGK_GUNLUK_TABAN']),
            'ust' => MaasHesaplamaSnapshotService::legalPayloadStatic($byCode['SGK_GUNLUK_TAVAN']),
        ]) : null;

        return [$lower, $upper, $version];
    }

    /** @return array<string, mixed> */
    private static function appendBlocker(array $result, array $blocker)
    {
        $result['blocker_detaylari'][] = $blocker;
        $result['blocker_kodlari'][] = (string) $blocker['code'];
        $result['blocker_kodlari'] = array_values(array_unique($result['blocker_kodlari']));
        $result['manuel_inceleme_gerekli_mi'] = true;
        $result['hesaplanan_prim_gunu'] = null;
        $result['eksik_gun_kodu'] = null;
        $result['donem_alt_sinir'] = null;
        $result['donem_ust_sinir'] = null;
        unset($result['sgk_hesap_hash']);
        $result['sgk_hesap_hash'] = SgkPrimGunuEngine::hashCanonical($result);

        return $result;
    }

    /** @return array<string, mixed> */
    private static function preflightIssue(array $blocker, $personelId, $personelAdi)
    {
        return [
            'severity' => 'BLOCKER',
            'code' => (string) $blocker['code'],
            'message' => (string) $blocker['message'],
            'record_type' => 'sgk',
            'record_id' => $blocker['kaynak_surec_id'] ?? null,
            'personel_id' => $personelId,
            'personel_adi' => $personelAdi,
            'metadata' => [
                'domain' => 'SGK',
                'tarih_baslangic' => $blocker['tarih_baslangic'] ?? null,
                'tarih_bitis' => $blocker['tarih_bitis'] ?? null,
                'kaynak_surec_id' => $blocker['kaynak_surec_id'] ?? null,
                'kaynak_belge_id' => $blocker['kaynak_belge_id'] ?? null,
                'cozum_onerisi' => $blocker['cozum_onerisi'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function mapSnapshotRow(array $row)
    {
        $mapped = [
            'id' => (int) $row['id'],
            'snapshot_id' => (int) ($row['snapshot_id'] ?? $row['donem_snapshot_id']),
            'personel_snapshot_id' => (int) $row['personel_snapshot_id'],
            'personel_id' => (int) $row['personel_id'],
            'hesaplanan_prim_gunu' => $row['hesaplanan_prim_gunu'] !== null ? (int) $row['hesaplanan_prim_gunu'] : null,
            'eksik_gun_sayisi' => $row['eksik_gun_sayisi'] !== null ? (int) $row['eksik_gun_sayisi'] : null,
            'eksik_gun_kodu' => $row['eksik_gun_kodu'] !== null ? (string) $row['eksik_gun_kodu'] : null,
            'eksik_gun_aciklamasi' => $row['eksik_gun_aciklamasi'] !== null ? (string) $row['eksik_gun_aciklamasi'] : null,
            'kaynak_surec_idleri' => self::decodeJson($row['kaynak_surec_idleri_json']),
            'kaynak_puantaj_idleri' => self::decodeJson($row['kaynak_puantaj_idleri_json']),
            'kaynak_belge_idleri' => self::decodeJson($row['kaynak_belge_idleri_json']),
            'katalog_surumu' => $row['katalog_surumu'] !== null ? (string) $row['katalog_surumu'] : null,
            'kaynak_manifest_hash' => $row['kaynak_manifest_hash'] !== null ? (string) $row['kaynak_manifest_hash'] : null,
            'sgk_hesap_hash' => (string) $row['sgk_hesap_hash'],
            'gunluk_karar_dokumu_hash' => (string) $row['gunluk_karar_dokumu_hash'],
            'manuel_inceleme_gerekli_mi' => (bool) $row['manuel_inceleme_gerekli_mi'],
            'blocker_kodlari' => self::decodeJson($row['blocker_kodlari_json']),
            'blocker_detaylari' => self::decodeJson($row['blocker_detaylari_json']),
            'ucret_modeli' => (string) $row['ucret_modeli'],
            'ilk_iki_gun_politika_ozeti' => self::decodeJson($row['ilk_iki_gun_politika_ozeti_json']),
            'sirket_politika_surum_id' => $row['sirket_politika_surum_id'] !== null ? (int) $row['sirket_politika_surum_id'] : null,
            'sirket_politika_hash' => $row['sirket_politika_hash'] !== null ? (string) $row['sirket_politika_hash'] : null,
            'sgk_odenek_durumu' => (string) $row['sgk_odenek_durumu'],
            'is_goremezlik_finans_ozeti' => self::decodeJson($row['is_goremezlik_finans_ozeti_json']),
            'gunluk_alt_sinir' => $row['gunluk_alt_sinir'] !== null ? (string) $row['gunluk_alt_sinir'] : null,
            'gunluk_ust_sinir' => $row['gunluk_ust_sinir'] !== null ? (string) $row['gunluk_ust_sinir'] : null,
            'donem_alt_sinir' => $row['donem_alt_sinir'] !== null ? (string) $row['donem_alt_sinir'] : null,
            'donem_ust_sinir' => $row['donem_ust_sinir'] !== null ? (string) $row['donem_ust_sinir'] : null,
            'sinir_mevzuat_surumu' => $row['sinir_mevzuat_surumu'] !== null ? (string) $row['sinir_mevzuat_surumu'] : null,
            'source_hash' => (string) $row['source_hash'],
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
        foreach (['yil', 'ay', 'donem', 'snapshot_revision_no', 'ad', 'soyad', 'sicil_no'] as $extra) {
            if (array_key_exists($extra, $row)) {
                $mapped[$extra] = in_array($extra, ['yil', 'ay', 'snapshot_revision_no'], true) ? (int) $row[$extra] : $row[$extra];
            }
        }

        return $mapped;
    }

    private static function wageModel($id)
    {
        return [1 => 'MAKTU_AYLIK', 2 => 'GUNLUK', 3 => 'SAATLIK'][(int) $id] ?? 'BELIRSIZ';
    }

    private static function mappingKey($type, $subtype)
    {
        return $type . '|' . ($subtype === null ? '*' : (string) $subtype);
    }

    private static function isVerifiedDocument(array $row)
    {
        return (string) $row['dogrulama_durumu'] === 'DOGRULANDI'
            && preg_match('/^[0-9a-f]{64}$/', (string) $row['dosya_hash']) === 1;
    }

    private static function json($value)
    {
        return json_encode(SgkPrimGunuEngine::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<int|string, mixed> */
    private static function decodeJson($value)
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
