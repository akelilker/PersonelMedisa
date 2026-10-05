import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelCinsiyetUpdateTestRunner.php");

describe("personel cinsiyet normal güncelleme akışı", () => {
  it("cinsiyet normal update çağrısıyla kalıcı olarak yazılıyor (SQLite, gerçek davranış)", () => {
    const isWindows = process.platform === "win32";
    let phpPath = "php";
    try {
      phpPath = isWindows
        ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
        : "php";
    } catch {
      throw new Error("PHP CLI not found on PATH.");
    }

    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-cinsiyet-update: OK");
    expect(result.stdout).toContain("[PASS] cinsiyet Kadın olarak kalici yazildi");
    expect(result.stdout).toContain("[PASS] cinsiyet Erkek olarak guncellendi");
    expect(result.stdout).toContain("[PASS] cinsiyet + temel alan birlikte yazildi");
  });
});
