import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("PERSONEL Giriş/Çıkış Geçmişim modal Taşıt monthly-todo parity", () => {
  it("wires scoped modal chrome + home control on AppShell owner", () => {
    const shell = read("src/app/AppShell.tsx");
    expect(shell).toContain('pathname === "/self/qr-hareketleri"');
    expect(shell).toContain("modal-container--self-qr-history");
    expect(shell).toContain("modal-body--self-qr-history");
    expect(shell).toContain('titleVariant: "premium"');
    expect(shell).toContain("SelfServiceModalHomeButton");
    expect(shell).toContain('className="modal-home-btn"');
    expect(shell).toContain('aria-label="Ana sayfaya dön"');
    expect(shell).toContain('d="M3 10.5 12 3l9 7.5"');
    expect(shell).toMatch(/isSelfQrHistoryModalRoute[\s\S]*SelfServiceModalHomeButton/);
  });

  it("keeps Taşıt-like overlay gap, opaque gap fill, and title tracking in self-service CSS owner", () => {
    const css = read("src/features/self-service/self-service.css");
    expect(css).toMatch(
      /\.modal-overlay:has\(\.modal-container--self-qr-history\)[\s\S]*--app-footer-gap/s
    );
    expect(css).toMatch(
      /\.modal-overlay:has\(\.modal-container--self-qr-history\)[\s\S]*background:\s*var\(--bg\)/s
    );
    expect(css).toMatch(
      /\.modal-container--self-qr-history \.modal-header[\s\S]*border-bottom:\s*none/s
    );
    expect(css).toMatch(
      /\.modal-container--self-qr-history \.modal-header h2\.premium-title[\s\S]*letter-spacing:\s*var\(--premium-title-letter-spacing\)/s
    );
    expect(css).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*\.modal-container--self-qr-history \.modal-header h2\.premium-title[\s\S]*letter-spacing:\s*0\.12em/s
    );
    expect(css).not.toMatch(
      /\.modal-container--self-qr-history[\s\S]*box-shadow:\s*none/s
    );
    const modal = read("src/styles/components/modal.css");
    expect(modal).toMatch(/var\(--modal-inset-chrome-lift\)/);
    expect(css).toMatch(
      /\.modal-body--self-qr-history:has\(> \.qr-history-page\)[\s\S]*overflow-y:\s*auto/s
    );
    expect(css).not.toContain("!important");
  });
});
