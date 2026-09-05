import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";
import {
  PAYROLL_LOCK_EDIT_MESSAGE,
  SCOPED_CORRECTABLE_TURLER,
  canCorrectScopedGunlukBildirim,
  defaultTurFromPerson,
  evidenceLabel,
  formatAuditTransition,
  turNeedsTimeFields,
  turRequiresAciklama
} from "../../src/lib/bildirim/gunluk-bildirim-correct-scoped";
import type { BugunPersonelDurumuPerson } from "../../src/types/bildirim";

const root = resolve(__dirname, "../..");

function read(pathFromRoot: string) {
  return readFileSync(resolve(root, pathFromRoot), "utf8");
}

describe("IK today dashboard scoped correction", () => {
  it("permission matrix: IK/GENEL yes; SISTEM/BIRIM/MUHASEBE/PERSONEL no", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "gunluk_bildirim.correct_scoped")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "gunluk_bildirim.correct_scoped")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "gunluk_bildirim.correct_scoped")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.correct_scoped")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "gunluk_bildirim.correct_scoped")).toBe(false);
    expect(hasRolePermission("PERSONEL", "gunluk_bildirim.correct_scoped")).toBe(false);
    expect(
      canCorrectScopedGunlukBildirim((p) => hasRolePermission("IK_SORUMLUSU", p))
    ).toBe(true);
    expect(
      canCorrectScopedGunlukBildirim((p) => hasRolePermission("BIRIM_AMIRI", p))
    ).toBe(false);
  });

  it("keeps exception-only tur contract and field semantics", () => {
    expect(SCOPED_CORRECTABLE_TURLER).not.toContain("GELDI");
    expect(SCOPED_CORRECTABLE_TURLER).toContain("GELMEDI");
    expect(SCOPED_CORRECTABLE_TURLER).toContain("GEC_GELDI");
    expect(turNeedsTimeFields("GEC_GELDI")).toBe(true);
    expect(turNeedsTimeFields("GELMEDI")).toBe(false);
    expect(turRequiresAciklama("DIGER")).toBe(true);
    expect(PAYROLL_LOCK_EDIT_MESSAGE).toContain("bordro kapanışı");
  });

  it("defaults edit tur from person without forcing GELDI for unassessed", () => {
    const henuz: BugunPersonelDurumuPerson = {
      personel_id: 1,
      ad_soyad: "A",
      durum: "HENUZ_DEGERLENDIRILMEDI",
      durum_label: "Henüz Değerlendirilmedi",
      gec_kalma_dakika: null,
      erken_cikis_dakika: null,
      giris_saati: null,
      cikis_saati: null,
      aciklama: null,
      alt_tur: null,
      detail_line: "Henüz değerlendirilmedi",
      evidence: "UNASSESSED",
      group: "PENDING"
    };
    expect(defaultTurFromPerson(henuz)).toBe("GELMEDI");
    expect(defaultTurFromPerson(henuz)).not.toBe("GELDI" as never);
    expect(evidenceLabel("UNASSESSED")).toContain("Henüz");
    expect(evidenceLabel("ATTENDANCE")).toContain("Attendance");
    expect(evidenceLabel("EXCEPTION")).toContain("Exception");
  });

  it("formats compact audit transition for history UI", () => {
    expect(
      formatAuditTransition({
        id: 1,
        gunluk_bildirim_id: 10,
        personel_id: 5,
        sube_id: 1,
        tarih: "2026-09-05",
        olay_tipi: "DUZELTME",
        actor_user_id: 42,
        eski_bildirim_turu: "GELMEDI",
        yeni_bildirim_turu: "GEC_GELDI",
        created_at: "2026-09-05 09:42:00"
      })
    ).toBe("Gelmedi → Geç Geldi");
  });

  it("reuses canonical create/update owners; no parallel CRUD endpoint", () => {
    const controller = read("api/src/Controllers/BildirimlerController.php");
    const modal = read("src/features/bildirimler/components/BugunPersonelDurumuModal.tsx");
    const router = read("api/src/Router.php");
    expect(controller).toContain("gunluk_bildirim.correct_scoped");
    expect(controller).toContain("assertScopedCorrectableState");
    expect(controller).toContain("assertAny");
    expect(modal).toContain("updateBildirim");
    expect(modal).toContain("createBildirim");
    expect(modal).toContain("fetchBildirimDetail");
    expect(router).not.toContain("bugun-personel-durumu/correct");
    expect(router).not.toContain("gunluk-bildirim-correct");
  });

  it("preserves PR #251 / #254 / #255 completion and QR untouched", () => {
    const header = read("src/lib/bildirim/header-notification-copy.ts");
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    const service = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    const birim = read("api/src/Services/Bildirim/BirimAmiriGunlukDurumService.php");
    expect(header).toContain("formatHeaderGunlukTamamlamaCopy");
    expect(home).toContain("BirimAmiriOperationalHomePage");
    expect(service).not.toContain("qr_attendance");
    expect(service).toContain("HENUZ_DEGERLENDIRILMEDI");
    expect(birim).toContain("BugunPersonelDurumuService::resolvePersonDurum");
  });
});
