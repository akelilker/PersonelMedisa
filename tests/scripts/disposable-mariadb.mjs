import { spawn, spawnSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { createConnection } from "node:net";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(__dirname, "../..");
const dataRoot = join(repoRoot, ".test-mariadb");
const dataDir = join(dataRoot, "data");
const startupLockDir = join(dataRoot, "startup.lock");
const executionLockDir = join(dataRoot, "execution.lock");
const executionLockOwner = join(executionLockDir, "owner.pid");
const managedPidFile = join(dataRoot, "mysqld.pid");
const defaultPort = Number.parseInt(process.env.MEDISA_TEST_MYSQL_PORT ?? "3307", 10);
const defaultUser = process.env.MEDISA_TEST_MYSQL_USER ?? "root";
const defaultPassword = process.env.MEDISA_TEST_MYSQL_PASSWORD ?? "";
const LOCAL_TEST_MYSQL_HOSTS = new Set(["127.0.0.1", "localhost", "::1"]);
const STALE_STARTUP_LOCK_MS = 60_000;
const STALE_EXECUTION_LOCK_MS = 900_000;
const ORPHAN_EXECUTION_LOCK_MS = 60_000;

/** @type {import('node:child_process').ChildProcess | null} */
let managedProcess = null;

function candidateMysqldPaths() {
  const fromEnv = process.env.MEDISA_TEST_MYSQLD_PATH;
  const candidates = [
    fromEnv,
    "C:\\Program Files\\MariaDB 12.3\\bin\\mysqld.exe",
    "C:\\Program Files\\MariaDB 11.4\\bin\\mysqld.exe",
    "C:\\Program Files\\MariaDB 10.11\\bin\\mysqld.exe",
    "mysqld"
  ].filter(Boolean);

  return [...new Set(candidates)];
}

function candidateInstallDbPaths(mysqldPath) {
  const dir = dirname(mysqldPath);
  return [
    join(dir, "mariadb-install-db.exe"),
    join(dir, "mysql_install_db.exe"),
    "mariadb-install-db",
    "mysql_install_db"
  ];
}

function waitForPort(port, timeoutMs = 30_000) {
  const started = Date.now();
  return new Promise((resolvePromise, reject) => {
    const attempt = () => {
      const socket = createConnection({ host: "127.0.0.1", port });
      socket.once("connect", () => {
        socket.end();
        resolvePromise(true);
      });
      socket.once("error", () => {
        socket.destroy();
        if (Date.now() - started > timeoutMs) {
          reject(new Error(`MariaDB did not open port ${port} within ${timeoutMs}ms.`));
          return;
        }
        setTimeout(attempt, 250);
      });
    };
    attempt();
  });
}

function isPortOpen(port) {
  return new Promise((resolvePromise) => {
    const socket = createConnection({ host: "127.0.0.1", port });
    socket.once("connect", () => {
      socket.end();
      resolvePromise(true);
    });
    socket.once("error", () => {
      socket.destroy();
      resolvePromise(false);
    });
  });
}

async function acquireStartupLock(timeoutMs = 45_000) {
  mkdirSync(dataRoot, { recursive: true });
  const started = Date.now();
  while (Date.now() - started <= timeoutMs) {
    try {
      mkdirSync(startupLockDir);
      return () => rmSync(startupLockDir, { recursive: true, force: true });
    } catch (error) {
      if (error?.code !== "EEXIST") {
        throw error;
      }
      try {
        if (Date.now() - statSync(startupLockDir).mtimeMs > STALE_STARTUP_LOCK_MS) {
          rmSync(startupLockDir, { recursive: true, force: true });
          continue;
        }
      } catch {
        // Lock owner may have released it between exists/stat checks.
      }
      await new Promise((resolvePromise) => setTimeout(resolvePromise, 100));
    }
  }
  throw new Error("Timed out waiting for disposable MariaDB startup owner lock.");
}

function sleepSync(milliseconds) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds);
}

function isProcessAlive(pid) {
  if (!Number.isInteger(pid) || pid <= 0) {
    return false;
  }
  try {
    process.kill(pid, 0);
    return true;
  } catch {
    return false;
  }
}

