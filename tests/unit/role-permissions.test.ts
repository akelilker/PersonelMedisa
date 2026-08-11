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
    expect(hasRolePermission("GENEL_YONETICI", "personeller.import.apply")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "personeller.import.apply")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "personeller.import.apply")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "personeller.import.apply")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.import.apply")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "surecler.cancel")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "bildirimler.update")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "raporlar.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "finans.cancel")).toBe(false);
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

  it("grants finans write to GENEL_YONETICI and BOLUM_YONETICISI; MUHASEBE is read-only", () => {
    for (const role of ["GENEL_YONETICI", "BOLUM_YONETICISI"] as const) {
      expect(hasRolePermission(role, "finans.view")).toBe(true);
      expect(hasRolePermission(role, "finans.create")).toBe(true);
      expect(hasRolePermission(role, "finans.update")).toBe(true);
      expect(hasRolePermission(role, "finans.cancel")).toBe(true);
    }
    expect(hasRolePermission("MUHASEBE", "finans.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "finans.create")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "finans.update")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "finans.cancel")).toBe(false);
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
      expect.arrayContaining([
        "GENEL_YONETICI",
        "BOLUM_YONETICISI",
        "MUHASEBE",
        "BIRIM_AMIRI",
        "IK_SORUMLUSU"
      ])
    );
    expect(SUREC_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining([
        "GENEL_YONETICI",
        "BOLUM_YONETICISI",
        "MUHASEBE",
        "BIRIM_AMIRI",
        "IK_SORUMLUSU"
      ])
    );
    expect(BILDIRIM_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "BIRIM_AMIRI"])
    );
    expect(BILDIRIM_DETAIL_ALLOWED_ROLES).not.toContain("MUHASEBE");
    expect(PUANTAJ_ALLOWED_ROLES).toEqual(
      expect.arrayContaining([
        "GENEL_YONETICI",
        "BOLUM_YONETICISI",
        "MUHASEBE",
        "BIRIM_AMIRI",
        "IK_SORUMLUSU"
      ])
    );
    expect(RAPORLAR_ALLOWED_ROLES).toEqual(
      expect.arrayContaining([
        "GENEL_YONETICI",
        "BOLUM_YONETICISI",
        "MUHASEBE",
        "BIRIM_AMIRI",
        "IK_SORUMLUSU",
        "SISTEM_YONETICISI"
      ])
    );
    expect(RAPORLAR_ALLOWED_ROLES).not.toContain("PATRON");
    expect(FINANS_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "SISTEM_YONETICISI"])
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

  it("keeps BOLUM_YONETICISI without approve/reject; MUHASEBE is revizyon read-only", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.view")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.create")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.reject")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.view_finance_effect")).toBe(true);

    expect(hasRolePermission("MUHASEBE", "revizyon.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "revizyon.create")).toBe(false);
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

  it("keeps PERSONEL with zero business permissions", () => {
    expect(ALL_ROLES).toContain("PERSONEL");
    expect(getRolePermissions("PERSONEL")).toEqual([]);
    expect(hasRolePermission("PERSONEL", "raporlar.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "patron_ack.view")).toBe(false);
  });

  it("safe-aliases PATRON to GENEL_YONETICI permissions", () => {
    expect(hasRolePermission("PATRON", "patron_ack.view")).toBe(true);
    expect(hasRolePermission("PATRON", "patron_ack.mark_seen")).toBe(true);
    expect(hasRolePermission("PATRON", "raporlar.view")).toBe(true);
    expect(hasRolePermission("PATRON", "bordro_kesinlestirme.approve")).toBe(true);
    expect(hasRolePermission("PATRON", "sirket_parametreleri.manage")).toBe(true);
    expect(ALL_ROLES).not.toContain("PATRON");
  });

  it("locks BIRIM_AMIRI target gunluk bildirim and haftalik view permissions (S70B-1)", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.update_own_open")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "gunluk_bildirim.submit")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "haftalik_mutabakat.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "haftalik_mutabakat.approve")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "aylik_bildirim_onayi.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "aylik_bildirim_onayi.approve")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "aylik_bolum_onayi.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "genel_yonetici_bildirim_onayi.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "genel_yonetici_bildirim_onayi.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "bordro_kesinlestirme.approve")).toBe(false);
  });

  it("locks BOLUM_YONETICISI haftalik and aylik bolum onay permissions (S70B-1)", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "gunluk_bildirim.create")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "gunluk_bildirim.request_correction")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "haftalik_mutabakat.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "aylik_bildirim_onayi.view")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "aylik_bildirim_onayi.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "aylik_bolum_onayi.approve")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "genel_yonetici_bildirim_onayi.view")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "genel_yonetici_bildirim_onayi.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "bordro_kesinlestirme.approve")).toBe(false);
  });

  it("locks GENEL_YONETICI genel onay and bordro permissions without bolum onay (S70B-1)", () => {
    expect(hasRolePermission("GENEL_YONETICI", "genel_yonetici_onayi.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "genel_yonetici_bildirim_onayi.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "genel_yonetici_bildirim_onayi.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "sirket_parametreleri.manage")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "bordro_kesinlestirme.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "aylik_bolum_onayi.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "aylik_bildirim_onayi.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "aylik_bildirim_onayi.approve")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "aylik_bolum_onayi.approve")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "patron_ack.mark_seen")).toBe(true);
  });

  it("locks MUHASEBE to read-only mali/bordro view without operational writes", () => {
    expect(hasRolePermission("MUHASEBE", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "sirket_parametreleri.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "sirket_parametreleri.manage")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "gunluk_bildirim.create")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "haftalik_mutabakat.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "aylik_bildirim_onayi.view")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "aylik_bildirim_onayi.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "aylik_bolum_onayi.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "genel_yonetici_onayi.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "genel_yonetici_bildirim_onayi.view")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "genel_yonetici_bildirim_onayi.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "patron_ack.mark_seen")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.generate")).toBe(false);
  });

  it("locks S74-B puantaj bildirim etki adaylari permission matrix", () => {
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.bildirim_etki.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.bildirim_etki.generate")).toBe(false);

    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.bildirim_etki.view")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.bildirim_etki.generate")).toBe(false);

    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.bildirim_etki.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.bildirim_etki.generate")).toBe(true);

    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.view")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.generate")).toBe(false);

    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.bildirim_etki.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.bildirim_etki.generate")).toBe(false);

    expect(hasRolePermission("PERSONEL", "puantaj.bildirim_etki.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "puantaj.bildirim_etki.generate")).toBe(false);
  });

  it("locks S74-C1 puantaj bildirim etki karar permission matrix", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.bildirim_etki.apply")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.bildirim_etki.dismiss")).toBe(true);

    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.apply")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.dismiss")).toBe(false);

    expect(hasRolePermission("GENEL_YONETICI", "puantaj.bildirim_etki.apply")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.bildirim_etki.dismiss")).toBe(false);

    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.bildirim_etki.apply")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.bildirim_etki.dismiss")).toBe(false);

    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.bildirim_etki.apply")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.bildirim_etki.dismiss")).toBe(false);

    expect(hasRolePermission("PERSONEL", "puantaj.bildirim_etki.apply")).toBe(false);
    expect(hasRolePermission("PERSONEL", "puantaj.bildirim_etki.dismiss")).toBe(false);
  });

  it("locks AUTH_SMOKE_READONLY to single ops.auth_smoke.read permission (S103)", () => {
    expect(getRolePermissions("AUTH_SMOKE_READONLY")).toEqual(["ops.auth_smoke.read"]);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "ops.auth_smoke.read")).toBe(true);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "personeller.view")).toBe(false);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "personeller.view.sube")).toBe(false);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "personeller.detail.view")).toBe(false);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "personeller.create")).toBe(false);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "personeller.update")).toBe(false);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "yonetim-paneli.manage")).toBe(false);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "resmi_tatil_takvimi.manage")).toBe(false);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "bordro_kesinlestirme.approve")).toBe(false);
    expect(ALL_ROLES).toContain("AUTH_SMOKE_READONLY");
  });

  it("locks S98 dual-control prepare/approve permissions", () => {
    expect(hasRolePermission("GENEL_YONETICI", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "sgk_karar_paketi.approve")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "sgk_karar_paketi.approve")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "sgk_karar_paketi.prepare")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "sirket_parametreleri.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "sirket_parametreleri.manage")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("IK_BORDRO", "sgk_karar_paketi.prepare")).toBe(true);
    expect(hasRolePermission("IK_BORDRO", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("SGK_KARAR_ONAY_YETKILISI", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("SGK_KARAR_ONAY_YETKILISI", "sgk_karar_paketi.prepare")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "sgk_karar_paketi.prepare")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("SISTEM_YONETICISI", "sgk_karar_paketi.approve")).toBe(false);
    expect(hasRolePermission("PERSONEL", "sgk_karar_paketi.approve")).toBe(false);
    expect(ALL_ROLES).toContain("IK_SORUMLUSU");
    expect(ALL_ROLES).not.toContain("IK_BORDRO");
    expect(ALL_ROLES).not.toContain("SGK_KARAR_ONAY_YETKILISI");
  });

  it("keeps TS and PHP role permission matrices in parity (S70B-1)", () => {
    for (const role of ALL_ROLES) {
      const tsPermissions = [...getRolePermissions(role)].sort();
      const phpPermissions = extractPhpRolePermissions(role);
      expect(phpPermissions).toEqual(tsPermissions);
    }
  });
});
