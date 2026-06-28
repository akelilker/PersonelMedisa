import type { Page, Route } from "@playwright/test";
import type { GunlukPuantaj } from "../../../src/types/puantaj";
import { hesaplaAylikSgkPuantajOzetleri } from "../../../src/services/dashboard-rapor-servisi";
import {
  SUBE_DELETE_BLOCKED_ERROR_CODE,
  SUBE_DELETE_BLOCKED_MESSAGE
} from "../../../src/lib/yonetim/sube-delete";
import {
  computeGecerlilikDurumu,
  PERSONEL_BELGE_KAYIT_TIPI_KEYS,
  type PersonelBelgeKayitDurum,
  type PersonelBelgeKayitTipi
} from "../../../src/types/personel-belge-kaydi";

export type MockUserRole = "GENEL_YONETICI" | "BOLUM_YONETICISI" | "MUHASEBE" | "BIRIM_AMIRI";

function okBody(data: unknown) {
  return JSON.stringify({
    data,
    meta: {},
    errors: []
  });
}

type RaporListMeta = {
  page?: number;
  limit?: number;
  total?: number;
  total_pages?: number;
  has_next_page?: boolean;
  has_prev_page?: boolean;
};

function raporListOkBody(items: Record<string, unknown>[], metaOverrides?: RaporListMeta) {
  const page = metaOverrides?.page ?? 1;
  const limit = metaOverrides?.limit ?? 10;
  const total = metaOverrides?.total ?? items.length;
  const totalPages = metaOverrides?.total_pages ?? Math.max(1, Math.ceil(total / limit));

  return JSON.stringify({
    data: { items },
    meta: {
      page,
      limit,
      total,
      total_pages: totalPages,
      has_next_page: metaOverrides?.has_next_page ?? false,
      ...(metaOverrides?.has_prev_page !== undefined ? { has_prev_page: metaOverrides.has_prev_page } : {})
    },
    errors: []
  });
}

const PERSONEL_OZET_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    sicil_no: "S-001",
    aktif_durum: "AKTIF",
    departman_id: 3,
    net_calisma_dakika: 510,
    sgk_prim_gun: 30
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    sicil_no: "S-002",
    aktif_durum: "AKTIF",
    departman_id: 6,
    net_calisma_dakika: 480,
    sgk_prim_gun: 28
  }
];

function personelOzetPaginatedBody(pageNumber: number, pageLimit: number, departmanId?: number) {
  if (departmanId === undefined) {
    const total = PERSONEL_OZET_PAGINATED_ITEMS.length;
    const totalPages = 2;
    const items =
      pageNumber === 2
        ? [PERSONEL_OZET_PAGINATED_ITEMS[1]]
        : pageNumber === 1
          ? [PERSONEL_OZET_PAGINATED_ITEMS[0]]
          : [];

    return raporListOkBody(items, {
      page: pageNumber,
      limit: pageLimit,
      total,
      total_pages: totalPages,
      has_next_page: pageNumber < totalPages,
      has_prev_page: pageNumber > 1
    });
  }

  const filtered = PERSONEL_OZET_PAGINATED_ITEMS.filter((item) => item.departman_id === departmanId);
  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1
  });
}

const RAPOR_MOCK_ITEMS: Record<string, Record<string, unknown>[]> = {
  "/api/raporlar/personel-ozet": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      sicil_no: "S-001",
      aktif_durum: "AKTIF",
      net_calisma_dakika: 510,
      sgk_prim_gun: 30
    }
  ],
  "/api/raporlar/izin": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-05",
      alt_tur: "YILLIK_IZIN",
      ucretli_mi: true,
      state: "AKTIF"
    }
  ],
  "/api/raporlar/devamsizlik": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-10",
      alt_tur: "IZINSIZ",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/tesvik": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      donem: "2026-04",
      gun_sayisi: 22,
      toplam_tutar: 1500,
      state: "AKTIF"
    }
  ],
  "/api/raporlar/ceza": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      donem: "2026-04",
      tutar: 500,
      aciklama: "Gec kalma",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/ekstra-prim": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      donem: "2026-04",
      tutar: 800,
      aciklama: "Performans primi",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/is-kazasi": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      baslangic_tarihi: "2026-03-15",
      bitis_tarihi: "2026-03-20",
      aciklama: "Hafif yaralanma",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/bildirim": [
    {
      tarih: "2026-04-11",
      departman_id: 3,
      personel_id: 2,
      ad_soyad: "Mehmet Kaya",
      bildirim_turu: "IZINSIZ_GELMEDI",
      aciklama: "Habersiz devamsizlik",
      state: "AKTIF"
    }
  ]
};

function errorBody(code: string, message: string) {
  return JSON.stringify({
    data: null,
    meta: {},
    errors: [{ code, message }]
  });
}

async function fulfillJson(route: Route, status: number, body: string) {
  await route.fulfill({
    status,
    contentType: "application/json",
    body
  });
}

