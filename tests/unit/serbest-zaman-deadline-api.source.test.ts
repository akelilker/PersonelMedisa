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
const destroyGateSource = readFileSync(
  resolve(
    process.cwd(),
    "api/src/Services/Retention/PhysicalDestruction/RetentionPhysicalDestroyGate.php"
  ),
  "utf8"
);
const destroyHandlerSource = readFileSync(
  resolve(
    process.cwd(),
    "api/src/Services/Retention/PhysicalDestruction/Handlers/SerbestZamanDestructionHandler.php"
  ),
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

  it("deadlineTakip returns 409 SCHEMA_NOT_READY when !isSchemaReady", () => {
    expect(controllerSource).toMatch(
      /deadlineTakip\([\s\S]*?!SerbestZamanDeadlineService::isSchemaReady\(\$pdo\)/
    );
    expect(controllerSource).toMatch(
      /JsonResponse::error\(\s*409,\s*SerbestZamanDeadlineService::CODE_SCHEMA_NOT_READY/
    );
    expect(controllerSource).not.toMatch(
      /deadlineTakip\([\s\S]{0,900}SerbestZamanDeadlineService::assertSchemaReady/
    );
  });

  it("locks Pack4B destroy readiness gate + pre-empty-scope assert", () => {
    expect(destroyGateSource).toContain("function isSerbestZamanPack4bReady");
    expect(destroyGateSource).toContain("function assertSerbestZamanPack4bReady");
    expect(destroyGateSource).toContain(
      "CODE_SERBEST_ZAMAN_ALLOCATION_SCHEMA_NOT_READY"
    );
    expect(destroyHandlerSource).toMatch(
      /resolveDestroyScope\([\s\S]{0,280}assertSerbestZamanPack4bReady\(\$pdo\)/
    );
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
