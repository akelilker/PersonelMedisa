import { createHash } from "node:crypto";
import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { getRolePermissions, hasRolePermission } from "../../src/lib/authorization/role-permissions";
import { ALL_ROLES, ASSIGNABLE_USER_ROLES } from "../../src/types/auth";

const root = process.cwd();

describe("retention policy source contract (053)", () => {
  it("keeps migration tip at 054 and leaves 052/053 present", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("058_qr_puantaj_candidate_decision_ledger.sql");
    expect(migrations).toContain("052_puantaj_tolerans_ve_disiplin.sql");
    expect(migrations).toContain("053_retention_legal_hold_arsiv.sql");

    const sql052 = readFileSync(
      resolve(root, "api/migrations/052_puantaj_tolerans_ve_disiplin.sql"),
      "utf8"
    );
    expect(sql052).toContain("puantaj_olay_karar_auditleri");
    // Content fingerprint — 052 must not be modified by Phase C.
    const hash = createHash("sha256").update(sql052).digest("hex");
    expect(hash.length).toBe(64);
  });

  it("053 has retention tables, new roles, no seed, Medisa policy wording", () => {
    const sql = readFileSync(
      resolve(root, "api/migrations/053_retention_legal_hold_arsiv.sql"),
      "utf8"
    );
    for (const table of [
      "arsiv_manifestleri",
      "legal_holdlar",
      "legal_hold_auditleri",
      "arsiv_erisim_auditleri",
      "retention_imha_talepleri",
      "retention_imha_auditleri"
    ]) {
      expect(sql).toContain(table);
    }
    expect(sql).toContain("IDARI_ISLER");
    expect(sql).toContain("SISTEM_YONETICISI");
    expect(sql).toMatch(/Medisa saklama politik/i);
    expect(sql.toLowerCase()).not.toContain("kanunen");
    expect(sql.toLowerCase()).not.toMatch(/\binsert\s+into\b/);
    expect(sql).not.toMatch(/DELETE\s+FROM\s+personeller/i);
    // Additive schema only — no data seed/backfill statements (header may mention "no seed").
    expect(sql).not.toMatch(/^\s*INSERT\s+/im);
  });

  it("categories and calendar +10 years via PHP pure runner / source", () => {
    const cats = readFileSync(
      resolve(root, "api/src/Services/Retention/RetentionCategories.php"),
      "utf8"
    );
    for (const c of [
      "PERSONEL_OZLUK",
      "PUANTAJ",
      "BORDRO",
      "IZIN",
      "RAPOR",
      "IS_KAZASI",
      "SGK_EKSIK_GUN",
      "FAZLA_CALISMA",
      "SERBEST_ZAMAN",
      "DISIPLIN",
      "OLAY",
      "SAVUNMA",
      "ISE_GIRIS_CIKIS",
      "PERSONEL_BELGE",
      "ONAY_AUDIT"
    ]) {
      expect(cats).toContain(c);
    }
    expect(cats).toContain("Medisa saklama politikası");
    expect(cats).toMatch(/POLICY_NOTE = 'Medisa saklama politikası'/);
    expect(cats).not.toMatch(/POLICY_NOTE\s*=\s*'[^']*kanunen/i);

    const policy = readFileSync(
      resolve(root, "api/src/Services/Retention/RetentionPolicyService.php"),
      "utf8"
    );
    expect(policy).toContain("modify('+' . RetentionCategories::POLICY_RETENTION_YEARS . ' years')");
    expect(policy).toContain("EXECUTION_HANDLER_NOT_IMPLEMENTED");
    expect(policy).toContain("ELIGIBLE_FOR_DESTRUCTION_REQUEST");
    expect(policy).toContain("evaluatePreApprovalEligibility");
    expect(policy).not.toMatch(/DELETE\s+FROM\s+personeller/i);
    expect(policy).toContain("RetentionPeriodTriggerResolver");
    expect(policy).toContain("ISTEN_AYRILMA");
    expect(policy).not.toMatch(/codeMessage[\s\S]*kanunen/i);
    expect(policy).not.toMatch(/\$context\['as_of'\]/);
    expect(policy).not.toMatch(/\$context\['gm_approved'\]|empty\(\$context\['gm_approved'\]\)/);

    const resolver = readFileSync(
      resolve(root, "api/src/Services/Retention/RetentionPeriodTriggerResolver.php"),
      "utf8"
    );
    expect(resolver).toContain("maas_hesaplama_calistirmalari");
    expect(resolver).toContain("KESINLESTI");
    expect(resolver).toContain("haftalik_kapanislar");
    expect(resolver).toContain("parent_category");

    const ctrl = readFileSync(
      resolve(root, "api/src/Controllers/RetentionController.php"),
      "utf8"
    );
    expect(ctrl).not.toMatch(/getQuery\('as_of'/);
    expect(ctrl).not.toMatch(/getQuery\('gm_approved'/);
    expect(ctrl).toContain("evaluatePreApprovalEligibility");

    const apiTs = readFileSync(resolve(root, "src/api/retention.api.ts"), "utf8");
    expect(apiTs).not.toContain("as_of");
    expect(apiTs).not.toContain("gm_approved");

    const sql053 = readFileSync(
      resolve(root, "api/migrations/053_retention_legal_hold_arsiv.sql"),
      "utf8"
    );
    expect(sql053).toContain("trigger_type_snapshot");
    expect(sql053).toContain("source_sha256_snapshot");
    expect(sql053).toContain("canonical_sube_id");
    expect(sql053).toContain("uq_arsiv_manifest_entity_cat_src");
    expect(sql053).toContain("source_version_identity");
  });

  it("multi-lifecycle and scope integrity source contracts", () => {
    const manifest = readFileSync(
      resolve(root, "api/src/Services/Retention/ArchiveManifestService.php"),
      "utf8"
    );
    expect(manifest).toContain("findBySourceIdentity");
    expect(manifest).toContain("findCurrentLifecycleManifest");
    expect(manifest).toContain("ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE");
    expect(manifest).not.toMatch(/UPDATE\s+arsiv_manifestleri\s+SET\s+source_version_identity/i);

    const period = readFileSync(
      resolve(root, "api/src/Services/Retention/RetentionPeriodTriggerResolver.php"),
      "utf8"
    );
    expect(period).toContain("loadCanonicalHaftalik");
    expect(period).not.toMatch(/hafta_baslangic\s*<=\s*:month_end/);
    expect(period).toContain("TRIGGER_TERMINATION_DATE");

    const ctrl = readFileSync(
      resolve(root, "api/src/Controllers/RetentionController.php"),
      "utf8"
    );
    expect(ctrl).toContain("RetentionTargetResolver::validateAndResolve");
    expect(ctrl).toContain("assertPersonelAccess");
    expect(ctrl).toContain("TARGET_MISMATCH");

    const legal = readFileSync(
      resolve(root, "api/src/Services/Retention/LegalHoldService.php"),
      "utf8"
    );
    expect(legal).toContain("LEGAL_HOLD_CATEGORY_INVALID");
    expect(legal).toContain("LEGAL_HOLD_PERSONEL_MISMATCH");
    expect(legal).toContain("LEGAL_HOLD_TARGET_UNSUPPORTED");
    expect(legal).toContain("RetentionScopeResolver::filterRowsBySubeScope");
    expect(legal).not.toContain("personel_id IS NULL OR");

    const destr = readFileSync(
      resolve(root, "api/src/Services/Retention/DestructionWorkflowService.php"),
      "utf8"
    );
    expect(destr).toContain("requiredSnapshotsIncomplete");
    expect(destr).toContain("SNAPSHOT_INCOMPLETE");
    expect(destr).not.toContain("personel_id IS NULL OR");

    const surec = readFileSync(
      resolve(root, "api/src/Controllers/SureclerController.php"),
      "utf8"
    );
    expect(surec).toMatch(/catch\s*\(\s*\\Throwable/);

    const adapter = readFileSync(
      resolve(root, "api/src/Services/Retention/RetentionSourceAdapterService.php"),
      "utf8"
    );
    expect(adapter).toContain("RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED");
    expect(adapter).toContain("personel_belge_dosya_surumleri");
    expect(adapter).toContain("coverageMap");
  });

  it("wires retention roles and permissions parity", () => {
    expect(ALL_ROLES).toContain("SISTEM_YONETICISI");
    expect(ALL_ROLES).not.toContain("IDARI_ISLER");
    expect(ASSIGNABLE_USER_ROLES).toContain("SISTEM_YONETICISI");
    expect(ASSIGNABLE_USER_ROLES).not.toContain("IDARI_ISLER");

    expect(hasRolePermission("GENEL_YONETICI", "legal_hold.manage")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "retention.destruction.approve")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "arsiv.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "legal_hold.manage")).toBe(false);
    expect(hasRolePermission("IDARI_ISLER", "arsiv.download")).toBe(false);
    expect(hasRolePermission("IDARI_ISLER", "legal_hold.manage")).toBe(false);
    expect(hasRolePermission("IDARI_ISLER", "retention.destruction.approve")).toBe(false);
    expect(hasRolePermission("SISTEM_YONETICISI", "arsiv.audit.view")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "legal_hold.manage")).toBe(false);
    expect(hasRolePermission("SISTEM_YONETICISI", "retention.destruction.approve")).toBe(false);
    expect(getRolePermissions("GENEL_YONETICI")).toContain("retention.view");
  });
});
