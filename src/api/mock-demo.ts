import type { ApiResponse } from "../types/api";
import { hesaplaAylikSgkPuantajOzetleri } from "../services/dashboard-rapor-servisi";

type DemoMethod = "GET" | "POST" | "PUT" | "DELETE";

type DemoPersonel = {
  id: number;
  tc_kimlik_no: string;
  ad: string;
  soyad: string;
  aktif_durum: "AKTIF" | "PASIF";
  sube_id?: number;
  telefon?: string;
  dogum_tarihi?: string;
  sicil_no?: string;
  dogum_yeri?: string;
  kan_grubu?: string;
  ise_giris_tarihi?: string;
  acil_durum_kisi?: string;
  acil_durum_telefon?: string;
  departman_id?: number;
  gorev_id?: number;
  personel_tipi_id?: number;
  bagli_amir_id?: number;
};

type DemoSurec = {
  id: number;
  personel_id: number;
  surec_turu: string;
  alt_tur?: string;
  baslangic_tarihi?: string;
  bitis_tarihi?: string;
  ucretli_mi?: boolean;
  aciklama?: string;
  state?: string;
};

type DemoZimmet = {
  id: number;
  personel_id: number;
  urun_turu: string;
  teslim_tarihi: string;
  teslim_eden?: string;
  aciklama?: string;
  teslim_durumu: string;
  zimmet_durumu: string;
  iade_tarihi?: string;
};

type DemoBildirim = {
  id: number;
  tarih?: string;
  departman_id?: number;
  personel_id?: number;
  bildirim_turu: string;
  aciklama?: string;
  state?: string;
  okundu_mi?: boolean;
};

type DemoFinansKalem = {
  id: number;
  personel_id: number;
  donem: string;
  kalem_turu: string;
  tutar: number;
  aciklama?: string;
  state?: string;
};

type DemoPuantaj = {
  personel_id: number;
  tarih: string;
  gun_tipi?: "Normal_Is_Gunu" | "Hafta_Tatili_Pazar" | "UBGT_Resmi_Tatil";
  hareket_durumu?: "Geldi" | "Gelmedi" | "Gec_Geldi" | "Erken_Cikti";
  dayanak?:
    | "Yok_Izinsiz"
    | "Ucretli_Izinli"
    | "Raporlu_Hastalik"
    | "Raporlu_Is_Kazasi"
    | "Yillik_Izin"
    | "Telafi_Calismasi";
  hesap_etkisi?: "Kesinti_Yap" | "Tam_Yevmiye_Ver" | "Mesai_Yaz";
  giris_saati?: string;
  cikis_saati?: string;
  gercek_mola_dakika?: number;
  hesaplanan_mola_dakika?: number;
  net_calisma_suresi_dakika?: number;
  gunluk_brut_sure_dakika?: number;
  hafta_tatili_hak_kazandi_mi?: boolean;
  state?: string;
  compliance_uyarilari: Array<{ code: string; message: string; level?: string }>;
};

type DemoMakine = {
  id: number;
  ad: string;
  tip: string;
  konum?: string | null;
  durum: "aktif" | "arizali" | "pasif";
  sube_id: number;
  son_bakim?: string | null;
  bakim_periyot_gun?: number | null;
};

type DemoMakineBakimKaydi = {
  id: number;
  makine_id: number;
  bakim_tarihi?: string | null;
  yapan?: string;
  notlar?: string;
};

type DemoYonetimKullanici = {
  id: number;
  ad_soyad: string;
  telefon?: string;
  kullanici_tipi: "IC_PERSONEL" | "HARICI";
  rol: "GENEL_YONETICI" | "BOLUM_YONETICISI" | "MUHASEBE" | "BIRIM_AMIRI";
  personel_id?: number | null;
  sube_ids: number[];
  varsayilan_sube_id: number | null;
  durum: "AKTIF" | "PASIF";
  notlar?: string;
};

type DemoSube = {
  id: number;
  kod: string;
  ad: string;
  departman_ids: number[];
  durum: "AKTIF" | "PASIF";
};

type DemoDepartman = {
  id: number;
  ad: string;
};

/** Åirket birim/departman referans listesi (sabit ID sÄ±rasÄ±). */
const DEMO_DEPARTMANLAR: DemoDepartman[] = [
  { id: 1, ad: "Muhasebe" },
  { id: 2, ad: "Finans" },
  { id: 3, ad: "Döşeme" },
  { id: 4, ad: "Panel" },
  { id: 5, ad: "Hammadde Depo" },
  { id: 6, ad: "Depo" },
  { id: 7, ad: "E-ticaret Depo" },
  { id: 8, ad: "Yönetim Personeli" },
  { id: 9, ad: "Dış Ticaret" },
  { id: 10, ad: "İdari İşler" },
  { id: 11, ad: "Pazarlama" },
  { id: 12, ad: "Ar-Ge" }
];

/** GÃ¶rev/unvan referans listesi (sabit ID sÄ±rasÄ±). */
const DEMO_GOREVLER: Array<{ id: number; ad: string }> = [
  { id: 1, ad: "Genel Müdür" },
  { id: 2, ad: "Üretim Müdürü" },
  { id: 3, ad: "Finans Müdürü" },
  { id: 4, ad: "Fabrika Müdürü" },
  { id: 5, ad: "İdari İşler Müdürü" },
  { id: 6, ad: "Pazarlama Müdürü" },
  { id: 7, ad: "Dış Ticaret Müdürü" },
  { id: 8, ad: "Satış Destek Personeli" },
  { id: 9, ad: "Dış Ticaret Personeli" },
  { id: 10, ad: "Satış Personeli" },
  { id: 11, ad: "Temizlik Personeli" },
  { id: 12, ad: "Güvenlik Personeli" },
  { id: 13, ad: "Satış Sonrası (SSH)" },
  { id: 14, ad: "Şoför" },
  { id: 15, ad: "Aşçı" }
];

const DEMO_GOREV_LABELS: Record<number, string> = DEMO_GOREVLER.reduce(
  (acc, row) => {
    acc[row.id] = row.ad;
    return acc;
  },
  {} as Record<number, string>
);

type DemoAylikDurum = {
  ay: string;
  personel_id: number;
  bolum_onay_durumu: "BOLUM_ONAYINDA" | "BOLUM_ONAYLANDI" | "REVIZE_ISTENDI";
  revize_var_mi: boolean;
  son_islem: string;
  kapanis_durumu: "ACIK" | "KAPANDI";
};

