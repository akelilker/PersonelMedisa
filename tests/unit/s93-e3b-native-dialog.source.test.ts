import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

const HOOK_PATHS = [
  "src/hooks/useSurecler.ts",
  "src/hooks/useFinans.ts",
  "src/hooks/useBildirimler.ts"
] as const;

const PAGE_PATHS = [
  "src/features/surecler/pages/SurecTakipPage.tsx",
  "src/features/finans/pages/FinansPage.tsx",
  "src/features/bildirimler/pages/BildirimlerPage.tsx"
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
        (expression.name.text === "confirm" || expression.name.text === "prompt")
      ) {
        hits.push(expression.getText(sourceFile));
      } else if (
        ts.isIdentifier(expression) &&
        (expression.text === "confirm" || expression.text === "prompt")
      ) {
        hits.push(expression.getText(sourceFile));
      }
    }

    ts.forEachChild(node, visit);
  }

  visit(sourceFile);
  return hits;
}

describe("S93-E3B native dialog source contract", () => {
  for (const hookPath of HOOK_PATHS) {
    it(`${hookPath} native dialog kullanmaz`, () => {
      expect(findNativeDialogCalls(hookPath)).toEqual([]);
    });
  }

  for (const pagePath of PAGE_PATHS) {
    it(`${pagePath} AppActionDialog kullanır ve native dialog kullanmaz`, () => {
      const source = readFileSync(resolve(process.cwd(), pagePath), "utf8");

      expect(findNativeDialogCalls(pagePath)).toEqual([]);
      expect(source).toContain("AppActionDialog");
    });
  }
});
