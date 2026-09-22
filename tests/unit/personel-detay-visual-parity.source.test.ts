import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("personel detay visual parity owners (tasit reference)", () => {
  it("exposes horizontal tab overflow controls with hidden scrollbar", () => {
    const tabs = read("src/features/personeller/components/personel-dosya/PersonelDosyaTabs.tsx");
    expect(tabs).toMatch(/personel-kart-tab-scroller/);
    expect(tabs).toMatch(/aria-label="Önceki sekmeler"/);
    expect(tabs).toMatch(/aria-label="Sonraki sekmeler"/);
    expect(tabs).toMatch(/scrollIntoView/);

    const css = read("src/styles/modules/personeller.css");
    expect(css).toMatch(/\.personel-kart-tablist[\s\S]*scrollbar-width:\s*none/s);
    expect(css).toMatch(/\.personel-kart-tab-scroller\.is-overflowing/);
  });

  it("keeps hero and tabs outside the per-tab scroll region", () => {
    const page = read("src/features/personeller/pages/PersonelDetayPage.tsx");
    expect(page).toMatch(/personel-dosya-sticky-head[\s\S]*PersonelDosyaTabList/s);
    expect(page).toMatch(/personel-dosya-tab-scroll[\s\S]*PersonelDosyaTabPanels/s);
    expect(page).not.toMatch(/personel-dosya-tab-scroll[\s\S]*personel-dosya-sticky-head/s);

    const css = read("src/styles/modules/personeller.css");
    expect(css).toMatch(/\.personel-dosya-tab-scroll[\s\S]*overflow-y:\s*auto/s);
    expect(css).toMatch(/\.personel-dosya-sticky-head[\s\S]*flex:\s*0\s*0\s*auto/s);

    const modalCss = read("src/styles/components/modal.css");
    expect(modalCss).toMatch(
      /\.modal-body:has\(> \.personel-detay-page\)[\s\S]*overflow:\s*hidden/s
    );
  });

  it("keeps detail card min-width guard and tasit label/value typography", () => {
    const css = read("src/styles/modules/personeller.css");
    expect(css).toMatch(/\.personel-detail-card > \*[\s\S]*min-width:\s*0/s);
    expect(css).toMatch(/\.personel-dosya-field-label[\s\S]*color:\s*var\(--text-inverse\)/s);
    expect(css).toMatch(/\.personel-dosya-field-value[\s\S]*color:\s*var\(--text-secondary\)/s);
    expect(css).toMatch(/\.personel-dosya-hero-name[\s\S]*font-size:\s*15px/s);
    expect(css).toMatch(/@media \(min-width: 641px\)[\s\S]*\.personel-dosya-hero-name[\s\S]*font-size:\s*16px/s);
    expect(css).toMatch(/\.personel-dosya-sticky-head[\s\S]*background:\s*var\(--modal-bg\)/s);
    expect(css).toMatch(/\.personel-kart-tablist[\s\S]*background:\s*var\(--modal-bg\)/s);
    expect(css).toMatch(/\.personel-detail-card \.personel-dosya-kayit-mirror \.personel-form-column/s);

    const mirror = read("src/features/personeller/components/personel-dosya/PersonelDosyaKayitMirrorFields.tsx");
    expect(mirror).toMatch(/personel-form-columns/);
    expect(mirror).not.toContain("Temel kimlik, iletişim ve lokasyon verileri");

    const genelPanel = read("src/features/personeller/components/personel-dosya/PersonelKartPanelGenelBilgiler.tsx");
    expect(genelPanel).not.toContain("Kimlik ve İletişim");
    expect(genelPanel).not.toMatch(/denseGrid/);
  });

  it("aligns modal body rhythm for personel detay overlay", () => {
    const modalCss = read("src/styles/components/modal.css");
    expect(modalCss).toMatch(/\.modal-body:has\(> \.personel-detay-page\)[\s\S]*padding-top:\s*0/s);
    expect(modalCss).toMatch(
      /\.modal-body:has\(> \.personel-detay-page\) > \.universal-back-bar[\s\S]*background:\s*var\(--modal-bg\)/s
    );
  });
});
