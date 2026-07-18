import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { phpQuietCliArgs } from "../scripts/php-cli-args.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/BildirimPuantajEtkiDismissTestRunner.php");
const controllerPath = resolve(
  process.cwd(),
  "api/src/Controllers/BildirimPuantajEtkiAdaylariController.php"
);
const controllerSource = readFileSync(controllerPath, "utf8");

describe("BildirimPuantajEtkiDismiss PHP runtime", () => {
  it("runs 36 dismiss validation and state scenarios via PHP CLI", () => {
    // Production dismiss validation uses mb_strlen; CI/setup-php provides mbstring.
    // Local WinGet PHP often has mbstring commented out — load it for this runner only.
    const output = execFileSync("php", [...phpQuietCliArgs(["mbstring"]), runnerPath], {
      encoding: "utf8"
    });
    const result = JSON.parse(output.trim()) as {
      total: number;
      passed: number;
      failed: number;
      failures: string[];
    };
    expect(result.total).toBe(36);
    expect(result.passed).toBe(36);
    expect(result.failed).toBe(0);
    expect(result.failures).toEqual([]);
  });

  it("test runner lives outside production api tree", () => {
    expect(runnerPath.replace(/\\/g, "/")).toContain("tests/php/");
    expect(() =>
      readFileSync(resolve(process.cwd(), "api/tests/BildirimPuantajEtkiDismissTestRunner.php"))
    ).toThrow();
  });
});

