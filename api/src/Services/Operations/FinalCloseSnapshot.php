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
    /** The generic prefix every preimage drift code keeps. */
    private const PREIMAGE_DRIFT = 'FINAL_CLOSE_PREIMAGE_DRIFT';
    /** The policy axis keeps its own pre-existing bounded code. */
    private const POLICY_PREIMAGE_DRIFT = 'FINAL_CLOSE_POLICY_PREIMAGE_DRIFT';
    /** Longest reason the bounded worker/transport token contract accepts. */
    private const DRIFT_MAX_LENGTH = 100;
    /**
     * Closed mismatch-class allowlist. Only the shape of a mismatch leaves the owner:
     * MISSING an absent actual field, NULL a null on either side, TYPE both sides
     * present with different types, COUNT a list whose size differs, IDS a list of the
     * same size whose membership/order differs, VALUE an equal-typed pair with a
     * different value. No value, no list member and no list size is ever returned.
     */
    private const DRIFT_CLASSES = ['MISSING', 'NULL', 'TYPE', 'COUNT', 'IDS', 'VALUE'];
    /**
     * Closed field allowlist. The label is the only thing a field can contribute to a
     * reason: the canonical key of every field the approved preimage contract compares
     * maps to one bounded label, and a key outside the allowlist contributes nothing.
     * A value can never reach a token this way.
     */
    private const DRIFT_FIELDS = [
        'id' => 'ID',
        'durum' => 'DURUM',
        'username' => 'USERNAME',
        'rol' => 'ROL',
        'personel_id' => 'PERSONEL_ID',
        'calisma_lokasyonu_id' => 'CALISMA_LOKASYONU_ID',
        'ad' => 'AD',
        'soyad' => 'SOYAD',
        'aktif_durum' => 'AKTIF_DURUM',
        'sube_id' => 'SUBE_ID',
        'sirket_id' => 'SIRKET_ID',
        'sube_ids' => 'SUBE_IDS',
        'actor_identity_id' => 'ACTOR_IDENTITY_ID',
        'actor_status' => 'ACTOR_STATUS',
        'sorumlu_yonetici_user_ids' => 'SORUMLU_YONETICI_USER_IDS',
    ];

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

    /**
     * Fail-closed compatibility guard for the mutation path: the first approved
     * preimage mismatch still stops it, with the unchanged bounded reason family.
     * The comparison itself has one owner, the canonical collector below.
     *
     * @param array<string, mixed> $s
     */
    public static function assertApprovedPreimage(array $s): void
    {
        $drifts = self::approvedPreimageDrifts($s);
        if ($drifts !== []) {
            throw new RuntimeException($drifts[0]);
        }
    }

    /**
     * Canonical read-only diagnostic owner for the whole approved preimage. It
     * collects every mismatch instead of throwing on the first one, so a single
     * production preflight can report all assumptions that no longer hold. Each
     * reason is a bounded token of the form
     * FINAL_CLOSE_PREIMAGE_DRIFT_<CATEGORY>_<ID>_<FIELD>_<CLASS>; the policy axis
     * keeps its own pre-existing FINAL_CLOSE_POLICY_PREIMAGE_DRIFT. A category,
     * target id, field or class outside the compiled allowlist degrades to the bare
     * generic prefix, so no caller can widen a token. A raw expected/actual value, an
     * id list, a row, an exception text and SQL never leave this owner.
     *
     * @param array<string, mixed> $s
     * @return array<int, string>
     */
    public static function approvedPreimageDrifts(array $s): array
    {
        $drifts = [];
        foreach (FinalClosePackage::USERS as $id => $expected) {
            self::comparisonDrifts($drifts, 'USER', (int) $id, $s['users'][$id] ?? [], $expected + ['id' => $id, 'durum' => 'AKTIF']);
        }
        foreach (FinalClosePackage::PERSONNEL as $id) {
            self::comparisonDrifts($drifts, 'PERSONNEL', (int) $id, $s['personnel'][$id] ?? [], ['id' => $id, 'calisma_lokasyonu_id' => null]);
        }
        self::comparisonDrifts($drifts, 'PERSONNEL', 203, $s['personnel'][203] ?? [], ['ad' => 'MUHAMMED IRAKLI', 'soyad' => '', 'aktif_durum' => 'AKTIF', 'sube_id' => 1, 'sirket_id' => 1]);
        self::comparisonDrifts($drifts, 'PERSONNEL', 210, $s['personnel'][210] ?? [], ['sube_id' => 6]);
        self::comparisonDrifts($drifts, 'PERSONNEL', 212, $s['personnel'][212] ?? [], ['sube_id' => null, 'sirket_id' => null]);
        foreach (FinalClosePackage::SCOPES_BEFORE as $id => $scope) {
            self::comparisonDrifts($drifts, 'SCOPE', (int) $id, $s['users'][$id] ?? [], ['sube_ids' => $scope]);
        }
        self::comparisonDrifts($drifts, 'SCOPE', 50, $s['users'][50] ?? [], ['sube_ids' => [2]]);
        self::comparisonDrifts($drifts, 'ACTOR', 110, $s['actors'][110] ?? [], ['actor_identity_id' => null, 'actor_status' => null]);
        self::comparisonDrifts($drifts, 'ACTOR', 11, $s['actors'][11] ?? [], ['actor_status' => 'VERIFIED']);
        // Only the compiled target branches are attested. Every other branch
        // (Kayseri/Kübra and the rest) stays outside the snapshot, the preimage
        // check and therefore outside the write scope.
        foreach (array_keys(FinalClosePackage::MANAGERS) as $id) {
            self::comparisonDrifts($drifts, 'BRANCH', (int) $id, $s['branches'][$id] ?? [], [
                'id' => $id, 'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => [],
            ]);
        }
        // The policy axis keeps its own bounded code: its body, hash and values must
        // never be published, so no field or value is attributed for it.
        foreach (self::driftTargets()['POLICY'] as $id) {
            if (($s['policies'][$id] ?? null) !== []) {
                $drifts[] = self::POLICY_PREIMAGE_DRIFT;
            }
        }
        return array_values(array_unique($drifts));
    }

    /**
     * Appends one bounded reason per drifted field of one approved comparison. Only
     * the canonical field label and the mismatch class are derived from the captured
     * row; the value itself is consumed here and never leaves the owner.
     *
     * @param array<int, string> $drifts
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     */
    private static function comparisonDrifts(array &$drifts, string $category, int $id, array $actual, array $expected): void
    {
        foreach ($expected as $key => $expectedValue) {
            $field = self::driftField((string) $key);
            if (!array_key_exists($key, $actual)) {
                $drifts[] = self::driftReason($category, $id, $field, 'MISSING');
                continue;
            }
            if ($actual[$key] !== $expectedValue) {
                $drifts[] = self::driftReason($category, $id, $field, self::mismatchClass($expectedValue, $actual[$key]));
            }
        }
    }

    /**
     * Compares one captured preimage against its approved expectation and fails
     * closed with the generic bounded drift token when they differ. The token
     * contract, the fail-fast behaviour and the message are unchanged, so every
     * existing caller keeps the FINAL_CLOSE_PREIMAGE_DRIFT family and a value, a row,
     * an exception text or SQL can never become part of it.
     *
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     */
    public static function matches(array $actual, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
                throw new RuntimeException(self::PREIMAGE_DRIFT);
            }
        }
    }

    /**
     * Safe shape of one mismatch. Only the class leaves the owner:
     * - MISSING an absent actual field (decided by the caller, never here),
     * - NULL  a null on either side (an absent actual field included),
     * - TYPE  both sides present with different types,
     * - COUNT a list whose size differs,
     * - IDS   a list of the same size whose membership/order differs,
     * - VALUE an equal-typed scalar (or other) pair with a different value.
     * No value, no list member and no list size is ever returned.
     *
     * @param mixed $expected
     * @param mixed $actual
     */
    private static function mismatchClass($expected, $actual): string
    {
        if ($expected === null || $actual === null) {
            return 'NULL';
        }
        $expectedType = gettype($expected);
        if ($expectedType !== gettype($actual)) {
            return 'TYPE';
        }
        if ($expectedType === 'array') {
            return count($expected) !== count($actual) ? 'COUNT' : 'IDS';
        }
        return 'VALUE';
    }

    /**
     * Bounded reason for one already-resolved part set. A category, target id, field
     * or class outside the compiled allowlist - and any token that would leave the
     * bounded contract - degrades to the bare generic prefix, so arbitrary input can
     * never be carried into the worker/transport token.
     */
    private static function driftReason(string $category, int $id, string $field, string $class): string
    {
        $targets = self::driftTargets();
        if ($field === '' || !isset($targets[$category]) || !in_array($id, $targets[$category], true)
            || !in_array($class, self::DRIFT_CLASSES, true)) {
            return self::PREIMAGE_DRIFT;
        }
        $reason = self::PREIMAGE_DRIFT . '_' . $category . '_' . $id . '_' . $field . '_' . $class;
        return strlen($reason) <= self::DRIFT_MAX_LENGTH && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $reason) === 1
            ? $reason : self::PREIMAGE_DRIFT;
    }

    /**
     * Compiled diagnostic target set. Target ids come from the compiled package and
     * never from a caller, so nothing outside the approved operation scope can be
     * attributed.
     *
     * @return array<string, array<int, int>>
     */
    private static function driftTargets(): array
    {
        return [
            'USER' => array_keys(FinalClosePackage::USERS),
            'PERSONNEL' => FinalClosePackage::PERSONNEL,
            'SCOPE' => array_values(array_unique(array_merge(array_keys(FinalClosePackage::SCOPES_BEFORE), [50]))),
            'ACTOR' => array_keys(FinalClosePackage::USERS),
            'BRANCH' => array_keys(FinalClosePackage::MANAGERS),
            'POLICY' => [12, 13],
        ];
    }

    /**
     * Canonical label of one field through the closed allowlist. An unknown key
     * contributes nothing and returns '', so the reason degrades to the generic prefix
     * instead of carrying caller-provided text into the token.
     */
    private static function driftField(string $field): string
    {
        $key = strtolower(trim($field));
        return isset(self::DRIFT_FIELDS[$key]) ? self::DRIFT_FIELDS[$key] : '';
    }

    public static function checksum(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
