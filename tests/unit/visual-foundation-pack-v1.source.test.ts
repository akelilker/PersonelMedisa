import { readdirSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const STYLES_ROOT = resolve(process.cwd(), "src/styles");
const SRC_ROOT = resolve(process.cwd(), "src");
const COLORS_TOKEN_FILE = join(STYLES_ROOT, "tokens", "colors.css");
const MAIN_ENTRY = resolve(process.cwd(), "src/main.tsx");

/** Shared brand/surface literals that must live only in colors.css after PACK V1. */
const FORBIDDEN_SHARED_HEX = [
  "#1f2937",
  "#6ee7a8",
  "#f59e0b",
  "#2ecc71",
  "#5c6570",
  "#d8dee6",
  "#c0392b",
  "#10151f",
  "#ffb4b4",
  "#2563eb",
  "#3b82f6",
  "#fafafa",
  "#0f1418",
  "#111722",
  "#ff2222",
  "#ff9f2f",
  "#f39c12",
  "#b7791f",
  "#fb923c",
  "#5c5c5c"
] as const;

const BLUE_BRAND_HEX = ["#3b82f6", "#2563eb", "#1d4ed8"] as const;

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

describe("PACK V1 visual foundation invariants", () => {
  it("forbids shared brand/surface raw hex outside tokens/colors.css", () => {
    const cssFiles = listFiles(STYLES_ROOT, (name) => name.endsWith(".css")).filter(
      (file) => file !== COLORS_TOKEN_FILE
    );
    const hits: string[] = [];
    for (const file of cssFiles) {
      const source = readFileSync(file, "utf8").toLowerCase();
      for (const hex of FORBIDDEN_SHARED_HEX) {
        if (source.includes(hex)) {
          hits.push(`${rel(file)}:${hex}`);
        }
      }
    }
    expect(hits).toEqual([]);
  });

  it("keeps blue brand theme literals at zero outside colors.css", () => {
    const cssFiles = listFiles(STYLES_ROOT, (name) => name.endsWith(".css")).filter(
      (file) => file !== COLORS_TOKEN_FILE
    );
    const hits: string[] = [];
    for (const file of cssFiles) {
      const source = readFileSync(file, "utf8").toLowerCase();
      for (const hex of BLUE_BRAND_HEX) {
        if (source.includes(hex)) {
          hits.push(`${rel(file)}:${hex}`);
        }
      }
    }
    expect(hits).toEqual([]);
  });

  it("allows direct CSS import only from main entry", () => {
    const tsFiles = listFiles(SRC_ROOT, (name) => /\.(ts|tsx)$/.test(name));
    const hits: string[] = [];
    const importRe = /import\s+["'][^"']+\.css["']/g;
    for (const file of tsFiles) {
      if (file === MAIN_ENTRY) {
        continue;
      }
      const source = readFileSync(file, "utf8");
      const matches = source.match(importRe);
      if (matches) {
        hits.push(`${rel(file)}:${matches.join(",")}`);
      }
    }
    expect(hits).toEqual([]);
  });

  it("has no unresolved CSS @import paths from main.css", () => {
    const mainCss = readFileSync(join(STYLES_ROOT, "main.css"), "utf8");
    const importRe = /@import\s+["']([^"']+)["']/g;
    const missing: string[] = [];
    let match: RegExpExecArray | null;
    while ((match = importRe.exec(mainCss)) !== null) {
      const importPath = match[1];
      const absolute = resolve(STYLES_ROOT, importPath);
      try {
        readFileSync(absolute, "utf8");
      } catch {
        missing.push(importPath);
      }
    }
    expect(missing).toEqual([]);
  });

  it("keeps static inline style usage at zero in src TS/TSX", () => {
    const tsFiles = listFiles(SRC_ROOT, (name) => /\.(ts|tsx)$/.test(name));
    const hits: string[] = [];
    const styleRe = /style=\{\{/g;
    for (const file of tsFiles) {
      const source = readFileSync(file, "utf8");
      if (styleRe.test(source)) {
        hits.push(rel(file));
      }
      styleRe.lastIndex = 0;
    }
    expect(hits).toEqual([]);
  });

  it("does not introduce new !important in styles", () => {
    const cssFiles = listFiles(STYLES_ROOT, (name) => name.endsWith(".css"));
    const hits: string[] = [];
    for (const file of cssFiles) {
      const source = readFileSync(file, "utf8");
      if (source.includes("!important")) {
        hits.push(rel(file));
      }
    }
    expect(hits).toEqual([]);
  });

  it("defines referenced CSS custom properties used without fallback in styles", () => {
    const colorsSource = readFileSync(COLORS_TOKEN_FILE, "utf8");
    const allTokenFiles = listFiles(join(STYLES_ROOT, "tokens"), (name) => name.endsWith(".css"));
    const defined = new Set<string>();
    const defRe = /--([a-zA-Z0-9-]+)\s*:/g;
    for (const file of allTokenFiles) {
      const source = readFileSync(file, "utf8");
      let match: RegExpExecArray | null;
      while ((match = defRe.exec(source)) !== null) {
        defined.add(match[1]);
      }
      // also local component tokens
    }
    // Collect local custom props defined outside tokens too
    const cssFiles = listFiles(STYLES_ROOT, (name) => name.endsWith(".css"));
    for (const file of cssFiles) {
      const source = readFileSync(file, "utf8");
      let match: RegExpExecArray | null;
      defRe.lastIndex = 0;
      while ((match = defRe.exec(source)) !== null) {
        defined.add(match[1]);
      }
    }

    const usageRe = /var\(\s*--([a-zA-Z0-9-]+)\s*(?:,|\))/g;
    const undefinedUsages: string[] = [];
    for (const file of cssFiles) {
      if (file === COLORS_TOKEN_FILE) {
        // skip self-referential checks inside token file for aliases that resolve
      }
      const source = readFileSync(file, "utf8");
      let match: RegExpExecArray | null;
      while ((match = usageRe.exec(source)) !== null) {
        const name = match[1];
        // usages with fallback are ok even if undefined; this regex captures both
        // Only flag when no fallback: var(--name)
        const full = match[0];
        if (full.includes(",")) {
          continue;
        }
        if (!defined.has(name)) {
          undefinedUsages.push(`${rel(file)}:--${name}`);
        }
      }
    }
    expect(undefinedUsages).toEqual([]);
    // keep colors token file loaded so unused import warning does not appear in tooling
    expect(colorsSource.length).toBeGreaterThan(0);
  });
});