describe("BildirimPuantajEtkiAdaylariController dismiss source contract (S74-C2A)", () => {
  function dismissBlock(): string {
    const match = controllerSource.match(/public static function dismiss\([\s\S]*?\n    \}/);
    expect(match).not.toBeNull();
    return match![0];
  }

  it("exposes dismiss operation with permission guard", () => {
    expect(controllerSource).toMatch(/public static function dismiss\(/);
    const block = dismissBlock();
    expect(block).toContain("BildirimPuantajEtkiDecisionPolicy::PERMISSION_DISMISS");
    expect(block).not.toMatch(/\$user\['rol'\]/);
    expect(block).not.toMatch(/===\s*'MUHASEBE'/);
  });

  it("validates expected_state and gerekce before transaction", () => {
    const block = dismissBlock();
    expect(block).toContain("validateDismissExpectedState");
    expect(block).toContain("validateDismissGerekce");
    const transactionIndex = block.indexOf("$pdo->beginTransaction()");
    const expectedIndex = block.indexOf("validateDismissExpectedState");
    const gerekceIndex = block.indexOf("validateDismissGerekce");
    expect(expectedIndex).toBeGreaterThan(-1);
    expect(gerekceIndex).toBeGreaterThan(-1);
    expect(expectedIndex).toBeLessThan(transactionIndex);
    expect(gerekceIndex).toBeLessThan(transactionIndex);
  });

  it("checks sube scope before transaction with lock-free scope fetch", () => {
    const block = dismissBlock();
    const scopeFetchIndex = block.indexOf("$scopeRow = self::fetchAdayById($pdo, $adayId)");
    const scopeAssertIndex = block.indexOf("SubeScope::assertPersonelAccess");
    const transactionIndex = block.indexOf("$pdo->beginTransaction()");
    const lockedFetchIndex = block.indexOf("fetchAdayById($pdo, $adayId, true)");
    expect(scopeFetchIndex).toBeGreaterThan(-1);
    expect(scopeAssertIndex).toBeGreaterThan(scopeFetchIndex);
    expect(transactionIndex).toBeGreaterThan(scopeAssertIndex);
    expect(lockedFetchIndex).toBeGreaterThan(transactionIndex);
    expect(block).not.toMatch(/\$scopeRow\['state'\]/);
  });

  it("uses locked row only for terminal policy and stale decisions", () => {
    const block = dismissBlock();
    const lockedFetchIndex = block.indexOf("$row = self::fetchAdayById($pdo, $adayId, true)");
    const stateIndex = block.indexOf("$currentState = BildirimPuantajEtkiDecisionPolicy::normalizeState((string) $row['state'])");
    expect(lockedFetchIndex).toBeGreaterThan(-1);
    expect(stateIndex).toBeGreaterThan(lockedFetchIndex);
    expect(block).not.toContain("$scopeRow['state']");
  });

  it("locks validation messages and error codes", () => {
    expect(controllerSource).toContain("Yok sayma gerekcesi en az 5 karakter olmalidir.");
    expect(controllerSource).toContain("Yok sayma gerekcesi en fazla 500 karakter olabilir.");
    expect(controllerSource).toContain("STATE_STALE");
    expect(controllerSource).toContain("Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin.");
    expect(controllerSource).toContain("Uygulanmis puantaj etki adayi yok sayilamaz.");
    expect(controllerSource).toContain("Puantaj etki adayi daha once farkli bir gerekceyle yok sayilmis.");
  });

  it("uses FOR UPDATE, transaction and updates only karar fields", () => {
    const block = dismissBlock();
    expect(block).toContain("$pdo->beginTransaction()");
    expect(block).toContain("fetchAdayById($pdo, $adayId, true)");
    expect(controllerSource).toContain("FOR UPDATE");
    expect(block).toContain("karar_veren_user_id = :karar_veren_user_id");
    expect(block).toContain("karar_zamani = :karar_zamani");
    expect(block).toContain("karar_gerekcesi = :karar_gerekcesi");
    expect(block).not.toContain("uygulanan_puantaj_id =");
    expect(block).not.toContain("onceki_puantaj_snapshot");
    expect(block).not.toContain("sonraki_puantaj_snapshot");
    expect(block).not.toContain("uygulama_hash");
  });

  it("uses DecisionPolicy for dismiss allowance and stale checks", () => {
    const block = dismissBlock();
    expect(block).toContain("isDismissAllowed");
    expect(block).toContain("validateExpectedState");
    expect(block).toContain("targetStateForAction");
    expect(block).toContain("ACTION_DISMISS");
  });

  it("supports exact idempotent repeat without mutation", () => {
    const block = dismissBlock();
    expect(block).toContain("mapDismissResponse($row, true)");
    expect(controllerSource).toContain("'idempotent' => (bool) $idempotent");
    const idempotentIndex = block.indexOf("mapDismissResponse($row, true)");
    const updateIndex = block.indexOf("UPDATE ' . self::TABLE");
    expect(idempotentIndex).toBeGreaterThan(-1);
    expect(updateIndex).toBeGreaterThan(idempotentIndex);
  });

  it("writes karar_zamani from backend and karar_veren_user_id from authenticated user", () => {
    const block = dismissBlock();
    expect(block).toContain("gmdate('Y-m-d H:i:s')");
    expect(block).toContain("self::userId($user)");
    expect(block).not.toContain("getJsonBody()['karar_zamani']");
    expect(block).not.toContain("getJsonBody()['karar_veren_user_id']");
  });

  it("rolls back on PDO failure", () => {
    const block = dismissBlock();
    expect(block).toContain("catch (\\PDOException $e)");
    expect(block).toContain("$pdo->rollBack()");
  });

  it("does not mutate operational tables", () => {
    expect(controllerSource).not.toContain("UPDATE gunluk_puantaj");
    expect(controllerSource).not.toContain("INSERT INTO gunluk_puantaj");
    expect(controllerSource).not.toContain("UPDATE gunluk_bildirimler");
    expect(controllerSource).not.toContain("UPDATE haftalik_bildirim_mutabakatlari");
    expect(controllerSource).not.toContain("UPDATE aylik_bildirim_onaylari");
    expect(controllerSource).not.toContain("UPDATE genel_yonetici_bildirim_onaylari");
    expect(controllerSource).not.toContain("UPDATE surecler");
    expect(controllerSource).not.toContain("UPDATE puantaj_aylik_muhurleri");
    expect(controllerSource).not.toContain("UPDATE ek_odeme_kesinti");
    expect(controllerSource).not.toContain("UPDATE aylik_ozet_satirlari");
  });

  it("keeps dismiss path free of apply/puantaj insert side effects", () => {
    const block = dismissBlock();
    expect(block).not.toContain("BildirimPuantajEtkiApplyService");
    expect(block).not.toContain("INSERT INTO gunluk_puantaj");
    expect(block).not.toContain("PERMISSION_APPLY");
    expect(controllerSource).toMatch(/public static function apply\(/);
  });
});
