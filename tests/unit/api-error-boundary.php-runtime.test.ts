import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/ApiErrorBoundaryTestRunner.php");

function runPhpRunner(path: string) {
  return spawnSync("php", ["-d", "display_errors=0", path], {
    cwd: process.cwd(),
    encoding: "utf8",
    env: process.env
  });
}

describe("canonical API error boundary", () => {
  it("answers uncaught throwables and shutdown fatals without leaking detail", () => {
    const result = runPhpRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-api-error-boundary: OK");
  });
});

describe("API error boundary source contracts", () => {
  const boundary = readFileSync("api/src/Http/ErrorBoundary.php", "utf8");

  it("covers both uncaught throwables and shutdown fatals", () => {
    expect(boundary).toContain("set_exception_handler");
    expect(boundary).toContain("register_shutdown_function");
    expect(boundary).toContain("E_COMPILE_ERROR");
    expect(boundary).toContain("E_PARSE");
  });

  it("keeps the response generic and the detail in the log", () => {
    expect(boundary).toContain("'INTERNAL_SERVER_ERROR'");
    expect(boundary).toContain("error_log");
    // The response builder must never reference file, line or message.
    const respond = boundary.slice(
      boundary.indexOf("private static function respond("),
      boundary.indexOf("private static function newErrorId(")
    );
    expect(respond).toContain("error_id");
    expect(respond).not.toContain("getFile");
    expect(respond).not.toContain("getLine");
    expect(respond).not.toContain("getMessage");
    expect(respond).not.toContain("trace");
  });

  it("never logs the query string or trace arguments", () => {
    expect(boundary).toContain("requestPathWithoutQuery");
    expect(boundary).not.toContain("QUERY_STRING");
    expect(boundary).not.toContain("getTraceAsString");
    expect(boundary).not.toContain("$frame['args']");
    expect(boundary).not.toContain("HTTP_AUTHORIZATION");
  });
});
