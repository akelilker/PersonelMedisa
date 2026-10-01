import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("home shell polish + self-service gateway owners", () => {
  it("A) scopes şube selector list typography to sube-selector-dropdown only", () => {
    const icons = read("src/styles/components/icons-row.css");
    expect(icons).toMatch(/\.settings-dropdown\.sube-selector-dropdown button\s*\{[^}]*text-align:\s*center/s);
    expect(icons).toMatch(/\.settings-dropdown\.sube-selector-dropdown button\s*\{[^}]*min-height:\s*44px/s);
    expect(icons).toMatch(/\.settings-dropdown\.sube-selector-dropdown\s*\{[^}]*scrollbar-width:\s*none/s);
    const appSelect = read("src/styles/components/app-select.css");
    expect(appSelect).not.toMatch(/sube-selector/);
  });

  it("B) mobile home hero uses ~2px logo-title gap and optical title centering", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session\s*\{[^}]*--hero-home-logo-footprint/s
    );
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session\s*\{[^}]*--hero-home-logo-title-gap:\s*3px/s
    );
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session\s*\{[^}]*column-gap:\s*var\(--hero-home-logo-title-gap\)/s
    );
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-title-stack\s*\{[^}]*gap:\s*var\(--hero-home-logo-title-gap\)/s
    );
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo picture\s*\{[^}]*margin-right:\s*var\(--hero-home-logo-title-gap\)/s
    );
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-title-stack\s*\{[^}]*padding-right:\s*calc\(\s*min\(clamp\(72px,\s*36vw,\s*132px\),\s*calc\(100vw - 152px\)\) - var\(--hero-home-right-track\)\s*\)/s
    );
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-title-stack > h1\s*\{[^}]*text-align:\s*center/s
    );
  });

  it("C) grows footer MEDİSA brand by exactly 0.5pt vs pre-#464 canonical sizes", () => {
    const footer = read("src/styles/components/footer.css");
    expect(footer).toMatch(/\.footer-content \.brand img\s*\{[^}]*max-height:\s*calc\(12\.5px \+ 0\.5pt\)/s);
    expect(footer).toMatch(
      /body\.app-home-route #app-footer \.brand img\s*\{[^}]*max-height:\s*calc\(14\.27px \+ 1\.5pt \+ 0\.5pt\)/s
    );
    expect(footer).toMatch(
      /body:not\(\.login-page\):not\(\.admin-report-page\) #app-footer \.footer-content \.brand img\s*\{[^}]*height:\s*calc\(20px \+ 0\.5pt\)/s
    );
  });

  it("D) gateway is QR-independent; manager home drops duplicate /self CTA from QR shortcuts", () => {
    const gateway = read("src/features/self-service/components/HomeSelfServiceGateway.tsx");
    expect(gateway).toContain('data-testid="home-self-service-gateway"');
    expect(gateway).toContain('"self_service.view"');
    expect(gateway).toContain("hasPersonnelLinkedSelfServiceEligibility");
    expect(gateway).toContain('aria-label="Kullanıcı Paneli"');
    expect(gateway).toContain("Kullanıcı Paneli");
    expect(gateway).toContain("GATEWAY_DIM_DELAY_MS = 4000");
    expect(gateway).toContain("home-self-service-gateway--dimmed");
    expect(gateway).not.toContain("Kendi Bilgilerim");
    expect(gateway).not.toContain("self_service.qr.scan");

    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(
      "var(--app-footer-real-height) + var(--app-footer-gap) + env(safe-area-inset-bottom, 0px) - 12px"
    );
    expect(css).toContain("var(--app-footer-real-height) + var(--app-footer-gap) + 4px - 12px");
    expect(css).toContain("transition: color 0.2s, opacity 0.2s;");
    expect(css).toMatch(/\.home-self-service-gateway--dimmed\s*\{[^}]*opacity:\s*0\.7/s);
    expect(css).toMatch(
      /\.home-self-service-gateway:hover,\s*\.home-self-service-gateway:focus,\s*\.home-self-service-gateway:focus-visible\s*\{[^}]*opacity:\s*1/s
    );

    const shell = read("src/app/AppShell.tsx");
    expect(shell).toContain("<HomeSelfServiceGateway />");

    const routes = read("src/app/routes.tsx");
    const start = routes.indexOf("function HomeIndexMainMenu()");
    const end = routes.indexOf("\nfunction ", start + 1);
    const block = routes.slice(start, end);
    expect(block).toContain("<SelfServiceQrShortcuts");
    expect(block).not.toContain("showSelfServiceHomeLink");
  });
});
