import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const STYLES_ROOT = resolve(process.cwd(), "src/styles");
const SRC_ROOT = resolve(process.cwd(), "src");
const COLORS_TOKEN_FILE = join(STYLES_ROOT, "tokens", "colors.css");
const MAIN_ENTRY = resolve(process.cwd(), "src/main.tsx");
const MAIN_CSS = join(STYLES_ROOT, "main.css");
const CARD_CSS = join(STYLES_ROOT, "components", "card.css");
const FORM_CSS = join(STYLES_ROOT, "components", "form.css");
const MODAL_CSS = join(STYLES_ROOT, "components", "modal.css");

const BLUE_BRAND_HEX = ["#3b82f6", "#2563eb", "#1d4ed8"] as const;

const TABLE_OWNER_CLASSES = [
  "raporlar-table",
  "yonetim-table",
  "yonetim-list-table",
  "puantaj-etki-aday-table",
  "personel-belge-kayit-table"
] as const;

function listFiles(dir: string, predicate: (name: string) => boolean): string[] {
  const entries = readdirSync(dir, { withFileTypes: true });
  const files: string[] = [];
  for (const entry of entries) {
    const absolute = join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...listFiles(absolute, predicate));
      continue;
    }
    if (entry.isFile() && predicate(entry.name)) {
      files.push(absolute);
    }
  }
  return files;
}

function rel(absolute: string): string {
  return absolute.slice(process.cwd().length + 1).replace(/\\/g, "/");
}

function read(path: string): string {
  return readFileSync(path, "utf8");
}

