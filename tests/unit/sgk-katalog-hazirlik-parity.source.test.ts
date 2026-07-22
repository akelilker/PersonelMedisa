import { existsSync, readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  buildSgkKatalogImportDryRunMock,
  buildSgkKatalogTamlikMock,
  SGK_KATALOG_TAMLIK_BLOCKER
} from "../../src/api/sgk-katalog-hazirlik.mock";

describe("S85-C1 SGK katalog hazirlik parity", () => {
  it("keeps empty catalog + tamlik blocker contract in mock", () => {
    const tamlik = buildSgkKatalogTamlikMock();
    expect(tamlik.kod_sayisi).toBe(0);
    expect(tamlik.onaylanabilir_mi).toBe(false);
    expect(tamlik.dogrulanmis_tam_secilebilir_mi).toBe(false);
    expect(tamlik.blocker_kodlari).toContain(SGK_KATALOG_TAMLIK_BLOCKER.code);
    expect(tamlik.tamlik_durumu).not.toBe("DOGRULANMIS_TAM");

    const dry = buildSgkKatalogImportDryRunMock();
    expect(dry.import_yapilabilir_mi).toBe(false);
    expect(dry.yazma_endpoint_aktif_mi).toBe(false);
    expect(dry.mode).toBe("DRY_RUN");
  });

  it("wires router, endpoints, UI panel and docs without seed/039", () => {
    const router = readFileSync(resolve("api/src/Router.php"), "utf8");
    const endpoints = readFileSync(resolve("src/api/endpoints.ts"), "utf8");
    const page = readFileSync(resolve("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx"), "utf8");
    const panel = readFileSync(resolve("src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx"), "utf8");
    const docs = readFileSync(resolve("docs/guncel/94-s85c-sgk-katalog-manuel-kanit-talep-paketi.md"), "utf8");
    const controller = readFileSync(resolve("api/src/Controllers/SgkKatalogHazirlikController.php"), "utf8");
    const reader = readFileSync(resolve("api/src/Services/Payroll/SgkKaynakManifestReader.php"), "utf8");
    const migrationNames = readdirSync(resolve("api/migrations"));

    expect(router).toContain("/sgk-katalog-hazirlik/tamlik");
    expect(router).toContain("SgkKatalogHazirlikController::importDryRun");
    expect(router).not.toContain("importWrite");
    expect(endpoints).toContain("sgkKatalogHazirlik");
    expect(page).toContain("sgk-katalog");
    expect(panel).toContain("DOGRULANMIS_TAM seçilemez");
    expect(panel).toContain("sgk-katalog-approve");
    expect(docs).toContain("OPERASYONEL_DOGRULAMA_KANITI");
    expect(docs).not.toMatch(/C:\\Users\\Akel\\Downloads/);
    expect(existsSync(resolve("api/migrations/036_sgk_prim_gunu_owner.sql"))).toBe(true);
    expect(existsSync(resolve("api/migrations/037_sgk_resmi_kaynak_manifesti_v1.sql"))).toBe(true);
    expect(migrationNames.some((name) => name.startsWith("038_"))).toBe(true);
    expect(migrationNames.some((name) => name.startsWith("039_"))).toBe(false);

    expect(reader).toContain("SGK_KAYNAK_MANIFEST_STORAGE_HATASI");
    expect(controller).toContain("SgkKaynakManifestReader::fetchAll");
    expect(controller).toContain("SgkKaynakManifestReader::STORAGE_ERROR_CODE");
    expect(controller).not.toMatch(/catch\s*\([^)]*\)\s*\{\s*return\s*\[\];/);
    expect(controller).toContain("'manifests' => self::loadManifests($pdo)");
    expect(controller).toContain("SgkOperasyonelKanitBase64Guard::resolve");
    expect(controller).toContain("operasyonel_kanit_max_decoded_bytes");
    expect(controller).not.toMatch(/base64_decode\(\$body/);

    const base64Guard = readFileSync(
      resolve("api/src/Services/Payroll/SgkOperasyonelKanitBase64Guard.php"),
      "utf8"
    );
    expect(base64Guard).toContain("MAX_DECODED_BYTES = 10 * 1024 * 1024");
    expect(base64Guard).toContain("SGK_OPERASYONEL_KANIT_BASE64_GECERSIZ");
    expect(base64Guard).toContain("SGK_OPERASYONEL_KANIT_DOSYA_BOYUTU_ASILDI");

    const apiClient = readFileSync(resolve("src/api/sgk-katalog-hazirlik.api.ts"), "utf8");
    expect(apiClient).toContain("SGK_OPERASYONEL_KANIT_MAX_DECODED_BYTES = 10 * 1024 * 1024");

    const importValidator = readFileSync(
      resolve("api/src/Services/Payroll/SgkKatalogImportValidator.php"),
      "utf8"
    );
    expect(importValidator).toContain("KAYNAKSIZ_KOD_ARALIGI_22_29");
    expect(importValidator).toContain("import_yapilabilir_mi");
    expect(importValidator).toContain("DRY_RUN");

    const demo = readFileSync(resolve("src/api/mock-demo.ts"), "utf8");
    const e2e = readFileSync(resolve("tests/e2e/helpers/mock-api.ts"), "utf8");
    expect(demo).toContain("/sgk-katalog-hazirlik/tamlik");
    expect(e2e).toContain("/api/sgk-katalog-hazirlik/tamlik");
    expect(demo).toContain("buildSgkKatalogTamlikMock");
    expect(e2e).toContain("buildSgkKatalogTamlikMock");
  });
});
