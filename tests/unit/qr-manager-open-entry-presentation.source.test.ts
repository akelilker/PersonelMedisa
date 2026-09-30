import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const servicePath = resolve(process.cwd(), "api/src/Services/Qr/QrAttendanceIntervalReadService.php");
const runnerPath = resolve(process.cwd(), "tests/php/QrManagerOpenEntryPresentationTestRunner.php");

function read(path: string): string {
  return readFileSync(path, "utf8");
}

describe("manager QR open-entry presentation (finding B)", () => {
  it("keeps presentation-only suppression in the read service", () => {
    const source = read(servicePath);
    expect(source).toContain("managerPresentationAnomalies");
    expect(source).toContain("managerFirstEntry");
    expect(source).toContain("openGirisBlocksNextGiris");
  });

  it("passes open-entry presentation acceptance when PHP CLI is available", () => {
    const php = spawnSync("php", ["-v"], { encoding: "utf8" });
    if (php.status !== 0) {
      return;
    }

    const result = spawnSync("php", [runnerPath], {
      cwd: process.cwd(),
      encoding: "utf8",
      env: process.env
    });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] QrManagerOpenEntryPresentationTestRunner");
  });
});
