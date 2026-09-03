import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

describe("canonical payroll period-close / snapshot identity", () => {
  const snap = read("api/src/Services/MaasHesaplamaSnapshotService.php");
  const muhur = read("api/migrations/002_puantaj_aylik_muhurleme.sql");
  const snapshotMig = read("api/migrations/020_maas_hesaplama_snapshotlari.sql");
  const reopen = read("api/migrations/044_puantaj_aylik_muhur_revision_reopen.sql");

  it("keeps period-close uniqueness on (sube_id, yil, ay) — not SGK employer", () => {
    expect(snapshotMig).toContain("UNIQUE KEY uq_mhds_aktif_snapshot (sube_id, yil, ay, aktif_snapshot)");
    expect(muhur).toMatch(/sube_id.*yil.*ay/s);
    expect(reopen).toContain("uq_pam_aktif_muhur");
    expect(snap).not.toMatch(/UNIQUE[^\n]*sgk_isveren/);
  });

  it("freezes personel payroll employer from personeller.sgk_isveren_id into snapshot payload", () => {
    expect(snap).toContain("PersonelOrgLocationSchema");
    expect(snap).toContain("p.sgk_isveren_id");
    expect(snap).toContain("'sgk_isveren_id' => $sgkIsverenId");
    expect(snap).toContain("'sirket_id' => $sirketId");
    expect(snap).toMatch(/never inferred from the branch default/);
  });

  it("fail-closes IC personel missing SGK employer at snapshot preflight", () => {
    expect(snap).toContain("BLOCKER_SGK_ISVEREN_MISSING");
    expect(snap).toContain("SGK_ISVEREN_MISSING");
    expect(snap).toMatch(/orgLocationReady && \$sgkIsverenId === null/);
  });

  it("freezes operational branch company + default SGK on snapshot header without re-resolving on detail", () => {
    expect(snap).toMatch(/function fetchSube[\s\S]*?'sirket_id'\s*=>/);
    expect(snap).toMatch(/function fetchSube[\s\S]*?'sgk_isveren_id'\s*=>/);
    expect(snap).toMatch(/function fetchSube[\s\S]*SubeReadModel::findById/);
    const detailStart = snap.indexOf("function getSnapshotDetail");
    const detailEnd = snap.indexOf("function verifySnapshotHash", detailStart);
    const detailBody = snap.slice(detailStart, detailEnd);
    expect(detailBody).not.toContain("fetchSube");
    expect(detailBody).toContain("mapSnapshotRow");
  });

  it("excludes DIS_KAYNAK from payroll personnel set via IC predicate", () => {
    expect(snap).toContain("PersonelCalisanKapsamService::sqlIcPersonelPredicate");
  });

  it("does not invent a second period-close or snapshot engine", () => {
    expect(snap).toContain("class MaasHesaplamaSnapshotService");
    const preflight = read("api/src/Services/DonemKapanisPreflightService.php");
    expect(preflight).toContain("class DonemKapanisPreflightService");
  });
});
