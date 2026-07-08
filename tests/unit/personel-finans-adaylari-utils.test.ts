import { describe, expect, it } from "vitest";
import {
  FINANS_ADAY_DONEM_YOK_MESAJI,
  FINANS_ADAY_KAYIT_YOK_MESAJI,
  computeFinansAdayToplamlari,
  formatFinansKayitAdayRolu,
  formatFinansKayitSatirOzeti,
  formatFinansKayitTutar,
  getFinansAdayGrubu,
  hasFinansAdayToplami,
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

  it("AVANS toplamini mahsupAdayTutari grubuna yazar", () => {
    expect(getFinansAdayGrubu("AVANS")).toBe("mahsup");
    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "AVANS", tutar: 1500 }),
      makeFinansKalem({ id: 2, kalem_turu: "AVANS", tutar: 500 })
    ]);
    expect(toplamlar.mahsupAdayTutari).toBe(2000);
    expect(toplamlar.kesintiAdayTutari).toBe(0);
    expect(toplamlar.ekOdemeAdayTutari).toBe(0);
  });

  it("CEZA, DIGER_KESINTI ve BES toplamini kesintiAdayTutari grubuna yazar", () => {
    expect(getFinansAdayGrubu("CEZA")).toBe("kesinti");
    expect(getFinansAdayGrubu("DIGER_KESINTI")).toBe("kesinti");
    expect(getFinansAdayGrubu("BES")).toBe("kesinti");

    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "CEZA", tutar: 300 }),
      makeFinansKalem({ id: 2, kalem_turu: "DIGER_KESINTI", tutar: 200 }),
      makeFinansKalem({ id: 3, kalem_turu: "BES", tutar: 100 })
    ]);
    expect(toplamlar.kesintiAdayTutari).toBe(600);
  });

  it("PRIM, EKSTRA_PRIM ve MESAI toplamini ekOdemeAdayTutari grubuna yazar", () => {
    expect(getFinansAdayGrubu("PRIM")).toBe("ek_odeme");
    expect(getFinansAdayGrubu("EKSTRA_PRIM")).toBe("ek_odeme");
    expect(getFinansAdayGrubu("MESAI")).toBe("ek_odeme");

    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "PRIM", tutar: 400 }),
      makeFinansKalem({ id: 2, kalem_turu: "EKSTRA_PRIM", tutar: 250 }),
      makeFinansKalem({ id: 3, kalem_turu: "MESAI", tutar: 350 })
    ]);
    expect(toplamlar.ekOdemeAdayTutari).toBe(1000);
  });

  it("MAAS toplam disinda kalir", () => {
    expect(getFinansAdayGrubu("MAAS")).toBeNull();
    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "MAAS", tutar: 5000 }),
      makeFinansKalem({ id: 2, kalem_turu: "PRIM", tutar: 1000 })
    ]);
    expect(toplamlar.ekOdemeAdayTutari).toBe(1000);
    expect(toplamlar.dahilEdilmeyenKayitSayisi).toBe(1);
    expect(hasFinansAdayToplami(toplamlar)).toBe(true);
  });

  it("pasif veya tutar sifir kayitlari toplam disinda birakir", () => {
    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "AVANS", tutar: 1000, state: "IPTAL" }),
      makeFinansKalem({ id: 2, kalem_turu: "PRIM", tutar: 0 }),
      makeFinansKalem({ id: 3, kalem_turu: "CEZA", tutar: -50 })
    ]);
    expect(toplamlar.mahsupAdayTutari).toBe(0);
    expect(toplamlar.kesintiAdayTutari).toBe(0);
    expect(toplamlar.ekOdemeAdayTutari).toBe(0);
    expect(toplamlar.dahilEdilmeyenKayitSayisi).toBe(3);
    expect(hasFinansAdayToplami(toplamlar)).toBe(false);
  });

  it("bilinmeyen turu toplam disinda birakir", () => {
    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "BILINMEYEN", tutar: 900 })
    ]);
    expect(toplamlar.dahilEdilmeyenKayitSayisi).toBe(1);
    expect(hasFinansAdayToplami(toplamlar)).toBe(false);
  });

  it("gun_sayisi toplami etkilemez", () => {
    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "PRIM", tutar: 1000, gun_sayisi: 5 }),
      makeFinansKalem({ id: 2, kalem_turu: "PRIM", tutar: 500, gun_sayisi: 10 })
    ]);
    expect(toplamlar.ekOdemeAdayTutari).toBe(1500);
  });

  it("para formati mevcut helper ile uyumludur", () => {
    expect(formatFinansKayitTutar(1500)).toBe("1.500 TL");
    expect(formatFinansKayitTutar(1500.5)).toBe("1.500,5 TL");
    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "AVANS", tutar: 1500 })
    ]);
    expect(formatFinansKayitTutar(toplamlar.mahsupAdayTutari)).toBe("1.500 TL");
  });

  it("toplamlar yasakli bordro dilini kullanmaz", () => {
    const toplamlar = computeFinansAdayToplamlari([
      makeFinansKalem({ id: 1, kalem_turu: "AVANS", tutar: 1000 }),
      makeFinansKalem({ id: 2, kalem_turu: "CEZA", tutar: 500 }),
      makeFinansKalem({ id: 3, kalem_turu: "PRIM", tutar: 750 })
    ]);
    const metinler = [
      formatFinansKayitTutar(toplamlar.mahsupAdayTutari),
      formatFinansKayitTutar(toplamlar.kesintiAdayTutari),
      formatFinansKayitTutar(toplamlar.ekOdemeAdayTutari)
    ];

    for (const metin of metinler) {
      for (const yasak of YASAKLI_BORDRO_DILI) {
        expect(metin).not.toContain(yasak);
      }
    }
  });
});
