import { describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";

const routerSource = readFileSync(
  resolve(process.cwd(), "api/src/Router.php"),
  "utf8"
);
const endpointsSource = readFileSync(
  resolve(process.cwd(), "src/api/endpoints.ts"),
  "utf8"
);
const apiSource = readFileSync(
  resolve(process.cwd(), "src/api/serbest-zaman.api.ts"),
  "utf8"
);
const mockDemoSource = readFileSync(
  resolve(process.cwd(), "src/api/mock-demo.ts"),
  "utf8"
);
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/SerbestZamanController.php"),
  "utf8"
);

describe("SerbestZaman deadline API source locks (Pack4B)", () => {
  it("registers GET /serbest-zaman/deadline-takip on Router", () => {
    expect(routerSource).toContain("'/serbest-zaman/deadline-takip'");
    expect(routerSource).toMatch(
      /deadline-takip[\s\S]{0,120}SerbestZamanController::deadlineTakip/
    );
    expect(controllerSource).toContain("function deadlineTakip");
    expect(controllerSource).toContain("raporlar.view");
  });

  it("exposes endpoints.serbestZaman.deadlineTakip", () => {
    expect(endpointsSource).toContain('deadlineTakip: "/serbest-zaman/deadline-takip"');
  });

  it("normalizes deadline rows/summary in serbest-zaman.api.ts", () => {
    expect(apiSource).toContain("function normalizeDeadlineState");
    expect(apiSource).toContain("function normalizeDeadlineRow");
    expect(apiSource).toContain("function normalizeDeadlineSummary");
    expect(apiSource).toContain("fetchSerbestZamanDeadlineTakip");
    expect(apiSource).toContain("endpoints.serbestZaman.deadlineTakip");
    expect(apiSource).toContain('"YAKLASIYOR"');
    expect(apiSource).toContain('"SURESI_DOLDU"');
    expect(apiSource).toContain('"ALLOCATION_UNRESOLVED"');
    expect(apiSource).toContain("payroll_hard_block");
    expect(apiSource).toContain("WARNING_AND_OPERATIONAL_FOLLOWUP");
  });

  it("mock-demo serves /serbest-zaman/deadline-takip GET", () => {
    expect(mockDemoSource).toContain('pathname === "/serbest-zaman/deadline-takip"');
    expect(mockDemoSource).toMatch(
      /pathname === "\/serbest-zaman\/deadline-takip"\s*&&\s*method === "GET"/
    );
  });
});
