import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

function appHomeHeroBlock(heroCss: string): string {
  const match = heroCss.match(
    /@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?body\.app-home-route \.hero\.hero-with-session \{[\s\S]*?\n\}/
  );
  expect(match).not.toBeNull();
  return match?.[0] ?? "";
}

describe("app-home hero session name under logo", () => {
  it("does not clamp the logo flex column to picture width only", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);

    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo\s*\{[^}]*flex:\s*0\s+1\s+auto/s
    );
    // Width-scaled cap: never wider than 112px, but gives width back on narrow
    // phones so the full PERSONEL title keeps its track next to a long name.
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo\s*\{[^}]*max-width:\s*clamp\(70px,\s*19\.2vw,\s*112px\)/s
    );
    expect(block).not.toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo\s*\{[^}]*flex:\s*0\s+0\s+43px/s
    );
    expect(block).not.toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo\s*\{[^}]*max-width:\s*43px/s
    );
  });

  it("lets session meta fill the logo column with multiline wrap instead of single-line ellipsis", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);

    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-meta\s*\{[^}]*width:\s*100%/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-user\s*\{[^}]*white-space:\s*normal/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-user\s*\{[^}]*overflow-wrap:\s*break-word/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-user\s*\{[^}]*text-overflow:\s*clip/s
    );
    expect(block).not.toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-user\s*\{[^}]*text-overflow:\s*ellipsis/s
    );
  });

  it("keeps the PERSONEL title in the center grid track with room to shrink", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);

    expect(block).toMatch(
      /body\.app-home-route section\.hero\.hero-with-session > h1\s*\{[^}]*grid-column:\s*2/s
    );
    expect(block).toMatch(
      /body\.app-home-route section\.hero\.hero-with-session > h1\s*\{[^}]*min-width:\s*0/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.animated-line\s*\{[^}]*grid-column:\s*2/s
    );
    expect(block).toMatch(
      /body\.app-home-route section\.hero\.hero-with-session > h1\s*\{[^}]*text-align:\s*left/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.animated-line\s*\{[^}]*justify-self:\s*start/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.animated-line\s*\{[^}]*margin-left:\s*calc\(var\(--hero-home-title-ink-w\) \* 0\.09\)/s
    );
  });

  it("uses taller home hero band and larger logo ink on mobile", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);

    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session\s*\{[^}]*min-height:\s*79px/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo picture\s*\{[^}]*width:\s*54px/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo img\s*\{[^}]*height:\s*44px/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-user\s*\{[^}]*font-size:\s*10\.5px/s
    );
  });
});
