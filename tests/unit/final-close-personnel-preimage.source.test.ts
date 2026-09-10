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
  it("reads the canonical personnel row plus the branch company column, nothing else", () => {
    expect(readStart).toBeGreaterThan(-1);
    expect(readBody).toContain(
      "'SELECT p.id, p.ad, p.soyad, p.aktif_durum, p.sube_id, s.sirket_id, p.calisma_lokasyonu_id'"
    );
    expect(readBody).toContain("' FROM personeller p'");
    expect(readBody).toContain("' LEFT JOIN subeler s ON s.id = p.sube_id'");
    expect(readBody).toContain("' WHERE p.id = :id LIMIT 1'");
    // The company of a person is the branch's stored column; only that one
    // relation may enter the statement.
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

  it("projects exactly the preimage fields and keeps NULL values as NULL", () => {
    expect(readBody).toContain(
      "foreach (['id', 'ad', 'soyad', 'aktif_durum', 'sube_id', 'sirket_id', 'calisma_lokasyonu_id'] as $column) {"
    );
    expect(readBody).toContain("'sube_id' => $row['sube_id'] === null ? null : (int) $row['sube_id'],");
    expect(readBody).toContain("'sirket_id' => $row['sirket_id'] === null ? null : (int) $row['sirket_id'],");
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

  it("hashes the raw canonical row and keeps the mutable-field exclusions", () => {
    expect(readBody).toContain(
      "foreach (['ad', 'soyad', 'calisma_lokasyonu_id', 'calisma_lokasyonu_adi', 'updated_at'] as $key) {"
    );
    expect(readBody).toContain("ksort($row);");
    expect(readBody).toContain(
      "$result['invariant_hash'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));"
    );
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

  it("leaves the approved preimage and the generic detail read untouched", () => {
    expect(snapshot).toContain("PersonellerController::finalCloseRead($id)");
    expect(snapshot).toContain(
      "self::matches($s['personnel'][203], ['ad' => 'MUHAMMED IRAKLI', 'soyad' => '', 'aktif_durum' => 'AKTIF', 'sube_id' => 1, 'sirket_id' => 1]);"
    );
    expect(snapshot).toContain(
      "self::matches($s['personnel'][212], ['sube_id' => null, 'sirket_id' => null]);"
    );
    expect(controller).toContain("private static function fetchPersonelRowById(PDO $pdo, $personelId)");
    expect(controller).toContain("$select = self::personelSelectSql($pdo);");
  });
});
