import { formatBildirimTuruLabel, normalizeEnumKey } from "../../lib/display/enum-display";
import type {
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi
} from "../../types/puantaj";
import type { KeyOption } from "../../types/referans";

export type GunlukKayitPreset = {
  key: string;
  label: string;
  gunTipi: PuantajGunTipi | null;
  hareketDurumu: PuantajHareketDurumu | null;
  dayanak: PuantajDayanak | null;
  hesapEtkisi: PuantajHesapEtkisi | null;
  aciklama: string;
};

export type GunlukKayitOption = {
  key: string;
  label: string;
  preset: GunlukKayitPreset;
};

const GUN_TIPI_LABELS: Record<PuantajGunTipi, string> = {
  Normal_Is_Gunu: "Normal Is Gunu",
  Hafta_Tatili_Pazar: "Hafta Tatili Pazar",
  UBGT_Resmi_Tatil: "UBGT Resmi Tatil"
};

const HAREKET_DURUMU_LABELS: Record<PuantajHareketDurumu, string> = {
  Geldi: "Geldi",
  Gelmedi: "Gelmedi",
  Gec_Geldi: "Gec Geldi",
  Erken_Cikti: "Erken Cikti"
};

const DAYANAK_LABELS: Record<PuantajDayanak, string> = {
  Yok_Izinsiz: "Yok / Izinsiz",
  Ucretli_Izinli: "Ucretli Izinli",
  Raporlu_Hastalik: "Raporlu Hastalik",
  Raporlu_Is_Kazasi: "Raporlu Is Kazasi",
  Yillik_Izin: "Yillik Izin",
  Telafi_Calismasi: "Telafi Calismasi"
};

const HESAP_ETKISI_LABELS: Record<PuantajHesapEtkisi, string> = {
  Kesinti_Yap: "Kesinti Yap",
  Tam_Yevmiye_Ver: "Tam Yevmiye Ver",
  Mesai_Yaz: "Mesai Yaz"
};

const PRESET_MAP: Record<
  string,
  Omit<GunlukKayitPreset, "key" | "label">
> = {
  GEC_GELDI: {
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Gec_Geldi",
    dayanak: "Yok_Izinsiz",
    hesapEtkisi: "Tam_Yevmiye_Ver",
    aciklama: "Personel gec geldi ancak yevmiye tam calisir."
  },
  GELMEDI: {
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Gelmedi",
    dayanak: "Yok_Izinsiz",
    hesapEtkisi: "Kesinti_Yap",
    aciklama: "Mazeretsiz devamsizlik puantajda kesinti yaratir."
  },
  IZINLI_GELMEDI: {
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Gelmedi",
    dayanak: "Ucretli_Izinli",
    hesapEtkisi: "Tam_Yevmiye_Ver",
    aciklama: "Ucretli mazeret nedeniyle calisilmayan gun tam yevmiye ile korunur."
  },
  IZINSIZ_GELMEDI: {
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Gelmedi",
    dayanak: "Yok_Izinsiz",
    hesapEtkisi: "Kesinti_Yap",
    aciklama: "Izinsiz devamsizlik puantajda kesinti ve hak kaybi riski tasir."
  },
  DEVAMSIZLIK: {
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Gelmedi",
    dayanak: "Yok_Izinsiz",
    hesapEtkisi: "Kesinti_Yap",
    aciklama: "Devamsizlik kaydi gunluk puantajda mazeretsiz yokluk olarak islenir."
  },
  RAPORLU: {
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Gelmedi",
    dayanak: "Raporlu_Hastalik",
    hesapEtkisi: "Tam_Yevmiye_Ver",
    aciklama: "Rapor bilgisi puantajda calisilmamis gun olarak tutulur."
  },
  GEC_CIKTI: {
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Geldi",
    dayanak: "Telafi_Calismasi",
    hesapEtkisi: "Mesai_Yaz",
    aciklama: "Gec cikis kaydi ek calisma veya telafi mantigi ile izlenir."
  }
};

function humanizeFallback(value: string): string {
  return value
    .split("_")
    .filter(Boolean)
    .map((token) => token.charAt(0) + token.slice(1).toLowerCase())
    .join(" ");
}

export function formatGunlukKayitGunTipi(value: PuantajGunTipi | null | undefined): string {
  if (!value) {
    return "-";
  }
  return GUN_TIPI_LABELS[value] ?? humanizeFallback(value);
}

export function formatGunlukKayitHareketDurumu(
  value: PuantajHareketDurumu | null | undefined
): string {
  if (!value) {
    return "-";
  }
  return HAREKET_DURUMU_LABELS[value] ?? humanizeFallback(value);
}

export function formatGunlukKayitDayanak(value: PuantajDayanak | null | undefined): string {
  if (!value) {
    return "-";
  }
  return DAYANAK_LABELS[value] ?? humanizeFallback(value);
}

export function formatGunlukKayitHesapEtkisi(
  value: PuantajHesapEtkisi | null | undefined
): string {
  if (!value) {
    return "-";
  }
  return HESAP_ETKISI_LABELS[value] ?? humanizeFallback(value);
}

export function resolveGunlukKayitPreset(value: string | null | undefined): GunlukKayitPreset {
  const normalized = typeof value === "string" ? normalizeEnumKey(value) : "";
  const preset = PRESET_MAP[normalized];

  return {
    key: normalized,
    label: normalized ? formatBildirimTuruLabel(normalized) : "-",
    gunTipi: preset?.gunTipi ?? null,
    hareketDurumu: preset?.hareketDurumu ?? null,
    dayanak: preset?.dayanak ?? null,
    hesapEtkisi: preset?.hesapEtkisi ?? null,
    aciklama:
      preset?.aciklama ?? "Bu kayit eski bildirim tipiyle tutuluyor; puantaj katmani icin ek yorum gerekebilir."
  };
}

export function buildGunlukKayitOptions(options: KeyOption[]): GunlukKayitOption[] {
  return options.map((option) => ({
    key: option.key,
    label: resolveGunlukKayitPreset(option.key).label,
    preset: resolveGunlukKayitPreset(option.key)
  }));
}
