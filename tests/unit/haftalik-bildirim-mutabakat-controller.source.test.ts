import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const controllerPath = resolve(
  process.cwd(),
  "api/src/Controllers/HaftalikBildirimMutabakatlariController.php"
);
const controllerSource = readFileSync(controllerPath, "utf8");

function extractSummaryAmirBranch(source: string): string {
  const match = source.match(
    /\$amirId = strtoupper\(trim\(\(string\) \(\$user\['rol'\] \?\? ''\)\)\) === 'BIRIM_AMIRI'[\s\S]*?;\n/
  );
  return match?.[0] ?? "";
}

describe("HaftalikBildirimMutabakatlariController source contract", () => {
  it("defines positiveInt helper for query parsing", () => {
    expect(controllerSource).toMatch(/private static function positiveInt\(\$value\)/);
  });

  it("does not call undefined parsePositiveInt in summary owner branch", () => {
    const summaryBranch = extractSummaryAmirBranch(controllerSource);
    expect(summaryBranch).toContain("self::positiveInt($request->getQuery('birim_amiri_user_id'))");
    expect(summaryBranch).not.toContain("parsePositiveInt");
    expect(controllerSource).not.toMatch(/self::parsePositiveInt\(/);
  });

  it("keeps BIRIM_AMIRI automatic owner resolution", () => {
    const summaryBranch = extractSummaryAmirBranch(controllerSource);
    expect(summaryBranch).toContain("? $currentUserId");
  });
});

describe("Haftalik positiveInt helper semantics", () => {
  function positiveInt(value: unknown): number | null {
    const id = Number.parseInt(String(value), 10);
    if (!Number.isFinite(id) || id <= 0) {
      return null;
    }
    return String(id) === String(value).trim() ? id : null;
  }

  it('parses "3" as 3', () => {
    expect(positiveInt("3")).toBe(3);
  });

  it("returns null for empty or null input", () => {
    expect(positiveInt(null)).toBeNull();
    expect(positiveInt("")).toBeNull();
  });

  it("returns null for zero and negative values", () => {
    expect(positiveInt("0")).toBeNull();
    expect(positiveInt("-1")).toBeNull();
  });
});
