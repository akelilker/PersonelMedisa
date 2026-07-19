import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/GunlukBildirimDuplicateAndCompletionTestRunner.php"
);
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/BildirimlerController.php"),
  "utf8"
);

describe("Gunluk bildirim duplicate + completion MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("controller exposes S81 create/completion guards", () => {
    expect(controllerSource).toContain("Bu personel/tarih/olay için açık bildirim zaten var.");
    expect(controllerSource).toContain("gunlukTamamlamaCreate");
    expect(controllerSource).toContain("gunluk_bildirim.complete_day");
  });

  it("runs duplicate/completion/weekly eksik_gun acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-gunluk-bildirim-duplicate-completion: OK");
    expect(result.stdout).toContain("[PASS] duplicate create → 409");
    expect(result.stdout).toContain("[PASS] completion create ok");
    expect(result.stdout).toContain("[PASS] weekly eksik_gun blocks approve");
  });
});