function acquireExecutionLock(timeoutMs = 600_000) {
  mkdirSync(dataRoot, { recursive: true });
  const started = Date.now();
  while (Date.now() - started <= timeoutMs) {
    try {
      mkdirSync(executionLockDir);
      writeFileSync(executionLockOwner, String(process.pid), "utf8");
      return () => rmSync(executionLockDir, { recursive: true, force: true });
    } catch (error) {
      if (error?.code !== "EEXIST") {
        throw error;
      }

      let ownerPid = 0;
      try {
        ownerPid = Number.parseInt(readFileSync(executionLockOwner, "utf8").trim(), 10);
      } catch {
        // Lock directory creation and owner file write are not atomic together.
      }
      try {
        const lockAgeMs = Date.now() - statSync(executionLockDir).mtimeMs;
        const ownerDead = ownerPid > 0 && !isProcessAlive(ownerPid);
        const ownerMissing = !(ownerPid > 0);
        if (ownerDead || lockAgeMs > STALE_EXECUTION_LOCK_MS || (ownerMissing && lockAgeMs > ORPHAN_EXECUTION_LOCK_MS)) {
          rmSync(executionLockDir, { recursive: true, force: true });
          continue;
        }
      } catch {
        // Lock owner may have released it between exists/read/stat checks.
      }
      sleepSync(100);
    }
  }
  throw new Error("Timed out waiting for disposable MariaDB execution owner lock.");
}

function phpMysqlBootstrapArgs() {
  // Fatal runner errors must remain visible; otherwise PHP exits 255 with no owner evidence.
  const args = ["-d", "display_errors=stderr", "-d", "log_errors=0"];
  if (process.platform === "win32") {
    const extensionDirResult = spawnSync("php", ["-d", "display_errors=0", "-r", "echo ini_get('extension_dir');"], {
      encoding: "utf8"
    });
    const extensionDir = (extensionDirResult.stdout || "")
      .trim()
      .split(/\r?\n/)
      .filter((line) => line && !/warning/i.test(line))
      .pop();
    if (extensionDir) {
      args.push("-d", `extension_dir=${extensionDir}`);
    }
  }

  // Avoid "Module pdo_mysql is already loaded" when php.ini already enables it.
  const loaded = spawnSync(
    "php",
    ["-d", "display_errors=0", "-r", "echo extension_loaded('pdo_mysql') ? '1' : '0';"],
    { encoding: "utf8" }
  );
  const hasPdoMysql = (loaded.stdout || "").trim().split(/\r?\n/).filter(Boolean).pop() === "1";
  if (!hasPdoMysql) {
    args.push("-d", process.platform === "win32" ? "extension=php_pdo_mysql.dll" : "extension=pdo_mysql");
  }

  return args;
}

function tryPdoPing(dsn, user, password) {
  const script = `try {
  $pdo = new PDO(${JSON.stringify(dsn)}, ${JSON.stringify(user)}, ${JSON.stringify(password)}, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
  $pdo->query('SELECT 1');
  echo 'OK';
} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage());
  exit(1);
}`;
  const result = spawnSync("php", [...phpMysqlBootstrapArgs(), "-d", "display_errors=0", "-r", script], {
    encoding: "utf8"
  });
  return result.status === 0 && result.stdout.includes("OK");
}

function ensureDataDir(mysqldPath) {
  mkdirSync(dataRoot, { recursive: true });
  if (existsSync(join(dataDir, "mysql"))) {
    return;
  }

  rmSync(dataDir, { recursive: true, force: true });
  mkdirSync(dataDir, { recursive: true });

  const installDb = candidateInstallDbPaths(mysqldPath).find((candidate) => {
    if (candidate.includes("\\") || candidate.includes("/")) {
      return existsSync(candidate);
    }
    return spawnSync(candidate, ["--version"], { encoding: "utf8" }).status === 0;
  });

  if (!installDb) {
    throw new Error("MariaDB data directory is missing and install helper was not found.");
  }

  const install = spawnSync(installDb, [`--datadir=${dataDir}`, "--password="], {
    encoding: "utf8"
  });
  if (install.status !== 0) {
    throw new Error(install.stderr || install.stdout || "MariaDB bootstrap failed.");
  }
}

function startManagedInstance(port) {
  const mysqldPath = candidateMysqldPaths().find((candidate) => {
    if (candidate.includes("\\") || candidate.includes("/")) {
      return existsSync(candidate);
    }
    return spawnSync(candidate, ["--version"], { encoding: "utf8" }).status === 0;
  });

  if (!mysqldPath) {
    throw new Error("MariaDB server binary was not found. Install MariaDB or set MEDISA_TEST_MYSQL_DSN.");
  }

  ensureDataDir(mysqldPath);

  managedProcess = spawn(
    mysqldPath,
    [
      `--datadir=${dataDir}`,
      `--port=${port}`,
      "--bind-address=127.0.0.1",
      "--skip-grant-tables",
      "--console"
    ],
    { stdio: "ignore", windowsHide: true, detached: true }
  );
  if (managedProcess.pid) {
    writeFileSync(managedPidFile, String(managedProcess.pid), "utf8");
  }
  managedProcess.unref();

  managedProcess.on("exit", () => {
    managedProcess = null;
    rmSync(managedPidFile, { force: true });
  });
}

