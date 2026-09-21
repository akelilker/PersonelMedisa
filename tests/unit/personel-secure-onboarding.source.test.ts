import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string): string => readFileSync(resolve(path), "utf8");

describe("PERSONEL first-login onboarding surfaces", () => {
  it("exposes only the management-authenticated account creation route", () => {
    const router = read("api/src/Router.php");
    const endpoints = read("src/api/endpoints.ts");

    expect(router).toContain("hesap-onboarding");
    expect(router).not.toContain("personel-activation");
    expect(router).not.toContain("aktivasyon-yenile");
    expect(router).not.toContain("aktivasyon-meta");
    expect(endpoints).toContain("personelHesapOnboarding");
    expect(endpoints).not.toContain("Aktivasyon");
  });

  it("creates a template-credential account that requires its first password change", () => {
    const service = read("api/src/Services/Auth/PersonelAccountOnboardingService.php");
    const create = service.slice(
      service.indexOf("public static function onboardAndIssue("),
      service.indexOf("public static function rejectGenericPersonelBoundCreate(")
    );

    expect(create).toContain("resolvePersonelCanonicalUsername");
    expect(create).toContain("resolvePersonelInitialPasswordMaterial");
    expect(create).toContain("PasswordHasher::hash($passwordMaterial)");
    expect(create).toMatch(/activation_required';\s*\n\s*\$insertVals \.= ', 0';/);
    expect(create).toMatch(/must_change_password';\s*\n\s*\$insertVals \.= ', 1';/);
    expect(create).not.toContain("invitation");
    expect(create).not.toContain("issueInvitationLocked");
  });

  it("keeps management UI aligned to first-login copy without activation-link actions", () => {
    const panel = read("src/features/yonetim/components/PersonelHesapOnboardingPanel.tsx");
    const detail = read("src/features/personeller/components/personel-dosya/PersonelKartPanelGenelBilgiler.tsx");

    expect(panel).toContain("Hesap ilk girişe hazır");
    expect(panel).toContain("İlk girişte şifresini değiştirmesi gerekir");
    expect(panel).not.toMatch(/aktivasyon|activation|Bağlantıyı Kopyala/i);
    expect(detail).toContain('title="Personel hesabı"');
    expect(detail).toContain("zorunlu ilk giriş şifre değişimi");
  });

  it("removes the public activation application surface", () => {
    const routes = read("src/app/routes.tsx");
    const shell = read("src/app/AppShell.tsx");

    expect(routes).not.toContain("personel-aktivasyon");
    expect(routes).not.toContain("PersonelAktivasyonPage");
    expect(shell).not.toContain("personel-aktivasyon");
  });
});
