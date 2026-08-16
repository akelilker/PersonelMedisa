<?php
declare(strict_types=1);

define('MIGRATION_067_TEST_IMPORT', true);
require dirname(__DIR__, 2) . '/scripts/ops/migration-067-ops.template.php';

$dsn = (string) getenv('MEDISA_TEST_MYSQL_DSN');
$user = (string) getenv('MEDISA_TEST_MYSQL_USER');
$password = (string) getenv('MEDISA_TEST_MYSQL_PASSWORD');
$adminDsn = preg_replace('/dbname=[^;]+/i', 'dbname=mysql', $dsn);
if (!is_string($adminDsn) || $dsn === '' || $user === '') {
    fwrite(STDERR, "Migration 067 backup test database environment is missing.\n");
    exit(1);
}

$suffix = (string) getmypid();
$sourceDb = 'migration067_src_' . $suffix;
$restoreDb = 'migration067_restore_' . $suffix;
$dumpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'migration067_backup_' . $suffix . '.sql';
$quoteIdentifier = static fn(string $value): string => '`' . str_replace('`', '``', $value) . '`';

try {
    $admin = new PDO(
        $adminDsn,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    foreach ([$sourceDb, $restoreDb] as $database) {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quoteIdentifier($database));
        $admin->exec(
            'CREATE DATABASE ' . $quoteIdentifier($database)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    $source = new PDO(
        str_replace('dbname=mysql', 'dbname=' . $sourceDb, $dsn),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $source->exec(
        'CREATE TABLE parents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_parents_title (title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $source->exec(
        'CREATE TABLE children (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id INT UNSIGNED NOT NULL,
            payload VARBINARY(16) NOT NULL,
            note TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_children_parent (parent_id),
            CONSTRAINT fk_children_parent FOREIGN KEY (parent_id) REFERENCES parents (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $source->exec(
        'CREATE TABLE child_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $source->exec(
        'CREATE TRIGGER trg_children_audit
         AFTER INSERT ON children
         FOR EACH ROW INSERT INTO child_audit (child_id) VALUES (NEW.id)'
    );
    $source->exec(
        'CREATE VIEW child_view AS
         SELECT c.id, p.title, c.note FROM children c INNER JOIN parents p ON p.id = c.parent_id'
    );

    $parent = $source->prepare('INSERT INTO parents (title) VALUES (?)');
    $parent->execute(["Üretim ' özel\\satır\nTürkçe"]);
    $child = $source->prepare('INSERT INTO children (parent_id, payload, note) VALUES (?, ?, ?)');
    $child->bindValue(1, 1, PDO::PARAM_INT);
    $child->bindValue(2, "\x00\xFFbinary", PDO::PARAM_LOB);
    $child->bindValue(3, null, PDO::PARAM_NULL);
    $child->execute();
    $child->bindValue(3, "tırnak ' ve slash\\ ve\nnewline", PDO::PARAM_STR);
    $child->execute();

    migration_067_php_dump($source, $dumpPath);
    if (!is_file($dumpPath) || filesize($dumpPath) <= 0) {
        throw new RuntimeException('Fallback dump was empty.');
    }

    $restore = new PDO(
        str_replace('dbname=mysql', 'dbname=' . $restoreDb, $dsn),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $delimiter = ';';
    $buffer = '';
    foreach (file($dumpPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = trim($line);
        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $matches) === 1) {
            $delimiter = $matches[1];
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($buffer), $delimiter)) {
            $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
            if ($statement !== '' && !str_starts_with($statement, '--')) {
                $restore->exec($statement);
            }
            $buffer = '';
        }
    }

    $title = $restore->query('SELECT title FROM parents WHERE id = 1')->fetchColumn();
    $rows = $restore->query('SELECT payload, note FROM children ORDER BY id')->fetchAll();
    $triggerCount = (int) $restore->query(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_children_audit'"
    )->fetchColumn();
    $viewCount = (int) $restore->query(
        "SELECT COUNT(*) FROM information_schema.VIEWS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'child_view'"
    )->fetchColumn();
    $foreignKeyCount = (int) $restore->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'children'
           AND REFERENCED_TABLE_NAME = 'parents'"
    )->fetchColumn();
    $indexCount = (int) $restore->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parents'
           AND INDEX_NAME = 'uq_parents_title'"
    )->fetchColumn();
    $autoIncrement = (int) $restore->query(
        "SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'children'"
    )->fetchColumn();
    if ($title !== "Üretim ' özel\\satır\nTürkçe"
        || count($rows) !== 2
        || $rows[0]['payload'] !== "\x00\xFFbinary"
        || $rows[0]['note'] !== null
        || $rows[1]['note'] !== "tırnak ' ve slash\\ ve\nnewline"
        || $triggerCount !== 1
        || $viewCount !== 1
        || $foreignKeyCount < 1
        || $indexCount < 1
        || $autoIncrement < 3) {
        throw new RuntimeException('Fallback restore evidence mismatch.');
    }

    echo "verify-migration-067-php-backup-mysql: OK\n";
} finally {
    @unlink($dumpPath);
    if (isset($admin) && $admin instanceof PDO) {
        foreach ([$sourceDb, $restoreDb] as $database) {
            try {
                $admin->exec('DROP DATABASE IF EXISTS ' . $quoteIdentifier($database));
            } catch (Throwable $ignored) {
            }
        }
    }
}
