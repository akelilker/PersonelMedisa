import { describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";

const servicePath = resolve(
  process.cwd(),
  "api/src/Services/SerbestZaman/SerbestZamanAllocationService.php"
);
const serviceSource = readFileSync(servicePath, "utf8");
const migration061 = readFileSync(
  resolve(process.cwd(), "api/migrations/061_serbest_zaman_kullanim_tahsisleri.sql"),
  "utf8"
);

describe("SerbestZamanAllocationService source locks (Pack4A)", () => {
  it("exposes consume/release policy constants", () => {
    expect(serviceSource).toContain("POLICY_CONSUME = 'EARLIEST_EXPIRY_FIRST_V1'");
    expect(serviceSource).toContain("POLICY_RELEASE = 'REVERSE_EARLIEST_EXPIRY_FIRST_V1'");
  });

  it("exposes legacy fail-closed codes and states", () => {
    expect(serviceSource).toContain("STATE_LEGACY_UNALLOCATED = 'LEGACY_UNALLOCATED'");
    expect(serviceSource).toContain(
      "CODE_LEGACY_ALLOCATION_REQUIRED = 'SERBEST_ZAMAN_LEGACY_ALLOCATION_REQUIRED'"
    );
    expect(serviceSource).toContain("CODE_OLUSUM_HAS_ALLOCATIONS");
    expect(serviceSource).toContain("assertOlusumHasNoNetAllocation");
    expect(serviceSource).toContain("assertOlusumEffectiveCoversAllocation");
    expect(serviceSource).toContain("assertWritableForNewUsage");
  });

  it("lot invariants do not skip zero-effective stranded lots", () => {
    expect(serviceSource).toContain("assertLotInvariants");
    expect(serviceSource).not.toMatch(
      /assertLotInvariants[\s\S]{0,400}if\s*\(\s*\$effective\s*<=\s*0\s*\)\s*\{\s*continue;/
    );
  });

  it("does not implement FIFO/LIFO auto-backfill language", () => {
    expect(serviceSource).not.toMatch(/\bFIFO\b/);
    expect(serviceSource).not.toMatch(/\bLIFO\b/);
    expect(serviceSource).toMatch(/NO auto-backfill/i);
    expect(serviceSource).not.toMatch(/\bautoBackfill\b/i);
    expect(serviceSource).not.toMatch(/function\s+\w*[Bb]ackfill/);
    expect(migration061).toMatch(/NO DATA BACKFILL/i);
    expect(migration061).not.toMatch(/\bFIFO\b/);
    expect(migration061).not.toMatch(/\bLIFO\b/);
  });
});
