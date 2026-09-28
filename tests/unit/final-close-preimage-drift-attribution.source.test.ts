import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string) => readFileSync(resolve(process.cwd(), path), "utf8");
const snapshot = read("api/src/Services/Operations/FinalCloseSnapshot.php");
const owners = read("api/src/Services/Operations/FinalCloseOwners.php");
const postcheck = read("api/src/Services/Operations/FinalClosePostcheck.php");
const transport = read("api/src/Services/Operations/FinalCloseTransport.php");
const service = read("api/src/Services/Operations/FinalCloseService.php");
const control = read("scripts/ops/final-close-control.py");

/** The fail-closed guard the mutation path uses: first mismatch, generic token. */
const guard = snapshot.slice(
  snapshot.indexOf("public static function assertApprovedPreimage"),
  snapshot.indexOf("public static function approvedPreimageDrifts")
);
/** The canonical read-only collector: every approved comparison in one table. */
const collector = snapshot.slice(
  snapshot.indexOf("public static function approvedPreimageDrifts"),
  snapshot.indexOf("private static function comparisonDrifts")
);
/** The per-field comparator: it consumes a value and may only emit a class. */
const comparison = snapshot.slice(
  snapshot.indexOf("private static function comparisonDrifts"),
  snapshot.indexOf("public static function matches")
);
/** The bounded-token helpers: reason, target set, field label, mismatch class. */
const helpers = snapshot.slice(
  snapshot.indexOf("private static function mismatchClass"),
  snapshot.indexOf("public static function checksum")
);
/** The closed field allowlist. */
const fields = snapshot.slice(
  snapshot.indexOf("private const DRIFT_FIELDS = ["),
  snapshot.indexOf("public static function collect")
);

