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
  });

  it("routes premium modal titles through canonical premium-title gradient", () => {
    const modal = read("src/styles/components/modal.css");
    expect(modal).toMatch(/\.modal-header h2\.premium-title[\s\S]*var\(--premium-title-gradient\)/);
    expect(modal).toMatch(/\.modal-header h2\.premium-title[\s\S]*var\(--premium-title-shadow\)/);
  });

  it("keeps visible modal frame when modal-open (mobile retains side borders)", () => {
    const modal = read("src/styles/components/modal.css");
    expect(modal).toMatch(/body\.modal-open \.modal-container[\s\S]*box-shadow:\s*none/);
    expect(modal).not.toMatch(/var\(--modal-chrome-glow\)/);
    expect(modal).not.toMatch(/border-left-width:\s*0/);
    expect(modal).toMatch(/body\.modal-open \.modal-header[\s\S]*--modal-header-red-gradient/);
    expect(modal).toMatch(/\.modal-header\s*\{[^}]*height:\s*60px/);
    expect(modal).toMatch(/\.modal-header\s*\{[^}]*border-bottom:\s*none/);
  });

  it("renders Taşıt-style semi-transparent brand marker on modal container", () => {
    const modal = read("src/styles/components/modal.css");
    expect(modal).toMatch(/\.modal-container::after[\s\S]*bottom:\s*8px/);
    expect(modal).toMatch(/\.modal-container::after[\s\S]*right:\s*8px/);
    expect(modal).toMatch(/\.modal-container::after[\s\S]*width:\s*44px/);
    expect(modal).toMatch(/\.modal-container::after[\s\S]*opacity:\s*0\.2/);
    expect(modal).toMatch(/\.modal-container::after[\s\S]*pointer-events:\s*none/);
    expect(modal).toMatch(/marker\.png/);
  });
});
