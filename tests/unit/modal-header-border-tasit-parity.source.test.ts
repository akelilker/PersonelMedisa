import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("modal header + frame Taşıt parity (chrome only)", () => {
  it("uses bright modal title token and Taşıt chrome border tokens", () => {
    const colors = read("src/styles/tokens/colors.css");
    expect(colors).toContain("--modal-title-color: #ffffff;");
    expect(colors).toContain("--modal-outline:");
    expect(colors).toContain("--modal-desktop-ring:");
    expect(colors).toContain("--modal-chrome-glow:");
    expect(colors).toContain("--modal-outline:");
  });

  it("routes premium modal titles through canonical premium-title gradient", () => {
    const modal = read("src/styles/components/modal.css");
    expect(modal).toMatch(/\.modal-header h2\.premium-title[\s\S]*var\(--premium-title-gradient\)/);
    expect(modal).toMatch(/\.modal-header h2\.premium-title[\s\S]*var\(--premium-title-shadow\)/);
  });

  it("keeps visible modal frame when modal-open (mobile retains side borders)", () => {
    const modal = read("src/styles/components/modal.css");
    expect(modal).toMatch(/body\.modal-open \.modal-container[\s\S]*overflow:\s*hidden/);
    expect(modal).toMatch(
      /@media\s*\(max-width:\s*640px\)[\s\S]*body\.modal-open \.modal-container[\s\S]*var\(--modal-outline\)/s
    );
    expect(modal).not.toMatch(/border-left-color:\s*var\(--border-red-side\)/);
    expect(modal).not.toMatch(/var\(--modal-chrome-glow\)/);
    expect(modal).not.toMatch(/border-left-width:\s*0/);
    expect(modal).toMatch(/body\.modal-open \.modal-header[\s\S]*--modal-header-red-gradient/);
    expect(modal).toMatch(/\.modal-header\s*\{[^}]*height:\s*60px/);
    expect(modal).toMatch(/\.modal-header\s*\{[^}]*border-bottom:\s*none/);
    expect(modal).not.toMatch(/\.modal-header[\s\S]*inset 0 -1px 0 rgba\(0,\s*0,\s*0,\s*0\.05\)/);
  });

  it("renders Taşıt-style semi-transparent brand marker on modal container", () => {
    const modal = read("src/styles/components/modal.css");
    expect(modal).toMatch(/\.modal-container\s*\{[^}]*isolation:\s*isolate/s);
    expect(modal).toMatch(/\.modal-container::after,\s*\.pm-notice-surface::after/);
    expect(modal).toMatch(/\.pm-notice-surface::after[\s\S]*bottom:\s*8px/);
    expect(modal).toMatch(/\.pm-notice-surface::after[\s\S]*right:\s*8px/);
    expect(modal).toMatch(/\.pm-notice-surface::after[\s\S]*width:\s*36px/);
    expect(modal).toMatch(/\.pm-notice-surface::after[\s\S]*height:\s*36px/);
    expect(modal).toMatch(/\.pm-notice-surface::after[\s\S]*opacity:\s*0\.2/);
    expect(modal).toMatch(/\.pm-notice-surface::after[\s\S]*z-index:\s*-1/);
    expect(modal).toMatch(/\.pm-notice-surface::after[\s\S]*pointer-events:\s*none/);
    expect(modal).toMatch(/marker\.png/);
    expect(modal).toMatch(/\.modal-container\.hide-marker::after\s*\{[^}]*display:\s*none/s);
    expect(modal).toMatch(/\.modal-footer\s*\{[^}]*background:\s*transparent/s);
  });

  it("pins the same watermark inside the home frame without reusing shell pseudos", () => {
    const shell = read("src/styles/layout/app-shell.css");
    const appShell = read("src/app/AppShell.tsx");
    const notice = read("src/features/self-service/self-service.css");

    expect(shell).toMatch(/\.app-shell::before\s*\{[^}]*border-left:/s);
    expect(shell).toMatch(/\.app-shell::after\s*\{[^}]*box-shadow:\s*inset/s);
    expect(shell).not.toMatch(/\.app-shell::before\s*\{[^}]*marker\.png/);
    expect(shell).not.toMatch(/\.app-shell::after\s*\{[^}]*marker\.png/);
    expect(shell).toMatch(
      /body\.app-home-route \.app-shell-marker\s*\{[^}]*bottom:\s*calc\(\s*var\(--app-footer-real-height\)\s*\+\s*env\(safe-area-inset-bottom,\s*0px\)\s*\+\s*8px\s*\)/s
    );
    expect(shell).toMatch(/body\.app-home-route \.app-shell-marker\s*\{[^}]*right:\s*8px/s);
    expect(shell).toMatch(/body\.app-home-route \.app-shell-marker\s*\{[^}]*width:\s*36px/s);
    expect(shell).toMatch(/body\.app-home-route \.app-shell-marker\s*\{[^}]*height:\s*36px/s);
    expect(shell).toMatch(/body\.app-home-route \.app-shell-marker\s*\{[^}]*opacity:\s*0\.2/s);
    expect(shell).toMatch(/body\.app-home-route \.app-shell-marker\s*\{[^}]*pointer-events:\s*none/s);
    expect(shell).toMatch(/body\.app-home-route \.app-shell-marker\s*\{[^}]*z-index:\s*0/s);
    expect(shell).toMatch(/body\.modal-open \.app-shell-marker\s*\{[^}]*visibility:\s*hidden/s);
    expect(appShell).toContain('className="app-shell-marker"');
    expect(notice).toMatch(/\.pm-notice-surface\s*\{[^}]*position:\s*relative/s);
    expect(notice).toMatch(/\.pm-notice-surface\s*\{[^}]*isolation:\s*isolate/s);
  });
});
