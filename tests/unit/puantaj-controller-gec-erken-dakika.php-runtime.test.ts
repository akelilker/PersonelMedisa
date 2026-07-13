import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/PuantajControllerGecErkenDakikaTestRunner.php");
const phpArgs = ["-d", "extension=php_sqlite3", "-d", "extension=php_pdo_sqlite", runnerPath];

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
  "SCENARIO:10:PASS"
];

describe("PuantajController gec/erken dakika PHP runtime", () => {
  it("runs insert/update/map/seal runtime scenarios via PHP CLI", () => {
    const output = execFileSync("php", phpArgs, { encoding: "utf8" });
    expect(output.trim().endsWith("OK")).toBe(true);
    for (const marker of expectedScenarios) {
      expect(output).toContain(marker);
    }
  });
});