export async function mockApi(page: Page, role: MockUserRole) {
  const bagliAmirReferanslari: Array<{ id: number; ad: string; sube_id: number; departman_id: number }> = [
    { id: 9, ad: "Demo Amir", sube_id: 1, departman_id: 3 },
    { id: 10, ad: "İkinci Amir", sube_id: 2, departman_id: 6 }
  ];

  const personeller: Array<{
    id: number;
    tc_kimlik_no: string;
    ad: string;
    soyad: string;
    aktif_durum: "AKTIF" | "PASIF";
    sube_id?: number;
    telefon?: string;
    dogum_tarihi?: string;
    dogum_yeri?: string;
    kan_grubu?: string;
    sicil_no?: string;
    ise_giris_tarihi?: string;
    acil_durum_kisi?: string;
    acil_durum_telefon?: string;
    departman_id?: number;
    gorev_id?: number;
    personel_tipi_id?: number;
    bagli_amir_id?: number;
    sube_adi?: string;
    departman_adi?: string;
    gorev_adi?: string;
    personel_tipi_adi?: string;
    bagli_amir_adi?: string;
    ucret_tipi_id?: number;
    ucret_tipi_adi?: string;
    maas_tutari?: number;
    prim_kurali_id?: number;
    prim_kurali_adi?: string;
  }> = [
    {
      id: 1,
      tc_kimlik_no: "12345678901",
      ad: "Ayşe",
      soyad: "Yılmaz",
      aktif_durum: "AKTIF",
      sube_id: 1,
      telefon: "05550000000",
      dogum_tarihi: "1992-03-14",
      dogum_yeri: "İstanbul",
      kan_grubu: "A Rh+",
      sicil_no: "P-001",
      ise_giris_tarihi: "2023-02-01",
      acil_durum_kisi: "Fatma Yılmaz",
      acil_durum_telefon: "05553334455",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 9,
      sube_adi: "Merkez",
      departman_adi: "Döşeme",
      gorev_adi: "Genel Müdür",
      personel_tipi_adi: "Tam Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 1,
      ucret_tipi_adi: "Aylık",
      maas_tutari: 35000,
      prim_kurali_id: 7,
      prim_kurali_adi: "7 No'lu Prim Kuralı"
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
      dogum_yeri: "Ankara",
      kan_grubu: "0 Rh+",
      sicil_no: "P-002",
      ise_giris_tarihi: "2024-07-15",
      acil_durum_kisi: "Zeynep Kaya",
      acil_durum_telefon: "05556667788",
      departman_id: 6,
      gorev_id: 2,
      personel_tipi_id: 2,
      bagli_amir_id: 9,
      sube_adi: "Depolama",
      departman_adi: "Depo",
      gorev_adi: "Üretim Müdürü",
      personel_tipi_adi: "Yarı Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 2,
      ucret_tipi_adi: "Saatlik",
      maas_tutari: 25000,
      prim_kurali_id: 8,
      prim_kurali_adi: "8 No'lu Prim Kuralı"
    },
    {
      id: 3,
      tc_kimlik_no: "34567890123",
      ad: "Pasif",
      soyad: "Ornek",
      aktif_durum: "PASIF",
      sube_id: 1,
      telefon: "05552222222",
      dogum_tarihi: "1990-01-01",
      dogum_yeri: "İzmir",
      kan_grubu: "B Rh+",
      sicil_no: "P-003",
      ise_giris_tarihi: "2022-01-10",
      acil_durum_kisi: "Yakın Kişi",
      acil_durum_telefon: "05559998877",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 9,
      sube_adi: "Merkez",
      departman_adi: "Döşeme",
      gorev_adi: "Genel Müdür",
      personel_tipi_adi: "Tam Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 1,
      ucret_tipi_adi: "Aylık",
      maas_tutari: 30000,
      prim_kurali_id: 7,
      prim_kurali_adi: "7 No'lu Prim Kuralı"
    },
    {
      id: 4,
      tc_kimlik_no: "45678901234",
      ad: "Maas",
      soyad: "Eksik",
      aktif_durum: "AKTIF",
      sube_id: 1,
      telefon: "05323334444",
      dogum_tarihi: "1988-08-08",
      dogum_yeri: "Bursa",
      kan_grubu: "AB Rh+",
      sicil_no: "P-004",
      ise_giris_tarihi: "2025-01-01",
      acil_durum_kisi: "Acil Kisi",
      acil_durum_telefon: "05325556677",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 9,
      sube_adi: "Merkez",
      departman_adi: "Döşeme",
      gorev_adi: "Genel Müdür",
      personel_tipi_adi: "Tam Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 1,
      ucret_tipi_adi: "Aylık"
    }
  ];

  const surecler: Array<{
    id: number;
    personel_id: number;
    surec_turu: string;
    alt_tur?: string;
    baslangic_tarihi: string;
    bitis_tarihi?: string;
    effective_date?: string;
    created_at?: string;
    ucretli_mi?: boolean;
    aciklama?: string;
    state: string;
  }> = [
    {
      id: 501,
      personel_id: 1,
      surec_turu: "IZIN",
      alt_tur: "YILLIK_IZIN",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-11",
      effective_date: "2026-04-10",
      created_at: "2026-04-10T10:00:00.000Z",
      ucretli_mi: true,
      aciklama: "Mevcut surec",
      state: "AKTIF"
    },
    {
      id: 502,
      personel_id: 1,
      surec_turu: "DEVAMSIZLIK",
      baslangic_tarihi: "2026-03-08",
      aciklama: "Demo devamsizlik sinyali",
      state: "AKTIF"
    }
  ];

  const DEMO_BELGE_TURLERI = ["KIMLIK", "ADRES_BEYANI", "IS_GIRIS_EVRAKLARI", "BANKA_IBAN"] as const;
  const belgeDurumByPersonelId = new Map<number, Record<string, "VAR" | "YOK">>();

  const zimmetler: Array<{
    id: number;
    personel_id: number;
    urun_turu: string;
    teslim_tarihi: string;
    teslim_eden?: string;
    aciklama?: string;
    teslim_durumu: string;
    zimmet_durumu: string;
    iade_tarihi?: string;
  }> = [
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
      teslim_eden: "Bağlı Amir",
      aciklama: "Önceki vardiyadan teslim alındı",
      teslim_durumu: "IKINCI_EL",
      zimmet_durumu: "IADE_EDILDI",
      iade_tarihi: "2026-02-20"
    }
  ];

  const personelBelgeKayitlari: Array<{
    id: number;
    personel_id: number;
    kayit_tipi: PersonelBelgeKayitTipi;
    ad: string;
    veren_kurum?: string | null;
    belge_no?: string | null;
    baslangic_tarihi?: string | null;
    bitis_tarihi?: string | null;
    durum: PersonelBelgeKayitDurum;
    ek_ref?: string | null;
    aciklama?: string | null;
    created_at?: string;
    updated_at?: string;
  }> = [
    {
      id: 901,
      personel_id: 1,
      kayit_tipi: "SERTIFIKA",
      ad: "Forklift Operatör Belgesi",
      veren_kurum: "Medisa Eğitim Merkezi",
      belge_no: "FRK-2024-001",
      baslangic_tarihi: "2024-03-01",
      bitis_tarihi: "2027-03-01",
      durum: "AKTIF",
      created_at: "2024-03-01T10:00:00.000Z"
    },
    {
      id: 902,
      personel_id: 1,
      kayit_tipi: "EHLIYET",
      ad: "B Sınıfı Ehliyet",
      veren_kurum: "İstanbul İl Emniyet",
      belge_no: "TR-987654",
      baslangic_tarihi: "2018-05-10",
      bitis_tarihi: "2026-07-15",
      durum: "AKTIF",
      created_at: "2018-05-10T10:00:00.000Z"
    }
  ];

  const bildirimler: Array<{
    id: number;
    tarih: string;
    departman_id: number;
    personel_id: number;
    bildirim_turu: string;
    aciklama?: string;
    state: string;
    okundu_mi?: boolean;
  }> = [
    {
      id: 701,
      tarih: "2026-04-09",
      departman_id: 3,
      personel_id: 1,
      bildirim_turu: "GEC_GELDI",
      aciklama: "Mevcut bildirim",
      state: "AKTIF",
      okundu_mi: false
    }
  ];

  const finansKalemleri: Array<{
    id: number;
    personel_id: number;
    donem: string;
    kalem_turu: string;
    tutar: number;
    aciklama?: string;
    state: string;
  }> = [
    {
      id: 901,
      personel_id: 1,
      donem: "2026-04",
      kalem_turu: "AVANS",
      tutar: 2500,
      aciklama: "Mevcut finans kalemi",
      state: "AKTIF"
    }
  ];

  const puantajKayitlari: GunlukPuantaj[] = [
    {
      personel_id: 1,
      tarih: "2026-04-09",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Geldi",
      hesap_etkisi: "Tam_Yevmiye_Ver",
      giris_saati: "08:30",
      cikis_saati: "18:00",
      gercek_mola_dakika: 60,
      hesaplanan_mola_dakika: 60,
      net_calisma_suresi_dakika: 510,
      gunluk_brut_sure_dakika: 570,
      hafta_tatili_hak_kazandi_mi: true,
      state: "HESAPLANDI",
      compliance_uyarilari: []
    },
    {
      personel_id: 1,
      tarih: "2026-04-10",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Gec_Geldi",
      dayanak: "Ucretli_Izinli",
      hesap_etkisi: "Tam_Yevmiye_Ver",
      giris_saati: "09:15",
      cikis_saati: "18:00",
      gercek_mola_dakika: 60,
      hesaplanan_mola_dakika: 60,
      net_calisma_suresi_dakika: 465,
      gunluk_brut_sure_dakika: 525,
      hafta_tatili_hak_kazandi_mi: true,
      state: "HESAPLANDI",
      compliance_uyarilari: []
    },
    {
      personel_id: 2,
      tarih: "2026-04-09",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Gelmedi",
      dayanak: "Yok_Izinsiz",
      hesap_etkisi: "Kesinti_Yap",
      hafta_tatili_hak_kazandi_mi: false,
      state: "HESAPLANDI",
      compliance_uyarilari: []
    }
  ];

  const departmanOptions: Array<{ id: number; ad: string }> = [
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

  const ucretTipiReferans: Array<{ id: number; ad: string }> = [
    { id: 1, ad: "Aylık" },
    { id: 2, ad: "Saatlik" }
  ];

  const primKuraliReferans: Array<{ id: number; ad: string }> = [
    { id: 7, ad: "7 No'lu Prim Kuralı" },
    { id: 8, ad: "8 No'lu Prim Kuralı" }
  ];

  const subeler: Array<{
    id: number;
    kod: string;
    ad: string;
    departman_ids: number[];
    departman_adlari: string[];
    durum: "AKTIF" | "PASIF";
  }> = [
    {
      id: 1,
      kod: "MRK",
      ad: "Merkez",
      departman_ids: [1, 3],
      departman_adlari: ["Muhasebe", "Döşeme"],
      durum: "AKTIF"
    },
    {
      id: 2,
      kod: "DPL",
      ad: "Depolama",
      departman_ids: [1],
      departman_adlari: ["Depo"],
      durum: "AKTIF"
    },
    {
      id: 99,
      kod: "PSF",
      ad: "Pasif Şube",
      departman_ids: [1],
      departman_adlari: ["Muhasebe"],
      durum: "PASIF"
    }
  ];

  const makineler: Array<{
    id: number;
    ad: string;
    tip: string;
    konum?: string | null;
    durum: "aktif" | "arizali" | "pasif";
    sube_id: number;
    son_bakim?: string | null;
    bakim_periyot_gun?: number | null;
  }> = [
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
  ];

  const bakimKayitlari: Array<{
    id: number;
    makine_id: number;
    bakim_tarihi?: string | null;
    yapan?: string | null;
    notlar?: string | null;
  }> = [
    {
      id: 2101,
      makine_id: 1101,
      bakim_tarihi: "2026-03-01",
      yapan: "Bakim Ekibi A",
      notlar: "Aylık kontrol"
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
  ];

  const yonetimKullanicilari: Array<{
    id: number;
    ad_soyad: string;
    telefon?: string;
    kullanici_tipi: "IC_PERSONEL" | "HARICI";
    rol: MockUserRole;
    personel_id: number | null;
    sube_ids: number[];
    varsayilan_sube_id: number | null;
    durum: "AKTIF" | "PASIF";
    notlar?: string;
  }> = [
    {
      id: 1,
      ad_soyad: "İlker Akel",
      telefon: "05550000001",
      kullanici_tipi: "HARICI",
      rol: "GENEL_YONETICI",
      personel_id: null,
      sube_ids: [],
      varsayilan_sube_id: null,
      durum: "AKTIF",
      notlar: "Tüm yapıyı yönetir"
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
      notlar: "Depolama kapsamında bölüm onayı verir"
    },
    {
      id: 3,
      ad_soyad: "Serhan Köse",
      telefon: "05550000003",
      kullanici_tipi: "IC_PERSONEL",
      rol: "BIRIM_AMIRI",
      personel_id: 1,
      sube_ids: [1],
      varsayilan_sube_id: 1,
      durum: "AKTIF",
      notlar: "Günlük kayıtları girer"
    }
  ];

  /** Playwright süreci ile tarayıcı aynı makinede; UI ilk yüklemede `new Date()` ayını kullanır. */
  const aylikOzetFixtureAy = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, "0")}`;

  const aylikOzetRows: Array<{
    ay: string;
    personel_id: number;
    ad_soyad: string;
    sicil_no?: string;
    sube_id: number;
    sube: string;
    departman_id: number;
    bolum: string;
    bagli_amir_adi: string;
    devamsizlik_gun: number;
    gec_kalma_adet: number;
    izinli_gelmedi: number;
    izinsiz_gelmedi: number;
    raporlu: number;
    tesvik_tutari: number;
    ceza_kesinti_tutari: number;
    bolum_onay_durumu: "BOLUM_ONAYINDA" | "BOLUM_ONAYLANDI" | "REVIZE_ISTENDI";
    revize_var_mi: boolean;
    son_islem: string;
    kapanis_durumu: "ACIK" | "KAPANDI";
  }> = [
    {
      ay: aylikOzetFixtureAy,
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      sicil_no: "P-001",
      sube_id: 1,
      sube: "Merkez",
      departman_id: 3,
      bolum: "Döşeme",
      bagli_amir_adi: "Serhan Köse",
      devamsizlik_gun: 0,
      gec_kalma_adet: 1,
      izinli_gelmedi: 0,
      izinsiz_gelmedi: 0,
      raporlu: 0,
      tesvik_tutari: 1200,
      ceza_kesinti_tutari: 0,
      bolum_onay_durumu: "BOLUM_ONAYINDA",
      revize_var_mi: false,
      son_islem: "Bağlı amir günlük kayıtları hazırladı",
      kapanis_durumu: "ACIK"
    },
    {
      ay: aylikOzetFixtureAy,
      personel_id: 2,
      ad_soyad: "Mehmet Kaya",
      sicil_no: "P-002",
      sube_id: 2,
      sube: "Depolama",
      departman_id: 6,
      bolum: "Depo",
      bagli_amir_adi: "Serhan Köse",
      devamsizlik_gun: 1,
      gec_kalma_adet: 0,
      izinli_gelmedi: 1,
      izinsiz_gelmedi: 1,
      raporlu: 0,
      tesvik_tutari: 0,
      ceza_kesinti_tutari: 450,
      bolum_onay_durumu: "REVIZE_ISTENDI",
      revize_var_mi: true,
      son_islem: "Bölüm yöneticisi revize istedi",
      kapanis_durumu: "ACIK"
    }
  ];

let surecIdCounter = 600;
let zimmetIdCounter = 560;
let personelBelgeKaydiIdCounter = 903;
let bildirimIdCounter = 800;
  let finansIdCounter = 950;
  let kullaniciIdCounter = 3;
  let subeIdCounter = 2;
  let departmanIdCounter = 12;
  let personelIdCounter = 4;

  function serializePersonelBelgeKaydi(record: (typeof personelBelgeKayitlari)[number]) {
    const bitisTarihi = record.bitis_tarihi ?? null;
    return {
      id: record.id,
      personel_id: record.personel_id,
      kayit_tipi: record.kayit_tipi,
      ad: record.ad,
      veren_kurum: record.veren_kurum ?? null,
      belge_no: record.belge_no ?? null,
      baslangic_tarihi: record.baslangic_tarihi ?? null,
      bitis_tarihi: bitisTarihi,
      durum: record.durum,
      gecerlilik_durumu: computeGecerlilikDurumu(bitisTarihi),
      ek_ref: record.ek_ref ?? null,
      aciklama: record.aciklama ?? null,
      created_at: record.created_at ?? null,
      updated_at: record.updated_at ?? null
    };
  }

  function getDepartmanLabel(id: number) {
    return departmanOptions.find((item) => item.id === id)?.ad ?? `Departman ${id}`;
  }

  function getSubeLabel(id: number | undefined) {
    return subeler.find((item) => item.id === id)?.ad;
  }

  function getGorevLabel(id: number | undefined) {
    return gorevAdlari.find((item) => item.id === id)?.ad;
  }

  function normalizeSubePayload(payload: { kod: string; ad: string; departman_ids?: number[]; durum: "AKTIF" | "PASIF" }) {
    const departmanIds = payload.departman_ids ?? [];
    return {
      kod: payload.kod,
      ad: payload.ad,
      departman_ids: departmanIds,
      departman_adlari: departmanIds.map((id) => getDepartmanLabel(id)),
      durum: payload.durum
    };
  }

  function getPuantajRowsForPersonel(personelId: number) {
    return puantajKayitlari.filter((kayit) => kayit.personel_id === personelId);
  }

  function buildPersonelDetail(personel: (typeof personeller)[number]) {
    const sgkOzeti = hesaplaAylikSgkPuantajOzetleri(getPuantajRowsForPersonel(personel.id))[0] ?? null;

    return {
      ana_kart: {
        id: personel.id,
        tc_kimlik_no: personel.tc_kimlik_no,
        ad: personel.ad,
        soyad: personel.soyad,
        aktif_durum: personel.aktif_durum,
        sube_id: personel.sube_id,
        telefon: personel.telefon,
        dogum_tarihi: personel.dogum_tarihi,
        dogum_yeri: personel.dogum_yeri,
        kan_grubu: personel.kan_grubu,
        sicil_no: personel.sicil_no,
        ise_giris_tarihi: personel.ise_giris_tarihi,
        acil_durum_kisi: personel.acil_durum_kisi,
        acil_durum_telefon: personel.acil_durum_telefon,
        departman_id: personel.departman_id,
        gorev_id: personel.gorev_id,
        personel_tipi_id: personel.personel_tipi_id,
        bagli_amir_id: personel.bagli_amir_id,
        ucret_tipi_id: personel.ucret_tipi_id,
        ucret_tipi_adi: personel.ucret_tipi_adi,
        maas_tutari: personel.maas_tutari,
        prim_kurali_id: personel.prim_kurali_id,
        prim_kurali_adi: personel.prim_kurali_adi
      },
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
        etiket: personel.aktif_durum === "PASIF" ? "İşten Ayrıldı" : null
      },
      referans_adlari: {
        sube: personel.sube_adi,
        departman: personel.departman_adi,
        gorev: personel.gorev_adi,
        personel_tipi: personel.personel_tipi_adi,
        bagli_amir: personel.bagli_amir_adi
      }
    };
  }

  const gorevAdlari: Array<{ id: number; ad: string }> = [
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

  function normalizeLifecycleSnapshot(p: (typeof personeller)[number]) {
    const n = (v: number | undefined | null) =>
      v === undefined || v === null || !Number.isFinite(v) ? null : v;
    const nm = (v: number | undefined | null) =>
      v === undefined || v === null || !Number.isFinite(v) ? null : v;
    return {
      departman_id: n(p.departman_id),
      gorev_id: n(p.gorev_id),
      bagli_amir_id: n(p.bagli_amir_id),
      ucret_tipi_id: n(p.ucret_tipi_id),
      maas_tutari: nm(p.maas_tutari),
      prim_kurali_id: n(p.prim_kurali_id)
    };
  }

  function lifecycleSnapshotsEqual(
    a: ReturnType<typeof normalizeLifecycleSnapshot>,
    b: ReturnType<typeof normalizeLifecycleSnapshot>
  ) {
    return (
      a.departman_id === b.departman_id &&
      a.gorev_id === b.gorev_id &&
      a.bagli_amir_id === b.bagli_amir_id &&
      a.ucret_tipi_id === b.ucret_tipi_id &&
      a.maas_tutari === b.maas_tutari &&
      a.prim_kurali_id === b.prim_kurali_id
    );
  }

  function mergePersonelFromPutPayload(
    base: (typeof personeller)[number],
    payload: Record<string, unknown>
  ) {
    const next: (typeof personeller)[number] = { ...base };
    if (typeof payload.ad === "string") next.ad = payload.ad.trim();
    if (typeof payload.soyad === "string") next.soyad = payload.soyad.trim();
    if (typeof payload.telefon === "string") next.telefon = payload.telefon.trim();

    const setId = (key: "departman_id" | "gorev_id" | "bagli_amir_id" | "prim_kurali_id" | "ucret_tipi_id") => {
      if (!(key in payload)) return;
      const v = payload[key];
      if (v === null) {
        next[key] = undefined;
        return;
      }
      if (typeof v === "number" && Number.isFinite(v)) {
        next[key] = v;
        return;
      }
      if (typeof v === "string" && v.trim()) {
        const parsed = Number.parseInt(v.trim(), 10);
        if (Number.isFinite(parsed)) next[key] = parsed;
      }
    };
    setId("departman_id");
    setId("gorev_id");
    setId("bagli_amir_id");
    setId("prim_kurali_id");
    setId("ucret_tipi_id");
    if ("maas_tutari" in payload) {
      const v = payload.maas_tutari;
      if (v === null || v === undefined) next.maas_tutari = undefined;
      else next.maas_tutari = typeof v === "number" ? v : Number.parseFloat(String(v));
    }
    return next;
  }

  function syncPersonelReferansAdlari(target: (typeof personeller)[number]) {
    if (target.departman_id !== undefined) {
      target.departman_adi = getDepartmanLabel(target.departman_id);
    }
    if (target.gorev_id !== undefined) {
      target.gorev_adi = gorevAdlari.find((g) => g.id === target.gorev_id)?.ad ?? target.gorev_adi;
    }
    if (target.ucret_tipi_id !== undefined) {
      target.ucret_tipi_adi =
        ucretTipiReferans.find((x) => x.id === target.ucret_tipi_id)?.ad ?? target.ucret_tipi_adi;
    }
    if (target.prim_kurali_id !== undefined) {
      target.prim_kurali_adi =
        primKuraliReferans.find((x) => x.id === target.prim_kurali_id)?.ad ?? target.prim_kurali_adi;
    }
    if (target.bagli_amir_id !== undefined) {
      target.bagli_amir_adi =
        bagliAmirReferanslari.find((item) => item.id === target.bagli_amir_id)?.ad ?? target.bagli_amir_adi;
    }
  }

  function buildAylikOzetResponse(searchUrl: URL) {
    const ay = urlValue(searchUrl.searchParams.get("ay")) ?? aylikOzetFixtureAy;
    const subeId = numberValue(searchUrl.searchParams.get("sube_id"));
    const departmanId = numberValue(searchUrl.searchParams.get("departman_id"));
    const sadeceRevizeli = searchUrl.searchParams.get("sadece_revizeli") === "true";

    const items = aylikOzetRows.filter((item) => {
      if (item.ay !== ay) {
        return false;
      }
      if (Number.isFinite(subeId) && item.sube_id !== subeId) {
        return false;
      }
      if (Number.isFinite(departmanId) && item.departman_id !== departmanId) {
        return false;
      }
      if (sadeceRevizeli && !item.revize_var_mi) {
        return false;
      }
      return true;
    });

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

  function urlValue(value: string | null) {
    return value && value.trim() ? value : null;
  }

  function numberValue(value: string | null) {
    const parsed = Number.parseInt(value ?? "", 10);
    return Number.isFinite(parsed) ? parsed : null;
  }

  await page.route(
    (testUrl) => {
      try {
        return new URL(testUrl).pathname.startsWith("/api/");
      } catch {
        return false;
      }
    },
    async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const method = request.method();

    if (path === "/api/auth/login" && method === "POST") {
      const subeIds =
        role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [2] : [];
      await fulfillJson(
        route,
        200,
        okBody({
          token: "mock-token",
          ui_profile: role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim",
          sube_list: subeIds.map((id) => ({ id, ad: subeler.find((item) => item.id === id)?.ad ?? `Şube ${id}` })),
          user: {
            id: 1,
            ad_soyad: "Mock Kullanıcı",
            rol: role,
            sube_ids: subeIds
          }
        })
      );
      return;
    }

    if (path === "/api/personeller" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const search = (url.searchParams.get("search") ?? "").toLowerCase();
      const departmanId = Number.parseInt(url.searchParams.get("departman_id") ?? "", 10);
      const personelTipiId = Number.parseInt(url.searchParams.get("personel_tipi_id") ?? "", 10);
      const aktiflik = url.searchParams.get("aktiflik") ?? "tum";

      const filtered = personeller.filter((item) => {
        if (aktiflik === "aktif" && item.aktif_durum !== "AKTIF") {
          return false;
        }
        if (aktiflik === "pasif" && item.aktif_durum !== "PASIF") {
          return false;
        }
        if (Number.isFinite(departmanId) && item.departman_id !== departmanId) {
          return false;
        }
        if (Number.isFinite(personelTipiId) && item.personel_tipi_id !== personelTipiId) {
          return false;
        }
        if (!search) {
          return true;
        }

        const fullText = `${item.ad} ${item.soyad} ${item.tc_kimlik_no}`.toLowerCase();
        return fullText.includes(search);
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/personeller" && method === "POST") {
      const payload = request.postDataJSON() as Record<string, unknown>;
      const subeId =
        typeof payload.sube_id === "number"
          ? payload.sube_id
          : Number.parseInt(String(payload.sube_id ?? ""), 10);

      if (!Number.isFinite(subeId) || subeId <= 0) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Şube seçilmelidir."));
        return;
      }

      const parseId = (value: unknown) => {
        if (typeof value === "number" && Number.isFinite(value)) {
          return value;
        }
        if (typeof value === "string" && value.trim()) {
          const parsed = Number.parseInt(value, 10);
          if (Number.isFinite(parsed)) {
            return parsed;
          }
        }
        return undefined;
      };

      const parseMaas = (value: unknown) => {
        if (value === undefined || value === null || value === "") {
          return undefined;
        }
        if (typeof value === "number" && Number.isFinite(value)) {
          return value;
        }
        if (typeof value === "string" && value.trim()) {
          const parsed = Number.parseFloat(value.replace(",", "."));
          return Number.isFinite(parsed) ? parsed : undefined;
        }
        return undefined;
      };

      const departmanId = parseId(payload.departman_id);
      const gorevId = parseId(payload.gorev_id);
      const personelTipiId = parseId(payload.personel_tipi_id);
      const ucretTipiId = parseId(payload.ucret_tipi_id);
      const nextId = ++personelIdCounter;

      const created = {
        id: nextId,
        tc_kimlik_no: String(payload.tc_kimlik_no ?? ""),
        ad: String(payload.ad ?? "Yeni"),
        soyad: String(payload.soyad ?? "Personel"),
        aktif_durum: (payload.aktif_durum === "PASIF" ? "PASIF" : "AKTIF") as "AKTIF" | "PASIF",
        sube_id: subeId,
        telefon: typeof payload.telefon === "string" ? payload.telefon : undefined,
        dogum_tarihi: typeof payload.dogum_tarihi === "string" ? payload.dogum_tarihi : undefined,
        dogum_yeri: typeof payload.dogum_yeri === "string" ? payload.dogum_yeri : undefined,
        kan_grubu: typeof payload.kan_grubu === "string" ? payload.kan_grubu : undefined,
        sicil_no: typeof payload.sicil_no === "string" ? payload.sicil_no : undefined,
        ise_giris_tarihi: typeof payload.ise_giris_tarihi === "string" ? payload.ise_giris_tarihi : undefined,
        acil_durum_kisi: typeof payload.acil_durum_kisi === "string" ? payload.acil_durum_kisi : undefined,
        acil_durum_telefon:
          typeof payload.acil_durum_telefon === "string" ? payload.acil_durum_telefon : undefined,
        departman_id: departmanId,
        gorev_id: gorevId,
        personel_tipi_id: personelTipiId,
        bagli_amir_id: parseId(payload.bagli_amir_id),
        ucret_tipi_id: ucretTipiId,
        ucret_tipi_adi: ucretTipiId === 1 ? "Aylık" : ucretTipiId === 2 ? "Saatlik" : undefined,
        maas_tutari: parseMaas(payload.maas_tutari),
        sube_adi: getSubeLabel(subeId),
        departman_adi: departmanId ? getDepartmanLabel(departmanId) : undefined,
        gorev_adi: gorevId ? getGorevLabel(gorevId) : undefined,
        personel_tipi_adi:
          personelTipiId === 1 ? "Tam Zamanlı" : personelTipiId === 2 ? "Yarı Zamanlı" : undefined
      };

      personeller.unshift(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path === "/api/isg/makineler" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const search = (url.searchParams.get("search") ?? "").toLowerCase();
      const durum = (url.searchParams.get("durum") ?? "tum").toLowerCase();
      const tip = (url.searchParams.get("tip") ?? "").toLowerCase();
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);

      const filtered = makineler.filter((item) => {
        if (Number.isFinite(subeId) && item.sube_id !== subeId) {
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

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit).map((item) => ({
        ...item,
        referans_adlari: {
          sube: subeler.find((sube) => sube.id === item.sube_id)?.ad ?? "-"
        }
      }));

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path.match(/^\/api\/isg\/makineler\/\d+$/) && method === "GET") {
      const makineId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const makine = makineler.find((item) => item.id === makineId);

      if (!makine || (Number.isFinite(subeId) && makine.sube_id !== subeId)) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Makine bulunamadi."));
        return;
      }

      await fulfillJson(
        route,
        200,
        okBody({
          ...makine,
          referans_adlari: {
            sube: subeler.find((sube) => sube.id === makine.sube_id)?.ad ?? "-"
          }
        })
      );
      return;
    }

    if (path.match(/^\/api\/isg\/makineler\/\d+\/bakimlar$/) && method === "GET") {
      const makineId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const makine = makineler.find((item) => item.id === makineId);

      if (!makine || (Number.isFinite(subeId) && makine.sube_id !== subeId)) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bakim gecmisi bulunamadi."));
        return;
      }

      const filtered = bakimKayitlari.filter((item) => item.makine_id === makineId);
      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path.match(/^\/api\/personeller\/\d+$/) && method === "PUT") {
      const personelId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const personel = personeller.find((item) => item.id === personelId);
      if (!personel) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Record<string, unknown>;

      const hasLifecycleKeys =
        "departman_id" in payload ||
        "gorev_id" in payload ||
        "bagli_amir_id" in payload ||
        "ucret_tipi_id" in payload ||
        "maas_tutari" in payload ||
        "prim_kurali_id" in payload;

      if (!hasLifecycleKeys) {
        if (typeof payload.ad === "string") personel.ad = payload.ad.trim();
        if (typeof payload.soyad === "string") personel.soyad = payload.soyad.trim();
        if (typeof payload.telefon === "string") personel.telefon = payload.telefon.trim();
        await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
        return;
      }

      const merged = mergePersonelFromPutPayload(personel, payload);
      const beforeSnap = normalizeLifecycleSnapshot(personel);
      const afterSnap = normalizeLifecycleSnapshot(merged);
      const hasLifecycleDiff = !lifecycleSnapshotsEqual(beforeSnap, afterSnap);

      if (!hasLifecycleDiff) {
        personel.ad = merged.ad;
        personel.soyad = merged.soyad;
        personel.telefon = merged.telefon;
        await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
        return;
      }

      const effectiveRaw = payload.effective_date;
      const effective =
        typeof effectiveRaw === "string" && effectiveRaw.trim() ? effectiveRaw.trim() : "";
      if (!effective) {
        await fulfillJson(
          route,
          400,
          errorBody("VALIDATION_ERROR", "Gecerlilik tarihi zorunludur.")
        );
        return;
      }

      Object.assign(personel, merged);
      syncPersonelReferansAdlari(personel);

      const createdAt = new Date().toISOString();
      surecler.unshift({
        id: ++surecIdCounter,
        personel_id: personelId,
        surec_turu: "ORG_DEGISIKLIK",
        baslangic_tarihi: effective,
        effective_date: effective,
        created_at: createdAt,
        state: "TAMAMLANDI",
        aciklama: "Mock otomatik org gecmis kaydi"
      });

      await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
      return;
    }

    if (path.match(/^\/api\/personeller\/\d+$/) && method === "GET") {
      const personelId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const personel = personeller.find((item) => item.id === personelId);
      const bagliAmir = bagliAmirReferanslari.find((item) => item.id === personelId);

      if (!personel && !bagliAmir) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }

      if (!personel && bagliAmir) {
        await fulfillJson(
          route,
          200,
          okBody({
            ana_kart: {
              id: bagliAmir.id,
              tc_kimlik_no: "00000000000",
              ad: bagliAmir.ad,
              soyad: "",
              aktif_durum: "AKTIF",
              sube_id: bagliAmir.sube_id,
              departman_id: bagliAmir.departman_id
            },
            sistem_ozeti: {
              hizmet_suresi: "-",
              toplam_izin_hakki: 0,
              kullanilan_izin: 0,
              kalan_izin: 0
            },
            pasiflik_durumu: {
              aktif_durum: "AKTIF",
              etiket: null
            },
            referans_adlari: {
              sube: subeler.find((sube) => sube.id === bagliAmir.sube_id)?.ad ?? "-",
              departman: getDepartmanLabel(bagliAmir.departman_id),
              gorev: "Birim Amiri",
              personel_tipi: "-",
              bagli_amir: null
            }
          })
        );
        return;
      }

      await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
      return;
    }

    if (path === "/api/surecler" && method === "GET") {
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      const surecTuru = url.searchParams.get("surec_turu");
      const state = url.searchParams.get("state");
      const baslangicTarihi = url.searchParams.get("baslangic_tarihi");
      const bitisTarihi = url.searchParams.get("bitis_tarihi");
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);

      const filtered = surecler.filter((item) => {
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
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
        if (Number.isFinite(subeId)) {
          const linkedPersonel = personeller.find((personel) => personel.id === item.personel_id);
          if (!linkedPersonel || linkedPersonel.sube_id !== subeId) {
            return false;
          }
        }
        return true;
      });

      await fulfillJson(route, 200, okBody({ items: filtered }));
      return;
    }

    if (path === "/api/surecler" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        surec_turu: string;
        alt_tur?: string;
        baslangic_tarihi: string;
        bitis_tarihi?: string;
        ucretli_mi?: boolean;
        aciklama?: string;
      };

      const created = {
        id: ++surecIdCounter,
        personel_id: payload.personel_id,
        surec_turu: payload.surec_turu,
        alt_tur: payload.alt_tur,
        baslangic_tarihi: payload.baslangic_tarihi,
        bitis_tarihi: payload.bitis_tarihi,
        ucretli_mi: payload.ucretli_mi,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      surecler.unshift(created);

      if (created.surec_turu === "ISTEN_AYRILMA") {
        const targetPersonel = personeller.find((item) => item.id === created.personel_id);
        if (targetPersonel) {
          targetPersonel.aktif_durum = "PASIF";
        }
      }

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path === "/api/zimmetler" && method === "GET") {
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      const zimmetDurumu = url.searchParams.get("zimmet_durumu");
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);

      const filtered = zimmetler.filter((item) => {
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
          return false;
        }
        if (zimmetDurumu && item.zimmet_durumu !== zimmetDurumu) {
          return false;
        }
        if (Number.isFinite(subeId)) {
          const linkedPersonel = personeller.find((personel) => personel.id === item.personel_id);
          if (!linkedPersonel || linkedPersonel.sube_id !== subeId) {
            return false;
          }
        }
        return true;
      });

      await fulfillJson(route, 200, okBody({ items: filtered }));
      return;
    }

    if (path === "/api/zimmetler" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        urun_turu: string;
        teslim_tarihi: string;
        teslim_eden?: string;
        aciklama?: string;
        teslim_durumu: string;
      };

      const created = {
        id: ++zimmetIdCounter,
        personel_id: payload.personel_id,
        urun_turu: payload.urun_turu,
        teslim_tarihi: payload.teslim_tarihi,
        teslim_eden: payload.teslim_eden,
        aciklama: payload.aciklama,
        teslim_durumu: payload.teslim_durumu,
        zimmet_durumu: "AKTIF"
      };
      zimmetler.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    const belgeKayitlariListMatch = path.match(/^\/api\/personeller\/(\d+)\/belge-kayitlari$/);
    if (belgeKayitlariListMatch && method === "GET") {
      const pid = Number.parseInt(belgeKayitlariListMatch[1] ?? "0", 10);
      const p = personeller.find((x) => x.id === pid);
      if (!p) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }

      const stateFilter = url.searchParams.get("state");
      const filtered = personelBelgeKayitlari.filter((item) => {
        if (item.personel_id !== pid) {
          return false;
        }
        if (stateFilter && stateFilter !== "tum" && item.durum !== stateFilter) {
          return false;
        }
        return true;
      });

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items: filtered.map(serializePersonelBelgeKaydi) },
          meta: { page: 1, limit: 50, total: filtered.length, total_pages: 1 },
          errors: []
        })
      );
      return;
    }

    if (belgeKayitlariListMatch && method === "POST") {
      const pid = Number.parseInt(belgeKayitlariListMatch[1] ?? "0", 10);
      const p = personeller.find((x) => x.id === pid);
      if (!p) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as {
        kayit_tipi?: string;
        ad?: string;
        veren_kurum?: string | null;
        belge_no?: string | null;
        baslangic_tarihi?: string | null;
        bitis_tarihi?: string | null;
        ek_ref?: string | null;
        aciklama?: string | null;
      };

      const kayitTipi = payload.kayit_tipi?.trim();
      const ad = payload.ad?.trim();
      if (!kayitTipi || !(PERSONEL_BELGE_KAYIT_TIPI_KEYS as readonly string[]).includes(kayitTipi) || !ad) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "kayit_tipi ve ad zorunludur."));
        return;
      }

      const now = new Date().toISOString();
      const created = {
        id: ++personelBelgeKaydiIdCounter,
        personel_id: pid,
        kayit_tipi: kayitTipi as PersonelBelgeKayitTipi,
        ad,
        veren_kurum: payload.veren_kurum?.trim() || null,
        belge_no: payload.belge_no?.trim() || null,
        baslangic_tarihi: payload.baslangic_tarihi?.trim() || null,
        bitis_tarihi: payload.bitis_tarihi?.trim() || null,
        durum: "AKTIF" as const,
        ek_ref: payload.ek_ref?.trim() || null,
        aciklama: payload.aciklama?.trim() || null,
        created_at: now,
        updated_at: now
      };
      personelBelgeKayitlari.unshift(created);
      await fulfillJson(route, 200, okBody(serializePersonelBelgeKaydi(created)));
      return;
    }

    const belgeKayitCancelMatch = path.match(/^\/api\/belge-kayitlari\/(\d+)\/iptal$/);
    if (belgeKayitCancelMatch && method === "POST") {
      const id = Number.parseInt(belgeKayitCancelMatch[1] ?? "0", 10);
      const kayit = personelBelgeKayitlari.find((item) => item.id === id);
      if (!kayit) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Belge kaydi bulunamadi."));
        return;
      }
      kayit.durum = "IPTAL";
      kayit.updated_at = new Date().toISOString();
      await fulfillJson(route, 200, okBody(serializePersonelBelgeKaydi(kayit)));
      return;
    }

    const belgeDurumMatch = path.match(/^\/api\/personeller\/(\d+)\/belge-durumu$/);
    if (belgeDurumMatch && method === "GET") {
      const pid = Number.parseInt(belgeDurumMatch[1] ?? "0", 10);
      const p = personeller.find((x) => x.id === pid);
      if (!p) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      const stored = belgeDurumByPersonelId.get(pid) ?? {};
      const items = DEMO_BELGE_TURLERI.map((belge_turu) => ({
        belge_turu,
        durum: (stored[belge_turu] as "VAR" | "YOK" | undefined) ?? "YOK"
      }));
      await fulfillJson(route, 200, okBody({ items }));
      return;
    }

    if (belgeDurumMatch && method === "PUT") {
      const pid = Number.parseInt(belgeDurumMatch[1] ?? "0", 10);
      const p = personeller.find((x) => x.id === pid);
      if (!p) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      const payload = (request.postDataJSON() ?? {}) as {
        items?: Array<{ belge_turu?: string; durum?: string }>;
      };
      const stored = belgeDurumByPersonelId.get(pid) ?? {};
      const incoming: Record<string, "VAR" | "YOK"> = {};
      for (const row of payload.items ?? []) {
        const tur = row.belge_turu?.trim();
        const durum = row.durum;
        if (!tur || (durum !== "VAR" && durum !== "YOK")) {
          continue;
        }
        if (!(DEMO_BELGE_TURLERI as readonly string[]).includes(tur)) {
          continue;
        }
        incoming[tur] = durum;
      }
      const next: Record<string, "VAR" | "YOK"> = { ...stored };
      for (const belge_turu of DEMO_BELGE_TURLERI) {
        next[belge_turu] = incoming[belge_turu] ?? stored[belge_turu] ?? "YOK";
      }
      belgeDurumByPersonelId.set(pid, next);
      const items = DEMO_BELGE_TURLERI.map((belge_turu) => ({
        belge_turu,
        durum: next[belge_turu] ?? "YOK"
      }));
      await fulfillJson(route, 200, okBody({ items }));
      return;
    }

    if (path === "/api/personeller/1" && method === "GET") {
      await fulfillJson(
        route,
        200,
        okBody({
          ana_kart: {
            id: 1,
            tc_kimlik_no: "12345678901",
            ad: "Ayşe",
            soyad: "Yılmaz",
            aktif_durum: "AKTIF",
            sube_id: 1,
            telefon: "05550000000",
            dogum_tarihi: "1992-03-14",
            dogum_yeri: "İstanbul",
            kan_grubu: "A Rh+",
            sicil_no: "P-001",
            ise_giris_tarihi: "2023-02-01",
            acil_durum_kisi: "Fatma Yılmaz",
            acil_durum_telefon: "05553334455",
            departman_id: 3,
            gorev_id: 1,
            personel_tipi_id: 1,
            bagli_amir_id: 9
          },
          sistem_ozeti: {
            hizmet_suresi: "3 yil 2 ay",
            toplam_izin_hakki: 14,
            kullanilan_izin: 4,
            kalan_izin: 10
          },
          pasiflik_durumu: {
            aktif_durum: "AKTIF",
            etiket: null
          },
          referans_adlari: {
            sube: "Merkez",
            departman: "Döşeme",
            gorev: "Genel Müdür",
            personel_tipi: "Tam Zamanlı",
            bagli_amir: "Demo Amir"
          }
        })
      );
      return;
    }

    if (path === "/api/surecler" && method === "GET") {
      await fulfillJson(route, 200, okBody({ items: surecler }));
      return;
    }

    if (path === "/api/surecler" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        surec_turu: string;
        alt_tur?: string;
        baslangic_tarihi: string;
        bitis_tarihi: string;
        ucretli_mi?: boolean;
        aciklama?: string;
      };

      const created = {
        id: ++surecIdCounter,
        personel_id: payload.personel_id,
        surec_turu: payload.surec_turu,
        alt_tur: payload.alt_tur,
        baslangic_tarihi: payload.baslangic_tarihi,
        bitis_tarihi: payload.bitis_tarihi,
        ucretli_mi: payload.ucretli_mi,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      surecler.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+$/) && method === "GET") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      await fulfillJson(route, 200, okBody(surec));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+$/) && method === "PUT") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof surec>;
      Object.assign(surec, payload);

      await fulfillJson(route, 200, okBody(surec));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+\/iptal$/) && method === "POST") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      surec.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: surec.id, state: surec.state }));
      return;
    }

    if (path === "/api/bildirimler" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const tarih = url.searchParams.get("tarih");
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      const bildirimTuru = url.searchParams.get("bildirim_turu");

      const filtered = bildirimler.filter((item) => {
        if (tarih && item.tarih !== tarih) {
          return false;
        }
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
          return false;
        }
        if (bildirimTuru && item.bildirim_turu !== bildirimTuru) {
          return false;
        }
        return true;
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/bildirimler" && method === "POST") {
      const payload = request.postDataJSON() as {
        tarih: string;
        departman_id: number;
        personel_id: number;
        bildirim_turu: string;
        aciklama?: string;
      };

      const created = {
        id: ++bildirimIdCounter,
        tarih: payload.tarih,
        departman_id: payload.departman_id,
        personel_id: payload.personel_id,
        bildirim_turu: payload.bildirim_turu,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      bildirimler.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+$/) && method === "GET") {
      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+$/) && method === "PUT") {
      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof bildirim>;
      Object.assign(bildirim, payload);

      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+\/iptal$/) && method === "POST") {
      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      bildirim.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: bildirim.id, state: bildirim.state }));
      return;
    }

    if (path === "/api/referans/departmanlar" && method === "POST") {
      const payload = request.postDataJSON() as { ad?: string };
      const ad = (payload.ad ?? "").trim();
      if (!ad) {
        await fulfillJson(route, 400, errorBody("DEPARTMAN_NAME_REQUIRED", "Departman adı zorunludur."));
        return;
      }

      const existing = departmanOptions.find((item) => item.ad.toLocaleLowerCase("tr-TR") === ad.toLocaleLowerCase("tr-TR"));
      if (existing) {
        await fulfillJson(route, 200, okBody(existing));
        return;
      }

      const created = {
        id: ++departmanIdCounter,
        ad
      };
      departmanOptions.push(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.startsWith("/api/referans/") && method === "GET") {
      if (path === "/api/referans/departmanlar") {
        await fulfillJson(route, 200, okBody(departmanOptions));
        return;
      }

      if (path === "/api/referans/gorevler") {
        await fulfillJson(route, 200, okBody(gorevAdlari));
        return;
      }

      if (path === "/api/referans/personel-tipleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { id: 1, ad: "Tam Zamanlı" },
            { id: 2, ad: "Yarı Zamanlı" }
          ])
        );
        return;
      }

      if (path === "/api/referans/surec-turleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { key: "IZIN", label: "İzin" },
            { key: "RAPOR", label: "Rapor" },
            { key: "IS_KAZASI", label: "İş Kazası" },
            { key: "DEVAMSIZLIK", label: "Devamsızlık" },
            { key: "ISTEN_AYRILMA", label: "İşten Ayrılma" }
          ])
        );
        return;
      }

      if (path === "/api/referans/bagli-amirler") {
        await fulfillJson(route, 200, okBody(bagliAmirReferanslari.map(({ id, ad }) => ({ id, ad }))));
        return;
      }

      if (path === "/api/referans/bildirim-turleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { key: "GEC_GELDI", label: "Geç Geldi" },
            { key: "GELMEDI", label: "Gelmedi" },
            { key: "IZINLI_GELMEDI", label: "İzinli Gelmedi" },
            { key: "IZINSIZ_GELMEDI", label: "İzinsiz Gelmedi" },
            { key: "DEVAMSIZLIK", label: "Devamsızlık" },
            { key: "RAPORLU", label: "Raporlu" }
          ])
        );
        return;
      }

      if (path === "/api/referans/ucret-tipleri") {
        await fulfillJson(route, 200, okBody(ucretTipiReferans));
        return;
      }

      if (path === "/api/referans/prim-kurallari") {
        await fulfillJson(route, 200, okBody(primKuraliReferans));
        return;
      }

      await fulfillJson(route, 200, okBody([]));
      return;
    }

    if (path.startsWith("/api/gunluk-puantaj/") && method === "GET") {
      const segments = path.split("/");
      const personelId = Number.parseInt(segments[3] ?? "0", 10);
      const tarih = decodeURIComponent(segments[4] ?? "");
      const mevcutKayit =
        puantajKayitlari.find((item) => item.personel_id === personelId && item.tarih === tarih) ?? null;

      await fulfillJson(
        route,
        200,
        okBody(
          mevcutKayit ?? {
            personel_id: personelId,
            tarih,
            gun_tipi: "Normal_Is_Gunu",
            hareket_durumu: "Geldi",
            hesap_etkisi: "Tam_Yevmiye_Ver",
            giris_saati: "08:30",
            cikis_saati: "18:00",
            gercek_mola_dakika: 60,
            hesaplanan_mola_dakika: 60,
            net_calisma_suresi_dakika: 510,
            gunluk_brut_sure_dakika: 570,
            hafta_tatili_hak_kazandi_mi: true,
            state: "HESAPLANDI",
            compliance_uyarilari: []
          }
        )
      );
      return;
    }

    if (path.startsWith("/api/gunluk-puantaj/") && method === "PUT") {
      const segments = path.split("/");
      const personelId = Number.parseInt(segments[3] ?? "0", 10);
      const tarih = decodeURIComponent(segments[4] ?? "");
      const payload = request.postDataJSON() as {
        gun_tipi?: GunlukPuantaj["gun_tipi"];
        hareket_durumu?: GunlukPuantaj["hareket_durumu"];
        dayanak?: GunlukPuantaj["dayanak"];
        durumu_bildirdi_mi?: boolean | null;
        durum_bildirim_aciklamasi?: string | null;
        beklenen_giris_saati?: string;
        beklenen_cikis_saati?: string;
        giris_saati?: string;
        cikis_saati?: string;
        gercek_mola_dakika?: number;
        kontrol_durumu?: GunlukPuantaj["kontrol_durumu"];
      };
      const mevcutIndex = puantajKayitlari.findIndex((item) => item.personel_id === personelId && item.tarih === tarih);
      const oncekiKayit =
        mevcutIndex >= 0
          ? puantajKayitlari[mevcutIndex]
          : {
              personel_id: personelId,
              tarih,
              gun_tipi: "Normal_Is_Gunu" as const,
              hareket_durumu: "Geldi" as const,
              hesap_etkisi: "Tam_Yevmiye_Ver" as const,
              hafta_tatili_hak_kazandi_mi: true,
              state: "HESAPLANDI",
              kontrol_durumu: "BEKLIYOR" as const,
              compliance_uyarilari: []
            };
      const updated = {
        ...oncekiKayit,
        gun_tipi: payload.gun_tipi ?? oncekiKayit.gun_tipi ?? "Normal_Is_Gunu",
        hareket_durumu: payload.hareket_durumu ?? oncekiKayit.hareket_durumu ?? "Geldi",
        dayanak: payload.dayanak ?? oncekiKayit.dayanak,
        durumu_bildirdi_mi:
          "durumu_bildirdi_mi" in payload
            ? payload.durumu_bildirdi_mi ?? undefined
            : oncekiKayit.durumu_bildirdi_mi,
        durum_bildirim_aciklamasi:
          payload.durumu_bildirdi_mi === true
            ? payload.durum_bildirim_aciklamasi ?? undefined
            : "durumu_bildirdi_mi" in payload
              ? undefined
              : oncekiKayit.durum_bildirim_aciklamasi,
        beklenen_giris_saati: payload.beklenen_giris_saati ?? oncekiKayit.beklenen_giris_saati,
        beklenen_cikis_saati: payload.beklenen_cikis_saati ?? oncekiKayit.beklenen_cikis_saati,
        giris_saati: payload.giris_saati ?? oncekiKayit.giris_saati ?? "08:30",
        cikis_saati: payload.cikis_saati ?? oncekiKayit.cikis_saati ?? "18:00",
        gercek_mola_dakika: payload.gercek_mola_dakika ?? oncekiKayit.gercek_mola_dakika ?? 60,
        hesaplanan_mola_dakika: payload.gercek_mola_dakika ?? oncekiKayit.hesaplanan_mola_dakika ?? 60,
        net_calisma_suresi_dakika: oncekiKayit.net_calisma_suresi_dakika ?? 510,
        gunluk_brut_sure_dakika: oncekiKayit.gunluk_brut_sure_dakika ?? 570,
        kontrol_durumu: payload.kontrol_durumu ?? oncekiKayit.kontrol_durumu ?? "BEKLIYOR"
      };

      if (mevcutIndex >= 0) {
        puantajKayitlari[mevcutIndex] = updated;
      } else {
        puantajKayitlari.push(updated);
      }

      await fulfillJson(
        route,
        200,
        okBody(updated)
      );
      return;
    }

    if (path === "/api/puantaj/muhurle" && method === "POST") {
      const payload = request.postDataJSON() as { yil?: number; ay?: number };
      const yil = Number.isFinite(payload.yil) ? Number(payload.yil) : new Date().getFullYear();
      const ay = Number.isFinite(payload.ay) ? Number(payload.ay) : new Date().getMonth() + 1;
      const donem = `${yil}-${String(ay).padStart(2, "0")}`;
      let muhurlenenKayitSayisi = 0;

      for (let index = 0; index < puantajKayitlari.length; index += 1) {
        const item = puantajKayitlari[index];
        if (!item.tarih.startsWith(`${donem}-`) && !item.tarih.startsWith(`${donem}`)) {
          continue;
        }
        if (item.tarih.slice(0, 7) !== donem) {
          continue;
        }
        if (item.state === "MUHURLENDI") {
          continue;
        }
        puantajKayitlari[index] = { ...item, state: "MUHURLENDI" };
        muhurlenenKayitSayisi += 1;
      }

      await fulfillJson(
        route,
        200,
        okBody({ muhurlenen_kayit_sayisi: muhurlenenKayitSayisi, donem })
      );
      return;
    }

    if (path === "/api/haftalik-kapanis" && method === "POST") {
      const payload = request.postDataJSON() as {
        hafta_baslangic?: string;
        hafta_bitis?: string;
        departman_id?: number;
      };

      const kapanisId = 99;
      const hafta_baslangic = payload.hafta_baslangic ?? "2026-04-06";
      const hafta_bitis = payload.hafta_bitis ?? "2026-04-12";
      const departman_id = payload.departman_id ?? 3;
      const yilMatch = /^(\d{4})-/.exec(hafta_baslangic);
      const yil = yilMatch ? Number.parseInt(yilMatch[1], 10) : undefined;
      const hesaplama_zamani = new Date().toISOString();
      const snapshot_satirlari = personeller.map((personel, index) => ({
        snapshot_id: kapanisId * 1000 + index + 1,
        kapanis_id: kapanisId,
        personel_id: personel.id,
        departman_id,
        hafta_baslangic,
        hafta_bitis,
        yil,
        hafta_no: 1,
        state: "KAPANDI",
        kaynak_versiyon: "A1_CONTRACT_STUB",
        toplam_net_dakika: 0,
        normal_calisma_dakika: 0,
        fazla_calisma_dakika: 0,
        fazla_surelerle_calisma_dakika: 0,
        tam_hafta_verisi: false,
        compliance_uyarilari: [],
        compliance_uyari_sayisi: 0,
        kritik_uyari_var_mi: false,
        hesaplama_zamani,
        kaynak_gun_sayisi: 0,
        notlar: ["A1 contract stub; gerçek hesap A2 fazında bağlanacak."]
      }));

      await fulfillJson(
        route,
        200,
        okBody({
          id: kapanisId,
          kapanis_id: kapanisId,
          hafta_baslangic,
          hafta_bitis,
          departman_id,
          state: "KAPANDI",
          personel_sayisi: 24,
          snapshot_satir_sayisi: snapshot_satirlari.length,
          snapshot_satirlari
        })
      );
      return;
    }

    if (path === "/api/yonetim/kullanicilar" && method === "GET") {
      await fulfillJson(
        route,
        200,
        okBody({
          items: yonetimKullanicilari.map((item) => ({
            ...item,
            personel_ad_soyad:
              item.personel_id != null
                ? personeller.find((personel) => personel.id === item.personel_id)
                  ? `${personeller.find((personel) => personel.id === item.personel_id)?.ad} ${
                      personeller.find((personel) => personel.id === item.personel_id)?.soyad
                    }`
                  : null
                : null
          }))
        })
      );
      return;
    }

    if (path === "/api/yonetim/kullanicilar" && method === "POST") {
      const payload = request.postDataJSON() as {
        ad_soyad: string;
        telefon?: string;
        kullanici_tipi: "IC_PERSONEL" | "HARICI";
        rol: MockUserRole;
        personel_id?: number | null;
        sube_ids?: number[];
        varsayilan_sube_id?: number | null;
        durum: "AKTIF" | "PASIF";
        notlar?: string;
      };

      const linkedPersonel =
        payload.personel_id != null ? personeller.find((item) => item.id === payload.personel_id) ?? null : null;

      const created = {
        id: ++kullaniciIdCounter,
        ad_soyad: payload.ad_soyad,
        telefon: payload.telefon,
        kullanici_tipi: payload.kullanici_tipi,
        rol: payload.rol,
        personel_id: payload.personel_id ?? null,
        sube_ids: payload.sube_ids ?? [],
        varsayilan_sube_id: payload.varsayilan_sube_id ?? null,
        durum: payload.durum,
        notlar: payload.notlar
      };

      yonetimKullanicilari.unshift(created);

      await fulfillJson(
        route,
        200,
        okBody({
          ...created,
          personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
        })
      );
      return;
    }

    if (path.match(/^\/api\/yonetim\/kullanicilar\/\d+$/) && method === "PUT") {
      const kullaniciId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const target = yonetimKullanicilari.find((item) => item.id === kullaniciId);
      if (!target) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Kullanici bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as {
        ad_soyad?: string;
        telefon?: string;
        kullanici_tipi?: "IC_PERSONEL" | "HARICI";
        rol?: MockUserRole;
        personel_id?: number | null;
        sube_ids?: number[];
        varsayilan_sube_id?: number | null;
        durum?: "AKTIF" | "PASIF";
        notlar?: string;
      };
      const linkedPersonel =
        payload.personel_id != null ? personeller.find((item) => item.id === payload.personel_id) ?? null : null;

      Object.assign(target, {
        ad_soyad: payload.ad_soyad ?? target.ad_soyad,
        telefon: payload.telefon ?? target.telefon,
        kullanici_tipi: payload.kullanici_tipi ?? target.kullanici_tipi,
        rol: payload.rol ?? target.rol,
        personel_id: payload.personel_id ?? null,
        sube_ids: payload.sube_ids ?? target.sube_ids,
        varsayilan_sube_id: payload.varsayilan_sube_id ?? target.varsayilan_sube_id,
        durum: payload.durum ?? target.durum,
        notlar: payload.notlar ?? target.notlar
      });

      await fulfillJson(
        route,
        200,
        okBody({
          ...target,
          personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
        })
      );
      return;
    }

    if (path === "/api/yonetim/subeler" && method === "GET") {
      await fulfillJson(route, 200, okBody({ items: subeler }));
      return;
    }

    if (path === "/api/yonetim/subeler" && method === "POST") {
      const payload = request.postDataJSON() as {
        kod: string;
        ad: string;
        departman_ids?: number[];
        durum: "AKTIF" | "PASIF";
      };

      const created = {
        id: ++subeIdCounter,
        ...normalizeSubePayload(payload)
      };

      subeler.unshift(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/yonetim\/subeler\/\d+$/) && method === "PUT") {
      const subeId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const target = subeler.find((item) => item.id === subeId);
      if (!target) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Sube bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<(typeof subeler)[number]>;
      Object.assign(target, {
        ...(payload.kod ? { kod: payload.kod } : {}),
        ...(payload.ad ? { ad: payload.ad } : {}),
        ...(payload.departman_ids ? {
          departman_ids: payload.departman_ids,
          departman_adlari: payload.departman_ids.map((id) => getDepartmanLabel(id))
        } : {}),
        ...(payload.durum ? { durum: payload.durum } : {})
      });
      await fulfillJson(route, 200, okBody(target));
      return;
    }

    if (path.match(/^\/api\/yonetim\/subeler\/\d+$/) && method === "DELETE") {
      const subeId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const targetIndex = subeler.findIndex((item) => item.id === subeId);
      if (targetIndex === -1) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Sube bulunamadi."));
        return;
      }

      const hasLinkedPersonel = personeller.some((personel) => personel.sube_id === subeId);
      if (hasLinkedPersonel) {
        await fulfillJson(route, 409, errorBody(SUBE_DELETE_BLOCKED_ERROR_CODE, SUBE_DELETE_BLOCKED_MESSAGE));
        return;
      }

      subeler.splice(targetIndex, 1);
      yonetimKullanicilari.forEach((kullanici) => {
        if (!kullanici.sube_ids.includes(subeId)) {
          return;
        }

        kullanici.sube_ids = kullanici.sube_ids.filter((id) => id !== subeId);
        if (kullanici.varsayilan_sube_id === subeId) {
          kullanici.varsayilan_sube_id = kullanici.sube_ids[0] ?? null;
        }
      });

      await fulfillJson(route, 200, okBody({ id: subeId, deleted: true }));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet" && method === "GET") {
      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(url)));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet/bolum-onay" && method === "POST") {
      const payload = request.postDataJSON() as {
        ay?: string;
        sube_id?: number;
        departman_id?: number;
      };

      aylikOzetRows.forEach((item) => {
        if (item.ay !== (payload.ay ?? aylikOzetFixtureAy)) {
          return;
        }
        if (payload.sube_id != null && item.sube_id !== payload.sube_id) {
          return;
        }
        if (payload.departman_id != null && item.departman_id !== payload.departman_id) {
          return;
        }
        if (item.kapanis_durumu === "KAPANDI") {
          return;
        }

        item.bolum_onay_durumu = "BOLUM_ONAYLANDI";
        item.revize_var_mi = false;
        item.son_islem = "Bolum yoneticisi toplu onay verdi";
      });

      const responseUrl = new URL(url.toString());
      if (payload.ay) {
        responseUrl.searchParams.set("ay", payload.ay);
      }
      if (payload.sube_id != null) {
        responseUrl.searchParams.set("sube_id", String(payload.sube_id));
      }
      if (payload.departman_id != null) {
        responseUrl.searchParams.set("departman_id", String(payload.departman_id));
      }
      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(responseUrl)));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet/ay-kapat" && method === "POST") {
      const payload = request.postDataJSON() as {
        ay?: string;
        sube_id?: number;
        departman_id?: number;
      };

      const responseUrl = new URL(url.toString());
      if (payload.ay) {
        responseUrl.searchParams.set("ay", payload.ay);
      }
      if (payload.sube_id != null) {
        responseUrl.searchParams.set("sube_id", String(payload.sube_id));
      }
      if (payload.departman_id != null) {
        responseUrl.searchParams.set("departman_id", String(payload.departman_id));
      }

      aylikOzetRows.forEach((item) => {
        if (item.ay !== (payload.ay ?? aylikOzetFixtureAy)) {
          return;
        }
        if (payload.sube_id != null && item.sube_id !== payload.sube_id) {
          return;
        }
        if (payload.departman_id != null && item.departman_id !== payload.departman_id) {
          return;
        }

        item.kapanis_durumu = "KAPANDI";
        item.son_islem = "Genel yonetici ust onay verdi";
      });

      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(responseUrl)));
      return;
    }

    if (path.startsWith("/api/raporlar/") && method === "GET") {
      if (path === "/api/raporlar/personel-ozet") {
        const url = new URL(route.request().url());
        const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(url.searchParams.get("departman_id") ?? "", 10);
        await fulfillJson(
          route,
          200,
          personelOzetPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined
          )
        );
        return;
      }

      const mockItems = RAPOR_MOCK_ITEMS[path];
      if (mockItems) {
        await fulfillJson(route, 200, raporListOkBody(mockItems));
        return;
      }

      await fulfillJson(route, 200, raporListOkBody([]));
      return;
    }

    if (path === "/api/ek-odeme-kesinti" && method === "GET") {
      const url = new URL(route.request().url());
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      const kalemTuru = url.searchParams.get("kalem_turu");
      const filtered = finansKalemleri.filter((item) => {
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
          return false;
        }
        if (kalemTuru && item.kalem_turu !== kalemTuru) {
          return false;
        }
        return true;
      });
      await fulfillJson(route, 200, okBody({ items: filtered }));
      return;
    }

    if (path === "/api/ek-odeme-kesinti" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        donem: string;
        kalem_turu: string;
        tutar: number;
        aciklama?: string;
      };

      const created = {
        id: ++finansIdCounter,
        personel_id: payload.personel_id,
        donem: payload.donem,
        kalem_turu: payload.kalem_turu,
        tutar: payload.tutar,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      finansKalemleri.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/ek-odeme-kesinti\/\d+$/) && method === "PUT") {
      const kalemId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const kalem = finansKalemleri.find((item) => item.id === kalemId);
      if (!kalem) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Finans kalemi bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof kalem>;
      Object.assign(kalem, payload);

      await fulfillJson(route, 200, okBody(kalem));
      return;
    }

    if (path.match(/^\/api\/ek-odeme-kesinti\/\d+\/iptal$/) && method === "POST") {
      const kalemId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const kalem = finansKalemleri.find((item) => item.id === kalemId);
      if (!kalem) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Finans kalemi bulunamadi."));
        return;
      }

      kalem.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: kalem.id, state: kalem.state }));
      return;
    }

    await fulfillJson(route, 404, errorBody("NOT_MOCKED", `${method} ${path}`));
    }
  );
}

