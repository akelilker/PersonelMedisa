import { describe, expect, it } from "vitest";
import {
  HAFTALIK_KAPANIS_KAYNAK_VERSIYON_A2,
  buildHaftalikKapanisSnapshot,
  hesaplaIsoHaftaNo,
  listHaftaTarihleri,
  type HaftalikKapanisPuantajGun
} from "../../src/services/haftalik-kapanis-snapshot";
import {
  HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA,
  ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE,
  UBGT_FAZLA_MESAI_CAKISMASI_CODE,
  hesaplaHaftalikCalismaOzeti
} from "../../src/services/puantaj-hesap-motoru";

const HAFTA_BAS = "2026-04-06";
const HAFTA_BIT = "2026-04-12";

function gunKaydi(
  tarih: string,
  net: number,
  extra: Partial<HaftalikKapanisPuantajGun> = {}
): HaftalikKapanisPuantajGun {
  return {
    tarih,
    net_calisma_suresi_dakika: net,
    gun_tipi: "Normal_Is_Gunu",
    hareket_durumu: "Geldi",
    hesap_etkisi: "Tam_Yevmiye_Ver",
    compliance_uyarilari: [],
    ...extra
  };
}

function yediGun510Map(personelId = 1): Map<string, HaftalikKapanisPuantajGun> {
  const map = new Map<string, HaftalikKapanisPuantajGun>();
  for (const tarih of listHaftaTarihleri(HAFTA_BAS)) {
    map.set(`${personelId}|${tarih}`, gunKaydi(tarih, 510));
  }
  return map;
}

function buildFromMap(
  map: Map<string, HaftalikKapanisPuantajGun>,
  personeller: Array<{ id: number; departman_id?: number; dogum_tarihi?: string | null }> = [
    { id: 1, departman_id: 3 }
  ]
) {
  return buildHaftalikKapanisSnapshot({
    kapanis_id: 10,
    hafta_baslangic: HAFTA_BAS,
    hafta_bitis: HAFTA_BIT,
    departman_id: 3,
    personeller,
    resolvePuantaj: (personelId, tarih) => map.get(`${personelId}|${tarih}`) ?? null
  });
}

