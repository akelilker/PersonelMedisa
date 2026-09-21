import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("personel detay visual parity owners (tasit reference)", () => {
  it("keeps detail card min-width guard and tasit label/value typography", () => {
    const css = read("src/styles/modules/personeller.css");
    expect(css).toMatch(/\.personel-detail-card > \*[\s\S]*min-width:\s*0/s);
    expect(css).toMatch(/\.personel-dosya-field-label[\s\S]*color:\s*var\(--text-inverse\)/s);
    expect(css).toMatch(/\.personel-dosya-field-value[\s\S]*color:\s*var\(--text-secondary\)/s);
    expect(css).toMatch(/\.personel-dosya-hero-name[\s\S]*font-size:\s*15px/s);
    expect(css).toMatch(/@media \(min-width: 641px\)[\s\S]*\.personel-dosya-hero-name[\s\S]*font-size:\s*16px/s);
  });

  it("aligns modal body rhythm for personel detay overlay", () => {
    const modalCss = read("src/styles/components/modal.css");
    expect(modalCss).toMatch(/\.modal-body:has\(> \.personel-detay-page\)[\s\S]*padding-top:\s*0/s);
    expect(modalCss).toMatch(
      /\.modal-body:has\(> \.personel-detay-page\) > \.universal-back-bar[\s\S]*border-bottom:/s
    );
  });
});
