import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

describe("I13-B default branch persistence source locks", () => {
  it("051 is additive nullable FK with SET NULL and no backfill UPDATE", () => {
    const sql = readFileSync("api/migrations/051_users_varsayilan_sube_id.sql", "utf8");
    expect(sql).toContain("EXISTING_USER_DEFAULT_BACKFILL = NONE");
    expect(sql).toContain("ADD COLUMN varsayilan_sube_id INT UNSIGNED NULL");
    expect(sql).toContain("ON DELETE SET NULL");
    expect(sql).not.toMatch(/UPDATE\s+users\s+SET\s+varsayilan/i);
    expect(sql).not.toMatch(/\bINSERT\s+INTO\s+users\b/i);
    expect(sql).not.toMatch(/\bDROP\s+COLUMN\b/i);
  });

  it("UsersSchema probe + controller/login/scope owners wire preferred default", () => {
    expect(existsSync(resolve("api/src/Database/UsersSchema.php"))).toBe(true);
    const schema = readFileSync("api/src/Database/UsersSchema.php", "utf8");
    expect(schema).toContain("hasVarsayilanSubeId");

    const yonetim = readFileSync("api/src/Controllers/YonetimController.php", "utf8");
    expect(yonetim).toContain("UsersSchema::hasVarsayilanSubeId");
    expect(yonetim).toContain("SCHEMA_NOT_READY");
    expect(yonetim).toContain("assertVarsayilanSubeInScope");

    const login = readFileSync("api/src/Auth/LoginController.php", "utf8");
    expect(login).toContain("UsersSchema::hasVarsayilanSubeId");
    expect(login).toContain("SubeScope::resolveInitialActiveSubeId($subeIds, $preferredSubeId)");

    const scope = readFileSync("api/src/Scope/SubeScope.php", "utf8");
    expect(scope).toContain("$preferredSubeId = null");
    expect(scope).toContain("in_array($preferred, $subeIds, true)");
  });

  it("migration tip ends at 051", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations[0]).toBe("001_initial_schema.sql");
    expect(migrations.at(-1)).toBe("052_puantaj_tolerans_ve_disiplin.sql");
  });
});
