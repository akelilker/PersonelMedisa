import { describe, expect, it } from "vitest";
import type { FazlaCalismaOdemeTercihi } from "../../src/types/fazla-calisma-odeme-tercihi";
import type { SerbestZamanEvent } from "../../src/types/serbest-zaman";
import {
  hesaplaSerbestZamanBakiye,
  hesaplaSonKullanimTarihi,
  olusturOlusumEvent
} from "../../src/services/serbest-zaman-event-motoru";

function tercih(
  overrides: Partial<FazlaCalismaOdemeTercihi> & Pick<FazlaCalismaOdemeTercihi, "id" | "odeme_tipi">
): FazlaCalismaOdemeTercihi {
  return {
    snapshot_id: 1001,
    kapanis_id: 1,
    personel_id: 1,
    hafta_baslangic: "2026-04-06",
    hafta_bitis: "2026-04-12",
    fazla_calisma_dakika: 60,
    ...overrides
  };
}

function olusumEvent(
  overrides: Partial<SerbestZamanEvent> & Pick<SerbestZamanEvent, "id">
): SerbestZamanEvent {
  return {
    personel_id: 1,
    kaynak_snapshot_id: 1001,
    kaynak_odeme_tercihi_id: 1,
    event_tipi: "SERBEST_ZAMAN_OLUSUM",
    dakika: 90,
    event_tarihi: "2026-05-31",
    son_kullanim_tarihi: "2026-11-30",
    ...overrides
  };
}

describe("serbest-zaman-event-motoru", () => {
  it("SERBEST_ZAMAN tercihinden olusum eventi uretir", () => {
    const sonuc = olusturOlusumEvent({
      tercih: tercih({
        id: 1,
        odeme_tipi: "SERBEST_ZAMAN",
        secim_zamani: "2026-05-31T10:00:00.000Z"
      }),
      mevcutEvents: []
    });

    expect(sonuc.ok).toBe(true);
    if (!sonuc.ok) {
      return;
    }

    expect(sonuc.event.event_tipi).toBe("SERBEST_ZAMAN_OLUSUM");
    expect(sonuc.event.dakika).toBe(90);
    expect(sonuc.event.kaynak_odeme_tercihi_id).toBe(1);
    expect(sonuc.event.kaynak_snapshot_id).toBe(1001);
    expect(sonuc.event.event_tarihi).toBe("2026-05-31");
    expect(sonuc.event.son_kullanim_tarihi).toBe("2026-11-30");
  });

  it("UCRET tercihinden event uretmez", () => {
    const sonuc = olusturOlusumEvent({
      tercih: tercih({ id: 1, odeme_tipi: "UCRET" }),
      mevcutEvents: []
    });

    expect(sonuc).toEqual({ ok: false, code: "NOT_ELIGIBLE" });
  });

  it("KARAR_BEKLIYOR tercihinden event uretmez", () => {
    const sonuc = olusturOlusumEvent({
      tercih: tercih({ id: 1, odeme_tipi: "KARAR_BEKLIYOR" }),
      mevcutEvents: []
    });

    expect(sonuc).toEqual({ ok: false, code: "NOT_ELIGIBLE" });
  });

  it("aynı tercih icin duplicate olusumu engeller", () => {
    const mevcutEvents: SerbestZamanEvent[] = [olusumEvent({ id: 10, kaynak_odeme_tercihi_id: 1 })];

    const sonuc = olusturOlusumEvent({
      tercih: tercih({ id: 1, odeme_tipi: "SERBEST_ZAMAN" }),
      mevcutEvents
    });

    expect(sonuc).toEqual({ ok: false, code: "ALREADY_EXISTS" });
  });

  it("FM 60 dk serbest zamana 90 dk hak cevirir", () => {
    const sonuc = olusturOlusumEvent({
      tercih: tercih({
        id: 2,
        odeme_tipi: "SERBEST_ZAMAN",
        fazla_calisma_dakika: 60
      }),
      mevcutEvents: []
    });

    expect(sonuc.ok).toBe(true);
    if (sonuc.ok) {
      expect(sonuc.event.dakika).toBe(90);
    }
  });

  it("son_kullanim_tarihi event tarihinden 6 ay sonrasidir", () => {
    expect(hesaplaSonKullanimTarihi("2026-05-31")).toBe("2026-11-30");
    expect(hesaplaSonKullanimTarihi("2026-01-31")).toBe("2026-07-31");
  });

  it("fazla_calisma_dakika sifirsa ZERO_DAKIKA doner", () => {
    const sonuc = olusturOlusumEvent({
      tercih: tercih({
        id: 3,
        odeme_tipi: "SERBEST_ZAMAN",
        fazla_calisma_dakika: 0
      }),
      mevcutEvents: []
    });

    expect(sonuc).toEqual({ ok: false, code: "ZERO_DAKIKA" });
  });

  it("persist edilmemis tercih id yoksa NOT_PERSISTED doner", () => {
    const sonuc = olusturOlusumEvent({
      tercih: tercih({ odeme_tipi: "SERBEST_ZAMAN" }),
      mevcutEvents: []
    });

    expect(sonuc).toEqual({ ok: false, code: "NOT_PERSISTED" });
  });

  it("bakiye yalniz OLUSUM eventlerinden hesaplanir ve kullanim sifirdir", () => {
    const bakiye = hesaplaSerbestZamanBakiye({
      personel_id: 1,
      events: [
        olusumEvent({ id: 1, dakika: 90, son_kullanim_tarihi: "2026-12-31" }),
        olusumEvent({
          id: 2,
          dakika: 60,
          kaynak_odeme_tercihi_id: 2,
          son_kullanim_tarihi: "2026-12-31"
        })
      ],
      referans_tarih: "2026-06-01"
    });

    expect(bakiye.toplam_hak_dakika).toBe(150);
    expect(bakiye.kullanilan_dakika).toBe(0);
    expect(bakiye.kalan_dakika).toBe(150);
    expect(bakiye.suresi_dolan_dakika).toBe(0);
    expect(bakiye.event_sayisi).toBe(2);
  });

  it("referans tarihi son kullanimi gecen lotlari suresi_dolan_dakika olarak sayar", () => {
    const bakiye = hesaplaSerbestZamanBakiye({
      personel_id: 1,
      events: [
        olusumEvent({
          id: 1,
          dakika: 90,
          son_kullanim_tarihi: "2026-05-01"
        }),
        olusumEvent({
          id: 2,
          dakika: 60,
          kaynak_odeme_tercihi_id: 2,
          son_kullanim_tarihi: "2026-12-31"
        })
      ],
      referans_tarih: "2026-06-01"
    });

    expect(bakiye.toplam_hak_dakika).toBe(150);
    expect(bakiye.suresi_dolan_dakika).toBe(90);
    expect(bakiye.kalan_dakika).toBe(60);
    expect(bakiye.kullanilan_dakika).toBe(0);
  });
});
