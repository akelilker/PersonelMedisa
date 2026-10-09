import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const backupOwner = readFileSync(
  resolve(process.cwd(), "api/src/Database/MigrationBackupService.php"),
  "utf8",
);
const worker = readFileSync(resolve(process.cwd(), "api/bin/cpanel-migration-cron.php"), "utf8");

describe("kalıcı sil 099 backup owner", () => {
  it("backs up users, the ten FK tables and the ledger for the 099 rollback scope", () => {
    for (const table of [
      "'users'",
      "'ek_odeme_kesinti'",
      "'gunluk_bildirimler'",
      "'legal_holdlar'",
      "'legal_hold_auditleri'",
      "'offline_mutation_idempotency'",
      "'personel_gecici_gorevlendirmeler'",
      "'personel_import_runs'",
      "'personel_test_fixture_archive_kayitlari'",
      "'personel_test_fixture_siniflandirmalari'",
      "'retention_imha_auditleri'",
      "'medisa_schema_migrations'",
    ]) {
      expect(backupOwner).toContain(table);
    }
    expect(backupOwner).toContain('KALICI_SIL_BACKED_UP_TABLES');
    expect(backupOwner).toContain('KALICI_SIL_SCHEMA_ONLY_TABLES');
    expect(backupOwner).toContain('createForKaliciSil');
  });

  it("captures the ten FK tables schema-only so 099 does not copy unneeded rows", () => {
    // users and the ledger are the only tables whose rows 099 mutates; the ten
    // FK tables are schema-only and must never appear in the schema-only list
    // beside users or the ledger.
    const schemaOnlyBlock = backupOwner.slice(
      backupOwner.indexOf('KALICI_SIL_SCHEMA_ONLY_TABLES'),
      backupOwner.indexOf('private const DIRECTORY_NAME'),
    );
    for (const table of [
      "'ek_odeme_kesinti'",
      "'gunluk_bildirimler'",
      "'legal_holdlar'",
      "'legal_hold_auditleri'",
      "'offline_mutation_idempotency'",
      "'personel_gecici_gorevlendirmeler'",
      "'personel_import_runs'",
      "'personel_test_fixture_archive_kayitlari'",
      "'personel_test_fixture_siniflandirmalari'",
      "'retention_imha_auditleri'",
    ]) {
      expect(schemaOnlyBlock).toContain(table);
    }
    expect(schemaOnlyBlock).not.toContain("'users'");
    expect(schemaOnlyBlock).not.toContain("'medisa_schema_migrations'");
    // The dump must mark schema-only tables and verify them distinctly.
    expect(backupOwner).toContain('-- schema-only(');
    expect(backupOwner).toContain("'schema_only_tables' =>");
  });

  it("never drops a schema-only table, so a restore cannot lose its rows", () => {
    // The 099 FK rollback is metadata-only: it drops fk_p099_* constraints, and
    // the readback guard rejects any dump that would DROP a schema-only table.
    expect(backupOwner).toContain('KALICI_SIL_FK_CONSTRAINTS');
    expect(backupOwner).toContain('DROP FOREIGN KEY');
    expect(backupOwner).toContain('rows preserved on restore');
    expect(backupOwner).toContain('-- 099 FK rollback (metadata only');
    // The readback data-loss guard must reject a schema-only table drop.
    expect(backupOwner).toContain(
      "strpos($written, 'DROP TABLE IF EXISTS `' . $table . '`') !== false",
    );
    expect(backupOwner).toContain('BACKUP_READBACK_INCOMPLETE');
  });

  it("wires the 099 apply mode to the dedicated backup path instead of the 079 scope", () => {
    // The 099 apply stage must call the dedicated owner, never the default
    // create() whose table list stops at the organisation mapping tables.
    const backupStage = worker.slice(
      worker.indexOf("$stage = 'KALICI_SIL_MIGRATION_BACKUP';"),
      worker.indexOf("$stage = 'KALICI_SIL_MIGRATION_APPLY';"),
    );
    expect(backupStage).toContain('MigrationBackupService::createForKaliciSil(');
    expect(backupStage).not.toContain('MigrationBackupService::create(');
  });
});
