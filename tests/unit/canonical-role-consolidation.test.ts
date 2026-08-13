import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import {
  ALL_ROLES,
  ASSIGNABLE_USER_ROLES,
  TECHNICAL_ROLES,
  type UserRole
} from "../../src/types/auth";
import {
  canonicalizeUserRole,
  SAFE_LEGACY_ROLE_ALIASES,
  UNRESOLVED_LEGACY_ROLES
} from "../../src/lib/authorization/canonicalize-user-role";
import {
  getRolePermissions,
  hasRolePermission
} from "../../src/lib/authorization/role-permissions";

const root = process.cwd();
const PHP_PATH = resolve(root, "api/src/Auth/RolePermissions.php");
const YONETIM_CTRL = resolve(root, "api/src/Controllers/YonetimController.php");
const MIG_054 = resolve(root, "api/migrations/054_canonical_role_consolidation.sql");

const HUMAN_7: UserRole[] = [
  "PERSONEL",
  "MUHASEBE",
  "IK_SORUMLUSU",
  "BIRIM_AMIRI",
  "BOLUM_YONETICISI",
  "GENEL_YONETICI",
  "SISTEM_YONETICISI"
];

function extractPhpRolePermissions(role: string): string[] {
  const php = readFileSync(PHP_PATH, "utf8");
  const marker = `'${role}' => [`;
  const start = php.indexOf(marker);
  if (start < 0) {
    return [];
  }
  let index = start + marker.length;
  let depth = 1;
  const permissions: string[] = [];
  while (index < php.length && depth > 0) {
    const char = php[index];
    if (char === "[") {
      depth += 1;
    } else if (char === "]") {
      depth -= 1;
    } else if (depth === 1 && char === "'") {
      let end = index + 1;
      while (end < php.length && php[end] !== "'") {
        end += 1;
      }
      permissions.push(php.slice(index + 1, end));
      index = end;
    }
    index += 1;
  }
  return permissions.sort();
}

