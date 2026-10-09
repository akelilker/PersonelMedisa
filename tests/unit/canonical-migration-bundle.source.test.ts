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
const migration071 = resolve(
  root,
  "api/migrations/071_org_hierarchy_authorization.sql",
);
const migration072 = resolve(
  root,
  "api/migrations/072_org_reference_short_codes.sql",
);
const migration073 = resolve(
  root,
  "api/migrations/073_test_fixture_personel_archive.sql",
);
const migration074 = resolve(
  root,
  "api/migrations/074_qr_attendance_correction_and_inbox.sql",
);
const migration075 = resolve(
  root,
  "api/migrations/075_personel_account_activation.sql",
);
const migration076 = resolve(
  root,
  "api/migrations/076_dis_kaynak_gecici_gorevlendirme.sql",
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
      expect((bundle.match(/'version' => '/g) ?? []).length).toBe(100);
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
      expect(bundle).toContain(
        "'name' => '071_org_hierarchy_authorization.sql'",
      );
      expect(bundle).toContain(
        "'name' => '072_org_reference_short_codes.sql'",
      );
      expect(bundle).toContain(
        "'name' => '073_test_fixture_personel_archive.sql'",
      );
      expect(bundle).toContain(
        "'name' => '074_qr_attendance_correction_and_inbox.sql'",
      );
      expect(bundle).toContain(
        "'name' => '075_personel_account_activation.sql'",
      );
      expect(bundle).toContain(
        "'name' => '076_dis_kaynak_gecici_gorevlendirme.sql'",
      );
      expect(bundle).toContain("'name' => '077_legacy_role_enum_shrink.sql'");
      expect(bundle).toContain("'name' => '078_personel_sicil_sequence.sql'");
      expect(bundle).toContain(
        "'name' => '079_sirket_sube_hiyerarsisi.sql'",
      );
      expect(bundle).toContain(
        "'name' => '080_organizasyon_audit_owners.sql'",
      );
      expect(bundle).toContain("'name' => '081_ik_personeli_rolu.sql'");
      expect(bundle).toContain(
        "'name' => '082_user_erisim_degisiklik_auditleri.sql'",
      );
      expect(bundle).toContain(
        "'name' => '083_personel_organizasyon_degisiklik_auditleri.sql'",
      );
      expect(bundle).toContain(
        "'name' => '084_gunluk_bildirim_tamamlama_header_summary.sql'",
      );
      expect(bundle).toContain(
        "'name' => '085_gunluk_bildirim_duzeltme_auditleri.sql'",
      );
      expect(bundle).toContain(
        "'name' => '086_personel_historical_exit_date_correction_auditleri.sql'",
      );
      expect(bundle).toContain(
        "'name' => '087_sube_muhasebe_yetkilileri.sql'",
      );
      expect(bundle).toContain(
        "'name' => '088_sube_sorumlu_yoneticiler.sql'",
      );
      expect(bundle).toContain(
        "'name' => '089_personel_legacy_account_activation.sql'",
      );
      expect(bundle).toContain(
        "'name' => '090_sgk_isveren_bildirim_donemi_owner.sql'",
      );
      expect(bundle).toContain(
        "'name' => '091_sgk_isveren_bildirim_donemi_reconcile.sql'",
      );
      expect(bundle).toContain(
        "'name' => '092_personel_self_service_product.sql'",
      );
      expect(bundle).toContain(
        "'name' => '093_attendance_anomaly_notification_dedupe.sql'",
      );
      expect(bundle).toContain("'name' => '094_attendance_no_event_day.sql'");
      expect(bundle).toContain("'name' => '095_personel_cinsiyet.sql'");
      expect(bundle).toContain("'name' => '096_personel_bordro_okumalari.sql'");
      expect(bundle).toContain("'name' => '097_qr_attendance_location_audit.sql'");
      expect(bundle).toContain("'name' => '098_birim_ad_duzeltme.sql'");
      expect(bundle).toContain("'name' => '099_user_kalici_silme_auditleri.sql'");

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

      const checksum071 = createHash("sha256")
        .update(readFileSync(migration071))
        .digest("hex");
      const entry071 = bundle.match(
        /'name' => '071_org_hierarchy_authorization\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry071?.[1]).toBe(checksum071);

      const checksum072 = createHash("sha256")
        .update(readFileSync(migration072))
        .digest("hex");
      const entry072 = bundle.match(
        /'name' => '072_org_reference_short_codes\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry072?.[1]).toBe(checksum072);

      const checksum073 = createHash("sha256")
        .update(readFileSync(migration073))
        .digest("hex");
      const entry073 = bundle.match(
        /'name' => '073_test_fixture_personel_archive\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry073?.[1]).toBe(checksum073);

      const checksum074 = createHash("sha256")
        .update(readFileSync(migration074))
        .digest("hex");
      const entry074 = bundle.match(
        /'name' => '074_qr_attendance_correction_and_inbox\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry074?.[1]).toBe(checksum074);

      const checksum075 = createHash("sha256")
        .update(readFileSync(migration075))
        .digest("hex");
      const entry075 = bundle.match(
        /'name' => '075_personel_account_activation\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry075?.[1]).toBe(checksum075);

      const checksum076 = createHash("sha256")
        .update(readFileSync(migration076))
        .digest("hex");
      const entry076 = bundle.match(
        /'name' => '076_dis_kaynak_gecici_gorevlendirme\.sql',[\s\S]*?'checksum' => '([a-f0-9]{64})'/,
      );
      expect(entry076?.[1]).toBe(checksum076);

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
          `if (count($rows) !== 100 || $rows[0]['version'] !== '000' || $rows[70]['version'] !== '070' || $rows[71]['version'] !== '071' || $rows[72]['version'] !== '072' || $rows[73]['version'] !== '073' || $rows[74]['version'] !== '074' || $rows[75]['version'] !== '075' || $rows[76]['version'] !== '076' || $rows[77]['version'] !== '077' || $rows[78]['version'] !== '078' || $rows[79]['version'] !== '079' || $rows[80]['version'] !== '080' || $rows[81]['version'] !== '081' || $rows[82]['version'] !== '082' || $rows[83]['version'] !== '083' || $rows[84]['version'] !== '084' || $rows[85]['version'] !== '085' || $rows[86]['version'] !== '086' || $rows[87]['version'] !== '087' || $rows[88]['version'] !== '088' || $rows[89]['version'] !== '089' || $rows[90]['version'] !== '090' || $rows[91]['version'] !== '091' || $rows[92]['version'] !== '092' || $rows[93]['version'] !== '093' || $rows[94]['version'] !== '094' || $rows[95]['version'] !== '095' || $rows[96]['version'] !== '096' || $rows[97]['version'] !== '097' || $rows[98]['version'] !== '098' || $rows[99]['version'] !== '099') { exit(1); }`,
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
