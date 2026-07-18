import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/ZimmetlerCreateListMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/ZimmetlerController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/026_zimmetler.sql"),
  "utf8"
);

describe("ZimmetlerController create/list MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks router owners, permissions and additive migration", () => {
    expect(routerSource).toContain("ZimmetlerController::list");
    expect(routerSource).toContain("ZimmetlerController::create");
    expect(controllerSource).toContain("personeller.detail.view");
    expect(controllerSource).toContain("personeller.update");
    expect(controllerSource).toContain("JsonResponse::success(self::mapZimmetRow($row), [], 201)");
    expect(controllerSource).toContain("SubeScope::assertPersonelAccess");
    expect(controllerSource).toMatch(/!is_string\(\$body\[\$field\]\)/);
    expect(migrationSource).toContain("CREATE TABLE IF NOT EXISTS zimmetler");
    expect(migrationSource).not.toMatch(/\bDROP\b/);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*UPDATE\b/im);
    expect(migrationSource).toContain("ON DELETE CASCADE");
    expect(migrationSource).toContain("ON UPDATE CURRENT_TIMESTAMP");

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("026_zimmetler.sql");
  });

  it("runs HTTP create/list acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-zimmetler-create-list-mysql: OK");
    expect(result.stdout).toContain("[PASS] HTTP authorized create → 201");
    expect(result.stdout).toContain("[PASS] HTTP list → 200");
    expect(result.stdout).toContain("[PASS] duplicate same product allowed → 201");
    expect(result.stdout).toContain("[PASS] empty urun_turu → 422");
    expect(result.stdout).toContain("[PASS] numeric urun_turu → 422");
    expect(result.stdout).toContain("[PASS] BA create → 403 (no personeller.update)");
    expect(result.stdout).toContain("[PASS] BA other sube → 403");
    expect(result.stdout).toContain("[PASS] unauthenticated → 401");
    expect(result.stdout).toContain("[PASS] server owns zimmet_durumu");
  });
});
