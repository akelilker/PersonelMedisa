import { execFileSync, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const generator = resolve(root, "scripts/generate-canonical-migration-bundle.mjs");
const ledger = resolve(root, "api/src/Database/migration_ledger.sql");
const migration068 = resolve(
  root,
  "api/migrations/068_sgk_actor_identity_lifecycle_audit.sql",
);
const migration069 = resolve(
  root,
  "api/migrations/069_personel_credential_onboarding.sql",
);
const migration070 = resolve(
  root,
  "api/migrations/070_offline_mutation_idempotency.sql",
);
const phpAvailable = spawnSync("php", ["-r", "echo PHP_VERSION;"]).status === 0;

describe("canonical migration bundle", () => {
  it("rebuilds deterministically and preserves original SQL checksums", () => {
    const temp = mkdtempSync(join(tmpdir(), "medisa-migration-bundle-"));
    try {
      const first = join(temp, "first.php");
      const second = join(temp, "second.php");
      execFileSync(process.execPath, [generator, root, first], { stdio: "pipe" });
      execFileSync(process.execPath, [generator, root, second], { stdio: "pipe" });

      const firstBytes = readFileSync(first);
      expect(firstBytes.equals(readFileSync(second))).toBe(true);

      const bundle = firstBytes.toString("utf8");
      expect((bundle.match(/'version' => '/g) ?? []).length).toBe(71);
      expect(bundle).toContain("'name' => 'migration_ledger.sql'");
      expect(bundle).toContain(
        "'name' => '067_personel_canonical_reference_gate.sql'",
      );
      expect(bundle).toContain(
        "'name' => '068_sgk_actor_identity_lifecycle_audit.sql'",
      );
      expect(bundle).toContain(
        "'name' => '069_personel_credential_onboarding.sql'",
      );
      expect(bundle).toContain(
        "'name' => '070_offline_mutation_idempotency.sql'",
      );

      const checksum068 = createHash("sha256")
        .update(readFileSync(migration068))
        .digest("hex");
      const entry068 = bundle.match(
        /'name' => '068_sgk_actor_identity_lifecycle_audit\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry068?.[1]).toBe(checksum068);

      const checksum069 = createHash("sha256")
        .update(readFileSync(migration069))
        .digest("hex");
      const entry069 = bundle.match(
        /'name' => '069_personel_credential_onboarding\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry069?.[1]).toBe(checksum069);

      const checksum070 = createHash("sha256")
        .update(readFileSync(migration070))
        .digest("hex");
      const entry070 = bundle.match(
        /'name' => '070_offline_mutation_idempotency\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry070?.[1]).toBe(checksum070);

      expect(bundle).toContain(
        createHash("sha256").update(readFileSync(ledger)).digest("hex"),
      );
    } finally {
      rmSync(temp, { recursive: true, force: true });
    }
  });

  it("keeps production source selection bundle-first and fail-closed", () => {
    const service = readFileSync(
      resolve(root, "api/src/Database/MigrationExecutionService.php"),
      "utf8",
    );
    const provider = readFileSync(
      resolve(root, "api/src/Database/BundledMigrationSourceProvider.php"),
      "utf8",
    );
    const cron = readFileSync(
      resolve(root, "api/bin/cpanel-migration-cron.php"),
      "utf8",
    );

    expect(service).toContain("new BundledMigrationSourceProvider");
    expect(service).toContain("Canonical migration bundle is missing.");
    expect(cron).toContain("sourceForRuntime($apiDirectory, true)");
    expect(provider).toContain("base64_decode");
    expect(provider).toContain("checksum mismatch");
  });

  it.skipIf(!phpAvailable)(
    "discovers all migrations from the bundle when raw SQL is absent",
    () => {
      const temp = mkdtempSync(join(tmpdir(), "medisa-migration-bundle-"));
      try {
        const bundle = join(temp, "canonical-migrations.php");
        execFileSync(process.execPath, [generator, root, bundle], { stdio: "pipe" });
        const phpRoot = root.replaceAll("\\", "/").replaceAll("'", "\\'");
        const phpBundle = bundle.replaceAll("\\", "/").replaceAll("'", "\\'");
        const script = [
          `require '${phpRoot}/api/src/bootstrap.php';`,
          `$provider = new Medisa\\Api\\Database\\BundledMigrationSourceProvider('${phpBundle}');`,
          `$rows = $provider->all();`,
          `if (count($rows) !== 71 || $rows[0]['version'] !== '000' || $rows[69]['version'] !== '069' || $rows[70]['version'] !== '070') { exit(1); }`,
          "echo 'RAW_SQL_MISSING_PRODUCTION_SIMULATION=PASS';",
        ].join(" ");
        const result = spawnSync("php", ["-r", script], {
          cwd: root,
          encoding: "utf8",
        });
        expect(result.status, result.stderr).toBe(0);
        expect(result.stdout).toContain("RAW_SQL_MISSING_PRODUCTION_SIMULATION=PASS");
      } finally {
        rmSync(temp, { recursive: true, force: true });
      }
    },
  );

  it.skipIf(!phpAvailable)("fails closed for missing, corrupt, tampered, and duplicate bundles", () => {
    const temp = mkdtempSync(join(tmpdir(), "medisa-migration-bundle-"));
    try {
      const valid = join(temp, "valid.php");
      execFileSync(process.execPath, [generator, root, valid], { stdio: "pipe" });
      const phpRoot = root.replaceAll("\\", "/").replaceAll("'", "\\'");
      const runProvider = (bundlePath: string) => {
        const phpBundle = bundlePath.replaceAll("\\", "/").replaceAll("'", "\\'");
        return spawnSync(
          "php",
          [
            "-r",
            `require '${phpRoot}/api/src/bootstrap.php'; (new Medisa\\Api\\Database\\BundledMigrationSourceProvider('${phpBundle}'))->all();`,
          ],
          { cwd: root, encoding: "utf8" },
        );
      };

      expect(runProvider(join(temp, "missing.php")).status).not.toBe(0);

      const corrupt = join(temp, "corrupt.php");
      writeFileSync(corrupt, "<?php declare(strict_types=1); return ['invalid'];\n");
      expect(runProvider(corrupt).status).not.toBe(0);

      const tampered = join(temp, "tampered.php");
      writeFileSync(
        tampered,
        readFileSync(valid, "utf8").replace(
          /'checksum' => '[a-f0-9]{64}'/,
          `'checksum' => '${"0".repeat(64)}'`,
        ),
      );
      expect(runProvider(tampered).status).not.toBe(0);

      const duplicate = join(temp, "duplicate.php");
      const validText = readFileSync(valid, "utf8");
      const firstEntry = validText.match(/    \[\n[\s\S]*?    \],\n/)?.[0];
      expect(firstEntry).toBeDefined();
      writeFileSync(duplicate, validText.replace("];\n", `${firstEntry}];\n`));
      expect(runProvider(duplicate).status).not.toBe(0);
    } finally {
      rmSync(temp, { recursive: true, force: true });
    }
  });
});
