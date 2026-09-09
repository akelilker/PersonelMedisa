import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const worker = readFileSync(resolve(process.cwd(), "api/bin/cpanel-migration-cron.php"), "utf8");

describe("cPanel migration worker bounded-reason contract", () => {
  it("surfaces any bounded single-token owner code instead of UNKNOWN", () => {
    // FINAL_CLOSE_*/BACKUP_* and other domain codes are single uppercase tokens;
    // the classifier must return them verbatim and never leak free-text messages.
    expect(worker).toContain("preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $message) === 1");
    expect(worker).toContain("return $message;");
    // The safe fallback still exists for genuinely unbounded (PDO/PII) messages.
    expect(worker).toContain("UNKNOWN_MIGRATION_FAILURE");
  });

  it("records detail only for bounded single-token codes, never raw message text", () => {
    expect(worker).toContain(
      "elseif (preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $exception->getMessage()) === 1) {"
    );
    // The bounded regex is the only gate: raw free-text (PDO/PII) messages cannot match.
    expect(worker).toContain("Bounded single-token code only: never raw PDO/PII message text.");
    const classifier = worker.slice(
      worker.indexOf("function classifyWorkerFailure"),
      worker.indexOf("function writeStatus(")
    );
    // The bounded guard must run before the UNKNOWN fallback.
    expect(classifier.indexOf("preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $message)")).toBeLessThan(
      classifier.indexOf("'UNKNOWN_MIGRATION_FAILURE'")
    );
  });
});
