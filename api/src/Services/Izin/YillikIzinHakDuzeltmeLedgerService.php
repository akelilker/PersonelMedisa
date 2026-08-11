<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Izin;

use PDO;
use PDOException;

class YillikIzinHakDuzeltmeException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /** @var string|null */
    private $field;

    public function __construct($code, $message, $httpStatus = 400, $field = null)
    {
        parent::__construct((string) $message, (int) $httpStatus);
        $this->errorCode = (string) $code;
        $this->httpStatus = (int) $httpStatus;
        $this->field = $field !== null && (string) $field !== '' ? (string) $field : null;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        $code = (int) $this->httpStatus;

        return $code >= 400 && $code < 600 ? $code : 400;
    }

    /** @return string|null */
    public function getField()
    {
        return $this->field;
    }
}

/**
 * Append-only yıllık izin hak düzeltme ledger (S2B).
 * D2=A: no approval workflow. Signed deltas OK; zero rejected.
 */
class YillikIzinHakDuzeltmeLedgerService
{
    /** @var string[] */
    private static $createKategoriler = ['DEVIR', 'EK_HAK', 'DUZELTME'];

    /**
     * @param array{gun_delta:mixed, kategori:mixed, aciklama:mixed, effective_date:mixed} $payload
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, $personelId, array $payload, array $actor)
    {
        $personelId = (int) $personelId;
        $normalized = self::normalizeCreatePayload($payload);
        $createdBy = (int) ($actor['id'] ?? 0);
        if ($createdBy <= 0) {
            throw new YillikIzinHakDuzeltmeException('UNAUTHORIZED', 'Actor gerekli.', 401);
        }

        self::assertPersonelExists($pdo, $personelId);

        $stmt = $pdo->prepare(
            'INSERT INTO yillik_izin_hak_duzeltmeleri
             (personel_id, gun_delta, kategori, aciklama, effective_date, created_by, reverses_id)
             VALUES
             (:personel_id, :gun_delta, :kategori, :aciklama, :effective_date, :created_by, NULL)'
        );
        $stmt->execute([
            'personel_id' => $personelId,
            'gun_delta' => $normalized['gun_delta'],
            'kategori' => $normalized['kategori'],
            'aciklama' => $normalized['aciklama'],
            'effective_date' => $normalized['effective_date'],
            'created_by' => $createdBy,
        ]);

        $id = (int) $pdo->lastInsertId();

        return self::getById($pdo, $id);
    }

    /**
     * Compensating TERS_KAYIT (−delta). Double-reversal protected via unique(reverses_id) + transaction.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function reverse(PDO $pdo, $personelId, $duzeltmeId, array $actor, $aciklamaOverride = null)
    {
        $personelId = (int) $personelId;
        $duzeltmeId = (int) $duzeltmeId;
        $createdBy = (int) ($actor['id'] ?? 0);
        if ($createdBy <= 0) {
            throw new YillikIzinHakDuzeltmeException('UNAUTHORIZED', 'Actor gerekli.', 401);
        }
        if ($duzeltmeId <= 0) {
            throw new YillikIzinHakDuzeltmeException('NOT_FOUND', 'Duzeltme kaydi bulunamadi.', 404);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM yillik_izin_hak_duzeltmeleri
                 WHERE id = :id AND personel_id = :pid
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $duzeltmeId, 'pid' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new YillikIzinHakDuzeltmeException('NOT_FOUND', 'Duzeltme kaydi bulunamadi.', 404);
            }
            if ((string) $row['kategori'] === 'TERS_KAYIT') {
                throw new YillikIzinHakDuzeltmeException(
                    'INVALID_REVERSAL_TARGET',
                    'Ters kayit tekrar terslenemez.',
                    409,
                    'duzeltme_id'
                );
            }

            $exists = $pdo->prepare(
                'SELECT id FROM yillik_izin_hak_duzeltmeleri WHERE reverses_id = :rid LIMIT 1 FOR UPDATE'
            );
            $exists->execute(['rid' => $duzeltmeId]);
            if ($exists->fetch(PDO::FETCH_ASSOC)) {
                throw new YillikIzinHakDuzeltmeException(
                    'ALREADY_REVERSED',
                    'Bu duzeltme zaten ters kayit ile kapatilmis.',
                    409,
                    'duzeltme_id'
                );
            }

            $delta = (int) $row['gun_delta'];
            $aciklama = $aciklamaOverride !== null && trim((string) $aciklamaOverride) !== ''
                ? trim((string) $aciklamaOverride)
                : ('Ters kayit #' . $duzeltmeId . ' icin otomatik telafi.');

            $ins = $pdo->prepare(
                'INSERT INTO yillik_izin_hak_duzeltmeleri
                 (personel_id, gun_delta, kategori, aciklama, effective_date, created_by, reverses_id)
                 VALUES
                 (:personel_id, :gun_delta, \'TERS_KAYIT\', :aciklama, :effective_date, :created_by, :reverses_id)'
            );
            $ins->execute([
                'personel_id' => $personelId,
                'gun_delta' => -$delta,
                'aciklama' => $aciklama,
                'effective_date' => (string) $row['effective_date'],
                'created_by' => $createdBy,
                'reverses_id' => $duzeltmeId,
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();

            return self::getById($pdo, $newId);
        } catch (YillikIzinHakDuzeltmeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Unique reverses_id race.
            if ((int) $e->getCode() === 23000 || strpos($e->getMessage(), 'uq_yihd_reverses_id') !== false) {
                throw new YillikIzinHakDuzeltmeException(
                    'ALREADY_REVERSED',
                    'Bu duzeltme zaten ters kayit ile kapatilmis.',
                    409,
                    'duzeltme_id'
                );
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listByPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT d.*, u.ad_soyad AS created_by_ad,
                    (SELECT r.id FROM yillik_izin_hak_duzeltmeleri r WHERE r.reverses_id = d.id LIMIT 1) AS reversed_by_id
             FROM yillik_izin_hak_duzeltmeleri d
             LEFT JOIN users u ON u.id = d.created_by
             WHERE d.personel_id = :pid
             ORDER BY d.effective_date DESC, d.id DESC'
        );
        $stmt->execute(['pid' => (int) $personelId]);

        return array_map([self::class, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return int */
    public static function netSum(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(gun_delta), 0) AS net
             FROM yillik_izin_hak_duzeltmeleri
             WHERE personel_id = :pid'
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['net'] ?? 0);
    }

    /** @return int */
    public static function countByPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt
             FROM yillik_izin_hak_duzeltmeleri
             WHERE personel_id = :pid'
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare(
            'SELECT d.*, u.ad_soyad AS created_by_ad,
                    (SELECT r.id FROM yillik_izin_hak_duzeltmeleri r WHERE r.reverses_id = d.id LIMIT 1) AS reversed_by_id
             FROM yillik_izin_hak_duzeltmeleri d
             LEFT JOIN users u ON u.id = d.created_by
             WHERE d.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new YillikIzinHakDuzeltmeException('NOT_FOUND', 'Duzeltme kaydi bulunamadi.', 404);
        }

        return self::mapRow($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{gun_delta:int, kategori:string, aciklama:string, effective_date:string}
     */
    private static function normalizeCreatePayload(array $payload)
    {
        if (!array_key_exists('gun_delta', $payload)) {
            throw new YillikIzinHakDuzeltmeException('VALIDATION_ERROR', 'gun_delta zorunlu.', 422, 'gun_delta');
        }
        $rawDelta = $payload['gun_delta'];
        $isInt = is_int($rawDelta)
            || (is_string($rawDelta) && preg_match('/^-?\d+$/', trim($rawDelta)) === 1);
        if (!$isInt) {
            throw new YillikIzinHakDuzeltmeException('VALIDATION_ERROR', 'gun_delta tam sayi olmali.', 422, 'gun_delta');
        }
        $gunDelta = (int) $rawDelta;
        if ($gunDelta === 0) {
            throw new YillikIzinHakDuzeltmeException('VALIDATION_ERROR', 'gun_delta sifir olamaz.', 422, 'gun_delta');
        }

        $kategori = strtoupper(trim((string) ($payload['kategori'] ?? '')));
        if (!in_array($kategori, self::$createKategoriler, true)) {
            throw new YillikIzinHakDuzeltmeException(
                'VALIDATION_ERROR',
                'kategori DEVIR, EK_HAK veya DUZELTME olmali.',
                422,
                'kategori'
            );
        }

        $aciklama = trim((string) ($payload['aciklama'] ?? ''));
        if ($aciklama === '') {
            throw new YillikIzinHakDuzeltmeException('VALIDATION_ERROR', 'aciklama zorunlu.', 422, 'aciklama');
        }

        $effective = trim((string) ($payload['effective_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
            throw new YillikIzinHakDuzeltmeException('VALIDATION_ERROR', 'effective_date YYYY-MM-DD olmali.', 422, 'effective_date');
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $effective);
        if ($dt === false || $dt->format('Y-m-d') !== $effective) {
            throw new YillikIzinHakDuzeltmeException('VALIDATION_ERROR', 'effective_date gecersiz.', 422, 'effective_date');
        }

        return [
            'gun_delta' => $gunDelta,
            'kategori' => $kategori,
            'aciklama' => $aciklama,
            'effective_date' => $effective,
        ];
    }

    private static function assertPersonelExists(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new YillikIzinHakDuzeltmeException('NOT_FOUND', 'Personel bulunamadi.', 404);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(array $row)
    {
        $display = isset($row['created_by_ad']) && $row['created_by_ad'] !== null
            ? (string) $row['created_by_ad']
            : null;

        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'gun_delta' => (int) $row['gun_delta'],
            'kategori' => (string) $row['kategori'],
            'aciklama' => (string) $row['aciklama'],
            'effective_date' => (string) $row['effective_date'],
            'created_by' => (int) $row['created_by'],
            'created_by_display' => $display,
            'created_by_ad' => $display,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'reverses_id' => $row['reverses_id'] !== null ? (int) $row['reverses_id'] : null,
            'is_reversed' => isset($row['reversed_by_id']) && $row['reversed_by_id'] !== null,
        ];
    }
}
