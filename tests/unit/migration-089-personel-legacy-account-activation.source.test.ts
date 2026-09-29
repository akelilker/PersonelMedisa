import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), "utf8");

const migration = read("api/migrations/089_personel_legacy_account_activation.sql");
const statements = migration.replace(/--[^\n]*\n/g, "\n");
const update = statements.match(/UPDATE\s+users[\s\S]*?;/i)?.[0] ?? "";

describe("migration 089: PERSONEL legacy hesap canonical hizalamasi", () => {
  it("canonical zincirin tipi olur ve tek dosyadir", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((name) => /^\d+_.*\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("094_attendance_no_event_day.sql");
    expect(migrations.filter((name) => name.startsWith("089_"))).toHaveLength(1);
  });

  it("yalniz hedef cohort'u canonical aktivasyon state'ine alir", () => {
    expect(update).toBeTruthy();
    expect(update).toContain("activation_required = 1");
    expect(update).toContain("must_change_password = 1");

    const [setClause, whereClause] = update.split(/WHERE/i);
    expect(setClause).not.toContain("username");
    expect(setClause).not.toContain("personel_id");
    expect(setClause).not.toContain("durum");
    expect(setClause).not.toContain("password_hash");
    expect(setClause).not.toContain("ad_soyad");
    expect(setClause).not.toContain("rol");
    expect(setClause).not.toContain("sube");

    expect(whereClause).toContain("rol = 'PERSONEL'");
    expect(whereClause).toContain("activation_required = 0");
    expect(whereClause).toMatch(/activated_at_utc\s+IS\s+NULL/i);
  });

  it("idempotent: ikinci calistirmada hedef satir kalmaz", () => {
    expect(update).toMatch(/activation_required\s*=\s*0/);
    expect(update).toMatch(/activated_at_utc\s+IS\s+NULL/i);
    expect((statements.match(/UPDATE\s+users/gi) ?? [])).toHaveLength(1);
  });

  it("sifre hash'i, credential ve davet uretmez", () => {
    expect(statements).not.toContain("password_hash =");
    expect(statements).not.toMatch(/INSERT\s+INTO\s+users\b/i);
    expect(statements).not.toMatch(/personel_account_activation_invitations/i);
    expect(statements).not.toMatch(/token/i);
    expect(statements).not.toMatch(/password\s*=\s*'/i);
    expect(statements).not.toMatch(/https?:\/\//i);
  });

  it("PERSONEL disina ve diger tablolara yazmaz, DDL uretmez", () => {
    expect(statements).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(statements).not.toMatch(/\b(CREATE|ALTER|DROP)\s+(TABLE|INDEX|TRIGGER|VIEW)\b/i);
    expect(statements).not.toMatch(/UPDATE\s+personeller\b/i);
    expect(statements).not.toMatch(/rol\s*!=\s*'PERSONEL'/i);
    expect(statements).toMatch(/UPDATE\s+users/i);
  });

  it("fail-closed schema guard ve denetim izi icerir", () => {
    expect(statements).toContain("PACK089_BLOCKER");
    expect(statements).toContain("information_schema.COLUMNS");
    expect(statements).toContain("information_schema.TABLES");
    expect(statements).toContain("LEGACY_ACCOUNT_ACTIVATION_TAKEOVER");
    expect(statements).toContain("personel_account_onboarding_audit");
    expect(statements).toContain("NOT EXISTS");
    expect(statements).toMatch(/EXISTS\s*\(\s*SELECT 1 FROM personeller/i);
    expect(statements).toContain("actor_user_id");
  });

  it("login guard'i sifre dogrulamasindan once fail-closed kalir", () => {
    const login = read("api/src/Auth/LoginController.php");
    expect(login).toMatch(/activation_required[\s\S]*PasswordHasher::verify/);
  });

  it("tarihsel migration kaydi current first-login owner'ini tekrar legacy davet akisina baglamaz", () => {
    const service = read("api/src/Services/Auth/PersonelAccountOnboardingService.php");
    expect(service).toContain("resolvePersonelInitialPasswordMaterial");
    expect(service).toContain("u.activation_required");
    expect(service).not.toContain("reissueActivation");
    expect(service).not.toContain("issueInvitationLocked");
  });
});
