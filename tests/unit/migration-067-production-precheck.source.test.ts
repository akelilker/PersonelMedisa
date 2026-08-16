import { describe, expect, it } from "vitest";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

const endpointPath = resolve(process.cwd(), "api/public/_migration_067_ops.php");
const templatePath = resolve(
  process.cwd(),
  "scripts/ops/migration-067-ops.template.php"
);
const workflowPath = resolve(
  process.cwd(),
  ".github/workflows/migration-067-production-precheck.yml"
);
const deployWorkflowPath = resolve(
  process.cwd(),
  ".github/workflows/deploy-cpanel.yml"
);
const driverPath = resolve(
  process.cwd(),
  "scripts/migration-067-production-precheck.php"
);
const endpoint = readFileSync(templatePath, "utf8");
const workflow = readFileSync(workflowPath, "utf8");
const deployWorkflow = readFileSync(deployWorkflowPath, "utf8");
const driver = readFileSync(driverPath, "utf8");
const precheck = endpoint.slice(
  endpoint.indexOf("function migration_067_precheck"),
  endpoint.indexOf("if (($_SERVER['REQUEST_METHOD']")
);

describe("Migration 067 production ops contract", () => {
  it("fails closed for invalid or missing runtime tokens", () => {
    expect(endpoint).toContain("hash_equals($expected, $provided)");
    expect(endpoint).toContain("MIGRATION_067_RUNTIME_TOKEN_B64");
    expect(endpoint).toContain("base64_decode($encoded, true)");
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
    expect(endpoint).toContain("REPLACE_MIGRATION_067_SOURCE_FILE_B64");
    expect(workflow).toContain("--verify-source");
  });

  it("keeps the live-capable endpoint out of normal cPanel deployment ownership", () => {
    expect(existsSync(endpointPath)).toBe(false);
    expect(workflow).toContain("scripts/ops/migration-067-ops.template.php");
    expect(workflow).toContain("REMOTE_ENDPOINT_PATH: api/public/_migration_067_ops.php");
    expect(workflow).toContain("PUBLIC_ENDPOINT_URL: https://www.karmotors.com.tr/personelmedisa/api/public/_migration_067_ops.php");
    expect(workflow).toContain('put -o "${REMOTE_ENDPOINT_DIR}/${ENDPOINT_TEMP}"');
    expect(workflow).toContain('mv "${REMOTE_ENDPOINT_DIR}/${ENDPOINT_TEMP}" "${REMOTE_ENDPOINT_PATH}"');
    expect(workflow).toContain('rm -f "${REMOTE_ENDPOINT_PATH}"');
    expect(workflow).toContain("base64.b64encode");
    expect(workflow).not.toContain("REPLACE_MIGRATION_067_OPS_TOKEN\", token");
    expect(deployWorkflow).toContain("mirror -R --verbose api/public api/public");
    expect(deployWorkflow).not.toContain("_migration_067_ops.php");
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
    expect(endpoint).toContain("BACKUP_PATH_NOT_PERSISTENT_PRIVATE");
    expect(endpoint).toContain("BACKUP_ROOT_REQUIRED");
    expect(endpoint).toContain("sys_get_temp_dir()");
    expect(endpoint).toContain("backup_location_class' => 'OUTSIDE_WEBROOT_PERSISTENT'");
    expect(endpoint).not.toContain("personelmedisa-migration-067");
    expect(endpoint).toContain("BACKUP_VALIDATION_FAILED");
    expect(endpoint).not.toContain("'method' => 'inventory_json'");
    expect(endpoint).toContain("backup_location_class' => 'OUTSIDE_WEBROOT_PERSISTENT'");
    expect(endpoint).toContain("CREATE TRIGGER");
    expect(endpoint).toContain("CREATE VIEW");
    expect(endpoint).toContain("DELIMITER $$");
    expect(endpoint).toContain("'SHOW CREATE ' . $routineType");
    expect(endpoint).toContain("SHOW CREATE EVENT");
    expect(endpoint).toContain("migration_067_backup_inventory");
    expect(endpoint).toContain("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    expect(endpoint).toContain("START TRANSACTION WITH CONSISTENT SNAPSHOT");
    expect(endpoint).toContain("BACKUP_FALLBACK_UNSUPPORTED_ENGINE");
    expect(endpoint).toContain("VIEW_DEPENDENCY_METADATA_UNAVAILABLE");
    expect(endpoint).toContain("VIEW_CROSS_DATABASE_DEPENDENCY");
    expect(endpoint).toContain("VIEW_DEPENDENCY_UNRESOLVED");
    expect(endpoint).toContain("if ($transactionStarted && $pdo->inTransaction())");
    expect(endpoint).toContain("--single-transaction");
    expect(endpoint).toContain("'backup_consistency'");
    expect(endpoint).toContain("'backup_engine_guard'");
  });

  it("has no apply action and always retires the endpoint", () => {
    expect(endpoint).not.toMatch(/['"]apply['"]/i);
    expect(workflow).toContain("if: always()");
    expect(workflow).toContain("if: always()");
    expect(workflow).toContain("REMOTE_ENDPOINT_ABSENT = YES");
    expect(workflow).toContain("REMOTE_SOURCE_ABSENT = YES");
    expect(workflow).toContain('retirement_http = 401_ROUTER_MASK');
    expect(workflow).toContain('[[ "$code" = "404" || "$code" = "410" ]]');
    expect(workflow).toContain("set ssl:verify-certificate yes;");
    expect(workflow).toContain("set ssl:check-hostname yes;");
    expect(workflow).not.toContain("upload true || upload false");
    expect(workflow).not.toContain("set ssl:verify-certificate no;");
    expect(workflow).not.toContain("path: /tmp/migration-067-${{ inputs.mode }}.sql");
    expect(workflow).toContain("GITHUB_RUN_ID");
    expect(workflow).toContain('rm -f "api/migrations/${SOURCE_FILE}"');
    expect(workflow).not.toContain("workflow_run:");
    expect(workflow).not.toContain("pull_request:");
    expect(workflow).not.toMatch(/^\s+push:/m);
    expect(workflow).toContain('test "${GITHUB_REF}" = "refs/heads/main"');
    expect(workflow).toContain('test "$MAIN_SHA" = "${GITHUB_SHA}"');
  });

  it("uses the physical endpoint and fails closed on exact remote path state", () => {
    expect(workflow).toContain("Verify exact remote paths absent before rendering");
    expect(workflow).toContain("quote SIZE ${path};");
    expect(workflow).toContain("STALE_MIGRATION_067_ENDPOINT_PRESENT");
    expect(workflow).toContain("STALE_MIGRATION_067_SOURCE_PRESENT");
    expect(workflow).toContain("FTP_CHECK_FAILED");
    expect(workflow).not.toContain("/personelmedisa/api/_migration_067_ops.php");
    expect(workflow).toContain('"$PUBLIC_ENDPOINT_URL"');
  });

  it("binds, validates, and sanitizes a run-bound endpoint identity", () => {
    expect(endpoint).toContain("MIGRATION_067_INSTANCE_ID_B64");
    expect(endpoint).toContain("ops_instance_id");
    expect(endpoint).toContain("INSTANCE_ID_INVALID");
    expect(workflow).toContain("OPS_INSTANCE_ID=");
    expect(workflow).toContain("--expected-instance-id");
    expect(driver).toContain("Endpoint instance identity mismatch.");
    expect(driver).toContain("'ops_instance_id'");
    expect(endpoint).not.toMatch(/['"]identity['"]/i);
    expect(endpoint).not.toMatch(/['"]status['"]/i);
  });
});
