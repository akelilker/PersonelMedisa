import { describe, expect, it } from "vitest";
import {
  filterSgkIsverenOptionsForSube,
  filterStatuOptionsForCreate,
  mapCalismaLokasyonuDisplayOptions,
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

  it("never auto-selects an SGK employer and keeps stale selection closed", () => {
    const medisaSgk = filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, "10");
    expect(medisaSgk.length).toBeGreaterThan(0);
    expect(resolveSgkIsverenAfterSubeChange("", medisaSgk)).toBe("");
  });

  it("filters Statü to Mavi/Beyaz Yaka without hardcoding backend ids", () => {
    const catalog = [
      { id: 11, label: "Mavi Yaka" },
      { id: 22, label: "Beyaz Yaka" },
      { id: 33, label: "Diğer" },
      { id: 44, label: "Sözleşmeli" },
      { id: 55, label: "Tam Zamanlı" }
    ];
    expect(filterStatuOptionsForCreate(catalog).map((option) => option.label)).toEqual([
      "Mavi Yaka",
      "Beyaz Yaka"
    ]);
  });

  it("uses the canonical short branch name owner for the Şube picker", () => {
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).toContain("label: sube.ad,");
    expect(workspace).toContain("sirketId: sube.sirket?.id ?? null");
    expect(workspace).not.toContain("label: sube.tam_ad");
  });

  it("keeps the empty date input on the canonical muted placeholder tone", () => {
    const css = read("src/styles/modules/kayit-surec.css");
    expect(css).toContain(
      '.kayit-workspace-grid--personel-form input[type="date"].form-input:invalid'
    );
    expect(css).toContain(
      '.kayit-workspace-grid--personel-form input[type="date"].form-input:invalid::-webkit-datetime-edit'
    );
    expect(css).toContain("color: var(--text-muted)");
    expect(css).not.toContain("rgba(230, 234, 245, 0.5)");
    expect(css).toContain("::-webkit-calendar-picker-indicator");
  });

  it("keeps the create form free of a separate Çalışma Tipi picker and sicil note", () => {
    const createFields = read("src/features/personeller/components/PersonelCreateFields.tsx");
    expect(createFields).not.toContain("Çalışma Tipi");
    expect(createFields).toContain('label="Statü"');
    expect(createFields).not.toContain("Sicil numarası kayıt sırasında otomatik atanacaktır.");
    expect(createFields).toContain("statuOptions");
    expect(createFields).toContain("calismaLokasyonuDisplayOptions");
  });

  it("maps work-location labels to city names without touching codes", () => {
    expect(
      mapCalismaLokasyonuDisplayOptions([
        { id: 1, label: "ANKARA — Ankara" },
        { id: 2, label: "İzmir" }
      ]).map((option) => option.label)
    ).toEqual(["Ankara", "İzmir"]);
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
    expect(referans).toContain("SELECT id, kod, ad, sirket_id FROM sgk_isverenler");

    const createService = read("api/src/Services/Personel/PersonelCreateService.php");
    expect(createService).toContain("validateCreateReferences");
    expect(createService).toContain("PersonelSgkCompanyConsistency");
  });
});
