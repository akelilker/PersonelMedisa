import type { ApiResponse } from "../types/api";
import type {
  DisiplinNihaiKararPayload,
  DisiplinSavunmaBelgePayload,
  DisiplinSavunmaTalepPayload,
  DisiplinVaka,
  DisiplinVakaAudit,
  DisiplinVakaGenerateResult
} from "../types/disiplin-vaka";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type DisiplinVakalarListParams = {
  personel_id?: number;
  surec_id?: number;
  ay?: string;
  open_only?: boolean | 0 | 1;
};

function normalizeDisiplinVaka(data: unknown): DisiplinVaka {
  if (typeof data !== "object" || data === null) {
    throw new Error("Disiplin vaka yaniti beklenen formatta degil.");
  }
  const vaka = data as Partial<DisiplinVaka>;
  if (typeof vaka.id !== "number" || typeof vaka.surec_id !== "number") {
    throw new Error("Disiplin vaka yaniti eksik alan iceriyor.");
  }
  return vaka as DisiplinVaka;
}

export async function fetchDisiplinVakalarList(
  params?: DisiplinVakalarListParams
): Promise<DisiplinVaka[]> {
  const path = appendQueryParams(endpoints.disiplinVakalar.list, {
    personel_id: params?.personel_id,
    surec_id: params?.surec_id,
    ay: params?.ay,
    open_only: params?.open_only === undefined ? undefined : params.open_only ? 1 : 0
  });
  const response = await apiRequest<ApiResponse<{ items?: unknown[] }>>(path);
  const items = response.data?.items;
  if (!Array.isArray(items)) {
    return [];
  }
  return items.map(normalizeDisiplinVaka);
}

export async function fetchDisiplinVakaDetail(
  vakaId: number | string
): Promise<{ item: DisiplinVaka; audits: DisiplinVakaAudit[] }> {
  const response = await apiRequest<
    ApiResponse<{ item?: unknown; audits?: unknown[] }>
  >(endpoints.disiplinVakalar.detail(vakaId));
  const item = normalizeDisiplinVaka(response.data?.item);
  const audits = Array.isArray(response.data?.audits)
    ? (response.data.audits as DisiplinVakaAudit[])
    : [];
  return { item, audits };
}

export async function generateDisiplinVakalar(payload: {
  ay: string;
  sube_id?: number;
  personel_id?: number;
}): Promise<DisiplinVakaGenerateResult> {
  const response = await apiRequest<ApiResponse<DisiplinVakaGenerateResult>>(
    endpoints.disiplinVakalar.generate,
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  const data = response.data;
  if (!data || typeof data.ay !== "string") {
    throw new Error("Disiplin aday uretim yaniti beklenen formatta degil.");
  }
  return {
    ...data,
    items: Array.isArray(data.items) ? data.items.map(normalizeDisiplinVaka) : []
  };
}

export async function ikIncelemeDisiplinVaka(
  vakaId: number | string,
  note?: string
): Promise<DisiplinVaka> {
  const response = await apiRequest<ApiResponse<{ item?: unknown }>>(
    endpoints.disiplinVakalar.ikInceleme(vakaId),
    {
      method: "POST",
      body: JSON.stringify(note ? { note } : {})
    }
  );
  return normalizeDisiplinVaka(response.data?.item);
}

export async function savunmaTalepDisiplinVaka(
  vakaId: number | string,
  payload: DisiplinSavunmaTalepPayload
): Promise<DisiplinVaka> {
  const response = await apiRequest<ApiResponse<{ item?: unknown }>>(
    endpoints.disiplinVakalar.savunmaTalep(vakaId),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  return normalizeDisiplinVaka(response.data?.item);
}

export async function savunmaBelgeDisiplinVaka(
  vakaId: number | string,
  payload: DisiplinSavunmaBelgePayload
): Promise<DisiplinVaka> {
  const response = await apiRequest<ApiResponse<{ item?: unknown }>>(
    endpoints.disiplinVakalar.savunmaBelge(vakaId),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  return normalizeDisiplinVaka(response.data?.item);
}

export async function nihaiKararDisiplinVaka(
  vakaId: number | string,
  payload: DisiplinNihaiKararPayload
): Promise<DisiplinVaka> {
  const response = await apiRequest<ApiResponse<{ item?: unknown }>>(
    endpoints.disiplinVakalar.nihaiKarar(vakaId),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  return normalizeDisiplinVaka(response.data?.item);
}

export async function islemsizKapatDisiplinVaka(
  vakaId: number | string,
  gerekce?: string
): Promise<DisiplinVaka> {
  const response = await apiRequest<ApiResponse<{ item?: unknown }>>(
    endpoints.disiplinVakalar.islemsizKapat(vakaId),
    {
      method: "POST",
      body: JSON.stringify(gerekce ? { gerekce } : {})
    }
  );
  return normalizeDisiplinVaka(response.data?.item);
}
