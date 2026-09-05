import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { buildCreateSurecPayload } from "../../src/features/surecler/surec-form-utils";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";
import { evidenceLabel } from "../../src/lib/bildirim/gunluk-bildirim-correct-scoped";
import {
  DEVAMSIZLIK_SUB_CARDS,
  PUANTAJ_SUBDOMAIN_CARDS
} from "../../src/features/kayit/kayit-surec-constants";
import type { SurecFormState } from "../../src/hooks/useSurecler";

function read(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

const baseForm: SurecFormState = {
  personelId: "12",
  surecTuru: "IZIN",
  altTur: "YILLIK_IZIN",
  baslangicTarihi: "2026-09-05",
  bitisTarihi: "2026-09-07",
  ucretliMi: true,
  tamGunMu: null,
  ilkIkiGunFirmaOderMi: null,
  aciklama: "Test"
};

describe("personnel leave/report/absence operational close", () => {
  it("IK can create surec; BIRIM_AMIRI / MUHASEBE / PERSONEL cannot", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "surecler.create")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "surecler.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.create")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "surecler.create")).toBe(false);
    expect(hasRolePermission("PERSONEL", "surecler.create")).toBe(false);
  });

  it("Puantaj tiles split formal surec vs Bugün daily owners", () => {
    const byId = Object.fromEntries(PUANTAJ_SUBDOMAIN_CARDS.map((c) => [c.id, c]));
    expect(byId.izin?.kind).toBe("inline-devamsizlik");
    expect(byId.rapor?.kind).toBe("inline-devamsizlik");
    expect(byId.is_kazasi?.kind).toBe("inline-devamsizlik");
    expect(byId.izinsiz?.kind).toBe("inline-devamsizlik");
    expect(byId.gec?.kind).toBe("bugun-modal");
    expect(byId.erken?.kind).toBe("bugun-modal");
    expect(byId.gorev?.kind).toBe("bugun-modal");
    expect(DEVAMSIZLIK_SUB_CARDS.some((c) => c.id === "izin")).toBe(true);
  });

  it("create payload rejects inverted date range", () => {
    expect(() =>
      buildCreateSurecPayload({
        ...baseForm,
        baslangicTarihi: "2026-09-10",
        bitisTarihi: "2026-09-01"
      })
    ).toThrow(/Bitiş tarihi/);
    const ok = buildCreateSurecPayload(baseForm);
    expect(ok.surec_turu).toBe("IZIN");
    expect(ok.baslangic_tarihi).toBe("2026-09-05");
  });

  it("backend wires period lock, overlap deny, and Bugün surec overlay", () => {
    const surecler = read("api/src/Controllers/SureclerController.php");
    const bugun = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    const panel = read("src/features/kayit/components/KayitSurecPersonelPuantajPanel.tsx");
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");

    expect(surecler).toContain("assertPeriodOpenForOperationalSurec");
    expect(surecler).toContain("assertNoCoveringAbsenceOverlap");
    expect(surecler).toContain("PERIOD_LOCKED");
    expect(surecler).toContain("SUREC_DATE_OVERLAP");
    expect(surecler).toContain("Bitiş tarihi başlangıç tarihinden önce olamaz.");

    expect(bugun).toContain("mapSurecToBugunExceptionTur");
    expect(bugun).toContain("fetchCoveringSurecExceptionTurByPersonel");
    expect(bugun).toContain("RESMI_SUREC");
    expect(bugun).toContain("'IZINLI'");

    expect(panel).toContain("dispatchOpenBugunPersonelDurumu");
    expect(panel).toContain("kayit-surec-puantaj-owner-hint");
    expect(shell).toContain("OPEN_BUGUN_PERSONEL_DURUMU_EVENT");
    expect(workspace).toContain("dispatchRefreshBugunPersonelDurumu");
  });

  it("evidence label covers resmi surec overlay", () => {
    expect(evidenceLabel("RESMI_SUREC")).toContain("Resmi");
  });

  it("create + daily timing owners remain separate and PASIF gate stays", () => {
    const panel = read("src/features/kayit/components/KayitSurecPersonelPuantajPanel.tsx");
    const create = read("src/features/personeller/components/PersonelCreateFields.tsx");
    const timing = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    expect(panel).toContain("Bu personel pasif");
    expect(create).toContain("create-calisma-lokasyonu");
    expect(timing).toContain("09:30");
    expect(timing).toContain("mapSurecToBugunExceptionTur");
  });
});
