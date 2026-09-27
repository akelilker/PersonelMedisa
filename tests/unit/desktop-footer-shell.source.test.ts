import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("desktop footer shell contract", () => {
  it("fills #root with a flex column so .app-shell can consume the viewport", () => {
    const reset = read("src/styles/base/reset.css");
    expect(reset).toMatch(/#root\s*\{[^}]*display:\s*flex/s);
    expect(reset).toMatch(/#root\s*\{[^}]*flex-direction:\s*column/s);
    expect(reset).toMatch(/#root\s*\{[^}]*min-height:\s*0/s);
    expect(reset).toMatch(/#root\s*\{[^}]*overflow:\s*hidden/s);
  });

  it("docks #app-footer in the shell flex column on desktop (not viewport-fixed)", () => {
    const footer = read("src/styles/components/footer.css");
    const desktop = footer.match(/@media\s*\(min-width:\s*641px\)\s*\{([\s\S]*?)\n\}/)?.[1] ?? "";
    expect(desktop).toMatch(/#app-footer\s*\{[^}]*position:\s*relative/s);
    expect(desktop).toMatch(/#app-footer\s*\{[^}]*flex:\s*0\s+0\s+auto/s);
    expect(desktop).not.toMatch(/#app-footer\s*\{[^}]*position:\s*fixed/s);
  });

  it("lets content-wrap shrink inside the shell and avoids double footer padding on desktop", () => {
    const content = read("src/styles/layout/content-wrap.css");
    expect(content).toMatch(/\.content-wrap\s*\{[^}]*min-height:\s*0/s);

    const desktop = read("src/styles/platform/desktop.css");
    expect(desktop).toMatch(
      /@media\s*\(min-width:\s*641px\)\s*\{[^}]*\.content-wrap\s*\{[^}]*padding-bottom:\s*calc\(var\(--app-footer-gap\)/s
    );
    expect(desktop).not.toMatch(
      /@media\s*\(min-width:\s*641px\)\s*\{[^}]*\.content-wrap\s*\{[^}]*padding-bottom:[^;]*--app-footer-real-height/s
    );
  });

  it("does not turn body.app-home-route into a flex page shell on desktop", () => {
    const shell = read("src/styles/layout/app-shell.css");
    expect(shell).not.toMatch(/body\.app-home-route\s*\{[^}]*display:\s*flex/s);
  });
});