function assertLocalTestMysqlDsn(dsn) {
  const hostMatch = /(?:^|[;])\s*host\s*=\s*([^;]+)/i.exec(String(dsn));
  const host = (hostMatch?.[1] ?? "").trim().toLowerCase();
  if (!LOCAL_TEST_MYSQL_HOSTS.has(host)) {
    throw new Error(
      `MEDISA_TEST_MYSQL_DSN yalnız localhost/127.0.0.1/::1 kabul eder; production veya uzak host engellendi (${host || "bos"}).`
    );
  }
}

export function buildMysqlDsn(port) {
  return `mysql:host=127.0.0.1;port=${port};dbname=mysql;charset=utf8mb4`;
}

export async function ensureDisposableMariaDbEnv() {
  if (process.env.MEDISA_TEST_MYSQL_DSN && process.env.MEDISA_TEST_MYSQL_USER) {
    assertLocalTestMysqlDsn(process.env.MEDISA_TEST_MYSQL_DSN);
    if (tryPdoPing(process.env.MEDISA_TEST_MYSQL_DSN, process.env.MEDISA_TEST_MYSQL_USER, process.env.MEDISA_TEST_MYSQL_PASSWORD ?? "")) {
      return {
        dsn: process.env.MEDISA_TEST_MYSQL_DSN,
        user: process.env.MEDISA_TEST_MYSQL_USER,
        password: process.env.MEDISA_TEST_MYSQL_PASSWORD ?? "",
        managed: false
      };
    }
  }

  const port = defaultPort;
  const dsn = buildMysqlDsn(port);
  if (await isPortOpen(port) && tryPdoPing(dsn, defaultUser, defaultPassword)) {
    process.env.MEDISA_TEST_MYSQL_DSN = dsn;
    process.env.MEDISA_TEST_MYSQL_USER = defaultUser;
    process.env.MEDISA_TEST_MYSQL_PASSWORD = defaultPassword;
    return { dsn, user: defaultUser, password: defaultPassword, managed: false };
  }

  const releaseStartupLock = await acquireStartupLock();
  try {
    if (!managedProcess && !(await isPortOpen(port))) {
      startManagedInstance(port);
      await waitForPort(port);
    } else if (!managedProcess) {
      await waitForPort(port);
    }
  } finally {
    releaseStartupLock();
  }

  if (!tryPdoPing(dsn, defaultUser, defaultPassword)) {
    throw new Error("Disposable MariaDB instance did not become ready for PHP PDO connections.");
  }

  process.env.MEDISA_TEST_MYSQL_DSN = dsn;
  process.env.MEDISA_TEST_MYSQL_USER = defaultUser;
  process.env.MEDISA_TEST_MYSQL_PASSWORD = defaultPassword;

  return { dsn, user: defaultUser, password: defaultPassword, managed: true };
}

export async function stopDisposableMariaDb() {
  let pid = managedProcess && !managedProcess.killed ? managedProcess.pid : null;
  if (!pid && existsSync(managedPidFile)) {
    pid = Number.parseInt(readFileSync(managedPidFile, "utf8").trim(), 10);
  }

  managedProcess = null;
  if (Number.isInteger(pid) && pid > 0 && isProcessAlive(pid)) {
    try {
      process.kill(pid);
    } catch {
      // Process may have exited between the alive check and kill.
    }
    const started = Date.now();
    while (isProcessAlive(pid) && Date.now() - started < 2_000) {
      sleepSync(50);
    }
  }
  rmSync(managedPidFile, { force: true });
}

export function runPhpMysqlRunner(runnerPath) {
  const releaseExecutionLock = acquireExecutionLock();
  try {
    return spawnSync("php", [...phpMysqlBootstrapArgs(), runnerPath], {
      encoding: "utf8",
      cwd: repoRoot,
      env: process.env
    });
  } finally {
    releaseExecutionLock();
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  const command = process.argv[2] ?? "ensure";
  if (command === "ensure") {
    ensureDisposableMariaDbEnv()
      .then((env) => {
        process.stdout.write(`${env.dsn}\n`);
      })
      .catch((error) => {
        process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
        process.exit(1);
      });
  } else if (command === "stop") {
    stopDisposableMariaDb().finally(() => process.exit(0));
  } else {
    process.stderr.write(`Unknown command: ${command}\n`);
    process.exit(1);
  }
}
