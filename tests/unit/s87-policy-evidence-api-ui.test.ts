import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
  evidenceStatusLabel,
  normalizePolitikaEvidenceInput
} from "../../src/api/sirket-calisma-politikasi.api";

describe("S87 policy evidence API/UI contract", () => {
  it("normalizes and validates evidence inputs", () => {
    expect(normalizePolitikaEvidenceInput("", "").ok).toBe(true);
    expect(normalizePolitikaEvidenceInput("ONLY", "").ok).toBe(false);
    expect(normalizePolitikaEvidenceInput("", "abcd").ok).toBe(false);
    expect(normalizePolitikaEvidenceInput("SYNTH-A", "TBD").ok).toBe(false);
    expect(normalizePolitikaEvidenceInput("SYNTH-A", "0".repeat(64)).ok).toBe(false);

    const upper = "A".repeat(64);
    const ok = normalizePolitikaEvidenceInput(" SYNTH-FORM91-X ", upper);
    expect(ok.ok).toBe(true);
    expect(ok.belge_id).toBe("SYNTH-FORM91-X");
    expect(ok.belge_sha256).toBe("a".repeat(64));
  });

  it("renders legacy and valid evidence labels", () => {
    expect(evidenceStatusLabel("LEGACY_MISSING")).toContain("Tarihsel kayıt");
    expect(evidenceStatusLabel("PRESENT_VALID")).toContain("geçerli");
    expect(evidenceStatusLabel("MISSING")).toContain("eksik");
    expect(evidenceStatusLabel("INVALID")).toContain("geçersiz");
  });

  it("API types and payloads carry evidence fields", () => {
    const api = readFileSync(resolve(process.cwd(), "src/api/sirket-calisma-politikasi.api.ts"), "utf8");
    expect(api).toContain("belge_id: string | null");
    expect(api).toContain("belge_sha256: string | null");
    expect(api).toContain("SirketPolitikaEvidenceStatus");
    expect(api).toContain("evidence_status: SirketPolitikaEvidenceStatus");
    expect(api).toContain("evidence_ready_for_approval: boolean");
    expect(api).toContain("belge_id?: string | null");
    expect(api).toContain("normalizePolitikaEvidenceInput");
  });

  it("BordroHazirlik UI wires evidence form, submit gate, and approval dialog", () => {
    const page = readFileSync(
      resolve(process.cwd(), "src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx"),
      "utf8"
    );
    expect(page).toContain("bordro-politika-belge-id");
    expect(page).toContain("bordro-politika-belge-sha256");
    expect(page).toContain("bordro-politika-evidence-help");
    expect(page).toContain("normalizePolitikaEvidenceInput");
    expect(page).toContain("evidenceStatusLabel");
    expect(page).toContain("bordro-politika-karar-belge-id");
    expect(page).toContain("bordro-politika-self-approval-warning");
    expect(page).toContain("disabled={!evidenceReady}");
    expect(page).toContain("Hazırlayan kullanıcı");
    expect(page).not.toContain("type=\"file\"");
    expect(page).toContain("belge_id: evidence.belge_id");
    expect(page).toContain("belge_sha256: evidence.belge_sha256");
  });
});
