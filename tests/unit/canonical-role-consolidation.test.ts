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

  it("preserves BOLUM_YONETICISI business decisions without GY finals", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.olay_karar.decide")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "disiplin.final_decision")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "sgk_karar_paketi.approve")).toBe(false);
  });

  it("GENEL_YONETICI owns final approvals including patron_ack.mark_seen", () => {
    expect(hasRolePermission("GENEL_YONETICI", "sgk_karar_paketi.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "bordro_kesinlestirme.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "legal_hold.manage")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "retention.destruction.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.mark_seen")).toBe(true);
  });

  it("SISTEM_YONETICISI is assignable but never business approver", () => {
    expect(ASSIGNABLE_USER_ROLES).toContain("SISTEM_YONETICISI");
    const denied = [
      "puantaj.olay_karar.decide",
      "disiplin.final_decision",
      "sgk_karar_paketi.approve",
      "bordro_kesinlestirme.approve",
      "genel_yonetici_onayi.approve",
      "legal_hold.manage",
      "retention.destruction.approve",
      "patron_ack.mark_seen",
      "yonetim-paneli.manage"
    ] as const;
    for (const p of denied) {
      expect(hasRolePermission("SISTEM_YONETICISI", p)).toBe(false);
    }
    expect(hasRolePermission("SISTEM_YONETICISI", "arsiv.audit.view")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "retention.view")).toBe(true);
  });

  it("PERSONEL has zero business access", () => {
    expect(ASSIGNABLE_USER_ROLES).toContain("PERSONEL");
    expect(getRolePermissions("PERSONEL")).toEqual([]);
    expect(hasRolePermission("PERSONEL", "personeller.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "puantaj.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "finans.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "yonetim-paneli.view")).toBe(false);
  });

  it("SGK owner split: prepare IK / approve GY; others neither", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "sgk_karar_paketi.approve")).toBe(true);
    for (const role of ["MUHASEBE", "BOLUM_YONETICISI", "BIRIM_AMIRI", "PERSONEL", "SISTEM_YONETICISI"] as const) {
      expect(hasRolePermission(role, "sgk_karar_paketi.prepare")).toBe(false);
      expect(hasRolePermission(role, "sgk_karar_paketi.approve")).toBe(false);
    }
  });

  it("migration 054 is staged-safe and 052/053 remain present", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("052_puantaj_tolerans_ve_disiplin.sql");
    expect(migrations).toContain("053_retention_legal_hold_arsiv.sql");
    expect(migrations).toContain("054_canonical_role_consolidation.sql");
    expect(migrations.at(-1)).toBe("054_canonical_role_consolidation.sql");

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
});
