export type SgkUcretTipi = "MAKTU_AYLIK" | "GUNLUK_YEVMIYE";

export type SgkPrimGunuHesaplamaModu = "OTUZ_GUN_STANDART" | "TAKVIM_GUNU";

export type SgkPrimGunuGirdisi = {
  yil: number;
  ay: number;
  eksik_gun_sayisi?: number;
  ucret_tipi?: SgkUcretTipi;
};

export type SgkPrimGunuSonucu = {
  yil: number;
  ay: number;
  ayin_takvim_gun_sayisi: number;
  eksik_gun_sayisi: number;
  ucret_tipi: SgkUcretTipi;
  hesaplama_modu: SgkPrimGunuHesaplamaModu;
  sgk_prim_gun: number;
};

function assertTamSayi(value: number, label: string) {
  if (!Number.isInteger(value)) {
    throw new Error(`${label} tam sayi olmalidir.`);
  }
}

export function hesaplaAyinTakvimGunSayisi(yil: number, ay: number): number {
  assertTamSayi(yil, "Yil");
  assertTamSayi(ay, "Ay");

  if (ay < 1 || ay > 12) {
    throw new Error("Ay 1 ile 12 arasinda olmalidir.");
  }

  return new Date(yil, ay, 0).getDate();
}

function normalizeEksikGunSayisi(eksikGunSayisi: number | undefined, takvimGunSayisi: number): number {
  const normalized = eksikGunSayisi ?? 0;
  assertTamSayi(normalized, "Eksik gun sayisi");

  if (normalized < 0) {
    throw new Error("Eksik gun sayisi sifirdan kucuk olamaz.");
  }

  if (normalized > takvimGunSayisi) {
    throw new Error("Eksik gun sayisi ayin takvim gun sayisini asamaz.");
  }

  return normalized;
}

export function hesaplaSgkPrimGunu(girdi: SgkPrimGunuGirdisi): SgkPrimGunuSonucu {
  const ayinTakvimGunSayisi = hesaplaAyinTakvimGunSayisi(girdi.yil, girdi.ay);
  const eksikGunSayisi = normalizeEksikGunSayisi(girdi.eksik_gun_sayisi, ayinTakvimGunSayisi);
  const ucretTipi = girdi.ucret_tipi ?? "MAKTU_AYLIK";

  if (ucretTipi === "MAKTU_AYLIK" && eksikGunSayisi === 0) {
    return {
      yil: girdi.yil,
      ay: girdi.ay,
      ayin_takvim_gun_sayisi: ayinTakvimGunSayisi,
      eksik_gun_sayisi: eksikGunSayisi,
      ucret_tipi: ucretTipi,
      hesaplama_modu: "OTUZ_GUN_STANDART",
      sgk_prim_gun: 30
    };
  }

  return {
    yil: girdi.yil,
    ay: girdi.ay,
    ayin_takvim_gun_sayisi: ayinTakvimGunSayisi,
    eksik_gun_sayisi: eksikGunSayisi,
    ucret_tipi: ucretTipi,
    hesaplama_modu: "TAKVIM_GUNU",
    sgk_prim_gun: ayinTakvimGunSayisi - eksikGunSayisi
  };
}
