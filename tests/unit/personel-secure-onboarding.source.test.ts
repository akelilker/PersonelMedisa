import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("personel secure onboarding frontend contracts", () => {
  it("registers public /personel-aktivasyon route next to /login outside ProtectedRoute", () => {
    const routes = read("src/app/routes.tsx");
    const publicBlock = routes.match(
      /<Route element=\{<AppLayout \/>\}>[\s\S]*?<\/Route>\s*\n\s*<Route path="\/yetkisiz"/
    )?.[0];
    expect(publicBlock).toBeTruthy();
    expect(publicBlock).toContain('path="/login"');
    expect(publicBlock).toContain('path="/personel-aktivasyon"');
    expect(publicBlock).toContain("PersonelAktivasyonPage");
    expect(publicBlock).not.toContain("ProtectedRoute");
  });

  it("wires yonetim and auth endpoints for onboarding/activation", () => {
    const endpoints = read("src/api/endpoints.ts");
    expect(endpoints).toContain('personelHesapOnboarding:');
    expect(endpoints).toContain("/yonetim/personeller/${id}/hesap-onboarding");
    expect(endpoints).toContain("kullaniciAktivasyonYenile:");
    expect(endpoints).toContain("/yonetim/kullanicilar/${id}/aktivasyon-yenile");
    expect(endpoints).toContain("kullaniciAktivasyonMeta:");
    expect(endpoints).toContain("/yonetim/kullanicilar/${id}/aktivasyon-meta");
    expect(endpoints).toContain('personelActivationStatus: "/auth/personel-activation/status"');
    expect(endpoints).toContain('personelActivationComplete: "/auth/personel-activation/complete"');
  });

  it("activation page uses hash token in React state only and clears fragment", () => {
    const page = read("src/features/auth/pages/PersonelAktivasyonPage.tsx");
    const tokenHelper = read("src/features/auth/personel-aktivasyon-token.ts");
    expect(tokenHelper).toContain("extractPersonelActivationTokenFromHash");
    expect(tokenHelper).toContain("clearPersonelActivationLocationHash");
    expect(tokenHelper).toContain("URLSearchParams");
    expect(tokenHelper).toContain("replaceState");
    expect(page).toContain("extractPersonelActivationTokenFromHash");
    expect(page).toContain("clearPersonelActivationLocationHash");
    expect(page).not.toMatch(/localStorage/);
    expect(page).not.toMatch(/sessionStorage/);
    expect(page).not.toMatch(/console\.log/);
  });

  it("uses Yönetici/İK wording and never amir for activation errors", () => {
    const page = read("src/features/auth/pages/PersonelAktivasyonPage.tsx");
    const panel = read("src/features/yonetim/components/PersonelHesapOnboardingPanel.tsx");
    expect(page).toMatch(/Yönetici veya İK/);
    expect(page).not.toMatch(/\bamir\b/i);
    expect(panel).not.toMatch(/\bamir\b/i);
    expect(panel).toContain("Aktivasyon Bekliyor");
    expect(panel).toContain("Personel şifresini aktivasyon bağlantısı üzerinden kendisi belirleyecektir.");
  });

  it("keeps DIS_KAYNAK capability coming-soon message unchanged", () => {
    const fe = read("src/features/self-service/personel-mobile-capability.ts");
    const be = read("api/src/Services/SelfService/PersonelMobileCapabilityService.php");
    expect(fe).toContain(
      "Yapım Aşamasındadır. Onay Bekleyen Kapsamlar Tamamlandığında Kullanıma Açılacaktır."
    );
    expect(be).toContain(
      "Yapım Aşamasındadır. Onay Bekleyen Kapsamlar Tamamlandığında Kullanıma Açılacaktır."
    );
  });

  it("gates personel detail onboarding panel with yonetim-paneli.manage", () => {
    const detay = read("src/features/personeller/pages/PersonelDetayPage.tsx");
    expect(detay).toContain('hasPermission("yonetim-paneli.manage")');
    expect(detay).toContain("canManageAccountOnboarding");
    const genel = read(
      "src/features/personeller/components/personel-dosya/PersonelKartPanelGenelBilgiler.tsx"
    );
    expect(genel).toContain("PersonelHesapOnboardingPanel");
  });
});
