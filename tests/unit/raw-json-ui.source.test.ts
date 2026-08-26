import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(rel: string) {
  return readFileSync(resolve(root, rel), "utf8");
}

function walkTsx(dir: string): string[] {
  const entries = readdirSync(dir);
  const files: string[] = [];
  for (const entry of entries) {
    const full = join(dir, entry);
    const stat = statSync(full);
    if (stat.isDirectory()) {
      files.push(...walkTsx(full));
      continue;
    }
    if (entry.endsWith(".tsx")) {
      files.push(full);
    }
  }
  return files;
}

const UI_ROOTS = [
  resolve(root, "src/features"),
  resolve(root, "src/components"),
  resolve(root, "src/app")
];

describe("raw JSON UI regression guards", () => {
  it("does not render user-visible pre blocks with JSON.stringify in production UI sources", () => {
    const offenders: string[] = [];
    for (const uiRoot of UI_ROOTS) {
      for (const file of walkTsx(uiRoot)) {
        const src = readFileSync(file, "utf8");
        if (/<pre[^>]*>\s*\{JSON\.stringify/m.test(src)) {
          offenders.push(file.replace(root + "\\", "").replace(root + "/", ""));
        }
        if (/<pre[^>]*>\{JSON\.stringify/m.test(src)) {
          offenders.push(file.replace(root + "\\", "").replace(root + "/", ""));
        }
      }
    }
    expect(offenders).toEqual([]);
  });

  it("sgk katalog panel uses Turkish summary surfaces instead of raw object dumps", () => {
    const panel = read("src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx");
    expect(panel).not.toMatch(/<pre[^>]*>\{JSON\.stringify/);
    expect(panel).toContain("ApiSonucOzeti");
    expect(panel).toContain('title="İşlem Özeti"');
    expect(panel).toContain("sgk-katalog-operasyonel-result");
    expect(panel).toContain("sgk-katalog-onay-result");
    // Internal package editing still uses JSON.stringify in textarea state
    expect(panel).toContain("setEslemePackageText(JSON.stringify");
  });

  it("maas hesaplama page does not dump source_summary or girdi_ozet as JSON", () => {
    const page = read("src/features/raporlar/pages/MaasHesaplamaMerkeziPage.tsx");
    expect(page).not.toMatch(/JSON\.stringify\(preflight\.source_summary/);
    expect(page).not.toMatch(/JSON\.stringify\(selectedDetail\.girdi_ozet/);
    expect(page).toContain("KaynakOzetOzeti");
    expect(page).toContain("GirdiOzetOzeti");
    expect(page).not.toContain("source_hash:");
    expect(page).not.toContain("snapshot_hash:");
  });

  it("api layer JSON.stringify remains allowed for request bodies", () => {
    const api = read("src/api/sgk-katalog-hazirlik.api.ts");
    expect(api).toMatch(/JSON\.stringify\(body\)/);
  });
});
