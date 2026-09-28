import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/HaftalikKapanisMysqlTestRunner.php");

describe("yillik fazla calisma kapsam MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("keeps BIRIM_AMIRI roster aligned with the canonical yearly aggregate", () => {
    const result = runPhpMysqlRunner(runnerPath, ["--kapsam-only"]);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[PASS] BA kapsam includes own birim personel");
    expect(result.stdout).toContain("[PASS] BA kapsam excludes other birim personel");
    expect(result.stdout).toContain("[PASS] BA kapsam excludes other sube personel");
    expect(result.stdout).toContain(
      "[PASS] BA kapsam kullanilan_dakika matches canonical aggregate personel 10"
    );
    expect(result.stdout).toContain(
      "[PASS] BA kapsam kalan_dakika matches canonical aggregate personel 40"
    );
    expect(result.stdout).toContain(
      "[PASS] BA kapsam limit_yaklasiyor_mu matches canonical aggregate personel 40"
    );
    expect(result.stdout).toContain(
      "[PASS] BA kapsam limit_asildi_mi matches canonical aggregate personel 40"
    );
    expect(result.stdout).toContain("[PASS] BA kapsam seeded kullanilan personel 10");
    expect(result.stdout).toContain("verify-yillik-fazla-calisma-kapsam-mysql: OK");
  });
});
