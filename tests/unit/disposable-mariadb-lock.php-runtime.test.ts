import { existsSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { afterEach, describe, expect, it } from "vitest";
import { runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const repoRoot = resolve(process.cwd());
const executionLockDir = join(repoRoot, ".test-mariadb", "execution.lock");
const executionLockOwner = join(executionLockDir, "owner.pid");
const engineRunner = join(repoRoot, "tests/php/SgkPrimGunuEngineTestRunner.php");
const missingRunner = join(repoRoot, "tests/php/__missing_sgk_runner_for_lock_test__.php");

function clearExecutionLock() {
  rmSync(executionLockDir, { recursive: true, force: true });
}

describe("disposable MariaDB execution lock recovery", () => {
  afterEach(() => {
    clearExecutionLock();
  });

  it("başarısız runner sonrası execution lock serbest kalır", () => {
    clearExecutionLock();
    const failed = runPhpMysqlRunner(missingRunner);
    expect(failed.status).not.toBe(0);
    expect(existsSync(executionLockDir)).toBe(false);

    const recovered = runPhpMysqlRunner(engineRunner);
    expect(recovered.status).toBe(0);
    expect(String(recovered.stdout)).toContain("verify-sgk-prim-gunu-engine: OK");
    expect(existsSync(executionLockDir)).toBe(false);
  });

  it("ölü PID sahipli stale lock bounded recovery ile çalınır", () => {
    clearExecutionLock();
    mkdirSync(executionLockDir, { recursive: true });
    writeFileSync(executionLockOwner, "99999999", "utf8");

    const recovered = runPhpMysqlRunner(engineRunner);
    expect(recovered.status).toBe(0);
    expect(String(recovered.stdout)).toContain("verify-sgk-prim-gunu-engine: OK");
    expect(existsSync(executionLockDir)).toBe(false);
  });
});
