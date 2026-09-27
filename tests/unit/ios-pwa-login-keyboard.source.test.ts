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
      iosPwa.match(/@media\s*\(display-mode:\s*standalone\)\s*\{([\s\S]*)\}\s*$/m)?.[1] ?? "";

    expect(standaloneBlock).toMatch(/body\s*\{[^}]*position:\s*fixed/s);
    expect(standaloneBlock).toMatch(/body\s*\{[^}]*inset:\s*0/s);
    expect(standaloneBlock).toMatch(/body #root\s*\{[^}]*height:\s*100%/s);
    expect(standaloneBlock).not.toMatch(/body\.login-page\s*\{[^}]*position:\s*fixed/s);
  });

  it("keeps login auth surface content-wrap from document scroll drift", () => {
    const iosPwa = read("src/styles/platform/ios-pwa.css");
    const standaloneBlock =
      iosPwa.match(/@media\s*\(display-mode:\s*standalone\)\s*\{([\s\S]*)\}\s*$/m)?.[1] ?? "";

    expect(standaloneBlock).toMatch(/body\.login-page \.content-wrap\s*\{[^}]*overflow:\s*hidden/s);
  });

  it("resets shell viewport scroll from AppShell on navigation and keyboard close", () => {
    const shell = read("src/app/AppShell.tsx");
    expect(shell).toMatch(/visualViewport/);
    expect(shell).toMatch(/content\.scrollTop\s*=\s*0/);
    expect(shell).toMatch(/focusout/);
    expect(shell).not.toMatch(/if\s*\(\s*!isAuthSurfaceRoute\s*\)/);
    expect(shell).toMatch(/\},\s*\[pathname\]\s*\)/);
  });

  it("keeps global document scroll lock in reset.css (PR 410 follow-up)", () => {
    const reset = read("src/styles/base/reset.css");
    expect(reset).toMatch(/html,\s*body,\s*#root\s*\{[^}]*height:\s*100%/s);
    expect(reset).toMatch(/html,\s*body\s*\{[^}]*overflow:\s*hidden/s);
  });
});
