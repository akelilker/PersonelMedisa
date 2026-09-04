import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("header notification density owners (tasit parity)", () => {
  it("clamps notification body to max 2 lines and keeps compact item metrics", () => {
    const css = read("src/styles/components/notifications.css");
    expect(css).toMatch(/\.notif-line1\s*\{[^}]*line-clamp:\s*2/s);
    expect(css).toMatch(/\.notif-line1\s*\{[^}]*-webkit-line-clamp:\s*2/s);
    expect(css).toMatch(/\.notification-item\s*\{[^}]*min-height:\s*52px/s);
    expect(css).toMatch(/\.notification-item\s*\{[^}]*padding:\s*4px\s+10px\s+1px/s);
    expect(css).toMatch(/\.notifications-dropdown\.open\s*\{[^}]*gap:\s*2px/s);
    expect(css).toMatch(/\.notif-line1\s*\{[^}]*font-size:\s*11\.5px/s);
    expect(css).toMatch(/\.notif-line1\s*\{[^}]*line-height:\s*1\.18/s);
    expect(css).toMatch(/\.notif-line2\s*\{[^}]*font-size:\s*10\.5px/s);
    expect(css).toMatch(
      /\.settings-dropdown\.notifications-dropdown\s*\{[^}]*width:\s*min\(360px,\s*calc\(100vw\s*-\s*32px\)\)/s
    );
    expect(css).toMatch(
      /@media\s*\(min-width:\s*769px\)\s*\{[^}]*\.settings-dropdown\.notifications-dropdown\s*\{[^}]*width:\s*min\(320px,\s*calc\(100vw\s*-\s*32px\)\)/s
    );
    expect(css).toMatch(
      /@media\s*\(max-width:\s*640px\)\s*\{[^}]*\.settings-dropdown\.notifications-dropdown\s*\{[^}]*width:\s*min\(82vw,\s*420px\)/s
    );
    expect(css).toMatch(
      /\.settings-dropdown\.notifications-dropdown\s*\{[^}]*max-height:\s*min\(60vh,\s*428px\)/s
    );
  });

  it("keeps summary completion copy owner for header", () => {
    const copy = read("src/lib/bildirim/header-notification-copy.ts");
    expect(copy).toContain("formatHeaderGunlukTamamlamaCopy");
    expect(copy).toContain("Devamsızlık Bildirimini Tamamladı.");
    expect(copy).toContain("Detayları Gör");
    expect(copy).not.toContain("formatHeaderBildirimCopy");
  });
});
