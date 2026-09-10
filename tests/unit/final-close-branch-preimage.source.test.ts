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

  it("attests exactly the six target branches as AKTIF with no recorded manager", () => {
    const preimage = snapshot.slice(
      snapshot.indexOf("public static function assertApprovedPreimage"),
      snapshot.indexOf("public static function matches")
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
