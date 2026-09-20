import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("shell dropdown viewport-safe owners", () => {
  it("keeps notifications owner on 100vw caps (not % of narrow icons-row-right)", () => {
    const css = read("src/styles/components/notifications.css");
    expect(css).toMatch(
      /\.settings-dropdown\.notifications-dropdown\s*\{[^}]*max-width:\s*calc\(100vw\s*-\s*32px\)/s
    );
    expect(css).toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[^}]*\.settings-dropdown\.notifications-dropdown\s*\{[^}]*max-width:\s*calc\(100vw\s*-\s*24px\)/s
    );
  });

  it("keeps settings-menu owner on 100vw caps", () => {
    const css = read("src/styles/components/dropdown.css");
    expect(css).toContain(".settings-dropdown.settings-menu-dropdown");
    expect(css).toContain("max-width: calc(100vw - 32px)");
    expect(css).toContain("max-width: calc(100vw - 24px)");
  });

  it("does not let platform/mobile.css override dropdown widths with % max-width", () => {
    const mobile = read("src/styles/platform/mobile.css");
    expect(mobile).not.toMatch(
      /\.settings-dropdown[^{]*\{[^}]*max-width:\s*calc\(100%\s*-\s*16px\)/s
    );
    expect(mobile).not.toMatch(
      /\.notifications-dropdown[^{]*\{[^}]*max-width:\s*calc\(100%\s*-\s*16px\)/s
    );
    expect(mobile).not.toMatch(/\.settings-dropdown:not\(\.settings-menu-dropdown\)/);
  });

  it("owns yönetim user/branch card grids in yonetim.css (not mobile.css duplicates)", () => {
    const yonetim = read("src/styles/modules/yonetim.css");
    const mobile = read("src/styles/platform/mobile.css");

    expect(yonetim).toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[^}]*\.yonetim-card-grid--users[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/s
    );
    expect(yonetim).toMatch(
      /@media\s*\(max-width:\s*480px\)\s*\{[^}]*\.yonetim-card-grid--users[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s
    );
    expect(mobile).not.toMatch(/\.yonetim-card-grid--users/);
    expect(mobile).not.toMatch(/\.yonetim-card-grid--branches/);
  });

  it("keeps sube selector viewport-safe (no relative wrap + 100vw caps)", () => {
    const icons = read("src/styles/components/icons-row.css");
    expect(icons).toContain(".settings-dropdown.sube-selector-dropdown");
    expect(icons).toContain("max-width: calc(100vw - 32px)");
    expect(icons).toMatch(/\.sube-selector-wrap\s*\{[^}]*display:\s*inline-flex/s);
    expect(icons).not.toMatch(/\.sube-selector-wrap\s*\{[^}]*position:\s*relative/s);
    expect(icons).toMatch(/\.sube-selector-dropdown\s*\{[^}]*right:\s*0/s);
    expect(icons).toMatch(
      /\.icons-row--minimal\s+\.sube-selector-dropdown\s*\{[^}]*margin-inline:\s*auto/s
    );
  });

  it("applies content-wrap blur when sube selector is open (same as notifications)", () => {
    const notifications = read("src/styles/components/notifications.css");
    expect(notifications).toMatch(
      /:has\(\.icons-row\s+\.sube-selector-dropdown\.open\)\s+\.content-wrap::after/s
    );
  });
});
