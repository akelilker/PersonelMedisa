import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

describe("branch accounting visibility owners", () => {
  it("adds migration 087 as next tip without mutating older migrations", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"));
    expect(migrations).toContain("087_sube_muhasebe_yetkilileri.sql");
    const sql = read("api/migrations/087_sube_muhasebe_yetkilileri.sql");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS sube_muhasebe_yetkilileri");
    expect(sql).toContain("PRIMARY KEY (sube_id, user_id)");
    expect(sql).toMatch(/NO DATA WRITES/i);
    expect(sql).not.toMatch(/INSERT\s+INTO\s+sube_muhasebe_yetkilileri/i);
    expect(migrations).toContain("086_personel_historical_exit_date_correction_auditleri.sql");
  });

  it("keeps OrganizasyonService as atomic branch+ACL save owner", () => {
    const service = read("api/src/Services/Organizasyon/OrganizasyonService.php");
    expect(service).toContain("parseMuhasebeYetkiPlan");
    expect(service).toContain("assertMuhasebeYetkiPlanValid");
    expect(service).toContain("SubeMuhasebeYetkiSchema::replaceForSube");
    expect(service).toContain("muhasebe_kisit_aktif");
    expect(service).toContain("muhasebe_yetkili_user_ids");
    const createTx = service.indexOf("public static function createSube");
    const updateTx = service.indexOf("public static function updateSube");
    expect(createTx).toBeGreaterThan(-1);
    expect(updateTx).toBeGreaterThan(-1);
    const createBlock = service.slice(createTx, updateTx);
    expect(createBlock.indexOf("$pdo->beginTransaction();")).toBeLessThan(
      createBlock.indexOf("SubeMuhasebeYetkiSchema::replaceForSube")
    );
  });

  it("enforces restriction only for MUHASEBE in OrgScope", () => {
    const org = read("api/src/Scope/OrgScope.php");
    expect(org).toContain("assertMuhasebeBranchAccountingVisibility");
    expect(org).toContain("appendMuhasebeBranchAccountingListFilter");
    expect(org).toContain("!== 'MUHASEBE'");
    expect(org).toContain("Bu subenin muhasebe verileri icin yetkiniz yok.");
    expect(org).toContain("sube_muhasebe_yetki_map");
  });

  it("loads ACL map for MUHASEBE in AuthMiddleware", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toContain("SubeMuhasebeYetkiSchema::loadRestrictedSubeUserMap");
    expect(auth).toContain("'sube_muhasebe_yetki_map'");
    expect(auth).toContain("$rol === 'MUHASEBE'");
  });

  it("wires Şube Yönetimi checkbox + multi-select without new design system", () => {
    const page = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    expect(page).toContain("Verileri Sadece İlgili Muhasebe Yetkilileri Görebilsin.");
    expect(page).toContain("yonetim-sube-muhasebe-kisit-checkbox");
    expect(page).toContain("yonetim-sube-muhasebe-yetkili-panel");
    expect(page).toContain("muhasebeKisitAktif");
    expect(page).toContain("muhasebeYetkiliUserIds");
    expect(page).toContain('item.rol === "MUHASEBE" && item.durum === "AKTIF"');
    expect(page).toContain("Muhasebe kısıtı açıkken en az bir muhasebe yetkilisi seçilmelidir.");
  });

  it("does not invent duplicate Karabük/Fabrika branch creation", () => {
    const plan = read("ops/organization-mapping/medisa-only-data-scope-rollout-plan.json");
    const parsed = JSON.parse(plan) as {
      karabuk_factory_model: string;
      duplicate_branch_created: boolean;
      work_location_targets: Array<{ calisma_lokasyonu_id: number; target_sube_id: number }>;
    };
    expect(parsed.duplicate_branch_created).toBe(false);
    expect(parsed.karabuk_factory_model).toContain("Fabrika");
    expect(parsed.work_location_targets.find((row) => row.calisma_lokasyonu_id === 5)?.target_sube_id).toBe(1);
    expect(plan).not.toMatch(/create.*Karabük.*şube/i);
  });
});
