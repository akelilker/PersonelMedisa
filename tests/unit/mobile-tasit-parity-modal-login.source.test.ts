import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("mobile Taşıt parity modal/login source guards", () => {
  it("keeps mobile modal↔footer gap token at Taşıt rhythm (~6px)", () => {
    const spacing = read("src/styles/tokens/spacing.css");
    expect(spacing).toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[^}]*--modal-gap-above-footer:\s*6px/s
    );
    expect(spacing).toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[^}]*--modal-footer-optical-reserve:\s*0px/s
    );
  });

  it("keeps mobile modal viewport edge parity (no centered floating shell)", () => {
    const modal = read("src/styles/components/modal.css");
    expect(modal).toContain("@media (max-width: 640px)");
    expect(modal).toMatch(/\.modal-overlay\s*\{[^}]*transform:\s*none/s);
    expect(modal).toMatch(/\.modal-overlay\s*\{[^}]*max-width:\s*none/s);
    expect(modal).toContain("@media (max-width: 480px)");
    expect(modal).toMatch(/\.modal-container\s*\{[^}]*border-radius:\s*0/s);
  });

  it("keeps Kayıt personel form 2-column grid on mobile", () => {
    const kayit = read("src/styles/modules/kayit-surec.css");
    expect(kayit).toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[^}]*\.personel-form-columns\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*minmax\(0,\s*1fr\)/s
    );
    expect(kayit).not.toMatch(
      /@media\s*\(max-width:\s*720px\)\s*\{[^}]*\.personel-form-columns\s*\{[^}]*grid-template-columns:\s*1fr/s
    );
    expect(kayit).toMatch(/\.personel-form-columns::after\s*\{[^}]*display:\s*block/s);
  });

  it("keeps login hero title visible without ellipsis clipping", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(/body\.login-page \.hero h1\s*\{[^}]*overflow:\s*visible/s);
    expect(hero).toMatch(/body\.login-page \.hero h1\s*\{[^}]*text-overflow:\s*clip/s);
    expect(hero).toMatch(/body\.login-page \.hero h1\s*\{[^}]*white-space:\s*nowrap/s);
    expect(hero).not.toMatch(/body\.login-page \.hero h1\s*\{[^}]*text-overflow:\s*ellipsis/s);
  });

  it("tightens mobile login form vertical rhythm without desktop overrides", () => {
    const auth = read("src/styles/modules/auth.css");
    expect(auth).toContain("padding: 12px 0 36px");
    expect(auth).toContain("gap: 16px");
    expect(auth).toMatch(/\.auth-login\s*\{[^}]*padding:\s*clamp\(28px,\s*5vh,\s*52px\)/s);
  });
});
