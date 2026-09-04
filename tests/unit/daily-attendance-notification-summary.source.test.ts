import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("daily attendance header summary owners", () => {
  it("header projects tamamlamalar instead of per-row GELMEDI events", () => {
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    expect(shell).toContain("formatHeaderGunlukTamamlamaCopy");
    expect(shell).toContain("/bildirimler/gunluk/");
    expect(shell).toContain("tamamlama-");
    expect(shell).not.toContain("formatHeaderBildirimCopy");
    expect(shell).not.toMatch(/mapBildirimLevel/);
  });

  it("hook loads gunluk tamamlamalari header list", () => {
    const hook = read("src/hooks/useBildirimler.ts");
    expect(hook).toContain("fetchGunlukTamamlamalariHeader");
    expect(hook).toContain("markGunlukTamamlamaOkundu");
  });

  it("routes expose summary detail page before numeric bildirim detail", () => {
    const routes = read("src/app/routes.tsx");
    const gunlukIdx = routes.indexOf('path="bildirimler/gunluk/:submissionId"');
    const detailIdx = routes.indexOf('path="bildirimler/:bildirimId"');
    expect(gunlukIdx).toBeGreaterThan(-1);
    expect(detailIdx).toBeGreaterThan(gunlukIdx);
    expect(routes).toContain("GunlukTamamlamaDetayPage");
  });

  it("backend list/detail/okundu owners exist and create is idempotent", () => {
    const controller = read("api/src/Controllers/BildirimlerController.php");
    expect(controller).toContain("function gunlukTamamlamaList");
    expect(controller).toContain("function gunlukTamamlamaDetail");
    expect(controller).toContain("function gunlukTamamlamaMarkOkundu");
    expect(controller).toContain("Idempotent: same submission identity");
    expect(controller).toContain("kind' => 'gunluk_tamamlama'");
    expect(controller).toContain("izinli_raporlu");

    const router = read("api/src/Router.php");
    expect(router).toContain("/bildirimler/gunluk-tamamlamalari");
    expect(router).toContain("gunlukTamamlamaDetail");
    expect(router).toContain("gunlukTamamlamaMarkOkundu");
  });

  it("migration 084 adds okundu_mi and toplam_personel without backfill", () => {
    const migration = read(
      "api/migrations/084_gunluk_bildirim_tamamlama_header_summary.sql"
    );
    expect(migration).toContain("okundu_mi");
    expect(migration).toContain("toplam_personel");
    expect(migration).toContain("NO DATA WRITES");
    expect(migration.toLowerCase()).not.toContain("insert into");
    expect(migration.toLowerCase()).not.toContain("update gunluk_bildirim");
  });

  it("density owners still clamp to two lines", () => {
    const css = read("src/styles/components/notifications.css");
    expect(css).toMatch(/\.notif-line1\s*\{[^}]*line-clamp:\s*2/s);
    expect(css).toMatch(/\.notification-item\s*\{[^}]*min-height:\s*52px/s);
  });
});
