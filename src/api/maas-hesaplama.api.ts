import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type MaasHesaplamaSeverity = "BLOCKER" | "WARNING" | "INFO";

export type MaasHesaplamaIssue = {
  severity: MaasHesaplamaSeverity;
  code: string;
  message: string;
  record_type: string;
  record_id: number | null;
  personel_id: number | null;
  personel_adi: string | null;
  metadata: Record<string, unknown>;
};

export type MaasHesaplamaPersonelSummary = {
  personel_id: number;
  ad_soyad: string;
  istihdam_baslangic: string;
  istihdam_bitis: string;
  ucret_segment_sayisi: number;
  puantaj_kayit_sayisi: number;
  finans_kalem_sayisi: number;
  hazir_mi: boolean;
  blocker_count: number;
  warning_count: number;
  sgk_sonucu: {
    hesaplanan_prim_gunu: number | null;
    eksik_gun_sayisi: number | null;
    eksik_gun_kodu: string | null;
    eksik_gun_aciklamasi: string | null;
    ucret_modeli: string | null;
    sgk_odenek_durumu: string | null;
    manuel_inceleme_gerekli_mi: boolean;
    blocker_kodlari: string[];
    sgk_hesap_hash: string;
    katalog_surumu: string | null;
    kaynak_manifest_hash: string | null;
  } | null;
};

export type MaasHesaplamaPreflight = {
  sube: { id: number; ad: string; kod?: string } | null;
  yil: number;
  ay: number;
  donem: string;
  donem_baslangic: string;
  donem_bitis: string;
  muhur: {
    id: number;
    durum: string;
    muhurlenen_kayit_sayisi: number;
    created_at: string;
  } | null;
  snapshot_olusturulabilir_mi: boolean;
  blocker_count: number;
  warning_count: number;
  info_count: number;
  items: MaasHesaplamaIssue[];
  personel_summary: MaasHesaplamaPersonelSummary[];
  source_summary: Record<string, unknown>;
  existing_snapshot: {
    id: number;
    state: string;
    revision_no: number;
    source_hash: string;
    snapshot_hash: string;
    created_at: string;
    source_changed: boolean;
  } | null;
  preflight_hash: string;
  source_hash: string;
  hashes: Record<string, string>;
  schema_version: string;
  contract_version: string;
  generated_at: string;
};

export type MaasHesaplamaSnapshot = {
  id: number;
  snapshot_id: number;
  sube_id: number;
  yil: number;
  ay: number;
  donem: string;
  donem_baslangic: string;
  donem_bitis: string;
  muhur_id: number;
  revision_no: number;
  parent_snapshot_id: number | null;
  state: string;
  contract_version: string;
  cutoff_at: string;
  preflight_hash: string;
  source_hash: string;
  snapshot_hash: string;
  personel_sayisi: number;
  girdi_sayisi: number;
  blocker_count: number;
  warning_count: number;
  created_by: number | null;
  created_at: string;
  iptal_edildi_by: number | null;
  iptal_edildi_at: string | null;
  iptal_nedeni: string | null;
};

export type MaasHesaplamaAudit = {
  id: number;
  donem_snapshot_id: number | null;
  sube_id: number;
  yil: number;
  ay: number;
  muhur_id: number | null;
  aksiyon: string;
  sonuc: string;
  actor_id: number | null;
  actor_rol: string | null;
  request_hash: string;
  preflight_hash: string | null;
  source_hash: string | null;
  result_hash: string | null;
  blocker_count: number;
  warning_count: number;
  created_at: string;
};

export type MaasHesaplamaCalculationPreflight = {
  snapshot_id: number;
  sube_id: number;
  yil: number;
  ay: number;
  donem: string;
  hesaplanabilir_mi: boolean;
  blocker_count: number;
  warning_count: number;
  info_count: number;
  items: MaasHesaplamaIssue[];
  personel_summary: Array<Record<string, unknown>>;
  parameter_summary: Record<string, unknown>;
  engine_version: string;
  contract_version: string;
  calculation_input_hash: string;
  source_hash: string;
  parameter_set_hash: string;
  carryover_set_hash: string;
  snapshot_hash: string;
  existing_calculation: {
    id: number;
    revision_no: number;
    state: string;
    source_hash: string;
    result_hash: string;
  } | null;
};

