/**
 * MG-PERSONNEL-EXIT-POSTCHECK-ROUTE-PARITY-001
 * Canonical exit postcheck transport for lifecycle bulk apply ops scripts.
 *
 * Read routes:
 *   - GET /surecler/{surec_id}  (preferred when apply returned entity_id)
 *   - GET /surecler?personel_id={id}  (fallback)
 *
 * Never uses the non-existent GET /personeller/{id}/surecler route.
 */
"use strict";

export const FORBIDDEN_EXIT_POSTCHECK_ROUTE = "/personeller/{id}/surecler";

const EXIT_TURU = "ISTEN_AYRILMA";

export class ExitPostcheckError extends Error {
  /**
   * @param {string} code
   * @param {string} message
   * @param {Record<string, unknown>} [details]
   */
  constructor(code, message, details = {}) {
    super(message);
    this.name = "ExitPostcheckError";
    this.code = code;
    this.details = details;
  }
}

/**
 * @param {unknown} json
 * @returns {unknown[]}
 */
export function unwrapSurecItems(json) {
  const data = json && typeof json === "object" ? /** @type {{ data?: unknown }} */ (json).data : null;
  if (Array.isArray(data)) return data;
  if (data && typeof data === "object" && Array.isArray(/** @type {{ items?: unknown[] }} */ (data).items)) {
    return /** @type {{ items: unknown[] }} */ (data).items;
  }
  return [];
}

/**
 * @param {unknown} json
 * @returns {Record<string, unknown> | null}
 */
export function unwrapSurecDetail(json) {
  const data = json && typeof json === "object" ? /** @type {{ data?: unknown }} */ (json).data : null;
  if (!data || typeof data !== "object" || Array.isArray(data)) return null;
  return /** @type {Record<string, unknown>} */ (data);
}

/**
 * Pick the canonical exit süreç from a personel-filtered list:
 * latest non-IPTAL ISTEN_AYRILMA by id DESC.
 *
 * @param {unknown[]} items
 * @param {number} personelId
 * @returns {Record<string, unknown> | null}
 */
export function resolveExitSurecFromList(items, personelId) {
  const candidates = items
    .filter((item) => item && typeof item === "object")
    .map((item) => /** @type {Record<string, unknown>} */ (item))
    .filter((item) => Number(item.personel_id) === personelId)
    .filter((item) => String(item.surec_turu || "").toUpperCase() === EXIT_TURU)
    .filter((item) => String(item.state || "").toUpperCase() !== "IPTAL")
    .sort((a, b) => Number(b.id ?? 0) - Number(a.id ?? 0));

  return candidates[0] ?? null;
}

/**
 * @param {Record<string, unknown>} surec
 * @param {{ personelId: number, expectedExitDate?: string, expectedAciklama?: string, requirePassivePersonel?: boolean }} expected
 * @returns {{ pass: boolean, code?: string, message?: string, details?: Record<string, unknown> }}
 */
export function validateExitSurecRecord(surec, expected) {
  const personelId = expected.personelId;
  const surecPersonelId = Number(surec.personel_id);
  if (!Number.isFinite(surecPersonelId) || surecPersonelId !== personelId) {
    return {
      pass: false,
      code: "EXIT_SUREC_PERSONEL_MISMATCH",
      message: "Exit surec personel_id does not match expected personel.",
      details: { expected_personel_id: personelId, actual_personel_id: surecPersonelId || null },
    };
  }

  const turu = String(surec.surec_turu || "").toUpperCase();
  if (turu !== EXIT_TURU) {
    return {
      pass: false,
      code: "EXIT_SUREC_TURU_MISMATCH",
      message: "Exit surec type is not ISTEN_AYRILMA.",
      details: { actual_surec_turu: turu || null },
    };
  }

  const state = String(surec.state || "").toUpperCase();
  if (state === "IPTAL") {
    return {
      pass: false,
      code: "EXIT_SUREC_IPTAL",
      message: "Exit surec is cancelled.",
      details: { actual_state: state },
    };
  }

  if (expected.expectedExitDate !== undefined) {
    const exitDate = String(surec.baslangic_tarihi || "");
    if (exitDate !== expected.expectedExitDate) {
      return {
        pass: false,
        code: "EXIT_SUREC_DATE_MISMATCH",
        message: "Exit surec baslangic_tarihi does not match expected exit date.",
        details: { expected_exit_date: expected.expectedExitDate, actual_exit_date: exitDate || null },
      };
    }
  }

  if (expected.expectedAciklama !== undefined) {
    const aciklama = String(surec.aciklama || "");
    if (aciklama !== expected.expectedAciklama) {
      return {
        pass: false,
        code: "EXIT_SUREC_ACIKLAMA_MISMATCH",
        message: "Exit surec aciklama does not match expected gerekce.",
        details: { expected_aciklama: expected.expectedAciklama, actual_aciklama: aciklama },
      };
    }
  }

  return { pass: true };
}

/**
 * @param {(pathname: string, init?: { method?: string, token?: string }) => Promise<{ status: number, json: unknown | null, text?: string }>} api
 * @param {{ token: string, personelId: number, surecId?: number | null }} params
 * @returns {Promise<Record<string, unknown>>}
 */
