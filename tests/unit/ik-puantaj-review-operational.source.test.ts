import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();

function read(rel: string) {
  return readFileSync(resolve(root, rel), "utf8");
}

describe("IK puantaj review operational close", () => {
  it("IK can view Bugün + correct scoped + view puantaj; no update/amir_kontrol grants", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "bugun_personel_durumu.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "gunluk_bildirim.correct_scoped")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.update")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.amir_kontrol")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.amir_kontrol")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.correct_scoped")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "puantaj.update")).toBe(false);
    expect(hasRolePermission("PERSONEL", "puantaj.view")).toBe(false);
  });

  it("attention_count includes henuz_degerlendirilmedi with gelmedi/gec", () => {
    const service = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    expect(service).toContain("henuz_degerlendirilmedi");
    expect(service).toMatch(
      /attentionCount \+= \(int\) \$branchCounts\['gelmedi'\][\s\S]*henuz_degerlendirilmedi/
    );
    const demo = read("src/api/mock-demo.ts");
    expect(demo).toContain("branch.counts.henuz_degerlendirilmedi");
  });

  it("Puantaj panel exposes Kontrol Gerekenler and routes IK row-edit to Bugün", () => {
    const panel = read("src/features/kayit/components/KayitSurecPersonelPuantajPanel.tsx");
    expect(panel).toContain("kayit-surec-puantaj-open-items");
    expect(panel).toContain("Kontrol Gerekenler");
    expect(panel).toContain("dispatchOpenBugunPersonelDurumu");
    expect(panel).toContain("!canUpdatePuantaj && canViewBugun");
    expect(panel).toContain("bugun_personel_durumu.view");
    expect(panel).toContain("canCorrectScopedGunlukBildirim");
  });

  it("Bugün correction refreshes header attention and puantaj page points IK to Bugün", () => {
    const modal = read("src/features/bildirimler/components/BugunPersonelDurumuModal.tsx");
    expect(modal).toContain("dispatchRefreshBugunPersonelDurumu");
    const page = read("src/features/puantaj/pages/GunlukPuantajPage.tsx");
    expect(page).toContain("puantaj-readonly-ik-hint");
    expect(page).toContain("puantaj-open-bugun-correction");
    expect(page).toContain("dispatchOpenBugunPersonelDurumu");
  });

  it("keeps period lock and amir-kontrol owners without opening them to IK", () => {
    const period = read("api/src/Services/PuantajDonemPeriodService.php");
    expect(period).toContain("PERIOD_LOCKED");
    expect(period).toContain("isWriteLocked");
    const puantajCtrl = read("api/src/Controllers/PuantajController.php");
    expect(puantajCtrl).toContain("puantaj.amir_kontrol");
    expect(puantajCtrl).toContain("assertUpsertPermission");
    const phpPerm = read("api/src/Auth/RolePermissions.php");
    const ikBlock = phpPerm.slice(phpPerm.indexOf("'IK_SORUMLUSU' => ["), phpPerm.indexOf("'SISTEM_YONETICISI' => ["));
    expect(ikBlock).toContain("'puantaj.view'");
    expect(ikBlock).not.toContain("'puantaj.update'");
    expect(ikBlock).not.toContain("'puantaj.amir_kontrol'");
  });

  it("preserves PR258 timing and PR261 exception owners; migration 085 audit owner stays", () => {
    const timing = read("tests/unit/daily-notification-timing-rules.source.test.ts");
    expect(timing.length).toBeGreaterThan(0);
    const leave = read("tests/unit/personnel-leave-report-absence-operational.source.test.ts");
    expect(leave).toContain("surecler.create");
    const audit = read("tests/unit/migration-085-gunluk-bildirim-duzeltme-audit.source.test.ts");
    expect(audit).toContain("085_gunluk_bildirim_duzeltme_auditleri");
    const bugun = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    expect(bugun).toContain("HENUZ_DEGERLENDIRILMEDI");
    expect(bugun).toContain("never auto-GELMEDI");
  });
});
