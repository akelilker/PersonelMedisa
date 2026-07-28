import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

const OWNER_PATHS = [
  "src/features/revizyon/pages/RevizyonTalebiDetailPage.tsx",
  "src/features/revizyon/pages/RevizyonCorrectionDetailPage.tsx"
] as const;

function findNativeDialogCalls(relativePath: string): string[] {
  const sourceText = readFileSync(resolve(process.cwd(), relativePath), "utf8");
  const sourceFile = ts.createSourceFile(
    relativePath,
    sourceText,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX
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

describe("S93-E3A Revizyon native dialog source contract", () => {
  for (const ownerPath of OWNER_PATHS) {
    it(`${ownerPath} yalnız AppActionDialog kullanır`, () => {
      const source = readFileSync(resolve(process.cwd(), ownerPath), "utf8");

      expect(findNativeDialogCalls(ownerPath)).toEqual([]);
      expect(source).toContain("AppActionDialog");
    });
  }
});
