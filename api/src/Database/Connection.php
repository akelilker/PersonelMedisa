<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;
use PDOException;

class Connection
{
    /** @var PDO|null */
    private static $pdo = null;

    /** @return PDO */
    public static function get()
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (!medisa_config_ready()) {
            throw new PDOException('Database configuration is incomplete.');
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            medisa_config('db_host'),
            medisa_config('db_name')
        );

        self::$pdo = new PDO(
            $dsn,
            medisa_config('db_user'),
            medisa_config('db_password'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$pdo;
    }
}
