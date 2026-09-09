import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string) => readFileSync(resolve(process.cwd(), path), "utf8");
const owners = read("api/src/Services/Operations/FinalCloseOwners.php");
const pkg = read("api/src/Services/Operations/FinalClosePackage.php");
const service = read("api/src/Services/Operations/FinalCloseService.php");

describe("bounded final-close canonical mutation path", () => {
  it("routes physical-location writes through the audited organization owner", () => {
    expect(owners).toContain("$method = 'organizasyonDegisikligi'");
    expect(owners).toContain("'preimage' => ['calisma_lokasyonu_id' => null]");
    expect(owners).toContain("'targets' => ['calisma_lokasyonu_id' => 5]");
  });

  it("keeps the approved manager allowlist exact and Kayseri deferred", () => {
    expect(pkg).toContain("public const MANAGERS = [1 => 110, 2 => 50, 5 => 110, 6 => 110, 12 => 50, 13 => 110]");
    expect(pkg).not.toMatch(/MANAGERS\s*=\s*\[[^\]]*4\s*=>/s);
  });

  it("keeps identity and A1 dependency chains fail-closed while independent groups may continue", () => {
    expect(service).toContain("FINAL_CLOSE_IDENTITY_SCOPE_DEPENDENCY_BLOCKED");
    expect(service).toContain("FINAL_CLOSE_IDENTITY_CREATE_DEPENDENCY_BLOCKED");
    expect(service).toContain("FINAL_CLOSE_IDENTITY_VERIFY_DEPENDENCY_BLOCKED");
    expect(service).toContain("FINAL_CLOSE_A1_PREREQUISITE_BLOCKED");
    expect(service).toContain("FINAL_CLOSE_POLICY_IMPORT_DEPENDENCY_BLOCKED");
    expect(service).toContain("FINAL_CLOSE_POLICY_SUBMIT_DEPENDENCY_BLOCKED");
  });

  it("uses the approved actors and contains no direct SQL mutation owner", () => {
    expect(owners).toContain("$actor = 10");
    expect(owners).toContain("$actor = strpos($op, 'policy_approve') === 0 ? 110 : 11");
    expect(owners).not.toMatch(/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b\s+/i);
  });
});
