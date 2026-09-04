import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";
import {
  BUGUN_STATUS_TO_DURUM,
  countsSatisfyInvariant,
  emptyStatusCounts,
  filterPersonsByStatus,
  formatCompletionGlyph
} from "../../src/lib/bildirim/bugun-personel-durumu";
import type { BugunPersonelDurumuPerson } from "../../src/types/bildirim";

const root = resolve(__dirname, "../..");

function read(pathFromRoot: string) {
  return readFileSync(resolve(root, pathFromRoot), "utf8");
}

describe("bugun personel durumu owners", () => {
  it("wires permission for IK / GENEL / SISTEM and denies BIRIM_AMIRI / MUHASEBE / PERSONEL", () => {
    expect(hasRolePermission("GENEL_YONETICI", "bugun_personel_durumu.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "bugun_personel_durumu.view")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "bugun_personel_durumu.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bugun_personel_durumu.view")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "bugun_personel_durumu.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "bugun_personel_durumu.view")).toBe(false);
  });

  it("keeps PHP/FE permission and route owners", () => {
    const phpPerm = read("api/src/Auth/RolePermissions.php");
    const fePerm = read("src/lib/authorization/role-permissions.ts");
    const router = read("api/src/Router.php");
    const controller = read("api/src/Controllers/BildirimlerController.php");
    const service = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    const shell = read("src/components/shell/ShellHeaderActions.tsx");

    expect(phpPerm).toContain("'bugun_personel_durumu.view'");
    expect(fePerm).toContain('"bugun_personel_durumu.view"');
    expect(router).toContain("/bildirimler/bugun-personel-durumu");
    expect(controller).toContain("bugunPersonelDurumu");
    expect(service).toContain("class BugunPersonelDurumuService");
    expect(service).toContain("WORKDAY_START = '08:30'");
    expect(service).toContain("ON_TIME_DEADLINE = '09:30'");
    expect(service).toContain("HENUZ_DEGERLENDIRILMEDI");
    expect(service).toContain("resolvePersonDurum");
    expect(shell).toContain("bugun-personel-durumu-entry");
    expect(shell).toContain("BugunPersonelDurumuModal");
  });

  it("does not invent QR attendance; evidence gates present before GELDI", () => {
    const service = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    expect(service).not.toContain("qr_attendance");
    expect(service).toContain("gunluk_bildirimler");
    expect(service).toContain("gunluk_bildirim_tamamlamalari");
    expect(service).toContain("gunluk_puantaj");
    expect(service).toContain("No-open-row is NOT GELDI");
  });

  it("filters person list by status key including henuz_degerlendirilmedi", () => {
    const people: BugunPersonelDurumuPerson[] = [
      {
        personel_id: 1,
        ad_soyad: "A",
        durum: "GEC_GELDI",
        durum_label: "Geç Geldi",
        gec_kalma_dakika: 17,
        erken_cikis_dakika: null,
        giris_saati: "08:47",
        cikis_saati: null,
        aciklama: null,
        alt_tur: null,
        detail_line: "08:47 · 17 dk geç",
        group: "ACTUAL"
      },
      {
        personel_id: 2,
        ad_soyad: "B",
        durum: "HENUZ_DEGERLENDIRILMEDI",
        durum_label: "Henüz Değerlendirilmedi",
        gec_kalma_dakika: null,
        erken_cikis_dakika: null,
        giris_saati: null,
        cikis_saati: null,
        aciklama: null,
        alt_tur: null,
        detail_line: "Henüz değerlendirilmedi",
        group: "PENDING"
      }
    ];
    expect(filterPersonsByStatus(people, "gec_geldi")).toHaveLength(1);
    expect(filterPersonsByStatus(people, "henuz_degerlendirilmedi")[0]?.personel_id).toBe(2);
    expect(BUGUN_STATUS_TO_DURUM.izinli).toBe("IZINLI");
    expect(BUGUN_STATUS_TO_DURUM.henuz_degerlendirilmedi).toBe("HENUZ_DEGERLENDIRILMEDI");
    expect(formatCompletionGlyph("SURESI_GECTI")).toBe("⚠");
  });

  it("enforces branch/unit count invariant including henuz_degerlendirilmedi", () => {
    const counts = emptyStatusCounts();
    counts.toplam = 5;
    counts.geldi = 1;
    counts.gec_geldi = 1;
    counts.gelmedi = 1;
    counts.izinli = 1;
    counts.henuz_degerlendirilmedi = 1;
    expect(countsSatisfyInvariant(counts)).toBe(true);
    counts.geldi = 2;
    expect(countsSatisfyInvariant(counts)).toBe(false);
  });

  it("documents correction history schema gap (no old→new owner without migration)", () => {
    const controller = read("api/src/Controllers/BildirimlerController.php");
    const migration = read("api/migrations/005_gunluk_bildirimler.sql");
    expect(controller).toContain("bildirim_turu = :bildirim_turu");
    expect(controller).toContain("correction_reason");
    expect(migration).toContain("correction_reason");
    expect(migration).toContain("correction_requested_by");
    expect(migration).not.toContain("onceki_durum");
    expect(migration).not.toContain("eski_bildirim_turu");
    expect(migration).not.toMatch(/gunluk_bildirim.*history|bildirim_gecmis/i);
  });

  it("keeps PR #251 header summary and PR #254 birim amiri home owners intact", () => {
    const header = read("src/lib/bildirim/header-notification-copy.ts");
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    const routes = read("src/app/routes.tsx");
    const birimService = read("api/src/Services/Bildirim/BirimAmiriGunlukDurumService.php");

    expect(header).toContain("formatHeaderGunlukTamamlamaCopy");
    expect(shell).toContain("useBildirimlerHeaderPreview");
    expect(shell).toContain("tamamlama-");
    expect(home).toContain("BirimAmiriOperationalHomePage");
    expect(routes).toContain('session?.user.rol === "BIRIM_AMIRI"');
    expect(routes).toContain("BirimAmiriOperationalHomePage");
    // PR #254 home + PR #255 evidence-gated parity (shared resolvePersonDurum)
    expect(birimService).toContain("BugunPersonelDurumuService::resolvePersonDurum");
    expect(birimService).toContain("HENUZ_DEGERLENDIRILMEDI");
    expect(birimService).not.toContain("no open daily notification → GELDI");
  });

  it("adds payroll close gate without migration", () => {
    const controller = read("api/src/Controllers/BildirimlerController.php");
    expect(controller).toContain("assertPeriodOpenForDate");
    expect(controller).toContain("PuantajDonemPeriodService::isWriteLocked");
    expect(controller).toContain("PERIOD_LOCKED");
  });
});
