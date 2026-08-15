import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/SerbestZamanAllocationPack4aMysqlTestRunner.php"
);
const migration061 = readFileSync(
  resolve(process.cwd(), "api/migrations/061_serbest_zaman_kullanim_tahsisleri.sql"),
  "utf8"
);
const serviceSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/SerbestZaman/SerbestZamanAllocationService.php"),
  "utf8"
);

describe("SerbestZamanAllocation Pack4A MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("source-locks migration 061, tip, service, policy, no backfill", () => {
    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("067_personel_canonical_reference_gate.sql");
    expect(migrations).toContain("061_serbest_zaman_kullanim_tahsisleri.sql");

    expect(serviceSource).toContain("class SerbestZamanAllocationService");
    expect(serviceSource).toContain("EARLIEST_EXPIRY_FIRST_V1");
    expect(serviceSource).toContain("POLICY_CONSUME");

    expect(migration061).toContain("serbest_zaman_kullanim_tahsisleri");
    expect(migration061).toMatch(/NO DATA BACKFILL/i);
    expect(migration061).not.toMatch(/INSERT\s+INTO\s+serbest_zaman_kullanim_tahsisleri/i);
    expect(migration061).not.toMatch(/INSERT\s+INTO\s+serbest_zaman_events/i);
  });

  it("runs Pack4A allocation acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-serbest-zaman-allocation-pack4a-mysql: OK");
  });
});
