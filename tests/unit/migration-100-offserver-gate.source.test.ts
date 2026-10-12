import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (p: string) => readFileSync(resolve(process.cwd(), p), "utf8");
const worker = read("api/bin/cpanel-migration-cron.php");
const apply = read(".github/workflows/apply-cpanel-migrations.yml");
const service = read("api/src/Database/FullDatabaseBackupService.php");

describe("migration 100 sunucu dışı geri yükleme kanıtı", () => {
  it("işçi yalnız hedefli 100 isteğinde sunucu dışı kapıyı kullanır, aksi halde eski kapı aynen", () => {
    const gate = worker.slice(worker.indexOf("$stage = 'FULL_BACKUP_GATE';"), worker.indexOf("$stage = 'APPLY';"));
    expect(gate).toContain("if ($targetVersion !== '100')");
    expect(gate).toContain("FullDatabaseBackupService::assertReadyForApplyOffServer(");
    expect(gate).toContain("FullDatabaseBackupService::assertReadyForApply(");
  });

  it("sunucu kapısı dosya, sha, sayım, defter, yaş ve yetki şemasını yeniden doğrular", () => {
    const block = service.slice(service.indexOf("function assertReadyForApplyOffServer"));
    for (const code of [
      "resolveAppBackupForDownload",
      "OFFSERVER_BACKUP_SHA_MISMATCH",
      "OFFSERVER_RESTORE_COUNT_MISMATCH",
      "FULL_BACKUP_LEDGER_MISMATCH",
      "FULL_BACKUP_STALE",
      "YETKI_SCHEMA_NOT_ABSENT",
      "hash_equals"
    ]) {
      expect(block).toContain(code);
    }
  });

  it("iş akışı kanıtı yalnız 100 için kabul eder ve dökümü taşımaz", () => {
    expect(apply).toContain('[[ "$TARGET_MIGRATION" == "100_user_yetki_istisnalari.sql" ]] || block OFFSERVER_EVIDENCE_INVALID');
    expect(apply).toContain("RESTORE_PRIVILEGE_ABSENT");
    expect(apply).not.toMatch(/\b(get|put|mget|mput)\s+[^\n]*\.sql/);
  });
});
