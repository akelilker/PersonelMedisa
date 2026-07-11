import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
  BILDIRIM_DETAIL_ALLOWED_ROLES,
  FINANS_ALLOWED_ROLES,
  PERSONEL_DETAIL_ALLOWED_ROLES,
  PUANTAJ_ALLOWED_ROLES,
  RAPORLAR_ALLOWED_ROLES,
  SUREC_DETAIL_ALLOWED_ROLES,
  getRolePermissions,
  hasRolePermission,
  sessionAllowsSubeAccess
} from "../../src/lib/authorization/role-permissions";
import { ALL_ROLES, type AuthSession } from "../../src/types/auth";

const PHP_ROLE_PERMISSIONS_PATH = resolve(
  process.cwd(),
  "api/src/Auth/RolePermissions.php"
);

function extractPhpRolePermissions(role: string): string[] {
  const php = readFileSync(PHP_ROLE_PERMISSIONS_PATH, "utf8");
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

describe("role permissions", () => {
  it("grants management roles full personel and process actions", () => {
    expect(hasRolePermission("GENEL_YONETICI", "personeller.create")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "surecler.cancel")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "bildirimler.update")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "raporlar.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "finans.cancel")).toBe(true);
  });

  it("keeps BIRIM_AMIRI role focused on sube visibility and daily bildirim workflow", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.view.sube")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.view.sube")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.detail.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.update")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.cancel")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "raporlar.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.create")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.update")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.update")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.amir_kontrol")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "finans.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "finans.create")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "finans.update")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "finans.cancel")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "yonetim-paneli.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "aylik-ozet.view")).toBe(false);
  });

  it("grants management roles full finans permissions (S43B matrix)", () => {
    for (const role of ["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE"] as const) {
      expect(hasRolePermission(role, "finans.view")).toBe(true);
      expect(hasRolePermission(role, "finans.create")).toBe(true);
      expect(hasRolePermission(role, "finans.update")).toBe(true);
      expect(hasRolePermission(role, "finans.cancel")).toBe(true);
    }
  });

  it("restricts yonetim read endpoints to frontend matrix (S43B)", () => {
    expect(hasRolePermission("GENEL_YONETICI", "yonetim-paneli.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "aylik-ozet.view")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "yonetim-paneli.view")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "aylik-ozet.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "yonetim-paneli.view")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "aylik-ozet.view")).toBe(false);
  });

  it("resolves allowed role lists for detail routes from permission matrix", () => {
    expect(PERSONEL_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(SUREC_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(BILDIRIM_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(PUANTAJ_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(RAPORLAR_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI", "PATRON"])
    );
    expect(FINANS_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE"])
    );
  });

  it("returns empty permissions for unknown/empty role input", () => {
    expect(getRolePermissions(null)).toEqual([]);
    expect(getRolePermissions(undefined)).toEqual([]);
  });

  it("sessionAllowsSubeAccess allows any sube when allowed list empty", () => {
    const session = {
      token: "t",
      ui_profile: "yonetim",
      active_sube_id: null,
      user: { id: 1, ad_soyad: "A", rol: "GENEL_YONETICI", sube_ids: [] }
    } satisfies AuthSession;
    expect(sessionAllowsSubeAccess(session, 99)).toBe(true);
  });

  it("sessionAllowsSubeAccess restricts to sube_ids when list non-empty", () => {
    const session = {
      token: "t",
      ui_profile: "yonetim",
      active_sube_id: 1,
      user: { id: 1, ad_soyad: "Genel Muh", rol: "MUHASEBE", sube_ids: [1, 2] }
    } satisfies AuthSession;
    expect(sessionAllowsSubeAccess(session, 1)).toBe(true);
    expect(sessionAllowsSubeAccess(session, 3)).toBe(false);
  });

  it("grants GENEL_YONETICI all revizyon permissions", () => {
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.create")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.submit")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.cancel")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.reject")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.view_finance_effect")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.view_audit_history")).toBe(true);
  });

  it("keeps BOLUM_YONETICISI and MUHASEBE without approve/reject", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.view")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.create")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.reject")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.view_finance_effect")).toBe(true);

    expect(hasRolePermission("MUHASEBE", "revizyon.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "revizyon.create")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "revizyon.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "revizyon.reject")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "revizyon.view_finance_effect")).toBe(true);
  });

  it("keeps BIRIM_AMIRI revizyon scope limited without finance effect and approval", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.submit")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.cancel")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.view_audit_history")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.reject")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.view_finance_effect")).toBe(false);
  });

  it("includes PATRON in all roles list (S70B-1)", () => {
    expect(ALL_ROLES).toContain("PATRON");
  });

  it("locks PATRON to patron ack and rapor view only (S70B-1)", () => {
    expect(hasRolePermission("PATRON", "patron_ack.view")).toBe(true);
    expect(hasRolePermission("PATRON", "patron_ack.mark_seen")).toBe(true);
    expect(hasRolePermission("PATRON", "raporlar.view")).toBe(true);
    expect(hasRolePermission("PATRON", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("PATRON", "sirket_parametreleri.manage")).toBe(false);
    expect(hasRolePermission("PATRON", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("PATRON", "aylik_bolum_onayi.approve")).toBe(false);
    expect(hasRolePermission("PATRON", "gunluk_bildirim.create")).toBe(false);
  });

  it("locks BIRIM_AMIRI target gunluk bildirim and haftalik view permissions (S70B-1)", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.update_own_open")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.submit")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "haftalik_mutabakat.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "haftalik_mutabakat.approve")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "aylik_bolum_onayi.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "bordro_kesinlestirme.approve")).toBe(false);
  });

  it("locks BOLUM_YONETICISI haftalik and aylik bolum onay permissions (S70B-1)", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "gunluk_bildirim.create")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "gunluk_bildirim.request_correction")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "haftalik_mutabakat.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "aylik_bolum_onayi.approve")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "bordro_kesinlestirme.approve")).toBe(false);
  });

  it("locks GENEL_YONETICI genel onay and bordro permissions without bolum onay (S70B-1)", () => {
    expect(hasRolePermission("GENEL_YONETICI", "genel_yonetici_onayi.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "sirket_parametreleri.manage")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "bordro_kesinlestirme.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "aylik_bolum_onayi.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "aylik_bolum_onayi.approve")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.mark_seen")).toBe(false);
  });

  it("locks MUHASEBE to bordro preview and read-only parametre view (S70B-1)", () => {
    expect(hasRolePermission("MUHASEBE", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "sirket_parametreleri.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "sirket_parametreleri.manage")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "gunluk_bildirim.create")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "haftalik_mutabakat.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "aylik_bolum_onayi.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "patron_ack.mark_seen")).toBe(false);
  });

  it("keeps TS and PHP role permission matrices in parity (S70B-1)", () => {
    for (const role of ALL_ROLES) {
      const tsPermissions = [...getRolePermissions(role)].sort();
      const phpPermissions = extractPhpRolePermissions(role);
      expect(phpPermissions).toEqual(tsPermissions);
    }
  });
});
