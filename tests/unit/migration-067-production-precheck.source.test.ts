import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const endpointPath = resolve(process.cwd(), "api/public/_migration_067_ops.php");
const workflowPath = resolve(
  process.cwd(),
  ".github/workflows/migration-067-production-precheck.yml"
);
const driverPath = resolve(
  process.cwd(),
  "scripts/migration-067-production-precheck.php"
);
const endpoint = readFileSync(endpointPath, "utf8");
const workflow = readFileSync(workflowPath, "utf8");
const driver = readFileSync(driverPath, "utf8");
const precheck = endpoint.slice(
  endpoint.indexOf("function migration_067_precheck"),
  endpoint.indexOf("if (($_SERVER['REQUEST_METHOD']")
);

describe("Migration 067 production ops contract", () => {
  it("fails closed for invalid or missing runtime tokens", () => {
    expect(endpoint).toContain("hash_equals($expected, $provided)");
    expect(endpoint).toContain("MIGRATION_067_TOKEN_PLACEHOLDER");
    expect(endpoint).toContain("migration_067_fail('FORBIDDEN', 403)");
  });

  it("guards production and the exact database before operations", () => {
    expect(endpoint).toContain("($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'");
    expect(endpoint).toContain("MIGRATION_067_EXPECTED_DATABASE = 'karmotor_medisa'");
    expect(endpoint).toContain("SELECT DATABASE()");
    expect(endpoint).toContain("DATABASE_TARGET_GUARD_FAILED");
    expect(endpoint).toContain("hash_equals(MIGRATION_067_SOURCE_SHA256");
  });

  it("binds the driver and endpoint to the unchanged migration source", () => {
    const hash =
      "afa8e99867b9c670af9f8ab84a814d72231f602fa2cd01e3f8d73c06cdb8c5b9";
    expect(endpoint).toContain(hash);
    expect(driver).toContain(hash);
    expect(workflow).toContain("ref: ${{ github.sha }}");
    expect(endpoint).toContain("REPLACE_MIGRATION_067_SOURCE_FILE");
    expect(workflow).toContain("--verify-source");
  });

  it("keeps precheck SELECT-only and excludes personnel identities", () => {
    expect(precheck).toMatch(/SELECT DATABASE\(\)/);
    expect(precheck).toMatch(/SELECT COUNT\(\*\)/);
    expect(precheck).not.toMatch(/\b(UPDATE|INSERT|DELETE|ALTER|CREATE|DROP)\b/i);
    expect(precheck).not.toMatch(/\b(tc_kimlik|sicil|ad_soyad|telefon|email|e_posta)\b/i);
    expect(endpoint).toContain("SCHEMA_066_FINGERPRINT");
    expect(endpoint).toContain("LEGACY_EXACT");
    expect(endpoint).toContain("CANONICAL_EXACT");
    expect(endpoint).toContain("BELOW_066_OR_DRIFT");
    expect(endpoint).toContain(": 'DRIFT'");
  });

  it("validates a full SQL dump outside the webroot", () => {
    expect(endpoint).toContain("karmotor_medisa_pre_067_");
    expect(endpoint).toContain("CREATE TABLE");
    expect(endpoint).toContain("SHOW CREATE TABLE");
    expect(endpoint).toContain("hash_file('sha256', $path)");
    expect(endpoint).toContain("BACKUP_PATH_INSIDE_WEBROOT");
    expect(endpoint).toContain("BACKUP_VALIDATION_FAILED");
    expect(endpoint).not.toContain("'method' => 'inventory_json'");
    expect(endpoint).toContain("backup_location_class' => 'OUTSIDE_WEBROOT'");
    expect(endpoint).toContain("BACKUP_FALLBACK_UNSUPPORTED_OBJECTS");
    expect(endpoint).toContain("BACKUP_MYSQLDUMP_FAILED");
    expect(endpoint).toContain("migration_067_assert_php_fallback_supported");
  });

  it("has no apply action and always retires the endpoint", () => {
    expect(endpoint).not.toMatch(/['"]apply['"]/i);
    expect(workflow).toContain("if: always()");
    expect(workflow).toContain("rm -f _migration_067_ops.php");
    expect(workflow).toContain('test "$code" = "404" || test "$code" = "410"');
    expect(workflow).toContain("set ssl:verify-certificate yes;");
    expect(workflow).toContain("set ssl:check-hostname yes;");
    expect(workflow).not.toContain("upload true || upload false");
    expect(workflow).not.toContain("set ssl:verify-certificate no;");
    expect(workflow).not.toContain("path: /tmp/migration-067-${{ inputs.mode }}.sql");
    expect(workflow).toContain("GITHUB_RUN_ID");
    expect(workflow).toContain('rm -f "${SOURCE_FILE}"');
    expect(workflow).not.toContain("workflow_run:");
    expect(workflow).not.toContain("pull_request:");
    expect(workflow).not.toMatch(/^\s+push:/m);
  });
});
