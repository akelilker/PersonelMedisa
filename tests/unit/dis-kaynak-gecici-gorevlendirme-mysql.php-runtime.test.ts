import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("DIS geçici görevlendirme MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("scope/atomiklik/finansal fail-closed acceptance", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/DisKaynakGeciciGorevlendirmeMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-dis-kaynak-gecici-gorevlendirme-mysql: OK");
  });
});
