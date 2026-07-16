import { spawn, spawnSync } from "node:child_process";
import { existsSync, mkdirSync, rmSync } from "node:fs";
import { createConnection } from "node:net";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(__dirname, "../..");
const dataRoot = join(repoRoot, ".test-mariadb");
const dataDir = join(dataRoot, "data");
const defaultPort = Number.parseInt(process.env.MEDISA_TEST_MYSQL_PORT ?? "3307", 10);
const defaultUser = process.env.MEDISA_TEST_MYSQL_USER ?? "root";
const defaultPassword = process.env.MEDISA_TEST_MYSQL_PASSWORD ?? "";

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

function phpMysqlBootstrapArgs() {
  const args = [];
  if (process.platform === "win32") {
    const extensionDirResult = spawnSync("php", ["-r", "echo ini_get('extension_dir');"], { encoding: "utf8" });
    const extensionDir = extensionDirResult.stdout.trim();
    if (extensionDir) {
      args.push("-d", `extension_dir=${extensionDir}`);
    }
  }
  args.push("-d", "extension=pdo_mysql");
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
  const result = spawnSync("php", [...phpMysqlBootstrapArgs(), "-r", script], { encoding: "utf8" });
  return result.status === 0 && result.stdout.trim() === "OK";
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
    { stdio: "ignore", windowsHide: true }
  );
  managedProcess.unref();

  managedProcess.on("exit", () => {
    managedProcess = null;
  });
}

export function buildMysqlDsn(port) {
  return `mysql:host=127.0.0.1;port=${port};dbname=mysql;charset=utf8mb4`;
}

export async function ensureDisposableMariaDbEnv() {
  if (process.env.MEDISA_TEST_MYSQL_DSN && process.env.MEDISA_TEST_MYSQL_USER) {
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

  if (!managedProcess && !(await isPortOpen(port))) {
    startManagedInstance(port);
    await waitForPort(port);
  } else if (!managedProcess) {
    await waitForPort(port);
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
  if (!managedProcess || managedProcess.killed) {
    managedProcess = null;
    return;
  }

  const proc = managedProcess;
  managedProcess = null;
  proc.kill();
  await new Promise((resolvePromise) => {
    proc.once("exit", () => resolvePromise(undefined));
    setTimeout(() => resolvePromise(undefined), 2_000);
  });
}

export function runPhpMysqlRunner(runnerPath) {
  return spawnSync("php", [...phpMysqlBootstrapArgs(), runnerPath], {
    encoding: "utf8",
    cwd: repoRoot,
    env: process.env
  });
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
