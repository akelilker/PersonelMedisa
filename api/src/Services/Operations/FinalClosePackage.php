<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Operations;

use RuntimeException;

/** Immutable business authorization, deliberately not a general operations API. */
final class FinalClosePackage
{
    public const ID = 'PERSONELMEDISA_FINAL_CLOSE_20260909';
    public const BASE_SHA = 'c9885ad34e8fa065a74a964368671554e001d1ed';
    public const REPOSITORY = 'akelilker/PersonelMedisa';
    public const PERSONNEL = [200, 201, 203, 204, 205, 206, 209, 210, 212, 217];
    public const MANAGERS = [1 => 110, 2 => 50, 5 => 110, 6 => 110, 12 => 50, 13 => 110];
    public const USERS = [
        10 => ['username' => 'ilkerA', 'rol' => 'GENEL_YONETICI', 'personel_id' => 212],
        11 => ['username' => 'sedanurB', 'rol' => 'IK_SORUMLUSU', 'personel_id' => 160],
        50 => ['username' => '040', 'rol' => 'SUBE_YONETICISI', 'personel_id' => 112],
        110 => ['username' => 'sinemH', 'rol' => 'GENEL_YONETICI', 'personel_id' => 173],
    ];
    public const SCOPES_BEFORE = [11 => [1, 2, 4, 5, 6, 7, 8, 9, 10, 11], 110 => []];

    public static function policy(int $branch): array
    {
        if (!in_array($branch, [12, 13], true)) {
            throw new RuntimeException('FINAL_CLOSE_BRANCH_FORBIDDEN');
        }
        return [
            'sube_id' => $branch,
            'surum_kodu' => 'S98-R1-POL-' . $branch,
            'bildirim_donem_tipi' => 'AY_1_SON_GUN',
            'gecerlilik_baslangic' => '2026-08-01',
            'gecerlilik_bitis' => null,
            'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'UCRET_MODELINE_GORE'],
        ];
    }

    public static function scopesAfter(int $userId): array
    {
        if (!array_key_exists($userId, self::SCOPES_BEFORE)) {
            throw new RuntimeException('FINAL_CLOSE_USER_FORBIDDEN');
        }
        return array_merge(self::SCOPES_BEFORE[$userId], [12, 13]);
    }

    public static function validateRequest(array $request, string $publishedSha): void
    {
        $keys = array_keys($request);
        sort($keys);
        $allowed = ['confirmation', 'deployed_sha', 'mode', 'package_id', 'preflight_checksum',
            'repository', 'repository_private', 'request_id', 'requested_at'];
        sort($allowed);
        if ($keys !== $allowed) {
            throw new RuntimeException('FINAL_CLOSE_REQUEST_FIELDS_INVALID');
        }
        if (!in_array($request['mode'], ['FINAL_CLOSE_PREFLIGHT', 'FINAL_CLOSE_APPLY'], true)
            || $request['confirmation'] !== $request['mode']
            || $request['package_id'] !== self::ID
            || $request['repository'] !== self::REPOSITORY
            || $request['repository_private'] !== true) {
            throw new RuntimeException('FINAL_CLOSE_AUTHORIZATION_INVALID');
        }
        if (!is_string($request['deployed_sha'])
            || !preg_match('/^[a-f0-9]{40}$/D', $request['deployed_sha'])
            || !hash_equals($publishedSha, $request['deployed_sha'])) {
            throw new RuntimeException('FINAL_CLOSE_DEPLOY_SHA_MISMATCH');
        }
        if (!is_string($request['request_id']) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $request['request_id'])) {
            throw new RuntimeException('FINAL_CLOSE_REQUEST_ID_INVALID');
        }
        $at = is_string($request['requested_at']) ? strtotime($request['requested_at']) : false;
        if ($at === false || $at > time() + 60 || $at < time() - 1800) {
            throw new RuntimeException('FINAL_CLOSE_REQUEST_EXPIRED');
        }
        if ($request['mode'] === 'FINAL_CLOSE_APPLY'
            && (!is_string($request['preflight_checksum']) || !preg_match('/^[a-f0-9]{64}$/D', $request['preflight_checksum']))) {
            throw new RuntimeException('FINAL_CLOSE_PREFLIGHT_REQUIRED');
        }
        if ($request['mode'] === 'FINAL_CLOSE_PREFLIGHT' && $request['preflight_checksum'] !== null) {
            throw new RuntimeException('FINAL_CLOSE_PREFLIGHT_INPUT_INVALID');
        }
    }
}
