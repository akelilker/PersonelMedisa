import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/OrgStructurePack6MysqlTestRunner.php"
);
const migration065 = readFileSync(
  resolve(process.cwd(), "api/migrations/065_personel_org_structure.sql"),
  "utf8"
);
const orgStructSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/Personel/PersonelOrgStructureSchema.php"),
  "utf8"
);

describe("Org Structure Pack6 MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("source-locks migration 065 tip and ORG_STRUCTURE error code", () => {
    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("068_sgk_actor_identity_lifecycle_audit.sql");
    expect(migrations).toContain("064_personel_org_location_model.sql");

    expect(migration065).toContain("bolumler");
    expect(migration065).toContain("birimler");
    expect(migration065).toContain("pozisyonlar");
    expect(migration065).toContain("sgk_isveren_id");
    expect(migration065).toContain("PACK6_065_BLOCKER");
    expect(migration065).toContain("durum missing with rows");
    expect(migration065).toContain("created_at missing with rows");
    expect(migration065).toContain("wrong semantics");
    expect(migration065).toContain("ADD COLUMN durum");
    expect(migration065).toContain("idx_bolumler_durum");
    expect(migration065).toContain("chk_bolumler_durum");
    expect(migration065).not.toContain("DEFAULT safe even with rows");
    expect(migration065).not.toContain("INSERT INTO");
    expect(migration065).not.toContain("DROP TABLE");
    expect(orgStructSource).toContain("ORG_STRUCTURE_SCHEMA_NOT_READY");
    expect(orgStructSource).toContain("assertHierarchyConsistent");
    expect(orgStructSource).toContain("fk_bolumler_departman");
    expect(orgStructSource).toContain("uq_bolumler_departman_ad");
    expect(orgStructSource).toContain("KEY_COLUMN_USAGE");
    expect(orgStructSource).toContain("clearReadyCache");
  });

  it("runs Pack6 acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-org-structure-pack6-mysql: OK");
  });
});
