import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("personel secure onboarding backend source contracts", () => {
  it("adds migration 075 as tip after 074 with safe defaults and no username rewrite", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((f) => /^\d+_.*\.sql$/.test(f))
      .sort();
    expect(migrations[migrations.length - 1]).toBe("075_personel_account_activation.sql");
    const sql = read("api/migrations/075_personel_account_activation.sql");
    expect(sql).toContain("activation_required");
    expect(sql).toContain("activated_at_utc");
    expect(sql).toContain("personel_account_activation_invitations");
    expect(sql).toContain("token_hash");
    expect(sql).toContain("personel_account_onboarding_audit");
    expect(sql).not.toContain("username_source");
    expect(sql).not.toContain("SICIL_CANONICAL");
    expect(sql).not.toMatch(/UPDATE\s+users\s+SET\s+username/i);
    expect(sql).not.toMatch(/INSERT\s+INTO\s+users/i);
    expect(sql).not.toMatch(/INSERT\s+INTO\s+personel_account_activation/i);
  });

  it("registers activation and onboarding routes", () => {
    const router = read("api/src/Router.php");
    expect(router).toContain("/auth/personel-activation/status");
    expect(router).toContain("/auth/personel-activation/complete");
    expect(router).toContain("hesap-onboarding");
    expect(router).toContain("aktivasyon-yenile");
    expect(router).toContain("PersonelActivationController");
    expect(router).toContain("PersonelAccountOnboardingController");
  });

  it("login denies activation_required with opaque INVALID_CREDENTIALS", () => {
    const login = read("api/src/Auth/LoginController.php");
    expect(login).toContain("hasActivationRequired");
    expect(login).toContain("activation_required");
    expect(login).toMatch(/activation_required[\s\S]*INVALID_CREDENTIALS/);
  });

  it("rejects generic PERSONEL+personel_id create via secure onboarding owner", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    const service = read("api/src/Services/Auth/PersonelAccountOnboardingService.php");
    expect(yonetim).toContain("rejectGenericPersonelBoundCreate");
    expect(service).toContain("PERSONEL_USE_SECURE_ONBOARDING");
    expect(service).toContain("PERSONEL_NAME_REQUIRED_FOR_ACCOUNT");
    expect(service).toContain("PERSONEL_USERNAME_COLLISION");
    expect(service).toContain("ALREADY_PROVISIONED");
    expect(service).toContain("buildPersonelUsernameFromNames");
    expect(service).not.toContain("SICIL_CANONICAL");
    expect(service).not.toContain("PERSONEL_SICIL_REQUIRED_FOR_ACCOUNT");
    expect(service).not.toContain("PERSONEL_SICIL_USERNAME_COLLISION");
    expect(service).not.toContain("syncUsernameFromSicilIfApplicable");
    expect(service).not.toContain("username_source");
    expect(service).toContain("generateActivationToken");
    expect(service).toContain("random_bytes(32)");
    expect(service).toContain("hash('sha256'");
    expect(service).toContain("personel_activation_ttl_minutes");
    expect(service).toContain("app_public_url");
    expect(service).toContain("#token=");
    expect(service).toContain("FOR UPDATE");
    expect(service).not.toMatch(/console\.log/);
  });

  it("centralizes password policy for change-password and activation", () => {
    const policy = read("api/src/Auth/PasswordPolicy.php");
    const change = read("api/src/Auth/ChangePasswordController.php");
    const activation = read("api/src/Auth/PersonelActivationController.php");
    expect(policy).toContain("MIN_LENGTH = 8");
    expect(change).toContain("PasswordPolicy::assertValidNewPassword");
    expect(activation).toContain("completeActivation");
    expect(activation).toContain("array_key_exists('user_id'");
    expect(activation).toContain("array_key_exists('personel_id'");
    expect(activation).toContain("array_key_exists('username'");
    expect(activation).toContain("array_key_exists('rol'");
  });

  it("never persists raw activation token or returns internal password", () => {
    const service = read("api/src/Services/Auth/PersonelAccountOnboardingService.php");
    expect(service).toContain("token_hash");
    expect(service).toContain("generateUnusableInternalSecret");
    expect(service).toMatch(/\$internalSecret\s*=\s*null/);
    expect(service).toContain("$detail['token']");
    expect(service).toContain("$detail['activation_url']");
    expect(service).not.toMatch(/INSERT[\s\S]{0,80}raw_token/);
  });

  it("config owns app_public_url and activation TTL", () => {
    const example = read("api/src/Config/config.example.php");
    expect(example).toContain("'app_public_url'");
    expect(example).toContain("'personel_activation_ttl_minutes' => 1440");
  });

  it("personel update does not sync username from sicil", () => {
    const controller = read("api/src/Controllers/PersonellerController.php");
    expect(controller).not.toContain("syncUsernameFromSicilIfApplicable");
  });

  it("security scan: no unsafe token patterns in intended owners", () => {
    const roots = [
      "api/src/Services/Auth/PersonelAccountOnboardingService.php",
      "api/src/Auth/PersonelActivationController.php",
      "api/src/Controllers/PersonelAccountOnboardingController.php",
      "src/features/auth/pages/PersonelAktivasyonPage.tsx",
      "src/features/auth/personel-aktivasyon-token.ts",
      "src/features/yonetim/components/PersonelHesapOnboardingPanel.tsx",
      "src/api/personel-activation.api.ts"
    ];
    for (const path of roots) {
      const src = read(path);
      expect(src).not.toMatch(/console\.log\([^)]*token/i);
      expect(src).not.toMatch(/localStorage\.[gs]etItem\([^)]*token/i);
      expect(src).not.toMatch(/sessionStorage\.[gs]etItem\([^)]*token/i);
      expect(src).not.toMatch(/\?token=/);
    }
  });
});
