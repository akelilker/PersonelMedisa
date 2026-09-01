import { existsSync, readFileSync, readdirSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("initial password owner", () => {
  it("derives the initial password with the Tasit rule (real PHP)", () => {
    const result = spawnSync("php", [resolve(process.cwd(), "tests/php/InitialPasswordPureTestRunner.php")], {
      encoding: "utf8",
      cwd: process.cwd(),
      env: process.env,
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("INITIAL_PASSWORD_TASIT_PARITY=PASS");
  });

  it("owns derivation from the name alone, with no config or shared secret", () => {
    const owner = read("api/src/Auth/InitialPassword.php");
    // Tasit transliteration table and ASCII token rule.
    expect(owner).toContain("'ğ' => 'g'");
    expect(owner).toContain("'İ' => 'I'");
    expect(owner).toContain("/[^A-Za-z0-9]/");
    // Password comes from the surname token plus the fixed suffix.
    expect(owner).toContain("const SUFFIX = '123'");
    expect(owner).toContain("$parts[count($parts) - 1]");
    // No config, no secret, no placeholder anywhere in the owner.
    expect(owner).not.toContain("medisa_config");
    expect(owner).not.toContain("CHANGE_ME");
    expect(owner).not.toContain("standard_initial_password_hash");
    // Only a hash ever leaves the owner.
    expect(owner).toContain("return PasswordHasher::hash($password);");
  });

  it("fails closed when the stored name cannot produce a password", () => {
    const owner = read("api/src/Auth/InitialPassword.php");
    expect(owner).toContain("INITIAL_PASSWORD_NAME_INVALID");
    expect(owner).toContain("JsonResponse::badRequest(");
  });

  it("creates users with the derived initial password and forces a change", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).toContain("use Medisa\\Api\\Auth\\InitialPassword;");
    expect(yonetim).toContain("InitialPassword::requireHashForName($adSoyad)");
    expect(yonetim).not.toContain("Sifre zorunludur.");
    expect(yonetim).not.toContain("password_hash($password, PASSWORD_BCRYPT)");
    expect(yonetim).toContain("PasswordHasher::hash($password)");
    expect(yonetim).toContain("must_change_password = 1");
  });

  it("resolves the reset name from stored state, never from the request", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).toContain("resolveStoredAdSoyadForInitialPassword($pdo, $existing)");
    // Bound personnel record wins, stored user name is the fallback.
    expect(yonetim).toContain("loadPersonelAdSoyadByIds($pdo, [$existing])");
    expect(yonetim).toContain("trim((string) ($existing['ad_soyad'] ?? ''))");
  });

  it("exposes the reset intent as a boolean-only flag", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).toContain("parseInitialPasswordResetIntent");
    expect(yonetim).toContain("'baslangic_sifresine_sifirla'");
    expect(yonetim).toContain("if (!is_bool($value))");
    // Reset and an explicit password can never be combined in one request.
    expect(yonetim).toContain("$password !== '' && $resetToInitial");
  });

  it("routes every explicit admin password write through PasswordPolicy", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    const policyAsserts = yonetim.match(/PasswordPolicy::assertValidNewPassword/g) ?? [];
    expect(policyAsserts.length).toBeGreaterThanOrEqual(2);
  });

  it("keeps the user-chosen password policy separate from the derived shape", () => {
    const policy = read("api/src/Auth/PasswordPolicy.php");
    expect(policy).toContain("FORBIDDEN_PASSWORDS");
    expect(policy).toContain("'demo123'");
    expect(policy).toContain("MIN_LENGTH = 8");
    // The derived initial password has its own Tasit shape check.
    expect(read("api/src/Auth/InitialPassword.php")).toContain("MIN_LENGTH = 6");
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

  it("leaves no trace of the withdrawn shared-secret model", () => {
    expect(existsSync(resolve("api/src/Auth/StandardInitialPassword.php"))).toBe(false);
    expect(existsSync(resolve("scripts/ops/standard-initial-password-config-ops.php"))).toBe(false);
    expect(existsSync(resolve(".github/workflows/set-cpanel-standard-initial-password-hash.yml"))).toBe(false);

    for (const path of [
      "api/src/Config/config.example.php",
      "api/src/Controllers/YonetimController.php",
      "DEPLOY_CHECKLIST.md",
      "src/api/yonetim.api.ts",
      "src/api/mock-demo.ts",
      "src/features/yonetim/pages/YonetimPaneliPage.tsx",
      "src/lib/yonetim/kullanici-api-contract.ts",
    ]) {
      const source = read(path);
      expect(source, path).not.toContain("standard_initial_password_hash");
      expect(source, path).not.toContain("STANDARD_INITIAL_PASSWORD_HASH");
      expect(source, path).not.toContain("StandardInitialPassword");
      expect(source, path).not.toContain("demo123");
      // Unrelated CHANGE_ME placeholders (db, jwt, qr) stay; no password one may return.
      expect(source, path).not.toMatch(/CHANGE_ME\w*(INITIAL|STANDARD)/i);
    }
  });

  it("keeps no shared initial-password secret in any workflow", () => {
    const workflowDir = resolve(".github/workflows");
    for (const name of readdirSync(workflowDir)) {
      const source = readFileSync(resolve(workflowDir, name), "utf8");
      expect(source, name).not.toContain("STANDARD_INITIAL_PASSWORD_HASH");
      expect(source, name).not.toContain("standard_initial_password_hash");
    }
  });

  it("adds no new migration for this change", () => {
    const migrations = readdirSync(resolve("api/migrations")).filter((name) => name.endsWith(".sql"));
    const highest = migrations
      .map((name) => Number.parseInt(name.slice(0, 3), 10))
      .filter((value) => Number.isFinite(value))
      .sort((left, right) => right - left)[0];
    expect(highest).toBe(82);
  });
});
