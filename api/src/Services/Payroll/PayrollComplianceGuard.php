<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;

/**
 * Kritik bordro uyum blocker owner'i (S87).
 *
 * Karar etiketleri:
 * - RESMI_KURAL: Is Kanunu md.41/46, SGK hastalik hali (kaynak disi kural uretilmez)
 * - SIRKET_KARARI: serbest zaman 1.5x, normal hastalik ilk 2 gun odemez, 45s=2700dk, %25 FSC yok
 * - TEKNIK_GUARD: preflight / hard-block / idempotency
 * - BILINCLI_KAPSAM_DISI: zorunlu/olaganustu calisma istisnasi modeli yok
 */
final class PayrollComplianceGuard
{
    public const CONTRACT_VERSION = 'S87_PAYROLL_COMPLIANCE_V1';

    /** SIRKET_KARARI + RESMI_KURAL (45 saat) */
    public const HAFTALIK_NORMAL_CALISMA_DAKIKA = 2700;
    public const AYLIK_NORMAL_CALISMA_SAATI = 225;
    public const GUNLUK_CALISMA_SAATI = 7.5;

    /** RESMI_KURAL — yillik fazla calisma azami (270 saat) */
    public const YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA = 16200;
    public const YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA = 15600;

    /** SIRKET_KARARI — serbest zaman donusum katsayisi */
    public const SERBEST_ZAMAN_DONUSUM_KATSAYISI = 1.5;

    // --- Blocker codes ---
    public const BLOCKER_ODEME_TERCIHI_KARAR_BEKLIYOR = 'FAZLA_CALISMA_ODEME_TERCIHI_KARAR_BEKLIYOR';
    public const BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK = 'SERBEST_ZAMAN_IMZALI_TALEP_KANIT_EKSIK';
    public const BLOCKER_SERBEST_ZAMAN_CIFT_ETKI = 'SERBEST_ZAMAN_UCRET_CIFT_ETKI';
    public const BLOCKER_ONSEKIZ_YAS_FAZLA_CALISMA = 'ONSEKIZ_YAS_ALTI_FAZLA_CALISMA';
    public const BLOCKER_ONSEKIZ_YAS_GECE = 'ONSEKIZ_YAS_ALTI_GECE_CALISMASI';
    public const BLOCKER_DOGUM_TARIHI_REQUIRED = 'DOGUM_TARIHI_REQUIRED';
    public const BLOCKER_YILLIK_270_SAAT_ASIMI = 'YILLIK_FAZLA_CALISMA_270_SAAT_ASIMI';
    public const BLOCKER_HASTALIK_POLITIKA_COZULEMEDI = 'NORMAL_HASTALIK_POLITIKASI_COZULEMEDI';
    public const BLOCKER_DEVAMSIZLIK_PARITY = 'DEVAMSIZLIK_HAFTA_TATILI_PARITY_UYUSMAZLIK';
    public const BLOCKER_SGK_POLITIKA_BELIRSIZ = 'SGK_SIRKET_POLITIKA_GIRDISI_BELIRSIZ';
    public const BLOCKER_COMPLIANCE_SCHEMA_UNAVAILABLE = 'COMPLIANCE_SCHEMA_UNAVAILABLE';

    public const POLICY_NORMAL_HASTALIK_ILK_IKI_GUN = 'NORMAL_HASTALIK_ILK_IKI_GUN_ISVEREN_ODEMESI';
    public const POLICY_HAFTALIK_NORMAL_DAKIKA = 'HAFTALIK_NORMAL_CALISMA_DAKIKA';
    public const POLICY_HASTALIK_HAYIR = 'HAYIR';

    public const KALEM_NORMAL_HASTALIK_ILK_2_GUN = 'NORMAL_HASTALIK_ILK_2_GUN_ODENMEDI';
    public const KALEM_HAFTA_TATILI_HAK_KAYBI = 'HAFTA_TATILI_HAK_KAYBI_KESINTISI';
    public const KALEM_DEVAMSIZLIK_FIILI = 'DEVAMSIZLIK_FIILI_GUN_KESINTISI';

