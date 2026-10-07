import { beforeAll, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const root = process.cwd();
const runnerPath = resolve(root, "tests/php/KullaniciYonetimiGizliTestPersonelMysqlTestRunner.php");
const controllerSrc = readFileSync(resolve(root, "api/src/Controllers/YonetimController.php"), "utf8");

function methodBody(name: string) {
  const start = controllerSrc.indexOf(`function ${name}(`);
  expect(start, name).toBeGreaterThan(-1);
  const next = controllerSrc.indexOf("\n    public static function ", start + 1);
  const nextPrivate = controllerSrc.indexOf("\n    private static function ", start + 1);
  const ends = [next, nextPrivate].filter((value) => value > -1);
  return controllerSrc.slice(start, ends.length ? Math.min(...ends) : undefined);
}

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("Kullanıcı Yönetimi — gizli test personeline bağlı hesaplar", () => {
  it("list reuses the canonical operational exclusion; detail-by-id stays unfiltered", () => {
    const list = methodBody("kullanicilar");
    expect(list).toContain("PersonelArchiveGate::appendOperationalExclusion($pdo, $where, 'p')");
    expect(list).toContain("LEFT JOIN personeller p ON p.id = u.personel_id");
    expect(list).not.toMatch(/sicil|ad\s*=\s*'DESTROYED'/);
    expect(methodBody("findKullaniciRowById")).toContain("FROM users WHERE id = :id LIMIT 1");
    expect(methodBody("findKullaniciRowById")).not.toContain("appendOperationalExclusion");
    // Revoke (DELETE) and update (PUT) resolve the target through the unfiltered by-id owner.
    expect(methodBody("kullaniciErisimKaldir")).toContain("self::findKullaniciRowById($pdo, $kullaniciId)");
    expect(controllerSrc).toContain("private const TOMBSTONED_PERSONEL_AD_SOYAD = 'DESTROYED PERSONEL';");
  });

  it("runs focused MariaDB scenarios", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-kullanici-yonetimi-gizli-test-personel-mysql: OK");
    expect(result.stdout).toContain(
      "[PASS] 4 default list drops exactly the 2 hidden-fixture-bound accounts, order id ASC kept"
    );
    expect(result.stdout).toContain("[PASS] 10 counters drop by exactly the hidden accounts (3/1)");
    expect(result.stdout).toContain(
      "[PASS] 14 DESTROYED PERSONEL never surfaced, even with include_hidden=1"
    );
    expect(result.stdout).toContain("[PASS] 16 schema-absent list unfiltered");
  });
});
