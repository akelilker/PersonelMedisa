import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

describe("Personel historical exit-date correction source contract", () => {
  it("adds dedicated correction owner without loosening normal exit or backfill overwrite rules", () => {
    const correction = read(
      "api/src/Services/Personel/PersonelHistoricalExitDateCorrectionService.php"
    );
    const backfill = read(
      "api/src/Services/Personel/PersonelHistoricalExitDateBackfillService.php"
    );
    const normalExit = read("api/src/Services/Personel/PersonelIstenAyrilmaService.php");

    expect(correction).toContain("HISTORICAL_EXIT_DATE_CORRECTION");
    expect(correction).toContain("CORRECT_HISTORICAL_ISTEN_AYRILMA");
    expect(correction).toContain("[HISTORICAL_EXIT_DATE_CORRECTION]");
    expect(correction).toContain("ERROR_PREIMAGE_MISMATCH");
    expect(correction).toContain("createPersonelLifecycleManifests");
    expect(correction).toContain("FOR UPDATE");
    expect(correction).toContain("currentBitis");
    expect(correction).toContain("assertSurecPreimageExactOld");
    expect(correction).toContain("ACTION_ALREADY_APPLIED");
    expect(correction).toContain("PersonelHistoricalExitDateCorrectionAuditService::appendInTransaction");
    expect(correction).toContain("Preserve original business aciklama");
    expect(correction).not.toContain("SET aktif_durum");
    expect(correction).not.toContain("aktif_durum = 'AKTIF'");
    // Preserve business aciklama on surec; do not overwrite with audit prefix.
    expect(correction).not.toMatch(/SET baslangic_tarihi[\s\S]*aciklama = :aciklama/);
    // Dry-run checksum must not hash wall-clock audit fields.
    expect(correction).not.toMatch(
      /buildAuditRecord[\s\S]*?return \[[\s\S]*?'created_at'\s*=>\s*RetentionClock::now\(\)/
    );
    expect(correction).not.toMatch(
      /buildAuditRecord[\s\S]*?return \[[\s\S]*?'timestamp'\s*=>\s*RetentionClock::now\(\)/
    );

    expect(backfill).toContain("HISTORICAL_EXIT_BACKFILL_CONFLICT");
    expect(backfill).not.toContain("CORRECT_HISTORICAL_ISTEN_AYRILMA");

    expect(normalExit).toContain("EXIT_PREIMAGE_NOT_AKTIF");
    expect(normalExit).not.toContain("HISTORICAL_EXIT_DATE_CORRECTION");
  });

  it("wires HISTORICAL_EXIT_DATE_CORRECTION through lifecycle bulk dry-run/apply/postcheck", () => {
    const contract = read("api/src/Services/Personel/PersonelLifecycleBulkRowContract.php");
    const dryRun = read("api/src/Services/Personel/PersonelLifecycleBulkDryRunService.php");
    const apply = read("api/src/Services/Personel/PersonelLifecycleBulkApplyService.php");
    const postcheck = read("api/src/Services/Personel/PersonelLifecycleBulkPostcheck.php");

    expect(contract).toContain("OP_HISTORICAL_EXIT_DATE_CORRECTION");
    expect(contract).toContain("'HISTORICAL_EXIT_DATE_CORRECTION'");
    expect(dryRun).toContain("PersonelHistoricalExitDateCorrectionService::plan");
    expect(apply).toContain("PersonelHistoricalExitDateCorrectionService::applyInTransaction");
    expect(apply).toContain("$mutationId !== '' ? $mutationId : null");
    expect(postcheck).toContain("OP_HISTORICAL_EXIT_DATE_CORRECTION");
  });

  it("provides durable append-only audit owner via migration 086", () => {
    const migration = read(
      "api/migrations/086_personel_historical_exit_date_correction_auditleri.sql"
    );
    const audit = read(
      "api/src/Services/Personel/PersonelHistoricalExitDateCorrectionAuditService.php"
    );
    expect(migration).toContain(
      "CREATE TABLE IF NOT EXISTS personel_historical_exit_date_correction_auditleri"
    );
    expect(migration).toContain("trg_phedca_no_update");
    expect(migration).toContain("trg_phedca_no_delete");
    expect(migration).toContain("mutation_id");
    expect(migration).toContain("old_aciklama");
    expect(migration).toContain("PACK086_BLOCKER");
    expect(audit).toContain("appendInTransaction");
    expect(audit).toContain("personel_historical_exit_date_correction_auditleri");
  });

  it("provides dedicated correction ops wrapper defaulting to dry-run and validating bitis", () => {
    const wrapper = read(
      "ops/personnel-lifecycle/historical-exit-date-correction-production-apply.mjs"
    );
    expect(wrapper).toContain("HISTORICAL_EXIT_DATE_CORRECTION");
    expect(wrapper).toContain("PersonelHistoricalExitDateCorrectionService");
    expect(wrapper).toContain("mg-historical-exit-date-correction-202");
    expect(wrapper).toContain("mg-historical-exit-date-correction-208");
    expect(wrapper).toContain("surec_id: CORRECTION_SUREC_IDS[202]");
    expect(wrapper).toContain("isApplyRequested");
    expect(wrapper).toContain('production_mutation: DO_APPLY ? "REQUESTED" : "DRY_RUN_ONLY"');
    expect(wrapper).toContain("evaluateCorrectionPreimage");
    expect(wrapper).toContain("currentSurecBitisDate: s.bitis_tarihi");
  });

  it("aligns backfill wrapper residual gate to ALREADY_APPLIED/CONFLICT domain semantics", () => {
    const gates = read("ops/personnel-lifecycle/lib/historical-exit-backfill-gates.mjs");
    const wrapper = read("ops/personnel-lifecycle/deferred-exit-production-apply.mjs");

    expect(gates).toContain("ALREADY_APPLIED");
    expect(gates).toContain("CONFLICT");
    expect(gates).toContain("CREATE_ELIGIBLE");
    expect(gates).toContain("backfill_gate_pass");
    expect(gates).toContain("evaluateCorrectionPreimage");
    expect(gates).toContain("old_bitis_tarihi_pass");
    expect(gates).toContain("currentSurecBitisDate");
    expect(wrapper).toContain("PREIMAGE_CONFLICT");
    expect(wrapper).toContain("HISTORICAL_EXIT_DATE_CORRECTION");
    expect(wrapper).toContain("backfill_gate_pass");
  });
});
