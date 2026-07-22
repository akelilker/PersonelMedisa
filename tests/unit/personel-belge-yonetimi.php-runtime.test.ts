import { execFileSync, spawnSync } from "node:child_process";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelBelgeContractsTestRunner.php");

describe("PersonelBelge contracts php runtime", () => {
  it("covers status, mime, base64, storage and owner wiring", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-belge-contracts: OK");
  });
});

describe("S86 personel belge parity source", () => {
  it("keeps FE/PHP contract and route parity without 039", () => {
    const types = readFileSync(resolve("src/types/personel-belge-kaydi.ts"), "utf8");
    const endpoints = readFileSync(resolve("src/api/endpoints.ts"), "utf8");
    const router = readFileSync(resolve("api/src/Router.php"), "utf8");
    const contracts = readFileSync(
      resolve("api/src/Services/PersonelBelge/PersonelBelgeContracts.php"),
      "utf8"
    );
    const allowlist = readFileSync(resolve("scripts/api-parity-allowlist.json"), "utf8");
    const migrations = readdirSync(resolve("api/migrations")).filter((n) => n.endsWith(".sql")).sort();

    expect(types).toContain("PERSONEL_BELGE_MAX_DECODED_BYTES = 10 * 1024 * 1024");
    expect(types).toContain("PERSONEL_BELGE_EXPIRY_WARNING_DAYS = 30");
    expect(types).toContain("deriveTakipDurumu");
    expect(types).toContain("SURESI_YAKLASIYOR");
    expect(contracts).toContain("MAX_DECODED_BYTES = 10 * 1024 * 1024");
    expect(contracts).toContain("EXPIRY_WARNING_DAYS = 30");

    expect(endpoints).toContain("belgeTakip");
    expect(endpoints).toContain("dosya-degistir");
    expect(endpoints).toContain("/gecmis");
    expect(endpoints).toContain("/indir");
    expect(router).toContain("PersonelBelgelerController::updateKaydi");
    expect(router).toContain("PersonelBelgelerController::belgeTakip");
    expect(router).toContain("PersonelBelgelerController::replaceDosya");

    expect(allowlist).not.toContain("D-BEL-01");
    expect(existsSync(resolve("api/migrations/038_personel_belge_yonetimi.sql"))).toBe(true);
    expect(migrations.some((n) => n.startsWith("039_"))).toBe(false);
    expect(migrations.at(-1)).toBe("038_personel_belge_yonetimi.sql");
    expect(existsSync(resolve("src/features/personeller/pages/BelgeTakipPage.tsx"))).toBe(true);
  });
});
