import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("login form control corner artifacts", () => {
  it("uses Taşıt login opaque 1px borders without subpixel inset rings", () => {
    const auth = read("src/styles/modules/auth.css");

    // The input block is canonical whether the selector is the legacy single
    // `.auth-field input` form or the current combined form that also covers
    // `.auth-field-row input` in the same block.
    const input = /\.auth-field input:not\(\[type="checkbox"\]\)(?:,\s*\.auth-field-row input:not\(\[type="checkbox"\]\))?\s*\{([^}]*)\}/s;
    const inputBlock = input.exec(auth)?.[1] ?? "";

    expect(inputBlock).toMatch(/padding:\s*7px 15px/);
    expect(inputBlock).toMatch(/border-radius:\s*8px/);
    expect(inputBlock).toMatch(/border:\s*1px solid rgba\(255, 255, 255, 0\.35\)/);
    expect(inputBlock).toMatch(/background:\s*var\(--bg-field\)/);
    expect(inputBlock).toMatch(/background-clip:\s*padding-box/);
    expect(inputBlock).toMatch(/box-shadow:\s*none/);
    expect(inputBlock).not.toMatch(/inset 0 0 0 0\.5px/);
    expect(inputBlock).not.toMatch(/filter:\s*drop-shadow/);

    // When the row variant exists, both selectors must share the same block.
    if (auth.includes(".auth-field-row")) {
      expect(auth).toMatch(
        /\.auth-field input:not\(\[type="checkbox"\]\),\s*\.auth-field-row input:not\(\[type="checkbox"\]\)\s*\{/s
      );
    }

    expect(auth).toMatch(
      /\.auth-field input:not\(\[type="checkbox"\]\):focus(?:,\s*\.auth-field-row input:not\(\[type="checkbox"\]\):focus)?\s*\{[^}]*border-color:\s*var\(--theme-color\)/s
    );
  });

  it("keeps checkbox size and Taşıt red inset frame without solid border bleed", () => {
    const auth = read("src/styles/modules/auth.css");

    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*width:\s*18px/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*height:\s*18px/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*box-shadow:\s*inset 0 0 0 0\.5px rgba\(var\(--theme-color-rgb\), 0\.6\)/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]:checked\s*\{[^}]*center\/55% no-repeat/s
    );
    expect(auth).not.toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*border:\s*1px solid #c41414/s
    );
  });

  it("scopes login submit to Taşıt transparent green-outline button", () => {
    const auth = read("src/styles/modules/auth.css");
    const buttons = read("src/styles/components/buttons.css");

    expect(auth).toMatch(/\.auth-login-form \.universal-btn-save\s*\{[^}]*border:\s*1px solid #1a5d35/s);
    expect(auth).toMatch(/\.auth-login-form \.universal-btn-save\s*\{[^}]*background:\s*transparent/s);
    expect(auth).toMatch(/\.auth-login-form \.universal-btn-save\s*\{[^}]*border-radius:\s*8px/s);
    expect(auth).toMatch(
      /\.auth-login-form \.universal-btn-save:hover:not\(\[disabled\]\),\s*\.auth-login-form \.universal-btn-save:focus-visible:not\(\[disabled\]\)\s*\{[^}]*background:\s*rgba\(74, 222, 128, 0\.12\)/s
    );

    expect(buttons).toMatch(/\.universal-btn-save\s*\{[^}]*border-color:\s*rgba\(74, 222, 128, 0\.5\)/s);
    expect(buttons).toMatch(
      /\.universal-btn-save:hover:not\(\[disabled\]\),\s*\.universal-btn-save:focus-visible:not\(\[disabled\]\)\s*\{[^}]*box-shadow:\s*0 0 0 2px rgba\(74, 222, 128, 0\.1\)/s
    );
  });

  it("does not change login alignment geometry or introduce a JS workaround", () => {
    const auth = read("src/styles/modules/auth.css");
    const page = read("src/features/auth/pages/LoginPage.tsx");

    expect(auth).toMatch(/\.auth-login-form\s*\{[^}]*width:\s*min\(100%,\s*392px\)/s);
    expect(auth).toMatch(/\.auth-login-stage\s*\{[^}]*justify-content:\s*center/s);
    expect(auth).toMatch(
      /@media\s*\(min-width:\s*641px\)\s*\{[^}]*\.auth-login\s*\{[^}]*justify-content:\s*center/s
    );
    expect(page).not.toMatch(/style=\{/);
    expect(page).not.toMatch(/boxShadow/);
    expect(page).not.toMatch(/getBoundingClientRect/);
  });
});
