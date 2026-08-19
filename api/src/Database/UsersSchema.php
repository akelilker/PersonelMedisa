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
}
