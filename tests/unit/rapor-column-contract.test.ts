import { describe, expect, it } from "vitest";
import { RAPOR_COLUMN_CONTRACT, getRaporColumns } from "../../src/features/raporlar/rapor-column-contract";
import type { RaporTipi } from "../../src/types/rapor";

const RAPOR_TIPLERI: RaporTipi[] = [
  "personel-ozet",
  "izin",
  "devamsizlik",
  "tesvik",
  "ceza",
  "ekstra-prim",
  "is-kazasi",
  "bildirim",
  "puantaj"
];

describe("rapor-column-contract", () => {
  it("defines a non-empty deterministic column contract for every report type", () => {
    for (const raporTipi of RAPOR_TIPLERI) {
      const columns = getRaporColumns(raporTipi);

      expect(columns.length).toBeGreaterThan(0);
      expect(columns.every((column) => column.key.trim() && column.label.trim())).toBe(true);
    }

    expect(Object.keys(RAPOR_COLUMN_CONTRACT).sort()).toEqual([...RAPOR_TIPLERI].sort());
  });

  it("keeps puantaj columns on stored attendance fields", () => {
    const puantajKeys = getRaporColumns("puantaj").map((column) => column.key);
    expect(puantajKeys).toEqual([
      "tarih",
      "ad_soyad",
      "sicil_no",
      "bolum",
      "birim",
      "giris_saati",
      "cikis_saati",
      "net_calisma_dakika",
      "gec_kalma_dakika",
      "erken_cikis_dakika",
      "hareket_durumu",
      "dayanak"
    ]);
    expect(puantajKeys.some((key) => key.includes("fazla"))).toBe(false);
  });

  it("internal personel_id hiçbir kullanıcı rapor kolonunda yer almaz", () => {
    for (const raporTipi of RAPOR_TIPLERI) {
      expect(getRaporColumns(raporTipi).some((column) => column.key === "personel_id")).toBe(false);
      expect(getRaporColumns(raporTipi).some((column) => column.label === "Personel ID")).toBe(false);
    }
  });

  it("keeps personel summary columns in business order", () => {
    expect(getRaporColumns("personel-ozet")).toEqual([
      { key: "ad_soyad", label: "Ad Soyad" },
      { key: "sicil_no", label: "Sicil No" },
      { key: "aktif_durum", label: "Durum" },
      { key: "net_calisma_dakika", label: "Net Çalışma (dk)" },
      { key: "sgk_prim_gun", label: "SGK Prim Gün" }
    ]);
  });
});