const demoState: {
  personeller: DemoPersonel[];
  surecler: DemoSurec[];
  zimmetler: DemoZimmet[];
  bildirimler: DemoBildirim[];
  finansKalemleri: DemoFinansKalem[];
  puantajMap: Record<string, DemoPuantaj>;
  makineler: DemoMakine[];
  bakimKayitlari: DemoMakineBakimKaydi[];
  yonetimKullanicilari: DemoYonetimKullanici[];
  departmanlar: DemoDepartman[];
  subeler: DemoSube[];
  aylikDurumMap: Record<string, DemoAylikDurum>;
  nextIds: {
    personel: number;
    surec: number;
    zimmet: number;
    bildirim: number;
    finans: number;
    kapanis: number;
    kullanici: number;
    sube: number;
    departman: number;
  };
} = {
  personeller: [
    {
      id: 1,
      tc_kimlik_no: "12345678901",
      ad: "Ayse",
      soyad: "Yilmaz",
      aktif_durum: "AKTIF",
      sube_id: 1,
      telefon: "05550000000",
      dogum_tarihi: "1992-03-14",
      sicil_no: "P-001",
      dogum_yeri: "Istanbul",
      kan_grubu: "A Rh+",
      ise_giris_tarihi: "2023-02-01",
      acil_durum_kisi: "Fatma Yilmaz",
      acil_durum_telefon: "05553334455",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 1
    },
    {
      id: 2,
      tc_kimlik_no: "23456789012",
      ad: "Mehmet",
      soyad: "Kaya",
      aktif_durum: "AKTIF",
      sube_id: 2,
      telefon: "05551111111",
      dogum_tarihi: "1989-11-02",
      sicil_no: "P-002",
      dogum_yeri: "Ankara",
      kan_grubu: "0 Rh+",
      ise_giris_tarihi: "2024-07-15",
      acil_durum_kisi: "Zeynep Kaya",
      acil_durum_telefon: "05556667788",
      departman_id: 6,
      gorev_id: 2,
      personel_tipi_id: 2,
      bagli_amir_id: 1
    }
  ],
  surecler: [
    {
      id: 501,
      personel_id: 1,
      surec_turu: "IZIN",
      alt_tur: "YILLIK_IZIN",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-11",
      ucretli_mi: true,
      aciklama: "Demo izin kaydi",
      state: "AKTIF"
    }
  ],
  zimmetler: [
    {
      id: 551,
      personel_id: 1,
      urun_turu: "KASK",
      teslim_tarihi: "2026-03-01",
      teslim_eden: "IK Gorevlisi",
      aciklama: "Seri No: KSK-001",
      teslim_durumu: "YENI",
      zimmet_durumu: "AKTIF"
    },
    {
      id: 552,
      personel_id: 1,
      urun_turu: "KULAKLIK",
      teslim_tarihi: "2026-01-15",
      teslim_eden: "BaÄŸlÄ± Amir",
      aciklama: "Onceki vardiyadan teslim alindi",
      teslim_durumu: "IKINCI_EL",
      zimmet_durumu: "IADE_EDILDI",
      iade_tarihi: "2026-02-20"
    },
    {
      id: 553,
      personel_id: 2,
      urun_turu: "AYAKKABI",
      teslim_tarihi: "2026-02-05",
      teslim_eden: "IK Gorevlisi",
      aciklama: "Seri No: AYK-204",
      teslim_durumu: "YENI",
      zimmet_durumu: "AKTIF"
    }
  ],
  bildirimler: [
    {
      id: 701,
      tarih: "2026-04-09",
      departman_id: 3,
      personel_id: 1,
      bildirim_turu: "GEC_GELDI",
      aciklama: "Demo bildirim",
      state: "AKTIF",
      okundu_mi: false
    },
    {
      id: 702,
      tarih: "2026-04-10",
      departman_id: 6,
      personel_id: 2,
      bildirim_turu: "IZINLI_GELMEDI",
      aciklama: "Onayli izin nedeniyle bugun yok.",
      state: "AKTIF",
      okundu_mi: false
    }
  ],
  finansKalemleri: [
    {
      id: 901,
      personel_id: 1,
      donem: "2026-04",
      kalem_turu: "AVANS",
      tutar: 2500,
      aciklama: "Demo avans",
      state: "AKTIF"
    }
  ],
  puantajMap: {
    "1|2026-04-09": buildDemoPuantaj({
      personelId: 1,
      tarih: "2026-04-09",
      gunTipi: "Normal_Is_Gunu",
      hareketDurumu: "Geldi",
      hesapEtkisi: "Tam_Yevmiye_Ver",
      girisSaati: "08:30",
      cikisSaati: "18:00",
      gercekMolaDakika: 60,
      hesaplananMolaDakika: 60,
      netCalismaSuresiDakika: 510,
      gunlukBrutSureDakika: 570,
      haftaTatiliHakKazandiMi: true
    }),
    "2|2026-04-09": buildDemoPuantaj({
      personelId: 2,
      tarih: "2026-04-09",
      gunTipi: "Normal_Is_Gunu",
      hareketDurumu: "Gelmedi",
      dayanak: "Yok_Izinsiz",
      hesapEtkisi: "Kesinti_Yap",
      haftaTatiliHakKazandiMi: false,
      complianceUyarilari: [
        {
          code: "DEVAMSIZLIK",
          message: "Mazeretsiz devamsizlik hafta tatili hakkini bozabilir.",
          level: "KRITIK"
        }
      ]
    }),
    "1|2026-04-10": buildDemoPuantaj({
      personelId: 1,
      tarih: "2026-04-10",
      gunTipi: "Normal_Is_Gunu",
      hareketDurumu: "Gec_Geldi",
      dayanak: "Ucretli_Izinli",
      hesapEtkisi: "Tam_Yevmiye_Ver",
      girisSaati: "09:15",
      cikisSaati: "18:00",
      gercekMolaDakika: 60,
      hesaplananMolaDakika: 60,
      netCalismaSuresiDakika: 465,
      gunlukBrutSureDakika: 525,
      haftaTatiliHakKazandiMi: true,
      complianceUyarilari: [
        {
          code: "MAZERET",
          message: "Gec giris ucretli mazeret kapsaminda degerlendirildi.",
          level: "BILGI"
        }
      ]
    })
  },
  makineler: [
    {
      id: 1101,
      ad: "Kesim Robotu",
      tip: "Kesim",
      konum: "Atolye A",
      durum: "aktif",
      sube_id: 1,
      son_bakim: "2026-04-01",
      bakim_periyot_gun: 30
    },
    {
      id: 1102,
      ad: "Forklift 02",
      tip: "Tasima",
      konum: "Depo Giris",
      durum: "aktif",
      sube_id: 2,
      son_bakim: "2026-02-10",
      bakim_periyot_gun: 30
    },
    {
      id: 1103,
      ad: "Pres Hatti",
      tip: "Pres",
      konum: "Atolye B",
      durum: "aktif",
      sube_id: 1,
      son_bakim: null,
      bakim_periyot_gun: 45
    }
  ],
  bakimKayitlari: [
    {
      id: 2101,
      makine_id: 1101,
      bakim_tarihi: "2026-03-01",
      yapan: "Bakim Ekibi A",
      notlar: "Aylik kontrol"
    },
    {
      id: 2102,
      makine_id: 1101,
      bakim_tarihi: "2026-04-01",
      yapan: "Bakim Ekibi B",
      notlar: "Rutin mekanik bakim"
    },
    {
      id: 2104,
      makine_id: 1102,
      bakim_tarihi: "2026-02-10",
      yapan: "Servis",
      notlar: "Fren ayari"
    },
    {
      id: 2103,
      makine_id: 1102,
      bakim_tarihi: "2026-01-05",
      yapan: "Servis",
      notlar: "Yag degisimi"
    },
    {
      id: 2105,
      makine_id: 1103,
      bakim_tarihi: "gecersiz-tarih",
      yapan: "Kayit Hatasi",
      notlar: "Tarih dogrulanamadi"
    }
  ],
  yonetimKullanicilari: [
    {
      id: 1,
      ad_soyad: "Ilker Akel",
      telefon: "05550000001",
      kullanici_tipi: "HARICI",
      rol: "GENEL_YONETICI",
      personel_id: null,
      sube_ids: [],
      varsayilan_sube_id: null,
      durum: "AKTIF",
      notlar: "Tum yapinin yonetim ve son kapanis yetkilisi"
    },
    {
      id: 2,
      ad_soyad: "Adnan Bulut",
      telefon: "05550000002",
      kullanici_tipi: "HARICI",
      rol: "BOLUM_YONETICISI",
      personel_id: null,
      sube_ids: [2],
      varsayilan_sube_id: 2,
      durum: "AKTIF",
      notlar: "Depolama biriminin aylik kontrol sorumlusu"
    },
    {
      id: 3,
      ad_soyad: "Serhan Kose",
      telefon: "05550000003",
      kullanici_tipi: "IC_PERSONEL",
      rol: "BIRIM_AMIRI",
      personel_id: 1,
      sube_ids: [1],
      varsayilan_sube_id: 1,
      durum: "AKTIF",
      notlar: "Gunluk kayit sorumlusu"
    }
  ],
  departmanlar: [...DEMO_DEPARTMANLAR],
  subeler: [
    {
      id: 1,
      kod: "MRK",
      ad: "Merkez",
      departman_ids: [1, 3],
      durum: "AKTIF"
    },
    {
      id: 2,
      kod: "DPL",
      ad: "Depolama",
      departman_ids: [6],
      durum: "AKTIF"
    }
  ],
  aylikDurumMap: {
    "2026-04|1": {
      ay: "2026-04",
      personel_id: 1,
      bolum_onay_durumu: "BOLUM_ONAYINDA",
      revize_var_mi: false,
      son_islem: "Birim amiri gunluk kayitlari hazirladi",
      kapanis_durumu: "ACIK"
    },
    "2026-04|2": {
      ay: "2026-04",
      personel_id: 2,
      bolum_onay_durumu: "REVIZE_ISTENDI",
      revize_var_mi: true,
      son_islem: "Bolum yoneticisi duzeltme istedi",
      kapanis_durumu: "ACIK"
    }
  },
  nextIds: {
    personel: 100,
    surec: 600,
    zimmet: 560,
    bildirim: 800,
    finans: 950,
    kapanis: 1000,
    kullanici: 3,
    sube: 2,
    departman: 12
  }
};

