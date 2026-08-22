import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();
const APP_MODAL = resolve(ROOT, "src/components/modal/AppModal.tsx");
const APP_SHELL = resolve(ROOT, "src/app/AppShell.tsx");
const FORM_CSS = resolve(ROOT, "src/styles/components/form.css");
const MODAL_CSS = resolve(ROOT, "src/styles/components/modal.css");
const KAYIT_CSS = resolve(ROOT, "src/styles/modules/kayit-surec.css");
const COLORS = resolve(ROOT, "src/styles/tokens/colors.css");
const CREATE_FIELDS = resolve(ROOT, "src/features/personeller/components/PersonelCreateFields.tsx");
const ENUM_DISPLAY = resolve(ROOT, "src/lib/display/enum-display.ts");

function read(path: string): string {
  return readFileSync(path, "utf8");
}

function splitCreateColumns(source: string): { left: string; right: string } {
  const parts = source.split('<div className="personel-form-column">');
  expect(parts.length).toBe(3);
  return { left: parts[1], right: parts[2] };
}

function assertNoSpacerHacks(kayitCss: string, createFields: string) {
  expect(kayitCss).toMatch(/\.personel-form-column\s*\{[^}]*display:\s*grid/s);
  expect(kayitCss).toMatch(/\.personel-form-column\s*\{[^}]*align-content:\s*start/s);
  expect(kayitCss).not.toMatch(/\.personel-form-column\s*>\s*:last-child\s*\{[^}]*margin-top:\s*auto/s);
  expect(kayitCss).not.toMatch(/\.personel-form-columns\s*\{[^}]*align-items:\s*stretch/s);
  expect(kayitCss).not.toMatch(/\.personel-form-column\s*\{[^}]*display:\s*flex/s);
  expect(kayitCss).not.toMatch(/personel-form-field-unit/);
  expect(createFields).not.toMatch(/personel-form-column--(?:left|right)/);
  expect(createFields).not.toMatch(/personel-form-field-unit/);
  expect(createFields).not.toMatch(/marginTop:\s*["']?auto/);
  expect(createFields).not.toMatch(/visibility:\s*["']?hidden/);
}

describe("kayit modal flow actions + field surface parity", () => {
  it("wires AppModal flow footer placement for Kayıt modal only", () => {
    const appModal = read(APP_MODAL);
    const appShell = read(APP_SHELL);
    expect(appModal).toContain('footerPlacement?: "fixed" | "flow"');
    expect(appModal).toContain('footerPlacement = "fixed"');
    expect(appModal).toContain("modal-footer--flow");
    expect(appShell).toContain('footerPlacement="flow"');
    expect(appShell).toContain("modal-container--kayit-surec");
  });

  it("keeps flow footer separatorless and body as kayit scroll owner", () => {
    const modalCss = read(MODAL_CSS);
    const kayitCss = read(KAYIT_CSS);
    expect(modalCss).toMatch(/\.modal-footer--flow\s*\{[^}]*border-top:\s*none/s);
    expect(kayitCss).toMatch(/\.modal-body--kayit-surec\s*\{[^}]*overflow-y:\s*auto/s);
    expect(kayitCss).toMatch(/\.kayit-workspace-scroll-body\s*\{[^}]*overflow:\s*visible/s);
    expect(kayitCss).toContain(".modal-footer--flow");
    expect(kayitCss).not.toMatch(/\.modal-container--kayit-surec \.modal-footer\s*\{/);
  });

  it("uses semantic --bg-field for shared form-input surfaces", () => {
    const formCss = read(FORM_CSS);
    const colors = read(COLORS);
    expect(colors).toMatch(/--bg-field:\s*#0f1418/);
    expect(formCss).toMatch(/\.form-input\s*\{[^}]*background:\s*var\(--bg-field\)/s);
    expect(formCss).toMatch(/box-shadow:\s*0 0 0 1000px var\(--bg-field\) inset/);
    expect(formCss).not.toMatch(/\.form-input\s*\{[^}]*background:\s*var\(--bg-surface-elevated\)/s);
  });

  it("populated references: Sicil left, 11/11 columns, natural bottom row, no spacer hacks", () => {
    const kayitCss = read(KAYIT_CSS);
    const createFields = read(CREATE_FIELDS);
    assertNoSpacerHacks(kayitCss, createFields);

    const { left, right } = splitCreateColumns(createFields);

    expect((createFields.match(/name="create-sicil"/g) ?? []).length).toBe(1);
    expect(left).toContain('name="create-sicil"');
    expect(right).not.toContain('name="create-sicil"');

    const leftNames = [...left.matchAll(/name="(create-[^"]+)"/g)].map((m) => m[1]);
    const rightNames = [...right.matchAll(/name="(create-[^"]+)"/g)].map((m) => m[1]);

    expect(leftNames).toEqual([
      "create-calisan-kapsami",
      "create-sicil",
      "create-tc",
      "create-ad",
      "create-soyad",
      "create-dogum",
      "create-telefon",
      "create-acil-kisi",
      "create-acil-tel",
      "create-dogum-yeri",
      "create-kan"
    ]);
    expect(rightNames).toEqual([
      "create-ise-giris",
      "create-sube",
      "create-bagli-amir",
      "create-departman",
      "create-bolum",
      "create-birim",
      "create-gorev",
      "create-pozisyon",
      "create-personel-tipi",
      "create-ucret-tipi",
      "create-maas"
    ]);
    expect(leftNames.at(-1)).toBe("create-kan");
    expect(rightNames.at(-1)).toBe("create-maas");
  });

  it("empty references: keeps canonical conditional render for Bölüm/Birim/Pozisyon", () => {
    const createFields = read(CREATE_FIELDS);

    expect(createFields).toMatch(/refs\.bolumOptions\.length\s*>\s*0\s*\?[\s\S]*?name="create-bolum"/);
    expect(createFields).toMatch(/refs\.birimOptions\.length\s*>\s*0\s*\?[\s\S]*?name="create-birim"/);
    expect(createFields).toMatch(/refs\.pozisyonOptions\.length\s*>\s*0\s*\?[\s\S]*?name="create-pozisyon"/);

    expect(createFields).not.toMatch(
      /refMissingNote\("Bölüm"|refMissingNote\("Birim"|refMissingNote\("Pozisyon"/
    );
  });

  it("uses canonical Çalışan Kapsamı display labels without changing enum values", () => {
    const enumDisplay = read(ENUM_DISPLAY);
    const createFields = read(CREATE_FIELDS);

    expect(enumDisplay).toContain("IC_PERSONEL: \"Dahili Personel\"");
    expect(enumDisplay).toContain("DIS_KAYNAK: \"Harici Personel\"");
    expect(enumDisplay).toContain("CALISAN_KAPSAMI_SELECT_OPTIONS");
    expect(enumDisplay).toContain("formatCalisanKapsamiLabel");

    expect(createFields).toContain("CALISAN_KAPSAMI_SELECT_OPTIONS");
    expect(createFields).not.toContain("İç Personel");
    expect(createFields).not.toContain("İç Kaynak");
    expect(createFields).not.toContain("Dış Kaynak");
    expect(createFields).not.toContain("SGK Başka İşverende");

    expect(enumDisplay).toMatch(/value:\s*"IC_PERSONEL"/);
    expect(enumDisplay).toMatch(/value:\s*"DIS_KAYNAK"/);
  });
});
