<?php

declare(strict_types=1);

/**
 * Canonical PERSONEL first-login credential ops worker (web'den erisilemez CLI).
 *
 * Kullanim:
 *   php api/bin/personel-first-login-credentials.php --dry-run
 *   php api/bin/personel-first-login-credentials.php --apply \
 *       --confirm=PERSONEL_FIRST_LOGIN_CREDENTIALS --actor-user-id=10
 *
 * Is kurallari:
 *   - Varsayilan davranis dry-run'dir; mutation yalniz --apply + exact --confirm ile olur.
 *   - Mutation cohort'u: rol = 'PERSONEL', durum = 'AKTIF', bagli personel AKTIF,
 *     PersonelAccountOnboardingService::PROTECTED_USERNAMES (ilkerA) HARIC.
 *   - Canonical username cakismasi varsa hicbir satir mutate edilmez (fail-closed).
 *   - Plaintext sifre ve password_hash ASLA loglanmaz, dosyaya yazilmaz veya ciktiya konmaz.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Services\Auth\PersonelAccountOnboardingService;

const PERSONEL_FIRST_LOGIN_CONFIRMATION = 'PERSONEL_FIRST_LOGIN_CREDENTIALS';

/**
 * @return array<string, mixed>
 */
function pflcOptions(array $argv)
{
    $apply = false;
    $confirm = null;
    $actorUserId = null;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') {
            $apply = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $apply = false;
            continue;
        }
        if (strpos($arg, '--confirm=') === 0) {
            $confirm = substr($arg, strlen('--confirm='));
            continue;
        }
        if (strpos($arg, '--actor-user-id=') === 0) {
            $actorUserId = (int) substr($arg, strlen('--actor-user-id='));
            continue;
        }
    }

    // Fail-closed: exact confirmation token yoksa mutation yok.
    if (!$apply || $confirm !== PERSONEL_FIRST_LOGIN_CONFIRMATION) {
        $apply = false;
    }

    return ['apply' => $apply, 'confirm' => $confirm, 'actor_user_id' => $actorUserId];
}

try {
    $options = pflcOptions($argv);

    if ($options['apply'] === true && ($options['actor_user_id'] ?? 0) <= 0) {
        echo json_encode([
            'data' => null,
            'meta' => [],
            'errors' => [['code' => 'ACTOR_USER_ID_REQUIRED']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }

    $pdo = Connection::get();

    JsonResponse::beginCapture();
    try {
        $result = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials(
            $pdo,
            $options['actor_user_id'],
            $options['apply'] === true
        );
        $captured = JsonResponse::capturedResponse();
    } catch (Throwable $e) {
        $captured = JsonResponse::capturedResponse();
        $result = null;
        if ($captured === null) {
            $captured = [
                'data' => null,
                'meta' => [],
                'errors' => [['code' => 'FIRST_LOGIN_CREDENTIALS_WORKER_FAILED']],
            ];
        }
    } finally {
        JsonResponse::endCapture();
    }

    if ($captured !== null) {
        echo json_encode($captured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }

    $excludedCounts = [];
    foreach (($result['excluded'] ?? []) as $reason => $rows) {
        $excludedCounts[(string) $reason] = is_array($rows) ? count($rows) : 0;
    }

    echo json_encode([
        'data' => [
            'mode' => $result['apply'] === true ? 'APPLY' : 'DRY_RUN',
            'blocked' => $result['blocked'] === true,
            'blocker' => $result['blocker'],
            'target_count' => $result['target_count'],
            'applied_count' => $result['applied_count'],
            'changed_username_count' => count(array_filter(
                $result['plan'] ?? [],
                static function ($row) {
                    return isset($row['username_changed']) && $row['username_changed'] === true;
                }
            )),
            'collisions' => $result['collisions'],
            'excluded_counts' => $excludedCounts,
            'audit_event' => $result['audit_event'],
            'plan' => $result['plan'],
        ],
        'meta' => ['protected_usernames' => PersonelAccountOnboardingService::PROTECTED_USERNAMES],
        'errors' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    // Exception detayi (PDO mesaji, secret) asla basilamaz.
    echo json_encode([
        'data' => null,
        'meta' => [],
        'errors' => [['code' => 'FIRST_LOGIN_CREDENTIALS_WORKER_FAILED']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
