import type { ApiResponse } from "../types/api";
import type {
  ComplianceUyari,
  GunlukPuantaj,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi,
  UpsertGunlukPuantajPayload
} from "../types/puantaj";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

const PUANTAJ_GUN_TIPI_MAP: Record<string, PuantajGunTipi> = {
  NORMAL_IS_GUNU: "Normal_Is_Gunu",
  HAFTA_TATILI_PAZAR: "Hafta_Tatili_Pazar",
  UBGT_RESMI_TATIL: "UBGT_Resmi_Tatil"
};

const PUANTAJ_HAREKET_DURUMU_MAP: Record<string, PuantajHareketDurumu> = {
  GELDI: "Geldi",
  GELMEDI: "Gelmedi",
  GEC_GELDI: "Gec_Geldi",
  ERKEN_CIKTI: "Erken_Cikti"
};

const PUANTAJ_DAYANAK_MAP: Record<string, PuantajDayanak> = {
  YOK_IZINSIZ: "Yok_Izinsiz",
  UCRETLI_IZINLI: "Ucretli_Izinli",
  RAPORLU_HASTALIK: "Raporlu_Hastalik",
  RAPORLU_IS_KAZASI: "Raporlu_Is_Kazasi",
  YILLIK_IZIN: "Yillik_Izin",
  TELAFI_CALISMASI: "Telafi_Calismasi"
};

const PUANTAJ_HESAP_ETKISI_MAP: Record<string, PuantajHesapEtkisi> = {
  KESINTI_YAP: "Kesinti_Yap",
  TAM_YEVMIYE_VER: "Tam_Yevmiye_Ver",
  MESAI_YAZ: "Mesai_Yaz"
};

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

function toBooleanValue(value: unknown): boolean | undefined {
  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value === "number") {
    if (value === 1) {
      return true;
    }
    if (value === 0) {
      return false;
    }
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    if (normalized === "true" || normalized === "1" || normalized === "evet") {
      return true;
    }
    if (normalized === "false" || normalized === "0" || normalized === "hayir") {
      return false;
    }
  }

  return undefined;
}

