import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/FinalCodeGapPack5MysqlTestRunner.php"
);
const migration063 = readFileSync(
  resolve(process.cwd(), "api/migrations/063_fazla_calisma_actual_date_provenance.sql"),
  "utf8"
);
const migration064 = readFileSync(
  resolve(process.cwd(), "api/migrations/064_personel_org_location_model.sql"),
  "utf8"
);
const otServiceSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/Payroll/FazlaCalismaYillikLimitService.php"),
  "utf8"
);
const orgSchemaSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/Personel/PersonelOrgLocationSchema.php"),
  "utf8"
);

describe("Final Code Gap Pack5 MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("source-locks migrations 063/064 present, OT policy, org error code", () => {
    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("063_fazla_calisma_actual_date_provenance.sql");
    expect(migrations).toContain("064_personel_org_location_model.sql");
    expect(migrations.at(-1)).toBe("068_sgk_actor_identity_lifecycle_audit.sql");

    expect(otServiceSource).toContain("ROLLING_12_MONTH_ACTUAL_DATE_V1");
    expect(otServiceSource).toContain("ISO/calendar year is NOT the 270h owner");
    expect(otServiceSource).toContain("allocateActualDateProvenance");

    expect(migration063).toContain("fazla_calisma_tarih_dagilimi_json");
    expect(migration063).toContain("ROLLING_12_MONTH_ACTUAL_DATE_V1");
    expect(migration064).toContain("sgk_isverenler");
    expect(migration064).toContain("calisma_lokasyonlari");
    expect(orgSchemaSource).toContain("ORG_LOCATION_SCHEMA_NOT_READY");
  });

  it("runs Pack5 Track A+B acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-final-code-gap-pack5-mysql: OK");
  });
});
