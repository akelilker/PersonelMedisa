import type { ApiResponse } from "../types/api";
import type { IdOption, KeyOption } from "../types/referans";
import { formatReferenceValue } from "../features/personeller/components/personel-dosya/personel-dosya-format-utils";
import { sortDepartmanDisplayOptions } from "../lib/organizasyon/departman-display-order";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

function getObjectLabel(item: Record<string, unknown>) {
  const candidates = ["ad", "adi", "name", "label", "title"];
  for (const field of candidates) {
    const value = item[field];
    if (typeof value === "string" && value.trim().length > 0) {
      return value.trim();
    }
  }

  return null;
}

function readOptionalKisaKod(item: Record<string, unknown>): string | null {
  // Pack6 refs use kisa_kod; SGK işveren / çalışma lokasyonu catalogs use kod.
  const raw = item.kisa_kod ?? item.kisaKod ?? item.kod;
  if (typeof raw !== "string") {
    return null;
  }
  const trimmed = raw.trim();
  return trimmed ? trimmed : null;
}

function normalizeIdOptions(data: unknown, parentKey?: string): IdOption[] {
  const entries = extractListItems<unknown>(data);
  const normalizedEntries =
    entries.length > 0 ? entries : typeof data === "object" && data !== null ? [data] : [];
  if (normalizedEntries.length === 0) {
    return [];
  }

  return normalizedEntries
    .map((entry) => {
      if (typeof entry !== "object" || entry === null) {
        return null;
      }

      const item = entry as Record<string, unknown>;
      const rawId = item.id;
      const id = typeof rawId === "number" ? rawId : Number.parseInt(String(rawId ?? ""), 10);
      if (Number.isNaN(id) || id <= 0) {
        return null;
      }

      const name = getObjectLabel(item);
      const kisaKod = readOptionalKisaKod(item);
      const label = formatReferenceValue(name, id, kisaKod);
      const option: IdOption = { id, label };
      if (kisaKod) {
        option.kisaKod = kisaKod;
      }
      const rawSirket = item.sirket_id ?? item.sirketId;
      if (rawSirket !== undefined && rawSirket !== null && rawSirket !== "") {
        const sirketId =
          typeof rawSirket === "number"
            ? rawSirket
            : Number.parseInt(String(rawSirket), 10);
        if (Number.isFinite(sirketId) && sirketId > 0) {
          option.sirketId = sirketId;
        }
      }
      if (parentKey) {
        const rawParent = item[parentKey];
        const parentId =
          typeof rawParent === "number"
            ? rawParent
            : Number.parseInt(String(rawParent ?? ""), 10);
        if (Number.isFinite(parentId) && parentId > 0) {
          option.parentId = parentId;
        }
      }
      return option;
    })
    .filter((item): item is IdOption => item !== null);
}

function readBagliAmirPersonelIds(data: unknown): Map<number, number | null> {
  const personelIdByUserId = new Map<number, number | null>();
  for (const entry of extractListItems<unknown>(data)) {
    if (typeof entry !== "object" || entry === null) {
      continue;
    }

    const item = entry as Record<string, unknown>;
    const userId = readPositiveId(item.id);
    if (userId === null) {
      continue;
    }

    personelIdByUserId.set(userId, readPositiveId(item.personel_id ?? item.personelId));
  }

  return personelIdByUserId;
}

function readPositiveId(value: unknown): number | null {
  const parsed = typeof value === "number" ? value : Number.parseInt(String(value ?? ""), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

function normalizeKeyOptions(data: unknown): KeyOption[] {
  const entries = extractListItems<unknown>(data);
  const normalizedEntries =
    entries.length > 0 ? entries : typeof data === "object" && data !== null ? [data] : [];
  if (normalizedEntries.length === 0) {
    return [];
  }

  return normalizedEntries
    .map((entry) => {
      if (typeof entry === "string" && entry.trim().length > 0) {
        return { key: entry, label: entry };
      }

      if (typeof entry !== "object" || entry === null) {
        return null;
      }

      const item = entry as Record<string, unknown>;
      const label = getObjectLabel(item);
      if (!label) {
        return null;
      }

      const rawKey = item.kod ?? item.code ?? item.key ?? item.value ?? label;
      const key = String(rawKey).trim();
      if (!key) {
        return null;
      }

      return {
        key,
        label
      };
    })
    .filter((item): item is KeyOption => item !== null);
}

export async function fetchDepartmanOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.departmanlar);
  // Kanonik business display order (alfabetik değil) tek owner'dan uygulanır;
  // bilinmeyen/yeni departmanlar listeden düşmez.
  return sortDepartmanDisplayOptions(normalizeIdOptions(response.data));
}

export async function createDepartmanOption(ad: string): Promise<IdOption> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.departmanlar, {
    method: "POST",
    body: JSON.stringify({ ad })
  });

  const items = normalizeIdOptions(response.data);
  if (items.length === 0) {
    throw new Error("Departman kaydı oluşturuldu ama yanıt beklenen formatta değil.");
  }

  return items[0];
}

export async function fetchGorevOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.gorevler);
  return normalizeIdOptions(response.data);
}