    /** @return array{ok:bool, message?:string, code?:string} */
    public static function validateSerbestZamanKanit(array $payload, ?array $belgeRow, int $personelId): array
    {
        $talepTarihi = isset($payload['talep_tarihi']) ? trim((string) $payload['talep_tarihi']) : '';
        $belgeId = isset($payload['imzali_talep_belge_id']) ? (int) $payload['imzali_talep_belge_id'] : 0;
        $gerekce = isset($payload['gerekce']) ? trim((string) $payload['gerekce']) : '';

        if ($talepTarihi === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $talepTarihi)) {
            return [
                'ok' => false,
                'code' => self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                'message' => 'SERBEST_ZAMAN icin talep_tarihi zorunludur.',
            ];
        }
        if ($belgeId < 1) {
            return [
                'ok' => false,
                'code' => self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                'message' => 'SERBEST_ZAMAN icin imzali talep belgesi zorunludur.',
            ];
        }
        if ($gerekce === '') {
            return [
                'ok' => false,
                'code' => self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                'message' => 'SERBEST_ZAMAN icin gerekce/not zorunludur.',
            ];
        }
        if ($belgeRow === null) {
            return [
                'ok' => false,
                'code' => self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                'message' => 'Imzali talep belgesi bulunamadi.',
            ];
        }
        if ((int) ($belgeRow['personel_id'] ?? 0) !== $personelId) {
            return [
                'ok' => false,
                'code' => self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                'message' => 'Imzali talep belgesi baska personele ait.',
            ];
        }
        $state = strtoupper(trim((string) ($belgeRow['state'] ?? '')));
        if ($state === 'IPTAL') {
            return [
                'ok' => false,
                'code' => self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                'message' => 'Imzali talep belgesi iptal edilmis.',
            ];
        }
        $tur = strtoupper(trim((string) ($belgeRow['surec_turu'] ?? '')));
        if ($tur !== 'BELGE') {
            return [
                'ok' => false,
                'code' => self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                'message' => 'Imzali talep belgesi gecerli bir personel belgesi degil.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * 18 yasini doldurmamis mi?
     * 18. dogum gununden once → true (block)
     * 18. dogum gununde → false (block degil)
     *
     * @return array{under_18:bool, missing_dob:bool}
     */
    public static function resolveUnder18(?string $dogumTarihi, string $referansTarihi): array
    {
        if ($dogumTarihi === null || trim($dogumTarihi) === '') {
            return ['under_18' => false, 'missing_dob' => true];
        }
        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dogumTarihi))
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($referansTarihi))
        ) {
            return ['under_18' => false, 'missing_dob' => true];
        }

        try {
            $dob = new \DateTimeImmutable(trim($dogumTarihi));
            $ref = new \DateTimeImmutable(trim($referansTarihi));
        } catch (\Throwable $e) {
            return ['under_18' => false, 'missing_dob' => true];
        }

        $eighteenth = $dob->modify('+18 years');
        // 18. dogum gununde block degil: ref < eighteenth → under 18
        return [
            'under_18' => $ref < $eighteenth,
            'missing_dob' => false,
        ];
    }

    /**
     * @param list<array{fazla_calisma_dakika:int}> $kapanmisHaftalar
     * @return array{kullanilan:int, projected:int, asildi:bool, yaklasiyor:bool}
     */
    public static function evaluateYillikLimit(array $kapanmisHaftalar, int $pendingDakika): array
    {
        $kullanilan = 0;
        foreach ($kapanmisHaftalar as $row) {
            $dk = (int) ($row['fazla_calisma_dakika'] ?? 0);
            if ($dk > 0) {
                $kullanilan += $dk;
            }
        }
        $pending = max(0, $pendingDakika);
        $projected = $kullanilan + $pending;

        return [
            'kullanilan' => $kullanilan,
            'projected' => $projected,
            'asildi' => $projected > self::YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA,
            'yaklasiyor' => $projected >= self::YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA,
        ];
    }

    /**
     * Effective haftalik normal dk: policy HAFTALIK_NORMAL_CALISMA_DAKIKA varsa onu kullan,
     * yoksa production default 2700. Yasal 270 saat limiti bu metodun kapsami disinda.
     *
     * @param array<string, mixed> $params
     */
    public static function resolveHaftalikNormalCalismaDakika(array $params = []): int
    {
        if (isset($params[self::POLICY_HAFTALIK_NORMAL_DAKIKA]) && $params[self::POLICY_HAFTALIK_NORMAL_DAKIKA] !== '') {
            $raw = trim((string) $params[self::POLICY_HAFTALIK_NORMAL_DAKIKA]);
            if (strpos($raw, '.') !== false) {
                $raw = explode('.', $raw, 2)[0];
            }
            $v = (int) $raw;
            if ($v > 0) {
                return $v;
            }
        }

        return self::HAFTALIK_NORMAL_CALISMA_DAKIKA;
    }

    /** 225 saat × 60 = 13500 dk/ay; haftalik 2700 ile tutarlilik (production default contract). */
    public static function assertWeeklyMonthlyParity(): bool
    {
        $weeklyHours = (float) self::HAFTALIK_NORMAL_CALISMA_DAKIKA / 60.0;
        $dailyHours = (float) self::GUNLUK_CALISMA_SAATI;
        $weeklyFromDaily = $dailyHours * 6.0; // production default: 6 is gunu × 7.5 = 45
        $monthlyDk = self::AYLIK_NORMAL_CALISMA_SAATI * 60;
        $fiveWeekDk = self::HAFTALIK_NORMAL_CALISMA_DAKIKA * 5;

        return abs($weeklyHours - 45.0) < 0.0001
            && abs($weeklyFromDaily - 45.0) < 0.0001
            && self::AYLIK_NORMAL_CALISMA_SAATI === 225
            && $monthlyDk === $fiveWeekDk;
    }

    /**
     * SIRKET_KARARI odeme bantlari: FSC yok; esik uzeri %50 FM.
     * Eşik authoritative policy HAFTALIK_NORMAL_CALISMA_DAKIKA'dan gelir (default 2700).
     *
     * @return array{fs_dk:int, fm_dk:int}
     */
    public static function hesaplaHaftalikBantlarSirketKarari(int $totalDk, ?int $haftalikNormalDk = null): array
    {
        $contract = ($haftalikNormalDk !== null && $haftalikNormalDk > 0)
            ? $haftalikNormalDk
            : self::HAFTALIK_NORMAL_CALISMA_DAKIKA;
        if ($totalDk <= $contract) {
            return ['fs_dk' => 0, 'fm_dk' => 0];
        }

        // SIRKET_KARARI: FSC (%25) kullanilmaz; normal esik uzeri tamamen %50 FM.
        return [
            'fs_dk' => 0,
            'fm_dk' => $totalDk - $contract,
        ];
    }

    /**
     * Donem personelleri icin odeme tercihi / yas / 270 saat / serbest zaman cift etki blocker'lari.
     *
     * @return list<array<string, mixed>>
     */
    public static function collectPeriodBlockers(
        PDO $pdo,
        int $subeId,
        string $donemBaslangic,
        string $donemBitis,
        array $personelIds
    ): array {
        if ($personelIds === []) {
            return [];
        }

        $schemaIssue = self::resolveComplianceSchemaIssue($pdo);
        if ($schemaIssue !== null) {
            return [self::schemaUnavailableBlocker($schemaIssue)];
        }

        try {
            return self::collectPeriodBlockersUnsafe(
                $pdo,
                $subeId,
                $donemBaslangic,
                $donemBitis,
                $personelIds
            );
        } catch (\Throwable $e) {
            return [self::schemaUnavailableBlocker('QUERY_FAILED', get_class($e))];
        }
    }

    /**
     * @param list<int> $personelIds
     * @return list<array<string, mixed>>
     */
    private static function collectPeriodBlockersUnsafe(
        PDO $pdo,
        int $subeId,
        string $donemBaslangic,
        string $donemBitis,
        array $personelIds
    ): array {
        $items = [];
        // Caller may pass personel_id-keyed maps; PDO positional binds require 0-based values.
        $personelIds = array_values(array_map('intval', $personelIds));
        $hasEvidenceCols = self::columnExists($pdo, 'fazla_calisma_odeme_tercihleri', 'imzali_talep_belge_id');
        $placeholders = implode(',', array_fill(0, count($personelIds), '?'));
        $evidenceSelect = $hasEvidenceCols
            ? 't.imzali_talep_belge_id, t.talep_tarihi, t.gerekce,'
            : 'NULL AS imzali_talep_belge_id, NULL AS talep_tarihi, t.gerekce,';
        $sql = "SELECT t.id, t.snapshot_id, t.personel_id, t.odeme_tipi,
                       t.fazla_calisma_dakika, {$evidenceSelect}
                       t.hafta_baslangic, t.hafta_bitis
                FROM fazla_calisma_odeme_tercihleri t
                WHERE t.personel_id IN ($placeholders)
                  AND t.hafta_baslangic <= ?
                  AND t.hafta_bitis >= ?
                  AND t.fazla_calisma_dakika > 0";
        $params = $personelIds;
        $params[] = $donemBitis;
        $params[] = $donemBaslangic;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tercihler = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Also include weeks with OT but no preference row (synthetic KARAR_BEKLIYOR)
        if (self::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            $sql2 = "SELECT s.id AS snapshot_id, s.personel_id, s.fazla_calisma_dakika,
                            s.hafta_baslangic, s.hafta_bitis
                     FROM haftalik_kapanis_satirlari s
                     INNER JOIN haftalik_kapanislar k ON k.id = s.kapanis_id
                     LEFT JOIN fazla_calisma_odeme_tercihleri t ON t.snapshot_id = s.id
                     WHERE k.sube_id = ?
                       AND s.personel_id IN ($placeholders)
                       AND s.hafta_baslangic <= ?
                       AND s.hafta_bitis >= ?
                       AND s.fazla_calisma_dakika > 0
                       AND t.id IS NULL
                       AND s.state = 'KAPANDI'";
            $params2 = array_merge([(int) $subeId], $personelIds, [$donemBitis, $donemBaslangic]);
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($params2);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $items[] = self::blockerItem(
                    self::BLOCKER_ODEME_TERCIHI_KARAR_BEKLIYOR,
                    'Fazla calisma odeme tercihi KARAR_BEKLIYOR; bordro kesinlestirme engellendi.',
                    (int) $row['personel_id'],
                    'fazla_calisma_odeme_tercihi',
                    (int) $row['snapshot_id'],
                    ['odeme_tipi' => 'KARAR_BEKLIYOR']
                );
            }
        }

        foreach ($tercihler as $t) {
            $odeme = (string) ($t['odeme_tipi'] ?? '');
            $personelId = (int) $t['personel_id'];
            $snapshotId = (int) $t['snapshot_id'];

            if ($odeme === 'KARAR_BEKLIYOR') {
                $items[] = self::blockerItem(
                    self::BLOCKER_ODEME_TERCIHI_KARAR_BEKLIYOR,
                    'Fazla calisma odeme tercihi KARAR_BEKLIYOR; bordro kesinlestirme engellendi.',
                    $personelId,
                    'fazla_calisma_odeme_tercihi',
                    $snapshotId,
                    ['odeme_tipi' => $odeme]
                );
            }

            if ($odeme === 'SERBEST_ZAMAN') {
                $belgeId = isset($t['imzali_talep_belge_id']) ? (int) $t['imzali_talep_belge_id'] : 0;
                $talep = trim((string) ($t['talep_tarihi'] ?? ''));
                $gerekce = trim((string) ($t['gerekce'] ?? ''));
                if ($belgeId < 1 || $talep === '' || $gerekce === '') {
                    $items[] = self::blockerItem(
                        self::BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK,
                        'SERBEST_ZAMAN secilmis fakat imzali talep kaniti eksik.',
                        $personelId,
                        'fazla_calisma_odeme_tercihi',
                        $snapshotId,
                        []
                    );
                }
            }
        }

        // Age + yearly OT for personeller with birth date
        if (self::tableExists($pdo, 'personeller') && self::columnExists($pdo, 'personeller', 'dogum_tarihi')) {
            $sqlP = "SELECT id, dogum_tarihi FROM personeller WHERE id IN ($placeholders)";
            $stmtP = $pdo->prepare($sqlP);
            $stmtP->execute($personelIds);
            $dobMap = [];
            foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                $dobMap[(int) $p['id']] = $p['dogum_tarihi'] ?? null;
            }

            foreach ($tercihler as $t) {
                if ((int) ($t['fazla_calisma_dakika'] ?? 0) <= 0) {
                    continue;
                }
                $pid = (int) $t['personel_id'];
                $ref = (string) ($t['hafta_bitis'] ?? $donemBitis);
                $age = self::resolveUnder18(
                    isset($dobMap[$pid]) ? ($dobMap[$pid] !== null ? (string) $dobMap[$pid] : null) : null,
                    $ref
                );
                if ($age['missing_dob']) {
                    $items[] = self::blockerItem(
                        self::BLOCKER_DOGUM_TARIHI_REQUIRED,
                        'Dogum tarihi olmadan fazla calisma islemi yapilamaz.',
                        $pid,
                        'personel',
                        $pid,
                        []
                    );
                } elseif ($age['under_18']) {
                    $items[] = self::blockerItem(
                        self::BLOCKER_ONSEKIZ_YAS_FAZLA_CALISMA,
                        '18 yasini doldurmamis personelde fazla calisma bordroya aktarilamaz.',
                        $pid,
                        'personel',
                        $pid,
                        []
                    );
                }
            }
        }

        return $items;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }

                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function resolveComplianceSchemaIssue(PDO $pdo): ?string
    {
        foreach ([
            'fazla_calisma_odeme_tercihleri',
            'haftalik_kapanis_satirlari',
            'haftalik_kapanislar',
            'personeller',
            'yillik_fazla_calisma_kilitleri',
        ] as $table) {
            if (!self::tableExists($pdo, $table)) {
                return 'MISSING_TABLE:' . $table;
            }
        }

        foreach ([
            ['fazla_calisma_odeme_tercihleri', 'imzali_talep_belge_id'],
            ['fazla_calisma_odeme_tercihleri', 'talep_tarihi'],
            ['fazla_calisma_odeme_tercihleri', 'gerekce'],
            ['personeller', 'dogum_tarihi'],
        ] as $required) {
            if (!self::columnExists($pdo, $required[0], $required[1])) {
                return 'MISSING_COLUMN:' . $required[0] . '.' . $required[1];
            }
        }

        return null;
    }

    private static function schemaUnavailableBlocker(string $reason, ?string $exceptionType = null): array
    {
        $metadata = ['reason' => $reason];
        if ($exceptionType !== null && $exceptionType !== '') {
            $metadata['exception_type'] = $exceptionType;
        }

        return self::blockerItem(
            self::BLOCKER_COMPLIANCE_SCHEMA_UNAVAILABLE,
            'Bordro uyumluluk semasi okunamadi; snapshot ve bordro islemi guvenli olarak durduruldu.',
            null,
            'compliance_schema',
            null,
            $metadata
        );
    }

    /**
     * @return array{severity:string, code:string, message:string, record_type:?string, record_id:?int, personel_id:?int, metadata:array}
     */
    public static function blockerItem(
        string $code,
        string $message,
        ?int $personelId = null,
        ?string $recordType = null,
        ?int $recordId = null,
        array $metadata = []
    ): array {
        return [
            'severity' => 'BLOCKER',
            'code' => $code,
            'message' => $message,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'personel_id' => $personelId,
            'metadata' => $metadata,
        ];
    }

    public static function loadBelgeKaydi(PDO $pdo, int $belgeId): ?array
    {
        if ($belgeId < 1 || !self::tableExists($pdo, 'surecler')) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT id, personel_id, surec_turu, state, alt_tur
             FROM surecler
             WHERE id = :id AND surec_turu = 'BELGE'
             LIMIT 1"
        );
        $stmt->execute(['id' => $belgeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Active SERBEST_ZAMAN_OLUSUM var mi? (cift etki kontrolu icin)
     */
    public static function hasActiveSerbestZamanOlusum(PDO $pdo, int $tercihId): bool
    {
        if ($tercihId < 1 || !self::tableExists($pdo, 'serbest_zaman_events')) {
            return false;
        }
        $sql = "SELECT o.id
                FROM serbest_zaman_events o
                WHERE o.event_tipi = 'SERBEST_ZAMAN_OLUSUM'
                  AND o.kaynak_odeme_tercihi_id = :tercih_id
                  AND NOT EXISTS (
                    SELECT 1 FROM serbest_zaman_events i
                    WHERE i.event_tipi = 'SERBEST_ZAMAN_IPTAL'
                      AND i.hedef_event_id = o.id
                  )
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tercih_id' => $tercihId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Snapshot satirlari icin odeme tipi haritasi (personel_id|hafta_baslangic → odeme_tipi).
     *
     * @return array<string, array{odeme_tipi:string, tercih_id:?int, imzali_talep_belge_id:?int}>
     */
    public static function loadOdemeTercihiMap(PDO $pdo, int $personelId, string $donemBaslangic, string $donemBitis): array
    {
        if (!self::tableExists($pdo, 'fazla_calisma_odeme_tercihleri')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id, personel_id, hafta_baslangic, odeme_tipi, imzali_talep_belge_id
             FROM fazla_calisma_odeme_tercihleri
             WHERE personel_id = :pid
               AND hafta_baslangic <= :bitis
               AND hafta_bitis >= :baslangic'
        );
        $stmt->execute([
            'pid' => $personelId,
            'bitis' => $donemBitis,
            'baslangic' => $donemBaslangic,
        ]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (int) $row['personel_id'] . '|' . (string) $row['hafta_baslangic'];
            $map[$key] = [
                'odeme_tipi' => (string) $row['odeme_tipi'],
                'tercih_id' => (int) $row['id'],
                'imzali_talep_belge_id' => isset($row['imzali_talep_belge_id']) ? (int) $row['imzali_talep_belge_id'] : null,
            ];
        }

        return $map;
    }

    /**
     * Kapali haftalik snapshot FM toplami (duplicate hafta: en yuksek kapanis_id).
     *
     * @return list<array{fazla_calisma_dakika:int, hafta_baslangic:string, kapanis_id:int}>
     */
    public static function loadKapanmisYillikFazlaCalisma(PDO $pdo, int $personelId, int $yil): array
    {
        if (!self::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            throw new \RuntimeException(self::BLOCKER_COMPLIANCE_SCHEMA_UNAVAILABLE . ':haftalik_kapanis_satirlari');
        }
        $stmt = $pdo->prepare(
            "SELECT s.fazla_calisma_dakika, s.hafta_baslangic, s.kapanis_id, s.tam_hafta_verisi
             FROM haftalik_kapanis_satirlari s
             WHERE s.personel_id = :pid
               AND s.state = 'KAPANDI'
               AND s.hafta_baslangic >= :yil_bas
               AND s.hafta_baslangic <= :yil_bit
             ORDER BY s.hafta_baslangic ASC, s.kapanis_id DESC"
        );
        $stmt->execute([
            'pid' => $personelId,
            'yil_bas' => sprintf('%04d-01-01', $yil),
            'yil_bit' => sprintf('%04d-12-31', $yil),
        ]);
        $byHafta = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!(int) ($row['tam_hafta_verisi'] ?? 1)) {
                continue;
            }
            $key = (string) $row['hafta_baslangic'];
            if (isset($byHafta[$key])) {
                continue; // highest kapanis_id first due to ORDER BY DESC
            }
            $byHafta[$key] = [
                'fazla_calisma_dakika' => (int) $row['fazla_calisma_dakika'],
                'hafta_baslangic' => $key,
                'kapanis_id' => (int) $row['kapanis_id'],
            ];
        }

        return array_values($byHafta);
    }

    public static function acquireYillikLock(PDO $pdo, int $personelId, int $yil, ?int $actorId): void
    {
        if (!self::tableExists($pdo, 'yillik_fazla_calisma_kilitleri')) {
            throw new \RuntimeException(self::BLOCKER_COMPLIANCE_SCHEMA_UNAVAILABLE . ':yillik_fazla_calisma_kilitleri');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO yillik_fazla_calisma_kilitleri (personel_id, yil, locked_at, locked_by)
             VALUES (:pid, :yil, :locked_at, :locked_by)
             ON DUPLICATE KEY UPDATE locked_at = VALUES(locked_at), locked_by = VALUES(locked_by)'
        );
        $stmt->execute([
            'pid' => $personelId,
            'yil' => $yil,
            'locked_at' => date('Y-m-d H:i:s'),
            'locked_by' => $actorId,
        ]);
        // Row lock for concurrency
        $lock = $pdo->prepare(
            'SELECT id FROM yillik_fazla_calisma_kilitleri
             WHERE personel_id = :pid AND yil = :yil
             FOR UPDATE'
        );
        $lock->execute(['pid' => $personelId, 'yil' => $yil]);
        $lock->fetch(PDO::FETCH_ASSOC);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t"
                );
                $stmt->execute(['t' => $table]);

                return (int) $stmt->fetchColumn() === 1;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t'
            );
            $stmt->execute(['t' => $table]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
