import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("iOS PWA login keyboard layout contract", () => {
  it("pins login auth surface in standalone mode and stops content-wrap scroll drift", () => {
    const iosPwa = read("src/styles/platform/ios-pwa.css");
    const standaloneBlock =
      iosPwa.match(/@media\s*\(display-mode:\s*standalone\)\s*\{([\s\S]*)\}\s*$/m)?.[1] ?? "";

    expect(standaloneBlock).toMatch(/body\.login-page\s*\{[^}]*position:\s*fixed/s);
    expect(standaloneBlock).toMatch(/body\.login-page\s*\{[^}]*inset:\s*0/s);
    expect(standaloneBlock).toMatch(/body\.login-page #root\s*\{[^}]*height:\s*100%/s);
    expect(standaloneBlock).toMatch(/body\.login-page \.content-wrap\s*\{[^}]*overflow:\s*hidden/s);
  });

  it("resets auth viewport scroll from AppShell when keyboard closes", () => {
    const shell = read("src/app/AppShell.tsx");
    expect(shell).toMatch(/isAuthSurfaceRoute/);
    expect(shell).toMatch(/visualViewport/);
    expect(shell).toMatch(/content\.scrollTop\s*=\s*0/);
    expect(shell).toMatch(/focusout/);
  });

  it("keeps global document scroll lock in reset.css (PR 410 follow-up)", () => {
    const reset = read("src/styles/base/reset.css");
    expect(reset).toMatch(/html,\s*body,\s*#root\s*\{[^}]*height:\s*100%/s);
    expect(reset).toMatch(/html,\s*body\s*\{[^}]*overflow:\s*hidden/s);
  });
});
