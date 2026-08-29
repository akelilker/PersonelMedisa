import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { createPozisyonFormFromPersonel } from "../../src/features/kayit/kayit-surec-constants";
import {
  isFirstGorevAtamasi,
  resolveGoreveBaslamaTarihiDefault
} from "../../src/lib/personel/gorev-baslama-tarihi";
import { personelToEditForm } from "../../src/features/personeller/personel-edit-utils";
import type { Personel } from "../../src/types/personel";

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function makePersonel(overrides: Partial<Personel> = {}): Personel {
  return {
    id: 1,
    tc_kimlik_no: "12345678901",
    ad: "Ayşe",
    soyad: "Yılmaz",
    aktif_durum: "AKTIF",
    sube_id: 1,
    ise_giris_tarihi: "2026-01-01",
    ...overrides
  };
}

describe("göreve başlama tarihi default", () => {
  it("uses ise_giris_tarihi for the first görev/pozisyon assignment", () => {
    const personel = makePersonel({ gorev_id: undefined, pozisyon_id: undefined });
    expect(isFirstGorevAtamasi(personel)).toBe(true);
    expect(resolveGoreveBaslamaTarihiDefault(personel)).toBe("2026-01-01");
    expect(createPozisyonFormFromPersonel(personel).effectiveDate).toBe("2026-01-01");
    expect(personelToEditForm(personel).effectiveDate).toBe("2026-01-01");
  });

  it("uses today for a subsequent görev change", () => {
    const personel = makePersonel({ gorev_id: 4 });
    expect(isFirstGorevAtamasi(personel)).toBe(false);
    expect(resolveGoreveBaslamaTarihiDefault(personel)).toBe(today());
    expect(createPozisyonFormFromPersonel(personel).effectiveDate).toBe(today());
  });

  it("uses today for a subsequent pozisyon change", () => {
    const personel = makePersonel({ pozisyon_id: 7 });
    expect(isFirstGorevAtamasi(personel)).toBe(false);
    expect(resolveGoreveBaslamaTarihiDefault(personel)).toBe(today());
  });

  it("falls back to today when the hire date is unknown", () => {
    const personel = makePersonel({ ise_giris_tarihi: undefined });
    expect(resolveGoreveBaslamaTarihiDefault(personel)).toBe(today());
  });

  it("keeps a manually entered date", () => {
    const personel = makePersonel({ gorev_id: 4 });
    const form = createPozisyonFormFromPersonel(personel);
    form.effectiveDate = "2025-06-15";
    expect(form.effectiveDate).toBe("2025-06-15");
  });

  it("shows the assignment start date wording, not pozisyon validity", () => {
    const workspace = readFileSync(
      resolve("src/features/kayit/components/KayitSurecWorkspace.tsx"),
      "utf8"
    );
    const inlineEdit = readFileSync(
      resolve("src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx"),
      "utf8"
    );
    expect(workspace).toContain('label="Göreve Başlama Tarihi"');
    expect(workspace).not.toContain("Geçerlilik Tarihi");
    expect(inlineEdit).toContain('label="Göreve Başlama Tarihi"');
    expect(inlineEdit).not.toContain("Geçerlilik Tarihi");
  });

  it("keeps the backend effective_date contract unchanged", () => {
    const utils = readFileSync(resolve("src/features/kayit/kayit-surec-utils.ts"), "utf8");
    const pozisyon = readFileSync(resolve("src/features/kayit/kayit-surec-pozisyon.ts"), "utf8");
    expect(utils).toContain("effective_date: form.effectiveDate");
    expect(pozisyon).toContain("baslangic_tarihi: params.form.effectiveDate");
  });
});
