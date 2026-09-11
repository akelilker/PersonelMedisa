import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/FinalClosePreimageDriftAttributionTestRunner.php");

/**
 * The exact bounded-check names the runner must print, in order. A contract that
 * silently lost an axis, a mismatch class or the fail-closed guard would drop one of
 * these lines, so the runtime test pins the whole surface instead of a sample.
 */
const PASS_NAMES = [
  "the approved preimage reports no drift at all",
  "the fail-closed guard still accepts the approved preimage",
  "a late, single drift is reported on its own",
  "one run reports every drifted axis, not just the first (6 drifts)",
  "each axis answers with its own category, id, field and mismatch class",
  "the whole-run report holds each drift exactly once (both drifted policies collapse to one code)",
  "the mutation guard still stops at the first mismatch and still throws one bounded token",
  "MISSING, NULL, TYPE, COUNT, IDS and VALUE are told apart as shape-only classes",
  "an unknown category, target, field or class degrades to the generic prefix",
  "every drift target is a compiled package id, never a caller value",
  "a poisoned row yields categories only: no value, no id list, no SQL, no exception text",
  "an unusable snapshot reports all 73 expected fields once, inside the 100-token transport bound",
  "a scope-less match keeps the untouched generic single-token contract",
  "the read-only snapshot path is released while the frame guard stays on the mutation path",
  "the mutation path still checks the captured frame and the approved preimage",
  "a failed preflight publishes a bounded FAIL report with no apply checksum and no mutation",
  "the failure report carries no snapshot, no SQL and no exception text",
  "an apply request still cannot run without a 64-char preflight checksum",
  "the control plane trusts a drift list only when it is correlated and bounded",
  "a failed preflight logs its correlated bounded tokens only, never raw report content",
  "one comparison owner, and its diagnostic block never serializes a value or an exception",
  "the approved personel 203 preimage carries the canonical NULL surname",
  "a canonical NULL surname passes the approved personel 203 preimage",
  "a stale empty-string surname is a strict drift, never an equivalent of NULL",
  "the generic comparator keeps NULL and an empty string strictly distinct",
  "the personel 203 target name correction stays Muhammed / Mahmud",
];

const TOKEN_SWEEP =
  /^every produced token is bounded, prefixed, token-only and <= 100 chars \(longest \d+\)$/;

describe("final-close preimage drift attribution php runtime", () => {
  it("reports every approved-preimage drift of one run as bounded, PII-free tokens", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    const summary = result.stdout.match(
      /FINAL_CLOSE_PREIMAGE_DRIFT_ATTRIBUTION: OK checks=(\d+) tokens=(\d+) longest=(\d+)/
    );
    expect(summary, result.stdout).not.toBeNull();

    // Every check the runner counted actually printed, in order, and none failed.
    const names = (result.stdout.match(/^\[PASS\] .+$/gm) ?? []).map((line) =>
      line.slice("[PASS] ".length)
    );
    expect(names.slice(0, PASS_NAMES.length)).toEqual(PASS_NAMES);
    expect(names[PASS_NAMES.length]).toMatch(TOKEN_SWEEP);
    expect(names).toHaveLength(Number(summary![1]));
    expect(result.stdout).not.toContain("[FAIL]");

    // The bounded-shape sweep saw the real token population, worst case included.
    const tokenCount = Number(summary![2]);
    const longest = Number(summary![3]);
    expect(tokenCount).toBeGreaterThanOrEqual(20);
    expect(longest).toBeGreaterThan(0);
    expect(longest).toBeLessThanOrEqual(100);

    // No production value, id, username or SQL text reaches the surfaced output.
    for (const raw of [
      "MUHAMMED",
      "IRAKLI",
      "sinemH",
      "ilkerA",
      "gizli-kullanici",
      "987654",
      "DROP",
      "SELECT",
      "PDOException",
    ]) {
      expect(result.stdout).not.toContain(raw);
    }
  });
});
