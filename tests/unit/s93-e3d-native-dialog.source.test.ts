import { readdirSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

const OWNER_PATHS = [
  "src/features/personeller/components/personel-dosya/PersonelUcretGecmisiSection.tsx",
  "src/features/personeller/components/personel-dosya/PersonelBordroKapsamSection.tsx",
  "src/features/raporlar/pages/MaasHesaplamaMerkeziPage.tsx"
] as const;

function listSourceFiles(dir: string): string[] {
  const entries = readdirSync(dir, { withFileTypes: true });
  const files: string[] = [];
  for (const entry of entries) {
    const absolute = join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...listSourceFiles(absolute));
      continue;
    }
    if (entry.isFile() && /\.(ts|tsx)$/.test(entry.name)) {
      files.push(absolute);
    }
  }
  return files;
}

function findNativeDialogCalls(relativeOrAbsolutePath: string): string[] {
  const cwd = resolve(process.cwd());
  const absolutePath = resolve(cwd, relativeOrAbsolutePath);
  const relativePath = absolutePath.slice(cwd.length + 1).replace(/\\/g, "/");
  const sourceText = readFileSync(absolutePath, "utf8");
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
        hits.push(`${relativePath}:${expression.getText(sourceFile)}`);
      } else if (
        ts.isIdentifier(expression) &&
        (expression.text === "confirm" || expression.text === "prompt" || expression.text === "alert")
      ) {
        hits.push(`${relativePath}:${expression.getText(sourceFile)}`);
      }
    }

    ts.forEachChild(node, visit);
  }

  visit(sourceFile);
  return hits;
}

describe("S93-E3D native dialog source contract", () => {
  for (const ownerPath of OWNER_PATHS) {
    it(`${ownerPath} AppActionDialog kullanır ve native dialog kullanmaz`, () => {
      const source = readFileSync(resolve(process.cwd(), ownerPath), "utf8");

      expect(findNativeDialogCalls(ownerPath)).toEqual([]);
      expect(source).toContain("AppActionDialog");
    });
  }

  it("src altında native dialog kalmamıştır", () => {
    const srcRoot = resolve(process.cwd(), "src");
    const hits = listSourceFiles(srcRoot).flatMap((filePath) => findNativeDialogCalls(filePath));
    expect(hits).toEqual([]);
  });
});
