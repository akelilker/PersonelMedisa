<?php

declare(strict_types=1);

function migrationAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
$password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
if ($dsn === '' || $user === '') {
    throw new RuntimeException('Isolated MariaDB credentials are required.');
}
$admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'medisa_s77c_migration_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

/**
 * Splits a migration file into executable statements.
 * Trigger bodies (IF ... END IF) are kept as single statements.
 *
 * @return array<int, string>
 */
function splitMigrationStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

try {
    $testDsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $dsn);
    $pdo = new PDO($testDsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE personeller (
        id INT UNSIGNED PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        maas_tutari DECIMAL(12,2) NULL, ise_giris_tarihi DATE NULL
    ) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        sube_id INT UNSIGNED NOT NULL, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
        donem CHAR(7) NOT NULL, durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI',
        muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    foreach ([
        '020_maas_hesaplama_snapshotlari.sql',
        '021_maas_hesaplama_snapshot_guvenlik_indexleri.sql',
        '022_personel_bordro_devirleri.sql',
        '023_maas_hesaplama_adaylari.sql',
        '024_maas_hesaplama_aday_guvenlik_indexleri.sql',
    ] as $file) {
        $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
        if ($sql === false) {
            throw new RuntimeException('Migration okunamadi: ' . $file);
        }
        migrationAssert(
            !preg_match('/\b(DROP\s+TABLE|DROP\s+COLUMN|TRUNCATE|DELETE\s+FROM|ALTER\s+TABLE[^;]*\bDROP\s+(COLUMN|KEY|INDEX|FOREIGN))\b/i', $sql),
            $file . ' destructive SQL icermiyor'
        );
        foreach (splitMigrationStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
        echo '[PASS] ' . $file . ' temiz uygulandi' . PHP_EOL;
    }

    foreach ([
        'maas_hesaplama_donem_snapshotlari',
        'maas_hesaplama_personel_snapshotlari',
        'maas_hesaplama_girdi_snapshotlari',
        'maas_hesaplama_snapshot_auditleri',
        'personel_bordro_devirleri',
        'maas_hesaplama_calistirmalari',
        'maas_hesaplama_adaylari',
        'maas_hesaplama_aday_kalemleri',
        'maas_hesaplama_auditleri',
    ] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :table');
        $stmt->execute(['db' => $database, 'table' => $table]);
        migrationAssert((int) $stmt->fetchColumn() === 1, $table . ' tablosu olusturuldu');
    }

    $uniqueIndexes = $pdo->query(
        "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'maas_hesaplama_donem_snapshotlari'
           AND index_name IN ('uq_mhds_sube_donem_revision', 'uq_mhds_muhur_revision', 'uq_mhds_aktif_snapshot')"
    )->fetchColumn();
    migrationAssert((int) $uniqueIndexes === 3, 'donem snapshot unique indexleri mevcut');

    $auditUnique = $pdo->query(
        "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'maas_hesaplama_snapshot_auditleri'
           AND index_name = 'uq_mhsa_idempotency'"
    )->fetchColumn();
    migrationAssert((int) $auditUnique === 1, 'audit idempotency unique key mevcut');

    $jsonColumns = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND ((table_name = 'maas_hesaplama_personel_snapshotlari' AND column_name = 'personel_snapshot_json')
             OR (table_name = 'maas_hesaplama_girdi_snapshotlari' AND column_name = 'payload_json')
             OR (table_name = 'maas_hesaplama_snapshot_auditleri' AND column_name = 'snapshot_json'))"
    )->fetchColumn();
    migrationAssert((int) $jsonColumns === 3, 'JSON payload kolonlari mevcut');

    $charset = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name LIKE 'maas_hesaplama_%'
           AND table_collation = 'utf8mb4_unicode_ci'"
    )->fetchColumn();
    migrationAssert((int) $charset === 8, 'charset/collation utf8mb4_unicode_ci');

    $triggers = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema = DATABASE() AND trigger_name IN (
            'trg_mhps_no_update', 'trg_mhps_no_delete',
            'trg_mhgs_no_update', 'trg_mhgs_no_delete',
            'trg_mhds_no_delete', 'trg_mhds_guarded_update',
            'trg_mhsa_no_update', 'trg_mhsa_no_delete',
            'trg_mha_no_update', 'trg_mha_no_delete',
            'trg_mhak_no_update', 'trg_mhak_no_delete',
            'trg_mhc_no_delete', 'trg_mhc_guarded_update',
            'trg_mhaud_no_update', 'trg_mhaud_no_delete'
         )"
    )->fetchColumn();
    migrationAssert((int) $triggers === 16, 'immutability triggerlari olusturuldu');

    $foreignKeys = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.referential_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name LIKE 'maas_hesaplama_%'"
    )->fetchColumn();
    migrationAssert((int) $foreignKeys >= 10, 'snapshot foreign keyleri mevcut');

    $pdo->exec('INSERT INTO subeler VALUES (1)');
    $pdo->exec('INSERT INTO users VALUES (1)');
    $pdo->exec("INSERT INTO personeller VALUES (1, 1, NULL, '2020-01-01')");
    $pdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, muhurlenen_kayit_sayisi, created_by)
        VALUES (1, 2026, 3, '2026-03', 2, 1)");

    $pdo->exec("INSERT INTO maas_hesaplama_donem_snapshotlari (
        sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
        state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
        personel_sayisi, girdi_sayisi, created_by
    ) VALUES (
        1, 2026, 3, '2026-03', '2026-03-01', '2026-03-31', 1, 1,
        'OLUSTURULDU', '2026-04-01 10:00:00', REPEAT('a', 64), REPEAT('b', 64), REPEAT('c', 64),
        1, 1, 1
    )");
    $snapshotId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO maas_hesaplama_personel_snapshotlari (
        donem_snapshot_id, personel_id, personel_snapshot_json, personel_snapshot_hash,
        istihdam_baslangic
    ) VALUES ($snapshotId, 1, '{\"ad\":\"Test\"}', REPEAT('d', 64), '2020-01-01')");
    $personelSnapshotId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO maas_hesaplama_girdi_snapshotlari (
        donem_snapshot_id, personel_snapshot_id, kaynak_turu, kaynak_tablo, sira_no,
        payload_json, payload_hash
    ) VALUES ($snapshotId, $personelSnapshotId, 'UCRET', 'personel_ucret_gecmisi', 1, '{\"t\":1}', REPEAT('e', 64))");

    $pdo->exec("INSERT INTO maas_hesaplama_calistirmalari (
        snapshot_id, sube_id, yil, ay, revision_no, state, engine_version, contract_version,
        snapshot_hash, parameter_set_hash, carryover_set_hash, request_hash, source_hash, result_hash,
        calculation_input_hash, personel_sayisi, basarili_aday_sayisi, created_by
    ) VALUES (
        $snapshotId, 1, 2026, 3, 1, 'HESAPLANDI', 'S77D_PAYROLL_ENGINE_V1', 'S77D_PAYROLL_CANDIDATE_V1',
        REPEAT('c', 64), REPEAT('p', 64), REPEAT('q', 64), REPEAT('r', 64), REPEAT('s', 64), REPEAT('t', 64),
        REPEAT('u', 64), 1, 1, 1
    )");
    $calistirmaId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO maas_hesaplama_adaylari (
        calistirma_id, personel_snapshot_id, personel_id, revision_no, state, ucret_turu, para_birimi,
        sozlesme_brut_tutar, hesaplanan_brut_tutar, sgk_matrahi, gelir_vergisi_matrahi,
        damga_vergisi_matrahi, sgk_isci_primi, issizlik_isci_primi, gelir_vergisi, damga_vergisi,
        toplam_ek_odeme, toplam_kesinti, net_odenecek, sonraki_kumulatif_vergi_matrahi,
        input_hash, result_hash, engine_version
    ) VALUES (
        $calistirmaId, $personelSnapshotId, 1, 1, 'HESAPLANDI', 'BRUT', 'TRY',
        50000, 50000, 50000, 42500, 50000, 7000, 500, 3375, 182,
        0, 11057, 38943, 42500, REPEAT('v', 64), REPEAT('w', 64), 'S77D_PAYROLL_ENGINE_V1'
    )");
    $adayId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO maas_hesaplama_aday_kalemleri (
        aday_id, sira_no, kalem_grubu, kalem_kodu, yon, tutar, payload_json, payload_hash
    ) VALUES ($adayId, 1, 'UCRET', 'BAZ_UCRET', 'BILGI', 50000, '{\"t\":1}', REPEAT('x', 64))");

    $updateBlocked = false;
    try {
        $pdo->exec("UPDATE maas_hesaplama_girdi_snapshotlari SET payload_json = '{\"t\":2}' WHERE id = 1");
    } catch (PDOException $e) {
        $updateBlocked = strpos($e->getMessage(), 'PAYROLL_SNAPSHOT_IMMUTABLE') !== false;
    }
    migrationAssert($updateBlocked, 'girdi snapshot UPDATE trigger ile reddedildi');

    $deleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM maas_hesaplama_personel_snapshotlari WHERE id = 1');
    } catch (PDOException $e) {
        $deleteBlocked = strpos($e->getMessage(), 'PAYROLL_SNAPSHOT_IMMUTABLE') !== false;
    }
    migrationAssert($deleteBlocked, 'personel snapshot DELETE trigger ile reddedildi');

    $adayUpdateBlocked = false;
    try {
        $pdo->exec("UPDATE maas_hesaplama_adaylari SET net_odenecek = 1 WHERE id = $adayId");
    } catch (PDOException $e) {
        $adayUpdateBlocked = strpos($e->getMessage(), 'PAYROLL_CALCULATION_IMMUTABLE') !== false;
    }
    migrationAssert($adayUpdateBlocked, 'aday UPDATE trigger ile reddedildi');

    $kalemDeleteBlocked = false;
    try {
        $pdo->exec("DELETE FROM maas_hesaplama_aday_kalemleri WHERE aday_id = $adayId");
    } catch (PDOException $e) {
        $kalemDeleteBlocked = strpos($e->getMessage(), 'PAYROLL_CALCULATION_IMMUTABLE') !== false;
    }
    migrationAssert($kalemDeleteBlocked, 'aday kalem DELETE trigger ile reddedildi');

    $rootSourceBlocked = false;
    try {
        $pdo->exec("UPDATE maas_hesaplama_donem_snapshotlari SET source_hash = REPEAT('f', 64) WHERE id = $snapshotId");
    } catch (PDOException $e) {
        $rootSourceBlocked = strpos($e->getMessage(), 'PAYROLL_SNAPSHOT_IMMUTABLE') !== false;
    }
    migrationAssert($rootSourceBlocked, 'root snapshot kaynak alan update reddedildi');

    $pdo->exec("UPDATE maas_hesaplama_donem_snapshotlari
        SET state = 'IPTAL', iptal_edildi_by = 1, iptal_edildi_at = CURRENT_TIMESTAMP, iptal_nedeni = 'test'
        WHERE id = $snapshotId");
    $state = $pdo->query("SELECT state FROM maas_hesaplama_donem_snapshotlari WHERE id = $snapshotId")->fetchColumn();
    migrationAssert($state === 'IPTAL', 'root snapshot OLUSTURULDU -> IPTAL gecisi calisti');

    $reCancelBlocked = false;
    try {
        $pdo->exec("UPDATE maas_hesaplama_donem_snapshotlari SET iptal_nedeni = 'degisti' WHERE id = $snapshotId");
    } catch (PDOException $e) {
        $reCancelBlocked = strpos($e->getMessage(), 'PAYROLL_SNAPSHOT_IMMUTABLE') !== false;
    }
    migrationAssert($reCancelBlocked, 'IPTAL sonrasi root update reddedildi');

    $businessRows = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    migrationAssert($businessRows === 1, 'mevcut business satirlari korundu');

    echo 'verify-maas-hesaplama-migrations: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
