import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { RAPOR_COLUMN_CONTRACT } from "../../src/features/raporlar/rapor-column-contract";

/**
 * #504 kararı: kullanıcıya internal personel kayıt ID'si hiçbir user-facing
 * alanda gösterilmez. Kullanıcı personeli Ad Soyad + Sicil No ile tanır.
 * Internal ID yalnız teknik backend/API/route/state ilişkilerinde kalır.
 *
 * Bu test user-facing kaynakların internal ID sızdırmadığını ve personel
 * selector'ının teknik `personel_id` kontratını koruduğunu doğrular.
 */

const SRC_ROOT = join(__dirname, "..", "..", "src");

function collectSourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const stat = statSync(full);
    if (stat.isDirectory()) {
      out.push(...collectSourceFiles(full));
      continue;
    }
    if (/\.(ts|tsx)$/.test(entry)) {
      out.push(full);
    }
  }
  return out;
}

const SOURCE_FILES = collectSourceFiles(SRC_ROOT);

function readSource(relativePath: string): string {
  return readFileSync(join(SRC_ROOT, relativePath), "utf8");
}

function filesMatching(pattern: RegExp): string[] {
  return SOURCE_FILES.filter((file) => pattern.test(readFileSync(file, "utf8")));
}

describe("internal personel ID kullanıcı görünürlüğü", () => {
  it("user-facing kaynaklarda 'Personel ID' label'ı kalmaz", () => {
    expect(filesMatching(/["'`]Personel ID/)).toEqual([]);
  });

  it("'Personel #<internal id>' fallback'i kalmaz", () => {
    expect(filesMatching(/Personel #\$\{/)).toEqual([]);
    expect(filesMatching(/Personel #\d/)).toEqual([]);
  });

  it("user-facing rapor/export kolonlarında personel_id bulunmaz", () => {
    for (const columns of Object.values(RAPOR_COLUMN_CONTRACT)) {
      expect(columns.some((column) => column.key === "personel_id")).toBe(false);
      expect(columns.some((column) => column.label === "Personel ID")).toBe(false);
    }
  });

  it("selector value'su teknik internal personel_id'yi taşımaya devam eder", () => {
    const selectorHook = readSource("hooks/usePersonelSelectOptions.ts");
    // Görünen etiket canonical owner'dan gelir; value teknik olarak internal id'dir.
    expect(selectorHook).toContain("buildPersonelSelectLabels");
    expect(selectorHook).toContain("value: String(personel.id)");
  });

  it("selector kullanan akışlar teknik personel_id payload'ını korur", () => {
    expect(readSource("hooks/useFinans.ts")).toContain("personel_id: parsePositiveInt(applied.personelId)");
    expect(readSource("hooks/useSurecler.ts")).toContain("personel_id: parsePositiveInt(applied.personelId)");
    expect(readSource("hooks/usePuantaj.ts")).toContain("personel_id: activeQuery.personelId");
    expect(readSource("lib/finans/finans-create-commit.ts")).toContain(
      "personel_id: parseRequiredPositiveInt(form.personelId"
    );
    expect(readSource("features/puantaj/hooks/useManagerQrAttendance.ts")).toContain(
      "personel_id: personelId ? Number(personelId) : undefined"
    );
  });
});
