import type { ApiResponse } from "../types/api";
import type {
  ComplianceUyari,
  GunlukPuantaj,
  PuantajAmirKontrolDurumu,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi,
  UpsertGunlukPuantajPayload
} from "../types/puantaj";
import { logAction } from "../audit/audit-service";
import { deriveGunTipi, deriveHesapEtkisi } from "../services/puantaj-hesap-motoru";
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

const PUANTAJ_KONTROL_DURUMU_MAP: Record<string, PuantajAmirKontrolDurumu> = {
  BEKLIYOR: "BEKLIYOR",
  AMIR_KONTROL_ETTI: "AMIR_KONTROL_ETTI",
  AMIR_KONTROL_EDILDI: "AMIR_KONTROL_ETTI"
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
    if (normalized === "false" || normalized === "0" || normalized === "hayir" || normalized === "hayır") {
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

  const beklenenGirisSaati = pickString(record, [
    "beklenen_giris_saati",
    "beklenen_giris",
    "expected_check_in"
  ]);
  const beklenenCikisSaati = pickString(record, [
    "beklenen_cikis_saati",
    "beklenen_cikis",
    "expected_check_out"
  ]);
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
  const explicitHesapEtkisi = pickLiteral(
    record,
    ["hesap_etkisi", "hesapEtkisi", "payroll_effect"],
    PUANTAJ_HESAP_ETKISI_MAP
  );
  const hesapEtkisi = deriveHesapEtkisi(
    gunTipi,
    hareketDurumu,
    dayanak,
    girisSaati,
    cikisSaati,
    explicitHesapEtkisi
  );

  const complianceUyarilari =
    normalizeComplianceUyarilari(record.compliance_uyarilari) ||
    normalizeComplianceUyarilari(record.compliance_alerts);

  const fallbackUyarilar = complianceUyarilari.length
    ? complianceUyarilari
    : normalizeComplianceUyarilari(record.uyarilar);

  const kontrolDurumu =
    pickLiteral(
      record,
      ["kontrol_durumu", "kontrolDurumu", "amir_kontrol_durumu", "amirKontrolDurumu"],
      PUANTAJ_KONTROL_DURUMU_MAP
    ) ?? "BEKLIYOR";

  return {
    personel_id: normalizedPersonelId,
    tarih: normalizedTarih,
    gun_tipi: gunTipi,
    hareket_durumu: hareketDurumu,
    dayanak,
    durumu_bildirdi_mi: pickBoolean(record, [
      "durumu_bildirdi_mi",
      "durumuBildirdiMi",
      "absence_reported"
    ]),
    durum_bildirim_aciklamasi: pickString(record, [
      "durum_bildirim_aciklamasi",
      "durumBildirimAciklamasi",
      "bildirim_aciklamasi",
      "absence_report_note"
    ]),
    hesap_etkisi: hesapEtkisi,
    beklenen_giris_saati: beklenenGirisSaati,
    beklenen_cikis_saati: beklenenCikisSaati,
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
    kontrol_durumu: kontrolDurumu,
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
  const requestBody: UpsertGunlukPuantajPayload = {
    ...payload,
    beklenen_giris_saati: payload.beklenen_giris_saati,
    beklenen_cikis_saati: payload.beklenen_cikis_saati
  };
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.puantaj.detail(personelId, tarih), {
    method: "PUT",
    body: JSON.stringify(requestBody)
  });

  const row = normalizeGunlukPuantaj(response.data, personelId, tarih);
  logAction({ action: "PUANTAJ_UPSERT", payload: { personel_id: personelId, tarih } });
  return row;
}

export type MuhurlePayload = {
  yil: number;
  ay: number;
};

export type MuhurleResponse = {
  muhur_id?: number;
  muhurlenen_kayit_sayisi: number;
  donem: string;
};

export async function muhurleAylikPuantaj(payload: MuhurlePayload): Promise<MuhurleResponse> {
  const response = await apiRequest<ApiResponse<MuhurleResponse>>(endpoints.puantaj.muhurle, {
    method: "POST",
    body: JSON.stringify(payload)
  });

  logAction({
    action: "PUANTAJ_MUHURLE",
    payload: { yil: payload.yil, ay: payload.ay }
  });

  return response.data;
}
