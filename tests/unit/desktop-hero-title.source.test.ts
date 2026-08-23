import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

function desktopHeroBlock(heroCss: string): string {
  const match = heroCss.match(/@media\s*\(min-width:\s*641px\)\s*\{([\s\S]*?)\n\}/);
  expect(match).not.toBeNull();
  return match?.[1] ?? "";
}

describe("desktop hero title fit", () => {
  it("keeps desktop title typography within the measured center-track budget", () => {
    const hero = read("src/styles/components/hero.css");
    const desktop = desktopHeroBlock(hero);

    // Regression: 20px + 1.8px tracking clipped "Personel Yönetim Sistemi"
    // in the ~331px center track of the 492px desktop hero shell.
    expect(desktop).toMatch(/\.hero h1,\s*body\.login-page \.hero h1\s*\{[^}]*font-size:\s*19px/s);
    expect(desktop).toMatch(/\.hero h1,\s*body\.login-page \.hero h1\s*\{[^}]*letter-spacing:\s*1\.8px/s);
    expect(desktop).not.toMatch(/\.hero h1,\s*body\.login-page \.hero h1\s*\{[^}]*font-size:\s*20px/s);
  });

  it("centers the desktop accent line in the same grid track as the title", () => {
    const hero = read("src/styles/components/hero.css");
    const desktop = desktopHeroBlock(hero);

    expect(desktop).toMatch(/grid-template-rows:\s*auto\s+auto/);
    expect(desktop).toMatch(
      /\.hero h1,\s*body\.login-page \.hero h1\s*\{[^}]*grid-row:\s*1/s,
    );
    expect(desktop).toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*grid-column:\s*2/s,
    );
    expect(desktop).toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*grid-row:\s*2/s,
    );
    expect(desktop).toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*position:\s*static/s,
    );
    expect(desktop).toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*justify-self:\s*center/s,
    );
    expect(desktop).toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*width:\s*74%/s,
    );
    expect(desktop).not.toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*position:\s*absolute/s,
    );
    expect(desktop).not.toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*left:\s*-?\d+px/s,
    );
    expect(desktop).not.toMatch(
      /\.animated-line,\s*body\.login-page \.hero \.animated-line\s*\{[^}]*transform:\s*translateX/s,
    );
  });

  it("does not change mobile hero title overflow policy", () => {
    const hero = read("src/styles/components/hero.css");
    const mobile = hero.match(/@media\s*\(max-width:\s*640px\)\s*\{([\s\S]*?)\n\}(?=\s*@media|\s*$)/)?.[1] ?? "";

    expect(mobile).toMatch(/body\.login-page \.hero h1\s*\{[^}]*overflow:\s*visible/s);
    expect(mobile).toMatch(/body\.login-page \.hero h1\s*\{[^}]*text-overflow:\s*clip/s);
    expect(mobile).toMatch(/\.hero\.hero-with-session\s*\{[^}]*--hero-logo-width:\s*48px/s);
    expect(mobile).toMatch(/\.hero\.hero-with-session\s*\{[^}]*--hero-spacer-width:\s*48px/s);
  });
});
