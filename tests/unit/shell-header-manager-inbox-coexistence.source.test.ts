import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const shell = readFileSync(resolve(process.cwd(), "src/components/shell/ShellHeaderActions.tsx"), "utf8");

describe("manager header notifications coexistence", () => {
  it("merges personel inbox with management tamamlamalar for non-PERSONEL deciders", () => {
    expect(shell).toContain("usesPersonelInbox");
    expect(shell).toContain("canViewBildirimler && !isPersonelRole");
    expect(shell).toMatch(/if \(usesPersonelInbox\) \{\s*return \[\.\.\.inboxMapped, \.\.\.reminderItems, \.\.\.apiItems\]/);
    expect(shell).toMatch(/if \(isPersonelRole\) \{\s*return inboxMapped;/);
  });
});