const DEMO_PERSONEL_TIPI_LABELS: Record<number, string> = {
  1: "Tam Zamanlı",
  2: "Yarı Zamanlı"
};

const DEMO_BAGLI_AMIR_LABELS: Record<number, string> = {
  1: "Demo Amir",
  2: "Ikinci Amir"
};

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
}

function toStringValue(value: unknown): string | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  return trimmed || null;
}

function readBody(init?: RequestInit): Record<string, unknown> {
  if (!init?.body || typeof init.body !== "string") {
    return {};
  }

  try {
    const parsed = JSON.parse(init.body) as unknown;
    return toRecord(parsed) ?? {};
  } catch {
    return {};
  }
}

function ok<T>(data: T, meta: Record<string, unknown> = {}): ApiResponse<T> {
  return {
    data,
    meta,
    errors: []
  };
}

function parsePath(path: string): URL {
  const normalized = path.startsWith("/") ? path : `/${path}`;
  return new URL(normalized, "https://demo.local");
}

function getMethod(init?: RequestInit): DemoMethod {
  const method = (init?.method ?? "GET").toUpperCase();
  if (method === "POST" || method === "PUT" || method === "DELETE") {
    return method;
  }

  return "GET";
}

function resolveDemoRole(username: string) {
  const normalized = username.toLowerCase();
  if (normalized.includes("birim")) {
    return "BIRIM_AMIRI";
  }
  if (normalized.includes("muhasebe")) {
    return "MUHASEBE";
  }
  if (normalized.includes("bolum") || normalized.includes("bÃ¶lÃ¼m")) {
    return "BOLUM_YONETICISI";
  }

  return "GENEL_YONETICI";
}

type DemoPuantajBuildParams = {
  personelId: number;
  tarih: string;
  gunTipi: NonNullable<DemoPuantaj["gun_tipi"]>;
  hareketDurumu: NonNullable<DemoPuantaj["hareket_durumu"]>;
  dayanak?: DemoPuantaj["dayanak"];
  hesapEtkisi?: DemoPuantaj["hesap_etkisi"];
  girisSaati?: string;
  cikisSaati?: string;
  gercekMolaDakika?: number;
  hesaplananMolaDakika?: number;
  netCalismaSuresiDakika?: number;
  gunlukBrutSureDakika?: number;
  haftaTatiliHakKazandiMi?: boolean;
  state?: string;
  complianceUyarilari?: DemoPuantaj["compliance_uyarilari"];
};

const DEMO_PUANTAJ_GUN_TIPI_MAP: Record<string, NonNullable<DemoPuantaj["gun_tipi"]>> = {
  NORMAL_IS_GUNU: "Normal_Is_Gunu",
  HAFTA_TATILI_PAZAR: "Hafta_Tatili_Pazar",
  UBGT_RESMI_TATIL: "UBGT_Resmi_Tatil"
};

const DEMO_PUANTAJ_HAREKET_DURUMU_MAP: Record<string, NonNullable<DemoPuantaj["hareket_durumu"]>> = {
  GELDI: "Geldi",
  GELMEDI: "Gelmedi",
  GEC_GELDI: "Gec_Geldi",
  ERKEN_CIKTI: "Erken_Cikti"
};

const DEMO_PUANTAJ_DAYANAK_MAP: Record<string, NonNullable<DemoPuantaj["dayanak"]>> = {
  YOK_IZINSIZ: "Yok_Izinsiz",
  UCRETLI_IZINLI: "Ucretli_Izinli",
  RAPORLU_HASTALIK: "Raporlu_Hastalik",
  RAPORLU_IS_KAZASI: "Raporlu_Is_Kazasi",
  YILLIK_IZIN: "Yillik_Izin",
  TELAFI_CALISMASI: "Telafi_Calismasi"
};

const DEMO_PUANTAJ_HESAP_ETKISI_MAP: Record<string, NonNullable<DemoPuantaj["hesap_etkisi"]>> = {
  KESINTI_YAP: "Kesinti_Yap",
  TAM_YEVMIYE_VER: "Tam_Yevmiye_Ver",
  MESAI_YAZ: "Mesai_Yaz"
};

function normalizeDemoLiteralToken(value: unknown) {
  const stringValue = toStringValue(value);
  if (!stringValue) {
    return undefined;
  }

  return stringValue.replace(/[\s-]+/g, "_").toUpperCase();
}

function readDemoPuantajGunTipi(value: unknown): DemoPuantaj["gun_tipi"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_GUN_TIPI_MAP[token] : undefined;
}

function readDemoPuantajHareketDurumu(value: unknown): DemoPuantaj["hareket_durumu"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_HAREKET_DURUMU_MAP[token] : undefined;
}

function readDemoPuantajDayanak(value: unknown): DemoPuantaj["dayanak"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_DAYANAK_MAP[token] : undefined;
}

function readDemoPuantajHesapEtkisi(value: unknown): DemoPuantaj["hesap_etkisi"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_HESAP_ETKISI_MAP[token] : undefined;
}

function buildDemoPuantaj(params: DemoPuantajBuildParams): DemoPuantaj {
  return {
    personel_id: params.personelId,
    tarih: params.tarih,
    gun_tipi: params.gunTipi,
    hareket_durumu: params.hareketDurumu,
    dayanak: params.dayanak,
    hesap_etkisi: params.hesapEtkisi,
    giris_saati: params.girisSaati,
    cikis_saati: params.cikisSaati,
    gercek_mola_dakika: params.gercekMolaDakika,
    hesaplanan_mola_dakika: params.hesaplananMolaDakika,
    net_calisma_suresi_dakika: params.netCalismaSuresiDakika,
    gunluk_brut_sure_dakika: params.gunlukBrutSureDakika,
    hafta_tatili_hak_kazandi_mi: params.haftaTatiliHakKazandiMi,
    state: params.state ?? "HESAPLANDI",
    compliance_uyarilari: params.complianceUyarilari ?? []
  };
}

function defaultPuantaj(personelId: number, tarih: string): DemoPuantaj {
  return buildDemoPuantaj({
    personelId,
    tarih,
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Geldi",
    hesapEtkisi: "Tam_Yevmiye_Ver",
    girisSaati: "08:30",
    cikisSaati: "18:00",
    gercekMolaDakika: 60,
    hesaplananMolaDakika: 60,
    netCalismaSuresiDakika: 510,
    gunlukBrutSureDakika: 570,
    haftaTatiliHakKazandiMi: true
  });
}

function getLabel(map: Record<number, string>, id: number | undefined) {
  if (typeof id !== "number") {
    return undefined;
  }

  return map[id] ?? `#${id}`;
}

function getSubeLabel(id: number | undefined) {
  if (typeof id !== "number") {
    return undefined;
  }

  return demoState.subeler.find((item) => item.id === id)?.ad;
}

function getDepartmanLabel(id: number | undefined) {
  if (typeof id !== "number") {
    return undefined;
  }

  return demoState.departmanlar.find((item) => item.id === id)?.ad;
}

function monthKey(ay: string, personelId: number) {
  return `${ay}|${personelId}`;
}

