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
      muhasebe_user_subeler_targets: {
        username: string;
        current_sube_ids: number[];
        target_sube_ids: number[];
        KEEP: number[];
        ADD: number[];
        REMOVE: number[];
      };
      production_state_pins: {
        HISTORICAL_PRODUCTION_MIGRATION_TIP?: string;
        HISTORICAL_PRODUCTION_MIGRATION_PENDING?: number;
        PRODUCTION_MIGRATION_TIP?: string;
        PRODUCTION_MIGRATION_PENDING?: number;
      };
      live_readback_after_plan?: {
        PRODUCTION_MIGRATION_TIP: string;
        PRODUCTION_MIGRATION_PENDING: number;
        PRODUCTION_DEPLOY_SHA: string;
      };
      production_mutation: number;
    };
    expect(parsed.duplicate_branch_created).toBe(false);
    expect(parsed.karabuk_factory_model).toContain("Fabrika");
    expect(parsed.work_location_targets.find((row) => row.calisma_lokasyonu_id === 5)?.target_sube_id).toBe(1);
    expect(plan).not.toMatch(/create.*Karabük.*şube/i);
    expect(parsed.production_mutation).toBe(0);
    // Historical PLAN pin preserved; live readback is authoritative current state.
    expect(parsed.production_state_pins.HISTORICAL_PRODUCTION_MIGRATION_TIP).toBe("086");
    expect(parsed.production_state_pins.HISTORICAL_PRODUCTION_MIGRATION_PENDING).toBe(1);
    expect(parsed.live_readback_after_plan?.PRODUCTION_MIGRATION_TIP).toBe("087");
    expect(parsed.live_readback_after_plan?.PRODUCTION_MIGRATION_PENDING).toBe(0);
    expect(parsed.muhasebe_user_subeler_targets.username).toBe("muhasebe");
    expect(parsed.muhasebe_user_subeler_targets.current_sube_ids).toEqual([1, 2]);
    expect(parsed.muhasebe_user_subeler_targets.target_sube_ids).toEqual([1, 2, 4, 5, 6, 12, 13]);
    expect(parsed.muhasebe_user_subeler_targets.KEEP).toEqual([1, 2]);
    expect(parsed.muhasebe_user_subeler_targets.ADD).toEqual([4, 5, 6, 12, 13]);
    expect(parsed.muhasebe_user_subeler_targets.REMOVE).toEqual([]);
  });

  it("pins CURRENT_STATE + registry to code tip 088 with prod tip 087 pending", () => {
    const current = read("CURRENT_STATE.md");
    const registry = read("docs/guncel/110-master-closure-gap-registry.md");
    expect(current).toMatch(/^CODE_MIGRATION_TIP: 088$/m);
    expect(current).toMatch(/^PRODUCTION_MIGRATION_TIP: 087$/m);
    expect(current).toMatch(/^PRODUCTION_MIGRATION_PENDING: 1$/m);
    expect(current).toMatch(
      /^PRODUCTION_DEPLOY_SHA: f5551160cab7d12b2086d7146c7822f4dfdb469d$/m
    );
    expect(current).toContain("USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY");
    expect(current).toContain("BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler");
    expect(current).toContain("USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY");
    expect(current).toContain("PREPARER: sedanurB");
    expect(current).toContain("APPROVER: Sinem Hamaloğlu");
    expect(current).toContain("TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE");
    expect(registry).toContain("TECHNICAL_GAP_LOCAL_FIXABLE");
    expect(registry).not.toContain("BM model ALREADY_SUPPORTED");
    expect(registry).toMatch(/^CODE_MIGRATION_TIP: 088$/m);
    expect(registry).toMatch(/^PRODUCTION_MIGRATION_TIP: 087$/m);
    expect(registry).toContain("| Migration 087 | **APPLIED** |");
    expect(registry).toContain("| Migration 088 | **CODE_ONLY_PENDING** |");
    expect(registry).toContain("pending **088**");
  });
});
