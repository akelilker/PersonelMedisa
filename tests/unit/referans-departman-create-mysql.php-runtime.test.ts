import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/ReferansDepartmanCreateMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/ReferansController.php"),
  "utf8"
);
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/025_departmanlar_ad_unique.sql"),
  "utf8"
);

describe("ReferansController createDepartman MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks unique migration and removes SELECT-all normalize scan", () => {
    expect(migrationSource).toContain("uq_departmanlar_ad");
    expect(migrationSource).toContain("ADD UNIQUE KEY");
    expect(controllerSource).toContain("WHERE ad = :ad");
    expect(controllerSource).not.toContain("SELECT id, ad FROM departmanlar");
    expect(controllerSource).not.toContain("normalizeDepartmanAdForCompare");
    expect(controllerSource).toMatch(/!is_string\(\$body\['ad'\]\)/);
    expect(controllerSource).not.toContain("is_numeric($body['ad'])");
  });

  it("runs HTTP/persistence/duplicate/concurrency acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-referans-departman-create-mysql: OK");
    expect(result.stdout).toContain("[PASS] HTTP authorized create → 201");
    expect(result.stdout).toContain("[PASS] HTTP duplicate → 409");
    expect(result.stdout).toContain("[PASS] HTTP numeric → 400");
    expect(result.stdout).toContain("[PASS] HTTP BIRIM_AMIRI → 403");
    expect(result.stdout).toContain("[PASS] HTTP MUHASEBE → 403");
    expect(result.stdout).toContain("[PASS] HTTP IK → 403");
    expect(result.stdout).toContain("[PASS] HTTP unauthenticated → 401");
    expect(result.stdout).toContain("[PASS] parallel create: one OK one DEPARTMAN_ZATEN_VAR");
    expect(result.stdout).toContain("[PASS] parallel create leaves single DB row");
  });
});
