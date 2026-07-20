<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use PDO;
use PDOException;

/**
 * S82 sirket calisma politikasi owner'i.
 * Onaysiz politika maaş hesabinda kullanilmaz.
 */
class SirketCalismaPolitikasiService
{
    /** @return array<int, array<string, mixed>> */
    public static function listPolitikalar(PDO $pdo, $state = null)
    {
        $sql = 'SELECT p.*, u1.ad_soyad AS hazirlayan_ad, u2.ad_soyad AS onaylayan_ad
                FROM sirket_calisma_politikalari p
                LEFT JOIN users u1 ON u1.id = p.hazirlayan_id
                LEFT JOIN users u2 ON u2.id = p.onaylayan_id';
        $params = [];
        if ($state !== null && trim((string) $state) !== '') {
            $sql .= ' WHERE p.state = :state';
            $params['state'] = strtoupper(trim((string) $state));
        }
        $sql .= ' ORDER BY p.gecerlilik_baslangic DESC, p.revision_no DESC, p.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'mapPolitika'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public static function getPolitikaDetail(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare(
            'SELECT p.*, u1.ad_soyad AS hazirlayan_ad, u2.ad_soyad AS onaylayan_ad
             FROM sirket_calisma_politikalari p
             LEFT JOIN users u1 ON u1.id = p.hazirlayan_id
             LEFT JOIN users u2 ON u2.id = p.onaylayan_id
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $detail = self::mapPolitika($row);
        $detail['degerler'] = self::listDegerler($pdo, (int) $row['id']);

        return $detail;
    }

