import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("iOS 27 PWA footer chrome (Taşıt PR541 parity)", () => {
  it("bootstraps medisa-ios27-pwa on iOS standalone before paint", () => {
    const indexHtml = read("index.html");
    expect(indexHtml).toMatch(/viewport-fit=cover/);
    expect(indexHtml).toMatch(
      /apple-mobile-web-app-status-bar-style" content="black"/
    );
    expect(indexHtml).toMatch(/classList\.add\("medisa-ios27-pwa"\)/);
    expect(indexHtml).toMatch(/display-mode: standalone/);
  });

  it("uses 100dvh shell + single safe-area owner (not layout-viewport body pin)", () => {
    const iosPwa = read("src/styles/platform/ios-pwa.css");
    expect(iosPwa).toMatch(/html\.medisa-ios27-pwa body\s*\{[^}]*position:\s*relative/s);
    expect(iosPwa).toMatch(/html\.medisa-ios27-pwa \.app-shell\s*\{[^}]*height:\s*100dvh/s);
    expect(iosPwa).toMatch(
      /html\.medisa-ios27-pwa \.app-shell\s*\{[^}]*padding-top:\s*env\(safe-area-inset-top,\s*0px\)/s
    );
    expect(iosPwa).toMatch(
      /html\.medisa-ios27-pwa \.app-shell::before\s*\{[^}]*bottom:\s*calc\(var\(--app-footer-real-height\) \+ env\(safe-area-inset-bottom,\s*0px\)\)/s
    );
    expect(iosPwa).toMatch(
      /html\.medisa-ios27-pwa #app-footer\s*\{[^}]*padding-bottom:\s*env\(safe-area-inset-bottom,\s*0px\)/s
    );
    expect(iosPwa).toMatch(
      /html\.medisa-ios27-pwa body\.login-page \.content-wrap\s*\{[^}]*padding-top:\s*4px/s
    );
    expect(iosPwa).not.toContain("!important");
  });
});
