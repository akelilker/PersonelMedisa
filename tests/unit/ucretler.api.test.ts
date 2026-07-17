import { describe, expect, it } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import {
  SALARY_DATE_OVERLAP_MESSAGE,
  getUcretApiErrorMessage,
  isUcretKaydiGuncel,
  normalizePersonelUcretKaydi
} from "../../src/api/ucretler.api";
import { normalizeMevzuatParametresi } from "../../src/api/mevzuat.api";

describe("normalizePersonelUcretKaydi", () => {
  it("backend state alanini durum olarak normalize eder ve string tutari sayiya cevirir", () => {
    const normalized = normalizePersonelUcretKaydi({
      id: "12",
      personel_id: "3",
      ucret_tutari: "42000.50",
      ucret_turu: "brut",
      para_birimi: "try",
      gecerlilik_baslangic: "2026-01-01",
      gecerlilik_bitis: null,
      state: "AKTIF",
      kaynak: "MANUEL"
    });

    expect(normalized.id).toBe(12);
    expect(normalized.personel_id).toBe(3);
    expect(normalized.ucret_tutari).toBe(42000.5);
    expect(normalized.ucret_turu).toBe("BRUT");
    expect(normalized.para_birimi).toBe("TRY");
    expect(normalized.durum).toBe("AKTIF");
    expect(normalized.kaynak).toBe("MANUEL");
    expect(normalized.guncel_mi).toBe(true);
  });

  it("iptal kayitlar guncel sayilmaz, virtual legacy kayitta id null olur", () => {
    const iptal = normalizePersonelUcretKaydi({
      id: 5,
      personel_id: 1,
      ucret_tutari: 1000,
      ucret_turu: "NET",
      gecerlilik_baslangic: "2020-01-01",
      gecerlilik_bitis: null,
      state: "IPTAL",
      kaynak: "MANUEL"
    });
    expect(iptal.durum).toBe("IPTAL");
    expect(iptal.guncel_mi).toBe(false);

    const virtual = normalizePersonelUcretKaydi({
      id: null,
      personel_id: 1,
      ucret_tutari: 35000,
      ucret_turu: "NET",
      gecerlilik_baslangic: "2023-02-01",
      gecerlilik_bitis: null,
      state: "AKTIF",
      kaynak: "PERSONEL_KAYDI_MIGRASYON"
    });
    expect(virtual.id).toBeNull();
    expect(virtual.kaynak).toBe("PERSONEL_KAYDI_MIGRASYON");
  });

  it("zorunlu alan eksikse hata firlatir", () => {
    expect(() => normalizePersonelUcretKaydi(null)).toThrow();
    expect(() =>
      normalizePersonelUcretKaydi({ id: 1, personel_id: 1, ucret_turu: "NET" })
    ).toThrow();
  });
});

describe("isUcretKaydiGuncel", () => {
  const base = {
    durum: "AKTIF" as const,
    gecerlilik_baslangic: "2026-01-01",
    gecerlilik_bitis: "2026-06-30" as string | null
  };

  it("tarih araligi dahil (inclusive) semantikle calisir", () => {
    expect(isUcretKaydiGuncel(base, "2026-01-01")).toBe(true);
    expect(isUcretKaydiGuncel(base, "2026-06-30")).toBe(true);
    expect(isUcretKaydiGuncel(base, "2025-12-31")).toBe(false);
    expect(isUcretKaydiGuncel(base, "2026-07-01")).toBe(false);
  });

  it("bitisi olmayan aktif kayit gelecekte de gecerlidir, iptal kayit gecerli degildir", () => {
    expect(isUcretKaydiGuncel({ ...base, gecerlilik_bitis: null }, "2030-01-01")).toBe(true);
    expect(isUcretKaydiGuncel({ ...base, durum: "IPTAL" }, "2026-03-01")).toBe(false);
  });
});

describe("getUcretApiErrorMessage", () => {
  it("SALARY_DATE_OVERLAP kodunu sabit Turkce mesaja cevirir", () => {
    const error = new ApiRequestError("Ucret gecerlilik tarihleri mevcut kayitla cakisiyor.", 409, {
      code: "SALARY_DATE_OVERLAP"
    });
    expect(getUcretApiErrorMessage(error, "Ücret kaydı oluşturulamadı.")).toBe(
      SALARY_DATE_OVERLAP_MESSAGE
    );
  });

  it("diger hatalarda backend mesajini veya fallback mesaji kullanir", () => {
    const error = new ApiRequestError("Ucret tutari sifirdan buyuk olmalidir.", 400, {
      code: "SALARY_AMOUNT_INVALID"
    });
    expect(getUcretApiErrorMessage(error, "Ücret kaydı oluşturulamadı.")).toBe(
      "Ucret tutari sifirdan buyuk olmalidir."
    );
    expect(getUcretApiErrorMessage(new Error(""), "Ücret geçmişi yüklenemedi.")).toBe(
      "Ücret geçmişi yüklenemedi."
    );
  });
});

describe("normalizeMevzuatParametresi", () => {
  it("state alanini durum olarak normalize eder ve kodu buyuk harfe cevirir", () => {
    const normalized = normalizeMevzuatParametresi({
      id: 7,
      parametre_kodu: "asgari_ucret_brut",
      deger_tipi: "SAYISAL",
      sayisal_deger: "26005.5",
      metin_deger: null,
      gecerlilik_baslangic: "2026-01-01",
      gecerlilik_bitis: null,
      state: "AKTIF"
    });

    expect(normalized.parametre_kodu).toBe("ASGARI_UCRET_BRUT");
    expect(normalized.sayisal_deger).toBe(26005.5);
    expect(normalized.durum).toBe("AKTIF");
    expect(normalized.gecerlilik_bitis).toBeNull();
  });

  it("zorunlu alan eksikse hata firlatir", () => {
    expect(() => normalizeMevzuatParametresi({ id: 1 })).toThrow();
  });
});
