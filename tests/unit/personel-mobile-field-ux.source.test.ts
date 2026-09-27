import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("personel mobile field UX closure contracts", () => {
  it("resolves QR/self modal titles instead of generic Modül fallback", () => {
    const shell = read("src/app/AppShell.tsx");
    expect(shell).toContain("resolveQrScanModalTitle");
    expect(shell).toContain('pathname === "/self/qr-okut"');
    expect(shell).toContain('pathname === "/self/qr-hareketleri"');
    expect(shell).toContain('pathname === "/self"');
    expect(shell).toContain('"Giriş"');
    expect(shell).toContain('"Çıkış"');
    expect(shell).toContain('"QR Okut"');
    expect(shell).toContain('"Giriş / Çıkış Geçmişim"');
    expect(shell).toContain("modal-container--self-qr-history");
    expect(shell).toContain("modal-container--self-qr-scan");
    expect(shell).toContain("SelfServiceModalHomeButton");
    expect(shell).toMatch(/isSelfQrScanModalRoute[\s\S]*SelfServiceModalHomeButton/);
    expect(shell).toContain('"Öz Servis"');
    expect(shell).toContain('searchParams.get("event")');
    expect(shell).toContain('return { title: "Modül", closeTo: "/" };');
  });

  it("keeps the camera preview filling leftover modal height without page scroll", () => {
    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(".qr-scan-video-wrap");
    expect(css).toContain("flex: 1 1 auto");
    expect(css).toContain("object-fit: cover");
    expect(css).toContain("overflow: hidden");
    expect(css).not.toContain("36svh");
    expect(css).not.toContain("36dvh");
    expect(css).toContain(".qr-scan-cta-zone");
    expect(css).toContain(".qr-scan-video-wrap--collapsed");
    expect(css).not.toContain("!important");
    // Compound owner must beat .personel-mobile-shell display:grid (same-specificity trap).
    expect(css).toMatch(
      /\.personel-mobile-shell\.qr-scan-page\s*\{[^}]*display:\s*flex;/s
    );
    expect(css).toMatch(
      /\.personel-mobile-shell\.qr-scan-page\s*\{[^}]*flex-direction:\s*column;/s
    );
    const scan = read("src/features/self-service/pages/PersonelQrScanPage.tsx");
    expect(scan).not.toContain("innerHeight");
    expect(scan).not.toContain("userAgent");
    expect(scan).toContain('data-testid="qr-scan-start"');
    expect(scan).toContain("QR Okut");
  });

  it("keeps actionable attendance as a single card surface", () => {
    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(".pm-attendance-box");
    expect(css).toContain(".pm-box-main-action");
    expect(css).toMatch(
      /\.pm-attendance-box:has\(\s*>\s*\.pm-box-main-action\s*\)\s*\{[^}]*padding:\s*0;/s
    );
    expect(css).toMatch(
      /\.pm-box-main-action\s*\{[^}]*border:\s*none;/s
    );
    expect(css).toMatch(
      /\.pm-box-main-action:focus-visible\s*\{[^}]*outline:\s*2px solid/s
    );
    // Nested second frame must not come from shared border with .pm-box-action.
    expect(css).not.toMatch(
      /\.pm-box-main-action\s*,\s*\.pm-box-action\s*\{[^}]*border:\s*1px solid/s
    );
  });

  it("keeps personel home minimal and attendance CTAs", () => {
    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).not.toContain("pm-context-bar");
    expect(home).not.toContain('data-testid="personel-notification-bell"');
    expect(home).not.toContain('data-testid="self-missing-info-warning"');
    expect(home).toContain("primeQrCamera()");
    expect(home).toContain('navigate("/self/qr-okut?event=GIRIS")');
    expect(home).toContain('navigate("/self/qr-okut?event=CIKIS")');
    expect(home).not.toContain("PERSONEL YÖN. SİST.");
    expect(home).not.toContain("ANASAYFA");
    expect(home).not.toContain("pm-help-strip");
    expect(home).not.toContain("pm-callout--warning");

    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(".pm-callout--warning");
    expect(css).toContain("min-height: 112px");
    expect(css).toMatch(/\.self-home-page \.pm-attendance-grid[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*minmax\(0,\s*1fr\)/);
    expect(css).not.toMatch(/\.self-home-page \.pm-attendance-grid[\s\S]*minmax\(148px/);
    expect(css).toMatch(
      /\.personel-mobile-shell\s*\{[^}]*padding:\s*4px 0 var\(--space-3\);/s
    );
    expect(css).not.toMatch(
      /\.personel-mobile-shell\s*\{[^}]*padding:[^;]*80px/s
    );
  });
});
