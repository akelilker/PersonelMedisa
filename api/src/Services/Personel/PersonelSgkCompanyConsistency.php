<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use PDO;

/**
 * Canonical same-company check between a personnel SGK employer and the
 * personnel branch company. Branch default SGK equality is intentionally
 * not required — only sirket_id alignment.
 */
final class PersonelSgkCompanyConsistency
{
    public const ERROR_MISMATCH = 'PERSONEL_SGK_SIRKET_UYUSMAZligi';
    public const ERROR_HIERARCHY = 'PERSONEL_SGK_SIRKET_HIERARCHY_NOT_READY';
    public const ERROR_REQUIRED = 'PERSONEL_SGK_ISVEREN_REQUIRED';

    /**
     * Active IC_PERSONEL must carry an explicit payroll employer.
     * DIS_KAYNAK and PASIF create paths are out of scope here.
     *
     * @param mixed $sgkIsverenId
     */
    public static function assertRequiredForActiveIc(string $kapsam, string $aktifDurum, $sgkIsverenId): void
    {
        if ($kapsam !== PersonelCalisanKapsamService::IC_PERSONEL) {
            return;
        }
        if (strtoupper(trim($aktifDurum)) !== 'AKTIF') {
            return;
        }
        if ($sgkIsverenId !== null && $sgkIsverenId !== '' && (int) $sgkIsverenId > 0) {
            return;
        }

        throw new PersonelValidationException(
            'sgk_isveren_id',
            'Aktif IC personel icin SGK isvereni zorunludur.',
            self::ERROR_REQUIRED
        );
    }

    /**
     * Null SGK is a no-op (required-check is separate). Fail-closed when the
     * hierarchy cannot prove company alignment.
     *
     * @param mixed $sgkIsverenId
     * @param mixed $subeId
     * @return array{ok:bool, code:?string, message:?string, sgk_sirket_id:?int, sube_sirket_id:?int}
     */
    public static function evaluate(PDO $pdo, $sgkIsverenId, $subeId): array
    {
        return self::evaluateAgainstSirket(
            $pdo,
            $sgkIsverenId,
            self::resolveSubeSirketId($pdo, self::nullablePositiveInt($subeId))
        );
    }

    /**
     * Same rule when the target company is already known (e.g. permanent branch move).
     *
     * @param mixed $sgkIsverenId
     * @return array{ok:bool, code:?string, message:?string, sgk_sirket_id:?int, sube_sirket_id:?int}
     */
    public static function evaluateAgainstSirket(PDO $pdo, $sgkIsverenId, ?int $sirketId): array
    {
        $sgkId = self::nullablePositiveInt($sgkIsverenId);
        if ($sgkId === null) {
            return [
                'ok' => true,
                'code' => null,
                'message' => null,
                'sgk_sirket_id' => null,
                'sube_sirket_id' => $sirketId,
            ];
        }

        if (!OrganizasyonSchema::isSchemaReady($pdo)) {
            return [
                'ok' => false,
                'code' => self::ERROR_HIERARCHY,
                'message' => 'Sirket hiyerarsisi hazir olmadigi icin SGK isvereni ile sube sirketi eslesmesi dogrulanamiyor.',
                'sgk_sirket_id' => null,
                'sube_sirket_id' => $sirketId,
            ];
        }

        $sgkSirketId = self::resolveSgkSirketId($pdo, $sgkId);
        if ($sgkSirketId === null || $sirketId === null || $sgkSirketId !== $sirketId) {
            return [
                'ok' => false,
                'code' => self::ERROR_MISMATCH,
                'message' => 'Personelin SGK isvereni ile subenin sirketi uyusmuyor.',
                'sgk_sirket_id' => $sgkSirketId,
                'sube_sirket_id' => $sirketId,
            ];
        }

        return [
            'ok' => true,
            'code' => null,
            'message' => null,
            'sgk_sirket_id' => $sgkSirketId,
            'sube_sirket_id' => $sirketId,
        ];
    }

