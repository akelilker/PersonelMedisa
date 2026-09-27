import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { getRaporColumns } from "../../src/features/raporlar/rapor-column-contract";

const root = resolve(process.cwd());

function read(path: string): string {
  return readFileSync(resolve(root, path), "utf8");
}

describe("puantaj raporu xlsx and print", () => {
  it("exports xlsx from the puantaj report owner with the UI column contract", () => {
    const controller = read("api/src/Controllers/RaporlarController.php");
    const router = read("api/src/Router.php");
    const labels = getRaporColumns("puantaj").map((column) => column.label);

    expect(router).toContain("'/raporlar/puantaj/export.xlsx'");
    expect(router).toContain("RaporlarController::exportPuantajXlsx");
    expect(controller).toContain("function exportPuantajXlsx");
    expect(controller).toContain("new SimpleXlsxWriter()");
    expect(controller).toContain("collectPuantajRows");
    expect(controller).toContain("fetchPuantajLive");
    expect(controller).toContain("fetchPuantajSnapshot");
    expect(controller).toContain("RolePermissions::assert($user, 'raporlar.view')");
    expect(controller).toContain("OrgScope::appendPersonelOrgFilter");
    for (const label of labels) {
      expect(controller).toContain(`'label' => '${label}'`);
    }
    expect(controller).not.toContain("fazla_calisma_dakika");
  });

  it("prints only the puantaj report sheet", () => {
    const page = read("src/features/raporlar/pages/RaporlarPage.tsx");
    const printCss = read("src/styles/print/report-print.css");

    expect(page).toContain("Yazdır / PDF");
    expect(page).not.toContain("PDF İndir");
    expect(page).toContain('data-testid="puantaj-raporu-yazdir"');
    expect(page).toContain("window.print()");
    expect(page).toContain("puantaj-raporu-print");
    expect(page).toContain("<h3>Puantaj Raporu</h3>");
    expect(page).toContain("puantaj-raporu-print-filtre");
    expect(printCss).toContain("size: A4 landscape");
    expect(printCss).toContain("body.puantaj-raporu-print .puantaj-raporu-print-sheet");
    expect(printCss).toContain("#app-footer");
  });
});