describe("haftalik-kapanis-snapshot", () => {
  it("7 gün 510 dk ile süre alanları motor özetiyle uyumludur", () => {
    const sonuc = buildFromMap(yediGun510Map());
    const row = sonuc.snapshot_satirlari[0];
    const beklenen = hesaplaHaftalikCalismaOzeti(
      Array.from({ length: 7 }, () => ({ net_calisma_suresi_dakika: 510 }))
    );

    expect(row.toplam_net_dakika).toBe(beklenen.toplam_net_dakika);
    expect(row.normal_calisma_dakika).toBe(beklenen.normal_calisma_dakika);
    expect(row.fazla_calisma_dakika).toBe(beklenen.fazla_calisma_dakika);
    expect(row.toplam_net_dakika).toBe(7 * 510);
    expect(row.fazla_calisma_dakika).toBe(7 * 510 - HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA);
  });

  it("fazla_surelerle_calisma_dakika her zaman 0 gelir", () => {
    const sonuc = buildFromMap(yediGun510Map());
    expect(sonuc.snapshot_satirlari[0].fazla_surelerle_calisma_dakika).toBe(0);
  });

  it("7 gün varsa tam_hafta_verisi true ve kaynak_gun_sayisi 7 olur", () => {
    const sonuc = buildFromMap(yediGun510Map());
    const row = sonuc.snapshot_satirlari[0];
    expect(row.tam_hafta_verisi).toBe(true);
    expect(row.kaynak_gun_sayisi).toBe(7);
    expect(row.notlar).toBeUndefined();
  });

  it("eksik gün varsa tam_hafta_verisi false; UBGT ve 18↓ haftalık uyarı üretilmez", () => {
    const map = new Map<string, HaftalikKapanisPuantajGun>();
    const tarihler = listHaftaTarihleri(HAFTA_BAS);
    map.set(`1|${tarihler[0]}`, gunKaydi(tarihler[0], 2760));
    map.set(
      `1|${tarihler[1]}`,
      gunKaydi(tarihler[1], 480, {
        gun_tipi: "UBGT_Resmi_Tatil",
        hesap_etkisi: "Mesai_Yaz"
      })
    );
    map.set(`1|${tarihler[2]}`, gunKaydi(tarihler[2], 510));

    const sonuc = buildFromMap(map, [
      { id: 1, departman_id: 3, dogum_tarihi: "2010-01-01" }
    ]);
    const row = sonuc.snapshot_satirlari[0];

    expect(row.tam_hafta_verisi).toBe(false);
    expect(row.kaynak_gun_sayisi).toBe(3);
    expect(
      row.compliance_uyarilari.some((u) => u.code === UBGT_FAZLA_MESAI_CAKISMASI_CODE)
    ).toBe(false);
    expect(
      row.compliance_uyarilari.some((u) => u.code === ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE)
    ).toBe(false);
    expect(row.notlar?.length).toBeGreaterThan(0);
  });

  it("compliance_uyari_sayisi compliance_uyarilari.length ile uyumludur", () => {
    const map = yediGun510Map();
    const tarih = listHaftaTarihleri(HAFTA_BAS)[0];
    map.set(`1|${tarih}`, gunKaydi(tarih, 510, { compliance_uyarilari: [{ code: "X", message: "y" }] }));
    const row = buildFromMap(map).snapshot_satirlari[0];
    expect(row.compliance_uyari_sayisi).toBe(row.compliance_uyarilari.length);
  });

  it("kritik_uyari_var_mi KRITIK uyarıda true olur", () => {
    const map = yediGun510Map();
    const tarih = listHaftaTarihleri(HAFTA_BAS)[0];
    map.set(`1|${tarih}`, {
      ...gunKaydi(tarih, 510),
      compliance_uyarilari: [{ code: "KRITIK_TEST", message: "kritik", level: "KRITIK" }]
    });
    const row = buildFromMap(map).snapshot_satirlari[0];
    expect(row.kritik_uyari_var_mi).toBe(true);
  });

  it("personel_sayisi ve snapshot_satir_sayisi kapsam personel sayısıyla uyumludur", () => {
    const map = new Map<string, HaftalikKapanisPuantajGun>();
    for (const id of [1, 2]) {
      for (const tarih of listHaftaTarihleri(HAFTA_BAS)) {
        map.set(`${id}|${tarih}`, gunKaydi(tarih, 480));
      }
    }
    const sonuc = buildFromMap(map, [
      { id: 1, departman_id: 3 },
      { id: 2, departman_id: 3 }
    ]);
    expect(sonuc.personel_sayisi).toBe(2);
    expect(sonuc.snapshot_satir_sayisi).toBe(2);
    expect(sonuc.snapshot_satirlari).toHaveLength(2);
  });

  it("kaynak_versiyon A2_MOTOR_V1 ve ISO hafta_no bilinen tarih için tutarlıdır", () => {
    const sonuc = buildFromMap(yediGun510Map());
    const row = sonuc.snapshot_satirlari[0];
    expect(row.kaynak_versiyon).toBe(HAFTALIK_KAPANIS_KAYNAK_VERSIYON_A2);

    const iso = hesaplaIsoHaftaNo("2026-04-06");
    expect(iso).not.toBeNull();
    expect(iso!.yil).toBe(2026);
    expect(iso!.hafta_no).toBe(15);
    expect(row.hafta_no).toBe(15);
    expect(row.yil).toBe(2026);
  });

  it("tam hafta + UBGT mesai + FM > 0 ise UBGT_FAZLA_MESAI_CAKISMASI üretilir", () => {
    const map = new Map<string, HaftalikKapanisPuantajGun>();
    const tarihler = listHaftaTarihleri(HAFTA_BAS);
    for (let i = 0; i < 5; i++) {
      map.set(`1|${tarihler[i]}`, gunKaydi(tarihler[i], 480));
    }
    map.set(
      `1|${tarihler[5]}`,
      gunKaydi(tarihler[5], 480, {
        gun_tipi: "UBGT_Resmi_Tatil",
        hesap_etkisi: "Mesai_Yaz"
      })
    );
    map.set(`1|${tarihler[6]}`, gunKaydi(tarihler[6], 0));

    const row = buildFromMap(map).snapshot_satirlari[0];
    expect(row.tam_hafta_verisi).toBe(true);
    expect(row.fazla_calisma_dakika).toBeGreaterThan(0);
    expect(
      row.compliance_uyarilari.some((u) => u.code === UBGT_FAZLA_MESAI_CAKISMASI_CODE)
    ).toBe(true);
  });
});
