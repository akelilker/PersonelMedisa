<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Auth\ActorIdentityService;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use Medisa\Api\Services\Organizasyon\SubeSorumluYoneticiSchema;
use Medisa\Api\Services\Payroll\SgkSirketPolitikaReadService;
use RuntimeException;

/** Read adapters only; every query remains in its existing canonical owner. */
final class FinalCloseSnapshot
{
    public static function collect(): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('FINAL_CLOSE_CLI_ONLY');
        }
        $pdo = Connection::get();
        $snapshot = ['personnel' => [], 'users' => [], 'actors' => [], 'branches' => [], 'policies' => []];
        foreach (FinalClosePackage::PERSONNEL as $id) {
            $snapshot['personnel'][$id] = PersonellerController::finalCloseRead($id);
        }
        foreach (FinalClosePackage::USERS as $id => $identity) {
            $snapshot['users'][$id] = YonetimController::finalCloseRead($id);
            $snapshot['actors'][$id] = ActorIdentityService::readForUser($pdo, $id);
        }
        if (!SubeSorumluYoneticiSchema::isReady($pdo)) {
            throw new RuntimeException('FINAL_CLOSE_MANAGER_SCHEMA_REQUIRED');
        }
        foreach (OrganizasyonService::listSubeler($pdo) as $branch) {
            $snapshot['branches'][(int) $branch['id']] = $branch;
        }
        foreach ([12, 13] as $id) {
            $snapshot['policies'][$id] = SgkSirketPolitikaReadService::listRevisionInventory($pdo, $id, '2026-08-01', '9999-12-31');
            foreach ($snapshot['policies'][$id] as &$policy) {
                foreach (['hazirlayan_id', 'onaylayan_id'] as $key) {
                    $policy[$key] = $policy[$key] === null ? null : (int) $policy[$key];
                }
            }
            unset($policy);
        }
        return $snapshot;
    }

    public static function assertApprovedPreimage(array $s): void
    {
        foreach (FinalClosePackage::USERS as $id => $expected) {
            self::matches($s['users'][$id] ?? [], $expected + ['id' => $id, 'durum' => 'AKTIF']);
        }
        foreach (FinalClosePackage::PERSONNEL as $id) {
            self::matches($s['personnel'][$id] ?? [], ['id' => $id, 'calisma_lokasyonu_id' => null]);
        }
        self::matches($s['personnel'][203], ['ad' => 'MUHAMMED IRAKLI', 'soyad' => '', 'aktif_durum' => 'AKTIF', 'sube_id' => 1, 'sirket_id' => 1]);
        self::matches($s['personnel'][210], ['sube_id' => 6]);
        self::matches($s['personnel'][212], ['sube_id' => null, 'sirket_id' => null]);
        foreach (FinalClosePackage::SCOPES_BEFORE as $id => $scope) {
            self::matches($s['users'][$id], ['sube_ids' => $scope]);
        }
        self::matches($s['users'][50], ['sube_ids' => [2]]);
        self::matches($s['actors'][110], ['actor_identity_id' => null, 'actor_status' => null]);
        self::matches($s['actors'][11], ['actor_status' => 'VERIFIED']);
        foreach ($s['branches'] as $branch) {
            self::matches($branch, ['sorumlu_yonetici_user_ids' => []]);
        }
        foreach (array_keys(FinalClosePackage::MANAGERS) as $id) {
            self::matches($s['branches'][$id] ?? [], ['id' => $id, 'durum' => 'AKTIF']);
        }
        if (($s['policies'][12] ?? null) !== [] || ($s['policies'][13] ?? null) !== []) {
            throw new RuntimeException('FINAL_CLOSE_POLICY_PREIMAGE_DRIFT');
        }
    }

    public static function matches(array $actual, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
                throw new RuntimeException('FINAL_CLOSE_PREIMAGE_DRIFT');
            }
        }
    }

    public static function checksum(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
