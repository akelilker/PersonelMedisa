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

describe("desktop hero session name visibility", () => {
  it("does not vertically clip session ad/soyad under the logo", () => {
    const desktop = desktopHeroBlock(read("src/styles/components/hero.css"));

    expect(desktop).toMatch(/\.hero\.hero-with-session\s*\{[^}]*overflow:\s*visible/s);
    expect(desktop).toMatch(
      /\.hero-with-session \.hero-session-meta\s*\{[^}]*overflow:\s*visible/s
    );
    expect(desktop).toMatch(
      /\.hero-with-session \.hero-session-user\s*\{[^}]*overflow:\s*visible/s
    );
    expect(desktop).not.toMatch(
      /\.hero-with-session \.hero-session-user\s*\{[^}]*transform:\s*translateY\(4px\)/s
    );
  });

  it("tightens session hero inner vertical padding by ~4px vs prior 14px band", () => {
    const desktop = desktopHeroBlock(read("src/styles/components/hero.css"));

    expect(desktop).toMatch(/\.hero\.hero-with-session\s*\{[^}]*padding-top:\s*12px/s);
    expect(desktop).toMatch(/\.hero\.hero-with-session\s*\{[^}]*padding-bottom:\s*12px/s);
    expect(desktop).not.toMatch(/\.hero\.hero-with-session\s*\{[^}]*padding-top:\s*14px/s);
  });
});
