import { beforeAll, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runner = resolve(
  process.cwd(),
  "tests/php/OfflineMutationIdempotencyMysqlTestRunner.php"
);
const migration = readFileSync(
  resolve(process.cwd(), "api/migrations/070_offline_mutation_idempotency.sql"),
  "utf8"
);
const service = readFileSync(
  resolve(process.cwd(), "api/src/Services/OfflineMutationIdempotencyService.php"),
  "utf8"
);
const personeller = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/PersonellerController.php"),
  "utf8"
);
const telemetry = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/ClientTelemetryController.php"),
  "utf8"
);

describe("offline mutation idempotency MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("keeps ledger schema + atomic claim owner wired", () => {
    expect(migration).toContain("offline_mutation_idempotency");
    expect(migration).toContain("uq_omi_actor_scope_key");
    expect(migration).toContain("payload_hash");
    expect(migration).not.toMatch(/request_body|response_body/i);
    expect(service).toContain("claimInTransaction");
    expect(service).toContain("IDEMPOTENCY_KEY_CONFLICT");
    expect(service).toContain("requires an open transaction");
    expect(personeller).toContain("OfflineMutationIdempotencyService::claimInTransaction");
    expect(personeller).toContain("personeller.create");
  });

  it("rejects client-spoofable telemetry actor fields", () => {
    expect(telemetry).toContain("TELEMETRY_CLIENT_ACTOR_FORBIDDEN");
    expect(telemetry).toContain("actor_user_id");
    expect(telemetry).not.toMatch(/\$safe\['user_id'\]/);
    expect(telemetry).toContain("client_active_sube_id");
    expect(telemetry).toContain("client_ui_profile");
  });

  it("runs sequential/concurrent/cross-actor/payload-mismatch acceptance", () => {
    const result = runPhpMysqlRunner(runner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (result.stdout.includes("SKIP:")) {
      return;
    }
    expect(result.stdout).toContain("OfflineMutationIdempotencyMysqlTestRunner: ALL PASS");
    expect(result.stdout).toContain("[PASS] sequential business mutation count = 1");
    expect(result.stdout).toContain("[PASS] concurrent business mutation count = 1");
    expect(result.stdout).toContain("[PASS] payload mismatch IDEMPOTENCY_KEY_CONFLICT");
    expect(result.stdout).toContain("[PASS] cross-actor second business row");
    expect(result.stdout).toContain("[PASS] response-lost business still 1");
  });
});
