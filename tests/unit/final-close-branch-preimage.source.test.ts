import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string) => readFileSync(resolve(process.cwd(), path), "utf8");
const snapshot = read("api/src/Services/Operations/FinalCloseSnapshot.php");
const postcheck = read("api/src/Services/Operations/FinalClosePostcheck.php");
const organization = read("api/src/Services/Organizasyon/OrganizasyonService.php");
const schema = read("api/src/Services/Organizasyon/SubeSorumluYoneticiSchema.php");

const narrowStart = organization.indexOf("public static function readFinalCloseBranchPreimage");
const narrow = organization.slice(
  narrowStart,
  organization.indexOf("public static function findSube", narrowStart)
);
const narrowBody = narrow.slice(narrow.indexOf("{"));

describe("final-close bounded branch preimage read", () => {
  it("reads only what the compiled preimage owns, not the screen branch read model", () => {
    expect(narrowStart).toBeGreaterThan(-1);
    expect(snapshot).not.toContain("OrganizasyonService::listSubeler(");
    expect(snapshot).not.toContain("SubeReadModel");
    expect(snapshot).toContain("OrganizasyonService::readFinalCloseBranchPreimage(");
    expect(snapshot).toContain("array_keys(FinalClosePackage::MANAGERS)");
    // The target set is the compiled allowlist [1, 2, 5, 6, 12, 13]; no other
    // branch may be indexed literally anywhere in the snapshot.
    expect(snapshot).not.toMatch(/\['branches'\]\[\s*\d/);
  });

  it("is a narrow projection without the departman/muhasebe/payload joins", () => {
    expect(narrowBody).toContain("'SELECT id, durum FROM subeler WHERE id IN ('");
    expect(narrowBody).toContain("'id' => $id,");
    expect(narrowBody).toContain("'durum' => strtoupper(trim((string) ($row['durum'] ?? ''))),");
    expect(narrowBody).toContain("'sorumlu_yonetici_user_ids' => array_values($managerIds[$id] ?? []),");
    expect(narrowBody).not.toContain("departman");
    expect(narrowBody).not.toContain("muhasebe");
    expect(narrowBody).not.toContain("sorumlu_yoneticiler");
    expect(narrowBody).not.toContain("LEFT JOIN");
    expect(narrowBody).not.toContain("GROUP BY");
  });

  it("uses the canonical manager owner and fails closed with bounded codes", () => {
    expect(narrowBody).toContain("SubeSorumluYoneticiSchema::isReady($pdo)");
    expect(narrowBody).toContain("SubeSorumluYoneticiSchema::loadSubeUserMap($pdo, array_values($ids))");
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_MANAGER_SCHEMA_REQUIRED')");
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_BRANCH_MISSING')");
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_BRANCH_TARGET_INVALID')");
    for (const code of narrowBody.match(/FINAL_CLOSE_[A-Z_]+/g) ?? []) {
      // A bounded single token is what the snapshot wrapper may surface verbatim
      // and what the worker publishes as the bounded reason.
      expect(code).toMatch(/^FINAL_CLOSE_[A-Z_]{3,}$/);
    }
    // An unready manager schema must never read as "no managers recorded".
    expect(schema).toContain("if (!self::isReady($pdo)) {");
    expect(schema).toContain("return [];");
  });

  it("isolates the target table read and the manager map read on their own bounded codes", () => {
    const selectIndex = narrowBody.indexOf("'SELECT id, durum FROM subeler WHERE id IN ('");
    const tableCodeIndex = narrowBody.indexOf("FINAL_CLOSE_BRANCH_TABLE_READ_FAILED");
    const mapCallIndex = narrowBody.indexOf(
      "SubeSorumluYoneticiSchema::loadSubeUserMap($pdo, array_values($ids))"
    );
    const mapCodeIndex = narrowBody.indexOf("FINAL_CLOSE_MANAGER_MAP_READ_FAILED");

    expect(selectIndex).toBeGreaterThan(-1);
    expect(tableCodeIndex).toBeGreaterThan(selectIndex);
    expect(mapCallIndex).toBeGreaterThan(tableCodeIndex);
    expect(mapCodeIndex).toBeGreaterThan(mapCallIndex);
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_BRANCH_TABLE_READ_FAILED')");
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_MANAGER_MAP_READ_FAILED')");
    // Two live reads, two bounded boundaries: the reads are never merged behind a
    // single catch, so one failure can no longer mask the other. The remaining
    // reads (schema probe, both normalizations, target assertion) each own a
    // boundary as well, so nothing attributable can escape to the caller.
    expect((narrowBody.match(/catch \(\\Throwable \$error\) \{/g) ?? []).length).toBe(6);
    // The bounded code is the whole surfaced value: no driver/SQL text is attached.
    expect(narrowBody).not.toContain("getMessage()");
    expect(narrowBody).not.toContain("errorInfo");
  });

  it("attributes every remaining owner stage to its own bounded code", () => {
    const schemaProbe = narrowBody.indexOf("SubeSorumluYoneticiSchema::isReady($pdo)");
    const schemaCheckCode = narrowBody.indexOf("FINAL_CLOSE_MANAGER_SCHEMA_CHECK_FAILED");
    const schemaRequired = narrowBody.indexOf(
      "throw new RuntimeException('FINAL_CLOSE_MANAGER_SCHEMA_REQUIRED')"
    );
    const mapCode = narrowBody.indexOf("FINAL_CLOSE_MANAGER_MAP_READ_FAILED");
    const mapNormalizeLoop = narrowBody.indexOf("$managerIds[$id] = array_values($managerIds[$id] ?? []);");
    const mapNormalizeCode = narrowBody.indexOf("FINAL_CLOSE_MANAGER_MAP_NORMALIZE_FAILED");
    const rowGuard = narrowBody.indexOf("if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {");
    const rowNormalizeCode = narrowBody.indexOf(
      "throw new RuntimeException('FINAL_CLOSE_BRANCH_ROW_NORMALIZE_FAILED')"
    );
    const assertionProbe = narrowBody.indexOf("$missing[] = $id;");
    const assertionCode = narrowBody.indexOf("FINAL_CLOSE_BRANCH_ASSERTION_FAILED");
    const missingThrow = narrowBody.indexOf("throw new RuntimeException('FINAL_CLOSE_BRANCH_MISSING')");

    // Each stage sits inside its own boundary, in owner order.
    expect(schemaProbe).toBeGreaterThan(-1);
    expect(schemaCheckCode).toBeGreaterThan(schemaProbe);
    expect(schemaRequired).toBeGreaterThan(schemaCheckCode);
    expect(mapNormalizeLoop).toBeGreaterThan(mapCode);
    expect(mapNormalizeCode).toBeGreaterThan(mapNormalizeLoop);
    expect(rowGuard).toBeGreaterThan(mapNormalizeCode);
    expect(rowNormalizeCode).toBeGreaterThan(rowGuard);
    expect(assertionProbe).toBeGreaterThan(rowNormalizeCode);
    expect(assertionCode).toBeGreaterThan(assertionProbe);
    // The target assertion keeps its own code: "branch missing" is still only
    // reached by a target the read did not answer for.
    expect(missingThrow).toBeGreaterThan(assertionCode);
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_BRANCH_TARGET_INVALID')");
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_BRANCH_ASSERTION_FAILED')");
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_MANAGER_SCHEMA_CHECK_FAILED')");
    expect(narrowBody).toContain("throw new RuntimeException('FINAL_CLOSE_MANAGER_MAP_NORMALIZE_FAILED')");
    // No owner code can be the caller's generic stage code, and every owner throw
    // is a bounded literal rather than a concatenated/derived string.
    expect(narrowBody).not.toContain("FINAL_CLOSE_SNAPSHOT_");
    const throws = narrowBody.match(/throw new RuntimeException\(/g) ?? [];
    const literalThrows = narrowBody.match(/throw new RuntimeException\('[A-Z][A-Z0-9_]{2,100}'\)/g) ?? [];
    expect(throws.length).toBeGreaterThan(0);
    expect(literalThrows.length).toBe(throws.length);
  });

  it("keeps the caller from collapsing the compiled codes into a generic stage code", () => {
    const bounded = snapshot.indexOf("/^[A-Z][A-Z0-9_]{2,100}$/D");
    const generic = snapshot.indexOf("'FINAL_CLOSE_SNAPSHOT_' . $step . '_FAILED'");
    expect(bounded).toBeGreaterThan(-1);
    expect(generic).toBeGreaterThan(bounded);
    expect(snapshot).not.toContain("getMessage() . ");
  });

  it("attests exactly the six target branches as AKTIF with no recorded manager", () => {
    // The approved comparison table now runs from the fail-closed guard through the
    // canonical all-drift collector, so the slice ends at the checksum owner.
    const preimage = snapshot.slice(
      snapshot.indexOf("public static function assertApprovedPreimage"),
      snapshot.indexOf("public static function checksum")
    );
    expect(preimage).toContain("foreach (array_keys(FinalClosePackage::MANAGERS) as $id) {");
    expect(preimage).toContain("'id' => $id, 'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => [],");
    // The whole-catalogue loop is gone: no unrelated branch enters the preimage.
    expect(preimage).not.toContain("foreach ($s['branches'] as $branch)");
    expect(preimage).toContain("'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => [],");
  });

  it("keeps the postcheck drift model on the narrowed preimage", () => {
    expect(postcheck).toContain(
      "['sorumlu_yonetici_user_ids' => [FinalClosePackage::MANAGERS[$id]]]"
    );
    expect(postcheck).not.toContain("sorumlu_yoneticiler");
  });

  it("leaves the UI branch read model and its callers untouched", () => {
    expect(organization).toContain("public static function listSubeler(PDO $pdo, ?int $sirketId = null): array");
    expect(organization).toContain("GROUP_CONCAT(sd.departman_id ORDER BY sd.departman_id ASC)");
    expect(organization).toContain(
      "return self::attachSorumluYoneticiPayloads(\n            $pdo,\n            self::attachMuhasebeYetkiPayloads($pdo, $items)\n        );"
    );
  });
});
