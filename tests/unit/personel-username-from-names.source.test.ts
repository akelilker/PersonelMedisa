import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { buildPersonelUsernameFromNames } from "../../src/features/yonetim/personelUsernameFromNames";

const servicePath = resolve("api/src/Services/Auth/PersonelAccountOnboardingService.php");

function phpBuild(ad: string, soyad: string): string {
  const script = `
require '${servicePath.replace(/\\/g, "/")}';
echo Medisa\\Api\\Services\\Auth\\PersonelAccountOnboardingService::buildPersonelUsernameFromNames(
  ${JSON.stringify(ad)},
  ${JSON.stringify(soyad)}
);
`;
  // bootstrap-light: class file needs autoload/config? It uses JsonResponse on empty — for valid names OK.
  // Prefer direct -r with require bootstrap.
  const result = spawnSync(
    "php",
    [
      "-r",
      `require 'api/src/bootstrap.php'; echo Medisa\\Api\\Services\\Auth\\PersonelAccountOnboardingService::buildPersonelUsernameFromNames(${JSON.stringify(ad)}, ${JSON.stringify(soyad)});`
    ],
    { encoding: "utf8", cwd: process.cwd() }
  );
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || "php build failed");
  }
  return (result.stdout || "").trim();
}

describe("personel kullanıcı adı üretimi", () => {
  it("FE ve PHP aynı örnekleri üretir; sicil katılmaz", () => {
    const cases: Array<[string, string, string]> = [
      ["İlker", "AKEL", "ilkerA"],
      ["Özkan", "ERÇİN", "ozkanE"],
      ["Kürşat", "KEDEROĞLU", "kursatK"],
      ["Mehmet Ali", "YILMAZ", "mehmetY"],
      ["Abdul Kadir", "KAN", "abdulK"],
      ["Sercan", "YILDIRIM", "sercanY"],
      ["Recep", "AYDIN", "recepA"]
    ];
    for (const [ad, soyad, expected] of cases) {
      expect(buildPersonelUsernameFromNames(ad, soyad)).toBe(expected);
      expect(phpBuild(ad, soyad)).toBe(expected);
    }
    expect(buildPersonelUsernameFromNames("İlker", "AKEL")).not.toContain("123");
    expect(buildPersonelUsernameFromNames("İlker", "AKEL")).not.toMatch(/SIC/i);
  });

  it("boş ad/soyad için boş öneri döner (FE)", () => {
    expect(buildPersonelUsernameFromNames("", "AKEL")).toBe("");
    expect(buildPersonelUsernameFromNames("İlker", "")).toBe("");
  });
});
