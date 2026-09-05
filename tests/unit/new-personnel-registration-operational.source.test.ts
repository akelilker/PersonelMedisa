import { describe, expect, it } from "vitest";
import {
  filterSgkIsverenOptionsForSube,
  resolveSgkIsverenAfterSubeChange
} from "../../src/features/personeller/personel-create-org-deps";
import { buildCreatePersonelPayload } from "../../src/features/personeller/personel-create-utils";
import { INITIAL_CREATE_PERSONEL_FORM } from "../../src/hooks/usePersoneller";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = resolve(__dirname, "../..");

function read(pathFromRoot: string) {
  return readFileSync(resolve(root, pathFromRoot), "utf8");
}

const sgkOptions = [
  { id: 1, label: "Medisa", sirketId: 1 },
  { id: 2, label: "Karyapı", sirketId: 2 },
  { id: 3, label: "Şenay", sirketId: 1 }
];

const subeOptions = [
  { id: 10, label: "Medisa Ankara", sirketId: 1 },
  { id: 20, label: "Karyapı Merkez", sirketId: 2 },
  { id: 30, label: "Eski Şube", sirketId: null }
];

describe("new personnel registration operational close", () => {
  it("filters SGK employers to the selected şube company and clears invalid selection", () => {
    expect(filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, "")).toEqual([]);
    expect(filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, "10").map((o) => o.id)).toEqual([
      1, 3
    ]);
    expect(filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, "20").map((o) => o.id)).toEqual([
      2
    ]);
    expect(filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, "30")).toEqual([]);

    const medisaSgk = filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, "10");
    expect(resolveSgkIsverenAfterSubeChange("2", medisaSgk)).toBe("");
    expect(resolveSgkIsverenAfterSubeChange("1", medisaSgk)).toBe("1");
  });

  it("create payload carries org + SGK + optional çalışma lokasyonu", () => {
    const payload = buildCreatePersonelPayload({
      ...INITIAL_CREATE_PERSONEL_FORM,
      tcKimlikNo: "12345678901",
      ad: "Seda",
      soyad: "Test",
      dogumTarihi: "1990-01-01",
      telefon: "05321234567",
      acilDurumKisi: "Yakın",
      acilDurumTelefon: "05329876543",
      iseGirisTarihi: "2026-09-05",
      subeId: "10",
      sgkIsverenId: "1",
      calismaLokasyonuId: "2",
      departmanId: "3",
      gorevId: "4",
      personelTipiId: "1",
      bolumId: "5",
      birimId: "6",
      pozisyonId: "7"
    });

    expect(payload).toMatchObject({
      sube_id: 10,
      sgk_isveren_id: 1,
      calisma_lokasyonu_id: 2,
      departman_id: 3,
      gorev_id: 4,
      personel_tipi_id: 1,
      bolum_id: 5,
      birim_id: 6,
      pozisyon_id: 7,
      aktif_durum: "AKTIF",
      calisan_kapsami: "IC_PERSONEL"
    });
  });

  it("denies create for unauthorized roles and allows IK_SORUMLUSU", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "personeller.create")).toBe(true);
    expect(hasRolePermission("IK_PERSONELI", "personeller.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.create")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "personeller.create")).toBe(false);
    expect(hasRolePermission("PERSONEL", "personeller.create")).toBe(false);
  });

  it("keeps account onboarding separate from create and wires SGK sirket_id referans", () => {
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).toContain("createPersonel(buildCreatePersonelPayload");
    expect(workspace).toContain("canManageAccountOnboarding");
    expect(workspace).toContain("personel-create-hesap-onboarding-cta");
    expect(workspace).toContain("sirketId: sube.sirket?.id ?? null");

    const referans = read("api/src/Controllers/ReferansController.php");
    expect(referans).toContain("SELECT id, ad, sirket_id FROM sgk_isverenler");

    const createService = read("api/src/Services/Personel/PersonelCreateService.php");
    expect(createService).toContain("validateCreateReferences");
    expect(createService).toContain("PersonelSgkCompanyConsistency");
  });
});
