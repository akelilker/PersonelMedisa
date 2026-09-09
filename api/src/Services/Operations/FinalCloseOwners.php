<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Controllers\SgkKatalogHazirlikController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Payroll\SgkSirketPolitikaImportValidator;
use RuntimeException;

/** Only named operations in the compiled package can reach a canonical write. */
final class FinalCloseOwners
{
    public static function operations(): array
    {
        // Name correction precedes location so canonical surname validation succeeds.
        $ops = ['name203'];
        foreach (FinalClosePackage::PERSONNEL as $id) { $ops[] = 'location' . $id; }
        foreach (FinalClosePackage::MANAGERS as $branch => $user) { $ops[] = 'manager' . $branch; }
        $ops = array_merge($ops, ['scope11', 'scope110', 'identity_create', 'identity_verify', 'identity_bind']);
        foreach ([12, 13] as $branch) {
            foreach (['import', 'submit', 'approve'] as $step) { $ops[] = 'policy_' . $step . $branch; }
        }
        return $ops;
    }

    /**
     * Runs one bounded operation. The read-only 'snapshot' operation returns the
     * canonical snapshot so the transport can run it in-process; mutation owners
     * terminate the child HTTP controller response and never return here.
     */
    public static function invoke(array $frame): ?array
    {
        if (PHP_SAPI !== 'cli' || array_keys($frame) !== ['operation', 'expected', 'identity_id']) {
            throw new RuntimeException('FINAL_CLOSE_FRAME_INVALID');
        }
        $op = $frame['operation'];
        if ($op !== 'snapshot' && !in_array($op, self::operations(), true)) {
            throw new RuntimeException('FINAL_CLOSE_OPERATION_FORBIDDEN');
        }
        $snapshot = FinalCloseSnapshot::collect();
        foreach (FinalClosePackage::USERS as $id => $identity) {
            FinalCloseSnapshot::matches($snapshot['users'][$id], $identity + ['id' => $id, 'durum' => 'AKTIF']);
        }
        if ($op === 'snapshot') {
            // Read-only canonical snapshot: return the array so the caller can run
            // without a child process. Mutation operations never take this branch.
            return $snapshot;
        }
        if (!is_string($frame['expected']) || !hash_equals(FinalCloseSnapshot::checksum($snapshot), $frame['expected'])) {
            throw new RuntimeException('FINAL_CLOSE_PREIMAGE_DRIFT');
        }
        $body = [];
        $actor = 10;
        $method = '';
        $controller = YonetimController::class;
        $id = null;
        if ($op === 'name203') {
            $controller = PersonellerController::class; $method = 'update'; $id = 203;
            $body = ['ad' => 'Muhammed', 'soyad' => 'Mahmud'];
        } elseif (strpos($op, 'location') === 0) {
            // calisma_lokasyonu_id is a protected organization axis. Generic PUT
            // deliberately rejects it; invoke the existing audited canonical owner.
            $controller = PersonellerController::class; $method = 'organizasyonDegisikligi'; $id = (int) substr($op, 8);
            $body = [
                'preimage' => ['calisma_lokasyonu_id' => null],
                'targets' => ['calisma_lokasyonu_id' => 5],
                'gerekce' => 'Final production close Karabuk calisma lokasyonu atamasi',
            ];
        } elseif (strpos($op, 'manager') === 0) {
            $method = 'subeGuncelle'; $id = (int) substr($op, 7);
            $body = ['sorumlu_yonetici_user_ids' => [FinalClosePackage::MANAGERS[$id]]];
        } elseif (strpos($op, 'scope') === 0) {
            $method = 'kullaniciGuncelle'; $id = (int) substr($op, 5);
            $body = ['sube_ids' => FinalClosePackage::scopesAfter($id)];
        } elseif ($op === 'identity_create') {
            $method = 'actorIdentityCreate'; $body = ['user_id' => 110];
        } elseif ($op === 'identity_verify' || $op === 'identity_bind') {
            if (!is_int($frame['identity_id']) || $frame['identity_id'] <= 0) {
                throw new RuntimeException('FINAL_CLOSE_IDENTITY_RECEIPT_REQUIRED');
            }
            $method = $op === 'identity_verify' ? 'actorIdentityVerify' : 'actorIdentityBind';
            $id = $op === 'identity_verify' ? $frame['identity_id'] : 110;
            $body = $op === 'identity_verify' ? [] : ['actor_identity_id' => $frame['identity_id']];
        } else {
            $branch = (int) substr($op, -2);
            $controller = SgkKatalogHazirlikController::class;
            $actor = strpos($op, 'policy_approve') === 0 ? 110 : 11;
            if (strpos($op, 'policy_import') === 0) {
                $method = 'sirketPolitikasiImport';
                $body = FinalClosePackage::policy($branch);
                $dry = SgkSirketPolitikaImportValidator::dryRun(Connection::get(), $body);
                if (empty($dry['import_yapilabilir_mi'])) { throw new RuntimeException('FINAL_CLOSE_POLICY_DRY_RUN_BLOCKED'); }
                $body['politika_hash'] = $dry['politika_hash'];
                $body['confirmation_text'] = 'SGK_POLITIKA_DRAFT_ONAY';
            } else {
                $items = $snapshot['policies'][$branch];
                if (count($items) !== 1 || $items[0]['surum_kodu'] !== 'S98-R1-POL-' . $branch) {
                    throw new RuntimeException('FINAL_CLOSE_POLICY_RECEIPT_REQUIRED');
                }
                $method = $actor === 110 ? 'sirketPolitikasiApprove' : 'sirketPolitikasiSubmit';
                $body = ['surum_id' => (int) $items[0]['policy_id'], 'politika_hash' => $items[0]['politika_hash']];
            }
        }
        $request = new FinalCloseOwnerRequest($op, $actor, $body);
        // No synthetic role/scope, cache override, password-change bypass or public token.
        $authenticated = AuthMiddleware::authenticate($request, true);
        FinalCloseSnapshot::matches($authenticated, ['id' => $actor] + FinalClosePackage::USERS[$actor]);
        if ($id === null) { $controller::$method($request); } else { $controller::$method($request, $id); }
        throw new RuntimeException('FINAL_CLOSE_OWNER_RESPONSE_MISSING');
    }
}
