import type { ApiResponse } from "../types/api";

type DemoMethod = "GET" | "POST" | "PUT" | "DELETE";

type DemoPersonel = {
  id: number;
  tc_kimlik_no: string;
  ad: string;
  soyad: string;
  aktif_durum: "AKTIF" | "PASIF";
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

const demoState: {
  personeller: DemoPersonel[];
  surecler: DemoSurec[];
  bildirimler: DemoBildirim[];
  finansKalemleri: DemoFinansKalem[];
  puantajMap: Record<string, DemoPuantaj>;
  nextIds: {
    personel: number;
    surec: number;
    bildirim: number;
    finans: number;
    kapanis: number;
  };
} = {
  personeller: [
    {
      id: 1,
      tc_kimlik_no: "12345678901",
      ad: "Ayse",
      soyad: "Yilmaz",
      aktif_durum: "AKTIF",
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
  nextIds: {
    personel: 100,
    surec: 600,
    bildirim: 800,
    finans: 950,
    kapanis: 1000
  }
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
    const sube_ids = role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : [];
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
    const departmanId = toNumber(requestUrl.searchParams.get("departman_id"));
    const personelTipiId = toNumber(requestUrl.searchParams.get("personel_tipi_id"));

    const filtered = demoState.personeller.filter((item) => {
      if (aktiflik === "aktif" && item.aktif_durum !== "AKTIF") {
        return false;
      }
      if (aktiflik === "pasif" && item.aktif_durum !== "PASIF") {
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

  if (pathname === "/personeller" && method === "POST") {
    const next: DemoPersonel = {
      id: ++demoState.nextIds.personel,
      tc_kimlik_no: toStringValue(body.tc_kimlik_no) ?? "00000000000",
      ad: toStringValue(body.ad) ?? "Yeni",
      soyad: toStringValue(body.soyad) ?? "Personel",
      aktif_durum: (toStringValue(body.aktif_durum) as "AKTIF" | "PASIF") ?? "AKTIF",
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
    return ok({ items: demoState.bildirimler });
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