describe("PACK V2 visual standardization invariants", () => {
  it("keeps blue brand theme literals at zero outside colors.css", () => {
    const cssFiles = listFiles(STYLES_ROOT, (name) => name.endsWith(".css")).filter(
      (file) => file !== COLORS_TOKEN_FILE
    );
    const hits: string[] = [];
    for (const file of cssFiles) {
      const source = read(file).toLowerCase();
      for (const hex of BLUE_BRAND_HEX) {
        if (source.includes(hex)) {
          hits.push(`${rel(file)}:${hex}`);
        }
      }
    }
    expect(hits).toEqual([]);
  });

  it("does not introduce !important in styles", () => {
    const hits = listFiles(STYLES_ROOT, (name) => name.endsWith(".css"))
      .filter((file) => read(file).includes("!important"))
      .map(rel);
    expect(hits).toEqual([]);
  });

  it("keeps static inline style usage at zero in src TS/TSX", () => {
    const hits: string[] = [];
    const styleRe = /style=\{\{/g;
    for (const file of listFiles(SRC_ROOT, (name) => /\.(ts|tsx)$/.test(name))) {
      if (styleRe.test(read(file))) {
        hits.push(rel(file));
      }
      styleRe.lastIndex = 0;
    }
    expect(hits).toEqual([]);
  });

  it("allows direct CSS import only from main entry", () => {
    const hits: string[] = [];
    const importRe = /import\s+["'][^"']+\.css["']/g;
    for (const file of listFiles(SRC_ROOT, (name) => /\.(ts|tsx)$/.test(name))) {
      if (file === MAIN_ENTRY) {
        continue;
      }
      const matches = read(file).match(importRe);
      if (matches) {
        hits.push(`${rel(file)}:${matches.join(",")}`);
      }
    }
    expect(hits).toEqual([]);
  });

  it("resolves every CSS @import from main.css", () => {
    const mainCss = read(MAIN_CSS);
    const importRe = /@import\s+["']([^"']+)["']/g;
    const missing: string[] = [];
    let match: RegExpExecArray | null;
    while ((match = importRe.exec(mainCss)) !== null) {
      const absolute = resolve(STYLES_ROOT, match[1]);
      if (!existsSync(absolute)) {
        missing.push(match[1]);
      }
    }
    expect(missing).toEqual([]);
  });

  it("defines referenced CSS custom properties used without fallback", () => {
    const cssFiles = listFiles(STYLES_ROOT, (name) => name.endsWith(".css"));
    const defined = new Set<string>();
    const defRe = /--([a-zA-Z0-9-]+)\s*:/g;
    for (const file of cssFiles) {
      const source = read(file);
      let match: RegExpExecArray | null;
      defRe.lastIndex = 0;
      while ((match = defRe.exec(source)) !== null) {
        defined.add(match[1]);
      }
    }

    const usageRe = /var\(\s*--([a-zA-Z0-9-]+)\s*(?:,|\))/g;
    const undefinedUsages: string[] = [];
    for (const file of cssFiles) {
      const source = read(file);
      let match: RegExpExecArray | null;
      while ((match = usageRe.exec(source)) !== null) {
        if (match[0].includes(",")) {
          continue;
        }
        if (!defined.has(match[1])) {
          undefinedUsages.push(`${rel(file)}:--${match[1]}`);
        }
      }
    }
    expect(undefinedUsages).toEqual([]);
  });

  it("registers canonical shared card owner in the CSS graph", () => {
    expect(existsSync(CARD_CSS)).toBe(true);
    const cardSource = read(CARD_CSS);
    const mainCss = read(MAIN_CSS);
    expect(mainCss.includes('./components/card.css')).toBe(true);
    expect(cardSource.includes(".app-card")).toBe(true);
    expect(cardSource.includes(".app-card--compact")).toBe(true);
    expect(cardSource.includes(".app-card--elevated")).toBe(true);
    expect(cardSource.includes(".state-card")).toBe(true);
    expect(cardSource.includes(".yonetim-summary-card")).toBe(true);
    expect(cardSource.includes(".personel-surec-card")).toBe(true);
    expect(cardSource.includes(".kapanis-ozet-card")).toBe(true);
  });

  it("keeps canonical shared form owner with message family", () => {
    expect(existsSync(FORM_CSS)).toBe(true);
    const formSource = read(FORM_CSS);
    expect(formSource.includes(".form-label")).toBe(true);
    expect(formSource.includes(".form-input")).toBe(true);
    expect(formSource.includes(".form-error")).toBe(true);
    expect(formSource.includes(".form-help")).toBe(true);
    expect(formSource.includes(".form-readonly")).toBe(true);
    expect(formSource.includes(".workspace-error")).toBe(true);
  });

  it("keeps modal shared visual owner for AppModal / AppActionDialog", () => {
    expect(existsSync(MODAL_CSS)).toBe(true);
    const modalSource = read(MODAL_CSS);
    expect(modalSource.includes(".modal-overlay")).toBe(true);
    expect(modalSource.includes(".modal-container")).toBe(true);
    expect(modalSource.includes(".modal-header")).toBe(true);
    expect(modalSource.includes(".modal-footer")).toBe(true);
    expect(modalSource.includes(".modal-footer--flow")).toBe(true);
    expect(modalSource.includes(".app-action-dialog-actions")).toBe(true);
    expect(modalSource.includes(".app-action-dialog-body")).toBe(true);
  });

  it("preserves AppShell outlet context and kayit modal controller wiring", () => {
    const appShell = read(join(SRC_ROOT, "app", "AppShell.tsx"));
    expect(appShell.includes("export type AppShellOutletContext")).toBe(true);
    expect(appShell.includes("useKayitModalController")).toBe(true);

    const controller = join(SRC_ROOT, "features", "kayit", "hooks", "useKayitModalController.ts");
    expect(existsSync(controller)).toBe(true);
    expect(read(controller).includes("export function useKayitModalController")).toBe(true);
  });

  it("preserves permission-aware module navigation and MainMenu registration", () => {
    const moduleMenu = read(join(SRC_ROOT, "components", "shell", "ShellModuleMenu.tsx"));
    expect(moduleMenu.includes("modules-nav-link")).toBe(true);
    expect(moduleMenu.includes("modules.map")).toBe(true);

    const mainMenu = read(join(SRC_ROOT, "components", "main-menu", "MainMenu.tsx"));
    expect(mainMenu.includes('data-testid="menu-kayit-surec"')).toBe(true);
    expect(mainMenu.includes('data-testid="menu-personel-karti"')).toBe(true);
    expect(mainMenu.includes('data-testid="menu-raporlar"')).toBe(true);
    expect(mainMenu.includes("hasPermission")).toBe(true);
    expect(mainMenu.includes("hasAnyPermission")).toBe(true);
  });

  it("keeps target table owner classes available for PACK V3 (architecture freeze)", () => {
    const cssSources = listFiles(STYLES_ROOT, (name) => name.endsWith(".css")).map(read).join("\n");
    const missing: string[] = [];
    for (const owner of TABLE_OWNER_CLASSES) {
      if (!cssSources.includes(`.${owner}`)) {
        missing.push(owner);
      }
    }
    expect(missing).toEqual([]);
  });

  it("does not reintroduce soft-card surface triple outside card.css", () => {
    const cssFiles = listFiles(STYLES_ROOT, (name) => name.endsWith(".css")).filter(
      (file) => file !== CARD_CSS
    );
    const hits: string[] = [];
    for (const file of cssFiles) {
      const blocks = read(file).split("}");
      for (const block of blocks) {
        const hasBorder = /border:\s*1px solid\s+var\(--line-soft\)/.test(block);
        const hasRadius = /border-radius:\s*var\(--radius-(sm|md)\)/.test(block);
        const hasPad = /padding:\s*var\(--space-[23]\)/.test(block);
        if (hasBorder && hasRadius && hasPad) {
          const selector = block.match(/([^{]+)\{[^{]*$/)?.[1]?.trim().replace(/\s+/g, " ") ?? "?";
          hits.push(`${rel(file)}:${selector.slice(0, 80)}`);
        }
      }
    }
    expect(hits).toEqual([]);
  });
});