export async function fetchBolumOptions(departmanId?: number): Promise<IdOption[]> {
  try {
    const path =
      departmanId && departmanId > 0
        ? `${endpoints.referans.bolumler}?departman_id=${departmanId}`
        : endpoints.referans.bolumler;
    const response = await apiRequest<ApiResponse<unknown>>(path);
    return normalizeIdOptions(response.data, "departman_id");
  } catch {
    return [];
  }
}

export async function fetchBirimOptions(bolumId?: number): Promise<IdOption[]> {
  try {
    const path =
      bolumId && bolumId > 0
        ? `${endpoints.referans.birimler}?bolum_id=${bolumId}`
        : endpoints.referans.birimler;
    const response = await apiRequest<ApiResponse<unknown>>(path);
    return normalizeIdOptions(response.data, "bolum_id");
  } catch {
    return [];
  }
}

export async function fetchPozisyonOptions(): Promise<IdOption[]> {
  try {
    const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.pozisyonlar);
    return normalizeIdOptions(response.data);
  } catch {
    return [];
  }
}

export async function fetchPersonelTipiOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.personelTipleri);
  return normalizeIdOptions(response.data);
}

export async function fetchSgkIsverenOptions(): Promise<IdOption[]> {
  try {
    const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.sgkIsverenler);
    return normalizeIdOptions(response.data);
  } catch {
    return [];
  }
}

/** Full SGK employer catalog for scope grants — not branch-default attachments. */
export async function fetchSgkIsverenCatalog(): Promise<
  Array<{ id: number; ad: string; kod: string | null; sirketId: number | null }>
> {
  try {
    const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.sgkIsverenler);
    const entries = extractListItems<unknown>(response.data);
    const normalizedEntries =
      entries.length > 0 ? entries : typeof response.data === "object" && response.data !== null ? [response.data] : [];
    return normalizedEntries
      .map((entry) => {
        if (typeof entry !== "object" || entry === null) {
          return null;
        }
        const item = entry as Record<string, unknown>;
        const id = typeof item.id === "number" ? item.id : Number.parseInt(String(item.id ?? ""), 10);
        if (!Number.isFinite(id) || id <= 0) {
          return null;
        }
        const adRaw = typeof item.ad === "string" ? item.ad.trim() : "";
        if (!adRaw) {
          return null;
        }
        const kod = readOptionalKisaKod(item);
        const rawSirket = item.sirket_id ?? item.sirketId;
        let sirketId: number | null = null;
        if (rawSirket !== undefined && rawSirket !== null && rawSirket !== "") {
          const parsed =
            typeof rawSirket === "number" ? rawSirket : Number.parseInt(String(rawSirket), 10);
          if (Number.isFinite(parsed) && parsed > 0) {
            sirketId = parsed;
          }
        }
        return { id, ad: adRaw, kod, sirketId };
      })
      .filter((item): item is { id: number; ad: string; kod: string | null; sirketId: number | null } => item !== null);
  } catch {
    return [];
  }
}

export async function fetchCalismaLokasyonuOptions(): Promise<IdOption[]> {
  try {
    const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.calismaLokasyonlari);
    // parentId = catalog parent branch (calisma_lokasyonlari.sube_id); independent of personel.sube_id.
    return normalizeIdOptions(response.data, "sube_id");
  } catch {
    return [];
  }
}

export type BagliAmirOption = IdOption & {
  /**
   * `users.personel_id`. `bagli_amir_id` kanonik olarak `users.id`'dir; amirin
   * personel context'ine (departman/şube) geçiş yalnız bu alan üzerinden yapılır.
   * Personel kaydına bağlı olmayan yönetim hesabında `null` kalır.
   */
  personelId: number | null;
};

/**
 * Amir lookup ekseni. Referans bundle'ı yalın `IdOption[]` taşıyabildiği için
 * `personelId` burada opsiyoneldir; bilinmiyorsa context üretilmez.
 */
export type BagliAmirLookupOption = IdOption & { personelId?: number | null };

export async function fetchBagliAmirOptions(): Promise<BagliAmirOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.bagliAmirler);
  const personelIdByUserId = readBagliAmirPersonelIds(response.data);
  return normalizeIdOptions(response.data).map((option) => ({
    ...option,
    personelId: personelIdByUserId.get(option.id) ?? null
  }));
}

/**
 * Bağlı amir user id → personel id çözümü (`users.id` → `users.personel_id`).
 * Seçenek listesinde bulunmayan ya da personel kaydı olmayan amir için `null`.
 */
export function resolveBagliAmirPersonelId(
  amirUserId: number | null | undefined,
  options: readonly BagliAmirLookupOption[]
): number | null {
  if (typeof amirUserId !== "number" || !Number.isFinite(amirUserId) || amirUserId <= 0) {
    return null;
  }

  return options.find((option) => option.id === amirUserId)?.personelId ?? null;
}

export async function fetchUcretTipiOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.ucretTipleri);
  return normalizeIdOptions(response.data);
}

export async function fetchPrimKuraliOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.primKurallari);
  return normalizeIdOptions(response.data);
}

export async function fetchSurecTuruOptions(): Promise<KeyOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.surecTurleri);
  return normalizeKeyOptions(response.data);
}

export async function fetchBildirimTuruOptions(): Promise<KeyOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.bildirimTurleri);
  return normalizeKeyOptions(response.data);
}
