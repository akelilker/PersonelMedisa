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
    expect(result.stdout).toContain("RAPOR create success 201");
    expect(result.stdout).toContain("orphan storage file cleaned after rollback");
    expect(result.stdout).toContain("SELF_RAPOR_REQUEST dedupe single inbox row");
    expect(result.stdout).toContain("SELF_IZIN_REQUEST dedupe single inbox row");
    expect(result.stdout).toContain("SELF_AVANS_REQUEST dedupe single inbox row");
    expect(result.stdout).toContain("archive guard ARCHIVED_PERSONEL_READ_ONLY");
    expect(result.stdout).toContain("notifier exception still 201");
  });
});
