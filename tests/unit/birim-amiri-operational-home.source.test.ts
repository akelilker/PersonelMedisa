import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("BIRIM_AMIRI operational home owners", () => {
  it("home index uses self-service owner for BIRIM_AMIRI instead of MainMenu", () => {
    const routes = read("src/app/routes.tsx");
    expect(routes).toContain('session?.user.rol === "BIRIM_AMIRI"');
    expect(routes).toContain("<BirimAmiriOperationalHomePage />");
    expect(routes).not.toContain("Üst Amire Gönder");
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(home).not.toContain("Üst Amire Gönder");
  });

  it("operational home has no QR CTA and keeps daily notification entry on /bildirimler", () => {
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(home).toContain('to="/bildirimler"');
    expect(home).toContain("Günlük Bildirimi Düzenle");
    expect(home).toContain("Günlük Bildirimi Tamamla");
    expect(home).not.toContain("/self/qr-okut");
    expect(home).not.toContain("self-qr-scan-link");
    expect(home).not.toContain("giris-scan");
    expect(home).toContain("Kendi Bilgilerim");
    expect(home).toContain("Birimim");
    expect(home).toContain("birim-amiri-pazar-mesai-prompt");
    expect(home).toContain("birim-amiri-eksik-giris-warning");
  });

  it("backend unit roster uses OrgScope and does not reuse fetchGunlukRoster fallback", () => {
    const service = read("api/src/Services/Bildirim/BirimAmiriGunlukDurumService.php");
    expect(service).toContain("OrgScope::appendPersonelOrgFilter");
    expect(service).not.toContain("bagli_amir_id");

    const controller = read("api/src/Controllers/BildirimlerController.php");
    const durumFn = controller.indexOf("function birimGunlukDurum");
    const nextFn = controller.indexOf("public static function ", durumFn + 10);
    const durumSlice = controller.slice(durumFn, nextFn === -1 ? undefined : nextFn);
    expect(durumSlice).toContain("BirimAmiriGunlukDurumService::build");
    expect(durumSlice).not.toContain("fetchGunlukRoster");
    expect(controller).toContain("function birimGunlukDurum");
    expect(controller).toContain("OrgScope::assertRequiredAssignment");
    expect(controller).toContain("$rol !== 'BIRIM_AMIRI'");

    const router = read("api/src/Router.php");
    expect(router).toContain("/bildirimler/birim-gunluk-durum");
    expect(router).toContain("birimGunlukDurum");
  });

  it("does not add a new daily completion path; header summary owner stays gunluk tamamlamalari", () => {
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(home).not.toContain("completeGunlukTamamlama");
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    expect(shell).toContain("formatHeaderGunlukTamamlamaCopy");
    expect(shell).toContain("tamamlama-");
  });

  it("PERSONEL self-service page still owns QR scan CTAs", () => {
    const selfHome = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(selfHome).toContain("self-qr-scan-link");
    expect(selfHome).toContain("giris-scan");
  });
});