    /**
     * S83 GY onay oncesi karar ozeti (gercek mevzuat/politika degeri seed etmez).
     *
     * @return array<string, mixed>|null
     */
    public static function getKararOzeti(PDO $pdo, $id, $subeId = null)
    {
        $detail = self::getPolitikaDetail($pdo, (int) $id);
        if (!$detail) {
            return null;
        }
        $required = SirketCalismaPolitikasiCatalog::requiredCodes();
        $present = [];
        foreach ($detail['degerler'] ?? [] as $deger) {
            $present[(string) $deger['parametre_kodu']] = $deger;
        }
        $eksik = array_values(array_diff($required, array_keys($present)));
        $onceki = null;
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM sirket_calisma_politikalari
                 WHERE state = 'ONAYLANDI' AND id <> :id
                 ORDER BY gecerlilik_baslangic DESC, revision_no DESC, id DESC
                 LIMIT 1"
            );
            $stmt->execute(['id' => (int) $id]);
            $prevRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prevRow) {
                $onceki = self::mapPolitika($prevRow);
                $oncekiDegerler = self::listDegerler($pdo, (int) $prevRow['id']);
                $prevMap = [];
                foreach ($oncekiDegerler as $d) {
                    $prevMap[(string) $d['parametre_kodu']] = (string) ($d['mevcut_deger'] ?? '');
                }
                $diff = [];
                foreach ($present as $code => $deger) {
                    $curr = (string) ($deger['mevcut_deger'] ?? '');
                    $prev = $prevMap[$code] ?? null;
                    if ($prev === null || $prev !== $curr) {
                        $diff[] = [
                            'parametre_kodu' => $code,
                            'onceki' => $prev,
                            'yeni' => $curr,
                        ];
                    }
                }
                $onceki['diff'] = $diff;
                $onceki['policy_version_hash'] = $prevRow['policy_version_hash'] !== null
                    ? (string) $prevRow['policy_version_hash'] : null;
            }
        } catch (\Throwable $e) {
            $onceki = null;
        }

        $etkilenenPersonel = 0;
        if ($subeId !== null) {
            try {
                $c = $pdo->prepare("SELECT COUNT(*) FROM personeller WHERE sube_id = :s AND durum = 'AKTIF'");
                $c->execute(['s' => (int) $subeId]);
                $etkilenenPersonel = (int) $c->fetchColumn();
            } catch (\Throwable $e) {
                $etkilenenPersonel = 0;
            }
        }

        $hashDegisecek = true;
        if ($onceki && isset($onceki['policy_version_hash']) && $detail['policy_version_hash']) {
            $hashDegisecek = (string) $onceki['policy_version_hash'] !== (string) $detail['policy_version_hash'];
        }

        return [
            'politika_id' => (int) $detail['id'],
            'revision_no' => (int) $detail['revision_no'],
            'state' => (string) $detail['state'],
            'gecerlilik_baslangic' => (string) $detail['gecerlilik_baslangic'],
            'gecerlilik_bitis' => $detail['gecerlilik_bitis'],
            'policy_version_hash' => $detail['policy_version_hash'],
            'zorunlu_parametreler' => $required,
            'eksik_parametreler' => $eksik,
            'onceki_onayli' => $onceki,
            'etkilenen_donem_ipucu' => sprintf(
                '%s ve sonrası dönemler (geçerlilik bitiş: %s)',
                $detail['gecerlilik_baslangic'],
                $detail['gecerlilik_bitis'] ?? 'açık'
            ),
            'etkilenen_personel_sayisi' => $etkilenenPersonel,
            'aday_snapshot_etki_notu' => $hashDegisecek
                ? 'Politika hash değişirse mevcut aday/snapshot girdi hash doğrulaması yeniden değerlendirilmelidir.'
                : 'Politika hash önceki onaylı sürümle aynı görünüyor.',
            'katalog_ornek_bicim' => array_map(static function ($code) {
                $meta = SirketCalismaPolitikasiCatalog::meta($code);

                return [
                    'parametre_kodu' => $code,
                    'etiket' => $meta['etiket'] ?? $code,
                    'deger_tipi' => $meta['deger_tipi'] ?? 'SAYISAL',
                    'birim' => $meta['birim'] ?? null,
                    'ornek_bicim' => ($meta['deger_tipi'] ?? '') === 'METIN' ? 'METIN_DEGER' : '0.00',
                ];
            }, $required),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function listDegerler(PDO $pdo, $politikaId)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM sirket_calisma_politika_degerleri WHERE politika_id = :id ORDER BY parametre_kodu ASC'
        );
        $stmt->execute(['id' => (int) $politikaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row) {
            $meta = SirketCalismaPolitikasiCatalog::meta((string) $row['parametre_kodu']);

            return [
                'id' => (int) $row['id'],
                'parametre_kodu' => (string) $row['parametre_kodu'],
                'etiket' => $meta['etiket'] ?? (string) $row['parametre_kodu'],
                'aciklama' => $meta['aciklama'] ?? null,
                'deger_tipi' => (string) $row['deger_tipi'],
                'sayisal_deger' => $row['sayisal_deger'] !== null ? (string) $row['sayisal_deger'] : null,
                'metin_deger' => $row['metin_deger'] !== null ? (string) $row['metin_deger'] : null,
                'birim' => $row['birim'] !== null ? (string) $row['birim'] : ($meta['birim'] ?? null),
                'mevcut_deger' => $row['deger_tipi'] === 'METIN'
                    ? (string) $row['metin_deger']
                    : (string) $row['sayisal_deger'],
            ];
        }, $rows);
    }

    /** @return array<string, mixed> */
    public static function getKatalog()
    {
        $items = [];
        foreach (SirketCalismaPolitikasiCatalog::all() as $code => $meta) {
            $items[] = [
                'parametre_kodu' => $code,
                'etiket' => $meta['etiket'],
                'aciklama' => $meta['aciklama'],
                'deger_tipi' => $meta['deger_tipi'],
                'birim' => $meta['birim'],
                'zorunlu' => true,
            ];
        }

        return ['items' => $items];
    }

    /**
     * Donem icin onayli politika degerlerini dondurur.
     *
     * @return array{politika: array<string, mixed>|null, degerler_by_code: array<string, array<string, mixed>>, policy_version_hash: string|null}
     */
    public static function resolveApprovedForPeriod(PDO $pdo, $donemBaslangic, $donemBitis)
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM sirket_calisma_politikalari
             WHERE state = 'ONAYLANDI'
               AND gecerlilik_baslangic <= :bit
               AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :bas)
             ORDER BY gecerlilik_baslangic DESC, revision_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['bas' => (string) $donemBaslangic, 'bit' => (string) $donemBitis]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['politika' => null, 'degerler_by_code' => [], 'policy_version_hash' => null];
        }
        $degerler = self::listDegerler($pdo, (int) $row['id']);
        $byCode = [];
        foreach ($degerler as $deger) {
            $byCode[(string) $deger['parametre_kodu']] = $deger;
        }

        return [
            'politika' => self::mapPolitika($row),
            'degerler_by_code' => $byCode,
            'policy_version_hash' => $row['policy_version_hash'] !== null ? (string) $row['policy_version_hash'] : null,
        ];
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor */
    public static function createDraft(PDO $pdo, array $payload, array $actor, $requestHash = null)
    {
        $data = self::normalizePayload($payload);
        $pdo->beginTransaction();
        try {
            $revision = 1;
            $parentId = null;
            $open = self::findOpenDraft($pdo, true);
            if ($open) {
                throw new SirketCalismaPolitikasiException('POLICY_DRAFT_EXISTS', 'Aktif taslak politika zaten mevcut.', 409);
            }
            $ins = $pdo->prepare(
                'INSERT INTO sirket_calisma_politikalari (
                    revision_no, parent_politika_id, state, gecerlilik_baslangic, gecerlilik_bitis,
                    aciklama, hazirlayan_id, created_by, updated_by
                 ) VALUES (
                    :revision_no, :parent_id, \'TASLAK\', :baslangic, :bitis,
                    :aciklama, :hazirlayan, :created_by, :updated_by
                 )'
            );
            $ins->execute([
                'revision_no' => $revision,
                'parent_id' => $parentId,
                'baslangic' => $data['gecerlilik_baslangic'],
                'bitis' => $data['gecerlilik_bitis'],
                'aciklama' => $data['aciklama'],
                'hazirlayan' => self::actorId($actor),
                'created_by' => self::actorId($actor),
                'updated_by' => self::actorId($actor),
            ]);
            $id = (int) $pdo->lastInsertId();
            self::upsertDegerler($pdo, $id, $data['degerler']);
            $hash = self::computePolicyHash($pdo, $id);
            $pdo->prepare('UPDATE sirket_calisma_politikalari SET policy_version_hash = :h WHERE id = :id')
                ->execute(['h' => $hash, 'id' => $id]);
            $detail = self::getPolitikaDetail($pdo, $id);
            self::audit($pdo, 'CREATE', null, $detail, $actor, $requestHash, $id);
            $pdo->commit();

            return $detail;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor */
    public static function updateDraft(PDO $pdo, $id, array $payload, array $actor, $requestHash = null)
    {
        $data = self::normalizePayload($payload);
        $pdo->beginTransaction();
        try {
            $row = self::fetchPolitika($pdo, (int) $id, true);
            if (!$row) {
                throw new SirketCalismaPolitikasiException('POLICY_NOT_FOUND', 'Politika bulunamadi.', 404);
            }
            if ((string) $row['state'] !== 'TASLAK') {
                throw new SirketCalismaPolitikasiException('POLICY_NOT_EDITABLE', 'Yalniz taslak politika duzenlenebilir.', 409);
            }
            $onceki = self::getPolitikaDetail($pdo, (int) $id);
            $upd = $pdo->prepare(
                'UPDATE sirket_calisma_politikalari SET
                    gecerlilik_baslangic = :baslangic, gecerlilik_bitis = :bitis, aciklama = :aciklama,
                    updated_by = :updated_by WHERE id = :id'
            );
            $upd->execute([
                'baslangic' => $data['gecerlilik_baslangic'],
                'bitis' => $data['gecerlilik_bitis'],
                'aciklama' => $data['aciklama'],
                'updated_by' => self::actorId($actor),
                'id' => (int) $id,
            ]);
            self::upsertDegerler($pdo, (int) $id, $data['degerler']);
            $hash = self::computePolicyHash($pdo, (int) $id);
            $pdo->prepare('UPDATE sirket_calisma_politikalari SET policy_version_hash = :h WHERE id = :id')
                ->execute(['h' => $hash, 'id' => (int) $id]);
            $sonraki = self::getPolitikaDetail($pdo, (int) $id);
            self::audit($pdo, 'UPDATE', $onceki, $sonraki, $actor, $requestHash, (int) $id);
            $pdo->commit();

            return $sonraki;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $actor */
    public static function submitForApproval(PDO $pdo, $id, array $actor, $requestHash = null)
    {
        $pdo->beginTransaction();
        try {
            $row = self::fetchPolitika($pdo, (int) $id, true);
            if (!$row) {
                throw new SirketCalismaPolitikasiException('POLICY_NOT_FOUND', 'Politika bulunamadi.', 404);
            }
            if ((string) $row['state'] !== 'TASLAK') {
                throw new SirketCalismaPolitikasiException('POLICY_INVALID_STATE', 'Yalniz taslak politika onaya gonderilebilir.', 409);
            }
            self::assertCompleteDegerler($pdo, (int) $id);
            $onceki = self::mapPolitika($row);
            $pdo->prepare("UPDATE sirket_calisma_politikalari SET state = 'ONAY_BEKLIYOR', updated_by = :u WHERE id = :id")
                ->execute(['u' => self::actorId($actor), 'id' => (int) $id]);
            $sonraki = self::getPolitikaDetail($pdo, (int) $id);
            self::audit($pdo, 'SUBMIT', $onceki, $sonraki, $actor, $requestHash, (int) $id);
            $pdo->commit();

            return $sonraki;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $actor */
    public static function approve(PDO $pdo, $id, array $actor, $requestHash = null)
    {
        $pdo->beginTransaction();
        try {
            $row = self::fetchPolitika($pdo, (int) $id, true);
            if (!$row) {
                throw new SirketCalismaPolitikasiException('POLICY_NOT_FOUND', 'Politika bulunamadi.', 404);
            }
            if ((string) $row['state'] !== 'ONAY_BEKLIYOR') {
                throw new SirketCalismaPolitikasiException('POLICY_INVALID_STATE', 'Yalniz onay bekleyen politika onaylanabilir.', 409);
            }
            self::assertCompleteDegerler($pdo, (int) $id);
            $openApproved = $pdo->query(
                "SELECT id FROM sirket_calisma_politikalari WHERE state = 'ONAYLANDI' AND gecerlilik_bitis IS NULL LIMIT 1 FOR UPDATE"
            )->fetch(PDO::FETCH_ASSOC);
            if ($openApproved) {
                $end = (new \DateTimeImmutable((string) $row['gecerlilik_baslangic']))->modify('-1 day')->format('Y-m-d');
                $pdo->prepare(
                    "UPDATE sirket_calisma_politikalari SET gecerlilik_bitis = :bitis, updated_by = :u WHERE id = :id"
                )->execute(['bitis' => $end, 'u' => self::actorId($actor), 'id' => (int) $openApproved['id']]);
            }
            $onceki = self::mapPolitika($row);
            $pdo->prepare(
                "UPDATE sirket_calisma_politikalari SET state = 'ONAYLANDI', onaylayan_id = :o, onay_zamani = NOW(), updated_by = :u WHERE id = :id"
            )->execute(['o' => self::actorId($actor), 'u' => self::actorId($actor), 'id' => (int) $id]);
            $sonraki = self::getPolitikaDetail($pdo, (int) $id);
            self::audit($pdo, 'APPROVE', $onceki, $sonraki, $actor, $requestHash, (int) $id);
            $pdo->commit();

            return $sonraki;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $actor */
    public static function cancel(PDO $pdo, $id, $neden, array $actor, $requestHash = null)
    {
        $pdo->beginTransaction();
        try {
            $row = self::fetchPolitika($pdo, (int) $id, true);
            if (!$row) {
                throw new SirketCalismaPolitikasiException('POLICY_NOT_FOUND', 'Politika bulunamadi.', 404);
            }
            if (in_array((string) $row['state'], ['IPTAL', 'ONAYLANDI'], true)) {
                throw new SirketCalismaPolitikasiException('POLICY_INVALID_STATE', 'Bu politika iptal edilemez.', 409);
            }
            $onceki = self::mapPolitika($row);
            $pdo->prepare(
                "UPDATE sirket_calisma_politikalari SET state = 'IPTAL', iptal_eden_id = :i, iptal_zamani = NOW(),
                 iptal_nedeni = :n, updated_by = :u WHERE id = :id"
            )->execute(['i' => self::actorId($actor), 'n' => trim((string) $neden), 'u' => self::actorId($actor), 'id' => (int) $id]);
            $sonraki = self::getPolitikaDetail($pdo, (int) $id);
            self::audit($pdo, 'CANCEL', $onceki, $sonraki, $actor, $requestHash, (int) $id);
            $pdo->commit();

            return $sonraki;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Onayli sirket politika degerlerini mevzuat map formatina donusturur.
     *
     * @param array<string, array<string, mixed>> $degerlerByCode
     * @return array<string, array<string, mixed>>
     */
    public static function toEngineParams(array $degerlerByCode)
    {
        $out = [];
        foreach ($degerlerByCode as $code => $deger) {
            $out[$code] = [
                'parametre_kodu' => $code,
                'deger_tipi' => (string) $deger['deger_tipi'],
                'sayisal_deger' => $deger['sayisal_deger'] ?? null,
                'metin_deger' => $deger['metin_deger'] ?? null,
                'birim' => $deger['birim'] ?? null,
                'kaynak' => 'SIRKET_POLITIKASI',
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function normalizePayload(array $payload)
    {
        $baslangic = trim((string) ($payload['gecerlilik_baslangic'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baslangic)) {
            throw new SirketCalismaPolitikasiException('VALIDATION_ERROR', 'gecerlilik_baslangic gecersiz.', 400);
        }
        $bitis = isset($payload['gecerlilik_bitis']) && $payload['gecerlilik_bitis'] !== null && $payload['gecerlilik_bitis'] !== ''
            ? trim((string) $payload['gecerlilik_bitis']) : null;
        if ($bitis !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bitis)) {
            throw new SirketCalismaPolitikasiException('VALIDATION_ERROR', 'gecerlilik_bitis gecersiz.', 400);
        }
        $degerler = $payload['degerler'] ?? [];
        if (!is_array($degerler) || count($degerler) === 0) {
            throw new SirketCalismaPolitikasiException('VALIDATION_ERROR', 'degerler zorunludur.', 400);
        }

        return [
            'gecerlilik_baslangic' => $baslangic,
            'gecerlilik_bitis' => $bitis,
            'aciklama' => isset($payload['aciklama']) ? trim((string) $payload['aciklama']) : null,
            'degerler' => $degerler,
        ];
    }

    /** @param array<int, array<string, mixed>> $degerler */
    private static function upsertDegerler(PDO $pdo, $politikaId, array $degerler)
    {
        $pdo->prepare('DELETE FROM sirket_calisma_politika_degerleri WHERE politika_id = :id')
            ->execute(['id' => (int) $politikaId]);
        $ins = $pdo->prepare(
            'INSERT INTO sirket_calisma_politika_degerleri (
                politika_id, parametre_kodu, deger_tipi, sayisal_deger, metin_deger, birim
             ) VALUES (:pid, :kod, :tip, :sayisal, :metin, :birim)'
        );
        foreach ($degerler as $deger) {
            $code = strtoupper(trim((string) ($deger['parametre_kodu'] ?? '')));
            if (!SirketCalismaPolitikasiCatalog::isKnown($code)) {
                throw new SirketCalismaPolitikasiException('POLICY_UNKNOWN_CODE', 'Bilinmeyen parametre kodu: ' . $code, 400);
            }
            $meta = SirketCalismaPolitikasiCatalog::meta($code);
            $tip = (string) $meta['deger_tipi'];
            $sayisal = null;
            $metin = null;
            if ($tip === 'METIN') {
                $metin = trim((string) ($deger['metin_deger'] ?? $deger['mevcut_deger'] ?? ''));
                if ($metin === '') {
                    throw new SirketCalismaPolitikasiException('VALIDATION_ERROR', $code . ' metin degeri zorunlu.', 400);
                }
            } else {
                $raw = $deger['sayisal_deger'] ?? $deger['mevcut_deger'] ?? null;
                if ($raw === null || $raw === '') {
                    throw new SirketCalismaPolitikasiException('VALIDATION_ERROR', $code . ' sayisal degeri zorunlu.', 400);
                }
                $sayisal = (string) $raw;
            }
            $ins->execute([
                'pid' => (int) $politikaId,
                'kod' => $code,
                'tip' => $tip,
                'sayisal' => $sayisal,
                'metin' => $metin,
                'birim' => $meta['birim'],
            ]);
        }
    }

    private static function assertCompleteDegerler(PDO $pdo, $politikaId)
    {
        $existing = self::listDegerler($pdo, (int) $politikaId);
        $codes = array_map(static function (array $row) {
            return (string) $row['parametre_kodu'];
        }, $existing);
        $missing = array_diff(SirketCalismaPolitikasiCatalog::requiredCodes(), $codes);
        if (count($missing) > 0) {
            throw new SirketCalismaPolitikasiException('POLICY_INCOMPLETE', 'Eksik politika degerleri var.', 409, [
                'eksik_kodlar' => array_values($missing),
            ]);
        }
    }

    private static function computePolicyHash(PDO $pdo, $politikaId)
    {
        $degerler = self::listDegerler($pdo, (int) $politikaId);
        $map = [];
        foreach ($degerler as $deger) {
            $map[(string) $deger['parametre_kodu']] = $deger['deger_tipi'] === 'METIN'
                ? (string) $deger['metin_deger']
                : (string) $deger['sayisal_deger'];
        }

        return MaasHesaplamaEngine::hashCanonical($map);
    }

    /** @return array<string, mixed>|null */
    private static function fetchPolitika(PDO $pdo, $id, $forUpdate = false)
    {
        $sql = 'SELECT * FROM sirket_calisma_politikalari WHERE id = :id LIMIT 1';
        if ($forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private static function findOpenDraft(PDO $pdo, $forUpdate = false)
    {
        $sql = "SELECT * FROM sirket_calisma_politikalari WHERE state IN ('TASLAK','ONAY_BEKLIYOR') LIMIT 1";
        if ($forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapPolitika(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'revision_no' => (int) $row['revision_no'],
            'parent_politika_id' => $row['parent_politika_id'] !== null ? (int) $row['parent_politika_id'] : null,
            'state' => (string) $row['state'],
            'gecerlilik_baslangic' => (string) $row['gecerlilik_baslangic'],
            'gecerlilik_bitis' => $row['gecerlilik_bitis'] !== null ? (string) $row['gecerlilik_bitis'] : null,
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'policy_version_hash' => $row['policy_version_hash'] !== null ? (string) $row['policy_version_hash'] : null,
            'hazirlayan_id' => $row['hazirlayan_id'] !== null ? (int) $row['hazirlayan_id'] : null,
            'hazirlayan_ad' => $row['hazirlayan_ad'] ?? null,
            'onaylayan_id' => $row['onaylayan_id'] !== null ? (int) $row['onaylayan_id'] : null,
            'onaylayan_ad' => $row['onaylayan_ad'] ?? null,
            'onay_zamani' => $row['onay_zamani'] ?? null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed>|null $onceki @param array<string, mixed>|null $sonraki */
    private static function audit(PDO $pdo, $aksiyon, $onceki, $sonraki, array $actor, $requestHash, $politikaId)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO sirket_calisma_politika_auditleri (
                politika_id, aksiyon, onceki_snapshot, sonraki_snapshot, actor_id, actor_rol, request_hash
             ) VALUES (:pid, :aksiyon, :onceki, :sonraki, :actor, :rol, :hash)'
        );
        $stmt->execute([
            'pid' => $politikaId,
            'aksiyon' => (string) $aksiyon,
            'onceki' => $onceki ? json_encode($onceki, JSON_UNESCAPED_UNICODE) : null,
            'sonraki' => $sonraki ? json_encode($sonraki, JSON_UNESCAPED_UNICODE) : null,
            'actor' => self::actorId($actor),
            'rol' => isset($actor['rol']) ? (string) $actor['rol'] : null,
            'hash' => $requestHash,
        ]);
    }

    /** @param array<string, mixed> $actor */
    private static function actorId(array $actor)
    {
        return isset($actor['id']) ? (int) $actor['id'] : null;
    }
}
