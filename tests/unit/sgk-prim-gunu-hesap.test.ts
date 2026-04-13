import { describe, expect, it } from "vitest";
import {
  hesaplaAyinTakvimGunSayisi,
  hesaplaSgkPrimGunu
} from "../../src/services/sgk-prim-gunu-hesap";

describe("hesaplaAyinTakvimGunSayisi", () => {
  it("31 çeken ayı doğru hesaplar", () => {
    expect(hesaplaAyinTakvimGunSayisi(2026, 1)).toBe(31);
  });

  it("28 çeken şubat ayını doğru hesaplar", () => {
    expect(hesaplaAyinTakvimGunSayisi(2026, 2)).toBe(28);
  });

  it("artık yılda şubat 29 gün hesaplanır", () => {
    expect(hesaplaAyinTakvimGunSayisi(2028, 2)).toBe(29);
  });
});

describe("hesaplaSgkPrimGunu", () => {
  it("31 çeken ayda 1 eksik gün → 30 prim günü", () => {
    const sonuc = hesaplaSgkPrimGunu({
      yil: 2026,
      ay: 1,
      eksik_gun_sayisi: 1,
      ucret_tipi: "MAKTU_AYLIK"
    });

    expect(sonuc.ayin_takvim_gun_sayisi).toBe(31);
    expect(sonuc.hesaplama_modu).toBe("TAKVIM_GUNU");
    expect(sonuc.sgk_prim_gun).toBe(30);
  });

  it("31 çeken ayda 2 eksik gün → 29 prim günü", () => {
    const sonuc = hesaplaSgkPrimGunu({
      yil: 2026,
      ay: 3,
      eksik_gun_sayisi: 2,
      ucret_tipi: "MAKTU_AYLIK"
    });

    expect(sonuc.sgk_prim_gun).toBe(29);
  });

  it("şubat 28 çekerken eksik gün yoksa maktu ücretli personel 30 gün bildirilir", () => {
    const sonuc = hesaplaSgkPrimGunu({
      yil: 2026,
      ay: 2,
      eksik_gun_sayisi: 0,
      ucret_tipi: "MAKTU_AYLIK"
    });

    expect(sonuc.ayin_takvim_gun_sayisi).toBe(28);
    expect(sonuc.hesaplama_modu).toBe("OTUZ_GUN_STANDART");
    expect(sonuc.sgk_prim_gun).toBe(30);
  });

  it("şubat 28 çekerken 1 eksik gün varsa 27 prim günü hesaplanır", () => {
    const sonuc = hesaplaSgkPrimGunu({
      yil: 2026,
      ay: 2,
      eksik_gun_sayisi: 1,
      ucret_tipi: "MAKTU_AYLIK"
    });

    expect(sonuc.hesaplama_modu).toBe("TAKVIM_GUNU");
    expect(sonuc.sgk_prim_gun).toBe(27);
  });

  it("artık yıl şubatında eksik gün yoksa yine 30 gün bildirilir", () => {
    const sonuc = hesaplaSgkPrimGunu({
      yil: 2028,
      ay: 2,
      eksik_gun_sayisi: 0,
      ucret_tipi: "MAKTU_AYLIK"
    });

    expect(sonuc.ayin_takvim_gun_sayisi).toBe(29);
    expect(sonuc.sgk_prim_gun).toBe(30);
  });

  it("günlük yevmiyede eksik gün yoksa takvim günü kadar bildirilir", () => {
    const sonuc = hesaplaSgkPrimGunu({
      yil: 2026,
      ay: 1,
      eksik_gun_sayisi: 0,
      ucret_tipi: "GUNLUK_YEVMIYE"
    });

    expect(sonuc.hesaplama_modu).toBe("TAKVIM_GUNU");
    expect(sonuc.sgk_prim_gun).toBe(31);
  });

  it("eksik gün sayısı takvim gününü aşarsa hata verir", () => {
    expect(() =>
      hesaplaSgkPrimGunu({
        yil: 2026,
        ay: 4,
        eksik_gun_sayisi: 31,
        ucret_tipi: "MAKTU_AYLIK"
      })
    ).toThrow(/Eksik gun sayisi ayin takvim gun sayisini asamaz/i);
  });
});
