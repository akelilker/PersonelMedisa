import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(path, "utf8");
}

describe("Kayıt > Süreç personel picker UX", () => {
  it("uses a single searchable AppSelect surface (no parallel toolbar search input)", () => {
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).not.toContain("kayit-surec-personel-search-input");
    expect(workspace).not.toContain("surecSearchExpanded");
    expect(workspace).toContain('searchPlaceholder="Ad/Soyad Veya Sicil No. Girin."');
    expect(workspace).toContain("toggleSurecPersonelSearch");
  });

  it("centers personel option labels only in the süreç picker scope", () => {
    const css = read("src/styles/modules/kayit-surec.css");
    expect(css).toMatch(
      /\.surec-personel-combobox \.app-select-option\s*\{[^}]*text-align:\s*center/s
    );
  });
});
