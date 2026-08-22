import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();
const APP_MODAL = resolve(ROOT, "src/components/modal/AppModal.tsx");
const APP_SHELL = resolve(ROOT, "src/app/AppShell.tsx");
const FORM_CSS = resolve(ROOT, "src/styles/components/form.css");
const MODAL_CSS = resolve(ROOT, "src/styles/components/modal.css");
const KAYIT_CSS = resolve(ROOT, "src/styles/modules/kayit-surec.css");
const COLORS = resolve(ROOT, "src/styles/tokens/colors.css");

function read(path: string): string {
  return readFileSync(path, "utf8");
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

  it("bottom-aligns personel create columns via stretched flex stacks", () => {
    const kayitCss = read(KAYIT_CSS);
    const createFields = read(resolve(ROOT, "src/features/personeller/components/PersonelCreateFields.tsx"));

    expect(kayitCss).toMatch(/\.personel-form-columns\s*\{[^}]*align-items:\s*stretch/s);
    expect(kayitCss).toMatch(/\.personel-form-column\s*\{[^}]*display:\s*flex/s);
    expect(kayitCss).toMatch(/\.personel-form-column\s*>\s*:last-child\s*\{[^}]*margin-top:\s*auto/s);
    expect(kayitCss).not.toMatch(/\.personel-form-column\s*\{[^}]*display:\s*contents/s);

    expect(createFields).toContain("personel-form-column--left");
    expect(createFields).toContain("personel-form-column--right");
    expect(createFields).toContain('name="create-kan"');
    expect(createFields).toContain('name="create-maas"');
  });
});
