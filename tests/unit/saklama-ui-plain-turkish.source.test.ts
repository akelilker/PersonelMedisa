import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(rel: string) {
  return readFileSync(resolve(root, rel), "utf8");
}

/** Extract likely user-visible string literals and JSX text snippets from a source file. */
function userVisibleSnippets(src: string): string[] {
  const out: string[] = [];
  const strRe = /(["'`])((?:\\.|(?!\1).)*)\1/g;
  let m: RegExpExecArray | null;
  while ((m = strRe.exec(src))) {
    const lit = m[2];
    if (lit.length < 2) continue;
    // Skip technical identifiers / selectors / paths (no spaces, kebab/snake/dot)
    if (/^[a-zA-Z0-9_./:-]+$/.test(lit)) continue;
    if (!/[\sA-Za-zÇĞİÖŞÜçğıöşü]/.test(lit)) continue;
    // Skip import paths and permission keys with dots but no spaces already handled
    out.push(lit);
  }
  const jsxRe = />(\s*[^<>{}\n][^<>{}]*?)\s*</g;
  while ((m = jsxRe.exec(src))) {
    const t = m[1].trim();
    if (t) out.push(t);
  }
  return out;
}

describe("saklama UI plain Turkish redesign", () => {
  it("keeps backend target_domain personel while hiding technical fields", () => {
    const panel = read("src/features/yonetim/components/SaklamaLegalHoldPanel.tsx");
    expect(panel).toContain('const TARGET_DOMAIN_PERSONEL = "personel"');
    expect(panel).toContain("target_domain: TARGET_DOMAIN_PERSONEL");
    expect(panel).toContain('label="Personel"');
    expect(panel).toContain("fetchPersonellerList");
    expect(panel).not.toContain("Hedef domain");
    expect(panel).not.toContain('label="Personel ID"');
    expect(panel).not.toMatch(/Legal hold oluştur/i);
    expect(panel).not.toContain("Aktif legal hold");
    expect(panel).not.toContain("Legal hold olusturuldu");
    expect(panel).not.toContain("Hold oluştur");
    expect(panel).toContain("Korumaya Al");
    expect(panel).toContain("Koruma Altındaki Kayıtlar");
    expect(panel).toContain("Korumayı Kaldır");
    expect(panel).toContain("PERSONEL_OZLUK");
    expect(panel).toContain("formatRetentionCategoryLabel");
    expect(panel).toContain("formatRetentionImhaStatusLabel");
    expect(panel).toContain("formatRetentionEligibilitySummary");
  });

  it("hides QR Kiosk outside kullanıcılar tab and shows Turkish QR label", () => {
    const page = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    expect(page).toContain('data-testid="yonetim-qr-kiosk-link"');
    expect(page).toContain('to="/qr-kiosk"');
    expect(page).toContain("QR Giriş Ekranı");
    expect(page).not.toContain(">QR Kiosk<");
    expect(page).not.toContain('"QR Kiosk"');
    const kioskIdx = page.indexOf("yonetim-qr-kiosk-link");
    const kioskBlock = page.slice(Math.max(0, kioskIdx - 160), kioskIdx + 220);
    expect(kioskBlock).toContain('activeTab === "kullanicilar"');
  });

  it("maps saklama modal title via shared helper", () => {
    const shell = read("src/app/AppShell.tsx");
    const helper = read("src/lib/yonetim/yonetim-modal-title.ts");
    expect(shell).toContain("yonetim-modal-title");
    expect(helper).toContain('saklama: "SAKLAMA VE İMHA YÖNETİMİ"');
    expect(helper).toContain('kullanicilar: "KULLANICI YÖNETİMİ"');
  });
});

describe("user-visible Turkish final audit contracts", () => {
  it("ResmiTatilTakvimiPage shows Hazırlık Özeti not Readiness özeti", () => {
    const page = read("src/features/yonetim/pages/ResmiTatilTakvimiPage.tsx");
    const visible = userVisibleSnippets(page).join("\n");
    expect(visible).toContain("Hazırlık Özeti");
    expect(visible).not.toContain("Readiness özeti");
    expect(visible).not.toMatch(/\bReadiness\b/);
  });

  it("saklama panel has no user-visible Legal/Hold/Domain wording", () => {
    const panel = read("src/features/yonetim/components/SaklamaLegalHoldPanel.tsx");
    const visible = userVisibleSnippets(panel).join("\n");
    expect(visible).not.toMatch(/\bLegal hold\b/i);
    expect(visible).not.toMatch(/\bLegal Hold\b/);
    expect(visible).not.toMatch(/\bHold oluştur\b/i);
    expect(visible).not.toMatch(/\bHedef domain\b/i);
    expect(visible).not.toMatch(/\bDomain\b/);
    // Internal API/domain identifier must remain
    expect(panel).toContain('const TARGET_DOMAIN_PERSONEL = "personel"');
    expect(panel).toContain("createLegalHold");
  });

  it("yonetim second-pass surfaces hide Workspace/actor/lifecycle user labels", () => {
    const files = [
      "src/features/yonetim/pages/YonetimPaneliPage.tsx",
      "src/features/yonetim/components/SaklamaLegalHoldPanel.tsx",
      "src/features/yonetim/components/KullaniciActorIdentityPanel.tsx",
      "src/features/yonetim/components/MevzuatParametreleriPanel.tsx",
      "src/components/shell/ShellHeaderActions.tsx"
    ];
    for (const rel of files) {
      const src = read(rel);
      const visible = userVisibleSnippets(src).join("\n");
      expect(visible, rel).not.toMatch(/\bWorkspace\b/);
      expect(visible, rel).not.toMatch(/\blifecycle\b/i);
      expect(visible, rel).not.toMatch(/\bLegal Hold\b/);
      expect(visible, rel).not.toMatch(/\bLegal hold\b/);
      expect(visible, rel).not.toMatch(/\bReadiness\b/);
      expect(visible, rel).not.toMatch(/\bSmoke\b/);
    }
    const actorPanel = read("src/features/yonetim/components/KullaniciActorIdentityPanel.tsx");
    const actorVisible = userVisibleSnippets(actorPanel).join("\n");
    expect(actorVisible).not.toMatch(/\bactor\b/i);
    expect(actorVisible).toContain("SGK yetkili kimliği");
    expect(actorPanel).toContain("yonetim-kullanici-actor-identity"); // internal test id kept
  });

  it("QR route stays /qr-kiosk while UI label is QR Giriş Ekranı", () => {
    const yonetim = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    const kiosk = read("src/features/self-service/pages/QrKioskPage.tsx");
    expect(yonetim).toContain('to="/qr-kiosk"');
    expect(yonetim).toContain("QR Giriş Ekranı");
    expect(kiosk).toContain("QR Giriş Ekranı");
    const kioskVisible = userVisibleSnippets(kiosk).join("\n");
    expect(kioskVisible).not.toContain("QR Kiosk");
  });

  it("shell settings menu uses Saklama ve İmha not Legal Hold", () => {
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    const visible = userVisibleSnippets(shell).join("\n");
    expect(visible).toContain("Saklama ve İmha");
    expect(visible).not.toMatch(/Legal Hold/i);
  });
});
