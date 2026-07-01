import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchRapor } from "../../src/api/raporlar.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("raporlar.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("calls selected report endpoint with filters and returns rows", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            items: [
              {
                personel_id: 1,
                ad_soyad: "Ayse Yilmaz",
                net_calisma_dakika: 510
              }
            ]
          },
          meta: { page: 2, limit: 10, total: 24, total_pages: 3, has_next_page: true },
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchRapor("personel-ozet", {
      personel_id: 1,
      departman_id: 3,
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      aktiflik: "aktif",
      page: 2,
      limit: 10
    });

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/personel-ozet");
    expect(url).toContain("personel_id=1");
    expect(url).toContain("departman_id=3");
    expect(url).toContain("aktiflik=aktif");
    expect(url).toContain("page=2");
    expect(url).toContain("limit=10");
    expect(result.total).toBe(24);
    expect(result.rows).toHaveLength(1);
    expect(result.pagination).toEqual({
      page: 2,
      limit: 10,
      total: 24,
      totalPages: 3,
      hasNextPage: true,
      hasPreviousPage: true
    });
  });

  it("wraps object payload into single row for summary-style endpoints", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              toplam_tutar: 12500,
              donem: "2026-04"
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("tesvik");
    expect(result.rows).toEqual([
      {
        toplam_tutar: 12500,
        donem: "2026-04"
      }
    ]);
    expect(result.total).toBe(1);
  });

  it("keeps empty list payloads empty instead of wrapping the response object", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              items: []
            },
            meta: { page: 1, limit: 10, total: 0, total_pages: 1, has_next_page: false },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("bildirim", { page: 1, limit: 10 });

    expect(result.rows).toEqual([]);
    expect(result.total).toBe(0);
    expect(result.pagination.hasNextPage).toBe(false);
  });

  it("preserves report source meta from response", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              items: [
                {
                  personel_id: 1,
                  ad_soyad: "Ayse Yilmaz",
                  net_calisma_dakika: 960,
                  sgk_prim_gun: 20
                }
              ]
            },
            meta: {
              page: 1,
              limit: 10,
              total: 1,
              total_pages: 1,
              has_next_page: false,
              kaynak: "SNAPSHOT",
              muhur_id: 42,
              donem: "2026-04",
              effective_sube_id: 1
            },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("personel-ozet", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    expect(result.reportMeta).toEqual({
      kaynak: "SNAPSHOT",
      muhur_id: 42,
      donem: "2026-04",
      effective_sube_id: 1
    });
    expect(result.rows[0]?.net_calisma_dakika).toBe(960);
  });

  it("preserves devamsizlik report source meta from response", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              items: [
                {
                  personel_id: 1,
                  ad_soyad: "Ayse Yilmaz",
                  baslangic_tarihi: "2026-04-10",
                  bitis_tarihi: "2026-04-10",
                  alt_tur: "IZINSIZ",
                  state: "MUHURLENDI"
                }
              ]
            },
            meta: {
              page: 1,
              limit: 10,
              total: 1,
              total_pages: 1,
              has_next_page: false,
              kaynak: "SNAPSHOT",
              muhur_id: 101,
              donem: "2026-04",
              effective_sube_id: 1
            },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("devamsizlik", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = (vi.mocked(fetch) as ReturnType<typeof vi.fn>).mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/devamsizlik");
    expect(url).toContain("baslangic_tarihi=2026-04-01");
    expect(url).toContain("bitis_tarihi=2026-04-30");
    expect(url).toContain("page=1");
    expect(url).toContain("limit=10");
    expect(result.reportMeta).toEqual({
      kaynak: "SNAPSHOT",
      muhur_id: 101,
      donem: "2026-04",
      effective_sube_id: 1
    });
    expect(result.rows[0]?.alt_tur).toBe("IZINSIZ");
  });

  it("preserves izin report source meta from response", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              items: [
                {
                  personel_id: 1,
                  ad_soyad: "Ayse Yilmaz",
                  baslangic_tarihi: "2026-04-03",
                  bitis_tarihi: "2026-04-03",
                  alt_tur: "YILLIK_IZIN",
                  ucretli_mi: true,
                  state: "MUHURLENDI"
                }
              ]
            },
            meta: {
              page: 1,
              limit: 10,
              total: 1,
              total_pages: 1,
              has_next_page: false,
              kaynak: "SNAPSHOT",
              muhur_id: 101,
              donem: "2026-04",
              effective_sube_id: 1
            },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("izin", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = (vi.mocked(fetch) as ReturnType<typeof vi.fn>).mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/izin");
    expect(url).toContain("baslangic_tarihi=2026-04-01");
    expect(url).toContain("bitis_tarihi=2026-04-30");
    expect(result.reportMeta).toEqual({
      kaynak: "SNAPSHOT",
      muhur_id: 101,
      donem: "2026-04",
      effective_sube_id: 1
    });
    expect(result.rows[0]?.alt_tur).toBe("YILLIK_IZIN");
    expect(result.rows[0]?.ucretli_mi).toBe(true);
  });
});
