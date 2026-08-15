import { spawnSync } from "node:child_process";
import { existsSync, mkdtempSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { pathToFileURL } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

const repoRoot = resolve(process.cwd());
const helperUrl = pathToFileURL(join(repoRoot, "tests/scripts/disposable-mariadb.mjs")).href;
const engineRunner = join(repoRoot, "tests/php/SgkPrimGunuEngineTestRunner.php");
const missingRunner = join(repoRoot, "tests/php/__missing_sgk_runner_for_lock_test__.php");
const isolatedRoots: string[] = [];

function createIsolatedRoot(): string {
  const root = mkdtempSync(join(tmpdir(), "medisa-mariadb-lock-"));
  isolatedRoots.push(root);
  return root;
}

function runIsolatedLockScenario(root: string, source: string) {
  return spawnSync(process.execPath, ["--input-type=module", "--eval", source], {
    cwd: repoRoot,
    encoding: "utf8",
    env: { ...process.env, MEDISA_TEST_MARIADB_ROOT: root }
  });
}

afterEach(() => {
  for (const root of isolatedRoots.splice(0)) {
    rmSync(root, { recursive: true, force: true });
  }
});

describe("disposable MariaDB execution lock recovery", () => {
  it("failed runner releases only its own isolated execution lock", () => {
    const root = createIsolatedRoot();
    const script = `
      import { runPhpMysqlRunner } from ${JSON.stringify(helperUrl)};
      const failed = runPhpMysqlRunner(${JSON.stringify(missingRunner)});
      const recovered = runPhpMysqlRunner(${JSON.stringify(engineRunner)});
      process.stdout.write(JSON.stringify({ failed: failed.status, recovered: recovered.status, stdout: recovered.stdout }));
    `;
    const result = runIsolatedLockScenario(root, script);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    const output = JSON.parse(result.stdout);
    expect(output.failed).not.toBe(0);
    expect(output.recovered).toBe(0);
    expect(output.stdout).toContain("verify-sgk-prim-gunu-engine: OK");
    expect(existsSync(join(root, "execution.lock"))).toBe(false);
  });

  it("dead owner lock is quarantined and recovered without touching the shared test root", () => {
    const root = createIsolatedRoot();
    const executionLockDir = join(root, "execution.lock");
    mkdirSync(executionLockDir, { recursive: true });
    writeFileSync(
      join(executionLockDir, "owner.json"),
      JSON.stringify({ pid: 99999999, token: "dead-owner", runnerPath: "stale", acquiredAt: new Date().toISOString() }),
      "utf8"
    );

    const script = `
      import { runPhpMysqlRunner } from ${JSON.stringify(helperUrl)};
      const recovered = runPhpMysqlRunner(${JSON.stringify(engineRunner)});
      process.stdout.write(JSON.stringify({ status: recovered.status, stdout: recovered.stdout }));
    `;
    const result = runIsolatedLockScenario(root, script);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    const output = JSON.parse(result.stdout);
    expect(output.status).toBe(0);
    expect(output.stdout).toContain("verify-sgk-prim-gunu-engine: OK");
    expect(existsSync(executionLockDir)).toBe(false);
  });
});
