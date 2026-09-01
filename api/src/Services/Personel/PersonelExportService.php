<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Organizasyon\SubeReadModel;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use Medisa\Api\Support\SimpleXlsxWriter;
use PDO;
use RuntimeException;

/**
 * Canonical personnel lifecycle export — real XLSX, no PII, fail-closed reconcile.
 */
final class PersonelExportService
{
    public const SCHEMA_VERSION = 'personel-export-v1';
    public const PAGE_SIZE = 250;

    public const LIST_HEADERS = [
        'personel_id',
        'sicil_no',
        'ad_soyad',
        'aktif_durum',
        'calisan_kapsami',
        'ise_giris_tarihi',
        'isten_cikis_tarihi',
        'sirket',
        'sube',
        'sgk_isvereni',
        'calisma_lokasyonu',
        'departman',
        'bolum',
        'birim',
        'gorev_unvan',
        'pozisyon',
        'personel_tipi',
    ];

    public const CHANGE_TEMPLATE_HEADERS = [
        'islem_tipi',
        'personel_id',
        'sicil_no',
        'ad_soyad',
        'gerekce',
        'yeni_gorev_unvan',
        'yeni_departman',
        'yeni_bolum',
        'yeni_birim',
        'yeni_pozisyon',
        'yeni_sube',
        'yeni_calisma_lokasyonu',
        'yeni_sgk_isvereni',
        'isten_cikis_tarihi',
        'ise_giris_tarihi',
        'not',
    ];

    /**
     * @param array<string, mixed> $user
     * @return array{binary:string, meta:array<string, mixed>}
     */
    public static function buildWorkbook(PDO $pdo, array $user, ?int $activeSubeHeader, string $deploySha): array
    {
        $scope = $activeSubeHeader;
        $where = ['1=1'];
        $params = [];
        $role = OrgScope::normalizeRole($user);
        $unitScoped = in_array($role, OrgScope::BOLUM_ASSIGNMENT_ROLES, true)
            || in_array($role, OrgScope::BIRIM_ASSIGNMENT_ROLES, true);
        if ($unitScoped && !PersonelOrgStructureSchema::hasPersonelScopeColumns($pdo)) {
            $where[] = '1=0';
        } else {
            OrgScope::appendPersonelOrgFilter($where, $params, $user, $scope, 'p', 'org', $pdo);
        }

        $aktiflik = PersonelArchiveGate::effectiveListAktiflik($user, 'tum');
        if ($aktiflik === 'aktif') {
            $where[] = "p.aktif_durum = 'AKTIF'";
        } elseif ($aktiflik === 'pasif') {
            $where[] = "p.aktif_durum = 'PASIF'";
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql");
        $countStmt->execute($params);
        $expectedTotal = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $select = self::exportSelectSql($pdo);
        $sql = "
            SELECT {$select['columns']}
            FROM personeller p
            {$select['joins']}
            WHERE $whereSql
            ORDER BY p.id ASC
        ";

        $seenIds = [];
        $listRows = [];
        $offset = 0;
        while ($offset < $expectedTotal || ($expectedTotal === 0 && $offset === 0)) {
            $pageSql = $sql . ' LIMIT :limit OFFSET :offset';
            $stmt = $pdo->prepare($pageSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', self::PAGE_SIZE, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($batch) === 0) {
                break;
            }
            foreach ($batch as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0 || isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;
                $listRows[] = self::mapExportRow($row);
            }
            $offset += self::PAGE_SIZE;
            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        $actualCount = count($listRows);
        if ($actualCount !== $expectedTotal) {
            throw new RuntimeException(
                'PERSONEL_EXPORT_RECONCILE_FAILED: expected ' . $expectedTotal . ' rows, got ' . $actualCount
            );
        }

        $scopeLabel = self::scopeLabel($pdo, $user, $scope);
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $meta = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at_utc' => $generatedAt,
            'deploy_sha' => $deploySha,
            'filter_scope' => $scopeLabel,
            'active_sube_id' => $scope,
            'row_count' => $actualCount,
            'expected_total' => $expectedTotal,
        ];

        $writer = new SimpleXlsxWriter();
        $writer->addSheet('Personel Listesi', self::LIST_HEADERS, $listRows);
        $writer->addSheet('Özet', ['anahtar', 'deger'], self::summaryRows($meta));
        $writer->addSheet(
            'Değişiklik Girişi',
            self::CHANGE_TEMPLATE_HEADERS,
            [self::changeTemplateExampleRow()]
        );
        $writer->addSheet(
            'Notlar',
            ['konu', 'aciklama'],
            self::notesRows()
        );

        return [
            'binary' => $writer->buildBinary(),
            'meta' => $meta,
        ];
    }

    /** @return array{columns:string,joins:string} */
    private static function exportSelectSql(PDO $pdo): array
    {
        $exitSub = "(SELECT s.baslangic_tarihi FROM surecler s
            WHERE s.personel_id = p.id AND s.surec_turu = 'ISTEN_AYRILMA' AND s.state = 'AKTIF'
            ORDER BY s.baslangic_tarihi DESC, s.id DESC LIMIT 1)";

        $columns = 'p.id, p.sicil_no, p.ad, p.soyad, p.aktif_durum, p.ise_giris_tarihi,'
            . ' s.ad AS sube_adi, d.ad AS departman_adi, g.ad AS gorev_adi, pt.ad AS personel_tipi_adi,'
            . ' ' . $exitSub . ' AS isten_cikis_tarihi';
        $joins = "
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            LEFT JOIN gorevler g ON g.id = p.gorev_id
            LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
        ";

        if (PersonelCalisanKapsamSchema::isReady($pdo)) {
            $columns .= ', p.calisan_kapsami';
        }
        if (OrganizasyonSchema::isSchemaReady($pdo)) {
            $columns .= ', sirket_of_sube.ad AS sirket_adi, sirket_of_sube.id AS sirket_id';
            $joins .= "
            LEFT JOIN sirketler sirket_of_sube ON sirket_of_sube.id = s.sirket_id
            ";
        }
        if (PersonelOrgLocationSchema::isReady($pdo)) {
            $columns .= ', si.ad AS sgk_isveren_adi, cl.ad AS calisma_lokasyonu_adi';
            $joins .= "
            LEFT JOIN sgk_isverenler si ON si.id = p.sgk_isveren_id
            LEFT JOIN calisma_lokasyonlari cl ON cl.id = p.calisma_lokasyonu_id
            ";
        }
        if (PersonelOrgStructureSchema::isReady($pdo)) {
            $columns .= ', b.ad AS bolum_adi, bi.ad AS birim_adi, poz.ad AS pozisyon_adi';
            $joins .= "
            LEFT JOIN bolumler b ON b.id = p.bolum_id
            LEFT JOIN birimler bi ON bi.id = p.birim_id
            LEFT JOIN pozisyonlar poz ON poz.id = p.pozisyon_id
            ";
        }

        return ['columns' => $columns, 'joins' => $joins];
    }

