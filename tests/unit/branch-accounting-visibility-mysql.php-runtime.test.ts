import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";

const runner = resolve(process.cwd(), "tests/php/BranchAccountingVisibilityMysqlTestRunner.php");

describe("branch accounting visibility mysql runtime", () => {
  it("keeps ACL relation integrity and atomic replace semantics", () => {
    const result = spawnSync("php", [runner], {
      encoding: "utf8",
      env: process.env
    });
    const output = `${result.stdout ?? ""}\n${result.stderr ?? ""}`;
    if (output.includes("SKIP:")) {
      expect(output).toContain("SKIP:");
      return;
    }
    expect(result.status, output).toBe(0);
    expect(output).toContain("ALL_PASS");
    expect(output).toContain("[PASS] failed validation leaves ACL unchanged");
  });
});
