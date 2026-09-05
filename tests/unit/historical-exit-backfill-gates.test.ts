import { describe, expect, it } from "vitest";
import {
  EXPECTED_FULL_NAMES,
  evaluateResidualPreimage,
  evaluateShaPreflight,
  isApplyRequested,
  matchesExactFullName,
  parseExpectedSha,
} from "../../ops/personnel-lifecycle/lib/historical-exit-backfill-gates.mjs";

const SHA = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
const SHA_OTHER = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";

describe("historical exit backfill gates — identity", () => {
  it("passes exact 202 and 208 full names", () => {
    expect(matchesExactFullName(202, "Ahmed Khalil Alsamar")).toBe(true);
    expect(matchesExactFullName(208, "Sefine Ozcan")).toBe(true);
    expect(EXPECTED_FULL_NAMES[202]).toBe("AHMED KHALIL ALSAMAR");
    expect(EXPECTED_FULL_NAMES[208]).toBe("SEFINE OZCAN");
  });

  it("fails partial / generic / wrong full-name identity checks", () => {
    expect(matchesExactFullName(202, "AHMED")).toBe(false);
    expect(matchesExactFullName(202, "AHMED YILMAZ")).toBe(false);
    expect(matchesExactFullName(208, "SEFINE")).toBe(false);
    expect(matchesExactFullName(208, "SEFINE YILMAZ")).toBe(false);
    expect(matchesExactFullName(202, "SEFINE OZCAN")).toBe(false);
    expect(matchesExactFullName(208, "AHMED KHALIL ALSAMAR")).toBe(false);
  });

  it("requires exact id + exact name + PASIF + empty exit preimage", () => {
    const pass = evaluateResidualPreimage({
      personelId: 202,
      ad: "Ahmed Khalil",
      soyad: "Alsamar",
      aktifDurum: "PASIF",
      istenCikisTarihi: null,
      exitSurecCount: 0,
    });
    expect(pass.residual_preimage_pass).toBe(true);

    const wrongName = evaluateResidualPreimage({
      personelId: 202,
      ad: "Ahmed",
      soyad: "Yilmaz",
      aktifDurum: "PASIF",
      istenCikisTarihi: null,
      exitSurecCount: 0,
    });
    expect(wrongName.residual_preimage_pass).toBe(false);
    expect(wrongName.name_exact_pass).toBe(false);

    const aktif = evaluateResidualPreimage({
      personelId: 202,
      ad: "Ahmed Khalil",
      soyad: "Alsamar",
      aktifDurum: "AKTIF",
      istenCikisTarihi: null,
      exitSurecCount: 0,
    });
    expect(aktif.residual_preimage_pass).toBe(false);
    expect(aktif.pasif_pass).toBe(false);

    const hasExit = evaluateResidualPreimage({
      personelId: 208,
      ad: "Sefine",
      soyad: "Ozcan",
      aktifDurum: "PASIF",
      istenCikisTarihi: "2026-05-25",
      exitSurecCount: 0,
    });
    expect(hasExit.residual_preimage_pass).toBe(false);
    expect(hasExit.exit_date_empty_pass).toBe(false);

    const hasSurec = evaluateResidualPreimage({
      personelId: 208,
      ad: "Sefine",
      soyad: "Ozcan",
      aktifDurum: "PASIF",
      istenCikisTarihi: null,
      exitSurecCount: 1,
    });
    expect(hasSurec.residual_preimage_pass).toBe(false);
  });
});

describe("historical exit backfill gates — runtime SHA", () => {
  it("fails when expected SHA is missing or invalid", () => {
    expect(() => parseExpectedSha([])).toThrow(/required/i);
    try {
      parseExpectedSha([]);
    } catch (e) {
      expect((e as { code?: string }).code).toBe("EXPECTED_SHA_MISSING");
    }
    try {
      parseExpectedSha(["--expected-sha=abc"]);
    } catch (e) {
      expect((e as { code?: string }).code).toBe("EXPECTED_SHA_INVALID");
    }
    expect(parseExpectedSha([`--expected-sha=${SHA}`])).toBe(SHA);
    expect(parseExpectedSha(["--expected-sha", SHA])).toBe(SHA);
  });

  it("fails origin/live/heartbeat mismatches and passes exact SHA pin", () => {
    expect(
      evaluateShaPreflight({
        expectedSha: SHA,
        originMain: SHA_OTHER,
        liveDeploySha: SHA,
        workerHeartbeatSha: SHA,
      }).origin_main_pass
    ).toBe(false);
    expect(
      evaluateShaPreflight({
        expectedSha: SHA,
        originMain: SHA,
        liveDeploySha: SHA_OTHER,
        workerHeartbeatSha: SHA,
      }).live_deploy_sha_pass
    ).toBe(false);
    expect(
      evaluateShaPreflight({
        expectedSha: SHA,
        originMain: SHA,
        liveDeploySha: SHA,
        workerHeartbeatSha: SHA_OTHER,
      }).worker_heartbeat_pass
    ).toBe(false);
    expect(
      evaluateShaPreflight({
        expectedSha: SHA,
        originMain: SHA,
        liveDeploySha: SHA,
        workerHeartbeatSha: SHA,
      }).all_sha_pass
    ).toBe(true);
  });

  it("defaults to dry-run and requires explicit --apply for mutation", () => {
    expect(isApplyRequested(["node", "script.mjs"])).toBe(false);
    expect(isApplyRequested(["node", "script.mjs", `--expected-sha=${SHA}`])).toBe(false);
    expect(isApplyRequested(["node", "script.mjs", `--expected-sha=${SHA}`, "--apply"])).toBe(
      true
    );
  });
});
