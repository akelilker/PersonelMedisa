import type { ApiResponse } from "../types/api";
import type {
  HaftalikKapanisPayload,
  HaftalikKapanisSnapshotSatir,
  HaftalikKapanisSonuc,
  HaftalikKapanisState
} from "../types/haftalik-kapanis";
import type { ComplianceUyari } from "../types/puantaj";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

const DEFAULT_STATE: HaftalikKapanisState = "KAPANDI";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toOptionalNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (!trimmed) {
      return undefined;
    }

    const parsed = Number.parseInt(trimmed, 10);
    return Number.isNaN(parsed) ? undefined : parsed;
  }

  return undefined;
}

function toNonNegativeNumber(value: unknown, fallback = 0): number {
  const parsed = toOptionalNumber(value);
  if (parsed === undefined || parsed < 0) {
    return fallback;
  }

  return parsed;
}

function toOptionalString(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : undefined;
}

function toBoolean(value: unknown, fallback = false): boolean {
  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    if (normalized === "true" || normalized === "1") {
      return true;
    }
    if (normalized === "false" || normalized === "0") {
      return false;
    }
  }

  return fallback;
}

function pickString(record: Record<string, unknown>, keys: string[]): string | undefined {
  for (const key of keys) {
    const value = toOptionalString(record[key]);
    if (value) {
      return value;
    }
  }

  return undefined;
}

function normalizeComplianceUyarilari(value: unknown): ComplianceUyari[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .map((entry) => {
      if (typeof entry === "string") {
        const message = entry.trim();
        if (!message) {
          return null;
        }

        return {
          code: "COMPLIANCE",
          message
        } satisfies ComplianceUyari;
      }

      const record = toRecord(entry);
      if (!record) {
        return null;
      }

      const message = pickString(record, ["message", "mesaj", "aciklama"]);
      if (!message) {
        return null;
      }

      return {
        code: pickString(record, ["code", "kod"]) ?? "COMPLIANCE",
        message,
        level: pickString(record, ["level", "seviye"])
      } satisfies ComplianceUyari;
    })
    .filter((item): item is ComplianceUyari => item !== null);
}

function isKritikComplianceLevel(level: string | undefined): boolean {
  if (!level) {
    return false;
  }

  const normalized = level.trim().toUpperCase();
  return normalized === "KRITIK" || normalized === "CRITICAL";
}

function deriveKritikUyariVarMi(
  uyarilar: readonly ComplianceUyari[],
  explicit: unknown
): boolean {
  if (typeof explicit === "boolean") {
    return explicit;
  }

  return uyarilar.some((uyari) => isKritikComplianceLevel(uyari.level));
}

function normalizeHaftalikKapanisState(value: unknown): HaftalikKapanisState {
  const raw = toOptionalString(value);
  if (raw === "KAPANDI") {
    return "KAPANDI";
  }

  return DEFAULT_STATE;
}

function normalizeStringArray(value: unknown): string[] | undefined {
  if (!Array.isArray(value)) {
    return undefined;
  }

  const items = value
    .map((entry) => (typeof entry === "string" ? entry.trim() : ""))
    .filter((entry) => entry.length > 0);

  return items.length > 0 ? items : undefined;
}