export async function fetchCanonicalExitSurec(api, { token, personelId, surecId }) {
  if (surecId != null && Number(surecId) > 0) {
    const detailPath = `/surecler/${surecId}`;
    const detailRes = await api(detailPath, { token });
    if (detailRes.status === 404) {
      throw new ExitPostcheckError("EXIT_SUREC_NOT_FOUND", `Canonical exit surec ${surecId} not found.`, {
        route: detailPath,
        http_status: detailRes.status,
      });
    }
    if (detailRes.status !== 200) {
      throw new ExitPostcheckError("POSTCHECK_ROUTE_READ_FAILED", `GET ${detailPath} failed with HTTP ${detailRes.status}.`, {
        route: detailPath,
        http_status: detailRes.status,
      });
    }
    const surec = unwrapSurecDetail(detailRes.json);
    if (!surec) {
      throw new ExitPostcheckError("EXIT_SUREC_PAYLOAD_INVALID", `GET ${detailPath} returned an invalid surec payload.`, {
        route: detailPath,
      });
    }
    return surec;
  }

  const listPath = `/surecler?personel_id=${personelId}&limit=50`;
  const listRes = await api(listPath, { token });
  if (listRes.status === 404) {
    throw new ExitPostcheckError(
      "POSTCHECK_ROUTE_NOT_FOUND",
      `Canonical surec list route returned 404 for personel ${personelId}.`,
      { route: listPath, http_status: listRes.status }
    );
  }
  if (listRes.status !== 200) {
    throw new ExitPostcheckError("POSTCHECK_ROUTE_READ_FAILED", `GET ${listPath} failed with HTTP ${listRes.status}.`, {
      route: listPath,
      http_status: listRes.status,
    });
  }

  const items = unwrapSurecItems(listRes.json);
  const surec = resolveExitSurecFromList(items, personelId);
  if (!surec) {
    throw new ExitPostcheckError("EXIT_SUREC_NOT_FOUND", `No canonical ISTEN_AYRILMA surec found for personel ${personelId}.`, {
      route: listPath,
      list_count: items.length,
    });
  }
  return surec;
}

/**
 * @param {(pathname: string, init?: { method?: string, token?: string }) => Promise<{ status: number, json: unknown | null, text?: string }>} api
 * @param {{ token: string, personelId: number, surecId?: number | null, expectedExitDate?: string, expectedAciklama?: string }} params
 */
export async function verifyPersonnelExitSurec(api, params) {
  const surec = await fetchCanonicalExitSurec(api, params);
  const validation = validateExitSurecRecord(surec, params);
  if (!validation.pass) {
    throw new ExitPostcheckError(validation.code || "EXIT_SUREC_VALIDATION_FAILED", validation.message || "Exit surec validation failed.", {
      ...(validation.details || {}),
      surec_id: surec.id ?? null,
    });
  }
  return {
    surec_id: Number(surec.id ?? 0) || null,
    personel_id: Number(surec.personel_id ?? 0) || null,
    baslangic_tarihi: surec.baslangic_tarihi ?? null,
    aciklama: surec.aciklama ?? null,
    state: surec.state ?? null,
    route: params.surecId ? `/surecler/${params.surecId}` : `/surecler?personel_id=${params.personelId}`,
  };
}

/**
 * @param {(pathname: string, init?: { method?: string, token?: string }) => Promise<{ status: number, json: unknown | null, text?: string }>} api
 * @param {{ token: string, personelId: number }} params
 */
export async function verifyPersonnelExitPreimage(api, { token, personelId }) {
  const res = await api(`/personeller/${personelId}`, { token });
  if (res.status !== 200) {
    throw new ExitPostcheckError("POSTCHECK_ROUTE_READ_FAILED", `GET /personeller/${personelId} failed with HTTP ${res.status}.`, {
      route: `/personeller/${personelId}`,
      http_status: res.status,
    });
  }
  const personel = unwrapSurecDetail(res.json);
  if (!personel) {
    throw new ExitPostcheckError("PERSONEL_PAYLOAD_INVALID", `GET /personeller/${personelId} returned invalid payload.`);
  }

  let exitSurec = null;
  try {
    exitSurec = await fetchCanonicalExitSurec(api, { token, personelId, surecId: null });
  } catch (error) {
    if (error instanceof ExitPostcheckError && error.code === "EXIT_SUREC_NOT_FOUND") {
      exitSurec = null;
    } else {
      throw error;
    }
  }

  const aktifDurum = String(personel.aktif_durum || "").toUpperCase();
  const istenCikisTarihi = personel.isten_cikis_tarihi ?? null;
  const preimagePass = aktifDurum === "AKTIF" && !istenCikisTarihi && !exitSurec;

  return {
    personel_id: personelId,
    aktif_durum: aktifDurum,
    isten_cikis_tarihi: istenCikisTarihi,
    exit_surec_count: exitSurec ? 1 : 0,
    preimage_pass: preimagePass,
  };
}

/**
 * @param {unknown} applySatirSonuclari
 * @param {{ mutationId?: string, owner?: string }} [filter]
 * @returns {number | null}
 */
export function resolveSurecIdFromApplyResult(applySatirSonuclari, filter = {}) {
  if (!Array.isArray(applySatirSonuclari)) return null;
  const owner = filter.owner ?? "PersonelIstenAyrilmaService";
  for (const row of applySatirSonuclari) {
    if (!row || typeof row !== "object") continue;
    const record = /** @type {Record<string, unknown>} */ (row);
    if (String(record.owner || "") !== owner) continue;
    if (filter.mutationId && String(record.mutation_id || "") !== filter.mutationId) continue;
    const entityId = Number(record.entity_id ?? 0);
    if (Number.isFinite(entityId) && entityId > 0) {
      return entityId;
    }
  }
  return null;
}
