import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const read = (path: string) => readFileSync(resolve(root, path), "utf8");
const worker = read("api/bin/cpanel-migration-cron.php");
const workflow = read(".github/workflows/ops-full-database-backup.yml");
const apply = read(".github/workflows/apply-cpanel-migrations.yml");

describe("tam veritabanı yedeği kontrol düzlemi", () => {
  it("APPLY yoluna girmeden biter ve beş tabloluk yedeği değiştirmez", () => {
    const block = worker.slice(
      worker.indexOf("if ($mode === 'FULL_DATABASE_BACKUP') {"),
      worker.indexOf("if ($mode === 'READ_ONLY_ORGANIZATION_INVENTORY') {")
    );
    expect(block).toContain("FullDatabaseBackupService::capture");
    expect(block).not.toContain("MigrationExecutionService::apply");
    expect(block).not.toContain("MigrationBackupService::create(");
    expect(worker).toContain("MigrationBackupService::create($pdo, $apiDirectory, $requestId, $backupLabel)");
  });

  it("dökümü düz FTP ile taşımaz", () => {
    expect(workflow).toContain("DUMP_TRANSFER_FORBIDDEN");
    expect(workflow).toContain('mode: "FULL_DATABASE_BACKUP"');
    expect(workflow).not.toMatch(/\b(get|put|mget|mput)\s+[^\n]*\.sql/);
    expect(apply).not.toMatch(/\b(get|put|mget|mput)\s+[^\n]*\.sql/);
    expect(apply).toContain("FULL_BACKUP_TRANSFER_FORBIDDEN");
    expect(apply).toContain("YETKI_SCHEMA_NOT_ABSENT");
    expect(apply).toContain("RESTORE_PRIVILEGE_ABSENT");
    expect(workflow).toContain("restore_privilege");
  });

  it("migration 100 APPLY öncesinde yedeği sunucuda yeniden kontrol eder", () => {
    const backupStage = worker.indexOf("$stage = 'BACKUP';");
    const gate = worker.indexOf("$stage = 'FULL_BACKUP_GATE';");
    const applyStage = worker.indexOf("$stage = 'APPLY';");
    const applyCall = worker.indexOf("MigrationExecutionService::apply");
    expect(backupStage).toBeGreaterThan(0);
    expect(gate).toBeGreaterThan(backupStage);
    expect(applyStage).toBeGreaterThan(gate);
    expect(applyCall).toBeGreaterThan(applyStage);
    const gateBlock = worker.slice(gate, applyStage);
    expect(gateBlock).toContain("in_array('100', $pendingVersions, true)");
    expect(gateBlock).toContain("FullDatabaseBackupService::assertReadyForApply");
    expect(gateBlock).not.toContain("MigrationExecutionService::apply");
    expect(worker).toContain("$stage === 'FULL_BACKUP_GATE'");
  });
});