function ensureAylikDurum(ay: string, personelId: number): DemoAylikDurum {
  const key = monthKey(ay, personelId);
  const existing = demoState.aylikDurumMap[key];
  if (existing) {
    return existing;
  }

  const created: DemoAylikDurum = {
    ay,
    personel_id: personelId,
    bolum_onay_durumu: "BOLUM_ONAYINDA",
    revize_var_mi: false,
    son_islem: "Birim amiri gunluk kayitlari hazirladi",
    kapanis_durumu: "ACIK"
  };
  demoState.aylikDurumMap[key] = created;
  return created;
}

function formatAylikIslemTimestamp() {
  return new Intl.DateTimeFormat("tr-TR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit"
  }).format(new Date());
}

function isTesvikKalemi(kalemTuru: string | undefined) {
  return ["PRIM", "BONUS", "IKRAMIYE", "EKSTRA_PRIM"].includes((kalemTuru ?? "").toUpperCase());
}

function isCezaKalemi(kalemTuru: string | undefined) {
  return (kalemTuru ?? "").toUpperCase() === "CEZA";
}

function summarizeBildirimForPersonel(ay: string, personelId: number) {
  const matching = demoState.bildirimler.filter(
    (item) => item.personel_id === personelId && (item.tarih ?? "").startsWith(`${ay}-`) && item.state !== "IPTAL"
  );

  let devamsizlikGun = 0;
  let gecKalmaAdet = 0;
  let izinliGelmedi = 0;
  let izinsizGelmedi = 0;
  let raporlu = 0;

  for (const item of matching) {
    switch ((item.bildirim_turu ?? "").toUpperCase()) {
      case "GEC_GELDI":
        gecKalmaAdet += 1;
        break;
      case "IZINLI_GELMEDI":
        izinliGelmedi += 1;
        break;
      case "IZINSIZ_GELMEDI":
        izinsizGelmedi += 1;
        devamsizlikGun += 1;
        break;
      case "DEVAMSIZLIK":
      case "GELMEDI":
        devamsizlikGun += 1;
        break;
      case "RAPORLU":
        raporlu += 1;
        break;
      default:
        break;
    }
  }

  return {
    devamsizlikGun,
    gecKalmaAdet,
    izinliGelmedi,
    izinsizGelmedi,
    raporlu
  };
}

function summarizeFinansForPersonel(ay: string, personelId: number) {
  const matching = demoState.finansKalemleri.filter(
    (item) => item.personel_id === personelId && item.donem === ay && item.state !== "IPTAL"
  );

  return matching.reduce(
    (acc, item) => {
      if (isTesvikKalemi(item.kalem_turu)) {
        acc.tesvikTutari += item.tutar;
      }
      if (isCezaKalemi(item.kalem_turu)) {
        acc.cezaKesintiTutari += item.tutar;
      }
      return acc;
    },
    { tesvikTutari: 0, cezaKesintiTutari: 0 }
  );
}

function buildAylikOzetResponse(ay: string, subeId?: number | null, departmanId?: number | null, sadeceRevizeli = false) {
  const items = demoState.personeller
    .filter((personel) => {
      if (subeId !== null && subeId !== undefined && personel.sube_id !== subeId) {
        return false;
      }
      if (departmanId !== null && departmanId !== undefined && personel.departman_id !== departmanId) {
        return false;
      }
      return personel.aktif_durum === "AKTIF";
    })
    .map((personel) => {
      const bildirimOzet = summarizeBildirimForPersonel(ay, personel.id);
      const finansOzet = summarizeFinansForPersonel(ay, personel.id);
      const durum = ensureAylikDurum(ay, personel.id);

      return {
        personel_id: personel.id,
        ad_soyad: `${personel.ad} ${personel.soyad}`,
        sicil_no: personel.sicil_no,
        sube: getSubeLabel(personel.sube_id) ?? "-",
        bolum: getDepartmanLabel(personel.departman_id) ?? "-",
        bagli_amir_adi: getLabel(DEMO_BAGLI_AMIR_LABELS, personel.bagli_amir_id) ?? "-",
        devamsizlik_gun: bildirimOzet.devamsizlikGun,
        gec_kalma_adet: bildirimOzet.gecKalmaAdet,
        izinli_gelmedi: bildirimOzet.izinliGelmedi,
        izinsiz_gelmedi: bildirimOzet.izinsizGelmedi,
        raporlu: bildirimOzet.raporlu,
        tesvik_tutari: finansOzet.tesvikTutari,
        ceza_kesinti_tutari: finansOzet.cezaKesintiTutari,
        bolum_onay_durumu: durum.bolum_onay_durumu,
        revize_var_mi: durum.revize_var_mi,
        son_islem: durum.son_islem,
        kapanis_durumu: durum.kapanis_durumu
      };
    })
    .filter((item) => (sadeceRevizeli ? item.revize_var_mi : true));

  const pendingBolumOnayi = items.filter((item) => item.bolum_onay_durumu === "BOLUM_ONAYINDA").length;

  const state = (() => {
    if (items.length === 0) {
      return "BOLUM_ONAYINDA";
    }
    if (items.every((item) => item.kapanis_durumu === "KAPANDI")) {
      return "KAPANDI";
    }
    if (items.some((item) => item.bolum_onay_durumu === "REVIZE_ISTENDI")) {
      return "REVIZE_ISTENDI";
    }
    if (pendingBolumOnayi === 0) {
      return "BOLUM_ONAYLANDI";
    }
    return "BOLUM_ONAYINDA";
  })();

  return {
    ay,
    state,
    summary: {
      toplam_personel: items.length,
      toplam_devamsizlik_gun: items.reduce((acc, item) => acc + item.devamsizlik_gun, 0),
      toplam_gec_kalma: items.reduce((acc, item) => acc + item.gec_kalma_adet, 0),
      toplam_izinli_gelmedi: items.reduce((acc, item) => acc + item.izinli_gelmedi, 0),
      toplam_izinsiz_gelmedi: items.reduce((acc, item) => acc + item.izinsiz_gelmedi, 0),
      toplam_raporlu: items.reduce((acc, item) => acc + item.raporlu, 0),
      toplam_tesvik_tutari: items.reduce((acc, item) => acc + item.tesvik_tutari, 0),
      toplam_ceza_kesinti_tutari: items.reduce((acc, item) => acc + item.ceza_kesinti_tutari, 0)
    },
    items,
    pending_bolum_onayi: pendingBolumOnayi
  };
}

function getDemoPuantajRowsForPersonel(personelId: number) {
  return Object.values(demoState.puantajMap).filter((kayit) => kayit.personel_id === personelId);
}

function buildDemoPersonelDetail(personel: DemoPersonel) {
  const sgkOzeti = hesaplaAylikSgkPuantajOzetleri(getDemoPuantajRowsForPersonel(personel.id))[0] ?? null;

  return {
    ana_kart: { ...personel },
    sistem_ozeti: {
      hizmet_suresi: personel.id === 1 ? "3 yil 2 ay" : "1 yil 8 ay",
      toplam_izin_hakki: personel.id === 1 ? 14 : 10,
      kullanilan_izin: personel.id === 1 ? 4 : 2,
      kalan_izin: personel.id === 1 ? 10 : 8,
      sgk_donem: sgkOzeti?.donem,
      sgk_prim_gun: sgkOzeti?.sgk_prim_gun,
      sgk_eksik_gun_sayisi: sgkOzeti?.eksik_gun_sayisi,
      sgk_eksik_gun_nedeni_kodu: sgkOzeti?.eksik_gun_nedeni_kodu ?? null,
      sgk_ayin_takvim_gun_sayisi: sgkOzeti?.ayin_takvim_gun_sayisi,
      sgk_hesaplama_modu: sgkOzeti?.hesaplama_modu
    },
    pasiflik_durumu: {
      aktif_durum: personel.aktif_durum,
      etiket: personel.aktif_durum === "PASIF" ? "Isten Ayrildi" : null
    },
    referans_adlari: {
      sube: getSubeLabel(personel.sube_id),
      departman: getDepartmanLabel(personel.departman_id),
      gorev: getLabel(DEMO_GOREV_LABELS, personel.gorev_id),
      personel_tipi: getLabel(DEMO_PERSONEL_TIPI_LABELS, personel.personel_tipi_id),
      bagli_amir: getLabel(DEMO_BAGLI_AMIR_LABELS, personel.bagli_amir_id)
    }
  };
}

