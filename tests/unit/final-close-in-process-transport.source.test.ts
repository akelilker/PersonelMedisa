import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string) => readFileSync(resolve(process.cwd(), path), "utf8");
const transport = read("api/src/Services/Operations/FinalCloseTransport.php");
const owners = read("api/src/Services/Operations/FinalCloseOwners.php");
const snapshot = read("api/src/Services/Operations/FinalCloseSnapshot.php");
const jsonResponse = read("api/src/Http/JsonResponse.php");
const captured = read("api/src/Http/ResponseCaptured.php");
const auth = read("api/src/Auth/AuthMiddleware.php");
const childOwner = read("api/bin/final-close-owner.php");
const service = read("api/src/Services/Operations/FinalCloseService.php");

describe("final-close in-process transport portability", () => {
  it("never spawns a child process: snapshot and mutations run in-process", () => {
    // proc_open is disabled on the hosting target, so no final-close operation may
    // depend on it. Every operation goes through the in-process canonical owner.
    expect(transport).not.toContain("proc_open(");
    expect(transport).not.toContain("PHP_BINARY");
    expect(transport).not.toContain("proc_get_status");
    expect(transport).not.toContain("shell_exec");
    expect(transport).not.toContain("exec(");
    expect(transport).not.toContain("FINAL_CLOSE_PROC_OPEN_UNAVAILABLE");
    expect(transport).not.toContain("final-close-owner.php");
    expect(transport).toContain("FinalCloseOwners::invoke(");
  });

  it("keeps the bounded response mapping on the in-process path", () => {
    expect(transport).toContain("FINAL_CLOSE_OWNER_INVALID_RESPONSE");
    expect(transport).toContain("FINAL_CLOSE_OWNER_INVALID_DATA");
    expect(transport).toContain("/^[A-Z0-9_]{1,100}$/D");
  });

  it("captures the controller JsonResponse instead of emitting it (first response wins)", () => {
    expect(captured).toContain("final class ResponseCaptured extends RuntimeException");
    expect(jsonResponse).toContain("public static function beginCapture()");
    expect(jsonResponse).toContain("public static function endCapture()");
    expect(jsonResponse).toContain("public static function capturedResponse()");
    expect(jsonResponse).toContain("throw new ResponseCaptured();");
    expect(jsonResponse).toContain("if (self::$captured === null) {");
    // Normal web behaviour is untouched: emit + exit still lives in send().
    const send = jsonResponse.slice(jsonResponse.indexOf("private static function send"));
    expect(send).toContain("if (self::$capturing) {");
    expect(send).toContain("header('Content-Type: application/json; charset=utf-8');");
    expect(send).toContain("exit;");
    // Only the trusted in-process owner arms the capture scope.
    expect(owners).toContain("JsonResponse::beginCapture();");
    expect(owners).toContain("JsonResponse::capturedResponse()");
    expect(owners).toContain("JsonResponse::endCapture();");
  });

  it("keeps the compiled allowlist, cli frame guard, JWT and AuthMiddleware chain", () => {
    expect(owners).toContain("PHP_SAPI !== 'cli'");
    expect(owners).toContain("self::operations()");
    expect(owners).toContain("new FinalCloseOwnerRequest($op, $actor, $body)");
    expect(owners).toContain("AuthMiddleware::authenticate($request, true)");
    expect(owners).toContain("$controller::$method($request");
    expect(owners).toContain("FINAL_CLOSE_FRAME_INVALID");
    expect(owners).toContain("FINAL_CLOSE_OPERATION_FORBIDDEN");
    expect(owners).toContain("FINAL_CLOSE_PREIMAGE_DRIFT");
  });

  it("re-derives identity per operation in the long-lived worker", () => {
    // One worker runs actors 10/11/110; the cached user must be cleared per op.
    expect(auth).toContain("public static function forgetAuthenticatedUser()");
    expect(owners).toContain("AuthMiddleware::forgetAuthenticatedUser();");
  });

  it("returns the canonical snapshot as a response frame without exiting", () => {
    expect(owners).toContain("public static function invoke(array $frame): array");
    expect(owners).toContain("return ['data' => $snapshot, 'meta' => [], 'errors' => []];");
    const snapshotBranch = owners.slice(
      owners.indexOf("if ($op === 'snapshot') {"),
      owners.indexOf("if (!is_string($frame['expected'])")
    );
    expect(snapshotBranch).not.toContain("$controller::$method");
  });

  it("splits snapshot reads into bounded stage codes without leaking raw text", () => {
    for (const step of ["CONNECTION", "PERSONNEL_READ", "USER_READ", "ACTOR_READ", "BRANCH_READ", "POLICY_READ"]) {
      expect(snapshot).toContain(`self::read('${step}'`);
    }
    // Known bounded single-token codes stay verbatim; unknown throwables are wrapped.
    expect(snapshot).toContain("'FINAL_CLOSE_SNAPSHOT_' . $step . '_FAILED'");
    expect(snapshot).toContain("/^[A-Z][A-Z0-9_]{2,100}$/D");
    // Raw exception text is never concatenated into a surfaced value.
    expect(snapshot).not.toContain("getMessage() . ");
  });

  it("keeps the web-inaccessible CLI owner compatible with the capture contract", () => {
    expect(childOwner).toContain("PHP_SAPI !== 'cli'");
    expect(childOwner).toContain("FinalCloseOwners::invoke($frame)");
    expect(childOwner).toContain("echo json_encode($data");
    expect(childOwner).toContain("exit(0)");
  });

  it("keeps preflight read-only with zero production mutations", () => {
    expect(service).toContain("'production_mutation_count' => 0");
    expect(service).toContain("writeEvidence($preflightPath,");
    const preflightBranch = service.slice(
      service.indexOf("if ($request['mode'] === 'FINAL_CLOSE_PREFLIGHT')"),
      service.indexOf("if (!hash_equals($checksum, $request['preflight_checksum']))")
    );
    expect(preflightBranch).toContain("return $report;");
    expect(preflightBranch).not.toContain("$transport->call('identity_");
  });
});
