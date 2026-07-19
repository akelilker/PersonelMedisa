<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

/**
 * S82 bordro on izleme ve onay akisi owner'i.
 */
class BordroOnIzlemeService
{
    /** @return array<string, mixed> */
    public static function buildDonemOzeti(PDO $pdo, $subeId, $yil, $ay, $departmanId = null)
    {
        $calistirma = self::findActiveCalistirma($pdo, (int) $subeId, (int) $yil, (int) $ay);
        $preflight = BordroHazirlikPreflightService::build($pdo, (int) $subeId, (int) $yil, (int) $ay);
        $personelRows = [];
        $toplamNet = '0.00';
        $toplamBrut = '0.00';
        $toplamEk = '0.00';
        $toplamKesinti = '0.00';
        $hesaplanabilir = 0;
        $blockerli = 0;
        $adayOlusturulan = 0;
        $kontrolBekleyen = 0;
        $kesinlesen = 0;

        if ($calistirma) {
            $personelRows = self::listPersonelSatirlari($pdo, (int) $calistirma['id'], $departmanId);
            foreach ($personelRows as $row) {
                $toplamNet = bcadd($toplamNet, (string) $row['net_odenecek'], 2);
                $toplamBrut = bcadd($toplamBrut, (string) $row['hesaplanan_brut_tutar'], 2);
                $toplamEk = bcadd($toplamEk, (string) $row['toplam_ek_odeme'], 2);
                $toplamKesinti = bcadd($toplamKesinti, (string) $row['toplam_kesinti'], 2);
                if ($row['durum'] === 'HESAPLANDI') {
                    $adayOlusturulan++;
                }
                if (in_array($row['bordro_onay_durumu'], ['MUHASEBE_KONTROLUNDE', 'ONAY_BEKLIYOR'], true)) {
                    $kontrolBekleyen++;
                }
                if ($row['bordro_onay_durumu'] === 'KESINLESTI') {
                    $kesinlesen++;
                }
            }
        }

        foreach ($preflight['items'] as $item) {
            if (($item['severity'] ?? '') === 'BLOCKER' && ($item['personel_id'] ?? null) !== null) {
                $blockerli++;
            }
        }
        $hesaplanabilir = max(0, count($preflight['snapshot_preflight']['existing_snapshot'] ? ($personelRows ?: []) : []) - $blockerli);

        return [
            'donem' => sprintf('%04d-%02d', (int) $yil, (int) $ay),
            'sube_id' => (int) $subeId,
            'departman_id' => $departmanId !== null ? (int) $departmanId : null,
            'toplam_personel' => count($personelRows),
            'hesaplanabilir' => $hesaplanabilir,
            'blocker_bulunan' => (int) $preflight['blocker_count'],
            'aday_olusturulan' => $adayOlusturulan,
            'kontrol_bekleyen' => $kontrolBekleyen,
            'kesinlesen' => $kesinlesen,
            'toplam_net' => $toplamNet,
            'toplam_brut' => $toplamBrut,
            'toplam_ek_odeme' => $toplamEk,
            'toplam_kesinti' => $toplamKesinti,
            'calistirma' => $calistirma,
            'preflight' => $preflight,
            'personel_satirlari' => $personelRows,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function listPersonelSatirlari(PDO $pdo, $calistirmaId, $departmanId = null)
    {
        $sql = "SELECT a.*, p.ad, p.soyad, p.sicil_no, p.departman_id, d.ad AS departman_ad, ss.ad AS sube_ad
                FROM maas_hesaplama_adaylari a
                INNER JOIN personeller p ON p.id = a.personel_id
                LEFT JOIN departmanlar d ON d.id = p.departman_id
                INNER JOIN maas_hesaplama_calistirmalari c ON c.id = a.calistirma_id
                INNER JOIN subeler ss ON ss.id = c.sube_id
                WHERE a.calistirma_id = :cid AND a.state = 'HESAPLANDI'";
        $params = ['cid' => (int) $calistirmaId];
        if ($departmanId !== null) {
            $sql .= ' AND p.departman_id = :departman_id';
            $params['departman_id'] = (int) $departmanId;
        }
        $sql .= ' ORDER BY p.ad ASC, p.soyad ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'mapPersonelSatiri'], $rows);
    }

    /** @return array<string, mixed>|null */
    public static function getAdayDetay(PDO $pdo, $adayId)
    {
        $stmt = $pdo->prepare(
            "SELECT a.*, p.ad, p.soyad, p.sicil_no, p.departman_id, d.ad AS departman_ad, c.sube_id, ss.ad AS sube_ad
             FROM maas_hesaplama_adaylari a
             INNER JOIN personeller p ON p.id = a.personel_id
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             INNER JOIN maas_hesaplama_calistirmalari c ON c.id = a.calistirma_id
             INNER JOIN subeler ss ON ss.id = c.sube_id
             WHERE a.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => (int) $adayId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $kalemStmt = $pdo->prepare(
            'SELECT * FROM maas_hesaplama_aday_kalemleri WHERE aday_id = :id ORDER BY sira_no ASC'
        );
        $kalemStmt->execute(['id' => (int) $adayId]);
        $kalemler = $kalemStmt->fetchAll(PDO::FETCH_ASSOC);
        $detail = self::mapPersonelSatiri($row);
        $detail['kalemler'] = array_map(static function (array $kalem) {
            $payload = json_decode((string) $kalem['payload_json'], true) ?: [];

            return [
                'kaynak' => (string) ($kalem['kaynak_turu'] ?? 'HESAP'),
                'aciklama' => (string) ($kalem['aciklama'] ?? $kalem['kalem_kodu']),
                'miktar' => $kalem['miktar'] !== null ? (string) $kalem['miktar'] : null,
                'birim' => $kalem['birim'] !== null ? (string) $kalem['birim'] : null,
                'oran' => $kalem['oran'] !== null ? (string) $kalem['oran'] : null,
                'tutar' => (string) $kalem['tutar'],
                'yon' => (string) $kalem['yon'],
                'snapshot_referansi' => $payload['snapshot_referansi'] ?? null,
                'correction_referansi' => $payload['correction_referansi'] ?? null,
            ];
        }, $kalemler);
        $detail['correction_projection'] = $row['correction_projection_json'] !== null
            ? json_decode((string) $row['correction_projection_json'], true) : null;

        return $detail;
    }

    /** @param array<string, mixed> $actor */
    public static function submitMuhasebeKontrol(PDO $pdo, $calistirmaId, $not, array $actor)
    {
        return self::transitionCalistirma($pdo, (int) $calistirmaId, ['HESAPLANDI', 'MUHASEBE_KONTROLUNDE'], 'ONAY_BEKLIYOR', $actor, [
            'muhasebe_kontrol_notu' => trim((string) $not),
            'muhasebe_kontrol_by' => self::actorId($actor),
            'muhasebe_kontrol_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $actor */
    public static function geriGonder(PDO $pdo, $calistirmaId, $not, array $actor)
    {
        return self::transitionCalistirma($pdo, (int) $calistirmaId, 'ONAY_BEKLIYOR', 'MUHASEBE_KONTROLUNDE', $actor, [
            'muhasebe_kontrol_notu' => trim((string) $not),
        ]);
    }

    /** @param array<string, mixed> $actor */
    public static function kesinlestir(PDO $pdo, $calistirmaId, array $actor)
    {
        $preflight = self::preflightForCalistirma($pdo, (int) $calistirmaId);
        if ((int) $preflight['blocker_count'] > 0) {
            throw new MaasHesaplamaException('BORDRO_PREFLIGHT_BLOCKED', 'Kesinleştirme için blocker giderilmelidir.', 409, [
                'blocker_count' => (int) $preflight['blocker_count'],
            ]);
        }

        return self::transitionCalistirma($pdo, (int) $calistirmaId, 'ONAY_BEKLIYOR', 'KESINLESTI', $actor, [
            'kesinlestiren_by' => self::actorId($actor),
            'kesinlestirme_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function findActiveCalistirma(PDO $pdo, $subeId, $yil, $ay)
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM maas_hesaplama_calistirmalari
             WHERE sube_id = :s AND yil = :y AND ay = :a AND state = 'HESAPLANDI'
             ORDER BY revision_no DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['s' => (int) $subeId, 'y' => (int) $yil, 'a' => (int) $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::mapCalistirma($row) : null;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private static function transitionCalistirma(PDO $pdo, $calistirmaId, $from, $to, array $actor, array $extra = [])
    {
        $allowedFrom = is_array($from) ? $from : [(string) $from];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM maas_hesaplama_calistirmalari WHERE id = :id AND state = 'HESAPLANDI' LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['id' => (int) $calistirmaId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new MaasHesaplamaException('PAYROLL_CALCULATION_NOT_FOUND', 'Çalıştırma bulunamadı.', 404);
            }
            if (!in_array((string) ($row['bordro_onay_durumu'] ?? 'HESAPLANDI'), $allowedFrom, true)) {
                throw new MaasHesaplamaException('BORDRO_INVALID_STATE', 'Bordro onay durumu geçişe uygun değil.', 409, [
                    'mevcut' => (string) ($row['bordro_onay_durumu'] ?? 'HESAPLANDI'),
                    'beklenen' => $allowedFrom,
                ]);
            }
            if ($to === 'KESINLESTI' && (string) ($row['bordro_onay_durumu'] ?? '') === 'KESINLESTI') {
                throw new MaasHesaplamaException('BORDRO_ALREADY_FINALIZED', 'Bordro zaten kesinleştirilmiş.', 409);
            }
            $sets = ["bordro_onay_durumu = :to"];
            $params = ['to' => (string) $to, 'id' => (int) $calistirmaId];
            foreach ($extra as $key => $value) {
                $sets[] = $key . ' = :' . $key;
                $params[$key] = $value;
            }
            $pdo->prepare('UPDATE maas_hesaplama_calistirmalari SET ' . implode(', ', $sets) . ' WHERE id = :id')
                ->execute($params);
            $pdo->prepare('UPDATE maas_hesaplama_adaylari SET bordro_onay_durumu = :to WHERE calistirma_id = :id')
                ->execute(['to' => (string) $to, 'id' => (int) $calistirmaId]);
            $pdo->commit();
            $fresh = $pdo->prepare('SELECT * FROM maas_hesaplama_calistirmalari WHERE id = :id');
            $fresh->execute(['id' => (int) $calistirmaId]);

            return self::mapCalistirma($fresh->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private static function preflightForCalistirma(PDO $pdo, $calistirmaId)
    {
        $stmt = $pdo->prepare('SELECT sube_id, yil, ay FROM maas_hesaplama_calistirmalari WHERE id = :id');
        $stmt->execute(['id' => (int) $calistirmaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new MaasHesaplamaException('PAYROLL_CALCULATION_NOT_FOUND', 'Çalıştırma bulunamadı.', 404);
        }

        return BordroHazirlikPreflightService::build($pdo, (int) $row['sube_id'], (int) $row['yil'], (int) $row['ay']);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapCalistirma(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'snapshot_id' => (int) $row['snapshot_id'],
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'revision_no' => (int) $row['revision_no'],
            'state' => (string) $row['state'],
            'bordro_onay_durumu' => (string) ($row['bordro_onay_durumu'] ?? 'HESAPLANDI'),
            'muhasebe_kontrol_notu' => $row['muhasebe_kontrol_notu'] ?? null,
            'kesinlestirme_at' => $row['kesinlestirme_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapPersonelSatiri(array $row)
    {
        return [
            'aday_id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'ad_soyad' => trim(((string) ($row['ad'] ?? '')) . ' ' . ((string) ($row['soyad'] ?? ''))),
            'sicil' => (string) ($row['sicil_no'] ?? ''),
            'sube_ad' => (string) ($row['sube_ad'] ?? ''),
            'departman_ad' => (string) ($row['departman_ad'] ?? ''),
            'net_maas' => $row['hedef_net_tutar'] !== null ? (string) $row['hedef_net_tutar'] : null,
            'brut_maas' => (string) $row['hesaplanan_brut_tutar'],
            'net_odenecek' => (string) $row['net_odenecek'],
            'toplam_ek_odeme' => (string) $row['toplam_ek_odeme'],
            'toplam_kesinti' => (string) $row['toplam_kesinti'],
            'durum' => (string) $row['state'],
            'bordro_onay_durumu' => (string) ($row['bordro_onay_durumu'] ?? 'HESAPLANDI'),
            'aktif_correction_var_mi' => $row['correction_projection_json'] !== null,
        ];
    }

    /** @param array<string, mixed> $actor */
    private static function actorId(array $actor)
    {
        return isset($actor['id']) ? (int) $actor['id'] : null;
    }
}
