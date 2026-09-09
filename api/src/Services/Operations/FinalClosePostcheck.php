<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use RuntimeException;

/** Compare every captured invariant; permit only the one compiled operation. */
final class FinalClosePostcheck
{
    public static function verify(string $op, array $before, array $after, array $result, ?int $identityId): void
    {
        $expected = $before;
        if ($op === 'name203') {
            $expected['personnel'][203]['ad'] = 'Muhammed';
            $expected['personnel'][203]['soyad'] = 'Mahmud';
        } elseif (strpos($op, 'location') === 0) {
            $expected['personnel'][(int) substr($op, 8)]['calisma_lokasyonu_id'] = 5;
        } elseif (strpos($op, 'manager') === 0) {
            $id = (int) substr($op, 7);
            FinalCloseSnapshot::matches($after['branches'][$id], ['sorumlu_yonetici_user_ids' => [FinalClosePackage::MANAGERS[$id]]]);
            $expected['branches'][$id]['sorumlu_yonetici_user_ids'] = $after['branches'][$id]['sorumlu_yonetici_user_ids'];
            $expected['branches'][$id]['sorumlu_yoneticiler'] = $after['branches'][$id]['sorumlu_yoneticiler'];
        } elseif (strpos($op, 'scope') === 0) {
            $id = (int) substr($op, 5);
            $expected['users'][$id]['sube_ids'] = FinalClosePackage::scopesAfter($id);
            $actorExpected = [
                'branch_scope' => FinalClosePackage::scopesAfter($id),
                'actor_identity_id' => $before['actors'][$id]['actor_identity_id'],
                'actor_status' => $before['actors'][$id]['actor_status'],
            ];
            if ($id === 11) {
                $actorExpected['can_prepare'] = true;
            }
            FinalCloseSnapshot::matches($after['actors'][$id], $actorExpected);
            $expected['actors'][$id] = $after['actors'][$id];
        } elseif ($op === 'identity_create' || $op === 'identity_verify') {
            FinalCloseSnapshot::matches($result, ['personel_id' => 173, 'actor_status' => $op === 'identity_create' ? 'PENDING' : 'VERIFIED']);
            if ((int) ($result['actor_identity_id'] ?? 0) <= 0
                || ($op === 'identity_verify' && $result['actor_identity_id'] !== $identityId)) {
                throw new RuntimeException('FINAL_CLOSE_IDENTITY_RESULT_MISMATCH');
            }
        } elseif ($op === 'identity_bind') {
            FinalCloseSnapshot::matches($after['actors'][110], ['user_id' => 110, 'personel_id' => 173,
                'actor_identity_id' => $identityId, 'actor_status' => 'VERIFIED', 'branch_scope' => [12, 13], 'can_approve' => true]);
            $expected['actors'][110] = $after['actors'][110];
        } elseif (strpos($op, 'policy_') === 0) {
            $branch = (int) substr($op, -2);
            $items = $after['policies'][$branch];
            if (count($items) !== 1) { throw new RuntimeException('FINAL_CLOSE_POLICY_RESULT_MISMATCH'); }
            $state = strpos($op, 'policy_import') === 0 ? 'TASLAK' : (strpos($op, 'policy_submit') === 0 ? 'ONAY_BEKLIYOR' : 'ONAYLANDI');
            $policy = FinalClosePackage::policy($branch);
            FinalCloseSnapshot::matches($items[0], $policy + ['state' => $state, 'hazirlayan_id' => 11,
                'onaylayan_id' => $state === 'ONAYLANDI' ? 110 : null]);
            if (!is_string($items[0]['politika_hash'] ?? null) || $items[0]['politika_hash'] !== ($result['politika_hash'] ?? null)) {
                throw new RuntimeException('FINAL_CLOSE_POLICY_HASH_MISMATCH');
            }
            $expected['policies'][$branch] = $items;
        } else { throw new RuntimeException('FINAL_CLOSE_OPERATION_FORBIDDEN'); }
        if (FinalCloseSnapshot::checksum($expected) !== FinalCloseSnapshot::checksum($after)) {
            throw new RuntimeException('FINAL_CLOSE_UNRELATED_DRIFT');
        }
    }
}
