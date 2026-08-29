import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("standard initial password owner", () => {
  it("owns the config key and fails closed on a missing or placeholder hash", () => {
    const owner = read("api/src/Auth/StandardInitialPassword.php");
    expect(owner).toContain("const CONFIG_KEY = 'standard_initial_password_hash'");
    expect(owner).toContain("const ERROR_CODE = 'STANDARD_INITIAL_PASSWORD_NOT_CONFIGURED'");
    expect(owner).toContain("CHANGE_ME");
    // Only a real bcrypt hash is accepted.
    expect(owner).toContain("\\$2[aby]\\$");
    expect(owner).toContain("JsonResponse::error(");
  });

  it("keeps only a placeholder key in the committed config example", () => {
    const example = read("api/src/Config/config.example.php");
    expect(example).toContain("'standard_initial_password_hash' => 'CHANGE_ME");
    expect(example).not.toContain("$2y$");
    expect(example).not.toContain("demo123");
  });

  it("creates users with the standard initial hash and no admin plaintext requirement", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).toContain("use Medisa\\Api\\Auth\\StandardInitialPassword;");
    expect(yonetim).toContain("StandardInitialPassword::requireHash()");
    expect(yonetim).not.toContain("Sifre zorunludur.");
    // Inline hashing is gone; PasswordHasher/StandardInitialPassword are the only writers.
    expect(yonetim).not.toContain("password_hash($password, PASSWORD_BCRYPT)");
    expect(yonetim).toContain("PasswordHasher::hash($password)");
    expect(yonetim).toContain("must_change_password = 1");
  });

  it("routes every admin password write through PasswordPolicy", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    const policyAsserts = yonetim.match(/PasswordPolicy::assertValidNewPassword/g) ?? [];
    expect(policyAsserts.length).toBeGreaterThanOrEqual(2);
  });

  it("exposes the reset intent as a boolean-only flag", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).toContain("parseStandardInitialPasswordResetIntent");
    expect(yonetim).toContain("'standart_baslangic_sifresine_sifirla'");
    expect(yonetim).toContain("if (!is_bool($value))");
    // Reset and explicit password can never be combined in one request.
    expect(yonetim).toContain("$password !== '' && $resetToStandardInitial");
  });

  it("rejects known demo/seed passwords in the canonical policy", () => {
    const policy = read("api/src/Auth/PasswordPolicy.php");
    expect(policy).toContain("FORBIDDEN_PASSWORDS");
    expect(policy).toContain("'demo123'");
    expect(policy).toContain("MIN_LENGTH = 8");
  });

  it("keeps must_change_password fail-closed in the auth middleware", () => {
    const middleware = read("api/src/Auth/AuthMiddleware.php");
    expect(middleware).toContain("PASSWORD_CHANGE_REQUIRED");
    expect(middleware).toContain("JsonResponse::error(403, 'PASSWORD_CHANGE_REQUIRED'");
  });

  it("never returns a password or hash from the admin API", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).not.toMatch(/JsonResponse::success\([^)]*password_hash/);
  });

  it("owns production config provisioning without exposing the hash", () => {
    const ops = read("scripts/ops/standard-initial-password-config-ops.php");
    const workflow = read(".github/workflows/set-cpanel-standard-initial-password-hash.yml");

    // The value only ever travels through a private file, never argv or output.
    expect(ops).toContain("--hash-file=");
    expect(ops).toContain("readHashFile");
    expect(ops).toContain("STANDARD_INITIAL_PASSWORD_HASH_CONFIGURED=");
    expect(ops).not.toMatch(/echo\s+\$hash/);
    expect(ops).toContain("UNRELATED_CONFIG_KEYS_CHANGED");

    expect(workflow).toContain("secrets.STANDARD_INITIAL_PASSWORD_HASH");
    expect(workflow).toContain("REMOTE_CONFIG_PATH: api/config.local.php");
    expect(workflow).toContain("SET_STANDARD_INITIAL_PASSWORD_HASH");
    expect(workflow).toContain("HASH_EXPOSED=NO");
    expect(workflow).toContain("PLAINTEXT_EXPOSED=NO");
    // The secret must never be interpolated into a workflow_dispatch input.
    expect(workflow).not.toContain("inputs.hash");
  });

  it("adds no new migration for this change", () => {
    const migrations = readdirSync(resolve("api/migrations")).filter((name) => name.endsWith(".sql"));
    const highest = migrations
      .map((name) => Number.parseInt(name.slice(0, 3), 10))
      .filter((value) => Number.isFinite(value))
      .sort((left, right) => right - left)[0];
    expect(highest).toBe(78);
  });
});
