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
    expect(panel).toContain(
      "Hesap oluşturulduğunda personel, şirket kuralına göre belirlenen başlangıç şifresi ile"
    );
  });

  it("onboarding panel: önerilen kullanıcı adı, şifre yok, çakışmada düzenlenebilir", () => {
    const panel = read("src/features/yonetim/components/PersonelHesapOnboardingPanel.tsx");
    expect(panel).toContain("Personel Hesabı Oluştur");
    expect(panel).toContain("buildPersonelUsernameFromNames");
    expect(panel).toContain("PERSONEL_USERNAME_COLLISION");
    expect(panel).toContain("readOnly={!usernameCollision}");
    expect(panel).not.toContain("Kullanıcı adı (sicil)");
    expect(panel).not.toContain("sicilNo");
    expect(panel).not.toMatch(/type=["']password["']/);
    expect(panel).toContain("yalnızca bir kez gösterilir");
    expect(panel).toContain("Bağlantıyı Kopyala");
    expect(panel).toContain("navigator.clipboard.writeText");
    expect(panel).toContain("Yeni Aktivasyon Bağlantısı Oluştur");
    expect(panel).not.toMatch(/localStorage|sessionStorage|indexedDB/i);
  });

  it("canonical yeni hesap create UX'i first-login modelini anlatir, aktivasyon linki beklemez", () => {
    const panel = read("src/features/yonetim/components/PersonelHesapOnboardingPanel.tsx");
    expect(panel).toContain("Onayla ve Hesap Oluştur");
    expect(panel).toContain("Hesap ilk girişe hazır");
    expect(panel).toContain("İlk girişte şifresini değiştirmesi gerekir");
    expect(panel).toContain("Başlangıç şifresi şirket kuralına göre");
    expect(panel).toContain("personel-hesap-onboarding-first-login");

    // Create sonucu bloku aktivasyon URL'i / meta uretmez ve link aksiyonu icermez.
    const createdBlock = panel.slice(
      panel.indexOf('data-testid="personel-hesap-onboarding-first-login"'),
      panel.indexOf('data-testid="personel-hesap-onboarding-issued"')
    );
    expect(createdBlock).not.toContain("activation");
    expect(createdBlock).not.toContain("Bağlantıyı Kopyala");
    expect(createdBlock).not.toContain("handleReissue");

    // Create akisi aktivasyon meta cagrisini tetiklemez.
    const createHandler = panel.slice(
      panel.indexOf("async function handleCreate()"),
      panel.indexOf("async function handleReissue()")
    );
    expect(createHandler).not.toContain("fetchPersonelAktivasyonMeta");
    expect(createHandler).not.toContain("result.activation");
  });

  it("api contract: create yolu activation_url zorunlu tutmaz, reissue legacy kalir", () => {
    const api = read("src/api/yonetim.api.ts");
    const createFn = api.slice(
      api.indexOf("export async function createPersonelHesapOnboarding("),
      api.indexOf("export async function reissuePersonelAktivasyon(")
    );
    expect(createFn).toContain("PersonelHesapFirstLoginResult");
    expect(createFn).not.toContain("activation_url");
    expect(createFn).not.toContain("activation");

    const firstLoginNormalizer = api.slice(
      api.indexOf("function normalizePersonelHesapFirstLoginResult("),
      api.indexOf("Legacy aktivasyon daveti sonucu (reissue yolu)")
    );
    expect(firstLoginNormalizer).not.toContain("activation_url");
    expect(firstLoginNormalizer).not.toContain("record.activation");

    const types = read("src/types/yonetim.ts");
    expect(types).toContain("PersonelHesapFirstLoginResult");
    const firstLoginType = types.slice(
      types.indexOf("export type PersonelHesapFirstLoginResult = {"),
      types.indexOf("};", types.indexOf("export type PersonelHesapFirstLoginResult = {"))
    );
    expect(firstLoginType).not.toContain("activation");
  });

  it("activation page: autocomplete, success copy, mobile auth shell", () => {
    const page = read("src/features/auth/pages/PersonelAktivasyonPage.tsx");
    const css = read("src/styles/modules/auth.css");
    expect(page).toContain('autoComplete="new-password"');
    expect(page).toContain("Hesabınız başarıyla etkinleştirildi.");
    expect(page).toContain('className="auth-login"');
    expect(css).toContain("@media (max-width: 640px)");
    expect(css).toContain(".auth-login");
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
