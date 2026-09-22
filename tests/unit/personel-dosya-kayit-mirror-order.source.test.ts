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

describe("personel dosya kayit mirror order", () => {
  it("matches PersonelCreateFields left/right label order (read-only kart)", () => {
    const create = read("src/features/personeller/components/PersonelCreateFields.tsx");
    const mirror = read("src/features/personeller/components/personel-dosya/PersonelDosyaKayitMirrorFields.tsx");

    const createLeft = extractCreateColumnLabels(create, 0);
    const createRight = extractCreateColumnLabels(create, 1);
    const mirrorLeft = extractMirrorColumnLabels(mirror, 0);
    const mirrorRight = extractMirrorColumnLabels(mirror, 1);

    expect(mirrorLeft.slice(0, createLeft.length)).toEqual(createLeft);

    const createRightCore = createRight.filter(
      (label) => label !== "Ücret Tipi" && label !== "Net Maaş"
    );
    expect(mirrorRight.slice(0, createRightCore.length)).toEqual(createRightCore);
  });
});
