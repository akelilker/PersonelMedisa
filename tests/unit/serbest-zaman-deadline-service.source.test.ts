import { describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";

const deadlineSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/SerbestZaman/SerbestZamanDeadlineService.php"),
  "utf8"
);
const payrollGuardSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/Payroll/PayrollComplianceGuard.php"),
  "utf8"
);

describe("SerbestZamanDeadlineService source locks (Pack4B)", () => {
  it("locks WARNING_DAYS=30 and operational compliance contract", () => {
    expect(deadlineSource).toContain("WARNING_DAYS = 30");
    expect(deadlineSource).toContain(
      "COMPLIANCE_MODE = 'WARNING_AND_OPERATIONAL_FOLLOWUP'"
    );
    expect(deadlineSource).toContain("PAYROLL_HARD_BLOCK = false");
    expect(deadlineSource).toMatch(/PAYROLL_HARD_BLOCK\s*=\s*NO/);
    expect(deadlineSource).toContain("DEADLINE_YAKLASIYOR = 'YAKLASIYOR'");
    expect(deadlineSource).toContain("DEADLINE_SURESI_DOLDU = 'SURESI_DOLDU'");
    expect(deadlineSource).toContain(
      "DEADLINE_ALLOCATION_UNRESOLVED = 'ALLOCATION_UNRESOLVED'"
    );
  });

  it("documents expiry boundary: referans<=son ACTIVE; referans>son EXPIRED", () => {
    expect(deadlineSource).toMatch(
      /Expiry boundary:\s*referans_tarih\s*<=\s*son_kullanim_tarihi\s*→\s*ACTIVE;\s*>\s*→\s*EXPIRED/
    );
    expect(deadlineSource).toMatch(
      /Warning window \(operational only\):\s*30 days/
    );
    expect(deadlineSource).toMatch(
      /LEGACY_UNALLOCATED\s*\/\s*INVARIANT_BROKEN\s*→\s*ALLOCATION_UNRESOLVED/
    );
  });

  it("does not invent payroll hard-block for 6-month expiry", () => {
    expect(deadlineSource).toContain("payroll_hard_block' => self::PAYROLL_HARD_BLOCK");
    expect(deadlineSource).not.toMatch(/PAYROLL_HARD_BLOCK\s*=\s*true/);
  });

  it("PayrollComplianceGuard has no new 6M deadline hard blocker", () => {
    expect(payrollGuardSource).not.toMatch(/SURESI_DOLDU/);
    expect(payrollGuardSource).not.toMatch(/YAKLASIYOR/);
    expect(payrollGuardSource).not.toMatch(/ALLOCATION_UNRESOLVED/);
    expect(payrollGuardSource).not.toMatch(/son_kullanim_tarihi/);
    expect(payrollGuardSource).not.toMatch(/WARNING_DAYS/);
    expect(payrollGuardSource).not.toMatch(/SerbestZamanDeadlineService/);
    expect(payrollGuardSource).not.toMatch(/6.?MONTH|6.?AY|SIX_MONTH/i);
    expect(payrollGuardSource).not.toMatch(
      /BLOCKER_.*SERBEST_ZAMAN.*(DEADLINE|EXPIR|SURESI|6M)/i
    );
  });

  it("isSchemaReady requires events + allocation ledger and asserts before project", () => {
    expect(deadlineSource).toContain("CODE_SCHEMA_NOT_READY = 'SCHEMA_NOT_READY'");
    expect(deadlineSource).toContain("function isSchemaReady");
    expect(deadlineSource).toContain("function assertSchemaReady");
    expect(deadlineSource).toMatch(
      /isSchemaReady[\s\S]{0,220}serbest_zaman_events[\s\S]{0,220}serbest_zaman_kullanim_tahsisleri/
    );
    expect(deadlineSource).toMatch(
      /projectPersonelDeadlineRows\([\s\S]{0,200}assertSchemaReady\(\$pdo\)/
    );
    expect(deadlineSource).toMatch(
      /assertSchemaReady[\s\S]{0,180}throw new \\RuntimeException\(self::CODE_SCHEMA_NOT_READY\)/
    );
  });
});
