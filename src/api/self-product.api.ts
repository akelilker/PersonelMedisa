import type { ApiResponse } from "../types/api";
import { ApiRequestError, apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type SelfIzinKaydi = {
  id: number;
  izin_turu: string;
  baslangic: string;
  bitis: string | null;
  gun: number | null;
  aciklama: string | null;
  durum: string;
  aktif_mi: boolean;
  ise_donus: string | null;
  bitime_kalan_gun: number | null;
};

export type SelfIzinList = {
  personel_id: number;
  aktif: SelfIzinKaydi | null;
  gecmis: SelfIzinKaydi[];
};

export type SelfCorrectionRequest = {
  id: number;
  tarih: string;
  talep_turu: string;
  aciklama: string | null;
  durum: string;
  sonuc: string | null;
};

export type SelfAvansTalep = {
  id: number;
  tutar: string;
  talep_tarihi: string;
  aciklama: string | null;
  durum: string;
  sonuc: string | null;
};

export type SelfGeriBildirim = {
  id: number;
  tur: "ONERI" | "SIKAYET";
  konu: string;
  aciklama: string;
  tarih: string;
  durum: string;
  sonuc: string | null;
};

export type SelfDuyuru = {
  id: number;
  baslik: string;
  aciklama: string;
  yayin_tarihi: string;
  bitis_tarihi: string | null;
  okundu: boolean;
};

export type SelfProfilFoto = {
  has_photo: boolean;
  mime_type: string | null;
  image_base64: string | null;
};

export type SelfBordroSlip = {
  calistirma_id: number;
  yil: number;
  ay: number;
  sube_id: number;
  donem_label: string;
  okundu: boolean;
  okundu_at: string | null;
  okundu_by_user_id: number | null;
};

export type SelfRaporKaydi = {
  id: number;
  surec_turu: string;
  alt_tur: string | null;
  baslangic_tarihi: string;
  bitis_tarihi: string | null;
  state: string;
  aciklama: string | null;
};

function unwrap(response: unknown, fallback: string): Record<string, unknown> {
  if (typeof response !== "object" || response === null || !("data" in response)) {
    throw new ApiRequestError(fallback, 400, { code: "INVALID_RESPONSE" });
  }
  const data = (response as ApiResponse<unknown>).data;
  if (typeof data !== "object" || data === null) {
    throw new ApiRequestError(fallback, 400, { code: "INVALID_RESPONSE" });
  }
  return data as Record<string, unknown>;
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null ? (value as Record<string, unknown>) : null;
}

function readString(value: unknown): string | null {
  return typeof value === "string" && value.trim() ? value.trim() : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function mapIzin(value: unknown): SelfIzinKaydi | null {
  const row = asRecord(value);
  const id = row ? readNumber(row.id) : null;
  const baslangic = row ? readString(row.baslangic) : null;
  if (!row || id === null || !baslangic) {
    return null;
  }
  return {
    id,
    izin_turu: readString(row.izin_turu) ?? "IZIN",
    baslangic,
    bitis: readString(row.bitis),
    gun: readNumber(row.gun),
    aciklama: readString(row.aciklama),
    durum: readString(row.durum) ?? "",
    aktif_mi: row.aktif_mi === true,
    ise_donus: readString(row.ise_donus),
    bitime_kalan_gun: readNumber(row.bitime_kalan_gun)
  };
}

export async function fetchSelfIzinler(): Promise<SelfIzinList> {
  const data = unwrap(await apiRequest(endpoints.me.izinler), "İzinler alınamadı.");
  const personelId = readNumber(data.personel_id);
  if (personelId === null) {
    throw new ApiRequestError("İzinler alınamadı.", 400, { code: "INVALID_RESPONSE" });
  }
  const gecmis = Array.isArray(data.gecmis)
    ? data.gecmis.map(mapIzin).filter((item): item is SelfIzinKaydi => item !== null)
    : [];
  return {
    personel_id: personelId,
    aktif: mapIzin(data.aktif),
    gecmis
  };
}

export async function createSelfIzinTalebi(body: {
  izin_turu: string;
  baslangic_tarihi: string;
  bitis_tarihi: string;
  aciklama?: string;
}): Promise<void> {
  await apiRequest(endpoints.me.izinTalepleri, { method: "POST", body: JSON.stringify(body) });
}

export async function fetchSelfCorrectionRequests(): Promise<SelfCorrectionRequest[]> {
  const data = unwrap(
    await apiRequest(endpoints.me.attendanceCorrectionRequests),
    "Düzeltme talepleri alınamadı."
  );
  if (!Array.isArray(data.items)) {
    return [];
  }
  return data.items.flatMap((item) => {
    const row = asRecord(item);
    const id = row ? readNumber(row.id) : null;
    const tarih = row ? readString(row.tarih) : null;
    if (!row || id === null || !tarih) {
      return [];
    }
    return [
      {
        id,
        tarih,
        talep_turu: readString(row.talep_turu) ?? "",
        aciklama: readString(row.aciklama),
        durum: readString(row.durum) ?? "",
        sonuc: readString(row.sonuc)
      }
    ];
  });
}

export async function fetchSelfAvansTalepleri(): Promise<SelfAvansTalep[]> {
  const data = unwrap(await apiRequest(endpoints.me.avansTalepleri), "Avans talepleri alınamadı.");
  if (!Array.isArray(data.items)) return [];
  return data.items.flatMap((item) => {
    const row = asRecord(item);
    const id = row ? readNumber(row.id) : null;
    if (!row || id === null) return [];
    return [
      {
        id,
        tutar: readString(row.tutar) ?? "",
        talep_tarihi: readString(row.talep_tarihi) ?? "",
        aciklama: readString(row.aciklama),
        durum: readString(row.durum) ?? "",
        sonuc: readString(row.sonuc)
      }
    ];
  });
}

export async function createSelfAvansTalebi(body: {
  tutar: string;
  talep_tarihi: string;
  aciklama?: string;
}): Promise<void> {
  await apiRequest(endpoints.me.avansTalepleri, { method: "POST", body: JSON.stringify(body) });
}

export async function fetchSelfGeriBildirimler(): Promise<SelfGeriBildirim[]> {
  const data = unwrap(await apiRequest(endpoints.me.geriBildirimler), "Geri bildirimler alınamadı.");
  if (!Array.isArray(data.items)) return [];
  return data.items.flatMap((item) => {
    const row = asRecord(item);
    const id = row ? readNumber(row.id) : null;
    const tur = row ? readString(row.tur) : null;
    if (!row || id === null || (tur !== "ONERI" && tur !== "SIKAYET")) return [];
    return [
      {
        id,
        tur,
        konu: readString(row.konu) ?? "",
        aciklama: readString(row.aciklama) ?? "",
        tarih: readString(row.tarih) ?? "",
        durum: readString(row.durum) ?? "",
        sonuc: readString(row.sonuc)
      }
    ];
  });
}

export async function createSelfGeriBildirim(body: {
  tur: "ONERI" | "SIKAYET";
  konu: string;
  aciklama: string;
}): Promise<void> {
  await apiRequest(endpoints.me.geriBildirimler, { method: "POST", body: JSON.stringify(body) });
}

export async function fetchSelfDuyurular(): Promise<{ items: SelfDuyuru[]; unread_count: number }> {
  const data = unwrap(await apiRequest(endpoints.me.duyurular), "Duyurular alınamadı.");
  const items = Array.isArray(data.items)
    ? data.items.flatMap((item) => {
        const row = asRecord(item);
        const id = row ? readNumber(row.id) : null;
        const baslik = row ? readString(row.baslik) : null;
        if (!row || id === null || !baslik) return [];
        return [
          {
            id,
            baslik,
            aciklama: readString(row.aciklama) ?? "",
            yayin_tarihi: readString(row.yayin_tarihi) ?? "",
            bitis_tarihi: readString(row.bitis_tarihi),
            okundu: row.okundu === true
          }
        ];
      })
    : [];
  return {
    items,
    unread_count: readNumber(data.unread_count) ?? items.filter((item) => !item.okundu).length
  };
}

export async function markSelfDuyuruRead(id: number): Promise<void> {
  await apiRequest(endpoints.me.duyuruOkundu(id), { method: "POST" });
}

const PHOTO_MIME = new Set(["image/jpeg", "image/png", "image/webp"]);

export function selfProfilFotoSrc(photo: SelfProfilFoto | null): string | null {
  if (!photo?.has_photo || !photo.mime_type || !photo.image_base64) {
    return null;
  }
  if (!PHOTO_MIME.has(photo.mime_type)) {
    return null;
  }
  return `data:${photo.mime_type};base64,${photo.image_base64}`;
}

export async function fetchSelfProfilFoto(): Promise<SelfProfilFoto> {
  const data = unwrap(await apiRequest(endpoints.me.profilFoto), "Profil fotoğrafı alınamadı.");
  return {
    has_photo: data.has_photo === true,
    mime_type: readString(data.mime_type),
    image_base64: readString(data.image_base64)
  };
}

export async function fetchSelfBordrolar(): Promise<SelfBordroSlip[]> {
  const data = unwrap(await apiRequest(endpoints.me.bordrolar), "Bordro listesi alınamadı.");
  const items = Array.isArray(data.items) ? data.items : [];
  return items.flatMap((item) => {
    const row = asRecord(item);
    const calistirmaId = row ? readNumber(row.calistirma_id) : null;
    if (!row || calistirmaId === null) return [];
    return [
      {
        calistirma_id: calistirmaId,
        yil: readNumber(row.yil) ?? 0,
        ay: readNumber(row.ay) ?? 0,
        sube_id: readNumber(row.sube_id) ?? 0,
        donem_label: readString(row.donem_label) ?? "",
        okundu: row.okundu === true,
        okundu_at: readString(row.okundu_at),
        okundu_by_user_id: readNumber(row.okundu_by_user_id)
      }
    ];
  });
}

export async function acknowledgeSelfBordro(calistirmaId: number): Promise<SelfBordroSlip> {
  const data = unwrap(
    await apiRequest(endpoints.me.bordroOkudum(calistirmaId), { method: "POST", body: "{}" }),
    "Bordro okundu işaretlenemedi."
  );
  const calistirma_id = readNumber(data.calistirma_id) ?? calistirmaId;
  return {
    calistirma_id,
    yil: readNumber(data.yil) ?? 0,
    ay: readNumber(data.ay) ?? 0,
    sube_id: 0,
    donem_label: readString(data.donem_label) ?? "",
    okundu: true,
    okundu_at: readString(data.okundu_at),
    okundu_by_user_id: readNumber(data.okundu_by_user_id)
  };
}

export async function fetchSelfRaporlar(): Promise<SelfRaporKaydi[]> {
  const data = unwrap(await apiRequest(endpoints.me.raporlar), "Raporlar alınamadı.");
  const items = Array.isArray(data.items) ? data.items : [];
  return items.flatMap((item) => {
    const row = asRecord(item);
    const id = row ? readNumber(row.id) : null;
    if (!row || id === null) return [];
    return [
      {
        id,
        surec_turu: readString(row.surec_turu) ?? "RAPOR",
        alt_tur: readString(row.alt_tur),
        baslangic_tarihi: readString(row.baslangic_tarihi) ?? "",
        bitis_tarihi: readString(row.bitis_tarihi),
        state: readString(row.state) ?? "",
        aciklama: readString(row.aciklama)
      }
    ];
  });
}

export async function createSelfRaporTalebi(body: {
  baslangic_tarihi: string;
  bitis_tarihi?: string;
  aciklama?: string;
  dosya_adi?: string;
  dosya_mime?: string;
  dosya_icerik_base64?: string;
}): Promise<void> {
  await apiRequest(endpoints.me.raporTalepleri, { method: "POST", body: JSON.stringify(body) });
}

export async function uploadSelfProfilFoto(dosyaIcerikBase64: string): Promise<SelfProfilFoto> {
  const data = unwrap(
    await apiRequest(endpoints.me.profilFoto, {
      method: "PUT",
      body: JSON.stringify({ dosya_icerik_base64: dosyaIcerikBase64 })
    }),
    "Profil fotoğrafı kaydedilemedi."
  );
  return {
    has_photo: data.has_photo === true,
    mime_type: readString(data.mime_type),
    image_base64: readString(data.image_base64)
  };
}
