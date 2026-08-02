import { createHash } from "node:crypto";
import { readFileSync, readdirSync, existsSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migration042 = readFileSync(
  "api/migrations/042_sgk_resmi_kaynakli_kisitli_katalog.sql",
  "utf8"
);
const importValidator = readFileSync(
  "api/src/Services/Payroll/SgkKatalogImportValidator.php",
  "utf8"
);
const tamlik = readFileSync(
  "api/src/Services/Payroll/SgkKatalogTamlikService.php",
  "utf8"
);
const onay = readFileSync(
  "api/src/Services/Payroll/SgkKatalogOnayService.php",
  "utf8"
);
const writeSvc = readFileSync(
  "api/src/Services/Payroll/SgkKatalogWriteService.php",
  "utf8"
);
const engine = readFileSync(
  "api/src/Services/Payroll/SgkPrimGunuEngine.php",
  "utf8"
);
const panel = readFileSync(
  "src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx",
  "utf8"
);
const api = readFileSync("src/api/sgk-katalog-hazirlik.api.ts", "utf8");
const contracts = readFileSync(
  "api/src/Services/Payroll/SgkKatalogContracts.php",
  "utf8"
);

const EXACT_19 = [
  "01",
  "03",
  "04",
  "05",
  "06",
  "07",
  "08",
  "09",
  "10",
  "11",
  "12",
  "13",
  "15",
  "16",
  "17",
  "18",
  "19",
  "20",
  "21",
];

describe("S106 RESMI_KAYNAKLI_KISITLI katalog", () => {
  it("042 migration: nullable baslangic, tarih durumu, kisitli tamlik, onay check", () => {
    expect(migration042).toContain("gecerlilik_baslangic DATE NULL");
    expect(migration042).toContain("gecerlilik_tarih_durumu");
    expect(migration042).toContain("RESMI_YURURLUK");
    expect(migration042).toContain("ILK_RESMI_KANIT");
    expect(migration042).toContain("BELIRLENEMEDI");
    expect(migration042).toContain("ilk_resmi_kanit_tarihi");
    expect(migration042).toContain("RESMI_KAYNAKLI_KISITLI");
    expect(migration042).toContain("DOGRULANMIS_TAM");
    expect(migration042).toContain("chk_sgk_egks_onay");
    expect(migration042).toContain("DROP CONSTRAINT chk_sgk_egks_onay");
    expect(migration042).toMatch(
      /tamlik_durumu\s+IN\s*\(\s*''RESMI_KAYNAKLI_KISITLI''\s*,\s*''DOGRULANMIS_TAM''\s*\)/
    );
    expect(migration042).toContain("resmi_kaynaklar_incelendi_mi");
    expect(migration042).toContain("belirsiz_tarihler_uydurulmadi_mi");
    expect(migration042).toContain("kisitli_kullanim_kabul_edildi_mi");
    expect(migration042).not.toMatch(/\b(?:DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i);
  });

  it("migration sequence ends with 043", () => {
    const names = readdirSync(resolve("api/migrations"))
      .filter((n) => n.endsWith(".sql"))
      .sort();
    expect(names.at(-1)).toBe("045_sirket_politikasi_kanit_owner.sql");
  });

  it("contracts/validators expose S106 enums and write path", () => {
    expect(contracts).toContain("GECERLILIK_TARIH_DURUMU");
    expect(contracts).toContain("RESMI_KAYNAKLI_KISITLI");
    expect(importValidator).toContain("gecerlilik_baslangic");
    expect(importValidator).toContain("CELISKI_TARIH_DURUMU");
    expect(importValidator).toContain("BELIRLENEMEDI");
    expect(tamlik).toContain("RESMI_KAYNAKLI_KISITLI");
    expect(tamlik).toContain("limited_blocker");
    expect(onay).toContain("resmi_kaynaklar_incelendi_mi");
    expect(onay).toContain("belirsiz_tarihler_uydurulmadi_mi");
    expect(onay).toContain("kisitli_kullanim_kabul_edildi_mi");
    expect(writeSvc).toContain("function import");
    expect(writeSvc).toContain("function submit");
    expect(writeSvc).toContain("function approve");
    expect(writeSvc).toContain("GENEL_YONETICI");
    expect(engine).toContain("RESMI_KAYNAKLI_KISITLI");
    expect(engine).toContain("BELIRLENEMEDI");
  });

  it("frontend shows kisitli badge copy without DOGRULANMIS_TAM claim", () => {
    expect(api).toContain("RESMI_KAYNAKLI_KISITLI");
    expect(panel).toContain("Resmî kod/ad doğrulandı");
    expect(panel).toMatch(/[Bb]elirsiz/);
    expect(panel).not.toMatch(/DOGRULANMIS_TAM gibi|tam doğrulanmış katalog/i);
  });

  it("canonical package exact 19 codes and checksums", () => {
    const canonicalPath = "ops/sgk/S106-SGK-EKSIK-GUN-19-CANONICAL.json";
    expect(existsSync(canonicalPath)).toBe(true);
    const pkg = JSON.parse(readFileSync(canonicalPath, "utf8"));
    expect(pkg.katalog_surumu).toBe("SGK-EKSIK-GUN-RESMI-2026-07");
    expect(pkg.dogrulanmis_tam_iddiasi).toBe(false);
    expect(pkg.rows).toHaveLength(19);
    const codes = pkg.rows.map((r: { eksik_gun_kodu: string }) => r.eksik_gun_kodu);
    expect([...codes].sort()).toEqual([...EXACT_19].sort());
    expect(new Set(codes).size).toBe(19);
    expect(codes).not.toContain("26");
    expect(codes).not.toContain("27");
    expect(codes).not.toContain("28");
    expect(codes).not.toContain("29");

    for (const row of pkg.rows) {
      expect(row.gecerlilik_baslangic).toBeNull();
      expect(row.gecerlilik_tarih_durumu).toBe("BELIRLENEMEDI");
      const hash = createHash("sha256")
        .update(row.resmi_aciklama, "utf8")
        .digest("hex");
      expect(row.aciklama_hash).toBe(hash);
    }

    const kod07 = pkg.rows.find(
      (r: { eksik_gun_kodu: string }) => r.eksik_gun_kodu === "07"
    );
    expect(kod07.sifir_gun_sifir_kazanc_durumu).toBe("YASAK");
    expect(kod07.sifir_gun_sifir_kazanc_kullanilabilir_mi).toBe(false);

    const sums = readFileSync(
      "ops/sgk/S106-SGK-EKSIK-GUN-19-SHA256SUMS.txt",
      "utf8"
    );
    const canonicalHash = createHash("sha256")
      .update(readFileSync(canonicalPath))
      .digest("hex");
    expect(sums).toContain(canonicalHash);
    expect(existsSync("ops/sgk/S106-SGK-EKSIK-GUN-19-VERIFY.sql")).toBe(true);
    expect(existsSync("ops/sgk/S106-PRODUCTION-APPLY.md")).toBe(true);
    const verify = readFileSync("ops/sgk/S106-SGK-EKSIK-GUN-19-VERIFY.sql", "utf8");
    expect(verify).not.toMatch(/\b(?:INSERT|UPDATE|DELETE|TRUNCATE)\b/i);
  });
});
