import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const SRC = resolve(root, "src");

function read(relativePath: string): string {
  return readFileSync(resolve(root, relativePath), "utf8");
}

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      walk(full, out);
    } else {
      out.push(full);
    }
  }

  return out;
}

function srcFilesWith(extensions: string[]): string[] {
  return walk(SRC).filter((file) => extensions.some((extension) => file.endsWith(extension)));
}

function filesUsing(token: string): string[] {
  return srcFilesWith([".ts", ".tsx", ".css"])
    .filter((file) => readFileSync(file, "utf8").includes(token))
    .map((file) => file.replace(`${root}\\`, "").replace(`${root}/`, ""));
}

const RAPORLAR_PAGINATION_FILES = [
  "src/features/raporlar/pages/RaporlarPage.tsx",
  "src/features/raporlar/components/etki-adayi/EtkiAdayiRaporTablosu.tsx",
  "src/features/raporlar/components/donem-kapanis/KapanisPersonelDetayModal.tsx"
] as const;

const REVIZYON_BADGE_FILES = [
  "src/features/revizyon/pages/RevizyonMerkeziPage.tsx",
  "src/features/revizyon/pages/RevizyonTalebiDetailPage.tsx",
  "src/features/revizyon/pages/RevizyonCorrectionDetailPage.tsx"
] as const;

describe("stale visual class cleanup (canonical owner reuse)", () => {
  it("state-action-btn artık hiçbir yerde yok (paralel CSS yazılmadı)", () => {
    expect(filesUsing("state-action-btn")).toEqual([]);
  });

  it("personeller-status-badge artık hiçbir yerde yok (paralel CSS yazılmadı)", () => {
    expect(filesUsing("personeller-status-badge")).toEqual([]);
  });

  it("canonical owner sınıfları mevcut", () => {
    const buttons = read("src/styles/components/buttons.css");
    expect(buttons).toContain(".universal-btn-aux");
    expect(buttons).toContain(".universal-btn-aux[disabled]");

    const badge = read("src/styles/components/badge.css");
    expect(badge).toContain(".status-badge");
  });

  it("Raporlar pagination blokları canonical universal-btn-aux kullanır", () => {
    for (const file of RAPORLAR_PAGINATION_FILES) {
      const source = read(file);
      const blocks = source.match(/<div className="module-pagination"[^>]*>[\s\S]*?<\/div>/g) ?? [];

      expect(blocks, file).toHaveLength(1);
      expect(blocks[0].match(/universal-btn-aux/g) ?? [], file).toHaveLength(2);
      expect(blocks[0], file).not.toContain("state-action-btn");
    }
  });

  it("pagination disabled/loading anlamları ve testid'ler korunur", () => {
    const kapanisModal = read("src/features/raporlar/components/donem-kapanis/KapanisPersonelDetayModal.tsx");
    expect(kapanisModal).toContain("disabled={!hasPrevPage || isLoading}");
    expect(kapanisModal).toContain("disabled={!hasNextPage || isLoading}");

    const etkiAdayi = read("src/features/raporlar/components/etki-adayi/EtkiAdayiRaporTablosu.tsx");
    expect(etkiAdayi).toContain('data-testid="etki-adayi-rapor-pagination"');
    expect(etkiAdayi).toContain("disabled={!hasPrevPage}");
    expect(etkiAdayi).toContain("disabled={!hasNextPage}");

    const raporlar = read("src/features/raporlar/pages/RaporlarPage.tsx");
    expect(raporlar).toContain("disabled={isLoading || page <= 1}");
    expect(raporlar).toContain("disabled={isLoading || !hasNextPage}");

    const kapanisList = read("src/features/raporlar/components/donem-kapanis/KapanisIssueListesi.tsx");
    expect(kapanisList).toContain("data-testid={`donem-kapanis-issue-detail-${issue.code}`}");
    expect(kapanisList).toMatch(/className="universal-btn-aux"\s+data-testid=\{`donem-kapanis-issue-detail-\$\{issue\.code\}`\}/);
  });

  it("Revizyon durum rozetleri canonical status-badge kullanır, etiket/format değişmedi", () => {
    for (const file of REVIZYON_BADGE_FILES) {
      const source = read(file);
      expect(source, file).toContain('className="status-badge"');
      expect(source, file).not.toContain("personeller-status-badge");
    }

    expect(read("src/features/revizyon/pages/RevizyonMerkeziPage.tsx")).toContain(
      'className="status-badge">{formatRevizyonDurumLabel(talep.durum)}'
    );
    expect(read("src/features/revizyon/pages/RevizyonTalebiDetailPage.tsx")).toContain(
      'className="status-badge">{formatRevizyonDurumLabel(talep.durum)}'
    );
    expect(read("src/features/revizyon/pages/RevizyonCorrectionDetailPage.tsx")).toContain(
      '{correction.iptal_edildi_mi ? "İptal" : "Aktif"}'
    );
  });
});
