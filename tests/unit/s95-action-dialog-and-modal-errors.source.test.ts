import { execSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import ts from "typescript";
import { describe, expect, it } from "vitest";

const CONFIRM_DIALOG_OWNERS = [
  "src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx",
  "src/features/raporlar/pages/DonemKapanisMerkeziPage.tsx"
] as const;

const MODAL_ERROR_OWNERS = [
  "src/features/yonetim/pages/ResmiTatilTakvimiPage.tsx",
  "src/features/personeller/components/personel-dosya/PersonelBelgelerPanel.tsx"
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

describe("S95 confirm dialog and modal error surface contracts", () => {
  for (const ownerPath of CONFIRM_DIALOG_OWNERS) {
    it(`${ownerPath} AppActionDialog kullanır ve native dialog kullanmaz`, () => {
      const source = readFileSync(resolve(process.cwd(), ownerPath), "utf8");
      expect(findNativeDialogCalls(ownerPath)).toEqual([]);
      expect(source).toContain("AppActionDialog");
    });
  }

  it("bordro kesinleştir dialog test id'leri sabittir", () => {
    const source = readFileSync(
      resolve(process.cwd(), "src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx"),
      "utf8"
    );
    expect(source).toContain('testId="bordro-kesinlestir-action-dialog"');
    expect(source).toContain("openKesinlestirDialog");
    expect(source).toContain("confirmKesinlestir");
  });

  it("dönem mühür dialog test id'leri sabittir", () => {
    const source = readFileSync(
      resolve(process.cwd(), "src/features/raporlar/pages/DonemKapanisMerkeziPage.tsx"),
      "utf8"
    );
    expect(source).toContain('testId="donem-kapanis-muhur-action-dialog"');
    expect(source).toContain("openSealDialog");
    expect(source).toContain("confirmSeal");
  });

  it("resmi tatil iptal modalı API hatasını modal içinde gösterir", () => {
    const source = readFileSync(resolve(process.cwd(), MODAL_ERROR_OWNERS[0]), "utf8");
    expect(source).toContain('data-testid="rtt-cancel-error"');
    expect(source).toContain("setCancelError");
    expect(source).not.toMatch(/handleCancelSubmit[\s\S]*setActionError\(apiErrorMessage\(error, "İptal başarısız\."\)\)/);
  });

  it("personel belge modalları API hatasını modal içinde gösterir", () => {
    const source = readFileSync(resolve(process.cwd(), MODAL_ERROR_OWNERS[1]), "utf8");
    expect(source).toContain('data-testid="personel-belge-create-error"');
    expect(source).toContain('data-testid="personel-belge-edit-error"');
    expect(source).toContain('data-testid="personel-belge-replace-error"');
    expect(source).toContain('testId="personel-belge-action-dialog"');
    expect(source).toContain("AppActionDialog");
  });

  it("src altında native dialog kalmamıştır", () => {
    let output = "";
    try {
      output = execSync(
        'rg -n "window\\.(confirm|prompt|alert)|globalThis\\.(confirm|prompt|alert)" src',
        { encoding: "utf8" }
      ).trim();
    } catch (error) {
      const err = error as { status?: number; stdout?: string };
      if (err.status === 1) {
        output = (err.stdout ?? "").trim();
      } else {
        throw error;
      }
    }
    expect(output).toBe("");
  });
});
