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
    expect(getRolePermissions("GENEL_YONETICI")).not.toContain("disiplin.final_decision");
    expect(getRolePermissions("GENEL_YONETICI")).not.toContain("puantaj.olay_karar.decide");
    expect(getRolePermissions("GENEL_YONETICI")).toContain("puantaj.olay_karar.view");
    expect(getRolePermissions("BOLUM_YONETICISI")).toContain("disiplin.final_decision");
    expect(getRolePermissions("BOLUM_YONETICISI")).toContain("puantaj.olay_karar.decide");
    expect(getRolePermissions("IK_BORDRO")).not.toContain("disiplin.final_decision");
    expect(getRolePermissions("IK_BORDRO")).not.toContain("puantaj.olay_karar.decide");
  });

  it("keeps migration tip at 052 and audit table in migration", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("052_puantaj_tolerans_ve_disiplin.sql");
    const sql = readFileSync(resolve(root, "api/migrations/052_puantaj_tolerans_ve_disiplin.sql"), "utf8");
    expect(sql).toContain("puantaj_olay_karar_auditleri");
    expect(sql).toContain("puantaj_olay_kararlari");
  });

  it("exposes minimum tolerance UI helpers without early/>35 tolerance", () => {
    const panel = readFileSync(
      resolve(root, "src/features/puantaj/components/PuantajOlayKararPanel.tsx"),
      "utf8"
    );
    expect(panel).toContain("LATE_TOLERANCE_MAX_MINUTE = 35");
    expect(panel).toContain('olayTuru === "ERKEN_CIKIS"');
    expect(panel).toContain('return ["KESINTI_UYGULA", "OFFICIAL_PROCESS_REQUIRED"]');
    expect(panel).toContain("puantaj.olay_karar.decide");

    const page = readFileSync(resolve(root, "src/features/puantaj/pages/GunlukPuantajPage.tsx"), "utf8");
    expect(page).toContain("PuantajOlayKararPanel");
  });

  it("captures attendance decisions in snapshot payload and avoids live calc read", () => {
    const snapshot = readFileSync(resolve(root, "api/src/Services/MaasHesaplamaSnapshotService.php"), "utf8");
    expect(snapshot).toContain("attachAttendanceDecisions");
    expect(snapshot).toContain("ATTENDANCE_DECISION_PENDING");
    expect(snapshot).toContain("olay_kararlari");

    const aday = readFileSync(resolve(root, "api/src/Services/MaasHesaplamaAdayService.php"), "utf8");
    expect(aday).toContain("indexSealedAttendanceKararlar");
    expect(aday).not.toMatch(/\$kararIndex = self::loadAttendanceKararIndex\(/);
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
