import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

describe("credential onboarding owners (MG-CRED-ONBOARD-001)", () => {
  it("defines migration 069 must_change_password column", () => {
    const sql = readFileSync("api/migrations/069_personel_credential_onboarding.sql", "utf8");
    expect(sql).toContain("must_change_password");
  });

  it("exposes POST /auth/change-password and login must_change_password flag", () => {
    const router = readFileSync("api/src/Router.php", "utf8");
    const login = readFileSync("api/src/Auth/LoginController.php", "utf8");
    const change = readFileSync("api/src/Auth/ChangePasswordController.php", "utf8");
    expect(router).toContain("'/auth/change-password'");
    expect(login).toContain("must_change_password");
    expect(change).toContain("must_change_password = 0");
  });

  it("sets must_change_password on admin password writes when schema present", () => {
    const yonetim = readFileSync("api/src/Controllers/YonetimController.php", "utf8");
    expect(yonetim).toContain("hasMustChangePassword");
    expect(yonetim).toContain("must_change_password = 1");
  });

  it("routes authenticated users with must_change_password to change-password page", () => {
    const route = readFileSync("src/router/ProtectedRoute.tsx", "utf8");
    expect(route).toContain("/change-password");
    expect(route).toContain("must_change_password");
  });
});
