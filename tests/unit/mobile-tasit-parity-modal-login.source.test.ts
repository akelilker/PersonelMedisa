import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("mobile Taşıt parity modal/login source guards", () => {
  it("keeps one mobile modal↔footer rhythm shared by every role", () => {
    const spacing = read("src/styles/tokens/spacing.css");
    // Taşıt style-core: mobile gap is the 20px --app-footer-gap. Desktop 641+ narrows
    // that token to 14px. Do not re-pin a separate mobile 14px on --modal-gap-above-footer.
    expect(spacing).toMatch(/--app-footer-gap:\s*20px/);
    expect(spacing).toMatch(/--modal-gap-above-footer:\s*var\(--app-footer-gap\)/);
    expect(spacing).toMatch(/@media\s*\(min-width:\s*641px\)\s*\{[^}]*--app-footer-gap:\s*14px/s);
    expect(spacing).not.toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[^}]*--modal-gap-above-footer:\s*14px/s
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
    expect(modal).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*\.modal-overlay\s*\{[^}]*padding-top:\s*env\(safe-area-inset-top,\s*0px\)/s
    );
    expect(modal).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*\.modal-overlay\.open[\s\S]*background-image:\s*linear-gradient/s
    );
    expect(modal).toContain("@media (max-width: 480px)");
    expect(modal).toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*\.modal-container\s*\{[^}]*border-radius:\s*var\(--modal-radius,\s*10px\)/
    );
    expect(modal).not.toMatch(/\.modal-container\s*\{[^}]*border-radius:\s*0/s);
  });

  it("keeps Personel Kartı scope grid below toolbar on mobile (no vertical center overflow)", () => {
    const personeller = read("src/styles/modules/personeller.css");
    expect(personeller).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*\.personeller-page--kart \.personeller-scope-stage\s*\{[^}]*align-items:\s*flex-start/s
    );
    expect(personeller).toMatch(
      /\.personeller-page--kart \.personeller-scope-stage\s*\{[^}]*overflow-y:\s*auto/s
    );
  });

  it("does not expand modal header height into iOS status-bar safe area (standalone)", () => {
    const iosPwa = read("src/styles/platform/ios-pwa.css");
    expect(iosPwa).not.toMatch(
      /\.modal-header\s*\{[^}]*height:\s*calc\(60px \+ env\(safe-area-inset-top/s
    );
    expect(iosPwa).toMatch(
      /\.modal-overlay\s*\{[^}]*padding-top:\s*env\(safe-area-inset-top,\s*0px\)/s
    );
  });

  it("keeps AppSelect native layer from opening iOS system picker on touch (pointer-events swap)", () => {
    const appSelect = read("src/styles/components/app-select.css");
    expect(appSelect).toMatch(
      /@media\s*\(hover:\s*none\)\s*and\s*\(pointer:\s*coarse\)\s*\{[\s\S]*\.app-select-native\s*\{[^}]*pointer-events:\s*none/s
    );
    expect(appSelect).toMatch(
      /@media\s*\(hover:\s*none\)\s*and\s*\(pointer:\s*coarse\)\s*\{[\s\S]*\.app-select-trigger\s*\{[^}]*pointer-events:\s*auto/s
    );
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

  it("matches desktop login hero band to the authenticated session hero height", () => {
    const hero = read("src/styles/components/hero.css");
    const desktop = hero.match(/@media\s*\(min-width:\s*641px\)\s*\{([\s\S]*?)\n\}(?=\s*@media|\s*$)/)?.[1] ?? "";
    // Authenticated main-screen hero (`.hero-with-session`) renders ~84px on desktop
    // because its logo column also carries the username line. Login shows no username,
    // so it must reserve the same band + inner vertical padding instead of the 60px base.
    expect(desktop).toMatch(/body\.login-page \.hero\s*\{[^}]*min-height:\s*84px/s);
    expect(desktop).toMatch(/body\.login-page \.hero\s*\{[^}]*padding-top:\s*12px/s);
    expect(desktop).toMatch(/body\.login-page \.hero\s*\{[^}]*padding-bottom:\s*12px/s);
    // Mobile login band is unchanged (separate ≤640 owner owns its 76px band).
    expect(hero).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*body\.login-page \.hero\s*\{[^}]*min-height:\s*76px/s
    );
  });

  it("keeps authenticated session hero title visible without ellipsis clipping", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(/section\.hero\.hero-with-session \.hero-title-stack > h1\s*\{[^}]*overflow:\s*hidden/s);
    expect(hero).toMatch(/section\.hero\.hero-with-session \.hero-title-stack > h1\s*\{[^}]*text-overflow:\s*clip/s);
    expect(hero).toMatch(/\.hero\.hero-with-session\s*\{[^}]*overflow:\s*visible/s);
  });

  it("keeps mobile home hero title without ellipsis (full PERSONEL title)", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-title-stack > h1\s*\{[^}]*text-overflow:\s*clip/s
    );
    expect(hero).not.toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-title-stack > h1\s*\{[^}]*text-overflow:\s*ellipsis/s
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
