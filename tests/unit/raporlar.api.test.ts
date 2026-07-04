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

  it("preserves tesvik report FINANS source meta from response", async () => {
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
                  donem: "2026-04",
                  gun_sayisi: 22,
                  toplam_tutar: 1500,
                  state: "AKTIF"
                }
              ]
            },
            meta: {
              page: 1,
              limit: 10,
              total: 1,
              total_pages: 1,
              has_next_page: false,
              kaynak: "FINANS",
              muhur_id: null,
              donem: "2026-04",
              effective_sube_id: 1
            },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("tesvik", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = (vi.mocked(fetch) as ReturnType).mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/tesvik");
    expect(result.reportMeta).toEqual({
      kaynak: "FINANS",
      muhur_id: null,
      donem: "2026-04",
      effective_sube_id: 1
    });
    expect(result.rows[0]?.toplam_tutar).toBe(1500);
  });

  it("preserves ceza report FINANS source meta from response", async () => {
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
                  donem: "2026-04",
                  tutar: 500,
                  aciklama: "Gec kalma",
                  state: "AKTIF"
                }
              ]
            },
            meta: {
              page: 1,
              limit: 10,
              total: 1,
              total_pages: 1,
              has_next_page: false,
              kaynak: "FINANS",
              muhur_id: null,
              donem: "2026-04",
              effective_sube_id: 1
            },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("ceza", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = (vi.mocked(fetch) as ReturnType).mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/ceza");
    expect(result.reportMeta?.kaynak).toBe("FINANS");
    expect(result.reportMeta?.muhur_id).toBeNull();
    expect(result.rows[0]?.aciklama).toBe("Gec kalma");
  });

  it("preserves ekstra-prim report FINANS source meta from response", async () => {
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
                  donem: "2026-04",
                  tutar: 800,
                  aciklama: "Performans primi",
                  state: "AKTIF"
                }
              ]
            },
            meta: {
              page: 1,
              limit: 10,
              total: 1,
              total_pages: 1,
              has_next_page: false,
              kaynak: "FINANS",
              muhur_id: null,
              donem: "2026-04",
              effective_sube_id: 1
            },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("ekstra-prim", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = (vi.mocked(fetch) as ReturnType).mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/ekstra-prim");
    expect(result.reportMeta?.kaynak).toBe("FINANS");
    expect(result.rows[0]?.tutar).toBe(800);
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

  it("preserves is-kazasi report source meta from response", async () => {
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
                  baslangic_tarihi: "2026-04-12",
                  bitis_tarihi: "2026-04-14",
                  aciklama: "Hafif yaralanma",
                  state: "AKTIF"
                }
              ]
            },
            meta: {
              page: 1,
              limit: 10,
              total: 1,
              total_pages: 1,
              has_next_page: false,
              kaynak: "SUREC",
              muhur_id: null,
              donem: "2026-04",
              effective_sube_id: 1
            },
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchRapor("is-kazasi", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = (vi.mocked(fetch) as ReturnType<typeof vi.fn>).mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/is-kazasi");
    expect(result.reportMeta).toEqual({
      kaynak: "SUREC",
      muhur_id: null,
      donem: "2026-04",
      effective_sube_id: 1
    });
    expect(result.rows[0]?.aciklama).toBe("Hafif yaralanma");
  });

  it("preserves bildirim report source meta from response", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              items: [
                {
                  tarih: "2026-04-11",
                  departman_id: 3,
                  personel_id: 1,
                  ad_soyad: "Ayse Yilmaz",
                  bildirim_turu: "IZINSIZ_GELMEDI",
                  aciklama: "Habersiz devamsizlik",
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

    const result = await fetchRapor("bildirim", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = (vi.mocked(fetch) as ReturnType<typeof vi.fn>).mock.calls[0] as [string];
    expect(url).toContain("/api/raporlar/bildirim");
    expect(result.reportMeta).toEqual({
      kaynak: "SNAPSHOT",
      muhur_id: 101,
      donem: "2026-04",
      effective_sube_id: 1
    });
    expect(result.rows[0]?.bildirim_turu).toBe("IZINSIZ_GELMEDI");
  });

  it("appends muhur_id and donem to query string when provided", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: { items: [] },
          meta: { page: 1, limit: 10, total: 0, total_pages: 1, has_next_page: false },
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    await fetchRapor("personel-ozet", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      muhur_id: 123,
      donem: "2026-04",
      page: 1,
      limit: 10
    });

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).toContain("muhur_id=123");
    expect(url).toContain("donem=2026-04");
    expect(url).toContain("baslangic_tarihi=2026-04-01");
    expect(url).toContain("bitis_tarihi=2026-04-30");
  });

  it("omits empty muhur_id and donem from query string", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: { items: [] },
          meta: { page: 1, limit: 10, total: 0, total_pages: 1, has_next_page: false },
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    await fetchRapor("personel-ozet", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).not.toContain("muhur_id=");
    expect(url).not.toContain("donem=");
  });

  it("appends sube_id to query string when provided", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: { items: [] },
          meta: { page: 1, limit: 10, total: 0, total_pages: 1, has_next_page: false },
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    await fetchRapor("personel-ozet", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      donem: "2026-04",
      sube_id: 1,
      page: 1,
      limit: 10
    });

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).toContain("sube_id=1");
    expect(url).not.toContain("sube_id=undefined");
  });

  it("omits empty sube_id from query string", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: { items: [] },
          meta: { page: 1, limit: 10, total: 0, total_pages: 1, has_next_page: false },
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    await fetchRapor("personel-ozet", {
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-30",
      page: 1,
      limit: 10
    });

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).not.toContain("sube_id=");
  });
});