function normalizeSnapshotSatir(
  raw: unknown,
  context: {
    kapanisId?: number;
    haftaBaslangic?: string;
    haftaBitis?: string;
    departmanId?: number;
  }
): HaftalikKapanisSnapshotSatir | null {
  const record = toRecord(raw);
  if (!record) {
    return null;
  }

  const personelId = toOptionalNumber(record.personel_id);
  if (personelId === undefined) {
    return null;
  }

  const hafta_baslangic =
    toOptionalString(record.hafta_baslangic) ??
    toOptionalString(record.week_start) ??
    context.haftaBaslangic ??
    "";
  const hafta_bitis =
    toOptionalString(record.hafta_bitis) ??
    toOptionalString(record.week_end) ??
    context.haftaBitis ??
    "";

  if (!hafta_baslangic || !hafta_bitis) {
    return null;
  }

  const compliance_uyarilari = normalizeComplianceUyarilari(record.compliance_uyarilari);
  const compliance_uyari_sayisi =
    toOptionalNumber(record.compliance_uyari_sayisi) ?? compliance_uyarilari.length;
  const kapanis_id = toOptionalNumber(record.kapanis_id) ?? context.kapanisId;

  return {
    snapshot_id: toOptionalNumber(record.snapshot_id),
    kapanis_id,
    personel_id: personelId,
    departman_id: toOptionalNumber(record.departman_id) ?? context.departmanId,
    hafta_baslangic,
    hafta_bitis,
    yil: toOptionalNumber(record.yil),
    hafta_no: toOptionalNumber(record.hafta_no),
    state: normalizeHaftalikKapanisState(record.state),
    kaynak_versiyon: toOptionalString(record.kaynak_versiyon),
    toplam_net_dakika: toNonNegativeNumber(record.toplam_net_dakika),
    normal_calisma_dakika: toNonNegativeNumber(record.normal_calisma_dakika),
    fazla_calisma_dakika: toNonNegativeNumber(record.fazla_calisma_dakika),
    fazla_surelerle_calisma_dakika: toNonNegativeNumber(record.fazla_surelerle_calisma_dakika),
    tam_hafta_verisi: toBoolean(record.tam_hafta_verisi, false),
    compliance_uyarilari,
    compliance_uyari_sayisi,
    kritik_uyari_var_mi: deriveKritikUyariVarMi(
      compliance_uyarilari,
      record.kritik_uyari_var_mi
    ),
    hesaplama_zamani: toOptionalString(record.hesaplama_zamani),
    kaynak_gun_sayisi: toOptionalNumber(record.kaynak_gun_sayisi),
    notlar: normalizeStringArray(record.notlar)
  };
}

function normalizeSnapshotSatirlari(
  value: unknown,
  context: {
    kapanisId?: number;
    haftaBaslangic?: string;
    haftaBitis?: string;
    departmanId?: number;
  }
): HaftalikKapanisSnapshotSatir[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .map((entry) => normalizeSnapshotSatir(entry, context))
    .filter((row): row is HaftalikKapanisSnapshotSatir => row !== null);
}

function normalizeHaftalikKapanisSonuc(data: unknown): HaftalikKapanisSonuc {
  const record = toRecord(data) ?? {};
  const id = toOptionalNumber(record.id);
  const kapanis_id = toOptionalNumber(record.kapanis_id) ?? id;
  const hafta_baslangic =
    toOptionalString(record.hafta_baslangic) ?? toOptionalString(record.week_start);
  const hafta_bitis =
    toOptionalString(record.hafta_bitis) ?? toOptionalString(record.week_end);
  const departman_id = toOptionalNumber(record.departman_id);

  const snapshot_satirlari = normalizeSnapshotSatirlari(record.snapshot_satirlari, {
    kapanisId: kapanis_id,
    haftaBaslangic: hafta_baslangic,
    haftaBitis: hafta_bitis,
    departmanId: departman_id
  });

  const snapshot_satir_sayisi =
    toOptionalNumber(record.snapshot_satir_sayisi) ?? snapshot_satirlari.length;

  return {
    id,
    kapanis_id,
    hafta_baslangic,
    hafta_bitis,
    departman_id,
    state: normalizeHaftalikKapanisState(
      record.state ?? record.durum ?? DEFAULT_STATE
    ),
    personel_sayisi: toOptionalNumber(record.personel_sayisi),
    snapshot_satir_sayisi,
    snapshot_satirlari
  };
}

export async function createHaftalikKapanis(
  payload: HaftalikKapanisPayload
): Promise<HaftalikKapanisSonuc> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.haftalikKapanis.close, {
    method: "POST",
    body: JSON.stringify(payload)
  });

  const sonuc = normalizeHaftalikKapanisSonuc(response.data);
  logAction({
    action: "HAFTALIK_KAPANIS_CLOSE",
    payload: { hafta_baslangic: payload.hafta_baslangic }
  });
  return sonuc;
}

export async function fetchHaftalikKapanisDetail(
  kapanisId: number | string
): Promise<HaftalikKapanisSonuc> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.haftalikKapanis.detail(kapanisId)
  );
  return normalizeHaftalikKapanisSonuc(response.data);
}
