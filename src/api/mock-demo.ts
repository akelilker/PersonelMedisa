import type { ApiResponse } from "../types/api";

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
  giris_saati?: string;
  cikis_saati?: string;
  gercek_mola_dakika?: number;
  hesaplanan_mola_dakika?: number;
  net_calisma_suresi_dakika?: number;
  gunluk_brut_sure_dakika?: number;
  state?: string;
  compliance_uyarilari: Array<{ code: string; message: string; level?: string }>;
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
  departmanlar: string[];
  durum: "AKTIF" | "PASIF";
};

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
  bildirimler: DemoBildirim[];
  finansKalemleri: DemoFinansKalem[];
  puantajMap: Record<string, DemoPuantaj>;
  yonetimKullanicilari: DemoYonetimKullanici[];
  subeler: DemoSube[];
  aylikDurumMap: Record<string, DemoAylikDurum>;
  nextIds: {
    personel: number;
    surec: number;
    bildirim: number;
    finans: number;
    kapanis: number;
    kullanici: number;
    sube: number;
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
      departman_id: 2,
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
      departman_id: 2,
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
  puantajMap: {},
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
      notlar: "Gunluk bildirim sorumlusu"
    }
  ],
  subeler: [
    {
      id: 1,
      kod: "MRK",
      ad: "Merkez",
      departmanlar: ["Yonetim", "Operasyon"],
      durum: "AKTIF"
    },
    {
      id: 2,
      kod: "DPL",
      ad: "Depolama",
      departmanlar: ["Depolama", "Sevkiyat"],
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
    bildirim: 800,
    finans: 950,
    kapanis: 1000,
    kullanici: 3,
    sube: 2
  }
};

const DEMO_SUBE_LABELS: Record<number, string> = {
  1: "Merkez",
  2: "Depolama"
};

const DEMO_DEPARTMAN_LABELS: Record<number, string> = {
  1: "Yonetim",
  2: "Muhasebe",
  3: "Operasyon"
};

const DEMO_GOREV_LABELS: Record<number, string> = {
  1: "Uzman",
  2: "Sef",
  3: "Mudur"
};

const DEMO_PERSONEL_TIPI_LABELS: Record<number, string> = {
  1: "Tam Zamanli",
  2: "Yari Zamanli"
};

const DEMO_BAGLI_AMIR_LABELS: Record<number, string> = {
  1: "Demo Amir"
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
  if (normalized.includes("bolum") || normalized.includes("bölüm")) {
    return "BOLUM_YONETICISI";
  }

  return "GENEL_YONETICI";
}

function defaultPuantaj(personelId: number, tarih: string): DemoPuantaj {
  return {
    personel_id: personelId,
    tarih,
    giris_saati: "08:30",
    cikis_saati: "18:00",
    gercek_mola_dakika: 60,
    hesaplanan_mola_dakika: 60,
    net_calisma_suresi_dakika: 510,
    gunluk_brut_sure_dakika: 570,
    state: "HESAPLANDI",
    compliance_uyarilari: []
  };
}

function getLabel(map: Record<number, string>, id: number | undefined) {
  if (typeof id !== "number") {
    return undefined;
  }

  return map[id] ?? `#${id}`;
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
        sube: getLabel(DEMO_SUBE_LABELS, personel.sube_id) ?? "-",
        bolum: getLabel(DEMO_DEPARTMAN_LABELS, personel.departman_id) ?? "-",
        birim_amiri: getLabel(DEMO_BAGLI_AMIR_LABELS, personel.bagli_amir_id) ?? "-",
        devamsizlik_gun: bildirimOzet.devamsizlikGun,
        gec_kalma_adet: bildirimOzet.gecKalmaAdet,
        izinli_gelmedi: bildirimOzet.izinliGelmedi,
        izinsiz_gelmedi: bildirimOzet.izinsizGelmedi,
        raporlu: bildirimOzet.raporlu,
        tesvik_tutari: finansOzet.tesvikTutari,
        ceza_kesinti_tutari: finansOzet.cezaKesintiTutari,
        bolum_onay_durumu: durum.kapanis_durumu === "KAPANDI" ? "KAPANDI" : durum.bolum_onay_durumu,
        revize_var_mi: durum.revize_var_mi,
        son_islem: durum.son_islem,
        kapanis_durumu: durum.kapanis_durumu
      };
    })
    .filter((item) => (sadeceRevizeli ? item.revize_var_mi : true));

  const pendingBolumOnayi = items.filter(
    (item) => item.kapanis_durumu !== "KAPANDI" && item.bolum_onay_durumu !== "BOLUM_ONAYLANDI"
  ).length;

  const state =
    items.length > 0 && items.every((item) => item.kapanis_durumu === "KAPANDI")
      ? "KAPANDI"
      : items.some((item) => item.bolum_onay_durumu === "REVIZE_ISTENDI")
        ? "REVIZE_ISTENDI"
        : pendingBolumOnayi === 0
          ? "BOLUM_ONAYLANDI"
          : "BOLUM_ONAYINDA";

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