    /**
     * Scope-aware SGK işvereni ↔ şube şirketi kuralı.
     *
     * IC_PERSONEL : personelin SGK işvereni ile şubenin şirketi aynı olmalıdır
     *               (mevcut aynı-şirket invariant'ı korunur).
     * DIS_KAYNAK  : SGK/bordro kaynağı fiili organizasyon şubesinden bağımsızdır.
     *               Başka şirketin AKTİF SGK işvereni geçerli olabilir
     *               (ör. Şenay Mobilya bordrolu, Medisa fabrikasında çalışan kişi).
     *               Şirket eşleşmesi aranmaz; katalog geçerliliği ayrı owner'da
     *               (PersonelOrgLocationSchema::existsActiveSgkIsveren) doğrulanır.
     *
     * @param mixed $sgkIsverenId
     * @param mixed $subeId
     * @return array{ok:bool, code:?string, message:?string, sgk_sirket_id:?int, sube_sirket_id:?int}
     */
    public static function evaluateForKapsam(PDO $pdo, string $kapsam, $sgkIsverenId, $subeId): array
    {
        return self::evaluateAgainstSirketForKapsam(
            $pdo,
            $kapsam,
            $sgkIsverenId,
            self::resolveSubeSirketId($pdo, self::nullablePositiveInt($subeId))
        );
    }

    /**
     * Same scope rule when the target company is already known (e.g. branch move).
     *
     * @param mixed $sgkIsverenId
     * @return array{ok:bool, code:?string, message:?string, sgk_sirket_id:?int, sube_sirket_id:?int}
     */
    public static function evaluateAgainstSirketForKapsam(PDO $pdo, string $kapsam, $sgkIsverenId, ?int $sirketId): array
    {
        if (self::isDisKaynakKapsam($kapsam)) {
            return [
                'ok' => true,
                'code' => null,
                'message' => null,
                'sgk_sirket_id' => null,
                'sube_sirket_id' => $sirketId,
            ];
        }

        return self::evaluateAgainstSirket($pdo, $sgkIsverenId, $sirketId);
    }

    /**
     * @param mixed $sgkIsverenId
     * @param mixed $subeId
     */
    public static function assertCompatibleForKapsam(PDO $pdo, string $kapsam, $sgkIsverenId, $subeId): void
    {
        $result = self::evaluateForKapsam($pdo, $kapsam, $sgkIsverenId, $subeId);
        if ($result['ok']) {
            return;
        }

        throw new PersonelValidationException(
            'sgk_isveren_id',
            (string) $result['message'],
            (string) $result['code']
        );
    }

    /**
     * Kapsam çözümü PersonelCalisanKapsamService'e aittir; eksik/boş değer
     * IC_PERSONEL'e düşer, yani şema öncesi durumda katı kural korunur.
     *
     * @param mixed $kapsam
     */
    private static function isDisKaynakKapsam($kapsam): bool
    {
        return PersonelCalisanKapsamService::resolveFromRow(['calisan_kapsami' => $kapsam])
            === PersonelCalisanKapsamService::DIS_KAYNAK;
    }

    /**
     * @param mixed $sgkIsverenId
     * @param mixed $subeId
     */
    public static function assertCompatible(PDO $pdo, $sgkIsverenId, $subeId): void
    {
        $result = self::evaluate($pdo, $sgkIsverenId, $subeId);
        if ($result['ok']) {
            return;
        }

        throw new PersonelValidationException(
            'sgk_isveren_id',
            (string) $result['message'],
            (string) $result['code']
        );
    }

    public static function resolveSgkSirketId(PDO $pdo, int $sgkIsverenId): ?int
    {
        $stmt = $pdo->prepare('SELECT sirket_id FROM sgk_isverenler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $sgkIsverenId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::nullablePositiveInt($row['sirket_id'] ?? null);
    }

    public static function resolveSubeSirketId(PDO $pdo, ?int $subeId): ?int
    {
        if ($subeId === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT sirket_id FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::nullablePositiveInt($row['sirket_id'] ?? null);
    }

    /** @param mixed $value */
    private static function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
}
