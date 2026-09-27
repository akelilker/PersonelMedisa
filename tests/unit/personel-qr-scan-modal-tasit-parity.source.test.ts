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

  it("keeps camera preview flush inside modal body (chrome lift is global modal owner)", () => {
    const css = read("src/features/self-service/self-service.css");
    const modal = read("src/styles/components/modal.css");
    expect(css).toMatch(
      /\.modal-body--self-qr-scan:has\(> \.qr-scan-page\)[\s\S]*padding:\s*0/s
    );
    expect(css).toMatch(
      /\.modal-body--self-qr-scan \.qr-scan-video-wrap[\s\S]*border-radius:\s*0/s
    );
    expect(css).not.toMatch(
      /\.modal-container--self-qr-scan[\s\S]*box-shadow:\s*none/s
    );
    expect(modal).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*var\(--modal-inset-chrome-lift\)/s
    );
    expect(css).not.toContain("!important");
  });
});
