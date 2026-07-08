import { describe, expect, it } from "vitest";
import {
  cozumleHastalikRaporGunu,
  type HastalikRaporSureci
} from "../../src/services/hastalik-rapor-politikasi";

function makeSurec(overrides: Partial<HastalikRaporSureci> & { id?: number | string }): HastalikRaporSureci {
  return {
    id: 1,
    personel_id: 1,
    surec_turu: "RAPOR",
    alt_tur: "Raporlu_Hastalik",
    baslangic_tarihi: "2026-04-10",
    bitis_tarihi: "2026-04-14",
    ilk_iki_gun_firma_oder_mi: false,
    state: "AKTIF",
    ...overrides
  };
}

const input = { personelId: 1, tarih: "2026-04-10" };

describe("cozumleHastalikRaporGunu", () => {
  it("1. eslesme yok → YOK", () => {
    expect(cozumleHastalikRaporGunu([], input)).toMatchObject({
      eslesme_var_mi: false,
      ucret_policy: "YOK",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("2. hastalik raporu 1. gun + false → KESINTI_ADAYI", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 10, ilk_iki_gun_firma_oder_mi: false })], {
        personelId: 1,
        tarih: "2026-04-10"
      })
    ).toMatchObject({
      eslesme_var_mi: true,
      gun_sirasi: 1,
      ilk_iki_gun_mu: true,
      firma_oder_mi: false,
      ucret_policy: "KESINTI_ADAYI",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("3. hastalik raporu 2. gun + false → KESINTI_ADAYI", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 11, ilk_iki_gun_firma_oder_mi: false })], {
        personelId: 1,
        tarih: "2026-04-11"
      })
    ).toMatchObject({
      gun_sirasi: 2,
      ilk_iki_gun_mu: true,
      ucret_policy: "KESINTI_ADAYI"
    });
  });

  it("4. hastalik raporu 1. gun + true → UCRET_KORUNUR", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 12, ilk_iki_gun_firma_oder_mi: true })], {
        personelId: 1,
        tarih: "2026-04-10"
      })
    ).toMatchObject({
      firma_oder_mi: true,
      ucret_policy: "UCRET_KORUNUR",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("5. hastalik raporu 3. gun + false → POLITIKA_INCELEMESI", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 13, ilk_iki_gun_firma_oder_mi: false })], {
        personelId: 1,
        tarih: "2026-04-12"
      })
    ).toMatchObject({
      gun_sirasi: 3,
      ilk_iki_gun_mu: false,
      ucret_policy: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });

  it("6. ilk_iki_gun_firma_oder_mi null + 1. gun → POLITIKA_INCELEMESI", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 14, ilk_iki_gun_firma_oder_mi: null })], {
        personelId: 1,
        tarih: "2026-04-10"
      })
    ).toMatchObject({
      firma_oder_mi: null,
      ucret_policy: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });

  it("7. state IPTAL ignore edilir", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 15, state: "IPTAL" })], {
        personelId: 1,
        tarih: "2026-04-10"
      }).ucret_policy
    ).toBe("YOK");
  });

  it("8. Raporlu_Is_Kazasi ignore edilir", () => {
    expect(
      cozumleHastalikRaporGunu(
        [
          makeSurec({
            id: 16,
            alt_tur: "Raporlu_Is_Kazasi",
            ilk_iki_gun_firma_oder_mi: null
          })
        ],
        { personelId: 1, tarih: "2026-04-10" }
      ).ucret_policy
    ).toBe("YOK");

    expect(
      cozumleHastalikRaporGunu(
        [
          makeSurec({
            id: 17,
            alt_tur: "IS_KAZASI",
            ilk_iki_gun_firma_oder_mi: null
          })
        ],
        { personelId: 1, tarih: "2026-04-10" }
      ).ucret_policy
    ).toBe("YOK");
  });

  it("9. bitis_tarihi null tek gunluk kabul edilir", () => {
    const tekGun = makeSurec({
      id: 18,
      baslangic_tarihi: "2026-04-15",
      bitis_tarihi: null
    });

    expect(
      cozumleHastalikRaporGunu([tekGun], { personelId: 1, tarih: "2026-04-15" })
    ).toMatchObject({
      gun_sirasi: 1,
      ucret_policy: "KESINTI_ADAYI"
    });

    expect(
      cozumleHastalikRaporGunu([tekGun], { personelId: 1, tarih: "2026-04-16" }).ucret_policy
    ).toBe("YOK");
  });

  it("10. ayni gun iki hastalik raporu cakismasi → coklu_eslesme_mi", () => {
    expect(
      cozumleHastalikRaporGunu(
        [
          makeSurec({ id: 20, baslangic_tarihi: "2026-04-08", bitis_tarihi: "2026-04-12" }),
          makeSurec({ id: 21, baslangic_tarihi: "2026-04-09", bitis_tarihi: "2026-04-11" })
        ],
        { personelId: 1, tarih: "2026-04-10" }
      )
    ).toMatchObject({
      eslesme_var_mi: true,
      coklu_eslesme_mi: true,
      surec_id: null,
      gun_sirasi: null,
      ucret_policy: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });

  it("11. farkli personel eslesmez", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 22, personel_id: 99 })], {
        personelId: 1,
        tarih: "2026-04-10"
      }).ucret_policy
    ).toBe("YOK");
  });

  it("gecersiz tarih → POLITIKA_INCELEMESI, exception yok", () => {
    expect(
      cozumleHastalikRaporGunu([makeSurec({ id: 23 })], {
        personelId: 1,
        tarih: "2026-13-40"
      })
    ).toMatchObject({
      eslesme_var_mi: false,
      ucret_policy: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });
});
