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

describe("app-home mobile shell inset (hero + menu)", () => {
  it("does not bleed the home hero frame past the content track", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);

    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session\s*\{[^}]*width:\s*calc\(100%\s*-\s*4px\)/s
    );
    expect(block).not.toMatch(/calc\(100%\s*\+\s*4px\)/);
    expect(block).not.toMatch(/margin-left:\s*-2px/);
    expect(block).not.toMatch(/margin-right:\s*-2px/);
  });

  it("insets the home hero and main menu without widening the whole content track", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session\s*\{[^}]*width:\s*calc\(100%\s*-\s*4px\)/s
    );
    expect(block).not.toMatch(/calc\(100%\s*\+\s*4px\)/);
    expect(block).not.toMatch(/margin-left:\s*-2px/);

    const shell = read("src/styles/layout/app-shell.css");
    expect(shell).toMatch(/body\.app-home-route #main-menu\.menu-container\s*\{[^}]*padding-inline:\s*8px/s);

    const contentWrap = read("src/styles/layout/content-wrap.css");
    expect(contentWrap).not.toMatch(/body\.app-home-route \.content-wrap/);
  });

  it("gives the session name column a little left inset under the logo", () => {
    const hero = read("src/styles/components/hero.css");
    const block = appHomeHeroBlock(hero);

    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-logo\s*\{[^}]*padding-left:\s*2px/s
    );
    expect(block).toMatch(
      /body\.app-home-route \.hero\.hero-with-session \.hero-session-meta\s*\{[^}]*padding-inline:\s*0/s
    );
  });
});

describe("app-home mobile footer wordmark", () => {
  it("bumps the centered MEDİSA mark slightly without touching version layout tokens", () => {
    const footer = read("src/styles/components/footer.css");
    expect(footer).toMatch(
      /body\.app-home-route #app-footer \.brand img\s*\{[^}]*max-height:\s*calc\(14\.27px \+ 1\.5pt\)/s
    );
  });
});