function buildDemoIsgMakineListResponse(searchUrl: URL) {
  const page = toNumber(searchUrl.searchParams.get("page")) ?? 1;
  const limit = toNumber(searchUrl.searchParams.get("limit")) ?? 10;
  const search = (toStringValue(searchUrl.searchParams.get("search")) ?? "").toLowerCase();
  const durum = (toStringValue(searchUrl.searchParams.get("durum")) ?? "tum").toLowerCase();
  const tip = (toStringValue(searchUrl.searchParams.get("tip")) ?? "").toLowerCase();
  const subeId = toNumber(searchUrl.searchParams.get("sube_id"));

  const filtered = demoState.makineler.filter((item) => {
    if (subeId !== null && item.sube_id !== subeId) {
      return false;
    }
    if (durum !== "tum" && item.durum !== durum) {
      return false;
    }
    if (tip && !item.tip.toLowerCase().includes(tip)) {
      return false;
    }
    if (!search) {
      return true;
    }

    const fullText = `${item.ad} ${item.tip} ${item.konum ?? ""}`.toLowerCase();
    return fullText.includes(search);
  });

  const start = (page - 1) * limit;
  const items = filtered.slice(start, start + limit).map((item) => ({
    ...item,
    referans_adlari: {
      sube: getSubeLabel(item.sube_id) ?? "-"
    }
  }));
  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / limit));

  return ok(
    {
      items
    },
    {
      page,
      limit,
      total,
      total_pages: totalPages
    }
  );
}

function findDemoMakineByScope(makineId: number, subeId: number | null) {
  const makine = demoState.makineler.find((item) => item.id === makineId);
  if (!makine) {
    return null;
  }
  if (subeId !== null && makine.sube_id !== subeId) {
    return null;
  }
  return makine;
}

function buildDemoIsgMakineDetailResponse(searchUrl: URL, makineId: number) {
  const subeId = toNumber(searchUrl.searchParams.get("sube_id"));
  const makine = findDemoMakineByScope(makineId, subeId);
  if (!makine) {
    return null;
  }

  return ok({
    ...makine,
    referans_adlari: {
      sube: getSubeLabel(makine.sube_id) ?? "-"
    }
  });
}

function buildDemoIsgMakineBakimResponse(searchUrl: URL, makineId: number) {
  const subeId = toNumber(searchUrl.searchParams.get("sube_id"));
  const page = toNumber(searchUrl.searchParams.get("page")) ?? 1;
  const limit = toNumber(searchUrl.searchParams.get("limit")) ?? 10;
  const makine = findDemoMakineByScope(makineId, subeId);
  if (!makine) {
    return null;
  }

  const filtered = demoState.bakimKayitlari.filter((item) => item.makine_id === makineId);
  const start = (page - 1) * limit;
  const items = filtered.slice(start, start + limit);
  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / limit));

  return ok(
    {
      items
    },
    {
      page,
      limit,
      total,
      total_pages: totalPages
    }
  );
}

