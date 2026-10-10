<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;

/**
 * Kullanıcı bazlı yetki istisnaları (migration 100) okuma sahibi.
 *
 * Tablo yoksa (100 uygulanmamış kurulum) her okuma boş döner: etkin izinler
 * bugünkü rol matrisiyle birebir aynı kalır. Tek sorgu; şema yoklaması yok.
 */
final class UserYetkiIstisnaSchema
{
    public const TABLE = 'user_yetki_istisnalari';
    public const AUDIT_TABLE = 'user_yetki_auditleri';

    /**
     * İptal edilmemiş ve süresi dolmamış istisnalar (UTC). Süre, çözücüde
     * değerlendirme anında ayrıca kontrol edilir.
     *
     * @return array<int, array{id:int, permission:string, etki:string, sube_id:int|null, gecerlilik_baslangic:string, gecerlilik_bitis:string|null}>
     */
    public static function loadActive(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id, permission, etki, sube_id, gecerlilik_baslangic, gecerlilik_bitis
                   FROM ' . self::TABLE . '
                  WHERE user_id = :user_id
                    AND iptal_edildi_at IS NULL
                    AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis > UTC_TIMESTAMP())
                  ORDER BY id ASC'
            );
            $stmt->execute(['user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            // Yalnız "tablo yok" (migration 100 öncesi) istisnasız sayılır. Başka bir
            // okuma hatası DENY'ı sessizce düşürmesin diye fail-closed yükselir.
            if (self::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }

        return array_map(static fn (array $row): array => self::mapRow($row), $rows);
    }

    /**
     * Bir kullanıcının tüm istisna geçmişi (iptal edilenler dahil), yeniden eskiye.
     *
     * @return array{schema_ready: bool, rows: array<int, array<string, mixed>>}
     */
    public static function loadHistory(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, user_id, permission, etki, sube_id, gecerlilik_baslangic, gecerlilik_bitis,
                        veren_user_id, hedef_rol_snapshot, gerekce, created_at,
                        iptal_edildi_at, iptal_eden_user_id, iptal_nedeni
                   FROM ' . self::TABLE . '
                  WHERE user_id = :user_id
                  ORDER BY id DESC'
            );
            $stmt->execute(['user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return ['schema_ready' => false, 'rows' => []];
        }

        return ['schema_ready' => true, 'rows' => array_map(static function (array $row): array {
            $mapped = self::mapRow($row);
            $mapped['user_id'] = (int) $row['user_id'];
            $mapped['veren_user_id'] = (int) $row['veren_user_id'];
            $mapped['hedef_rol_snapshot'] = (string) $row['hedef_rol_snapshot'];
            $mapped['gerekce'] = (string) $row['gerekce'];
            $mapped['created_at'] = (string) $row['created_at'];
            $mapped['iptal_edildi_at'] = $row['iptal_edildi_at'] !== null ? (string) $row['iptal_edildi_at'] : null;
            $mapped['iptal_eden_user_id'] = $row['iptal_eden_user_id'] !== null ? (int) $row['iptal_eden_user_id'] : null;
            $mapped['iptal_nedeni'] = $row['iptal_nedeni'] !== null ? (string) $row['iptal_nedeni'] : null;

            return $mapped;
        }, $rows)];
    }

    /**
     * Audit kayıtları (yeniden eskiye). hedefUserId verilirse yalnız o kullanıcı.
     *
     * @return array{schema_ready: bool, rows: array<int, array<string, mixed>>}
     */
    public static function loadAudit(PDO $pdo, ?int $hedefUserId, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $where = '';
        $params = [];
        if ($hedefUserId !== null && $hedefUserId > 0) {
            $where = 'WHERE a.hedef_user_id = :hedef';
            $params['hedef'] = $hedefUserId;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT a.id, a.aksiyon, a.aktor_user_id, a.hedef_user_id, a.hedef_rol, a.istisna_id,
                        a.permission, a.etki, a.sube_id, a.gecerlilik_baslangic, a.gecerlilik_bitis,
                        a.gerekce, a.uyari_kodlari, a.created_at
                   FROM ' . self::AUDIT_TABLE . ' a ' . $where . '
                  ORDER BY a.id DESC
                  LIMIT ' . $limit
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return ['schema_ready' => false, 'rows' => []];
        }

        return ['schema_ready' => true, 'rows' => array_map(static function (array $row): array {
            foreach (['id', 'aktor_user_id', 'hedef_user_id', 'istisna_id', 'sube_id'] as $key) {
                $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
            }

            return $row;
        }, $rows)];
    }

    public static function isMissingTable(\PDOException $e): bool
    {
        $state = (string) $e->getCode();
        $info = $e->errorInfo;
        if ($state === '42S02' || (is_array($info) && (($info[0] ?? '') === '42S02' || (int) ($info[1] ?? 0) === 1146))) {
            return true;
        }

        return stripos($e->getMessage(), 'no such table') !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, permission:string, etki:string, sube_id:int|null, gecerlilik_baslangic:string, gecerlilik_bitis:string|null}
     */
    private static function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'permission' => (string) $row['permission'],
            'etki' => strtoupper((string) $row['etki']),
            'sube_id' => $row['sube_id'] !== null && $row['sube_id'] !== '' ? (int) $row['sube_id'] : null,
            'gecerlilik_baslangic' => (string) $row['gecerlilik_baslangic'],
            'gecerlilik_bitis' => $row['gecerlilik_bitis'] !== null && $row['gecerlilik_bitis'] !== '' ? (string) $row['gecerlilik_bitis'] : null,
        ];
    }
}
