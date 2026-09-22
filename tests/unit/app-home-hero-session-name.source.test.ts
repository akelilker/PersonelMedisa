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
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo\s*\{[^}]*max-width:\s*min\(112px,\s*30vw\)/s
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

  it("keeps the PERSONEL title on a shrinkable flex track", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);

    expect(block).toMatch(
      /body\.app-home-route section\.hero\.hero-with-session > h1\s*\{[^}]*flex:\s*1\s+1\s+0/s
    );
  });
});
