import { existsSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();
const STYLES_ROOT = resolve(ROOT, "src/styles");
const MAIN_CSS = join(STYLES_ROOT, "main.css");
const TABLE_CSS = join(STYLES_ROOT, "components", "table.css");
const PUANTAJ_CSS = join(STYLES_ROOT, "modules", "puantaj.css");
const PERSONELLER_CSS = join(STYLES_ROOT, "modules", "personeller.css");
const YONETIM_CSS = join(STYLES_ROOT, "modules", "yonetim.css");
const RAPORLAR_CSS = join(STYLES_ROOT, "modules", "raporlar.css");

function read(path: string): string {
  return readFileSync(path, "utf8");
}

const REQUIRED_TABLE_OWNERS = [
  ".app-table",
  ".raporlar-table",
  ".yonetim-list-table",
  ".personeller-table",
  ".personel-belge-kayit-table",
  ".puantaj-etki-aday-table",
  ".puantaj-etki-conflict-compare-table"
] as const;

const REQUIRED_WRAP_OWNERS = [
  ".app-table-wrap",
  ".raporlar-table-wrap",
  ".yonetim-list-table-wrap",
  ".personeller-table-wrap",
  ".personel-belge-kayit-table-wrap",
  ".puantaj-etki-aday-table-wrap"
] as const;

describe("PACK V3 table/list standardization invariants", () => {
  it("registers the canonical table owner after feature CSS", () => {
    expect(existsSync(TABLE_CSS)).toBe(true);
    const main = read(MAIN_CSS);
    const ownerImport = '@import "./components/table.css";';
    expect(main.includes(ownerImport)).toBe(true);
    expect(main.indexOf(ownerImport)).toBeGreaterThan(main.indexOf('@import "./modules/yonetim.css";'));
    expect(main.indexOf(ownerImport)).toBeLessThan(main.indexOf('@import "./platform/mobile.css";'));
  });

  it("covers canonical operational table and wrapper families", () => {
    const source = read(TABLE_CSS);
    for (const owner of REQUIRED_TABLE_OWNERS) {
      expect(source.includes(owner)).toBe(true);
    }
    for (const owner of REQUIRED_WRAP_OWNERS) {
      expect(source.includes(owner)).toBe(true);
    }
  });

  it("keeps the shared table owner free of raw hex and important overrides", () => {
    const source = read(TABLE_CSS);
    expect(source).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
    expect(source).not.toContain("!important");
  });

  it("preserves personeller sticky header and clickable-row feature contracts", () => {
    const source = read(PERSONELLER_CSS);
    expect(source).toContain(".personeller-table thead th");
    expect(source).toContain("position: sticky");
    expect(source).toContain(".personeller-table-row-clickable");
  });

  it("preserves puantaj table-to-card mobile fallback", () => {
    const source = read(PUANTAJ_CSS);
    expect(source).toContain(".puantaj-etki-aday-table-wrap");
    expect(source).toContain("display: none");
    expect(source).toContain(".puantaj-etki-aday-card-list");
    expect(source).toContain("display: grid");
  });

  it("preserves yonetim list table and rapor table minimum-width ownership", () => {
    const yonetim = read(YONETIM_CSS);
    const raporlar = read(RAPORLAR_CSS);
    expect(yonetim).toContain(".yonetim-list-table");
    expect(yonetim).toContain("min-width: 720px");
    expect(raporlar).toContain(".raporlar-table");
    expect(raporlar).toContain("min-width: 560px");
  });

  it("keeps Class-E list/card surfaces as lists rather than forcing table markup", () => {
    const source = read(TABLE_CSS);
    expect(source).toContain(".app-list");
    expect(source).toContain(".personeller-list");
    expect(source).toContain(".puantaj-etki-aday-card-list");
    expect(source).toContain("list-style: none");
  });
});
