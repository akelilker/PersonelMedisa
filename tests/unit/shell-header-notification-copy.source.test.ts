import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("ShellHeaderActions human-readable notification wiring", () => {
  it("uses completion summary formatter and does not compose Personel:/Tarih: leaks", () => {
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    expect(shell).toContain('from "../../lib/bildirim/header-notification-copy"');
    expect(shell).toContain("formatHeaderGunlukTamamlamaCopy");
    expect(shell).toContain("formatHeaderReminderCopy");
    expect(shell).not.toContain("formatHeaderBildirimCopy");
    expect(shell).not.toContain("Personel:");
    expect(shell).not.toContain("Tarih:");
    expect(shell).not.toContain("formatBildirimTuruLabel");
    expect(shell).not.toContain("headerPersonelMap");
    expect(shell).toContain("markAllNotificationsAsRead");
    expect(shell).not.toContain("mapBildirimLevel");
    expect(shell).toContain("/bildirimler/gunluk/");
  });
});
