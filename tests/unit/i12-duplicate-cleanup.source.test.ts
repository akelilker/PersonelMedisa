import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

function walkSrcTsFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const st = statSync(full);
    if (st.isDirectory()) {
      walkSrcTsFiles(full, out);
      continue;
    }
    if (/\.(ts|tsx)$/.test(entry) && !entry.endsWith(".d.ts")) {
      out.push(full);
    }
  }
  return out;
}

function readSrcRuntimeCorpus(): string {
  const root = resolve(process.cwd(), "src");
  return walkSrcTsFiles(root)
    .map((path) => readFileSync(path, "utf8"))
    .join("\n");
}

describe("i12 duplicate cleanup source guards", () => {
  const corpus = readSrcRuntimeCorpus();

  it("removes legacy personel gateway intent strings from runtime src", () => {
    expect(corpus).not.toContain("personel-edit-gateway");
    expect(corpus).not.toContain("personel-zimmet-gateway");
    expect(corpus).not.toContain("KayitModalIntent");
    expect(corpus).not.toContain("useKayitGatewayIntent");
    expect(corpus).not.toContain("kayitEntryIntent");
    expect(corpus).not.toContain("initialIntent");
  });

  it("removes dead returnTo gateway plumbing from runtime src", () => {
    expect(corpus).not.toContain("kayitEntryReturnTo");
    expect(corpus).not.toContain("initialReturnTo");
    expect(corpus).not.toMatch(/\breturnTo\b/);
  });

  it("removes orphan zimmet create modal and redirect panel", () => {
    expect(corpus).not.toContain("PersonelZimmetCreateModal");
    expect(corpus).not.toContain("KayitGatewayRedirectPanel");
    expect(corpus).not.toContain("personel-modal-utils");
  });

  it("preserves canonical Card → Süreç gateway and zimmet form owner", () => {
    expect(corpus).toContain("Süreçte İşlem Yap");
    expect(corpus).toContain('tab: "surec"');
    expect(corpus).toContain("PersonelZimmetCreateForm");
    expect(corpus).toContain("PersonelInlineEditForm");
  });
});
