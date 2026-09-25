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
    expect(shell).toContain('"QR ile Giriş"');
    expect(shell).toContain('"QR ile Çıkış"');
    expect(shell).toContain('"QR Okut"');
    expect(shell).toContain('"QR Hareketlerim"');
    expect(shell).toContain('"Öz Servis"');
    expect(shell).toContain('searchParams.get("event")');
    // Fallback Modül remains for unknown modules only.
    expect(shell).toContain('return { title: "Modül", closeTo: "/" };');
  });

  it("keeps camera CTA reachable via height-capped preview owner CSS", () => {
    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(".qr-scan-video-wrap");
    expect(css).toContain("36svh");
    expect(css).toContain("36dvh");
    expect(css).toContain("aspect-ratio: 3 / 4");
    expect(css).toContain(".qr-scan-cta-zone");
    expect(css).toContain(".qr-scan-lead");
    expect(css).not.toContain("!important");
    // No UA sniff or JS height measurement owners.
    const scan = read("src/features/self-service/pages/PersonelQrScanPage.tsx");
    expect(scan).not.toContain("innerHeight");
    expect(scan).not.toContain("userAgent");
    expect(scan).toContain('data-testid="qr-scan-start"');
    expect(scan).toContain("Kamerayı aç");
  });

  it("removes duplicate product header on personel home and keeps attendance CTAs", () => {
    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).toContain("pm-context-bar");
    expect(home).toContain('data-testid="personel-notification-bell"');
    expect(home).toContain('data-testid="self-missing-info-warning"');
    expect(home).toContain('navigate("/self/qr-okut?event=GIRIS")');
    expect(home).toContain('navigate("/self/qr-okut?event=CIKIS")');
    expect(home).not.toContain("PERSONEL YÖN. SİST.");
    expect(home).not.toContain("ANASAYFA");
    expect(home).toContain("pm-help-strip");
    expect(home).toContain("pm-callout--warning");

    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(".pm-context-bar");
    expect(css).toContain(".pm-callout--warning");
    expect(css).toContain("min-height: 112px");
    // content-wrap already clears AppFooter; shell padding must not re-stack 80px
    expect(css).toMatch(
      /\.personel-mobile-shell\s*\{[^}]*padding:\s*4px 0 var\(--space-3\);/s
    );
    expect(css).not.toMatch(
      /\.personel-mobile-shell\s*\{[^}]*padding:[^;]*80px/s
    );
  });
});
