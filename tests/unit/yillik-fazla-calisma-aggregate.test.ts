import { describe, expect, it } from "vitest";
import {
  YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA,
  YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA,
  aggregateYillikFazlaCalisma
} from "../../src/services/yillik-fazla-calisma-aggregate";
import type {
  HaftalikKapanisSnapshotSatir,
  HaftalikKapanisSonuc
} from "../../src/types/haftalik-kapanis";

const YIL = 2026;

function snapshotSatir(
  overrides: Partial<HaftalikKapanisSnapshotSatir> & Pick<HaftalikKapanisSnapshotSatir, "personel_id" | "hafta_baslangic">
): HaftalikKapanisSnapshotSatir {
  return {
    personel_id: overrides.personel_id,
    hafta_baslangic: overrides.hafta_baslangic,
    hafta_bitis: overrides.hafta_bitis ?? "2026-04-12",
    state: "KAPANDI",
    toplam_net_dakika: 0,
    normal_calisma_dakika: 0,
    fazla_calisma_dakika: 0,
    fazla_surelerle_calisma_dakika: 0,
    tam_hafta_verisi: true,
    compliance_uyarilari: [],
    compliance_uyari_sayisi: 0,
    kritik_uyari_var_mi: false,
    ...overrides
  };
}

function kapanis(
  id: number,
  satirlar: HaftalikKapanisSnapshotSatir[]
): HaftalikKapanisSonuc {
  return {
    id,
    kapanis_id: id,
    snapshot_satirlari: satirlar
  };
}

describe("yillik-fazla-calisma-aggregate", () => {
  it("aynı personel ve yıl içinde iki farklı hafta toplamı doğru hesaplar", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: 500
          })
        ]),
        kapanis(2, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-13",
            yil: YIL,
            fazla_calisma_dakika: 300
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.kullanilan_dakika).toBe(800);
    expect(sonuc.kapanan_hafta_sayisi).toBe(2);
    expect(sonuc.kalan_dakika).toBe(YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA - 800);
  });

  it("farklı personel satırlarını izole eder", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: 400
          }),
          snapshotSatir({
            personel_id: 2,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: 9000
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.kullanilan_dakika).toBe(400);
    expect(sonuc.kapanan_hafta_sayisi).toBe(1);
  });

  it("farklı yıl satırlarını izole eder", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: 2026,
            fazla_calisma_dakika: 200
          }),
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2025-04-07",
            yil: 2025,
            fazla_calisma_dakika: 5000
          })
        ])
      ],
      personel_id: 1,
      yil: 2026
    });

    expect(sonuc.kullanilan_dakika).toBe(200);
    expect(sonuc.yil).toBe(2026);
  });

  it("limit altında limit_asildi_mi false döner", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: 1000
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.limit_asildi_mi).toBe(false);
    expect(sonuc.limit_yaklasiyor_mu).toBe(false);
  });

  it("limit üstünde limit_asildi_mi true ve kalan 0 döner", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA + 100
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.limit_asildi_mi).toBe(true);
    expect(sonuc.kalan_dakika).toBe(0);
  });

  it("yaklaşma eşiği üstü limit altı → limit_yaklasiyor_mu true", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA + 100
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.limit_yaklasiyor_mu).toBe(true);
    expect(sonuc.limit_asildi_mi).toBe(false);
    expect(sonuc.kullanilan_dakika).toBe(YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA + 100);
  });

  it("aynı hafta duplicate kapanışta en yüksek kapanis_id kazanır", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(5, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: 100
          })
        ]),
        kapanis(10, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: 700
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.kullanilan_dakika).toBe(700);
    expect(sonuc.kapanan_hafta_sayisi).toBe(1);
    expect(sonuc.atlanan_duplicate_hafta_sayisi).toBe(1);
  });

  it("tam_hafta_verisi false satır toplama dışı kalır ve eksik sayacı artar", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: 9999,
            tam_hafta_verisi: false
          }),
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-13",
            yil: YIL,
            fazla_calisma_dakika: 200,
            tam_hafta_verisi: true
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.kullanilan_dakika).toBe(200);
    expect(sonuc.atlanan_eksik_hafta_sayisi).toBe(1);
    expect(sonuc.kapanan_hafta_sayisi).toBe(1);
  });

  it("boş veride sıfır kullanım ve tam kalan döner", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.kullanilan_dakika).toBe(0);
    expect(sonuc.kalan_dakika).toBe(YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA);
    expect(sonuc.limit_asildi_mi).toBe(false);
    expect(sonuc.limit_yaklasiyor_mu).toBe(false);
    expect(sonuc.kapanan_hafta_sayisi).toBe(0);
    expect(sonuc.atlanan_duplicate_hafta_sayisi).toBe(0);
    expect(sonuc.atlanan_eksik_hafta_sayisi).toBe(0);
    expect(sonuc.yillik_limit_dakika).toBe(16200);
    expect(sonuc.yaklasma_esik_dakika).toBe(15600);
  });

  it("negatif veya geçersiz fazla_calisma_dakika 0 kabul edilir", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-06",
            yil: YIL,
            fazla_calisma_dakika: -50
          }),
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-04-13",
            yil: YIL,
            fazla_calisma_dakika: Number.NaN
          })
        ])
      ],
      personel_id: 1,
      yil: YIL
    });

    expect(sonuc.kullanilan_dakika).toBe(0);
    expect(sonuc.kapanan_hafta_sayisi).toBe(2);
  });

  it("yil alanı yoksa hafta_baslangic tarihinden yıl türetir", () => {
    const sonuc = aggregateYillikFazlaCalisma({
      kapanislar: [
        kapanis(1, [
          snapshotSatir({
            personel_id: 1,
            hafta_baslangic: "2026-05-05",
            fazla_calisma_dakika: 120
          })
        ])
      ],
      personel_id: 1,
      yil: 2026
    });

    expect(sonuc.kullanilan_dakika).toBe(120);
  });
});