export type MaasHesaplamaCalistirma = {
  id: number;
  calistirma_id?: number;
  donem_snapshot_id?: number;
  snapshot_id?: number;
  sube_id: number;
  yil: number;
  ay: number;
  donem?: string;
  revision_no: number;
  state: string;
  engine_version?: string | null;
  contract_version?: string | null;
  calculation_input_hash?: string | null;
  source_hash?: string | null;
  result_hash?: string | null;
  personel_sayisi?: number;
  aday_sayisi?: number;
  toplam_net?: number | null;
  toplam_brut?: number | null;
  toplam_gelir_vergisi?: number | null;
  toplam_sgk?: number | null;
  created_at?: string;
  iptal_edildi_at?: string | null;
  iptal_nedeni?: string | null;
};

export type MaasHesaplamaAday = {
  id: number;
  aday_id?: number;
  calistirma_id?: number;
  personel_id: number;
  personel_ad_soyad?: string | null;
  personel_adi?: string | null;
  sicil_no?: string | null;
  state?: string;
  brut_ucret?: number | null;
  brut?: number | null;
  net_ucret?: number | null;
  net?: number | null;
  gelir_vergisi?: number | null;
  gv?: number | null;
  sgk_primi?: number | null;
  sgk?: number | null;
  toplam_isci_sgk?: number | null;
  result_hash?: string | null;
  created_at?: string;
};

export type MaasHesaplamaKalem = {
  id: number;
  aday_id?: number;
  kod?: string | null;
  kalem_kodu?: string | null;
  ad?: string | null;
  kalem_adi?: string | null;
  kategori?: string | null;
  tur?: string | null;
  tutar?: number | null;
  matrah?: number | null;
  metadata?: Record<string, unknown> | null;
};

export type MaasHesaplamaYasalKatalog = {
  engine_version?: string;
  contract_version?: string;
  items?: Array<Record<string, unknown>>;
  [key: string]: unknown;
};

export type MaasHesaplamaDevir = {
  id: number;
  personel_id: number;
  personel_ad_soyad?: string | null;
  sube_id: number;
  yil: number;
  ay: number;
  onceki_kumulatif_gelir_vergisi_matrahi: number;
  onceki_kumulatif_gelir_vergisi: number;
  onceki_kumulatif_sgk_matrahi?: number | null;
  kaynak?: string | null;
  aciklama?: string | null;
  created_at?: string;
  updated_at?: string;
};

export type MaasHesaplamaSnapshotDetail = MaasHesaplamaSnapshot & {
  personeller?: unknown[];
  girdi_ozet?: Record<string, number>;
  girdiler?: unknown[];
  hash_dogrulama?: { dogrulandi: boolean; hesaplanan_snapshot_hash?: string };
};

export type MaasHesaplamaParams = {
  sube_id: number;
  yil: number;
  ay: number;
};

export type SgkPrimGunuSonucu = {
  id: number;
  snapshot_id: number;
  snapshot_revision_no: number;
  personel_snapshot_id: number;
  personel_id: number;
  yil: number;
  ay: number;
  donem: string;
  hesaplanan_prim_gunu: number | null;
  eksik_gun_sayisi: number | null;
  eksik_gun_kodu: string | null;
  eksik_gun_aciklamasi: string | null;
  kaynak_surec_idleri: number[];
  kaynak_puantaj_idleri: number[];
  kaynak_belge_idleri: number[];
  katalog_surumu: string | null;
  kaynak_manifest_hash: string | null;
  sgk_hesap_hash: string;
  gunluk_karar_dokumu_hash: string;
  manuel_inceleme_gerekli_mi: boolean;
  blocker_kodlari: string[];
  blocker_detaylari: Array<Record<string, unknown>>;
  ucret_modeli: string;
  ilk_iki_gun_politika_ozeti: Record<string, unknown>;
  sirket_politika_surum_id: number | null;
  sirket_politika_hash: string | null;
  sgk_odenek_durumu: string;
  is_goremezlik_finans_ozeti: Array<Record<string, unknown>>;
  gunluk_alt_sinir: string | null;
  gunluk_ust_sinir: string | null;
  donem_alt_sinir: string | null;
  donem_ust_sinir: string | null;
  sinir_mevzuat_surumu: string | null;
  source_hash: string;
  ad?: string;
  soyad?: string;
  sicil_no?: string | null;
};