function buildDemoPersonelDetail(personel: DemoPersonel) {
  return {
    ana_kart: { ...personel },
    sistem_ozeti: {
      hizmet_suresi: personel.id === 1 ? "3 yil 2 ay" : "1 yil 8 ay",
      toplam_izin_hakki: personel.id === 1 ? 14 : 10,
      kullanilan_izin: personel.id === 1 ? 4 : 2,
      kalan_izin: personel.id === 1 ? 10 : 8
    },
    pasiflik_durumu: {
      aktif_durum: personel.aktif_durum,
      etiket: personel.aktif_durum === "PASIF" ? "Pasif" : null
    },
    referans_adlari: {
      sube: getLabel(DEMO_SUBE_LABELS, personel.sube_id),
      departman: getLabel(DEMO_DEPARTMAN_LABELS, personel.departman_id),
      gorev: getLabel(DEMO_GOREV_LABELS, personel.gorev_id),
      personel_tipi: getLabel(DEMO_PERSONEL_TIPI_LABELS, personel.personel_tipi_id),
      bagli_amir: getLabel(DEMO_BAGLI_AMIR_LABELS, personel.bagli_amir_id)
    }
  };
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
      ? sube_ids.map((id) => ({ id, ad: id === 1 ? "Merkez" : `Şube ${id}` }))
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
      sube_adi: getLabel(DEMO_SUBE_LABELS, item.sube_id),
      departman_adi: getLabel(DEMO_DEPARTMAN_LABELS, item.departman_id),
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
    return ok({ items: demoState.surecler });
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
        giris_saati: toStringValue(body.giris_saati) ?? existing.giris_saati,
        cikis_saati: toStringValue(body.cikis_saati) ?? existing.cikis_saati,
        gercek_mola_dakika: toNumber(body.gercek_mola_dakika) ?? existing.gercek_mola_dakika
      };
      demoState.puantajMap[key] = updated;
      return ok(updated);
    }
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
    return ok({ items: demoState.subeler });
  }

  if (pathname === "/yonetim/subeler" && method === "POST") {
    const next: DemoSube = {
      id: ++demoState.nextIds.sube,
      kod: toStringValue(body.kod) ?? `SBE-${demoState.nextIds.sube}`,
      ad: toStringValue(body.ad) ?? "Yeni Sube",
      departmanlar: Array.isArray(body.departmanlar)
        ? body.departmanlar
            .map((item) => toStringValue(item))
            .filter((item): item is string => item !== null)
        : [],
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF"
    };
    demoState.subeler.unshift(next);
    return ok(next);
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
      departmanlar: Array.isArray(body.departmanlar)
        ? body.departmanlar
            .map((item) => toStringValue(item))
            .filter((item): item is string => item !== null)
        : target.departmanlar,
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF"
    });
    return ok(target);
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
    const current = buildAylikOzetResponse(ay, subeId, departmanId, false);

    if (current.pending_bolum_onayi > 0) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "PENDING_BOLUM_APPROVAL",
            message: "Bolum onayi bekleyen kayitlar var. Ay kapatilamadi."
          }
        ]
      };
    }

    current.items.forEach((item) => {
      const durum = ensureAylikDurum(ay, item.personel_id);
      durum.kapanis_durumu = "KAPANDI";
      durum.son_islem = `Genel yonetici ayi kapatti (${formatAylikIslemTimestamp()})`;
    });

    return ok(buildAylikOzetResponse(ay, subeId, departmanId, sadeceRevizeli));
  }

  if (pathname.startsWith("/referans/") && method === "GET") {
    if (pathname === "/referans/departmanlar") {
      return ok([
      { id: 1, ad: "Yönetim" },
        { id: 2, ad: "Muhasebe" },
        { id: 3, ad: "Operasyon" }
      ]);
    }

    if (pathname === "/referans/gorevler") {
      return ok([
        { id: 1, ad: "Uzman" },
        { id: 2, ad: "Sef" },
        { id: 3, ad: "Mudür" }
      ]);
    }

    if (pathname === "/referans/personel-tipleri") {
      return ok([
        { id: 1, ad: "Tam Zamanli" },
        { id: 2, ad: "Yari Zamanli" }
      ]);
    }

    if (pathname === "/referans/surec-turleri") {
      return ok([
        { key: "IZIN", label: "İzin" },
        { key: "RAPOR", label: "Rapor" },
        { key: "ISTEN_AYRILMA", label: "Isten Ayrilma" }
      ]);
    }

    if (pathname === "/referans/bildirim-turleri") {
      return ok([
        { key: "GEC_GELDI", label: "Gec Geldi" },
        { key: "GELMEDI", label: "Gelmedi" },
        { key: "IZINLI_GELMEDI", label: "Izinli Gelmedi" },
        { key: "IZINSIZ_GELMEDI", label: "Izinsiz Gelmedi" },
        { key: "DEVAMSIZLIK", label: "Devamsızlık" },
        { key: "RAPORLU", label: "Raporlu" }
      ]);
    }

    if (pathname === "/referans/bagli-amirler") {
      return ok([{ id: 1, ad: "Demo Amir" }]);
    }

    return ok([]);
  }

  if (pathname.startsWith("/raporlar/") && method === "GET") {
    return ok(
      {
        items: [
          {
            personel_id: 1,
            ad_soyad: "Ayse Yilmaz",
            rapor_tipi: pathname.replace("/raporlar/", ""),
            net_calisma_dakika: 510
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
