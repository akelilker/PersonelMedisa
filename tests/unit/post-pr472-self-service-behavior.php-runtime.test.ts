import { describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const runner = resolve(process.cwd(), "tests/php/PostPr472SelfServiceBehaviorTestRunner.php");
const phpAvailable = spawnSync("php", ["-r", "echo PHP_VERSION;"]).status === 0;

describe("post-PR472 self-service behavior (PHP)", () => {
  it.skipIf(!phpAvailable)("covers bordro ack idempotency and rapor attachment validation", () => {
    const result = spawnSync("php", [runner], { encoding: "utf8" });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("POST_PR472_SELF_SERVICE_BEHAVIOR=OK");
  });
});
