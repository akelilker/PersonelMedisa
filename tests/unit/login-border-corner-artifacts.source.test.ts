import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();

function read(relPath: string): string {
  return readFileSync(resolve(ROOT, relPath), "utf8");
}

describe("login form control corner artifacts", () => {
  it("replaces hairline inset rings with opaque 1px borders and soft glow", () => {
    const auth = read("src/styles/modules/auth.css");
    const input = /\.auth-field input:not\(\[type="checkbox"\]\)\s*\{[^}]*\}/s;

    expect(auth).toMatch(/\.auth-field input:not\(\[type="checkbox"\]\)\s*\{[^}]*height:\s*40px/s);
    expect(auth).toMatch(/\.auth-field input:not\(\[type="checkbox"\]\)\s*\{[^}]*padding:\s*0 14px/s);
    expect(auth).toMatch(/\.auth-field input:not\(\[type="checkbox"\]\)\s*\{[^}]*border-radius:\s*8px/s);
    expect(auth).toMatch(/\.auth-field input:not\(\[type="checkbox"\]\)\s*\{[^}]*border:\s*1px solid #4e565c/s);
    expect(auth).toMatch(
      /\.auth-field input:not\(\[type="checkbox"\]\)\s*\{[^}]*background-clip:\s*padding-box/s
    );
    expect(auth).toMatch(
      /\.auth-field input:not\(\[type="checkbox"\]\)\s*\{[^}]*filter:\s*drop-shadow\(0 0 8px rgba\(255, 255, 255, 0\.06\)\)/s
    );
    expect(auth).not.toMatch(/inset 0 0 0 0\.5px/);
    expect(input.exec(auth)?.[0] ?? "").not.toMatch(/overflow:\s*hidden/);
    expect(auth).toMatch(
      /\.auth-field input:not\(\[type="checkbox"\]\):focus\s*\{[^}]*border-color:\s*var\(--theme-color\)/s
    );
    expect(auth).toMatch(
      /\.auth-field input:not\(\[type="checkbox"\]\):focus\s*\{[^}]*filter:\s*drop-shadow\(0 0 10px rgba\(var\(--theme-color-rgb\)/s
    );
  });

  it("keeps checkbox size and red frame without inset corner highlights", () => {
    const auth = read("src/styles/modules/auth.css");

    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*width:\s*20px/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*height:\s*20px/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*padding:\s*0/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*border:\s*1px solid #c41414/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*background-clip:\s*padding-box/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*overflow:\s*hidden/s
    );
    expect(auth).not.toMatch(
      /\.auth-field-inline input\[type="checkbox"\]\s*\{[^}]*inset 0 1px 0/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]:checked::after\s*\{[^}]*border-radius:\s*2px/s
    );
    expect(auth).toMatch(
      /\.auth-field-inline input\[type="checkbox"\]:checked::after\s*\{[^}]*inset:\s*4px/s
    );
  });

  it("scopes button corner clipping to the login submit owner only", () => {
    const auth = read("src/styles/modules/auth.css");
    const buttons = read("src/styles/components/buttons.css");

    expect(auth).toMatch(/\.auth-login-form \.universal-btn-save\s*\{[^}]*height:\s*44px/s);
    expect(auth).toMatch(/\.auth-login-form \.universal-btn-save\s*\{[^}]*border-radius:\s*8px/s);
    expect(auth).toMatch(/\.auth-login-form \.universal-btn-save\s*\{[^}]*border-color:\s*#3da86a/s);
    expect(auth).toMatch(
      /\.auth-login-form \.universal-btn-save\s*\{[^}]*background-clip:\s*padding-box/s
    );
    expect(auth).toMatch(/\.auth-login-form \.universal-btn-save\s*\{[^}]*overflow:\s*hidden/s);
    expect(auth).toMatch(
      /\.auth-login-form \.universal-btn-save:hover:not\(\[disabled\]\),\s*\.auth-login-form \.universal-btn-save:focus-visible:not\(\[disabled\]\)\s*\{[^}]*box-shadow:\s*inset 0 0 10px rgba\(74, 222, 128/s
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
