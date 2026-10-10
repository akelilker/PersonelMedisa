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

    // Özet kartı kaldırıldı (2026-10-10): kimlik yalnız Genel panelinde (Ad SOYAD + durum).
    expect(workspace).not.toContain("data-testid=\"kayit-surec-personel-context\"");
    expect(workspace).not.toContain("İşlem yapılan personel");
    expect(workspace).toContain("SurecInlineBackButton");
    expect(workspace).toContain("testId=\"kayit-surec-personel-degistir\"");
    expect(workspace).toContain("beginChangeSurecPersonel");
    expect(workspace).toContain("showSurecPersonelPickerSurface");
    expect(workspace).toContain("!selectedSurecPersonel || (surecPersonelPickerOpen && !personelContextLocked)");
    expect(workspace).toContain("disabled={personelContextLocked}");
    expect(workspace).not.toContain("Personeli Değiştir");
    expect(workspace).not.toContain("Personel Seçimi");

    expect(genel).not.toContain("Genel bilgiler");
    expect(genel).toContain("<KayitSurecPersonelNameHeading");
    // "Personeli Düzenle" metin düğmesi yok; kalem ikon (aria-label korunur) ad başlığında.
    expect(genel).not.toContain("universal-btn-aux");
    expect(genel).toContain("onEdit={canEdit && !isEditing ? openEdit : undefined}");
    expect(genel).not.toContain("surec-person-general-title");
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
  it("labels the personel-context back with the target picker surface's canonical title", () => {
    const workspace = readOwner("src/features/kayit/components/KayitSurecWorkspace.tsx");
    const constants = readOwner("src/features/kayit/kayit-surec-constants.ts");

    expect(constants).toContain('export const KAYIT_SUREC_PERSONEL_PICKER_LABEL = "Personel"');
    expect(workspace).toMatch(
      /SurecInlineBackButton[\s\S]*?label=\{KAYIT_SUREC_PERSONEL_PICKER_LABEL\}/
    );
    expect(workspace).not.toContain('label="Personel Seçimi"');
  });

});
