import { describe, expect, it } from "vitest";
import {
  FINANS_ADAY_DONEM_YOK_MESAJI,
  FINANS_ADAY_KAYIT_YOK_MESAJI,
  formatFinansKayitAdayRolu,
  formatFinansKayitSatirOzeti,
  isAktifFinansKaydi
} from "../../src/features/personeller/components/personel-dosya/personel-finans-adaylari-utils";
import type { FinansKalem } from "../../src/types/finans";

const YASAKLI_BORDRO_DILI = [
  "Kesildi",
  "Maaştan düşüldü",
  "Bordro kesinleşti",
  "Net maaş sonucu",
  "SGK'dan ödenecek"
];

function makeFinansKalem(overrides: Partial<FinansKalem> & Pick<FinansKalem, "kalem_turu">): FinansKalem {
  return {
    id: 1,
    personel_id: 1,
    donem: "2026-04",
    tutar: 1000,
    state: "AKTIF",
    ...overrides
  };
}

describe("personel-finans-adaylari-utils", () => {
  it("bos durum mesajlarini saglar", () => {
    expect(FINANS_ADAY_DONEM_YOK_MESAJI).toBe(
      "Dönem bilgisi olmadığı için finans adayları gösterilemiyor."
    );
    expect(FINANS_ADAY_KAYIT_YOK_MESAJI).toBe("Bu dönem için aktif finans kaydı görünmüyor.");
  });

  it("isAktifFinansKaydi yalnizca AKTIF veya state bos kayitlari kabul eder", () => {
    expect(isAktifFinansKaydi(makeFinansKalem({ kalem_turu: "AVANS", state: "AKTIF" }))).toBe(true);
    expect(isAktifFinansKaydi(makeFinansKalem({ kalem_turu: "AVANS", state: undefined }))).toBe(true);
    expect(isAktifFinansKaydi(makeFinansKalem({ kalem_turu: "AVANS", state: "IPTAL" }))).toBe(false);
  });

  it("AVANS kaydinda maaştan mahsup edilecek aday dilini kullanir", () => {
    const rol = formatFinansKayitAdayRolu("AVANS");
    expect(rol).toBe("Maaştan mahsup edilecek aday");
    expect(formatFinansKayitSatirOzeti(makeFinansKalem({ kalem_turu: "AVANS", tutar: 5000 }))).toContain(rol);
  });

  it("PRIM ve EKSTRA_PRIM kayitlarinda ek odeme adayi dilini kullanir", () => {
    expect(formatFinansKayitAdayRolu("PRIM")).toBe("Ek ödeme adayı");
    expect(formatFinansKayitAdayRolu("EKSTRA_PRIM")).toBe("Ek ödeme adayı");
  });

  it("CEZA ve DIGER_KESINTI kayitlarinda kesinti adayi dilini kullanir", () => {
    expect(formatFinansKayitAdayRolu("CEZA")).toBe("Bordroda dikkate alınacak kesinti adayı");
    expect(formatFinansKayitAdayRolu("DIGER_KESINTI")).toBe("Bordroda dikkate alınacak kesinti adayı");
    expect(formatFinansKayitSatirOzeti(makeFinansKalem({ kalem_turu: "CEZA" }))).toContain(
      "Bordroda dikkate alınacak kesinti adayı"
    );
  });

  it("yasakli kesin bordro dilini kullanmaz", () => {
    const ornekler = [
      formatFinansKayitAdayRolu("AVANS"),
      formatFinansKayitAdayRolu("PRIM"),
      formatFinansKayitAdayRolu("CEZA"),
      formatFinansKayitSatirOzeti(makeFinansKalem({ kalem_turu: "BES", tutar: 250 }))
    ];

    for (const metin of ornekler) {
      for (const yasak of YASAKLI_BORDRO_DILI) {
        expect(metin).not.toContain(yasak);
      }
    }
  });
});