    /** @param array<string, mixed> $row @return list<string> */
    private static function mapExportRow(array $row): array
    {
        $ad = trim((string) ($row['ad'] ?? ''));
        $soyad = trim((string) ($row['soyad'] ?? ''));
        $adSoyad = trim($ad . ' ' . $soyad);

        return [
            (string) (int) ($row['id'] ?? 0),
            (string) ($row['sicil_no'] ?? ''),
            $adSoyad,
            (string) ($row['aktif_durum'] ?? ''),
            PersonelCalisanKapsamService::resolveFromRow($row),
            self::dateOnly($row['ise_giris_tarihi'] ?? null),
            self::dateOnly($row['isten_cikis_tarihi'] ?? null),
            (string) ($row['sirket_adi'] ?? ''),
            (string) ($row['sube_adi'] ?? ''),
            (string) ($row['sgk_isveren_adi'] ?? ''),
            (string) ($row['calisma_lokasyonu_adi'] ?? ''),
            (string) ($row['departman_adi'] ?? ''),
            (string) ($row['bolum_adi'] ?? ''),
            (string) ($row['birim_adi'] ?? ''),
            (string) ($row['gorev_adi'] ?? ''),
            (string) ($row['pozisyon_adi'] ?? ''),
            (string) ($row['personel_tipi_adi'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $meta @return list<list<string>> */
    private static function summaryRows(array $meta): array
    {
        $rows = [];
        foreach ($meta as $key => $value) {
            if ($key === 'expected_total') {
                continue;
            }
            $rows[] = [(string) $key, is_scalar($value) ? (string) $value : json_encode($value)];
        }

        return $rows;
    }

    /** @return list<string> */
    private static function changeTemplateExampleRow(): array
    {
        return [
            'SADECE_NOT',
            '',
            '',
            '',
            'Ornek satir — silin veya degistirin',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'ID kolonlarini degistirmeyin; eslestirme personel_id + sicil_no ile yapilir.',
        ];
    }

    /** @return list<list<string>> */
    private static function notesRows(): array
    {
        return [
            ['personel_id', 'Veritabani birincil anahtar. Degistirmeyin; yanlis ID importu fail-closed reddeder.'],
            ['sicil_no', 'Sicil numarasi; personel_id ile capraz dogrulanir. Celiski blocker uretir.'],
            ['sube / calisma_lokasyonu', 'Ayri kolonlardir (or. Karabuk lokasyonu, Medisa Fabrika subesi). Birbirinden turetilmez.'],
            ['PII', 'TC, IBAN, ucret, telefon ve adres bilgileri bilerek dislanmistir.'],
            ['islem_tipi', 'YENI_GIRIS | ISTEN_AYRILMA | YENIDEN_ISE_ALMA | GOREV_UNVAN_DEGISIKLIGI | DEPARTMAN_BOLUM_BIRIM_POZISYON_DEGISIKLIGI | KALICI_SUBE_DEGISIKLIGI | CALISMA_LOKASYONU_DEGISIKLIGI | SGK_ISVERENI_DEGISIKLIGI | SADECE_NOT | DEGISIKLIK_YOK'],
        ];
    }

    /** @param array<string, mixed> $user */
    private static function scopeLabel(PDO $pdo, array $user, ?int $scope): string
    {
        if ($scope === null || $scope <= 0) {
            return OrgScope::isUnrestricted($user) ? 'TUM_SUBELER' : 'GLOBAL_READ';
        }
        $stmt = $pdo->prepare('SELECT ad, sirket_id FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $scope]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'SUBE_' . $scope;
        }
        $sirketAd = null;
        if (OrganizasyonSchema::isSchemaReady($pdo) && !empty($row['sirket_id'])) {
            $s = $pdo->prepare('SELECT ad FROM sirketler WHERE id = :id LIMIT 1');
            $s->execute(['id' => (int) $row['sirket_id']]);
            $sr = $s->fetch(PDO::FETCH_ASSOC);
            $sirketAd = is_array($sr) ? ($sr['ad'] ?? null) : null;
        }

        return SubeReadModel::tamAd($sirketAd, (string) ($row['ad'] ?? '')) . ' (id=' . $scope . ')';
    }

    /** @param mixed $value */
    private static function dateOnly($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return substr((string) $value, 0, 10);
    }
}
