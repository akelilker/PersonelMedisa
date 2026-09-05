import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  buildPersonelUpdatePayload,
  pickGenelLifecycleFormFields,
  type EditPersonelFormState
} from "../../src/features/personeller/personel-edit-utils";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";
import type { Personel } from "../../src/types/personel";

function read(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

const baseForm: EditPersonelFormState = {
  calisanKapsami: "IC_PERSONEL",
  tcKimlikNo: "10000000146",
  ad: "Seda",
  soyad: "Test",
  dogumTarihi: "1990-01-01",
  telefon: "05551234567",
  sicilNo: "SIC-100",
  iseGirisTarihi: "2024-01-01",
  departmanId: "99",
  bolumId: "88",
  birimId: "77",
  gorevId: "66",
  pozisyonId: "55",
  bagliAmirId: "44",
  ucretTipiId: "2",
  maasTutari: "50000",
  primKuraliId: "1",
  effectiveDate: "2026-09-05"
};

const personel = {
  id: 42,
  ad: "Seda",
  soyad: "Test",
  tc_kimlik_no: "10000000146",
  sicil_no: "SIC-100",
  ise_giris_tarihi: "2024-01-01",
  ucret_tipi_id: 1,
  maas_tutari: 30000,
  net_maas_tutari: 30000,
  departman_id: 1,
  bolum_id: 2,
  birim_id: 3,
  gorev_id: 4,
  pozisyon_id: 5,
  bagli_amir_id: 6,
  personel_tipi_id: 7,
  prim_kurali_id: 1,
  aktif_durum: "AKTIF"
} as Personel;

const PROTECTED_ORG_KEYS = [
  "sube_id",
  "departman_id",
  "bolum_id",
  "birim_id",
  "gorev_id",
  "pozisyon_id",
  "sgk_isveren_id",
  "calisma_lokasyonu_id",
  "bagli_amir_id",
  "personel_tipi_id"
] as const;

describe("existing personnel general edit operational close", () => {
  it("IK has personeller.update; BIRIM_AMIRI / MUHASEBE / PERSONEL do not", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "personeller.update")).toBe(true);
    expect(hasRolePermission("IK_PERSONELI", "personeller.update")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.update")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "personeller.update")).toBe(false);
    expect(hasRolePermission("PERSONEL", "personeller.update")).toBe(false);
  });

  it("Genel PUT payload omits protected org / work-info fields", () => {
    const payload = buildPersonelUpdatePayload(baseForm, true, {
      includeWageFields: false,
      includeBagliAmir: false,
      currentPersonel: personel
    });
    for (const key of PROTECTED_ORG_KEYS) {
      expect(payload).not.toHaveProperty(key);
    }
    expect(payload.ad).toBeTruthy();
    expect(payload.calisan_kapsami).toBe("IC_PERSONEL");
    expect(payload).not.toHaveProperty("sicil_no");
    expect(payload.prim_kurali_id).toBe(1);
  });

  it("Genel lifecycle pins org from personel so form org edits cannot drift PUT", () => {
    const fields = pickGenelLifecycleFormFields(baseForm, personel);
    expect(fields.departmanId).toBe("1");
    expect(fields.gorevId).toBe("4");
    expect(fields.bagliAmirId).toBe("6");
    expect(fields.primKuraliId).toBe("1");
  });

  it("Genel UI is PASIF read-only, strips dead amir handlers, keeps org readonly summary", () => {
    const genel = read("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx");
    const inline = read("src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx");

    expect(genel).toContain("canUpdatePersonel && !isPasif");
    expect(genel).toContain("kayit-surec-personel-genel-pasif-hint");
    expect(genel).toContain("kayit-surec-personel-genel-org-readonly");
    expect(genel).toContain("includeBagliAmir: false");
    expect(genel).toContain("includeWageFields: false");
    expect(genel).toContain("deleteCacheEntry(dataCacheKeys.personelDetail");
    expect(genel).toContain("personelIdRef.current !== requestPersonelId");
    expect(genel).not.toContain("handleEditBagliAmirChange");
    expect(genel).not.toContain("handleEditDepartmanChange");
    expect(genel).not.toContain("fetchPersonelDetail");

    expect(inline).toContain("personel-edit-org-yonlendirme");
    expect(inline).not.toContain("handleEditBagliAmirChange");
    expect(inline).not.toContain('name="edit-departman"');
    expect(inline).not.toContain('name="edit-bagli-amir"');
  });

  it("backend strips protected org fields after generic PUT change deny", () => {
    const org = read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php");
    const controller = read("api/src/Controllers/PersonellerController.php");
    const basic = read("api/src/Services/Personel/PersonelBasicUpdateService.php");

    expect(org).toContain("function stripProtectedOrgFieldsFromGenericPut");
    expect(org).toContain("TRACKED_FIELDS");
    expect(controller).toContain("stripProtectedOrgFieldsFromGenericPut");
    expect(controller).toContain("assertNotChangedViaGenericPut");
    expect(controller).toContain("PersonelArchiveGate::assertBusinessWriteAllowed");
    expect(controller).toContain("Bu sicil no ile kayıt açılamaz.");
    expect(controller).toContain("DUPLICATE_TC_KIMLIK_NO");
    expect(controller).toContain("DUPLICATE_SICIL_NO");
    expect(basic).toContain("stripProtectedOrgFieldsFromGenericPut");
  });

  it("FE maps duplicate TC and sicil to short Turkish messages", () => {
    const apiClient = read("src/api/api-client.ts");
    expect(apiClient).toContain("DUPLICATE_TC_KIMLIK_NO");
    expect(apiClient).toContain("DUPLICATE_SICIL_NO");
    expect(apiClient).toContain("Bu T.C. Kimlik No ile kayıt açılamaz.");
    expect(apiClient).toContain("Bu sicil no ile kayıt açılamaz.");
  });

  it("create owner and org workflow owners stay separate from Genel", () => {
    const createFields = read("src/features/personeller/components/PersonelCreateFields.tsx");
    const orgPanel = read("src/features/kayit/components/KayitSurecPersonelOrganizasyonPanel.tsx");
    const genel = read("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx");

    expect(createFields).toContain("create-calisma-lokasyonu");
    expect(orgPanel).toContain("organizasyon");
    expect(genel).not.toContain("applyPersonelOrganizasyonDegisikligi");
    expect(genel).not.toContain("applyPersonelKaliciSubeDegisikligi");
    expect(genel).not.toContain("buildCreatePersonelPayload");
  });
});
