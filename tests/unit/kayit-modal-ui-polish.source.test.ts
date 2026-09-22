import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { INITIAL_CREATE_PERSONEL_FORM } from "../../src/hooks/usePersoneller";

const root = process.cwd();

function read(path: string) {
  return readFileSync(resolve(root, path), "utf8");
}

describe("Kayıt modal UI polish source locks", () => {
  it("removes grey tab rule, compacts tab row, and mutes Kayıt placeholders", () => {
    const kayitCss = read("src/styles/modules/kayit-surec.css");

    expect(kayitCss).toMatch(
      /\.modal-container--kayit-surec \.kayit-workspace-tabs[\s\S]*margin-top: -6px/
    );
    expect(kayitCss).toMatch(
      /\.modal-container--kayit-surec \.kayit-workspace-tabs[\s\S]*border-bottom: none/
    );
    expect(kayitCss).not.toMatch(/\.kayit-workspace-tabs \{[\s\S]*border-bottom: 1px solid rgba\(255, 255, 255, 0\.14\)/);
    expect(kayitCss).toContain("rgba(230, 234, 245, 0.26)");
    expect(kayitCss).toContain(".kayit-workspace-grid--personel-form .app-select-trigger-text.is-placeholder");
  });

  it("extends Kayıt form center red divider downward with subtle glow", () => {
    const kayitCss = read("src/styles/modules/kayit-surec.css");

    expect(kayitCss).toMatch(
      /\.kayit-workspace-grid--personel-form \.personel-form-columns::after[\s\S]*bottom: -14px/
    );
    expect(kayitCss).toMatch(
      /\.kayit-workspace-grid--personel-form \.personel-form-columns::after[\s\S]*background: rgba\(var\(--theme-color-rgb\), 0\.26\)/
    );
    expect(kayitCss).toMatch(
      /\.kayit-workspace-grid--personel-form \.personel-form-columns::after[\s\S]*box-shadow: 0 0 10px rgba\(var\(--theme-color-rgb\), 0\.32\)/
    );
  });

  it("defaults new personel ücret tipi to Saatlik (id 3)", () => {
    expect(INITIAL_CREATE_PERSONEL_FORM.ucretTipiId).toBe("3");
  });

  it("styles bulk import chrome: blue link, no import back-bar rule, centered stage", () => {
    const personelCss = read("src/styles/modules/personeller.css");

    expect(personelCss).toContain(".kayit-bulk-import-link");
    expect(personelCss).toMatch(/\.kayit-bulk-import-link[\s\S]*color: #60a5fa/);
    expect(personelCss).toMatch(/\.personel-import-back-bar[\s\S]*border-bottom: none/);
    expect(personelCss).toMatch(
      /\.personel-import-dry-run-modal:not\(\.modal-container--fixed-footer\)[\s\S]*justify-content: center/
    );
  });
});
