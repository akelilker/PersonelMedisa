import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

const OWNER_PATHS = [
  "src/features/yonetim/pages/YonetimPaneliPage.tsx",
  "src/features/yonetim/components/MevzuatParametreleriPanel.tsx",
  "src/features/kayit/components/KayitBelgeKayitlariSection.tsx"
] as const;

const E3D_OUT_OF_SCOPE_PATHS = [
  "src/features/personeller/components/personel-dosya/PersonelUcretGecmisiSection.tsx",
  "src/features/personeller/components/personel-dosya/PersonelBordroKapsamSection.tsx",
  "src/features/raporlar/pages/MaasHesaplamaMerkeziPage.tsx"
] as const;

function findNativeDialogCalls(relativePath: string): string[] {
  const sourceText = readFileSync(resolve(process.cwd(), relativePath), "utf8");
  const sourceFile = ts.createSourceFile(
    relativePath,
    sourceText,
    ts.ScriptTarget.Latest,
    true,
    relativePath.endsWith(".tsx") ? ts.ScriptKind.TSX : ts.ScriptKind.TS
  );
  const hits: string[] = [];

  function visit(node: ts.Node) {
    if (ts.isCallExpression(node)) {
      const expression = node.expression;
      if (
        ts.isPropertyAccessExpression(expression) &&
        ts.isIdentifier(expression.expression) &&
        (expression.expression.text === "window" || expression.expression.text === "globalThis") &&
        (expression.name.text === "confirm" ||
          expression.name.text === "prompt" ||
          expression.name.text === "alert")
      ) {
        hits.push(expression.getText(sourceFile));
      } else if (
        ts.isIdentifier(expression) &&
        (expression.text === "confirm" || expression.text === "prompt" || expression.text === "alert")
      ) {
        hits.push(expression.getText(sourceFile));
      }
    }

    ts.forEachChild(node, visit);
  }

  visit(sourceFile);
  return hits;
}

describe("S93-E3C native dialog source contract", () => {
  for (const ownerPath of OWNER_PATHS) {
    it(`${ownerPath} AppActionDialog kullanır ve native dialog kullanmaz`, () => {
      const source = readFileSync(resolve(process.cwd(), ownerPath), "utf8");

      expect(findNativeDialogCalls(ownerPath)).toEqual([]);
      expect(source).toContain("AppActionDialog");
    });
  }

  it("E3D owner dosyalarına dokunulmadı (native dialog hâlâ mevcut)", () => {
    for (const path of E3D_OUT_OF_SCOPE_PATHS) {
      expect(findNativeDialogCalls(path).length).toBeGreaterThan(0);
    }
  });
});
