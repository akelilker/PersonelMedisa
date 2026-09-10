<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Auth\ActorIdentityService;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
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
        $pdo = self::read('CONNECTION', static function () {
            return Connection::get();
        });
        $snapshot = ['personnel' => [], 'users' => [], 'actors' => [], 'branches' => [], 'policies' => []];
        self::read('PERSONNEL_READ', static function () use ($pdo, &$snapshot) {
            foreach (FinalClosePackage::PERSONNEL as $id) {
                $snapshot['personnel'][$id] = PersonellerController::finalCloseRead($id);
            }
        });
        self::read('USER_READ', static function () use ($pdo, &$snapshot) {
            foreach (FinalClosePackage::USERS as $id => $identity) {
                $snapshot['users'][$id] = YonetimController::finalCloseRead($id);
            }
        });
        self::read('ACTOR_READ', static function () use ($pdo, &$snapshot) {
            foreach (FinalClosePackage::USERS as $id => $identity) {
                $snapshot['actors'][$id] = ActorIdentityService::readForUser($pdo, $id);
            }
        });
        self::read('BRANCH_READ', static function () use ($pdo, &$snapshot) {
            // Bounded owner read: only the compiled target branches, only the
            // preimage fields. The general UI branch read model stays out of the
            // final-close path so a screen-level dependency cannot fail it.
            $snapshot['branches'] = OrganizasyonService::readFinalCloseBranchPreimage(
                $pdo,
                array_keys(FinalClosePackage::MANAGERS)
            );
        });
        self::read('POLICY_READ', static function () use ($pdo, &$snapshot) {
            foreach ([12, 13] as $id) {
                $snapshot['policies'][$id] = SgkSirketPolitikaReadService::listRevisionInventory($pdo, $id, '2026-08-01', '9999-12-31');
                foreach ($snapshot['policies'][$id] as &$policy) {
                    foreach (['hazirlayan_id', 'onaylayan_id'] as $key) {
                        $policy[$key] = $policy[$key] === null ? null : (int) $policy[$key];
                    }
                }
                unset($policy);
            }
        });
        return $snapshot;
    }

    /**
     * Runs one read boundary and converts an unknown throwable into a bounded stage
     * code so an opaque PHP Error (for example "Call to a member function ... on
     * null") becomes attributable instead of surfacing as a bare, uninformative
     * detail. Known bounded single-token FINAL_CLOSE/BACKUP/domain codes are kept
     * verbatim; raw exception text, SQL, PII and credentials are never surfaced.
     *
     * @param callable $read
     * @return mixed
     */
    private static function read(string $step, callable $read)
    {
        try {
            return $read();
        } catch (\Throwable $error) {
            $message = $error->getMessage();
            if (is_string($message) && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $message) === 1) {
                throw $error;
            }
            // Some domain owners carry their bounded code on a public property while
            // getMessage() holds human text; surface that code instead of hiding it.
            $vars = get_object_vars($error);
            if (isset($vars['errorCode']) && is_string($vars['errorCode'])
                && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $vars['errorCode']) === 1) {
                throw new RuntimeException($vars['errorCode']);
            }
            throw new RuntimeException('FINAL_CLOSE_SNAPSHOT_' . $step . '_FAILED');
        }
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
        // Only the compiled target branches are attested. Every other branch
        // (Kayseri/Kübra and the rest) stays outside the snapshot, the preimage
        // check and therefore outside the write scope.
        foreach (array_keys(FinalClosePackage::MANAGERS) as $id) {
            self::matches($s['branches'][$id] ?? [], [
                'id' => $id, 'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => [],
            ]);
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
