import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const DOWNLOAD_OWNER_PATHS = [
  "src/api/donem-kapanis.api.ts",
  "src/api/bordro-hazirlik.api.ts",
  "src/api/maas-hesaplama.api.ts",
  "src/api/bildirim-etki-rapor.api.ts",
  "src/api/personel-belge-kayitlari.api.ts"
] as const;

describe("S94 download demo gate contract", () => {
  for (const ownerPath of DOWNLOAD_OWNER_PATHS) {
    it(`${ownerPath} demo download'u shouldPreferDemoApi ile kapılar`, () => {
      const source = readFileSync(resolve(process.cwd(), ownerPath), "utf8");
      expect(source).toContain("shouldPreferDemoApi");
      expect(source).toMatch(/if\s*\(\s*shouldPreferDemoApi\s*\(\s*\)\s*\)/);
    });
  }

  it("api-client production'da demo fallback varsayılanı false'tur", () => {
    const source = readFileSync(resolve(process.cwd(), "src/api/api-client.ts"), "utf8");
    expect(source).toContain('MODE === "production" ? "false" : "true"');
    expect(source).toContain("export function shouldPreferDemoApi");
  });

  it("LoginPage rememberMe bilgisini login'e iletir", () => {
    const source = readFileSync(resolve(process.cwd(), "src/features/auth/pages/LoginPage.tsx"), "utf8");
    expect(source).toMatch(/login\(\{[\s\S]*rememberMe[\s\S]*\}\)/);
  });
});
