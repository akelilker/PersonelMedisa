import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const preflightSource = readFileSync(resolve(root, "api/src/Services/DonemKapanisPreflightService.php"), "utf8");
const contextSource = readFileSync(resolve(root, "api/src/Services/BildirimDonemContextService.php"), "utf8");
const auditSource = readFileSync(resolve(root, "api/src/Services/DonemKapanisAuditService.php"), "utf8");
const itemsSource = readFileSync(resolve(root, "api/src/Services/DonemKapanisPreflightItemsService.php"), "utf8");
const puantajController = readFileSync(resolve(root, "api/src/Controllers/PuantajController.php"), "utf8");
const routerSource = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
const rolePermissionsSource = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");

function methodBlock(source: string, method: string, nextMethod: string) {
  const start = source.indexOf(`public static function ${method}(`);
  const end = source.indexOf(`public static function ${nextMethod}(`, start + 1);
  expect(start).toBeGreaterThanOrEqual(0);
  expect(end).toBeGreaterThan(start);
  return source.slice(start, end);
}

describe("S76 DonemKapanisPreflight source contract", () => {
  it("defines canonical severity constants and schema version", () => {
    expect(preflightSource).toContain("public const SCHEMA_VERSION = 'S76_PERIOD_CLOSE_PREFLIGHT_V1'");
    expect(preflightSource).toContain("public const SEVERITY_BLOCKER = 'BLOCKER'");
    expect(preflightSource).toContain("public const SEVERITY_WARNING = 'WARNING'");
    expect(preflightSource).toContain("public const SEVERITY_INFO = 'INFO'");
  });

  it("composes notification context from BildirimDonemContextService", () => {
    expect(contextSource).toContain("listAmirsWithBildirimActivity");
    expect(contextSource).toContain("buildMonthContext");
    expect(contextSource).toContain("fetchAylikOnay");
    expect(contextSource).toContain("fetchGyOnay");
    expect(contextSource).toContain("countEligibleGySources");
    expect(preflightSource).toContain("BildirimDonemContextService::");
    expect(preflightSource).not.toContain("HaftalikBildirimMutabakatlariController");
  });

  it("implements required blocker and warning codes", () => {
    for (const code of [
      "NOTIF_DRAFT_OR_CORRECTION",
      "NOTIF_WEEKLY_INCOMPLETE",
      "NOTIF_MONTHLY_INCOMPLETE",
      "NOTIF_GY_INCOMPLETE",
      "CANDIDATE_GENERATION_MISSING",
      "CANDIDATE_HAZIR_PENDING",
      "CANDIDATE_INCELEME_PENDING",
      "PUANTAJ_CONTROL_PENDING",
      "FINANCE_SALARY_MISSING",
      "PUANTAJ_DAY_ROW_MISSING",
      "FINANCE_OPEN_AFTER_SEAL_RISK",
      "LEGACY_AYLIK_OZET_OPEN",
      "NO_NOTIFICATION_ACTIVITY"
    ]) {
      expect(preflightSource).toContain(code);
    }
  });

  it("computes deterministic preflight hash from canonical payload", () => {
    expect(preflightSource).toContain("'preflight_hash' => \$preflightHash");
    expect(preflightSource).toContain("hash('sha256'");
    expect(preflightSource).toContain("canonicalize(");
    expect(preflightSource).toContain("'kapanabilir_mi' => \$kapanabilirMi");
    expect(preflightSource).toContain("\$muhurState !== 'MUHURLENDI' && \$blockerCount === 0");
  });

  it("exposes read-only preflight and paginated item list owners", () => {
    expect(preflightSource).toMatch(/public static function evaluate\(/);
    expect(itemsSource).toMatch(/public static function listItems\(/);
    expect(itemsSource).toMatch(/public static function exportRows\(/);
    expect(itemsSource).toContain("DonemKapanisPreflightService::evaluate");
  });

  it("records blocked and success close audits with request hash idempotency", () => {
    expect(auditSource).toContain("ACTION_CLOSE_BLOCKED");
    expect(auditSource).toContain("ACTION_CLOSE_SUCCESS");
    expect(auditSource).toContain("computeRequestHash");
    expect(auditSource).toContain("findByIdempotency");
    expect(auditSource).toContain("donem_kapanis_auditleri");
  });

  it("acquires period lock before locked preflight inside muhurleAylik", () => {
    const sealStart = puantajController.indexOf("public static function muhurleAylik(");
    const sealEnd = puantajController.indexOf("private static function", sealStart);
    expect(sealStart).toBeGreaterThanOrEqual(0);
    expect(sealEnd).toBeGreaterThan(sealStart);
    const seal = puantajController.slice(sealStart, sealEnd);
    expect(seal.indexOf("PuantajDonemKilidiService::acquire")).toBeGreaterThan(seal.indexOf("\$pdo->beginTransaction()"));
    expect(seal).toContain("DonemKapanisPreflightService::evaluate");
    expect(seal).toContain("DonemKapanisAuditService::recordBlocked");
    expect(seal).toContain("DonemKapanisAuditService::recordSuccess");
    expect(seal).toContain("periodCloseBlocked");
  });

  it("registers preflight routes and permissions", () => {
    expect(routerSource).toContain("/puantaj/donem-kapanis-preflight");
    expect(routerSource).toContain("/puantaj/donem-kapanis-preflight/items");
    expect(routerSource).toContain("/puantaj/donem-kapanis-preflight/export.csv");
    expect(rolePermissionsSource).toContain("puantaj.donem_kapanis.view");
    expect(rolePermissionsSource).toContain("puantaj.donem_kapanis.export");
  });
});