describe("S1 canonical role consolidation", () => {
  it("locks exact 7 human + 1 technical catalog", () => {
    expect([...ASSIGNABLE_USER_ROLES].sort()).toEqual([...HUMAN_7].sort());
    expect(ASSIGNABLE_USER_ROLES).toHaveLength(7);
    expect(TECHNICAL_ROLES).toEqual(["AUTH_SMOKE_READONLY"]);
    expect([...ALL_ROLES].sort()).toEqual([...HUMAN_7, "AUTH_SMOKE_READONLY"].sort());
    for (const legacy of ["PATRON", "IK_BORDRO", "SGK_KARAR_ONAY_YETKILISI", "IDARI_ISLER"]) {
      expect(ALL_ROLES).not.toContain(legacy);
      expect(ASSIGNABLE_USER_ROLES).not.toContain(legacy);
    }
  });

  it("keeps AUTH_SMOKE technical-only (not assignable)", () => {
    expect(ASSIGNABLE_USER_ROLES).not.toContain("AUTH_SMOKE_READONLY");
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "ops.auth_smoke.read")).toBe(true);
    expect(getRolePermissions("AUTH_SMOKE_READONLY")).toEqual(["ops.auth_smoke.read"]);
  });

  it("safe-aliases PATRON and IK_BORDRO only", () => {
    expect(SAFE_LEGACY_ROLE_ALIASES.PATRON).toBe("GENEL_YONETICI");
    expect(SAFE_LEGACY_ROLE_ALIASES.IK_BORDRO).toBe("IK_SORUMLUSU");
    expect(canonicalizeUserRole("PATRON")).toBe("GENEL_YONETICI");
    expect(canonicalizeUserRole("IK_BORDRO")).toBe("IK_SORUMLUSU");
    expect(hasRolePermission("PATRON", "legal_hold.manage")).toBe(true);
    expect(hasRolePermission("IK_BORDRO", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("IK_BORDRO", "sgk_karar_paketi.approve")).toBe(false);
  });

  it("fail-closes unresolved legacy roles", () => {
    expect(UNRESOLVED_LEGACY_ROLES).toEqual(
      expect.arrayContaining(["SGK_KARAR_ONAY_YETKILISI", "IDARI_ISLER"])
    );
    expect(canonicalizeUserRole("SGK_KARAR_ONAY_YETKILISI")).toBeNull();
    expect(canonicalizeUserRole("IDARI_ISLER")).toBeNull();
    expect(getRolePermissions("SGK_KARAR_ONAY_YETKILISI")).toEqual([]);
    expect(getRolePermissions("IDARI_ISLER")).toEqual([]);
    expect(hasRolePermission("SGK_KARAR_ONAY_YETKILISI", "sgk_karar_paketi.approve")).toBe(false);
  });

  it("keeps FE/BE permission parity for all canonical roles", () => {
    for (const role of ALL_ROLES) {
      expect(extractPhpRolePermissions(role)).toEqual([...getRolePermissions(role)].sort());
    }
  });

  it("API validRoles matches assignable humans + AUTH_SMOKE", () => {
    const php = readFileSync(YONETIM_CTRL, "utf8");
    for (const role of HUMAN_7) {
      expect(php).toContain(`'${role}'`);
    }
    expect(php).toContain("'AUTH_SMOKE_READONLY'");
    expect(php).not.toMatch(/\$validRoles\s*=\s*\[[^\]]*PATRON/s);
    expect(php).not.toMatch(/\$validRoles\s*=\s*\[[^\]]*IK_BORDRO/s);
    expect(php).not.toMatch(/\$validRoles\s*=\s*\[[^\]]*SGK_KARAR_ONAY_YETKILISI/s);
    expect(php).not.toMatch(/\$validRoles\s*=\s*\[[^\]]*IDARI_ISLER/s);
  });

  it("narrows MUHASEBE operational writes", () => {
    const denied = [
      "personeller.create",
      "personeller.update",
      "personeller.import.apply",
      "personeller.ucret.manage",
      "surecler.create",
      "surecler.update",
      "bildirimler.create",
      "puantaj.update",
      "puantaj.olay_karar.decide",
      "disiplin.final_decision",
      "sgk_karar_paketi.prepare",
      "sgk_karar_paketi.approve",
      "bordro_kesinlestirme.approve",
      "sirket_parametreleri.manage",
      "yonetim-paneli.manage",
      "legal_hold.manage",
      "retention.destruction.approve",
      "revizyon.approve",
      "finans.create",
      "maas_hesaplama.manage",
      "puantaj.bildirim_etki.generate",
      "puantaj.bildirim_etki.apply"
    ] as const;
    for (const p of denied) {
      expect(hasRolePermission("MUHASEBE", p)).toBe(false);
    }
    expect(hasRolePermission("MUHASEBE", "raporlar.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "finans.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "puantaj.donem_kapanis.export")).toBe(true);
  });

  it("IK_SORUMLUSU prepares SGK but cannot final-approve", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.olay_karar.decide")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "disiplin.final_decision")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "legal_hold.manage")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "personeller.create")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "surecler.create")).toBe(true);
  });

  it("preserves BOLUM_YONETICISI business decisions + explicit SGK approve without GY inheritance", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.olay_karar.decide")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "disiplin.final_decision")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "sgk_karar_paketi.approve")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "sgk_karar_paketi.prepare")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "legal_hold.manage")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "retention.destruction.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "yonetim-paneli.manage")).toBe(false);
  });

  it("GENEL_YONETICI owns final approvals including patron_ack.mark_seen", () => {
    expect(hasRolePermission("GENEL_YONETICI", "sgk_karar_paketi.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "bordro_kesinlestirme.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "legal_hold.manage")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "retention.destruction.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.mark_seen")).toBe(true);
  });

  it("SISTEM_YONETICISI is assignable technical admin, never business approver", () => {
    expect(ASSIGNABLE_USER_ROLES).toContain("SISTEM_YONETICISI");

    const troubleshootingReads = [
      "personeller.view",
      "personeller.ucret.view",
      "surecler.view",
      "bildirimler.view",
      "puantaj.view",
      "puantaj.olay_karar.view",
      "disiplin.view",
      "raporlar.view",
      "finans.view",
      "maas_hesaplama.view",
      "bordro_on_izleme.view",
      "personel_bordro_kapsam.view",
      "revizyon.view",
      "revizyon.view_audit_history",
      "mevzuat_parametreleri.view",
      "sirket_parametreleri.view",
      "resmi_tatil_takvimi.view",
      "isg.view",
      "aylik-ozet.view",
      "arsiv.audit.view",
      "retention.view",
      "retention.destruction.view",
      "yonetim-paneli.view"
    ] as const;
    for (const p of troubleshootingReads) {
      expect(hasRolePermission("SISTEM_YONETICISI", p)).toBe(true);
    }

    expect(hasRolePermission("SISTEM_YONETICISI", "yonetim-paneli.manage")).toBe(true);

    const denied = [
      "puantaj.olay_karar.decide",
      "disiplin.final_decision",
      "aylik_bolum_onayi.approve",
      "aylik_bildirim_onayi.approve",
      "genel_yonetici_onayi.approve",
      "genel_yonetici_bildirim_onayi.approve",
      "bordro_kesinlestirme.approve",
      "sgk_karar_paketi.prepare",
      "sgk_karar_paketi.approve",
      "revizyon.approve",
      "revizyon.reject",
      "legal_hold.manage",
      "retention.destruction.approve",
      "retention.destruction.request",
      "patron_ack.mark_seen",
      "sirket_parametreleri.manage",
      "mevzuat_parametreleri.manage",
      "resmi_tatil_takvimi.manage",
      "personeller.create",
      "personeller.update",
      "personeller.ucret.manage",
      "puantaj.update",
      "finans.create",
      "maas_hesaplama.manage",
      "personel_bordro_kapsam.manage",
      "personel_bordro_kapsam.approve"
    ] as const;
    for (const p of denied) {
      expect(hasRolePermission("SISTEM_YONETICISI", p)).toBe(false);
    }
  });

  it("PERSONEL has self_service read + QR scan only (no business access)", () => {
    expect(ASSIGNABLE_USER_ROLES).toContain("PERSONEL");
    expect(getRolePermissions("PERSONEL")).toEqual([
      "self_service.view",
      "self_service.puantaj.view",
      "self_service.yillik_izin.view",
      "self_service.fazla_calisma.view",
      "self_service.qr.scan",
      "self_service.qr.events.view"
    ]);
    expect(hasRolePermission("PERSONEL", "personeller.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "puantaj.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "finans.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "yonetim-paneli.view")).toBe(false);
  });

  it("SGK owner split: prepare IK; approve GY + BOLUM; deny others", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "sgk_karar_paketi.approve")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "sgk_karar_paketi.approve")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "sgk_karar_paketi.prepare")).toBe(false);
    for (const role of ["MUHASEBE", "BIRIM_AMIRI", "PERSONEL", "SISTEM_YONETICISI"] as const) {
      expect(hasRolePermission(role, "sgk_karar_paketi.prepare")).toBe(false);
      expect(hasRolePermission(role, "sgk_karar_paketi.approve")).toBe(false);
    }
  });

  it("manual canonical role assignment: exact 7 picker; no legacy/smoke; GY+BOLUM selectable", () => {
    expect(ASSIGNABLE_USER_ROLES).toHaveLength(7);
    expect(ASSIGNABLE_USER_ROLES).toEqual(expect.arrayContaining(HUMAN_7));
    expect(ASSIGNABLE_USER_ROLES).toContain("GENEL_YONETICI");
    expect(ASSIGNABLE_USER_ROLES).toContain("BOLUM_YONETICISI");
    expect(ASSIGNABLE_USER_ROLES).not.toContain("AUTH_SMOKE_READONLY");
    expect(ASSIGNABLE_USER_ROLES).not.toContain("SGK_KARAR_ONAY_YETKILISI");
    expect(ASSIGNABLE_USER_ROLES).not.toContain("IDARI_ISLER");
    expect(ASSIGNABLE_USER_ROLES).not.toContain("PATRON");
    expect(ASSIGNABLE_USER_ROLES).not.toContain("IK_BORDRO");

    const panel = readFileSync(resolve(root, "src/features/yonetim/pages/YonetimPaneliPage.tsx"), "utf8");
    expect(panel).toContain("ASSIGNABLE_USER_ROLES");
    expect(panel).toContain("roleOptions");
    expect(panel).not.toContain("SGK_KARAR_ONAY_YETKILISI");
    expect(panel).not.toContain("IDARI_ISLER");
  });

  it("forbids person/username/id specific role mapping in migration and auth owners", () => {
    const sql = readFileSync(MIG_054, "utf8");
    const php = readFileSync(PHP_PATH, "utf8");
    const feCanon = readFileSync(resolve(root, "src/lib/authorization/canonicalize-user-role.ts"), "utf8");

    expect(sql).not.toMatch(/WHERE\s+username\s*=/i);
    expect(sql).not.toMatch(/WHERE\s+id\s*=/i);
    expect(sql).not.toMatch(/ad_soyad/i);
    expect(sql).not.toMatch(/UPDATE users SET rol = .+ WHERE rol = 'SGK_KARAR_ONAY_YETKILISI'/);
    expect(sql).not.toMatch(/UPDATE users SET rol = .+ WHERE rol = 'IDARI_ISLER'/);

    for (const src of [php, feCanon, sql]) {
      expect(src).not.toMatch(/WHERE\s+username\s*=\s*['"]/i);
      expect(src).not.toMatch(/ad_soyad\s*=\s*['"][^'"]+['"]/);
    }
  });

  it("migration 054 is staged-safe and 052/053 remain present; 055 is additive tip", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("052_puantaj_tolerans_ve_disiplin.sql");
    expect(migrations).toContain("053_retention_legal_hold_arsiv.sql");
    expect(migrations).toContain("054_canonical_role_consolidation.sql");
    expect(migrations).toContain("055_yillik_izin_hak_duzeltmeleri.sql");
    expect(migrations.at(-1)).toBe("065_personel_org_structure.sql");

    const sql = readFileSync(MIG_054, "utf8");
    expect(sql).toContain("PERSONEL");
    expect(sql).toContain("IK_SORUMLUSU");
    expect(sql).toContain("UPDATE users SET rol = 'GENEL_YONETICI' WHERE rol = 'PATRON'");
    expect(sql).toContain("UPDATE users SET rol = 'IK_SORUMLUSU' WHERE rol = 'IK_BORDRO'");
    expect(sql).not.toMatch(/UPDATE users SET rol = .+ WHERE rol = 'SGK_KARAR_ONAY_YETKILISI'/);
    expect(sql).not.toMatch(/UPDATE users SET rol = .+ WHERE rol = 'IDARI_ISLER'/);
    expect(sql).toContain("SGK_KARAR_ONAY_YETKILISI");
    expect(sql).toContain("IDARI_ISLER");
  });

  it("S2B yillik_izin_hak_duzeltme.manage is GY+IK only; surecler.create uses RolePermissions", () => {
    expect(hasRolePermission("GENEL_YONETICI", "yillik_izin_hak_duzeltme.manage")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "yillik_izin_hak_duzeltme.manage")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "yillik_izin_hak_duzeltme.manage")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "yillik_izin_hak_duzeltme.manage")).toBe(false);
    expect(hasRolePermission("SISTEM_YONETICISI", "yillik_izin_hak_duzeltme.manage")).toBe(false);

    const surecler = readFileSync(resolve(root, "api/src/Controllers/SureclerController.php"), "utf8");
    expect(surecler).toContain("RolePermissions::assert($user, 'surecler.create')");
    expect(surecler).not.toContain("function assertCreateRole");
    expect(surecler).not.toMatch(/\$allowedRoles\s*=\s*\[['"]GENEL_YONETICI['"]/);
  });
});