function assertNoMaasApiErrors(response: ApiResponse<unknown>, fallback: string): void {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    const first = response.errors[0] as { code?: string; message?: string };
    throw new ApiRequestError(first.message ?? fallback, 409, { code: first.code ?? "PAYROLL_ERROR" });
  }
}

function unwrapData<T>(payload: ApiResponse<T> | T, fallback: string): T {
  if (typeof payload === "object" && payload !== null && "data" in payload) {
    assertNoMaasApiErrors(payload as ApiResponse<unknown>, fallback);
    return (payload as ApiResponse<T>).data;
  }
  return payload as T;
}

export async function fetchMaasHesaplamaPreflight(
  params: MaasHesaplamaParams
): Promise<MaasHesaplamaPreflight> {
  const path = appendQueryParams(endpoints.maasHesaplama.preflight, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<ApiResponse<MaasHesaplamaPreflight> | MaasHesaplamaPreflight>(path);
  return unwrapData(response, "Preflight alinamadi.");
}

export async function fetchMaasHesaplamaSnapshots(
  params: MaasHesaplamaParams
): Promise<MaasHesaplamaSnapshot[]> {
  const path = appendQueryParams(endpoints.maasHesaplama.snapshots, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<
    ApiResponse<{ items: MaasHesaplamaSnapshot[] }> | { items: MaasHesaplamaSnapshot[] }
  >(path);
  return unwrapData(response, "Snapshot listesi alinamadi.").items ?? [];
}

export async function fetchSgkPrimGunuSonuclari(
  params: MaasHesaplamaParams & { personel_id?: number }
): Promise<SgkPrimGunuSonucu[]> {
  const path = appendQueryParams(endpoints.maasHesaplama.sgkSonuclari, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay,
    personel_id: params.personel_id
  });
  const response = await apiRequest<
    ApiResponse<{ items: SgkPrimGunuSonucu[] }> | { items: SgkPrimGunuSonucu[] }
  >(path);
  return unwrapData(response, "SGK prim gunu sonuclari alinamadi.").items ?? [];
}

export async function downloadSgkPrimGunuSonuclariCsv(params: MaasHesaplamaParams): Promise<void> {
  const path = appendQueryParams(endpoints.maasHesaplama.sgkSonuclariExportCsv, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const { resolveDemoApiResponse } = await import("./mock-demo");
  const { buildApiUrl } = await import("./api-client");
  const { getAuthTokenForApi } = await import("../auth/auth-token-provider");
  const { getActiveSubeIdForApiHeader } = await import("../auth/auth-manager");

  let blob: Blob;
  const demoResponse = resolveDemoApiResponse(path, { method: "GET" });
  if (demoResponse !== null) {
    if (typeof demoResponse.data !== "string") {
      throw new ApiRequestError("SGK CSV indirilemedi.", 403);
    }
    blob = new Blob([demoResponse.data], { type: "text/csv;charset=utf-8" });
  } else {
    const headers = new Headers();
    const token = getAuthTokenForApi();
    if (token) {
      headers.set("Authorization", `Bearer ${token}`);
    }
    const subeHeader = getActiveSubeIdForApiHeader();
    if (subeHeader) {
      headers.set("X-Active-Sube-Id", subeHeader);
    }
    const response = await fetch(buildApiUrl(path), { headers });
    if (!response.ok) {
      throw new ApiRequestError("SGK CSV indirilemedi.", response.status);
    }
    blob = await response.blob();
  }

  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = `sgk-kontrol-${params.yil}-${String(params.ay).padStart(2, "0")}-sube-${params.sube_id}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
}

export async function fetchMaasHesaplamaSnapshotDetail(
  snapshotId: number,
  includePayloads = false
): Promise<MaasHesaplamaSnapshotDetail> {
  const path = appendQueryParams(endpoints.maasHesaplama.snapshotDetail(snapshotId), {
    ...(includePayloads ? { include_payloads: 1 } : {})
  });
  const response = await apiRequest<ApiResponse<MaasHesaplamaSnapshotDetail> | MaasHesaplamaSnapshotDetail>(
    path
  );
  return unwrapData(response, "Snapshot detayi alinamadi.");
}

export async function fetchMaasHesaplamaCalculationPreflight(
  snapshotId: number
): Promise<MaasHesaplamaCalculationPreflight> {
  const response = await apiRequest<
    ApiResponse<MaasHesaplamaCalculationPreflight> | MaasHesaplamaCalculationPreflight
  >(endpoints.maasHesaplama.snapshotCalculationPreflight(snapshotId));
  return unwrapData(response, "Hesaplama preflight alinamadi.");
}

export async function calculateMaasHesaplamaSnapshot(input: {
  snapshot_id: number;
  expected_calculation_input_hash: string;
  engine_version?: string;
}): Promise<{ calistirma?: MaasHesaplamaCalistirma; idempotent?: boolean; audit?: MaasHesaplamaAudit | null }> {
  const response = await apiRequest<
    | ApiResponse<{ calistirma?: MaasHesaplamaCalistirma; idempotent?: boolean; audit?: MaasHesaplamaAudit | null }>
    | { calistirma?: MaasHesaplamaCalistirma; idempotent?: boolean; audit?: MaasHesaplamaAudit | null }
  >(endpoints.maasHesaplama.calculateSnapshot(input.snapshot_id), {
    method: "POST",
    body: JSON.stringify({
      expected_calculation_input_hash: input.expected_calculation_input_hash,
      ...(input.engine_version ? { engine_version: input.engine_version } : {})
    })
  });
  return unwrapData(response, "Maas hesaplama calistirilamadi.");
}

export async function createMaasHesaplamaSnapshot(input: {
  sube_id: number;
  yil: number;
  ay: number;
  expected_preflight_hash: string;
}): Promise<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }> {
  const response = await apiRequest<
    | ApiResponse<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }>
    | { snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }
  >(endpoints.maasHesaplama.snapshots, {
    method: "POST",
    body: JSON.stringify(input)
  });
  return unwrapData(response, "Snapshot olusturulamadi.");
}

export async function cancelMaasHesaplamaSnapshot(
  snapshotId: number,
  neden: string
): Promise<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }> {
  const response = await apiRequest<
    | ApiResponse<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }>
    | { snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }
  >(endpoints.maasHesaplama.cancel(snapshotId), {
    method: "POST",
    body: JSON.stringify({ neden })
  });
  return unwrapData(response, "Snapshot iptal edilemedi.");
}

export async function fetchMaasHesaplamaAudits(
  params: MaasHesaplamaParams
): Promise<MaasHesaplamaAudit[]> {
  const path = appendQueryParams(endpoints.maasHesaplama.audits, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<ApiResponse<{ items: MaasHesaplamaAudit[] }> | { items: MaasHesaplamaAudit[] }>(
    path
  );
  return unwrapData(response, "Audit listesi alinamadi.").items ?? [];
}

export async function fetchMaasHesaplamaCalistirmalar(
  params: MaasHesaplamaParams
): Promise<MaasHesaplamaCalistirma[]> {
  const path = appendQueryParams(endpoints.maasHesaplama.calistirmalar, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<
    ApiResponse<{ items: MaasHesaplamaCalistirma[] }> | { items: MaasHesaplamaCalistirma[] }
  >(path);
  return unwrapData(response, "Calistirma listesi alinamadi.").items ?? [];
}

export async function fetchMaasHesaplamaCalistirmaDetail(
  calistirmaId: number
): Promise<MaasHesaplamaCalistirma> {
  const response = await apiRequest<ApiResponse<MaasHesaplamaCalistirma> | MaasHesaplamaCalistirma>(
    endpoints.maasHesaplama.calistirmaDetail(calistirmaId)
  );
  return unwrapData(response, "Calistirma detayi alinamadi.");
}

export async function fetchMaasHesaplamaCalistirmaAdaylari(
  calistirmaId: number
): Promise<MaasHesaplamaAday[]> {
  const response = await apiRequest<ApiResponse<{ items: MaasHesaplamaAday[] }> | { items: MaasHesaplamaAday[] }>(
    endpoints.maasHesaplama.calistirmaAdaylar(calistirmaId)
  );
  return unwrapData(response, "Aday listesi alinamadi.").items ?? [];
}

export async function fetchMaasHesaplamaCalistirmaAudit(
  calistirmaId: number
): Promise<MaasHesaplamaAudit[]> {
  const response = await apiRequest<ApiResponse<{ items: MaasHesaplamaAudit[] }> | { items: MaasHesaplamaAudit[] }>(
    endpoints.maasHesaplama.calistirmaAudit(calistirmaId)
  );
  return unwrapData(response, "Calistirma audit listesi alinamadi.").items ?? [];
}

export async function cancelMaasHesaplamaCalistirma(
  calistirmaId: number,
  neden: string
): Promise<{ calistirma?: MaasHesaplamaCalistirma; idempotent?: boolean; audit?: MaasHesaplamaAudit | null }> {
  const response = await apiRequest<
    | ApiResponse<{ calistirma?: MaasHesaplamaCalistirma; idempotent?: boolean; audit?: MaasHesaplamaAudit | null }>
    | { calistirma?: MaasHesaplamaCalistirma; idempotent?: boolean; audit?: MaasHesaplamaAudit | null }
  >(endpoints.maasHesaplama.cancelCalistirma(calistirmaId), {
    method: "POST",
    body: JSON.stringify({ neden })
  });
  return unwrapData(response, "Calistirma iptal edilemedi.");
}

export async function fetchMaasHesaplamaAdayDetail(adayId: number): Promise<MaasHesaplamaAday> {
  const response = await apiRequest<ApiResponse<MaasHesaplamaAday> | MaasHesaplamaAday>(
    endpoints.maasHesaplama.adayDetail(adayId)
  );
  return unwrapData(response, "Aday detayi alinamadi.");
}

export async function fetchMaasHesaplamaAdayKalemler(adayId: number): Promise<MaasHesaplamaKalem[]> {
  const response = await apiRequest<ApiResponse<{ items: MaasHesaplamaKalem[] }> | { items: MaasHesaplamaKalem[] }>(
    endpoints.maasHesaplama.adayKalemler(adayId)
  );
  return unwrapData(response, "Aday kalemleri alinamadi.").items ?? [];
}

export async function fetchMaasHesaplamaYasalKatalog(): Promise<MaasHesaplamaYasalKatalog> {
  const response = await apiRequest<ApiResponse<MaasHesaplamaYasalKatalog> | MaasHesaplamaYasalKatalog>(
    endpoints.maasHesaplama.yasalKatalog
  );
  return unwrapData(response, "Yasal katalog alinamadi.");
}

export async function fetchMaasHesaplamaDevirler(params: MaasHesaplamaParams): Promise<MaasHesaplamaDevir[]> {
  const path = appendQueryParams(endpoints.maasHesaplama.devirler, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<ApiResponse<{ items: MaasHesaplamaDevir[] }> | { items: MaasHesaplamaDevir[] }>(
    path
  );
  return unwrapData(response, "Devir listesi alinamadi.").items ?? [];
}

export async function upsertMaasHesaplamaDevir(input: {
  personel_id: number;
  sube_id: number;
  yil: number;
  ay: number;
  onceki_kumulatif_gelir_vergisi_matrahi: number;
  onceki_kumulatif_gelir_vergisi: number;
  onceki_kumulatif_sgk_matrahi?: number;
  kaynak?: string;
  aciklama?: string;
}): Promise<MaasHesaplamaDevir> {
  const response = await apiRequest<ApiResponse<MaasHesaplamaDevir> | MaasHesaplamaDevir>(
    endpoints.maasHesaplama.devirler,
    {
      method: "POST",
      body: JSON.stringify(input)
    }
  );
  return unwrapData(response, "Devir kaydedilemedi.");
}
