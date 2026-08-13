import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  buildPersonelUpdatePayload,
  pickGenelLifecycleFormFields,
  type EditPersonelFormState
} from "../../src/features/personeller/personel-edit-utils";
import type { Personel } from "../../src/types/personel";

function readOwner(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

const baseForm: EditPersonelFormState = {
  ad: "Ali",
  soyad: "Veli",
  telefon: "05551234567",
  departmanId: "2",
  bolumId: "",
  birimId: "",
  gorevId: "3",
  pozisyonId: "",
  bagliAmirId: "4",
  ucretTipiId: "9",
  maasTutari: "99999",
  primKuraliId: "5",
  effectiveDate: "2026-08-01"
};

const personel = {
  id: 11,
  ad: "Ali",
  soyad: "Veli",
  ucret_tipi_id: 1,
  maas_tutari: 30000,
  net_maas_tutari: 30000,
  departman_id: 1,
  gorev_id: 1,
  bagli_amir_id: 1,
  prim_kurali_id: 1
} as Personel;

describe("I10 review fix — Genel ücret owner + payload", () => {
  it("Genel lifecycle pins wage fields from personel (Mali owner)", () => {
    const fields = pickGenelLifecycleFormFields(baseForm, personel);
    expect(fields.ucretTipiId).toBe("1");
    expect(fields.maasTutari).toBe("30000");
    expect(fields.departmanId).toBe("2");
  });

  it("Genel PUT omits ucret_tipi_id and maas fields", () => {
    const payload = buildPersonelUpdatePayload(baseForm, true, { includeWageFields: false });
    expect(payload).not.toHaveProperty("ucret_tipi_id");
    expect(payload).not.toHaveProperty("maas_tutari");
    expect(payload).not.toHaveProperty("net_maas_tutari");
    expect(payload.departman_id).toBe(2);
    expect(payload.prim_kurali_id).toBe(5);
  });

  it("PersonelInlineEditForm has no ücret tipi write control", () => {
    const source = readOwner(
      "src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx"
    );
    expect(source).not.toContain('name="edit-ucret-tipi-id"');
    expect(source).not.toContain("canManageUcret");
    expect(source).toContain("personel-edit-ucret-yonlendirme");
  });

  it("Genel panel locks person context and guards stale PUT responses", () => {
    const genel = readOwner("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx");
    const workspace = readOwner("src/features/kayit/components/KayitSurecWorkspace.tsx");

    expect(genel).toContain("onBusyChange");
    expect(genel).toContain("includeWageFields: false");
    expect(genel).toContain("personelIdRef.current !== requestPersonelId");
    expect(genel).not.toContain("canManageUcret");

    expect(workspace).toContain("genelMutating");
    expect(workspace).toMatch(/personelContextLocked\s*=\s*[\s\S]*genelMutating/);
    expect(workspace).toContain("onBusyChange={setGenelMutating}");
  });

  it("Bordro Kapsam no-view skips personel detail fetch", () => {
    const source = readOwner("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx");
    expect(source).toContain("if (!canViewBordroKapsam)");
    expect(source).toContain("activeTab !== \"personel-kapsam\" || !canViewBordroKapsam");
    expect(source).toContain("[canViewBordroKapsam]");
  });
});
