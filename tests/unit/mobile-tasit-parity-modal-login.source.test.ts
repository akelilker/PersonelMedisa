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

  it("keeps mobile login hero band aligned with Taşıt driver-shell proportions", () => {
    const hero = read("src/styles/components/hero.css");
    const mobile = hero.match(/@media\s*\(max-width:\s*640px\)\s*\{([\s\S]*?)\n\}(?=\s*@media|\s*$)/)?.[1] ?? "";
    expect(mobile).toMatch(/body\.login-page \.hero\s*\{[^}]*min-height:\s*76px/s);
    expect(mobile).toMatch(/body\.login-page \.hero-logo\s*\{[^}]*width:\s*48px/s);
    expect(mobile).toMatch(/body\.login-page \.hero-logo img\s*\{[^}]*height:\s*36px/s);
    expect(mobile).toMatch(/body\.login-page \.hero h1\s*\{[^}]*font-size:\s*clamp\(/s);
    expect(mobile).toMatch(/body\.login-page \.hero h1\s*\{[^}]*letter-spacing:\s*clamp\(/s);
  });

  it("keeps authenticated session hero title visible without ellipsis clipping", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(/section\.hero\.hero-with-session > h1\s*\{[^}]*overflow:\s*hidden/s);
    expect(hero).toMatch(/section\.hero\.hero-with-session > h1\s*\{[^}]*text-overflow:\s*clip/s);
    expect(hero).toMatch(/\.hero\.hero-with-session\s*\{[^}]*overflow:\s*visible/s);
  });

  it("keeps mobile home hero title without ellipsis (full PERSONEL title)", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(
      /body\.app-home-route section\.hero\.hero-with-session > h1\s*\{[^}]*text-overflow:\s*clip/s
    );
    expect(hero).not.toMatch(
      /body\.app-home-route section\.hero\.hero-with-session > h1\s*\{[^}]*text-overflow:\s*ellipsis/s
    );
  });

  it("centers whole mobile login form block without splitting fields or changing gap", () => {
    const auth = read("src/styles/modules/auth.css");
    const page = read("src/features/auth/pages/LoginPage.tsx");
    expect(page).not.toMatch(/auth-login-form-middle/);
    expect(auth).toMatch(/\.auth-login-form\s*\{[^}]*gap:\s*12px/s);
    expect(auth).not.toMatch(/auth-login-form-middle/);
    expect(auth).toMatch(/\.auth-login\s*\{[^}]*min-height:\s*0/s);
    expect(auth).not.toMatch(/\.auth-login\s*\{[^}]*min-height:\s*100%/s);
    expect(auth).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*\.auth-login\s*\{[^}]*justify-content:\s*center/s
    );
    expect(auth).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*\.auth-login\s*\{[^}]*flex:\s*1\s+1\s+auto/s
    );
  });

  it("centers desktop login form within remaining panel body", () => {
    const auth = read("src/styles/modules/auth.css");
    expect(auth).toMatch(
      /@media\s*\(min-width:\s*641px\)\s*\{[^}]*\.auth-login\s*\{[^}]*flex:\s*1\s+1\s+auto/s
    );
    expect(auth).toMatch(
      /@media\s*\(min-width:\s*641px\)\s*\{[^}]*\.auth-login\s*\{[^}]*justify-content:\s*center/s
    );
    expect(auth).toMatch(/\.auth-login-stage\s*\{[^}]*justify-content:\s*center/s);
    expect(auth).toMatch(/\.auth-login-form\s*\{[^}]*width:\s*min\(100%,\s*392px\)/s);
  });
});
