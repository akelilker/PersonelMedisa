import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function readOwner(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

describe("Kayit surec personel context dedup", () => {
  it("keeps a single canonical identity surface after selection", () => {
    const workspace = readOwner("src/features/kayit/components/KayitSurecWorkspace.tsx");
    const genel = readOwner("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx");

    expect(workspace).toContain("data-testid=\"kayit-surec-personel-context\"");
    expect(workspace).toContain("data-testid=\"kayit-surec-personel-degistir\"");
    expect(workspace).toContain("beginChangeSurecPersonel");
    expect(workspace).toContain("showSurecPersonelPickerSurface");
    expect(workspace).toContain("!selectedSurecPersonel || (surecPersonelPickerOpen && !personelContextLocked)");
    expect(workspace).toContain("changeDisabled={personelContextLocked}");
    expect(workspace).toContain("Personeli Değiştir");

    expect(genel).toContain("Genel bilgiler");
    expect(genel).toContain("data-testid=\"kayit-surec-personel-duzenle\"");
    expect(genel).not.toContain("surec-person-general-title");
    expect(genel).not.toMatch(
      /surec-shell-summary-kicker[\s\S]*\[personel\.ad,\s*personel\.soyad\]/
    );
  });

  it("preserves picker engine and mutation lock owners", () => {
    const workspace = readOwner("src/features/kayit/components/KayitSurecWorkspace.tsx");

    expect(workspace).toContain("normalizePersonelSearchText");
    expect(workspace).toContain("filteredSurecPersonelOptions");
    expect(workspace).toContain("selectSurecPersonel");
    expect(workspace).toContain("initialSurecPersonelId");
    expect(workspace).toMatch(/personelContextLocked\s*=\s*[\s\S]*genelMutating/);
    expect(workspace).toMatch(/personelContextLocked\s*=\s*[\s\S]*pozisyonSubmitting/);
    expect(workspace).toMatch(/personelContextLocked\s*=\s*[\s\S]*ucretMutating/);
    expect(workspace).toMatch(/personelContextLocked\s*=\s*[\s\S]*belgeDurumSaving/);
    expect(workspace).toMatch(/personelContextLocked\s*=\s*[\s\S]*belgeFileMutating/);
  });
});
