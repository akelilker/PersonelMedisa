<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;

/**
 * Rolling-deploy schema probes for users table columns.
 * No process-level cache (same risk model as actor_identity_id detection).
 */
class UsersSchema
{
    public static function hasVarsayilanSubeId(PDO $pdo): bool
    {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'varsayilan_sube_id'");
            $exists = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }

            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasPersonelId(PDO $pdo): bool
    {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'personel_id'");
            $exists = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }
            if ($exists) {
                return true;
            }
        } catch (\Throwable $e) {
            // Non-MySQL drivers (focused SQLite runners).
        }
        try {
            $stmt = $pdo->query("PRAGMA table_info(users)");
            if ($stmt === false) {
                return false;
            }
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['name']) && (string) $row['name'] === 'personel_id') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    public static function hasSilinmesiKorunur(PDO $pdo): bool
    {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'silinmesi_korunur'");
            $exists = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }

            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasMustChangePassword(PDO $pdo): bool
    {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
            $exists = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }

            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasActivationRequired(PDO $pdo): bool
    {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'activation_required'");
            $exists = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }

            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasActivatedAtUtc(PDO $pdo): bool
    {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'activated_at_utc'");
            $exists = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }

            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
