import type { ApiResponse } from "../types/api";
import type { ComplianceUyari, GunlukPuantaj, UpsertGunlukPuantajPayload } from "../types/puantaj";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : undefined;
}

function toNumberValue(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (!trimmed) {
      return undefined;
    }

    const parsed = Number(trimmed);
    return Number.isFinite(parsed) ? parsed : undefined;
  }

  return undefined;
}

function pickString(record: Record<string, unknown>, keys: string[]): string | undefined {
  for (const key of keys) {
    const value = toStringValue(record[key]);
    if (value !== undefined) {
      return value;
    }
  }

  return undefined;
}

function pickNumber(record: Record<string, unknown>, keys: string[]): number | undefined {
  for (const key of keys) {
    const value = toNumberValue(record[key]);
    if (value !== undefined) {
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

function normalizeGunlukPuantaj(
  data: unknown,
  personelId: number,
  tarih: string
): GunlukPuantaj {
  const record = toRecord(data) ?? {};

  const normalizedPersonelId =
    pickNumber(record, ["personel_id", "personelId", "id"]) ?? personelId;
  const normalizedTarih = pickString(record, ["tarih", "date"]) ?? tarih;

  const complianceUyarilari =
    normalizeComplianceUyarilari(record.compliance_uyarilari) ||
    normalizeComplianceUyarilari(record.compliance_alerts);

  const fallbackUyarilar = complianceUyarilari.length
    ? complianceUyarilari
    : normalizeComplianceUyarilari(record.uyarilar);

  return {
    personel_id: normalizedPersonelId,
    tarih: normalizedTarih,
    giris_saati: pickString(record, ["giris_saati", "giris", "check_in"]),
    cikis_saati: pickString(record, ["cikis_saati", "cikis", "check_out"]),
    gercek_mola_dakika: pickNumber(record, ["gercek_mola_dakika", "gercek_mola", "break_minutes"]),
    hesaplanan_mola_dakika: pickNumber(record, [
      "hesaplanan_mola_dakika",
      "gunluk_mola_dusumu",
      "calculated_break_minutes"
    ]),
    net_calisma_suresi_dakika: pickNumber(record, [
      "net_calisma_suresi_dakika",
      "gunluk_net_calisma_suresi",
      "net_work_duration_minutes"
    ]),
    gunluk_brut_sure_dakika: pickNumber(record, [
      "gunluk_brut_sure_dakika",
      "gunluk_brut_sure",
      "gross_duration_minutes"
    ]),
    state: pickString(record, ["state", "durum"]),
    compliance_uyarilari: fallbackUyarilar
  };
}

export async function fetchGunlukPuantaj(
  personelId: number,
  tarih: string
): Promise<GunlukPuantaj | null> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.puantaj.detail(personelId, tarih));
  if (response.data === null) {
    return null;
  }

  return normalizeGunlukPuantaj(response.data, personelId, tarih);
}

export async function upsertGunlukPuantaj(
  personelId: number,
  tarih: string,
  payload: UpsertGunlukPuantajPayload
): Promise<GunlukPuantaj> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.puantaj.detail(personelId, tarih), {
    method: "PUT",
    body: JSON.stringify(payload)
  });

  const row = normalizeGunlukPuantaj(response.data, personelId, tarih);
  logAction({ action: "PUANTAJ_UPSERT", payload: { personel_id: personelId, tarih } });
  return row;
}
