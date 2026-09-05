import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

describe("Personel historical exit-date backfill source contract", () => {
  it("adds dedicated PASIF residual owner without loosening normal AKTIF exit gate", () => {
    const backfill = read(
      "api/src/Services/Personel/PersonelHistoricalExitDateBackfillService.php"
    );
    const normalExit = read("api/src/Services/Personel/PersonelIstenAyrilmaService.php");

    expect(backfill).toContain("HISTORICAL_EXIT_BACKFILL_NOT_PASIF");
    expect(backfill).toContain("HISTORICAL_EXIT_BACKFILL_CONFLICT");
    expect(backfill).toContain("CREATE_HISTORICAL_ISTEN_AYRILMA");
    expect(backfill).toContain("[HISTORICAL_EXIT_DATE_BACKFILL]");
    expect(backfill).toContain("$aktifDurum !== 'PASIF'");
    expect(backfill).not.toContain("aktif_durum = 'AKTIF'");
    expect(backfill).not.toContain("SET aktif_durum");

    expect(normalExit).toContain("EXIT_PREIMAGE_NOT_AKTIF");
    expect(normalExit).toContain("$aktifDurum !== 'AKTIF'");
    expect(normalExit).not.toContain("HISTORICAL_EXIT_DATE_BACKFILL");
  });

  it("wires HISTORICAL_EXIT_DATE_BACKFILL through lifecycle bulk dry-run/apply/postcheck", () => {
    const contract = read("api/src/Services/Personel/PersonelLifecycleBulkRowContract.php");
    const dryRun = read("api/src/Services/Personel/PersonelLifecycleBulkDryRunService.php");
    const apply = read("api/src/Services/Personel/PersonelLifecycleBulkApplyService.php");
    const postcheck = read("api/src/Services/Personel/PersonelLifecycleBulkPostcheck.php");

    expect(contract).toContain("OP_HISTORICAL_EXIT_DATE_BACKFILL");
    expect(contract).toContain("'HISTORICAL_EXIT_DATE_BACKFILL'");
    expect(dryRun).toContain("PersonelHistoricalExitDateBackfillService::plan");
    expect(dryRun).toContain("OP_HISTORICAL_EXIT_DATE_BACKFILL");
    expect(apply).toContain("PersonelHistoricalExitDateBackfillService::applyInTransaction");
    expect(apply).toContain("'PersonelHistoricalExitDateBackfillService'");
    expect(postcheck).toContain("OP_HISTORICAL_EXIT_DATE_BACKFILL");
  });

  it("retargets deferred-exit ops wrapper to historical backfill with exact 202/208 dates", () => {
    const wrapper = read("ops/personnel-lifecycle/deferred-exit-production-apply.mjs");

    expect(wrapper).toContain("HISTORICAL_EXIT_DATE_BACKFILL");
    expect(wrapper).toContain("PersonelHistoricalExitDateBackfillService");
    expect(wrapper).toContain("0b6c90b2c4a75532d42482b898a421a3658ea3a5");
    expect(wrapper).toContain("personel_id: 202");
    expect(wrapper).toContain('exit_date: "2025-12-31"');
    expect(wrapper).toContain("personel_id: 208");
    expect(wrapper).toContain('exit_date: "2026-05-25"');
    expect(wrapper).not.toContain("2026-07-30");
    expect(wrapper).not.toContain('operation_type: "PERSONEL_EXIT"');
    expect(wrapper).toContain('process.argv.includes("--apply")');
    expect(wrapper).toContain('production_mutation: DO_APPLY ? "REQUESTED" : "DRY_RUN_ONLY"');
    expect(wrapper).toContain('aktifDurum === "PASIF"');
  });

  it("keeps lifecycle bulk apply behind import.apply authorization gate", () => {
    const controller = read("api/src/Controllers/PersonellerController.php");
    expect(controller).toContain("lifecycleBulkApply");
    expect(controller).toContain("personeller.import.apply");
    expect(controller).toContain("lifecycleBulkDryRun");
    expect(controller).toContain("['personeller.create', 'personeller.update']");
  });
});
