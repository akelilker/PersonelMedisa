import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const applyRunner = resolve(process.cwd(), "tests/php/S97BPersonelImportApplyMysqlTestRunner.php");
const concurrencyRunner = resolve(
  process.cwd(),
  "tests/php/S97BPersonelImportApplyConcurrencyMysqlTestRunner.php"
);
const migrationRunner = resolve(process.cwd(), "tests/php/S97B046MigrationMysqlTestRunner.php");
const applyService = readFileSync(
  resolve(process.cwd(), "api/src/Services/Personel/PersonelImportApplyService.php"),
  "utf8"
);
const createService = readFileSync(
  resolve(process.cwd(), "api/src/Services/Personel/PersonelCreateService.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");

describe("S97-B personel import apply MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("wires apply owner without salary writes and shared create insert", () => {
    expect(applyService).toContain("CREATE_ONLY_ALL_OR_NOTHING");
    expect(applyService).toContain("PERSONEL_IMPORT_ONAYLIYORUM");
    expect(applyService).toContain("PersonelCreateService::insertPersonel");
    expect(applyService).not.toContain("PersonelUcretService");
    expect(applyService).not.toContain("createSalaryRecord");
    expect(createService).toContain("INSERT INTO personeller");
    expect(routerSource).toContain("/personeller/import/apply");
  });

  it("applies migration 046 idempotently on MariaDB", () => {
    const result = runPhpMysqlRunner(migrationRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("S97B046MigrationMysqlTestRunner: ALL PASS");
  });

  it("runs personel import apply acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(applyRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s97b-personel-import-apply-mysql: OK");
    expect(result.stdout).toContain("[PASS] two-row dry-run can_apply true");
    expect(result.stdout).toContain("[PASS] deterministic manifest hash on repeat dry-run");
    expect(result.stdout).toContain("[PASS] apply success personel delta +2");
    expect(result.stdout).toContain("[PASS] apply success salary delta 0");
    expect(result.stdout).toContain("[PASS] idempotency idempotent_replay true");
    expect(result.stdout).toContain("[PASS] schema missing SCHEMA_NOT_READY");
    expect(result.stdout).toContain("[PASS] no durable CLAIMED after rollback");
    expect(result.stdout).toContain("[PASS] same key retry after BASARISIZ completes");
    expect(result.stdout).toContain("[PASS] response has no tc_sha256");
  });

  it("runs personel import apply concurrency acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(concurrencyRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s97b-personel-import-apply-concurrency-mysql: OK");
  });
});
