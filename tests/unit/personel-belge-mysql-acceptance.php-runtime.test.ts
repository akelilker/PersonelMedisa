import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelBelgeMysqlAcceptanceTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/PersonelBelgelerController.php"),
  "utf8"
);
const repoSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/PersonelBelge/PersonelBelgeKayitRepository.php"),
  "utf8"
);
const storageSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/PersonelBelge/PersonelBelgeStorageService.php"),
  "utf8"
);

describe("S86 personel belge MariaDB PHP acceptance + concurrency", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 120_000);

  it("locks concurrency owners (FOR UPDATE + storage env + tek aktif)", () => {
    expect(repoSource).toContain("FOR UPDATE");
    expect(repoSource).toContain("lockSurecRowForUpdate");
    expect(controllerSource).toContain("lockSurecRowForUpdate");
    expect(storageSource).toContain("MEDISA_PERSONEL_BELGE_STORAGE_ROOT");
    expect(controllerSource).toContain("function replaceDosya");
  });

  it("runs real PHP CRUD + parallel replace on disposable MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-belge-mysql-acceptance: OK");
    expect(result.stdout).toContain("[PASS] belge olustur → 201");
    expect(result.stdout).toContain("[PASS] metadata guncelle → 200");
    expect(result.stdout).toContain("[PASS] dosya degistir → 200");
    expect(result.stdout).toContain("[PASS] eski surum listelenir (2)");
    expect(result.stdout).toContain("[PASS] aktif surum indir → 200");
    expect(result.stdout).toContain("[PASS] iptal → 200");
    expect(result.stdout).toContain("[PASS] iptal sonrasi replace reddi → 409");
    expect(result.stdout).toContain("[PASS] SQL: birden fazla aktif surum yok");
    expect(result.stdout).toContain("[PASS] concurrency sonunda 1 aktif surum");
    expect(result.stdout).toContain("[PASS] orphan dosya yok");
    expect(result.stdout).toContain("[PASS] parallel 500 sızıntı yok");
  });
});
