import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { getRolePermissions } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();

describe("attendance discipline source contract", () => {
  it("includes DISIPLIN in referans surec turleri", () => {
    const referans = readFileSync(resolve(root, "api/src/Controllers/ReferansController.php"), "utf8");
    expect(referans).toContain("'DISIPLIN'");
  });

  it("wires disiplin and puantaj olay karar permissions", () => {
    const permissions = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");
    for (const key of [
      "disiplin.view",
      "disiplin.review",
      "disiplin.defense_manage",
      "disiplin.final_decision",
      "puantaj.olay_karar.view",
      "puantaj.olay_karar.decide"
    ]) {
      expect(permissions).toContain(key);
    }
    expect(getRolePermissions("GENEL_YONETICI")).toContain("disiplin.view");
    expect(getRolePermissions("BOLUM_YONETICISI")).toContain("disiplin.final_decision");
  });

  it("keeps PersonelDisiplinPanel read-only without createSurec", () => {
    const panel = readFileSync(
      resolve(root, "src/features/personeller/components/personel-dosya/PersonelDisiplinPanel.tsx"),
      "utf8"
    );
    expect(panel).not.toContain("createSurec");
  });

  it("uses gec_kalma_effective_dakika in MaasHesaplamaEngine", () => {
    const engine = readFileSync(resolve(root, "api/src/Services/Payroll/MaasHesaplamaEngine.php"), "utf8");
    expect(engine).toContain("gec_kalma_effective_dakika");
  });

  it("exposes evaluateDailyCandidateKinds on DisiplinAdayProjectionService", () => {
    const service = readFileSync(
      resolve(root, "api/src/Services/Attendance/DisiplinAdayProjectionService.php"),
      "utf8"
    );
    expect(service).toContain("public static function evaluateDailyCandidateKinds");
    expect(service).toContain("public static function countMonthlyLateEvents");
    expect(service).toContain("public static function shouldCreateMonthlyCandidate");
  });

  it("has no statutory 3 day or kanuni hardcode in Attendance services", () => {
    const attendanceDir = resolve(root, "api/src/Services/Attendance");
    for (const file of readdirSync(attendanceDir)) {
      if (!file.endsWith(".php")) {
        continue;
      }
      const source = readFileSync(resolve(attendanceDir, file), "utf8");
      expect(source.toLowerCase()).not.toContain("3 gün");
      expect(source.toLowerCase()).not.toContain("kanuni");
    }
  });

  it("wires frontend disiplin endpoints and panel", () => {
    const endpoints = readFileSync(resolve(root, "src/api/endpoints.ts"), "utf8");
    expect(endpoints).toContain("disiplinVakalar");
    expect(endpoints).toContain("puantajOlayKararlari");
    expect(endpoints).toContain("/disiplin-vakalar/generate");

    const detay = readFileSync(resolve(root, "src/features/surecler/pages/SurecDetayPage.tsx"), "utf8");
    expect(detay).toContain("DisiplinVakaPanel");
    expect(detay).toContain('surec_turu === "DISIPLIN"');

    const takip = readFileSync(resolve(root, "src/features/surecler/pages/SurecTakipPage.tsx"), "utf8");
    expect(takip).toContain("DisiplinAdaylariSection");
    expect(takip).toContain('hasPermission("disiplin.view")');
  });
});
