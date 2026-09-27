import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("PERSONEL QR kamera modal Taşıt monthly-todo parity", () => {
  it("wires scoped modal chrome + home control on AppShell owner", () => {
    const shell = read("src/app/AppShell.tsx");
    expect(shell).toContain('pathname === "/self/qr-okut"');
    expect(shell).toContain("modal-container--self-qr-scan");
    expect(shell).toContain("modal-body--self-qr-scan");
    expect(shell).toContain('titleVariant: "premium"');
    expect(shell).toContain("SelfServiceModalHomeButton");
    expect(shell).toMatch(/isSelfQrScanModalRoute[\s\S]*SelfServiceModalHomeButton/);
  });

  it("keeps camera preview flush inside modal frame with inset red lift", () => {
    const css = read("src/features/self-service/self-service.css");
    const spacing = read("src/styles/tokens/spacing.css");
    expect(spacing).toMatch(/--app-footer-gap:\s*20px/);
    expect(css).toMatch(
      /\.modal-overlay:has\(\.modal-container--self-qr-scan\)[\s\S]*--app-footer-gap/s
    );
    expect(css).toMatch(
      /\.modal-body--self-qr-scan:has\(> \.qr-scan-page\)[\s\S]*padding:\s*0/s
    );
    expect(css).toMatch(
      /\.modal-body--self-qr-scan \.qr-scan-video-wrap[\s\S]*border-radius:\s*0/s
    );
    expect(css).not.toMatch(
      /\.modal-body--self-qr-scan \.personel-mobile-shell\.qr-scan-page[\s\S]*calc\(100% \+ 3px\)/s
    );
    expect(css).toMatch(
      /\.modal-container--self-qr-scan[\s\S]*overflow:\s*hidden/s
    );
    expect(css).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*\.modal-container--self-qr-scan[\s\S]*inset 0 0 22px/s
    );
    expect(css).not.toContain("!important");
  });
});
