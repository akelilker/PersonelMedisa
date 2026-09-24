import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

function extractCreateColumnLabels(source: string, columnIndex: number): string[] {
  const columnsBlock = source.match(
    /<div className="personel-form-columns">([\s\S]*?)<\/div>\s*\n\s*\{createErrorMessage/s
  );
  if (!columnsBlock) {
    throw new Error("personel-form-columns block not found");
  }
  const columns = columnsBlock[1].split('<div className="personel-form-column">').slice(1);
  const column = columns[columnIndex] ?? "";
  const labels: string[] = [];
  const labelPattern = /label="([^"]+)"/g;
  let match = labelPattern.exec(column);
  while (match) {
    labels.push(match[1]);
    match = labelPattern.exec(column);
  }
  return labels;
}

function extractMirrorColumnLabels(source: string, columnIndex: number): string[] {
  const columnsBlock = source.match(
    /<div className="personel-form-columns">([\s\S]*?)<\/div>\s*\n\s*<\/div>\s*\n\s*<\/div>/s
  );
  if (!columnsBlock) {
    throw new Error("mirror personel-form-columns block not found");
  }
  const columns = columnsBlock[1].split('<div className="personel-form-column">').slice(1);
  const column = columns[columnIndex] ?? "";
  const labels: string[] = [];
  const labelPattern = /label="([^"]+)"/g;
  let match = labelPattern.exec(column);
  while (match) {
    labels.push(match[1]);
    match = labelPattern.exec(column);
  }
  return labels;
}

/** Sticky hero kimlik alanına taşınan kayıt etiketleri — aynada tekrar edilmez. */
const HERO_OWNED_MIRROR_LABELS = new Set([
  "Çalışan Kapsamı",
  "Ad",
  "Soyad",
  "Şube",
  "Departman",
  "Görev / Unvan",
  "Sicil No",
  "Çalışma Durumu"
]);

describe("personel dosya kayit mirror order", () => {
  it("keeps create-form field order for non-hero mirror labels", () => {
    const create = read("src/features/personeller/components/PersonelCreateFields.tsx");
    const mirror = read(
      "src/features/personeller/components/personel-dosya/PersonelDosyaKayitMirrorFields.tsx"
    );

    const createLeft = extractCreateColumnLabels(create, 0).filter(
      (label) => !HERO_OWNED_MIRROR_LABELS.has(label)
    );
    const createRight = extractCreateColumnLabels(create, 1).filter(
      (label) =>
        !HERO_OWNED_MIRROR_LABELS.has(label) && label !== "Ücret Tipi" && label !== "Net Maaş"
    );
    const mirrorLeft = extractMirrorColumnLabels(mirror, 0);
    const mirrorRight = extractMirrorColumnLabels(mirror, 1);

    expect(mirrorLeft.slice(0, createLeft.length)).toEqual(createLeft);
    expect(mirrorRight.slice(0, createRight.length)).toEqual(createRight);

    for (const label of HERO_OWNED_MIRROR_LABELS) {
      expect(mirror).not.toContain(`label="${label}"`);
    }
  });
});
