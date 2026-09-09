import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string) => readFileSync(resolve(process.cwd(), path), "utf8");
const transport = read("api/src/Services/Operations/FinalCloseTransport.php");
const owners = read("api/src/Services/Operations/FinalCloseOwners.php");
const childOwner = read("api/bin/final-close-owner.php");
const service = read("api/src/Services/Operations/FinalCloseService.php");

describe("final-close in-process transport portability", () => {
  it("runs the read-only snapshot in-process before any proc_open gate", () => {
    // The snapshot branch must bypass subprocess spawning entirely, so preflight
    // works where proc_open is disabled. The mutation gate stays after it.
    expect(transport.indexOf("if ($operation === 'snapshot')")).toBeGreaterThanOrEqual(0);
    expect(transport.indexOf("return $this->callSnapshotInProcess();")).toBeGreaterThan(
      transport.indexOf("if ($operation === 'snapshot')")
    );
    expect(transport.indexOf("FINAL_CLOSE_PROC_OPEN_UNAVAILABLE")).toBeGreaterThan(
      transport.indexOf("if ($operation === 'snapshot')")
    );
    expect(transport).toContain("callSnapshotInProcess(): array");
    expect(transport).not.toContain("shell_exec");
    expect(transport).not.toContain("exec(");
  });

  it("keeps proc_open only as the fail-closed gate for mutation operations", () => {
    // Mutations keep their isolated child process; without proc_open they still
    // fail closed instead of running HTTP controllers in-process.
    expect(transport).toContain("FINAL_CLOSE_PROC_OPEN_UNAVAILABLE");
    expect(transport).toContain("proc_open([PHP_BINARY");
    const inProcess = transport.slice(transport.indexOf("private function callSnapshotInProcess"));
    expect(inProcess).not.toContain("proc_open");
  });

  it("returns the canonical snapshot array without JsonResponse/exit in-process", () => {
    expect(owners).toContain("public static function invoke(array $frame): ?array");
    expect(owners).toContain("if ($op === 'snapshot') {");
    expect(owners).toContain("return $snapshot;");
    // The read-only branch must never emit an HTTP response or reach a mutation owner.
    const snapshotBranch = owners.slice(owners.indexOf("if ($op === 'snapshot') {"), owners.indexOf("if (!is_string($frame['expected'])"));
    expect(snapshotBranch).not.toContain("JsonResponse");
    expect(snapshotBranch).not.toContain("$controller::$method");
    expect(owners).not.toContain("JsonResponse");
  });

  it("frames returned snapshot data identically in the child wrapper", () => {
    expect(childOwner).toContain("FinalCloseOwners::invoke($frame)");
    expect(childOwner).toContain("json_encode(['data' => $data, 'meta' => [], 'errors' => []]");
    expect(childOwner).toContain("exit(0)");
    expect(childOwner).toContain("FINAL_CLOSE_OWNER_RESPONSE_MISSING");
  });

  it("keeps bounded failure mapping on the in-process and child paths", () => {
    expect(transport).toContain("FINAL_CLOSE_OWNER_INVALID_DATA");
    expect(transport).toContain("/^[A-Z0-9_]{1,100}$/D");
  });

  it("keeps preflight read-only with zero production mutations", () => {
    expect(service).toContain("'production_mutation_count' => 0");
    expect(service).toContain("writeEvidence($preflightPath,");
    // The preflight branch returns before the fresh-checksum/apply gate.
    const preflightBranch = service.slice(
      service.indexOf("if ($request['mode'] === 'FINAL_CLOSE_PREFLIGHT')"),
      service.indexOf("if (!hash_equals($checksum, $request['preflight_checksum']))")
    );
    expect(preflightBranch).toContain("return $report;");
    expect(preflightBranch).not.toContain("$transport->call('identity_");
  });
});
