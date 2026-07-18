import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/SureclerDetailUpdateCancelMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/SureclerController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");

describe("SureclerController detail/update/cancel MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks router owners and permission asserts", () => {
    expect(routerSource).toContain("SureclerController::detail");
    expect(routerSource).toContain("SureclerController::update");
    expect(routerSource).toContain("SureclerController::cancel");
    expect(controllerSource).toContain("surecler.detail.view");
    expect(controllerSource).toContain("surecler.update");
    expect(controllerSource).toContain("surecler.cancel");
    expect(controllerSource).not.toContain("DELETE FROM surecler");
    expect(controllerSource).toContain("AND state NOT IN");
  });

  it("runs HTTP detail/update/cancel acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-surecler-detail-update-cancel-mysql: OK");
    expect(result.stdout).toContain("[PASS] HTTP detail → 200");
    expect(result.stdout).toContain("[PASS] HTTP detail missing → 404");
    expect(result.stdout).toContain("[PASS] HTTP BA other sube → 403");
    expect(result.stdout).toContain("[PASS] HTTP BA update → 403");
    expect(result.stdout).toContain("[PASS] HTTP empty update → 422");
    expect(result.stdout).toContain("[PASS] HTTP personel_id change → 422");
    expect(result.stdout).toContain("[PASS] HTTP update → 200");
    expect(result.stdout).toContain("[PASS] HTTP update TAMAMLANDI → 409");
    expect(result.stdout).toContain("[PASS] HTTP cancel → 200");
    expect(result.stdout).toContain("[PASS] cancel is soft (row remains)");
    expect(result.stdout).toContain("[PASS] HTTP cancel idempotent → 200");
    expect(result.stdout).toContain("[PASS] HTTP cancel TAMAMLANDI → 409");
  });
});