function normalizeLiteralToken(value: unknown): string | undefined {
  const stringValue = toStringValue(value);
  if (!stringValue) {
    return undefined;
  }

  return stringValue.replace(/[\s-]+/g, "_").toUpperCase();
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

function pickBoolean(record: Record<string, unknown>, keys: string[]): boolean | undefined {
  for (const key of keys) {
    const value = toBooleanValue(record[key]);
    if (value !== undefined) {
      return value;
    }
  }

  return undefined;
}

function pickLiteral<T extends string>(
  record: Record<string, unknown>,
  keys: string[],
  valueMap: Record<string, T>
): T | undefined {
  for (const key of keys) {
    const token = normalizeLiteralToken(record[key]);
    if (token && valueMap[token]) {
      return valueMap[token];
    }
  }

  return undefined;
}

function parsePuantajDate(value: string): Date | null {
  const trimmed = value.trim();
  const dateOnlyMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec(trimmed);
  if (dateOnlyMatch) {
    return new Date(
      Number.parseInt(dateOnlyMatch[1], 10),
      Number.parseInt(dateOnlyMatch[2], 10) - 1,
      Number.parseInt(dateOnlyMatch[3], 10)
    );
  }

  const parsed = Date.parse(trimmed);
  if (Number.isNaN(parsed)) {
    return null;
  }

  return new Date(parsed);
}

function deriveGunTipi(tarih: string, explicit?: PuantajGunTipi): PuantajGunTipi {
  if (explicit) {
    return explicit;
  }

  const parsedDate = parsePuantajDate(tarih);
  if (parsedDate?.getDay() === 0) {
    return "Hafta_Tatili_Pazar";
  }

  return "Normal_Is_Gunu";
}

function deriveHareketDurumu(params: {
  explicit?: PuantajHareketDurumu;
  girisSaati?: string;
  cikisSaati?: string;
  explicitDayanak?: PuantajDayanak;
}): PuantajHareketDurumu {
  if (params.explicit) {
    return params.explicit;
  }

  if (params.girisSaati || params.cikisSaati) {
    return "Geldi";
  }

  if (params.explicitDayanak) {
    return "Gelmedi";
  }

  return "Gelmedi";
}

function deriveDayanak(params: {
  explicit?: PuantajDayanak;
  hareketDurumu: PuantajHareketDurumu;
  girisSaati?: string;
  cikisSaati?: string;
}): PuantajDayanak | undefined {
  if (params.explicit) {
    return params.explicit;
  }

  if (!params.girisSaati && !params.cikisSaati && params.hareketDurumu === "Gelmedi") {
    return "Yok_Izinsiz";
  }

  return undefined;
}

function deriveHesapEtkisi(params: {
  explicit?: PuantajHesapEtkisi;
  gunTipi: PuantajGunTipi;
  hareketDurumu: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  girisSaati?: string;
  cikisSaati?: string;
}): PuantajHesapEtkisi | undefined {
  if (params.explicit) {
    return params.explicit;
  }

  if (params.hareketDurumu === "Gelmedi" && params.dayanak === "Yok_Izinsiz") {
    return "Kesinti_Yap";
  }

  if (
    (params.gunTipi === "Hafta_Tatili_Pazar" || params.gunTipi === "UBGT_Resmi_Tatil") &&
    (params.girisSaati || params.cikisSaati)
  ) {
    return "Mesai_Yaz";
  }

  if (params.dayanak && params.dayanak !== "Yok_Izinsiz") {
    return "Tam_Yevmiye_Ver";
  }

  if (
    params.hareketDurumu === "Geldi" ||
    params.hareketDurumu === "Gec_Geldi" ||
    params.hareketDurumu === "Erken_Cikti"
  ) {
    return "Tam_Yevmiye_Ver";
  }

  return undefined;
}

function deriveHaftaTatiliHakKazandiMi(params: {
  explicit?: boolean;
  hareketDurumu: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
}): boolean | undefined {
  if (params.explicit !== undefined) {
    return params.explicit;
  }

  if (params.hareketDurumu === "Gelmedi" && params.dayanak === "Yok_Izinsiz") {
    return false;
  }

  if (
    params.hareketDurumu !== "Gelmedi" ||
    (params.dayanak !== undefined && params.dayanak !== "Yok_Izinsiz")
  ) {
    return true;
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

  const girisSaati = pickString(record, ["giris_saati", "giris", "check_in"]);
  const cikisSaati = pickString(record, ["cikis_saati", "cikis", "check_out"]);

  const explicitGunTipi = pickLiteral(record, ["gun_tipi", "gunTipi", "day_type"], PUANTAJ_GUN_TIPI_MAP);
  const explicitDayanak = pickLiteral(
    record,
    ["dayanak", "mazeret", "basis", "absence_reason"],
    PUANTAJ_DAYANAK_MAP
  );
  const hareketDurumu = deriveHareketDurumu({
    explicit: pickLiteral(
      record,
      ["hareket_durumu", "hareketDurumu", "movement_status"],
      PUANTAJ_HAREKET_DURUMU_MAP
    ),
    girisSaati,
    cikisSaati,
    explicitDayanak
  });
  const dayanak = deriveDayanak({
    explicit: explicitDayanak,
    hareketDurumu,
    girisSaati,
    cikisSaati
  });
  const gunTipi = deriveGunTipi(normalizedTarih, explicitGunTipi);
  const hesapEtkisi = deriveHesapEtkisi({
    explicit: pickLiteral(
      record,
      ["hesap_etkisi", "hesapEtkisi", "payroll_effect"],
      PUANTAJ_HESAP_ETKISI_MAP
    ),
    gunTipi,
    hareketDurumu,
    dayanak,
    girisSaati,
    cikisSaati
  });

  const complianceUyarilari =
    normalizeComplianceUyarilari(record.compliance_uyarilari) ||
    normalizeComplianceUyarilari(record.compliance_alerts);

  const fallbackUyarilar = complianceUyarilari.length
    ? complianceUyarilari
    : normalizeComplianceUyarilari(record.uyarilar);

  return {
    personel_id: normalizedPersonelId,
    tarih: normalizedTarih,
    gun_tipi: gunTipi,
    hareket_durumu: hareketDurumu,
    dayanak,
    hesap_etkisi: hesapEtkisi,
    giris_saati: girisSaati,
    cikis_saati: cikisSaati,
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
    hafta_tatili_hak_kazandi_mi: deriveHaftaTatiliHakKazandiMi({
      explicit: pickBoolean(record, [
        "hafta_tatili_hak_kazandi_mi",
        "haftaTatiliHakKazandiMi",
        "week_rest_eligible"
      ]),
      hareketDurumu,
      dayanak
    }),
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
