import { describe, expect, it } from "vitest";
import {
  buildCreatePersonelPayload,
  isPersonelMaasMissing
} from "../../src/features/personeller/personel-create-utils";
import { INITIAL_CREATE_PERSONEL_FORM } from "../../src/hooks/usePersoneller";

const validForm = {
  ...INITIAL_CREATE_PERSONEL_FORM,
  tcKimlikNo: "12345678901",
  ad: "E2E",
  soyad: "Test",
  dogumTarihi: "1990-05-15",
  telefon: "05321234567",
  acilDurumKisi: "Yakın Kişi",
  acilDurumTelefon: "05329876543",
  sicilNo: "E2E-001",
  iseGirisTarihi: "2026-06-01",
  subeId: "1",
  departmanId: "3",
  gorevId: "1",
  personelTipiId: "1"
};

describe("personel-create-utils", () => {
  it("buildCreatePersonelPayload şube olmadan hata verir", () => {
    expect(() =>
      buildCreatePersonelPayload({
        ...validForm,
        subeId: ""
      })
    ).toThrow("Şube seçilmelidir.");
  });

  it("buildCreatePersonelPayload maaş boşken kayıt payload'ını üretir", () => {
    const payload = buildCreatePersonelPayload({
      ...validForm,
      maasTutari: ""
    });

    expect(payload.sube_id).toBe(1);
    expect(payload.maas_tutari).toBeUndefined();
  });

  it("maas_tutari === 0 eksik sayılmaz", () => {
    expect(isPersonelMaasMissing(0)).toBe(false);
    expect(isPersonelMaasMissing(undefined)).toBe(true);
    expect(isPersonelMaasMissing(null)).toBe(true);
  });
});