export function resolveDemoApiResponse(
  path: string,
  init?: RequestInit
): ApiResponse<unknown> | null {
  const requestUrl = parsePath(path);
  const pathname = requestUrl.pathname;
  const method = getMethod(init);
  const body = readBody(init);

  if (pathname === "/auth/login" && method === "POST") {
    const username = toStringValue(body.username) ?? "demo";
    const role = resolveDemoRole(username);
    const profile = role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim";
    const sube_ids =
      role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [2] : [];
    const sube_list =
      sube_ids.length > 0
      ? sube_ids.map((id) => ({ id, ad: id === 1 ? "Merkez" : `Åube ${id}` }))
        : undefined;

    return ok({
      token: "demo-token",
      ui_profile: profile,
      sube_list,
      user: {
        id: username.length + 1,
        ad_soyad: username,
        rol: role,
        sube_ids
      }
    });
  }

  if (pathname === "/isg/makineler" && method === "GET") {
    return buildDemoIsgMakineListResponse(requestUrl);
  }

  const isgMakineDetailMatch = pathname.match(/^\/isg\/makineler\/(\d+)$/);
  if (isgMakineDetailMatch && method === "GET") {
    return buildDemoIsgMakineDetailResponse(requestUrl, Number.parseInt(isgMakineDetailMatch[1], 10));
  }

  const isgMakineBakimMatch = pathname.match(/^\/isg\/makineler\/(\d+)\/bakimlar$/);
  if (isgMakineBakimMatch && method === "GET") {
    return buildDemoIsgMakineBakimResponse(requestUrl, Number.parseInt(isgMakineBakimMatch[1], 10));
  }

  if (pathname === "/personeller" && method === "GET") {
    const page = toNumber(requestUrl.searchParams.get("page")) ?? 1;
    const limit = toNumber(requestUrl.searchParams.get("limit")) ?? 10;
    const aktiflik = toStringValue(requestUrl.searchParams.get("aktiflik")) ?? "tum";
    const search = (toStringValue(requestUrl.searchParams.get("search")) ?? "").toLowerCase();
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const departmanId = toNumber(requestUrl.searchParams.get("departman_id"));
    const personelTipiId = toNumber(requestUrl.searchParams.get("personel_tipi_id"));

    const filtered = demoState.personeller.filter((item) => {
      if (aktiflik === "aktif" && item.aktif_durum !== "AKTIF") {
        return false;
      }
      if (aktiflik === "pasif" && item.aktif_durum !== "PASIF") {
        return false;
      }
      if (subeId !== null && item.sube_id !== subeId) {
        return false;
      }
      if (departmanId !== null && item.departman_id !== departmanId) {
        return false;
      }
      if (personelTipiId !== null && item.personel_tipi_id !== personelTipiId) {
        return false;
      }
      if (!search) {
        return true;
      }

      const fullText = `${item.ad} ${item.soyad} ${item.tc_kimlik_no}`.toLowerCase();
      return fullText.includes(search);
    });

    const start = (page - 1) * limit;
    const items = filtered.slice(start, start + limit).map((item) => ({
      ...item,
      sube_adi: getSubeLabel(item.sube_id),
      departman_adi: getDepartmanLabel(item.departman_id),
      gorev_adi: getLabel(DEMO_GOREV_LABELS, item.gorev_id),
      personel_tipi_adi: getLabel(DEMO_PERSONEL_TIPI_LABELS, item.personel_tipi_id),
      bagli_amir_adi: getLabel(DEMO_BAGLI_AMIR_LABELS, item.bagli_amir_id)
    }));
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    return ok(
      {
        items
      },
      {
        page,
        limit,
        total,
        total_pages: totalPages
      }
    );
  }

  if (pathname === "/personeller" && method === "POST") {
    const next: DemoPersonel = {
      id: ++demoState.nextIds.personel,
      tc_kimlik_no: toStringValue(body.tc_kimlik_no) ?? "00000000000",
      ad: toStringValue(body.ad) ?? "Yeni",
      soyad: toStringValue(body.soyad) ?? "Personel",
      aktif_durum: (toStringValue(body.aktif_durum) as "AKTIF" | "PASIF") ?? "AKTIF",
      sube_id: toNumber(body.sube_id) ?? (toNumber(body.departman_id) === 2 ? 2 : 1),
      telefon: toStringValue(body.telefon) ?? undefined,
      dogum_tarihi: toStringValue(body.dogum_tarihi) ?? undefined,
      sicil_no: toStringValue(body.sicil_no) ?? undefined,
      dogum_yeri: toStringValue(body.dogum_yeri) ?? undefined,
      kan_grubu: toStringValue(body.kan_grubu) ?? undefined,
      ise_giris_tarihi: toStringValue(body.ise_giris_tarihi) ?? undefined,
      acil_durum_kisi: toStringValue(body.acil_durum_kisi) ?? undefined,
      acil_durum_telefon: toStringValue(body.acil_durum_telefon) ?? undefined,
      departman_id: toNumber(body.departman_id) ?? undefined,
      gorev_id: toNumber(body.gorev_id) ?? undefined,
      personel_tipi_id: toNumber(body.personel_tipi_id) ?? undefined,
      bagli_amir_id: toNumber(body.bagli_amir_id) ?? undefined
    };
    demoState.personeller.unshift(next);
    return ok(next);
  }

  const personelDetailMatch = pathname.match(/^\/personeller\/(\d+)$/);
  if (personelDetailMatch) {
    const id = Number.parseInt(personelDetailMatch[1], 10);
    const personel = demoState.personeller.find((item) => item.id === id);
    if (!personel) {
      return null;
    }

    if (method === "GET") {
      return ok(buildDemoPersonelDetail(personel));
    }

    if (method === "PUT") {
      Object.assign(personel, body);
      return ok(personel);
    }
  }

  if (pathname === "/surecler" && method === "GET") {
    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const surecTuru = toStringValue(requestUrl.searchParams.get("surec_turu"));
    const state = toStringValue(requestUrl.searchParams.get("state"));
    const baslangicTarihi = toStringValue(requestUrl.searchParams.get("baslangic_tarihi"));
    const bitisTarihi = toStringValue(requestUrl.searchParams.get("bitis_tarihi"));
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));

    const filtered = demoState.surecler.filter((item) => {
      if (personelId !== null && item.personel_id !== personelId) {
        return false;
      }

      if (surecTuru && item.surec_turu !== surecTuru) {
        return false;
      }

      if (state && item.state !== state) {
        return false;
      }

      if (baslangicTarihi && item.baslangic_tarihi !== baslangicTarihi) {
        return false;
      }

      if (bitisTarihi && item.bitis_tarihi !== bitisTarihi) {
        return false;
      }

      if (subeId !== null) {
        const linkedPersonel = demoState.personeller.find((personel) => personel.id === item.personel_id);
        if (!linkedPersonel || linkedPersonel.sube_id !== subeId) {
          return false;
        }
      }

      return true;
    });

    return ok({ items: filtered });
  }

  if (pathname === "/surecler" && method === "POST") {
    const next: DemoSurec = {
      id: ++demoState.nextIds.surec,
      personel_id: toNumber(body.personel_id) ?? 1,
      surec_turu: toStringValue(body.surec_turu) ?? "IZIN",
      alt_tur: toStringValue(body.alt_tur) ?? undefined,
      baslangic_tarihi: toStringValue(body.baslangic_tarihi) ?? undefined,
      bitis_tarihi: toStringValue(body.bitis_tarihi) ?? undefined,
      ucretli_mi: body.ucretli_mi === undefined ? true : Boolean(body.ucretli_mi),
      aciklama: toStringValue(body.aciklama) ?? undefined,
      state: "AKTIF"
    };
    demoState.surecler.unshift(next);

    if (next.surec_turu === "ISTEN_AYRILMA") {
      const targetPersonel = demoState.personeller.find((personel) => personel.id === next.personel_id);
      if (targetPersonel) {
        targetPersonel.aktif_durum = "PASIF";
      }
    }

    return ok(next);
  }

  if (pathname === "/zimmetler" && method === "GET") {
    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const zimmetDurumu = toStringValue(requestUrl.searchParams.get("zimmet_durumu"));
    const page = toNumber(requestUrl.searchParams.get("page")) ?? 1;
    const limit = toNumber(requestUrl.searchParams.get("limit")) ?? 10;

    const filtered = demoState.zimmetler.filter((item) => {
      if (personelId !== null && item.personel_id !== personelId) {
        return false;
      }
      if (zimmetDurumu && item.zimmet_durumu !== zimmetDurumu) {
        return false;
      }
      if (subeId !== null) {
        const linkedPersonel = demoState.personeller.find((personel) => personel.id === item.personel_id);
        if (!linkedPersonel || linkedPersonel.sube_id !== subeId) {
          return false;
        }
      }
      return true;
    });

    const start = (page - 1) * limit;
    const items = filtered.slice(start, start + limit);
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    return ok(
      { items },
      {
        page,
        limit,
        total,
        total_pages: totalPages
      }
    );
  }

  if (pathname === "/zimmetler" && method === "POST") {
    const next: DemoZimmet = {
      id: ++demoState.nextIds.zimmet,
      personel_id: toNumber(body.personel_id) ?? 1,
      urun_turu: toStringValue(body.urun_turu) ?? "DIGER",
      teslim_tarihi: toStringValue(body.teslim_tarihi) ?? new Date().toISOString().slice(0, 10),
      teslim_eden: toStringValue(body.teslim_eden) ?? undefined,
      aciklama: toStringValue(body.aciklama) ?? undefined,
      teslim_durumu: toStringValue(body.teslim_durumu) ?? "YENI",
      zimmet_durumu: "AKTIF"
    };
    demoState.zimmetler.unshift(next);
    return ok(next);
  }

  const surecDetailMatch = pathname.match(/^\/surecler\/(\d+)$/);
  if (surecDetailMatch) {
    const id = Number.parseInt(surecDetailMatch[1], 10);
    const surec = demoState.surecler.find((item) => item.id === id);
    if (!surec) {
      return null;
    }

    if (method === "GET") {
      return ok(surec);
    }

    if (method === "PUT") {
      Object.assign(surec, body);
      return ok(surec);
    }
  }

  const surecCancelMatch = pathname.match(/^\/surecler\/(\d+)\/iptal$/);
  if (surecCancelMatch && method === "POST") {
    const id = Number.parseInt(surecCancelMatch[1], 10);
    const surec = demoState.surecler.find((item) => item.id === id);
    if (!surec) {
      return null;
    }

    surec.state = "IPTAL";
    return ok({ id: surec.id, state: surec.state });
  }

  if (pathname === "/bildirimler" && method === "GET") {
    const page = toNumber(requestUrl.searchParams.get("page")) ?? 1;
    const limit = toNumber(requestUrl.searchParams.get("limit")) ?? 10;
    const tarih = toStringValue(requestUrl.searchParams.get("tarih"));
    const departmanId = toNumber(requestUrl.searchParams.get("departman_id"));
    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const bildirimTuru = toStringValue(requestUrl.searchParams.get("bildirim_turu"));

    const filtered = demoState.bildirimler.filter((item) => {
      if (tarih && item.tarih !== tarih) {
        return false;
      }
      if (departmanId !== null && item.departman_id !== departmanId) {
        return false;
      }
      if (personelId !== null && item.personel_id !== personelId) {
        return false;
      }
      if (bildirimTuru && item.bildirim_turu !== bildirimTuru) {
        return false;
      }
      return true;
    });

    const start = (page - 1) * limit;
    const items = filtered.slice(start, start + limit);
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    return ok(
      { items },
      {
        page,
        limit,
        total,
        total_pages: totalPages
      }
    );
  }

  if (pathname === "/bildirimler" && method === "POST") {
    const next: DemoBildirim = {
      id: ++demoState.nextIds.bildirim,
      tarih: toStringValue(body.tarih) ?? undefined,
      departman_id: toNumber(body.departman_id) ?? undefined,
      personel_id: toNumber(body.personel_id) ?? undefined,
      bildirim_turu: toStringValue(body.bildirim_turu) ?? "GEC_GELDI",
      aciklama: toStringValue(body.aciklama) ?? undefined,
      state: "AKTIF",
      okundu_mi: false
    };
    demoState.bildirimler.unshift(next);
    return ok(next);
  }

  const bildirimDetailMatch = pathname.match(/^\/bildirimler\/(\d+)$/);
  if (bildirimDetailMatch) {
    const id = Number.parseInt(bildirimDetailMatch[1], 10);
    const bildirim = demoState.bildirimler.find((item) => item.id === id);
    if (!bildirim) {
      return null;
    }

    if (method === "GET") {
      return ok(bildirim);
    }

    if (method === "PUT") {
      Object.assign(bildirim, body);
      return ok(bildirim);
    }
  }

  const bildirimCancelMatch = pathname.match(/^\/bildirimler\/(\d+)\/iptal$/);
  if (bildirimCancelMatch && method === "POST") {
    const id = Number.parseInt(bildirimCancelMatch[1], 10);
    const bildirim = demoState.bildirimler.find((item) => item.id === id);
    if (!bildirim) {
      return null;
    }

    bildirim.state = "IPTAL";
    return ok({ id: bildirim.id, state: bildirim.state });
  }

  const puantajMatch = pathname.match(/^\/gunluk-puantaj\/(\d+)\/([^/]+)$/);
  if (puantajMatch) {
    const personelId = Number.parseInt(puantajMatch[1], 10);
    const tarih = decodeURIComponent(puantajMatch[2]);
    const key = `${personelId}|${tarih}`;
    const existing = demoState.puantajMap[key] ?? defaultPuantaj(personelId, tarih);

    if (method === "GET") {
      demoState.puantajMap[key] = existing;
      return ok(existing);
    }

    if (method === "PUT") {
      const updated: DemoPuantaj = {
        ...existing,
        gun_tipi: readDemoPuantajGunTipi(body.gun_tipi) ?? existing.gun_tipi,
        hareket_durumu: readDemoPuantajHareketDurumu(body.hareket_durumu) ?? existing.hareket_durumu,
        dayanak: readDemoPuantajDayanak(body.dayanak) ?? existing.dayanak,
        hesap_etkisi: readDemoPuantajHesapEtkisi(body.hesap_etkisi) ?? existing.hesap_etkisi,
        giris_saati: toStringValue(body.giris_saati) ?? existing.giris_saati,
        cikis_saati: toStringValue(body.cikis_saati) ?? existing.cikis_saati,
        gercek_mola_dakika: toNumber(body.gercek_mola_dakika) ?? existing.gercek_mola_dakika
      };
      demoState.puantajMap[key] = updated;
      return ok(updated);
    }
  }

  if (pathname === "/puantaj/muhurle" && method === "POST") {
    const yil = toNumber(body.yil) ?? new Date().getFullYear();
    const ay = toNumber(body.ay) ?? new Date().getMonth() + 1;
    const donemPrefix = `${yil}-${String(ay).padStart(2, "0")}`;
    let count = 0;
    for (const [key, entry] of Object.entries(demoState.puantajMap)) {
      if (key.includes(`|${donemPrefix}`) && entry.state !== "MUHURLENDI") {
        entry.state = "MUHURLENDI";
        count++;
      }
    }
    return ok({ muhurlenen_kayit_sayisi: count, donem: donemPrefix });
  }

  if (pathname === "/haftalik-kapanis" && method === "POST") {
    return ok({
      id: ++demoState.nextIds.kapanis,
      hafta_baslangic: toStringValue(body.hafta_baslangic) ?? "2026-04-06",
      hafta_bitis: toStringValue(body.hafta_bitis) ?? "2026-04-12",
      departman_id: toNumber(body.departman_id) ?? 3,
      state: "KAPANDI",
      personel_sayisi: demoState.personeller.length
    });
  }

  if (pathname === "/yonetim/kullanicilar" && method === "GET") {
    return ok({
      items: demoState.yonetimKullanicilari.map((item) => ({
        ...item,
        personel_ad_soyad:
          item.personel_id != null
            ? demoState.personeller
                .filter((personel) => personel.id === item.personel_id)
                .map((personel) => `${personel.ad} ${personel.soyad}`)[0] ?? null
            : null
      }))
    });
  }

  if (pathname === "/yonetim/kullanicilar" && method === "POST") {
    const personelId = toNumber(body.personel_id);
    const linkedPersonel =
      personelId !== null ? demoState.personeller.find((personel) => personel.id === personelId) ?? null : null;
    const next: DemoYonetimKullanici = {
      id: ++demoState.nextIds.kullanici,
      ad_soyad:
        toStringValue(body.ad_soyad) ??
        (linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : "Yeni Kullanici"),
      telefon: toStringValue(body.telefon) ?? linkedPersonel?.telefon ?? undefined,
      kullanici_tipi: body.kullanici_tipi === "HARICI" ? "HARICI" : "IC_PERSONEL",
      rol:
        body.rol === "GENEL_YONETICI" ||
        body.rol === "BOLUM_YONETICISI" ||
        body.rol === "MUHASEBE" ||
        body.rol === "BIRIM_AMIRI"
          ? body.rol
          : "BIRIM_AMIRI",
      personel_id: personelId,
      sube_ids: Array.isArray(body.sube_ids)
        ? body.sube_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : [],
      varsayilan_sube_id: toNumber(body.varsayilan_sube_id),
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF",
      notlar: toStringValue(body.notlar) ?? undefined
    };
    demoState.yonetimKullanicilari.unshift(next);
    return ok({
      ...next,
      personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
    });
  }

  const yonetimKullaniciMatch = pathname.match(/^\/yonetim\/kullanicilar\/(\d+)$/);
  if (yonetimKullaniciMatch && method === "PUT") {
    const id = Number.parseInt(yonetimKullaniciMatch[1], 10);
    const target = demoState.yonetimKullanicilari.find((item) => item.id === id);
    if (!target) {
      return null;
    }

    const personelId = toNumber(body.personel_id);
    const linkedPersonel =
      personelId !== null ? demoState.personeller.find((personel) => personel.id === personelId) ?? null : null;
    Object.assign(target, {
      ad_soyad:
        toStringValue(body.ad_soyad) ??
        (linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : target.ad_soyad),
      telefon: toStringValue(body.telefon) ?? linkedPersonel?.telefon ?? target.telefon,
      kullanici_tipi: body.kullanici_tipi === "HARICI" ? "HARICI" : "IC_PERSONEL",
      rol:
        body.rol === "GENEL_YONETICI" ||
        body.rol === "BOLUM_YONETICISI" ||
        body.rol === "MUHASEBE" ||
        body.rol === "BIRIM_AMIRI"
          ? body.rol
          : target.rol,
      personel_id: personelId,
      sube_ids: Array.isArray(body.sube_ids)
        ? body.sube_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : target.sube_ids,
      varsayilan_sube_id: toNumber(body.varsayilan_sube_id),
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF",
      notlar: toStringValue(body.notlar) ?? undefined
    });
    return ok({
      ...target,
      personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
    });
  }

  if (pathname === "/yonetim/subeler" && method === "GET") {
    return ok({
      items: demoState.subeler.map((item) => ({
        ...item,
        departman_adlari: item.departman_ids
          .map((departmanId) => getDepartmanLabel(departmanId))
          .filter((label): label is string => typeof label === "string")
      }))
    });
  }

  if (pathname === "/yonetim/subeler" && method === "POST") {
    const next: DemoSube = {
      id: ++demoState.nextIds.sube,
      kod: toStringValue(body.kod) ?? `SBE-${demoState.nextIds.sube}`,
      ad: toStringValue(body.ad) ?? "Yeni Sube",
      departman_ids: Array.isArray(body.departman_ids)
        ? body.departman_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : [],
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF"
    };
    demoState.subeler.unshift(next);
    return ok({
      ...next,
      departman_adlari: next.departman_ids
        .map((departmanId) => getDepartmanLabel(departmanId))
        .filter((label): label is string => typeof label === "string")
    });
  }

  const yonetimSubeMatch = pathname.match(/^\/yonetim\/subeler\/(\d+)$/);
  if (yonetimSubeMatch && method === "PUT") {
    const id = Number.parseInt(yonetimSubeMatch[1], 10);
    const target = demoState.subeler.find((item) => item.id === id);
    if (!target) {
      return null;
    }

    Object.assign(target, {
      kod: toStringValue(body.kod) ?? target.kod,
      ad: toStringValue(body.ad) ?? target.ad,
      departman_ids: Array.isArray(body.departman_ids)
        ? body.departman_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : target.departman_ids,
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF"
    });
    return ok({
      ...target,
      departman_adlari: target.departman_ids
        .map((departmanId) => getDepartmanLabel(departmanId))
        .filter((label): label is string => typeof label === "string")
    });
  }

  if (pathname === "/yonetim/aylik-ozet" && method === "GET") {
    const ay = toStringValue(requestUrl.searchParams.get("ay")) ?? "2026-04";
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const departmanId = toNumber(requestUrl.searchParams.get("departman_id"));
    const sadeceRevizeli = toStringValue(requestUrl.searchParams.get("sadece_revizeli")) === "true";
    return ok(buildAylikOzetResponse(ay, subeId, departmanId, sadeceRevizeli));
  }

  if (pathname === "/yonetim/aylik-ozet/bolum-onay" && method === "POST") {
    const ay = toStringValue(body.ay) ?? "2026-04";
    const subeId = toNumber(body.sube_id);
    const departmanId = toNumber(body.departman_id);
    const sadeceRevizeli = Boolean(body.sadece_revizeli);

    demoState.personeller.forEach((personel) => {
      if (personel.aktif_durum !== "AKTIF") {
        return;
      }
      if (subeId !== null && personel.sube_id !== subeId) {
        return;
      }
      if (departmanId !== null && personel.departman_id !== departmanId) {
        return;
      }

      const durum = ensureAylikDurum(ay, personel.id);
      if (durum.kapanis_durumu === "KAPANDI") {
        return;
      }
      durum.bolum_onay_durumu = "BOLUM_ONAYLANDI";
      durum.revize_var_mi = false;
      durum.son_islem = `Bolum yoneticisi onay verdi (${formatAylikIslemTimestamp()})`;
    });

    return ok(buildAylikOzetResponse(ay, subeId, departmanId, sadeceRevizeli));
  }

  if (pathname === "/yonetim/aylik-ozet/ay-kapat" && method === "POST") {
    const ay = toStringValue(body.ay) ?? "2026-04";
    const subeId = toNumber(body.sube_id);
    const departmanId = toNumber(body.departman_id);
    const sadeceRevizeli = Boolean(body.sadece_revizeli);

    demoState.personeller.forEach((personel) => {
      if (personel.aktif_durum !== "AKTIF") {
        return;
      }
      if (subeId !== null && personel.sube_id !== subeId) {
        return;
      }
      if (departmanId !== null && personel.departman_id !== departmanId) {
        return;
      }

      const durum = ensureAylikDurum(ay, personel.id);
      durum.kapanis_durumu = "KAPANDI";
      durum.son_islem = `Genel yonetici ust onay verdi (${formatAylikIslemTimestamp()})`;
    });

    return ok(buildAylikOzetResponse(ay, subeId, departmanId, sadeceRevizeli));
  }

  if (pathname === "/referans/departmanlar" && method === "POST") {
    const ad = toStringValue(body.ad);
    if (!ad) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "DEPARTMAN_NAME_REQUIRED",
            message: "Departman adÄ± zorunludur."
          }
        ]
      };
    }

    const normalized = ad.toLocaleLowerCase("tr-TR");
    const existing = demoState.departmanlar.find((item) => item.ad.toLocaleLowerCase("tr-TR") === normalized);
    if (existing) {
      return ok(existing);
    }

    const created: DemoDepartman = {
      id: ++demoState.nextIds.departman,
      ad
    };
    demoState.departmanlar.push(created);
    return ok(created);
  }

  if (pathname.startsWith("/referans/") && method === "GET") {
    if (pathname === "/referans/departmanlar") {
      return ok(demoState.departmanlar);
    }

    if (pathname === "/referans/gorevler") {
      return ok(DEMO_GOREVLER);
    }

    if (pathname === "/referans/personel-tipleri") {
      return ok([
        { id: 1, ad: "Tam Zamanlı" },
        { id: 2, ad: "Yarı Zamanlı" }
      ]);
    }

    if (pathname === "/referans/surec-turleri") {
      return ok([
        { key: "IZIN", label: "Ä°zin" },
        { key: "RAPOR", label: "Rapor" },
        { key: "IS_KAZASI", label: "Ä°ÅŸ KazasÄ±" },
        { key: "DEVAMSIZLIK", label: "DevamsÄ±zlÄ±k" },
        { key: "ISTEN_AYRILMA", label: "Ä°ÅŸten AyrÄ±lma" }
      ]);
    }

    if (pathname === "/referans/bildirim-turleri") {
      return ok([
        { key: "GEC_GELDI", label: "GeÃ§ Geldi" },
        { key: "GELMEDI", label: "Gelmedi" },
        { key: "IZINLI_GELMEDI", label: "Ä°zinli Gelmedi" },
        { key: "IZINSIZ_GELMEDI", label: "Ä°zinsiz Gelmedi" },
        { key: "DEVAMSIZLIK", label: "DevamsÄ±zlÄ±k" },
        { key: "RAPORLU", label: "Raporlu" }
      ]);
    }

    if (pathname === "/referans/bagli-amirler") {
      return ok([
        { id: 1, ad: "Demo Amir" },
        { id: 2, ad: "Ikinci Amir" }
      ]);
    }

    if (pathname === "/referans/ucret-tipleri") {
      return ok([
        { id: 1, ad: "Maktu Aylık" },
        { id: 2, ad: "Saatlik" }
      ]);
    }

    if (pathname === "/referans/prim-kurallari") {
      return ok([
        { id: 7, ad: "7 No'lu Prim Kuralı" },
        { id: 8, ad: "8 No'lu Prim Kuralı" }
      ]);
    }

    return ok([]);
  }

  if (pathname.startsWith("/raporlar/") && method === "GET") {
    const personel = demoState.personeller[0];
    const sgkOzeti =
      personel != null ? hesaplaAylikSgkPuantajOzetleri(getDemoPuantajRowsForPersonel(personel.id))[0] ?? null : null;

    return ok(
      {
        items: [
          {
            personel_id: personel?.id ?? 1,
            ad_soyad: personel != null ? `${personel.ad} ${personel.soyad}` : "Ayse Yilmaz",
            rapor_tipi: pathname.replace("/raporlar/", ""),
            net_calisma_dakika: 510,
            sgk_donem: sgkOzeti?.donem ?? "2026-04",
            sgk_prim_gun: sgkOzeti?.sgk_prim_gun ?? 30,
            eksik_gun_nedeni_kodu: sgkOzeti?.eksik_gun_nedeni_kodu ?? "-"
          }
        ]
      },
      { total: 1 }
    );
  }

  if (pathname === "/ek-odeme-kesinti" && method === "GET") {
    return ok({ items: demoState.finansKalemleri });
  }

  if (pathname === "/ek-odeme-kesinti" && method === "POST") {
    const next: DemoFinansKalem = {
      id: ++demoState.nextIds.finans,
      personel_id: toNumber(body.personel_id) ?? 1,
      donem: toStringValue(body.donem) ?? "2026-04",
      kalem_turu: toStringValue(body.kalem_turu) ?? "AVANS",
      tutar: toNumber(body.tutar) ?? 0,
      aciklama: toStringValue(body.aciklama) ?? undefined,
      state: "AKTIF"
    };
    demoState.finansKalemleri.unshift(next);
    return ok(next);
  }

  const finansDetailMatch = pathname.match(/^\/ek-odeme-kesinti\/(\d+)$/);
  if (finansDetailMatch && method === "PUT") {
    const id = Number.parseInt(finansDetailMatch[1], 10);
    const finans = demoState.finansKalemleri.find((item) => item.id === id);
    if (!finans) {
      return null;
    }

    Object.assign(finans, body);
    return ok(finans);
  }

  const finansCancelMatch = pathname.match(/^\/ek-odeme-kesinti\/(\d+)\/iptal$/);
  if (finansCancelMatch && method === "POST") {
    const id = Number.parseInt(finansCancelMatch[1], 10);
    const finans = demoState.finansKalemleri.find((item) => item.id === id);
    if (!finans) {
      return null;
    }

    finans.state = "IPTAL";
    return ok({ id: finans.id, state: finans.state });
  }

  return null;
}

