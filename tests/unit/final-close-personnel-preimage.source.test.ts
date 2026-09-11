import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string) => readFileSync(resolve(process.cwd(), path), "utf8");
const controller = read("api/src/Controllers/PersonellerController.php");
const snapshot = read("api/src/Services/Operations/FinalCloseSnapshot.php");
const pkg = read("api/src/Services/Operations/FinalClosePackage.php");

const readStart = controller.indexOf("public static function finalCloseRead(int $personelId)");
const readBody = controller.slice(
  readStart,
  controller.indexOf("public static function detail", readStart)
);

describe("final-close bounded personnel preimage read", () => {
  it("reads the canonical personeller row plus the branch company alias, nothing else", () => {
    expect(readStart).toBeGreaterThan(-1);
    // p.* carries the whole canonical row so the invariant hash keeps its coverage.
    expect(readBody).toContain("'SELECT p.*, s.sirket_id AS final_close_sirket_id'");
    expect(readBody).toContain("' FROM personeller p'");
    expect(readBody).toContain("' LEFT JOIN subeler s ON s.id = p.sube_id'");
    expect(readBody).toContain("' WHERE p.id = :id'");
    expect(readBody).toContain("' LIMIT 1'");
    // The narrowed seven-field projection may never come back.
    expect(readBody).not.toMatch(/SELECT p\.id, p\.ad/);
    expect(readBody).not.toMatch(/SELECT p\.[a-z_]+(?:,| AS)/);
    // The company of a person is the branch's stored column; only that one
    // relation may enter the statement, and it enters under an explicit alias.
    expect(readBody.match(/JOIN\s+[a-z_]+/g) ?? []).toEqual(["JOIN subeler"]);
  });

  it("never uses the UI/detail projection or a read-model fallback", () => {
    expect(readBody).not.toContain("fetchPersonelRowById");
    expect(readBody).not.toContain("personelSelectSql");
    expect(readBody).not.toContain("readSube");
    expect(readBody).not.toContain("listSubeler");
    expect(readBody).not.toContain("SubeReadModel");
    expect(readBody).not.toContain("OrganizasyonService");
    for (const displayOwner of [
      "departmanlar",
      "gorevler",
      "personel_tipleri",
      "sirketler",
      "calisma_lokasyonlari",
      "sgk_isverenler",
      "bolumler",
      "birimler",
      "pozisyonlar",
      "sirket_of_sube",
    ]) {
      expect(readBody).not.toContain(displayOwner);
    }
  });

  it("publishes exactly the approved preimage fields and keeps NULL values as NULL", () => {
    const resultStart = readBody.indexOf("$result = [");
    const resultBlock = readBody.slice(resultStart, readBody.indexOf("];", resultStart));
    const resultKeys = [...resultBlock.matchAll(/^\s*'([a-z_]+)' =>/gm)].map((match) => match[1]);
    expect(resultKeys).toEqual([
      "id",
      "ad",
      "soyad",
      "aktif_durum",
      "sube_id",
      "sirket_id",
      "calisma_lokasyonu_id",
    ]);
    // The response keeps the public field name while the join alias stays internal.
    expect(resultBlock).toContain(
      "'sirket_id' => $row['final_close_sirket_id'] === null ? null : (int) $row['final_close_sirket_id'],"
    );
    expect(resultBlock).not.toContain("...$row");
    expect(resultBlock).not.toContain("array_intersect_key");
    expect(readBody).toContain(
      "foreach (['id', 'ad', 'soyad', 'aktif_durum', 'sube_id', 'final_close_sirket_id', 'calisma_lokasyonu_id'] as $column) {"
    );
    expect(readBody).toContain("'sube_id' => $row['sube_id'] === null ? null : (int) $row['sube_id'],");
    expect(readBody).toContain(
      "'calisma_lokasyonu_id' => $row['calisma_lokasyonu_id'] === null ? null : (int) $row['calisma_lokasyonu_id'],"
    );
    // No derived company: a branchless person stays NULL instead of a guessed id.
    expect(readBody).not.toMatch(/COALESCE|IFNULL|sirket_of_sube|\?\?\s*\d/);
  });

  it("keeps the CLI guard and the compiled PERSONNEL allowlist intact", () => {
    expect(readBody).toContain("PHP_SAPI !== 'cli'");
    expect(readBody).toContain("FinalClosePackage::PERSONNEL, true");
    expect(pkg).toContain("public const PERSONNEL = [200, 201, 203, 204, 205, 206, 209, 210, 212, 217]");
  });

  it("builds the invariant input from the canonical row and keeps the join alias out", () => {
    expect(readBody).toContain("$result['invariant_hash'] = self::finalClosePersonnelInvariantHash($row);");
    expect(readBody).toContain("private static function finalClosePersonnelHashInput(array $row): array");
    expect(readBody).toContain("private static function finalClosePersonnelInvariantHash(array $row): string");
    // The joined company alias is dropped before hashing, so the branch relation
    // never becomes part of the personnel-row invariant.
    expect(readBody).toContain("unset($row['final_close_sirket_id']);");
    expect(readBody).toContain(
      "foreach (['ad', 'soyad', 'calisma_lokasyonu_id', 'calisma_lokasyonu_adi', 'updated_at'] as $key) {"
    );
    expect(readBody).toContain("ksort($row);");
    expect(readBody).toContain(
      "return hash('sha256', json_encode(self::finalClosePersonnelHashInput($row), JSON_THROW_ON_ERROR));"
    );
    // Never hash the public projection or a joined value.
    expect(readBody).not.toMatch(/json_encode\(\$result/);
    expect(readBody).not.toMatch(/json_encode\(\$row\b/);
  });

  it("keeps the behavioural runner that catches a narrowed hash input", () => {
    const runner = read("tests/php/FinalClosePersonnelInvariantHashTestRunner.php");
    const runnerWrapper = read("tests/unit/final-close-personnel-invariant-hash.php-runtime.test.ts");
    expect(runner).toContain("finalClosePersonnelInvariantHash");
    expect(runner).toContain("!array_key_exists('final_close_sirket_id', $hashInput)");
    expect(runner).toContain("'canonical column changes the invariant: ' . $field");
    expect(runner).toContain("echo 'verify-final-close-personnel-invariant-hash: OK' . PHP_EOL;");
    expect(runnerWrapper).toContain("verify-final-close-personnel-invariant-hash: OK");
  });

  it("fails closed with the personnel owner's bounded codes only", () => {
    const codes = new Set(readBody.match(/FINAL_CLOSE_[A-Z_]+/g) ?? []);
    expect(codes).toEqual(
      new Set([
        "FINAL_CLOSE_PERSONNEL_FORBIDDEN",
        "FINAL_CLOSE_PERSONNEL_MISSING",
        "FINAL_CLOSE_PERSONNEL_PROJECTION_INCOMPLETE",
      ])
    );
  });

  it("keeps the approved preimage values in one collector and the generic detail read untouched", () => {
    expect(snapshot).toContain("PersonellerController::finalCloseRead($id)");
    expect(snapshot).toContain(
      "self::comparisonDrifts($drifts, 'PERSONNEL', 203, $s['personnel'][203] ?? [], ['ad' => 'MUHAMMED IRAKLI', 'soyad' => '', 'aktif_durum' => 'AKTIF', 'sube_id' => 1, 'sirket_id' => 1]);"
    );
    expect(snapshot).toContain(
      "self::comparisonDrifts($drifts, 'PERSONNEL', 212, $s['personnel'][212] ?? [], ['sube_id' => null, 'sirket_id' => null]);"
    );
    expect(controller).toContain("private static function fetchPersonelRowById(PDO $pdo, $personelId)");
    expect(controller).toContain("$select = self::personelSelectSql($pdo);");
  });
});
