import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  collarAllowsQrSelfService,
  hasQrSelfServiceEntitlement,
  hasUserPermission
} from "../../src/lib/authorization/role-permissions";
import { CALISAN_KAPSAMI_SELECT_OPTIONS, formatCalisanKapsamiLabel } from "../../src/lib/display/enum-display";
import { buildCreatePersonelPayload } from "../../src/features/personeller/personel-create-utils";
import { INITIAL_CREATE_PERSONEL_FORM } from "../../src/hooks/usePersoneller";

function read(rel: string): string {
  return readFileSync(resolve(process.cwd(), rel), "utf8");
}

describe("PR402 terminology + Statü/QR productization closure", () => {
  it("locks Dahili/Harici Personel as the only user-facing çalışan kapsamı labels", () => {
    expect(formatCalisanKapsamiLabel("IC_PERSONEL")).toBe("Dahili Personel");
    expect(formatCalisanKapsamiLabel("DIS_KAYNAK")).toBe("Harici Personel");
    expect(CALISAN_KAPSAMI_SELECT_OPTIONS.map((o) => o.label)).toEqual([
      "Dahili Personel",
      "Harici Personel"
    ]);
    expect(CALISAN_KAPSAMI_SELECT_OPTIONS.map((o) => o.value)).toEqual([
      "IC_PERSONEL",
      "DIS_KAYNAK"
    ]);

    const createFields = read("src/features/personeller/components/PersonelCreateFields.tsx");
    expect(createFields).toContain("CALISAN_KAPSAMI_SELECT_OPTIONS");
    expect(createFields).toContain("Harici Personel — Medisa ücret/SGK tahakkukuna dahil değildir");
    expect(createFields).not.toContain("İç Personel");
    expect(createFields).not.toContain("Dış Personel");
    expect(createFields).not.toContain("Dış Kaynak Personel");
    expect(createFields).not.toMatch(/>\s*IC_PERSONEL\s*</);
    expect(createFields).not.toMatch(/>\s*DIS_KAYNAK\s*</);
  });

  it("keeps Dahili Statü required and Harici Statü selectable/optional on create", () => {
    expect(() =>
      buildCreatePersonelPayload({
        ...INITIAL_CREATE_PERSONEL_FORM,
        calisanKapsami: "IC_PERSONEL",
        ad: "Ali",
        soyad: "Veli",
        tcKimlikNo: "12345678901",
        dogumTarihi: "1990-01-01",
        telefon: "05321234567",
        acilDurumTelefon: "05329876543",
        iseGirisTarihi: "2026-01-01",
        subeId: "1",
        sgkIsverenId: "1",
        departmanId: "1",
        gorevId: "1",
        personelTipiId: ""
      })
    ).toThrow(/Statü/);

    const hariciEmpty = buildCreatePersonelPayload({
      ...INITIAL_CREATE_PERSONEL_FORM,
      calisanKapsami: "DIS_KAYNAK",
      ad: "Harici",
      iseGirisTarihi: "2026-01-01",
      personelTipiId: ""
    });
    expect(hariciEmpty).not.toHaveProperty("personel_tipi_id");

    const hariciMavi = buildCreatePersonelPayload({
      ...INITIAL_CREATE_PERSONEL_FORM,
      calisanKapsami: "DIS_KAYNAK",
      ad: "Harici",
      iseGirisTarihi: "2026-01-01",
      personelTipiId: "1"
    });
    expect(hariciMavi.personel_tipi_id).toBe(1);

    const orgPanel = read("src/features/kayit/components/KayitSurecPersonelOrganizasyonPanel.tsx");
    expect(orgPanel).toContain('label="Statü"');
    expect(orgPanel).not.toContain('label="Çalışma Tipi"');
    expect(orgPanel).toContain("filterStatuOptionsForCreate");
    expect(orgPanel).toContain("Personel Statüsü belirlenmedi. QR kullanımı Mavi Yaka Statüsünde açılır.");
    expect(orgPanel).toContain("required={!isDisKaynak}");
  });

  it("resolves QR entitlement from collar + binding, not çalışan kapsamı", () => {
    // Dahili/Harici are orthogonal — entitlement is Mavi Yaka + bound.
    expect(hasQrSelfServiceEntitlement(10, "Mavi Yaka")).toBe(true);
    expect(hasQrSelfServiceEntitlement(10, "Beyaz Yaka")).toBe(false);
    expect(hasQrSelfServiceEntitlement(10, null)).toBe(false);
    expect(hasQrSelfServiceEntitlement(null, "Mavi Yaka")).toBe(false);
    expect(collarAllowsQrSelfService(null)).toBe(false);

    for (const role of ["PERSONEL", "BIRIM_AMIRI", "BOLUM_YONETICISI"] as const) {
      expect(hasUserPermission(role, "self_service.qr.scan", 42, "Mavi Yaka")).toBe(true);
      expect(hasUserPermission(role, "self_service.qr.scan", 42, "Beyaz Yaka")).toBe(false);
      expect(hasUserPermission(role, "self_service.qr.scan", 42, null)).toBe(false);
    }

    const perms = read("src/lib/authorization/role-permissions.ts");
    expect(perms).toContain("hasQrSelfServiceEntitlement");
    expect(perms).toContain("collarAllowsQrSelfService");
    expect(perms).not.toMatch(/calisan_kapsami.*qr\.scan|DIS_KAYNAK.*qr\.scan/);
  });
});
