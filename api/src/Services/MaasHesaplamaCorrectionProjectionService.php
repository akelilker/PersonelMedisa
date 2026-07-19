<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use PDO;

/**
 * S82 aktif correction projection — snapshot immutable kalir.
 */
class MaasHesaplamaCorrectionProjectionService
{
    /**
     * @param array<int, int> $personelIds
     * @return array{
     *   projections_by_personel: array<int, array<int, array<string, mixed>>>,
     *   projection_hash: string,
     *   blocker_items: array<int, array<string, mixed>>,
     *   active_count: int
     * }
     */
    public static function buildProjection(PDO $pdo, $subeId, array $personelIds, $donemBaslangic, $donemBitis)
    {
        if (count($personelIds) === 0) {
            return [
                'projections_by_personel' => [],
                'projection_hash' => MaasHesaplamaEngine::hashCanonical([]),
                'blocker_items' => [],
                'active_count' => 0,
            ];
        }
        if (!self::tableExists($pdo)) {
            return [
                'projections_by_personel' => [],
                'projection_hash' => MaasHesaplamaEngine::hashCanonical(['schema' => 'missing']),
                'blocker_items' => [],
                'active_count' => 0,
            ];
        }

        $ph = implode(',', array_fill(0, count($personelIds), '?'));
        $params = array_map('intval', array_values($personelIds));
        $params[] = (int) $subeId;
        $params[] = (string) $donemBaslangic;
        $params[] = (string) $donemBitis;
        $stmt = $pdo->prepare(
            "SELECT c.*, t.id AS revizyon_talebi_id
             FROM haftalik_kapanis_revizyon_corrections c
             INNER JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = c.revizyon_talebi_id
             WHERE c.personel_id IN ($ph)
               AND c.sube_id = ?
               AND c.iptal_edildi_mi = 0
               AND c.bordro_etki_var_mi = 1
               AND c.etkilenen_tarih BETWEEN ? AND ?
             ORDER BY c.personel_id ASC, c.etkilenen_tarih ASC, c.id ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byPersonel = [];
        $sourceConflicts = [];
        foreach ($rows as $row) {
            $pid = (int) $row['personel_id'];
            $sourceKey = (string) $row['kaynak_tipi'] . ':' . (int) $row['kaynak_id'] . ':' . (string) $row['etkilenen_tarih'];
            if (!isset($byPersonel[$pid])) {
                $byPersonel[$pid] = [];
            }
            if (!isset($sourceConflicts[$pid])) {
                $sourceConflicts[$pid] = [];
            }
            if (isset($sourceConflicts[$pid][$sourceKey])) {
                $sourceConflicts[$pid][$sourceKey][] = (int) $row['id'];
            } else {
                $sourceConflicts[$pid][$sourceKey] = [(int) $row['id']];
            }
            $byPersonel[$pid][] = self::mapProjection($row);
        }

        $blockers = [];
        foreach ($sourceConflicts as $pid => $groups) {
            foreach ($groups as $sourceKey => $ids) {
                if (count($ids) > 1) {
                    $blockers[] = [
                        'severity' => 'BLOCKER',
                        'code' => 'CORRECTION_SOURCE_CONFLICT',
                        'message' => 'Ayni kaynaga birden fazla aktif correction var.',
                        'record_type' => 'correction',
                        'record_id' => $ids[0],
                        'personel_id' => (int) $pid,
                        'personel_adi' => null,
                        'metadata' => [
                            'kaynak' => $sourceKey,
                            'correction_ids' => $ids,
                        ],
                        'action_link' => '/revizyon-merkezi',
                    ];
                }
            }
        }

        $canonical = [];
        foreach ($byPersonel as $pid => $items) {
            $canonical[$pid] = array_map(static function (array $item) {
                return [
                    'correction_event_id' => $item['correction_event_id'],
                    'revizyon_talebi_id' => $item['revizyon_talebi_id'],
                    'kaynak_tipi' => $item['kaynak_tipi'],
                    'kaynak_id' => $item['kaynak_id'],
                    'ham_deger' => $item['ham_deger'],
                    'corrected_deger' => $item['corrected_deger'],
                    'delta_dakika' => $item['delta_dakika'],
                    'projection_zamani' => $item['projection_zamani'],
                ];
            }, $items);
        }

        return [
            'projections_by_personel' => $byPersonel,
            'projection_hash' => MaasHesaplamaEngine::hashCanonical($canonical),
            'blocker_items' => $blockers,
            'active_count' => count($rows),
        ];
    }

    /**
     * Snapshot puantaj satirlarina aktif correction overlay uygular.
     *
     * @param array<int, array<string, mixed>> $puantajlar
     * @param array<int, array<string, mixed>> $projections
     * @return array{puantajlar: array<int, array<string, mixed>>, applied: array<int, array<string, mixed>>}
     */
    public static function applyToPuantajlar(array $puantajlar, array $projections)
    {
        if (count($projections) === 0) {
            return ['puantajlar' => $puantajlar, 'applied' => []];
        }
        $byDate = [];
        foreach ($projections as $projection) {
            $date = (string) ($projection['etkilenen_tarih'] ?? '');
            if ($date !== '') {
                $byDate[$date][] = $projection;
            }
        }
        $applied = [];
        $out = [];
        foreach ($puantajlar as $puantaj) {
            $tarih = (string) ($puantaj['tarih'] ?? '');
            $copy = $puantaj;
            if (isset($byDate[$tarih])) {
                foreach ($byDate[$tarih] as $projection) {
                    $delta = (int) ($projection['delta_dakika'] ?? 0);
                    if ($delta !== 0) {
                        $field = self::resolvePuantajField((string) ($projection['correction_tipi'] ?? ''));
                        if ($field !== null) {
                            $ham = (int) ($copy[$field] ?? 0);
                            $copy[$field] = $ham + $delta;
                            $copy['_correction_projection'] = [
                                'correction_event_id' => (int) $projection['correction_event_id'],
                                'revizyon_talebi_id' => (int) $projection['revizyon_talebi_id'],
                                'ham_deger' => $ham,
                                'corrected_deger' => (int) $copy[$field],
                                'delta_dakika' => $delta,
                            ];
                            $applied[] = $copy['_correction_projection'];
                        }
                    }
                }
            }
            $out[] = $copy;
        }

        return ['puantajlar' => $out, 'applied' => $applied];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapProjection(array $row)
    {
        $onceki = $row['onceki_deger'] !== null ? json_decode((string) $row['onceki_deger'], true) : null;
        $yeni = $row['yeni_deger'] !== null ? json_decode((string) $row['yeni_deger'], true) : null;

        return [
            'correction_event_id' => (int) $row['id'],
            'revizyon_talebi_id' => (int) $row['revizyon_talebi_id'],
            'personel_id' => (int) $row['personel_id'],
            'etkilenen_tarih' => (string) $row['etkilenen_tarih'],
            'kaynak_tipi' => (string) $row['kaynak_tipi'],
            'kaynak_id' => (int) $row['kaynak_id'],
            'correction_tipi' => (string) $row['correction_tipi'],
            'ham_deger' => $onceki,
            'corrected_deger' => $yeni,
            'delta_dakika' => (int) $row['delta_dakika'],
            'delta_gun' => (int) $row['delta_gun'],
            'bordro_etki_tipi' => $row['bordro_etki_tipi'] !== null ? (string) $row['bordro_etki_tipi'] : null,
            'projection_zamani' => gmdate('c'),
        ];
    }

    private static function resolvePuantajField($correctionTipi)
    {
        switch ($correctionTipi) {
            case 'GIRIS_CIKIS_DUZELTME':
            case 'MOLA_DUZELTME':
            case 'KAPANIS_HESAP_REVIZYONU':
                return 'toplam_net_dakika';
            case 'DEVAMSIZLIK_DUZELTME':
                return 'devamsizlik_dakika';
            default:
                return null;
        }
    }

    private static function tableExists(PDO $pdo)
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'haftalik_kapanis_revizyon_corrections'");

            return (bool) $stmt->fetch(PDO::FETCH_NUM);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
