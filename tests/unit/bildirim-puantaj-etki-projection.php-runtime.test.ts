import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/BildirimPuantajEtkiProjectionTestRunner.php");
const servicePath = resolve(process.cwd(), "api/src/Services/BildirimPuantajEtkiProjectionService.php");
const serviceSource = readFileSync(servicePath, "utf8");

const expectedScenarios = [
  "SCENARIO:1:PASS",
  "SCENARIO:2:PASS",
  "SCENARIO:3:PASS",
  "SCENARIO:4:PASS",
  "SCENARIO:5:PASS",
  "SCENARIO:6:PASS",
  "SCENARIO:7:PASS",
  "SCENARIO:8:PASS",
  "SCENARIO:9:PASS",
  "SCENARIO:10:PASS",
  "SCENARIO:11:PASS",
  "SCENARIO:12:PASS",
  "SCENARIO:13:PASS",
  "SCENARIO:14:PASS",
  "SCENARIO:15:PASS",
  "SCENARIO:16:PASS",
  "SCENARIO:17:PASS",
  "SCENARIO:R1:PASS"
];

describe("BildirimPuantajEtkiProjectionService PHP runtime", () => {
  it("runs projection scenarios plus regression via PHP CLI", () => {
    const output = execFileSync("php", [runnerPath], { encoding: "utf8" });
    expect(output.trim().endsWith("OK")).toBe(true);
    for (const marker of expectedScenarios) {
      expect(output).toContain(marker);
    }
  });

  it("uses locked conflict codes only in projection service", () => {
    for (const code of [
      "COKLU_BILDIRIM_CELISKISI",
      "COKLU_RESMI_SUREC",
      "MEVCUT_PUANTAJ_VAR",
      "DAKIKA_EKSIK",
      "IZIN_SURECI_YOK",
      "RAPOR_SURECI_YOK",
      "DIGER_MANUEL_INCELEME",
      "UCRETSIZ_IZIN_DESTEKLENMIYOR"
    ]) {
      expect(serviceSource).toContain(`'${code}'`);
    }
    expect(serviceSource).not.toContain("RESMI_SUREC_GEREKLI");
    expect(serviceSource).not.toContain("RESMI_SUREC_DOGRULANAMADI");
    expect(serviceSource).not.toContain("RESMI_SUREC_CELISKISI");
  });

  it("does not write to operational tables", () => {
    expect(serviceSource).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);
    expect(serviceSource).not.toMatch(/UPDATE\s+gunluk_puantaj/i);
    expect(serviceSource).not.toContain("ek_odeme_kesinti");
  });

  it("test runner lives outside production api tree", () => {
    expect(runnerPath.replace(/\\/g, "/")).toContain("tests/php/");
    expect(() => readFileSync(resolve(process.cwd(), "api/tests/BildirimPuantajEtkiProjectionTestRunner.php"))).toThrow();
  });
});
