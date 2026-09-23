import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  UCRET_TIPI_ENVANTERI_BUCKET_LABELS,
  buildUcretTipiEnvanteri
} from "../../src/lib/yonetim/ucret-tipi-envanteri";
import type { Personel } from "../../src/types/personel";

const root = process.cwd();

function read(rel: string) {
  return readFileSync(resolve(root, rel), "utf8");
}

function personel(over: Partial<Personel> & { id: number }): Personel {
  return {
    ad: "Ali",
    soyad: "Veli",
    aktif_durum: "AKTIF",
    tc_kimlik_no: null,
    ...over
  };
}

const PANEL = "src/features/yonetim/components/UcretTipiEnvanteriPanel.tsx";
const LIB = "src/lib/yonetim/ucret-tipi-envanteri.ts";

describe("Ücret Tipi Envanteri dağılımı (personeller.ucret_tipi_id)", () => {
  it("SAATLIK / GUNLUK / MAKTU_AYLIK / eksik / geçersiz sayaçlarını üretir", () => {
    const sonuc = buildUcretTipiEnvanteri([
      personel({ id: 1, ucret_tipi_id: 3 }),
      personel({ id: 2, ucret_tipi_id: 2 }),
      personel({ id: 3, ucret_tipi_id: 1 }),
      personel({ id: 4, ucret_tipi_id: 1 }),
      personel({ id: 5 }),
      personel({ id: 6, ucret_tipi_id: 7 })
    ]);

    expect(sonuc.toplam).toBe(6);
    expect(sonuc.sayaclar).toEqual({
      SAATLIK: 1,
      GUNLUK: 1,
      MAKTU_AYLIK: 2,
      EKSIK: 1,
      GECERSIZ: 1
    });
    expect(sonuc.toplam).toBe(sonuc.satirlar.length);
  });

  it("eksik ve geçersiz kayıtları ayrı durum olarak işaretler", () => {
    const sonuc = buildUcretTipiEnvanteri([
      personel({ id: 1 }),
      personel({ id: 2, ucret_tipi_id: 9 }),
      personel({ id: 3, ucret_tipi_id: 3 })
    ]);

    const byId = new Map(sonuc.satirlar.map((satir) => [satir.personelId, satir]));
    expect(byId.get(1)?.durum).toBe("EKSIK");
    expect(byId.get(1)?.ucretTipiEtiketi).toBe("-");
    expect(byId.get(2)?.durum).toBe("GECERSIZ");
    expect(byId.get(2)?.ucretTipiEtiketi).toBe("#9");
    expect(byId.get(3)?.durum).toBe("TANIMLI");
    expect(byId.get(3)?.ucretTipiEtiketi).toBe(UCRET_TIPI_ENVANTERI_BUCKET_LABELS.SAATLIK);
  });

  it("Mavi/Beyaz statüsünden ücret tipi türetmez; statü yalnız bilgi olarak taşınır", () => {
    const sonuc = buildUcretTipiEnvanteri([
      personel({ id: 1, personel_tipi_adi: "Mavi Yaka" }),
      personel({ id: 2, personel_tipi_adi: "Beyaz Yaka" })
    ]);

    expect(sonuc.sayaclar).toEqual({
      SAATLIK: 0,
      GUNLUK: 0,
      MAKTU_AYLIK: 0,
      EKSIK: 2,
      GECERSIZ: 0
    });
    expect(sonuc.satirlar.map((satir) => satir.statu)).toEqual(["Mavi Yaka", "Beyaz Yaka"]);
  });

  it("ad-soyad boşsa personel id ile okunabilir kalır", () => {
    const sonuc = buildUcretTipiEnvanteri([personel({ id: 42, ad: "", soyad: null })]);
    expect(sonuc.satirlar[0].adSoyad).toBe("Personel #42");
  });
});

describe("Ücret Tipi Envanteri salt okunur sözleşmesi", () => {
  it("dağılımı yalnız canonical personel read API'sinden okur", () => {
    const panel = read(PANEL);
    expect(panel).toContain("fetchPersonellerList");
    expect(panel).toContain('aktiflik: "aktif"');
    expect(panel).toContain('calisan_kapsami: "IC_PERSONEL"');
  });

  it("hiçbir yazma/güncelleme çağrısı yapmaz", () => {
    const panel = read(PANEL);
    for (const forbidden of [
      "createPersonel",
      "updatePersonel",
      "apiRequest",
      "method:",
      "PUT",
      "POST",
      "DELETE"
    ]) {
      expect(panel, forbidden).not.toContain(forbidden);
    }
  });

  it("maaş motoru, /225, /30, SGK, prim günü veya PEK hesabına dokunmaz", () => {
    for (const rel of [PANEL, LIB]) {
      const src = read(rel);
      for (const forbidden of ["225", "SgkPrimGunu", "maas-hesaplama", "PEK", "prim_gun"]) {
        expect(src, `${rel} → ${forbidden}`).not.toContain(forbidden);
      }
      expect(src, rel).not.toMatch(/\/\s*30\b/);
    }
  });
});
