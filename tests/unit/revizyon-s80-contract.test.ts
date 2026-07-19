import { describe, expect, it, vi } from "vitest";
import { resolveDemoApiResponse, seedDemoHaftalikMutabakatForClose } from "../../src/api/mock-demo";
import { fetchRevizyonKaynaklar, normalizeRevizyonTalebi } from "../../src/api/revizyon-talebi.api";

function closeDemoHaftalikKapanis(haftaBaslangic: string, haftaBitis: string, departmanId = 3) {
  seedDemoHaftalikMutabakatForClose({ haftaBaslangic, haftaBitis });
  return resolveDemoApiResponse("/haftalik-kapanis", {
    method: "POST",
    body: JSON.stringify({
      hafta_baslangic: haftaBaslangic,
      hafta_bitis: haftaBitis,
      departman_id: departmanId
    })
  });
}

describe("S80 revizyon contract", () => {
  it("normalize enrichment alanlarini korur", () => {
    const talep = normalizeRevizyonTalebi({
      id: 1,
      personel_id: 10,
      personel_ad_soyad: "Ali Veli",
      sicil_no: "S-1",
      sube_id: 2,
      sube_adi: "Merkez",
      departman_id: 3,
      departman_adi: "Üretim",
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      etkilenen_tarih: "2026-04-07",
      kaynak_tipi: "PUANTAJ",
      kaynak_id: 99,
      revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
      onceki_deger: { giris_saati: "08:00" },
      talep_edilen_deger: { giris_saati: "08:30" },
      gerekce: "duzeltme",
      talep_eden_kullanici_id: 1,
      talep_eden_kullanici_adi: "Admin",
      talep_zamani: "2026-04-08T10:00:00Z",
      durum: "TASLAK",
      bordro_etki_var_mi: false,
      correction_event_id: null,
      correction_durumu: null,
      aktif_correction_var_mi: false
    });

    expect(talep.personel_ad_soyad).toBe("Ali Veli");
    expect(talep.sicil_no).toBe("S-1");
    expect(talep.sube_adi).toBe("Merkez");
    expect(talep.departman_adi).toBe("Üretim");
    expect(talep.talep_eden_kullanici_adi).toBe("Admin");
  });

  it("sahte onceki_deger forged isaretini reddeder", () => {
    closeDemoHaftalikKapanis("2026-06-01", "2026-06-07");

    const response = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-06-01",
        hafta_bitis: "2026-06-07",
        etkilenen_tarih: "2026-06-02",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9301,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: { forged: true, giris_saati: "00:00" },
        talep_edilen_deger: { giris_saati: "09:00" },
        gerekce: "S80 forged onceki_deger"
      })
    });

    expect(response?.errors?.[0]?.code).toBe("VALIDATION_ERROR");
  });

  it("create server-owned onceki_deger yazar", () => {
    closeDemoHaftalikKapanis("2026-06-08", "2026-06-14");

    const response = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-06-08",
        hafta_bitis: "2026-06-14",
        etkilenen_tarih: "2026-06-09",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9302,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        talep_edilen_deger: { giris_saati: "09:00" },
        gerekce: "S80 server-owned"
      })
    });

    const data = response?.data as { onceki_deger?: { server_owned?: boolean } } | undefined;
    expect(data?.onceki_deger?.server_owned).toBe(true);
  });

  it("kaynak secim read-contract doner", async () => {
    closeDemoHaftalikKapanis("2026-06-15", "2026-06-21");

    const fetchMock = vi.fn(async () => {
      const body = resolveDemoApiResponse(
        "/haftalik-kapanis/revizyon-kaynaklar?personel_id=1&hafta_baslangic=2026-06-15&hafta_bitis=2026-06-21",
        { method: "GET" }
      );
      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { "Content-Type": "application/json" }
      });
    });
    vi.stubGlobal("fetch", fetchMock);

    const items = await fetchRevizyonKaynaklar({
      personel_id: 1,
      hafta_baslangic: "2026-06-15",
      hafta_bitis: "2026-06-21"
    });

    expect(items.length).toBeGreaterThan(0);
    expect(items[0]?.goruntuleme_etiketi).toBeTruthy();
    expect(items[0]?.uygun_revizyon_tipleri.length).toBeGreaterThan(0);

    vi.unstubAllGlobals();
  });

  it("liste filtre serialization yeni alanlari tasir", async () => {
    const fetchMock = vi.fn(async () =>
      new Response(JSON.stringify({ data: { items: [] }, meta: {}, errors: [] }), {
        status: 200,
        headers: { "Content-Type": "application/json" }
      })
    );
    vi.stubGlobal("fetch", fetchMock);

    const { fetchRevizyonTalepleri } = await import("../../src/api/revizyon-talebi.api");
    await fetchRevizyonTalepleri({
      personel_id: 1,
      revizyon_tipi: "MOLA_DUZELTME",
      departman_id: 3,
      bordro_etki_var_mi: true,
      correction_var_mi: false,
      correction_durumu: "AKTIF"
    });

    const calledUrl = String(fetchMock.mock.calls[0]?.[0] ?? "");
    expect(calledUrl).toContain("revizyon_tipi=MOLA_DUZELTME");
    expect(calledUrl).toContain("departman_id=3");
    expect(calledUrl).toContain("bordro_etki_var_mi=1");
    expect(calledUrl).toContain("correction_var_mi=0");
    expect(calledUrl).toContain("correction_durumu=AKTIF");

    vi.unstubAllGlobals();
  });
});
