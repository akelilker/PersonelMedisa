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
    expect(correction).not.toContain("SET aktif_durum");
    expect(correction).not.toContain("aktif_durum = 'AKTIF'");

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
    expect(postcheck).toContain("OP_HISTORICAL_EXIT_DATE_CORRECTION");
  });

  it("provides dedicated correction ops wrapper defaulting to dry-run", () => {
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
  });

  it("aligns backfill wrapper residual gate to ALREADY_APPLIED/CONFLICT domain semantics", () => {
    const gates = read("ops/personnel-lifecycle/lib/historical-exit-backfill-gates.mjs");
    const wrapper = read("ops/personnel-lifecycle/deferred-exit-production-apply.mjs");

    expect(gates).toContain("ALREADY_APPLIED");
    expect(gates).toContain("CONFLICT");
    expect(gates).toContain("CREATE_ELIGIBLE");
    expect(gates).toContain("backfill_gate_pass");
    expect(gates).toContain("evaluateCorrectionPreimage");
    expect(wrapper).toContain("PREIMAGE_CONFLICT");
    expect(wrapper).toContain("HISTORICAL_EXIT_DATE_CORRECTION");
    expect(wrapper).toContain("backfill_gate_pass");
  });
});
