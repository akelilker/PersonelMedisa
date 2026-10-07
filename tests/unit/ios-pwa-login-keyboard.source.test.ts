import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("iOS PWA shell viewport contract", () => {
  it("pins the app shell to the layout viewport in standalone mode (all routes)", () => {
    const iosPwa = read("src/styles/platform/ios-pwa.css");
    const standaloneBlock =
      iosPwa.match(/@media\s*\(display-mode:\s*standalone\)\s*\{([\s\S]*?)\n\}/m)?.[1] ?? "";

    expect(standaloneBlock).toMatch(/body\s*\{[^}]*position:\s*fixed/s);
    expect(standaloneBlock).toMatch(/body\s*\{[^}]*inset:\s*0/s);
    expect(standaloneBlock).toMatch(/body #root\s*\{[^}]*height:\s*100%/s);
    expect(standaloneBlock).not.toMatch(/body\.login-page\s*\{[^}]*position:\s*fixed/s);
  });

  it("replaces layout-viewport body pin on iOS 27 PWA (html.medisa-ios27-pwa)", () => {
    const iosPwa = read("src/styles/platform/ios-pwa.css");
    expect(iosPwa).toMatch(/html\.medisa-ios27-pwa body\s*\{[^}]*position:\s*relative/s);
    expect(iosPwa).toMatch(/html\.medisa-ios27-pwa body\s*\{[^}]*min-height:\s*100dvh/s);
  });

  it("keeps login auth surface content-wrap from document scroll drift", () => {
    const iosPwa = read("src/styles/platform/ios-pwa.css");
    const standaloneBlock =
      iosPwa.match(/@media\s*\(display-mode:\s*standalone\)\s*\{([\s\S]*)\}\s*$/m)?.[1] ?? "";

    expect(standaloneBlock).toMatch(/body\.login-page \.content-wrap\s*\{[^}]*overflow:\s*hidden/s);
  });

  it("resets document scroll on navigation; content-wrap only on auth + keyboard dismiss", () => {
    const shell = read("src/app/AppShell.tsx");
    expect(shell).toMatch(/resetDocumentScroll/);
    expect(shell).toMatch(/document\.documentElement\.scrollTop\s*=\s*0/);
    expect(shell).toMatch(/resetAuthContentScroll/);
    expect(shell).toMatch(/if\s*\(\s*isAuthSurfaceRoute\s*\)\s*\{[^}]*resetAuthContentScroll/s);
    expect(shell).toMatch(/isKeyboardFieldTarget/);
    expect(shell).toMatch(/keyboardViewportWasShrunk/);
    expect(shell).toMatch(/KEYBOARD_VIEWPORT_SHRINK_PX/);
    expect(shell).toMatch(/visualViewport/);
    expect(shell).toMatch(/focusout/);
    expect(shell).toMatch(/\},\s*\[pathname,\s*isAuthSurfaceRoute\]\s*\)/);
  });

  it("keeps global document scroll lock in reset.css (PR 410 follow-up)", () => {
    const reset = read("src/styles/base/reset.css");
    expect(reset).toMatch(/html,\s*body,\s*#root\s*\{[^}]*height:\s*100%/s);
    expect(reset).toMatch(/html,\s*body\s*\{[^}]*overflow:\s*hidden/s);
  });
});
