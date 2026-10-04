import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("home shell header toolbar polish", () => {
  it("uses map-pin icon branch control with accessible filter label", () => {
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    expect(shell).toContain('data-testid="header-sube-selector-toggle"');
    expect(shell).toContain("sube-selector-icon");
    expect(shell).not.toContain("sube-selector-building-icon");
    expect(shell).not.toContain("sube-selector-label");
    expect(shell).toContain("activeSubeFilterLabel");
    expect(shell).toMatch(/aria-label=\{`Şube filtresi: \$\{activeSubeFilterLabel\}`\}/);
    expect(shell).toMatch(/title=\{`Şube filtresi: \$\{activeSubeFilterLabel\}`\}/);
    expect(shell).toMatch(/className="sube-selector-icon"[\s\S]*?width="19"/);
    expect(shell).toMatch(/className="sube-selector-chevron"[\s\S]*?width="12"/);
  });

  it("keeps branch glyphs at the shared icon opacity", () => {
    const icons = read("src/styles/components/icons-row.css");
    expect(icons).not.toMatch(/\.sube-selector-(?:icon|chevron)\s*\{[^}]*opacity:/s);
  });

  it("keeps the hero branch label readable but quieter than the title", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(
      /\.hero-session-sube\s*\{[^}]*color:\s*rgba\(210,\s*222,\s*234,\s*0\.86\)/s
    );
    expect(hero).toMatch(/\.hero-session-sube\s*\{[^}]*font-size:\s*clamp\(10px,\s*2vw,\s*13px\)/s);
    expect(hero).toMatch(
      /\.hero-with-session \.hero-session-sube\s*\{[^}]*font-size:\s*13px/s
    );
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-sube\s*\{[^}]*font-size:\s*11\.5px/s
    );
  });

  it("centers the hero branch label under the accent line without moving session user", () => {
    const hero = read("src/styles/components/hero.css");
    expect(hero).toMatch(/\.hero-with-session \.hero-session-sube\s*\{[^}]*bottom:\s*6px/s);
    expect(hero).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-sube\s*\{[^}]*bottom:\s*3px/s
    );
    expect(hero).not.toMatch(/\.hero-session-user\s*\{[^}]*bottom:/s);
  });

  it("matches home icons→menu gap to hero→icons gap", () => {
    const icons = read("src/styles/components/icons-row.css");
    expect(icons).toMatch(
      /\.icons-row--minimal\s*\{[^}]*margin-bottom:\s*calc\(var\(--shell-top-stack-gap\)\s*\+\s*4px\)/s
    );
    expect(icons).not.toMatch(/\.icons-row--minimal\s*\{[^}]*margin-bottom:\s*var\(--space-4\)/s);
  });

  it("keeps calendar badge hover tied to header button", () => {
    const bugunCss = read("src/styles/modules/bugun-personel-durumu.css");
    expect(bugunCss).toMatch(
      /\.bugun-personel-header-btn:hover \.bugun-personel-header-badge/s
    );
    expect(bugunCss).toMatch(
      /\.bugun-personel-header-btn:focus-visible \.bugun-personel-header-badge/s
    );
  });

  it("strengthens minimal home toolbar icon hover glow", () => {
    const icons = read("src/styles/components/icons-row.css");
    expect(icons).toMatch(/\.icons-row--minimal \.icon-btn:hover/s);
    expect(icons).toMatch(/\.icons-row--minimal \.icon-btn\.notification-red:hover/s);
  });
});
