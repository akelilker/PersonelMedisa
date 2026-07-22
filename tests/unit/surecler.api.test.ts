import { afterEach, describe, expect, it, vi } from "vitest";
import { createSurec } from "../../src/api/surecler.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("surecler.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("createSurec hastalik raporunda varsayilan ilk_iki_gun_firma_oder_mi=null doner", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 901,
            personel_id: 12,
            surec_turu: "RAPOR",
            alt_tur: "Raporlu_Hastalik",
            baslangic_tarihi: "2026-04-10",
            bitis_tarihi: "2026-04-14",
            ilk_iki_gun_firma_oder_mi: null,
            state: "AKTIF"
          },
          meta: {},
          errors: []
        },
        201
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await createSurec({
      personel_id: 12,
      surec_turu: "RAPOR",
      alt_tur: "Raporlu_Hastalik",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-14"
    });

    expect(result.ilk_iki_gun_firma_oder_mi).toBeNull();
  });

  it("createSurec is kazasi raporunda ilk_iki_gun_firma_oder_mi null kalir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 902,
            personel_id: 12,
            surec_turu: "RAPOR",
            alt_tur: "Raporlu_Is_Kazasi",
            baslangic_tarihi: "2026-04-10",
            ilk_iki_gun_firma_oder_mi: null,
            state: "AKTIF"
          },
          meta: {},
          errors: []
        },
        201
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await createSurec({
      personel_id: 12,
      surec_turu: "RAPOR",
      alt_tur: "Raporlu_Is_Kazasi",
      baslangic_tarihi: "2026-04-10"
    });

    expect(result.ilk_iki_gun_firma_oder_mi).toBeNull();
  });

  it("createSurec hastalik raporunda ilk_iki_gun_firma_oder_mi payload degerini tasir", async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
      const body = JSON.parse(String(init?.body ?? "{}")) as Record<string, unknown>;
      expect(body.ilk_iki_gun_firma_oder_mi).toBe(true);

      return createJsonResponse(
        {
          data: {
            id: 903,
            personel_id: 12,
            surec_turu: "RAPOR",
            alt_tur: "Raporlu_Hastalik",
            baslangic_tarihi: "2026-04-10",
            ilk_iki_gun_firma_oder_mi: true,
            state: "AKTIF"
          },
          meta: {},
          errors: []
        },
        201
      );
    });
    vi.stubGlobal("fetch", fetchMock);

    const result = await createSurec({
      personel_id: 12,
      surec_turu: "RAPOR",
      alt_tur: "Raporlu_Hastalik",
      baslangic_tarihi: "2026-04-10",
      ilk_iki_gun_firma_oder_mi: true
    });

    expect(result.ilk_iki_gun_firma_oder_mi).toBe(true);
  });
});
