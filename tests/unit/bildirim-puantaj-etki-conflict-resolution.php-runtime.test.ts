import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/BildirimPuantajEtkiConflictResolutionTestRunner.php");
const controllerPath = resolve(process.cwd(), "api/src/Controllers/BildirimPuantajEtkiAdaylariController.php");
const routerPath = resolve(process.cwd(), "api/src/Router.php");
const controllerSource = readFileSync(controllerPath, "utf8");
const routerSource = readFileSync(routerPath, "utf8");

function phpArgs(runner: string): string[] {
  const isWindows = process.platform === "win32";
  if (!isWindows) {
    return [runner];
  }
  const phpPath = execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim();
  return ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runner];
}

describe("BildirimPuantajEtkiConflictResolutionService php runtime", () => {
  it("passes classification, hash, keep, revise and idempotency scenarios", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const result = spawnSync(phpPath, phpArgs(runnerPath), { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr).toBe(0);
    expect(result.stdout).toContain("verify-bildirim-puantaj-etki-conflict-resolution: OK");
    expect(result.stdout).toContain("[PASS] class D resmi surec dayanak");
    expect(result.stdout).toContain("[PASS] keep decision succeeds");
    expect(result.stdout).toContain("[PASS] revise decision succeeds");
    expect(result.stdout).toContain("[PASS] same request is idempotent");
  });
});

describe("S75 conflict resolution controller source contract", () => {
  it("exposes resolveConflict and enriches detail with conflict context", () => {
    expect(controllerSource).toMatch(/public static function resolveConflict\(/);
    expect(controllerSource).toContain("enrichDetailWithConflictContext");
    expect(controllerSource).toContain("current_puantaj_hash");
    expect(controllerSource).toContain("BildirimPuantajEtkiConflictResolutionService::resolve");
    expect(controllerSource).toContain("BildirimPuantajEtkiDecisionPolicy::PERMISSION_RESOLVE_CONFLICT");
  });

  it("acquires period lock before aday and puantaj row locks", () => {
    const start = controllerSource.indexOf("public static function resolveConflict(");
    const end = controllerSource.indexOf("public static function detail(", start);
    const block = controllerSource.slice(start, end);
    expect(block.indexOf("PuantajDonemKilidiService::acquireForDate")).toBeGreaterThan(block.indexOf("$pdo->beginTransaction()"));
    expect(block.indexOf("fetchAdayById($pdo, $adayId, true)")).toBeGreaterThan(block.indexOf("PuantajDonemKilidiService::acquireForDate"));
    expect(block.indexOf("fetchPuantajForUpdate")).toBeGreaterThan(block.indexOf("fetchAdayById($pdo, $adayId, true)"));
  });

  it("registers cakisma-coz before dynamic detail route", () => {
    const cakismaIndex = routerSource.indexOf("#^/puantaj/bildirim-etki-adaylari/(\\d+)/cakisma-coz$#");
    const detailIndex = routerSource.indexOf("#^/puantaj/bildirim-etki-adaylari/(\\d+)$#");
    const uygulaIndex = routerSource.indexOf("#^/puantaj/bildirim-etki-adaylari/(\\d+)/uygula$#");
    expect(cakismaIndex).toBeGreaterThan(uygulaIndex);
    expect(detailIndex).toBeGreaterThan(cakismaIndex);
  });
});
