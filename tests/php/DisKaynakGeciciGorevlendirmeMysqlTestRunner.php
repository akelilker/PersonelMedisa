<?php

declare(strict_types=1);

/**
 * DIS geçici görevlendirme — son canlı öncesi MariaDB acceptance.
 * php tests/php/DisKaynakGeciciGorevlendirmeMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
use Medisa\Api\Services\Personel\PersonelGeciciGorevlendirmeSchema;
use Medisa\Api\Services\Personel\PersonelGeciciGorevlendirmeService;
use Medisa\Api\Services\Personel\PersonelOperationalContextService;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\Attendance\AttendanceCorrectionApproverResolver;

function dkgAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function dkgPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function dkgApplySqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('SQL okunamadi: ' . $path);
    }
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $pdo->exec(trim($buffer));
            $buffer = '';
        }
    }
}

function dkgMakeRequest(): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => 'GET',
        'path' => '/',
        'headers' => [],
        'jsonBody' => [],
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }
    $_GET = [];

    return $request;
}

/** @return array{process: resource, pipes: array<int, resource>} */
function dkgSpawnCreateChild(string $childDsn, int $personelId, int $subeId, int $bolumId, string $bas, string $bit): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows' && !extension_loaded('pdo_mysql')) {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=pdo_mysql';
    }
    $cmd = array_merge(
        [PHP_BINARY],
        $phpArgs,
        [
            __FILE__,
            '--create-child',
            $childDsn,
            (string) $personelId,
            (string) $subeId,
            (string) $bolumId,
            $bas,
            $bit,
        ]
    );
    $pipes = [];
    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
    if (!is_resource($process)) {
        throw new RuntimeException('create-child start failed');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

function dkgFinishChild(array $child): string
{
    $stdout = trim((string) stream_get_contents($child['pipes'][1]));
    $stderr = trim((string) stream_get_contents($child['pipes'][2]));
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $status = proc_close($child['process']);
    if ($status !== 0 && $stdout === '' && $stderr !== '') {
        throw new RuntimeException('child failed: ' . $stderr);
    }
    $lines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
    $meaningful = array_values(array_filter($lines, static function (string $line): bool {
        $line = trim($line);

        return $line !== '' && stripos($line, 'Warning:') !== 0;
    }));

    return $meaningful === [] ? '' : (string) end($meaningful);
}

if (($argv[1] ?? '') === '--create-child') {
    $pdo = dkgPdo((string) ($argv[2] ?? ''));
    $user = [
        'id' => 1,
        'rol' => 'GENEL_YONETICI',
        'sube_ids' => [],
        'bolum_ids' => [],
        'birim_ids' => [],
    ];
    try {
        PersonelGeciciGorevlendirmeService::create($pdo, $user, [
            'personel_id' => (int) ($argv[3] ?? 0),
            'hedef_sube_id' => (int) ($argv[4] ?? 0),
            'hedef_bolum_id' => (int) ($argv[5] ?? 0),
            'baslangic_at' => (string) ($argv[6] ?? ''),
            'bitis_at' => (string) ($argv[7] ?? ''),
        ]);
        echo 'OK' . PHP_EOL;
    } catch (PersonelValidationException $e) {
        echo ($e->getCodeString() ?: 'VALIDATION_ERROR') . PHP_EOL;
    }
    exit(0);
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '' || stripos($dsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $dsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}

$db = 'medisa_dkg_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = dkgPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = dkgPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));
$childDsn = preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn;

try {
    // --- Minimal org + personel schema (075-era) ---
    $pdo->exec("CREATE TABLE subeler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(120) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE departmanlar (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(120) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE sube_departmanlar (
        sube_id INT UNSIGNED NOT NULL,
        departman_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (sube_id, departman_id),
        CONSTRAINT fk_sd_sube FOREIGN KEY (sube_id) REFERENCES subeler(id),
        CONSTRAINT fk_sd_dep FOREIGN KEY (departman_id) REFERENCES departmanlar(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE bolumler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        departman_id INT UNSIGNED NOT NULL,
        ad VARCHAR(120) NOT NULL,
        CONSTRAINT fk_bolum_dep FOREIGN KEY (departman_id) REFERENCES departmanlar(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE birimler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        bolum_id INT UNSIGNED NOT NULL,
        ad VARCHAR(120) NOT NULL,
        CONSTRAINT fk_birim_bolum FOREIGN KEY (bolum_id) REFERENCES bolumler(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE personeller (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tc_kimlik_no CHAR(11) NULL,
        ad VARCHAR(80) NOT NULL,
        soyad VARCHAR(80) NULL,
        dogum_tarihi DATE NULL,
        telefon VARCHAR(32) NULL,
        sicil_no VARCHAR(32) NOT NULL,
        sube_id INT UNSIGNED NOT NULL DEFAULT 1,
        departman_id INT UNSIGNED NULL,
        bolum_id INT UNSIGNED NULL,
        birim_id INT UNSIGNED NULL,
        ise_giris_tarihi DATE NOT NULL,
        aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
        UNIQUE KEY uq_sicil (sicil_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        password_hash VARCHAR(255) NOT NULL DEFAULT '',
        ad_soyad VARCHAR(120) NOT NULL DEFAULT '',
        rol VARCHAR(40) NOT NULL,
        durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
        personel_id INT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE user_subeler (
        user_id INT UNSIGNED NOT NULL,
        sube_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, sube_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE user_bolumler (
        user_id INT UNSIGNED NOT NULL,
        bolum_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, bolum_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE user_birimler (
        user_id INT UNSIGNED NOT NULL,
        birim_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, birim_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Fixture org: same departman on two subeler (multi-sube ambiguity)
    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1,'SubeX'),(2,'SubeY'),(3,'SubeZ')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (10,'DepShared'),(20,'DepOther')");
    $pdo->exec("INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1,10),(2,10),(3,20)");
    $pdo->exec("INSERT INTO bolumler (id, departman_id, ad) VALUES (100,10,'BolumA'),(200,10,'BolumB'),(300,20,'BolumC')");
    $pdo->exec("INSERT INTO birimler (id, bolum_id, ad) VALUES (1000,100,'BirimY'),(2000,200,'BirimB')");

    dkgApplySqlFile($pdo, __DIR__ . '/../../api/migrations/066_personel_calisan_kapsami.sql');
    dkgAssert(!PersonelGeciciGorevlendirmeSchema::isReady($pdo), '076 absent before upgrade');

    // 075 → 076 upgrade
    dkgApplySqlFile($pdo, __DIR__ . '/../../api/migrations/076_dis_kaynak_gecici_gorevlendirme.sql');
    dkgAssert(PersonelGeciciGorevlendirmeSchema::isReady($pdo), 'MIGRATION_075_TO_076 table ready');
    $nullable = $pdo->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='personeller' AND COLUMN_NAME='sube_id'"
    )->fetchColumn();
    dkgAssert($nullable === 'YES', 'nullable sube_id PASS');

    // Idempotent second apply
    dkgApplySqlFile($pdo, __DIR__ . '/../../api/migrations/076_dis_kaynak_gecici_gorevlendirme.sql');
    dkgAssert(PersonelGeciciGorevlendirmeSchema::isReady($pdo), 'MIGRATION_076_IDEMPOTENT');

    // FK integrity: orphan insert must fail
    $fkFail = false;
    try {
        $pdo->exec("INSERT INTO personel_gecici_gorevlendirmeler
            (personel_id, hedef_sube_id, hedef_departman_id, hedef_bolum_id, baslangic_at, durum)
            VALUES (99999, 1, 10, 100, '2026-09-01 00:00:00', 'AKTIF')");
    } catch (PDOException $e) {
        $fkFail = true;
    }
    dkgAssert($fkFail, 'FK bütünlüğü PASS');

    // DIS unassigned (bağlantısız)
    $pdo->exec("INSERT INTO personeller
        (id, tc_kimlik_no, ad, soyad, sicil_no, sube_id, departman_id, bolum_id, birim_id, ise_giris_tarihi, calisan_kapsami)
        VALUES
        (50, NULL, 'Dis', 'Baglantisiz', 'DIS-50', NULL, NULL, NULL, NULL, '2026-01-01', 'DIS_KAYNAK'),
        (51, NULL, 'Dis', 'Race', 'DIS-51', NULL, NULL, NULL, NULL, '2026-01-01', 'DIS_KAYNAK'),
        (52, NULL, 'Dis', 'PermA', 'DIS-52', NULL, 10, 100, NULL, '2026-01-01', 'DIS_KAYNAK'),
        (60, '11111111110', 'Ic', 'Personel', 'IC-60', 1, 10, 100, 1000, '2020-01-01', 'IC_PERSONEL')");

    $gy = [
        'id' => 1,
        'rol' => 'GENEL_YONETICI',
        'sube_ids' => [],
        'bolum_ids' => [],
        'birim_ids' => [],
    ];
    $pdo->exec("INSERT INTO users (id, username, ad_soyad, rol, durum) VALUES
        (1,'gy','Genel','GENEL_YONETICI','AKTIF'),
        (2,'bya','BolumA','BOLUM_YONETICISI','AKTIF'),
        (3,'byb','BolumB','BOLUM_YONETICISI','AKTIF'),
        (4,'suba','SubeX','SUBE_YONETICISI','AKTIF'),
        (5,'suba2','SubeY','SUBE_YONETICISI','AKTIF'),
        (6,'ba','BirimY','BIRIM_AMIRI','AKTIF')");
    $pdo->exec('INSERT INTO user_bolumler (user_id, bolum_id) VALUES (2,100),(3,200)');
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (4,1),(5,2)');
    $pdo->exec('INSERT INTO user_birimler (user_id, birim_id) VALUES (6,1000)');

    $byA = ['id' => 2, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [], 'bolum_ids' => [100], 'birim_ids' => []];
    $byB = ['id' => 3, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [], 'bolum_ids' => [200], 'birim_ids' => []];
    $subeX = ['id' => 4, 'rol' => 'SUBE_YONETICISI', 'sube_ids' => [1], 'bolum_ids' => [], 'birim_ids' => []];
    $subeY = ['id' => 5, 'rol' => 'SUBE_YONETICISI', 'sube_ids' => [2], 'bolum_ids' => [], 'birim_ids' => []];
    $birimA = ['id' => 6, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [], 'bolum_ids' => [], 'birim_ids' => [1000]];
    $request = dkgMakeRequest();

    // --- MULTI_SUBE: hedef_sube_id required ---
    $failNoSube = false;
    try {
        PersonelGeciciGorevlendirmeService::create($pdo, $gy, [
            'personel_id' => 50,
            'hedef_bolum_id' => 100,
            'baslangic_at' => '2026-09-01',
            'bitis_at' => '2026-09-10',
        ]);
    } catch (PersonelValidationException $e) {
        $failNoSube = $e->getField() === 'hedef_sube_id'
            || stripos($e->getMessage(), 'sube') !== false;
    }
    dkgAssert($failNoSube, 'MULTI_SUBE hedef_sube_id yok FAIL');

    $failWrongSube = false;
    try {
        PersonelGeciciGorevlendirmeService::create($pdo, $gy, [
            'personel_id' => 50,
            'hedef_sube_id' => 3, // dep 20 only — not linked to bolum A dep 10
            'hedef_bolum_id' => 100,
            'baslangic_at' => '2026-09-01',
            'bitis_at' => '2026-09-10',
        ]);
    } catch (PersonelValidationException $e) {
        $failWrongSube = $e->getCodeString() === PersonelGeciciGorevlendirmeService::ERROR_TARGET
            || $e->getField() === 'hedef_sube_id';
    }
    dkgAssert($failWrongSube, 'MULTI_SUBE unrelated hedef_sube FAIL');

    $row = PersonelGeciciGorevlendirmeService::create($pdo, $gy, [
        'personel_id' => 50,
        'hedef_sube_id' => 1,
        'hedef_bolum_id' => 100,
        'hedef_birim_id' => 1000,
        'baslangic_at' => '2026-08-01',
        'bitis_at' => '2026-12-31',
    ]);
    dkgAssert((int) $row['hedef_sube_id'] === 1, 'MULTI_SUBE doğru hedef_sube PASS');
    dkgAssert((int) $row['id'] > 0, 'assignment created');

    // Permanent fields untouched
    $perm = $pdo->query('SELECT sube_id, bolum_id, birim_id FROM personeller WHERE id=50')->fetch();
    dkgAssert($perm['sube_id'] === null && $perm['bolum_id'] === null && $perm['birim_id'] === null, 'permanent org not overwritten');

    // Effective org now
    $nowCtx = PersonelOperationalContextService::resolveNow($pdo, 50);
    dkgAssert($nowCtx['source'] === PersonelOperationalContextService::SOURCE_ASSIGNMENT, 'EFFECTIVE_ORG_NOW assignment');
    dkgAssert((int) $nowCtx['effective']['sube_id'] === 1, 'effective sube X');
    dkgAssert((int) $nowCtx['effective']['bolum_id'] === 100, 'effective bolum A');
    dkgAssert($nowCtx['has_operational_scope'] === true, 'operational ready with assignment');

    // LIST/DETAIL assignment-aware
    $where = [];
    $params = [];
    OrgScope::appendPersonelOrgFilter($where, $params, $byA, null, 'p', 'org', $pdo);
    $sql = 'SELECT p.id FROM personeller p WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $idsA = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    dkgAssert(in_array(50, $idsA, true), 'LIST_DETAIL BY_A list görünür');

    $whereB = [];
    $paramsB = [];
    OrgScope::appendPersonelOrgFilter($whereB, $paramsB, $byB, null, 'p', 'org', $pdo);
    $stmtB = $pdo->prepare('SELECT p.id FROM personeller p WHERE ' . implode(' AND ', $whereB));
    $stmtB->execute($paramsB);
    $idsB = array_map('intval', array_column($stmtB->fetchAll(), 'id'));
    dkgAssert(!in_array(50, $idsB, true), 'LIST_DETAIL BY_B list gizli');

    $eff = PersonelOperationalContextService::effectiveOrgForAccess($pdo, ['id' => 50, 'sube_id' => null, 'bolum_id' => null, 'birim_id' => null]);
    dkgAssert((int) $eff['bolum_id'] === 100, 'DETAIL effective bolum for BY_A');
    dkgAssert((int) $eff['birim_id'] === 1000, 'BIRIM_SCOPE effective birim');

    $whereBirim = [];
    $paramsBirim = [];
    OrgScope::appendPersonelOrgFilter($whereBirim, $paramsBirim, $birimA, null, 'p', 'org', $pdo);
    $stmtBirim = $pdo->prepare('SELECT p.id FROM personeller p WHERE ' . implode(' AND ', $whereBirim));
    $stmtBirim->execute($paramsBirim);
    $idsBirim = array_map('intval', array_column($stmtBirim->fetchAll(), 'id'));
    dkgAssert(in_array(50, $idsBirim, true), 'BIRIM_SCOPE assignment-aware list');

    $whereSube = [];
    $paramsSube = [];
    OrgScope::appendPersonelOrgFilter($whereSube, $paramsSube, $subeX, null, 'p', 'org', $pdo);
    $stmtSube = $pdo->prepare('SELECT p.id FROM personeller p WHERE ' . implode(' AND ', $whereSube));
    $stmtSube->execute($paramsSube);
    $idsSube = array_map('intval', array_column($stmtSube->fetchAll(), 'id'));
    dkgAssert(in_array(50, $idsSube, true), 'SUBE_SCOPE assignment-aware list');

    $whereSubeY = [];
    $paramsSubeY = [];
    OrgScope::appendPersonelOrgFilter($whereSubeY, $paramsSubeY, $subeY, null, 'p', 'org', $pdo);
    $stmtSubeY = $pdo->prepare('SELECT p.id FROM personeller p WHERE ' . implode(' AND ', $whereSubeY));
    $stmtSubeY->execute($paramsSubeY);
    $idsSubeY = array_map('intval', array_column($stmtSubeY->fetchAll(), 'id'));
    dkgAssert(!in_array(50, $idsSubeY, true), 'ROLE_SUBE other sube gizli');

    // ROLE: BOLUM may not assign other bolum — subprocess (JsonResponse exits)
    $scriptBolum = tempnam(sys_get_temp_dir(), 'dkg_bolum_') . '.php';
    file_put_contents($scriptBolum, "<?php
require_once " . var_export(__DIR__ . '/../../api/src/bootstrap.php', true) . ";
use Medisa\\Api\\Services\\Personel\\PersonelGeciciGorevlendirmeService;
\$pdo=new PDO(" . var_export($childDsn, true) . "," . var_export(getenv('MEDISA_TEST_MYSQL_USER') ?: 'root', true) . "," . var_export(getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', true) . ",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
\$user=['id'=>3,'rol'=>'BOLUM_YONETICISI','sube_ids'=>[],'bolum_ids'=>[200],'birim_ids'=>[]];
PersonelGeciciGorevlendirmeService::create(\$pdo,\$user,[
  'personel_id'=>51,'hedef_sube_id'=>1,'hedef_bolum_id'=>100,
  'baslangic_at'=>'2026-10-01','bitis_at'=>'2026-10-05'
]);
echo 'UNEXPECTED_OK';
");
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        if (!extension_loaded('pdo_mysql')) {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension=pdo_mysql';
        }
    }
    $cmdPrefix = escapeshellarg(PHP_BINARY);
    foreach ($phpArgs as $a) {
        $cmdPrefix .= ' ' . escapeshellarg($a);
    }
    $bolumOut = trim((string) shell_exec($cmdPrefix . ' ' . escapeshellarg($scriptBolum) . ' 2>&1'));
    @unlink($scriptBolum);
    dkgAssert(
        strpos($bolumOut, 'GECICI_GOREVLENDIRME_YETKI_YOK') !== false || strpos($bolumOut, 'FORBIDDEN') !== false,
        'ROLE_BOLUM other bolum 403'
    );

    // BIRIM cannot create assignment
    $script = tempnam(sys_get_temp_dir(), 'dkg_role_') . '.php';
    file_put_contents($script, "<?php
require_once " . var_export(__DIR__ . '/../../api/src/bootstrap.php', true) . ";
use Medisa\\Api\\Services\\Personel\\PersonelGeciciGorevlendirmeService;
\$pdo=new PDO(" . var_export($childDsn, true) . "," . var_export(getenv('MEDISA_TEST_MYSQL_USER') ?: 'root', true) . "," . var_export(getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', true) . ",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
\$user=['id'=>6,'rol'=>'BIRIM_AMIRI','sube_ids'=>[],'bolum_ids'=>[],'birim_ids'=>[1000]];
PersonelGeciciGorevlendirmeService::create(\$pdo,\$user,[
  'personel_id'=>51,'hedef_sube_id'=>1,'hedef_bolum_id'=>100,
  'baslangic_at'=>'2026-10-01','bitis_at'=>'2026-10-05'
]);
echo 'UNEXPECTED_OK';
");
    $roleOut = trim((string) shell_exec($cmdPrefix . ' ' . escapeshellarg($script) . ' 2>&1'));
    @unlink($script);
    dkgAssert(
        strpos($roleOut, 'GECICI_GOREVLENDIRME_YETKI_YOK') !== false || strpos($roleOut, 'FORBIDDEN') !== false,
        'ROLE_BIRIM cannot assign'
    );

    // SUBE role: can assign within own sube; other sube denied
    $script2 = tempnam(sys_get_temp_dir(), 'dkg_sube_') . '.php';
    file_put_contents($script2, "<?php
require_once " . var_export(__DIR__ . '/../../api/src/bootstrap.php', true) . ";
use Medisa\\Api\\Services\\Personel\\PersonelGeciciGorevlendirmeService;
\$pdo=new PDO(" . var_export($childDsn, true) . "," . var_export(getenv('MEDISA_TEST_MYSQL_USER') ?: 'root', true) . "," . var_export(getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', true) . ",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
\$okUser=['id'=>4,'rol'=>'SUBE_YONETICISI','sube_ids'=>[1],'bolum_ids'=>[],'birim_ids'=>[]];
\$badUser=['id'=>5,'rol'=>'SUBE_YONETICISI','sube_ids'=>[2],'bolum_ids'=>[],'birim_ids'=>[]];
\$own='FAIL';
try {
  PersonelGeciciGorevlendirmeService::create(\$pdo,\$okUser,[
    'personel_id'=>51,'hedef_sube_id'=>1,'hedef_bolum_id'=>200,
    'baslangic_at'=>'2026-11-01','bitis_at'=>'2026-11-05'
  ]);
  \$own='PASS';
} catch (Throwable \$e) { \$own='FAIL'; }
echo 'OWN='.\$own.PHP_EOL;
PersonelGeciciGorevlendirmeService::create(\$pdo,\$badUser,[
  'personel_id'=>51,'hedef_sube_id'=>1,'hedef_bolum_id'=>200,
  'baslangic_at'=>'2026-11-10','bitis_at'=>'2026-11-15'
]);
echo 'OTHER=OK'.PHP_EOL;
");
    $subeOut = trim((string) shell_exec($cmdPrefix . ' ' . escapeshellarg($script2) . ' 2>&1'));
    @unlink($script2);
    $pdo->exec("DELETE FROM personel_gecici_gorevlendirmeler WHERE personel_id=51");
    dkgAssert(strpos($subeOut, 'OWN=PASS') !== false, 'ROLE_SUBE own sube assign');
    dkgAssert(
        strpos($subeOut, 'GECICI_GOREVLENDIRME_YETKI_YOK') !== false || strpos($subeOut, 'FORBIDDEN') !== false,
        'ROLE_SUBE other sube 403'
    );

    // Financial always closed for DIS
    $finBlocked = false;
    try {
        PersonelCalisanKapsamService::assertFinancialEligibleOrThrow($pdo, 50);
    } catch (PersonelValidationException $e) {
        $finBlocked = $e->getCodeString() === PersonelCalisanKapsamService::ERROR_FINANSAL;
    }
    dkgAssert($finBlocked, 'DIS_REAL_PAYROLL / FINANCIAL fail-closed');
    $finPred = PersonelCalisanKapsamService::sqlFinancialEligiblePredicate($pdo, 'p');
    $finCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM personeller p WHERE p.id=50 AND {$finPred}"
    )->fetchColumn();
    dkgAssert($finCount === 0, 'FINANCIAL_DB_TEST DIS candidate=0');

    // Puantaj assignment-aware SQL
    $paramsP = ['sube_id' => 1];
    $pred = PersonelGeciciGorevlendirmeService::sqlPersonelMatchesEffectiveSube($pdo, 'p', 'sube_id', $paramsP, null, 't_eff');
    $stmtP = $pdo->prepare("SELECT p.id FROM personeller p WHERE {$pred} AND p.id=50");
    $stmtP->execute($paramsP);
    dkgAssert((int) $stmtP->fetchColumn() === 50, 'PUANTAJ_DB active assignment VAR');

    // End assignment → leave current scope
    PersonelGeciciGorevlendirmeService::end($pdo, $gy, (int) $row['id'], '2026-08-20 12:00:00');
    $after = PersonelOperationalContextService::resolveNow($pdo, 50);
    dkgAssert($after['has_operational_scope'] === false, 'assignment end → not operational');
    $whereA2 = [];
    $paramsA2 = [];
    OrgScope::appendPersonelOrgFilter($whereA2, $paramsA2, $byA, null, 'p', 'org', $pdo);
    $stmtA2 = $pdo->prepare('SELECT p.id FROM personeller p WHERE ' . implode(' AND ', $whereA2));
    $stmtA2->execute($paramsA2);
    $idsA2 = array_map('intval', array_column($stmtA2->fetchAll(), 'id'));
    dkgAssert(!in_array(50, $idsA2, true), 'LIST after end BY_A current gizli');

    $paramsP2 = ['sube_id' => 1];
    $pred2 = PersonelGeciciGorevlendirmeService::sqlPersonelMatchesEffectiveSube($pdo, 'p', 'sube_id', $paramsP2, null, 't2');
    $stmtP2 = $pdo->prepare("SELECT p.id FROM personeller p WHERE {$pred2} AND p.id=50");
    $stmtP2->execute($paramsP2);
    dkgAssert($stmtP2->fetchColumn() === false, 'PUANTAJ after end YOK');

    // Historical resolveAt during assignment window
    $hist = PersonelOperationalContextService::resolveAt($pdo, 50, '2026-08-15 10:00:00');
    dkgAssert($hist['source'] === PersonelOperationalContextService::SOURCE_ASSIGNMENT, 'EFFECTIVE_ORG_AT_TIME');
    dkgAssert((int) $hist['effective']['bolum_id'] === 100, 'HISTORICAL bolum A');
    $chain = AttendanceCorrectionApproverResolver::chainForRequesterRole('PERSONEL');
    dkgAssert($chain === ['BIRIM_AMIRI', 'BOLUM_YONETICISI', 'GENEL_YONETICI'], 'HISTORICAL_CORRECTION chain canonical');
    dkgAssert(!in_array('SUBE_YONETICISI', $chain, true), 'SUBE_YONETICISI not in correction chain');

    // Second end reject
    $secondEnd = false;
    try {
        PersonelGeciciGorevlendirmeService::end($pdo, $gy, (int) $row['id']);
    } catch (PersonelValidationException $e) {
        $secondEnd = true;
    }
    dkgAssert($secondEnd, 'second end reject');

    // Invalid date reject
    $badDate = false;
    try {
        PersonelGeciciGorevlendirmeService::create($pdo, $gy, [
            'personel_id' => 50,
            'hedef_sube_id' => 1,
            'hedef_bolum_id' => 100,
            'baslangic_at' => '2026-09-10',
            'bitis_at' => '2026-09-01',
        ]);
    } catch (PersonelValidationException $e) {
        $badDate = $e->getField() === 'bitis_at';
    }
    dkgAssert($badDate, 'bitis < baslangic reject');

    // Non-overlap adjacent PASS
    PersonelGeciciGorevlendirmeService::create($pdo, $gy, [
        'personel_id' => 50,
        'hedef_sube_id' => 1,
        'hedef_bolum_id' => 100,
        'baslangic_at' => '2026-09-01',
        'bitis_at' => '2026-09-05 23:59:59',
    ]);
    PersonelGeciciGorevlendirmeService::create($pdo, $gy, [
        'personel_id' => 50,
        'hedef_sube_id' => 1,
        'hedef_bolum_id' => 200,
        'baslangic_at' => '2026-09-06',
        'bitis_at' => '2026-09-10 23:59:59',
    ]);
    $cntAdj = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_gecici_gorevlendirmeler WHERE personel_id=50 AND durum='AKTIF'"
    )->fetchColumn();
    dkgAssert($cntAdj === 2, 'NON_OVERLAP 01-05 / 06-10 PASS');
    $pdo->exec("UPDATE personel_gecici_gorevlendirmeler SET durum='SONLANDIRILDI', bitis_at='2026-09-05 23:59:59' WHERE personel_id=50 AND durum='AKTIF'");

    // Permanent override: DIS permanent Bolum A, temp Bolum B
    $pdo->exec('UPDATE personeller SET bolum_id=100, departman_id=10 WHERE id=52');
    PersonelGeciciGorevlendirmeService::create($pdo, $gy, [
        'personel_id' => 52,
        'hedef_sube_id' => 1,
        'hedef_bolum_id' => 200,
        'baslangic_at' => '2026-01-01',
        'bitis_at' => '2099-12-31',
    ]);
    $ov = PersonelOperationalContextService::resolveNow($pdo, 52);
    dkgAssert((int) $ov['effective']['bolum_id'] === 200, 'temp override effective=B');
    dkgAssert((int) $ov['permanent']['bolum_id'] === 100, 'permanent stays A');
    $asgId = (int) $ov['active_assignment']['id'];
    PersonelGeciciGorevlendirmeService::end($pdo, $gy, $asgId, '2026-08-27');
    $ov2 = PersonelOperationalContextService::resolveNow($pdo, 52);
    // permanent bolum A but no sube → not operational ready
    dkgAssert((int) $ov2['permanent']['bolum_id'] === 100, 'after end permanent A');
    dkgAssert($ov2['has_operational_scope'] === false, 'partial org not operational ready');

    // CONCURRENCY: overlapping inserts
    $pdo->exec("DELETE FROM personel_gecici_gorevlendirmeler WHERE personel_id=51");
    $c1 = dkgSpawnCreateChild($childDsn, 51, 1, 100, '2026-09-01', '2026-09-10');
    $c2 = dkgSpawnCreateChild($childDsn, 51, 1, 200, '2026-09-05', '2026-09-12');
    $r1 = dkgFinishChild($c1);
    $r2 = dkgFinishChild($c2);
    $results = [$r1, $r2];
    $okCount = count(array_filter($results, static fn ($r) => $r === 'OK'));
    $conflictCount = count(array_filter(
        $results,
        static fn ($r) => $r === PersonelGeciciGorevlendirmeService::ERROR_CONFLICT
    ));
    $insertCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_gecici_gorevlendirmeler WHERE personel_id=51 AND durum='AKTIF'"
    )->fetchColumn();
    dkgAssert($insertCount === 1, 'CONCURRENCY INSERT_COUNT=1');
    dkgAssert($okCount === 1 && $conflictCount === 1, 'CONCURRENCY other=GECICI_GOREVLENDIRME_CAKISMA');

    // Unassigned DIS: no time ops
    $pdo->exec("UPDATE personel_gecici_gorevlendirmeler SET durum='SONLANDIRILDI' WHERE personel_id=50");
    $unassignedOps = false;
    try {
        PersonelCalisanKapsamService::assertTimeOperationalEligibleOrThrow($pdo, 50);
    } catch (PersonelValidationException $e) {
        $unassignedOps = $e->getCodeString() === PersonelCalisanKapsamService::ERROR_ORG_SCOPE;
    }
    dkgAssert($unassignedOps, 'QR/time unassigned fail-closed');

    // Silent fallback string gone
    $svcSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Personel/PersonelGeciciGorevlendirmeService.php');
    dkgAssert(strpos($svcSrc, 'ORDER BY sube_id ASC LIMIT 1') === false, 'SILENT_SUBE_FALLBACK removed');
    dkgAssert(strpos($svcSrc, 'FOR UPDATE') !== false, 'PERSONEL_ROW_FOR_UPDATE present');
    dkgAssert(strpos($svcSrc, 'hedef_sube_id') !== false, 'HEDEF_SUBE_EXPLICIT');

    echo "verify-dis-kaynak-gecici-gorevlendirme-mysql: OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