describe("final-close preimage drift attribution", () => {
  it("keeps one owner for the token contract and retires the scope-parameter API", () => {
    expect(snapshot).toContain("private const PREIMAGE_DRIFT = 'FINAL_CLOSE_PREIMAGE_DRIFT';");
    expect(snapshot).toContain(
      "private const POLICY_PREIMAGE_DRIFT = 'FINAL_CLOSE_POLICY_PREIMAGE_DRIFT';"
    );
    expect(snapshot).toContain("private const DRIFT_MAX_LENGTH = 100;");
    expect(snapshot).toContain(
      "private const DRIFT_CLASSES = ['MISSING', 'NULL', 'TYPE', 'COUNT', 'IDS', 'VALUE'];"
    );
    expect(snapshot).toContain("private const DRIFT_FIELDS = [");
    // The bounded single-token contract every existing caller depends on.
    expect(snapshot).toContain("public static function matches(array $actual, array $expected): void");
    expect(snapshot).not.toContain("string $scope");
    // One comparison owner, no parallel second drift system.
    for (const owner of [
      "public static function approvedPreimageDrifts(",
      "private static function comparisonDrifts(",
      "function matches(",
      "private static function driftReason(",
      "private static function driftTargets(",
      "private static function driftField(",
      "private static function mismatchClass(",
    ]) {
      const pattern = owner.replace("(", "\\(").replace(")", "\\)");
      expect(snapshot.match(new RegExp(pattern, "g")) ?? []).toHaveLength(1);
    }
    // The removed scope-token helpers may not come back.
    for (const retired of ["driftCode", "driftScope", "driftBound", "driftClass("]) {
      expect(snapshot).not.toContain(retired);
    }
  });

  it("reports every drifted axis of one run from a single comparison table", () => {
    // The collector only reports: it never drops the remaining drifts and never asks
    // a caller for a scope.
    expect(collector).not.toContain("throw new");
    expect(collector.match(/self::matches\(/g) ?? []).toHaveLength(0);
    // Every approved comparison of the preimage is present, in owner order, and each
    // one hands the shared collector its own category and compiled target id.
    expect(collector.match(/self::comparisonDrifts\(\$drifts,/g) ?? []).toHaveLength(10);
    expect(collector).toContain("foreach (FinalClosePackage::USERS as $id => $expected) {");
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'USER', (int) $id, $s['users'][$id] ?? [], $expected + ['id' => $id, 'durum' => 'AKTIF']);"
    );
    expect(collector).toContain("foreach (FinalClosePackage::PERSONNEL as $id) {");
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'PERSONNEL', (int) $id, $s['personnel'][$id] ?? [], ['id' => $id, 'calisma_lokasyonu_id' => null]);"
    );
    expect(collector).toContain("self::comparisonDrifts($drifts, 'PERSONNEL', 203, $s['personnel'][203] ?? []");
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'PERSONNEL', 210, $s['personnel'][210] ?? [], ['sube_id' => 6]);"
    );
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'PERSONNEL', 212, $s['personnel'][212] ?? [], ['sube_id' => null, 'sirket_id' => null]);"
    );
    expect(collector).toContain("foreach (FinalClosePackage::SCOPES_BEFORE as $id => $scope) {");
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'SCOPE', (int) $id, $s['users'][$id] ?? [], ['sube_ids' => $scope]);"
    );
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'SCOPE', 50, $s['users'][50] ?? [], ['sube_ids' => [2]]);"
    );
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'ACTOR', 110, $s['actors'][110] ?? [], ['actor_identity_id' => null, 'actor_status' => null]);"
    );
    expect(collector).toContain(
      "self::comparisonDrifts($drifts, 'ACTOR', 11, $s['actors'][11] ?? [], ['actor_status' => 'VERIFIED']);"
    );
    // Only the compiled target branches, with the manager allowlist kept narrowed.
    expect(collector).toContain("foreach (array_keys(FinalClosePackage::MANAGERS) as $id) {");
    expect(collector).toContain("self::comparisonDrifts($drifts, 'BRANCH', (int) $id, $s['branches'][$id] ?? [], [");
    expect(collector).toContain("'id' => $id, 'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => [],");
    // The policy axis is the only one without a field: its body may never surface, so
    // both drifted policies collapse into one pre-existing bounded code.
    expect(collector).toContain("foreach (self::driftTargets()['POLICY'] as $id) {");
    expect(collector).toContain("if (($s['policies'][$id] ?? null) !== []) {");
    expect(collector).toContain("$drifts[] = self::POLICY_PREIMAGE_DRIFT;");
    expect(collector).toContain("return array_values(array_unique($drifts));");
  });

  it("keeps the mutation guard fail-closed on one generic token", () => {
    expect(guard).toContain("$drifts = self::approvedPreimageDrifts($s);");
    expect(guard).toContain("if ($drifts !== []) {");
    expect(guard).toContain("throw new RuntimeException($drifts[0]);");
    // The generic prefix is declared once and never duplicated as a literal token.
    expect(snapshot.match(/'FINAL_CLOSE_PREIMAGE_DRIFT'/g) ?? []).toHaveLength(1);
    expect(snapshot.match(/self::PREIMAGE_DRIFT\b/g) ?? []).toHaveLength(4);
    expect(snapshot.match(/'FINAL_CLOSE_POLICY_PREIMAGE_DRIFT'/g) ?? []).toHaveLength(1);
    expect(snapshot.match(/self::POLICY_PREIMAGE_DRIFT/g) ?? []).toHaveLength(1);
    // A scope-less match still fails closed with the generic prefix, message unchanged.
    expect(snapshot).toContain("throw new RuntimeException(self::PREIMAGE_DRIFT);");
    expect(owners).toContain("throw new RuntimeException('FINAL_CLOSE_PREIMAGE_DRIFT');");
  });

  it("derives the field label from a closed allowlist and the class from a shape set", () => {
    const labels = [...fields.matchAll(/^\s*'([a-z_]+)' => '([A-Z_]+)',$/gm)].map((match) => match[2]);
    expect(labels).toEqual([
      "ID",
      "DURUM",
      "USERNAME",
      "ROL",
      "PERSONEL_ID",
      "CALISMA_LOKASYONU_ID",
      "AD",
      "SOYAD",
      "AKTIF_DURUM",
      "SUBE_ID",
      "SIRKET_ID",
      "SUBE_IDS",
      "ACTOR_IDENTITY_ID",
      "ACTOR_STATUS",
      "SORUMLU_YONETICI_USER_IDS",
    ]);
    expect(helpers).toContain("private static function driftField(string $field): string");
    expect(helpers).toContain("$key = strtolower(trim($field));");
    expect(helpers).toContain("return isset(self::DRIFT_FIELDS[$key]) ? self::DRIFT_FIELDS[$key] : '';");
    // The class set stays shape-only: presence, null, type, list size, members, value.
    expect(helpers).toContain("private static function mismatchClass($expected, $actual): string");
    expect(helpers).toContain("if ($expected === null || $actual === null) {");
    expect(helpers).toContain("return 'NULL';");
    expect(helpers).toContain("if ($expectedType !== gettype($actual)) {");
    expect(helpers).toContain("return 'TYPE';");
    expect(helpers).toContain("return count($expected) !== count($actual) ? 'COUNT' : 'IDS';");
    expect(helpers).toContain("return 'VALUE';");
    // MISSING is decided by the comparator: an absent actual field, and nothing else.
    expect(comparison).toContain("$field = self::driftField((string) $key);");
    expect(comparison).toContain("if (!array_key_exists($key, $actual)) {");
    expect(comparison).toContain("$drifts[] = self::driftReason($category, $id, $field, 'MISSING');");
    expect(comparison).toContain(
      "$drifts[] = self::driftReason($category, $id, $field, self::mismatchClass($expectedValue, $actual[$key]));"
    );
    expect(comparison).toContain("if ($actual[$key] !== $expectedValue) {");
    // The comparator never assembles a reason itself: the token is built in one place.
    expect(comparison).not.toContain("$reason");
    // No free-text normalization can smuggle caller text into a token any more.
    expect(snapshot).not.toContain("preg_replace");
  });

  it("degrades anything outside the compiled allowlist to the bare generic prefix", () => {
    expect(helpers).toContain("'USER' => array_keys(FinalClosePackage::USERS),");
    expect(helpers).toContain("'PERSONNEL' => FinalClosePackage::PERSONNEL,");
    expect(helpers).toContain(
      "'SCOPE' => array_values(array_unique(array_merge(array_keys(FinalClosePackage::SCOPES_BEFORE), [50]))),"
    );
    expect(helpers).toContain("'ACTOR' => array_keys(FinalClosePackage::USERS),");
    expect(helpers).toContain("'BRANCH' => array_keys(FinalClosePackage::MANAGERS),");
    expect(helpers).toContain("'POLICY' => [12, 13],");
    expect(helpers).toContain(
      "if ($field === '' || !isset($targets[$category]) || !in_array($id, $targets[$category], true)"
    );
    expect(helpers).toContain("|| !in_array($class, self::DRIFT_CLASSES, true)) {");
    // Both degradation paths return the same bare prefix: the guard clause and the
    // bound re-validation of the assembled reason.
    expect(helpers.match(/return self::PREIMAGE_DRIFT;/g) ?? []).toHaveLength(1);
    expect(helpers.match(/\? \$reason : self::PREIMAGE_DRIFT;/g) ?? []).toHaveLength(1);
    // The assembled reason is re-validated against the worker/transport bound.
    expect(helpers).toContain(
      "$reason = self::PREIMAGE_DRIFT . '_' . $category . '_' . $id . '_' . $field . '_' . $class;"
    );
    expect(helpers).toContain(
      "return strlen($reason) <= self::DRIFT_MAX_LENGTH && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $reason) === 1"
    );
  });

  it("never lets a value, a row, an exception or SQL into a token", () => {
    for (const leak of [
      "json_encode",
      "var_export",
      "print_r",
      "serialize",
      "implode",
      "getMessage",
      "PDO",
      "SELECT",
      "MUHAMMED",
      "IRAKLI",
      "sinemH",
    ]) {
      expect(helpers).not.toContain(leak);
      expect(comparison).not.toContain(leak);
    }
    // Only the canonical collector may hold the approved expectation, and only once.
    expect(snapshot.match(/MUHAMMED IRAKLI/g) ?? []).toHaveLength(1);
    expect(collector).not.toContain("DELETE");
    expect(collector).not.toContain("UPDATE");
  });

  it("releases the read-only snapshot path without weakening the mutation frame guard", () => {
    expect(owners).toContain("if ($op === 'snapshot') {");
    expect(owners).toContain("return ['data' => $snapshot, 'meta' => [], 'errors' => []];");
    expect(owners).not.toContain("FinalCloseSnapshot::assertApprovedPreimage(");
    expect(owners).not.toContain("'FRAME_USER_' . $id");
    // The mutation path still checks the captured frame and the approved preimage.
    expect(owners).toContain(
      "FinalCloseSnapshot::matches($snapshot['users'][$id], $identity + ['id' => $id, 'durum' => 'AKTIF']);"
    );
    expect(owners).toContain(
      "FinalCloseSnapshot::matches($authenticated, ['id' => $actor] + FinalClosePackage::USERS[$actor]);"
    );
  });

  it("publishes a bounded FAIL report instead of an apply checksum", () => {
    expect(service).toContain("$drifts = FinalCloseSnapshot::approvedPreimageDrifts($snapshot);");
    expect(service).toContain(
      "if ($request['mode'] === 'FINAL_CLOSE_PREFLIGHT' && $drifts !== []) {"
    );
    expect(service).toContain("return self::preflightDriftReport($request, $publishedSha, $drifts);");
    // The mutation path keeps its own guard, so a FAIL report never reaches a write.
    expect(service).toContain("FinalCloseSnapshot::assertApprovedPreimage($snapshot);");
    expect(service).toContain("'preflight_checksum' => null, 'result' => 'FAIL',");
    expect(service).toContain("'production_mutation_count' => 0,");
    expect(service).toContain("'preimage_drift_count' => count($drifts),");
    expect(service).toContain("'preimage_drifts' => array_values($drifts),");
    const report = service.slice(
      service.indexOf("private static function preflightDriftReport"),
      service.indexOf("private static function dependencyBlocker")
    );
    expect(report.length).toBeGreaterThan(0);
    expect(report).not.toContain("snapshot");
    expect(report).not.toContain("PDO");
    expect(report).not.toContain("getMessage");
  });

  it("lets the control plane log correlated bounded tokens and nothing else", () => {
    expect(control).toContain("def preflight_drift_items(report, request_id, sha):");
    expect(control).toContain(
      "require(report.get('request_id') == request_id and report.get('deployed_sha') == sha"
    );
    expect(control).toContain("'FINAL_CLOSE_PREIMAGE_REPORT_INVALID')");
    expect(control).toContain("'FINAL_CLOSE_PREIMAGE_DRIFT_INVALID')");
    expect(control).toContain(
      "and isinstance(drifts, list) and 0 < count <= 100 and count == len(drifts)"
    );
    expect(control).toContain(
      "and all(isinstance(item, str) and re.fullmatch('[A-Z0-9_]{1,100}', item) for item in drifts),"
    );
    expect(control).toContain("drifts = preflight_drift_items(drift_report, request_id, sha)");
    expect(control).toContain("print('FINAL_CLOSE_PREIMAGE_DRIFT_COUNT=' + str(len(drifts)))");
    expect(control).toContain("print('FINAL_CLOSE_PREIMAGE_DRIFT_ITEM=' + item)");
    // The correlated tokens are logged; the raw report never is.
    expect(control).not.toContain("print(drifts)");
    expect(control).not.toContain("print(drift_report)");
    expect(control).not.toContain("print(report)");
    expect(control).toContain("raise RuntimeError('FINAL_CLOSE_WORKER_FAILED')");
    // The bounded list is read before the failure is raised.
    const readIndex = control.indexOf("drift_report = json.loads(");
    const raiseIndex = control.indexOf("raise RuntimeError('FINAL_CLOSE_WORKER_FAILED')");
    expect(readIndex).toBeGreaterThan(-1);
    expect(raiseIndex).toBeGreaterThan(readIndex);
    expect(transport).toContain("/^[A-Z0-9_]{1,100}$/D");
  });

  it("leaves the apply-phase postcheck and the approved business values untouched", () => {
    expect(postcheck.match(/FinalCloseSnapshot::matches\(/g) ?? []).toHaveLength(5);
    for (const scope of ["'FRAME_USER_", "'PERSONNEL_", "'SCOPE_", "'ACTOR_", "'BRANCH_"]) {
      expect(postcheck).not.toContain(scope);
    }
    expect(collector).toContain(
      "'ad' => 'MUHAMMED IRAKLI', 'soyad' => null, 'aktif_durum' => 'AKTIF', 'sube_id' => 1, 'sirket_id' => 1"
    );
    expect(collector).toContain("['id' => $id, 'calisma_lokasyonu_id' => null]");
    expect(collector).toContain("['sube_id' => 6]");
    expect(collector).toContain("['sube_id' => null, 'sirket_id' => null]");
    expect(collector).toContain("['actor_status' => 'VERIFIED']");
    expect(collector).toContain("'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => [],");
  });

  it("pins the canonical NULL surname of personel 203 and keeps NULL distinct from an empty string", () => {
    // Re-verified live shape: the canonical surname is NULL, so the approved
    // expectation is NULL and a stale empty string must stay a strict drift.
    expect(collector).toContain(
      "'ad' => 'MUHAMMED IRAKLI', 'soyad' => null, 'aktif_durum' => 'AKTIF', 'sube_id' => 1, 'sirket_id' => 1"
    );
    expect(snapshot).not.toContain("'soyad' => ''");
    // The per-field comparator compares strictly and holds no empty-string literal, so
    // no NULL/'' equivalence can hide a real drift on any approved field.
    expect(comparison).toContain("if ($actual[$key] !== $expectedValue) {");
    expect(comparison).not.toContain("''");
    // NULL stays a shape-only class, never a MISSING side effect.
    expect(helpers).toContain("if ($expected === null || $actual === null) {");
    expect(helpers).toContain("return 'NULL';");
    // The bounded reader stays raw: only the expectation was corrected.
    expect(read("api/src/Controllers/PersonellerController.php")).toContain("'soyad' => $row['soyad'],");
    // The approved target correction itself is untouched.
    expect(postcheck).toContain("$expected['personnel'][203]['soyad'] = 'Mahmud';");
    expect(owners).toContain("$body = ['ad' => 'Muhammed', 'soyad' => 'Mahmud'];");
  });

  it("stays PHP 7.4 compatible", () => {
    expect(snapshot).not.toMatch(/(?:^|[^\w])match\s*\(/);
    expect(snapshot).not.toMatch(/\breadonly\b/);
    expect(snapshot).not.toMatch(/\?->/);
    expect(snapshot).not.toMatch(/:\s*mixed\b/);
    expect(snapshot).not.toMatch(/^\s*enum\s+/m);
    expect(snapshot).not.toMatch(/^\s*#\[/m);
  });
});
