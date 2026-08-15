import { beforeAll, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/PersonelCanonicalReferenceMigration067MysqlTestRunner.php"
);
const migrationPath = resolve(
  process.cwd(),
  "api/migrations/067_personel_canonical_reference_gate.sql"
);

describe("067 canonical reference migration MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("runs fail-closed, idempotent canonical reference migration", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-canonical-reference-migration-067-mysql: OK");
    expect(result.stdout).toContain("[PASS] 067 first apply PASS");
    expect(result.stdout).toContain("[PASS] 067 reapply idempotent");
    expect(result.stdout).toContain("[PASS] 067 canonical state tolerates personnel usage");
    expect(result.stdout).toContain("[PASS] 067 mixed legacy parent with passive section fails closed");
    expect(result.stdout).toContain("[PASS] 067 canonical parent with active legacy section fails closed");
    expect(result.stdout).toContain("[PASS] 067 legacy personnel usage fails closed");
    expect(result.stdout).toContain("[PASS] 067 legacy personnel usage leaves legacy state unchanged");
    expect(result.stdout).toContain("[PASS] 067 unsafe active child fails closed");
    expect(result.stdout).toContain("[PASS] 067 failed precondition leaves parent unchanged");
    expect(result.stdout).toContain("[PASS] 067 first update affected-row failure");
    expect(result.stdout).toContain("[PASS] 067 first update failure rolls back full transaction");
    expect(result.stdout).toContain("[PASS] 067 second update affected-row failure");
    expect(result.stdout).toContain("[PASS] 067 second update failure rolls back first update");
    expect(result.stdout).toContain("[PASS] 067 canonical readback failure");
    expect(result.stdout).toContain("[PASS] 067 readback failure rolls back full transaction");
    expect(result.stdout).toContain("[PASS] 067 wrong department root fails closed");
    expect(result.stdout).toContain("[PASS] 067 duplicate active Güvenlik fails closed");
  });

  it("asserts exact affected rows on both legacy updates", () => {
    const migration = readFileSync(migrationPath, "utf8");
    expect(migration).toContain("SET @p067_move_affected := ROW_COUNT()");
    expect(migration).toContain("SET @p067_passive_affected := ROW_COUNT()");
    expect(migration).toContain("unexpected birim affected rows");
    expect(migration).toContain("unexpected bolum affected rows");
  });
});
