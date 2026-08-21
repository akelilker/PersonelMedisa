import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join, relative, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = process.cwd();
const STYLES_ROOT = resolve(ROOT, "src/styles");
const SRC_ROOT = resolve(ROOT, "src");
const MAIN_CSS = join(STYLES_ROOT, "main.css");
const MAIN_ENTRY = resolve(ROOT, "src/main.tsx");
const COLORS_TOKEN_FILE = join(STYLES_ROOT, "tokens", "colors.css");
const MOBILE_CSS = join(STYLES_ROOT, "platform", "mobile.css");
const IOS_PWA_CSS = join(STYLES_ROOT, "platform", "ios-pwa.css");
const ANDROID_PWA_CSS = join(STYLES_ROOT, "platform", "android-pwa.css");
const TABLE_CSS = join(STYLES_ROOT, "components", "table.css");
const MODAL_CSS = join(STYLES_ROOT, "components", "modal.css");
const PUANTAJ_CSS = join(STYLES_ROOT, "modules", "puantaj.css");
const APP_SHELL_TSX = join(SRC_ROOT, "app", "AppShell.tsx");

const BLUE_BRAND_HEX = ["#3b82f6", "#2563eb", "#1d4ed8"] as const;

const SAFE_AREA_ALLOWLIST = [
  "src/styles/platform/",
  "src/styles/layout/",
  "src/styles/components/footer.css",
  "src/styles/components/modal.css",
  "src/styles/components/icons-row.css",
  "src/styles/components/notifications.css",
  "src/styles/modules/kayit-surec.css"
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
  return relative(ROOT, absolute).replace(/\\/g, "/");
}

function read(path: string): string {
  return readFileSync(path, "utf8");
}

function isSafeAreaAllowed(fileRel: string): boolean {
  return SAFE_AREA_ALLOWLIST.some((prefix) => fileRel === prefix || fileRel.startsWith(prefix));
}

describe("PACK V4 mobile/PWA visual parity invariants", () => {
  it("keeps platform mobile / ios-pwa / android-pwa owners registered in main.css", () => {
    expect(existsSync(MOBILE_CSS)).toBe(true);
    expect(existsSync(IOS_PWA_CSS)).toBe(true);
    expect(existsSync(ANDROID_PWA_CSS)).toBe(true);
    const main = read(MAIN_CSS);
    expect(main).toContain('@import "./platform/mobile.css";');
    expect(main).toContain('@import "./platform/ios-pwa.css";');
    expect(main).toContain('@import "./platform/android-pwa.css";');
    expect(main.indexOf('@import "./platform/mobile.css";')).toBeGreaterThan(
      main.indexOf('@import "./components/table.css";')
    );
  });

  it("keeps PACK V3 table owner available before platform CSS", () => {
    expect(existsSync(TABLE_CSS)).toBe(true);
    const table = read(TABLE_CSS);
    expect(table).toContain(".app-table-wrap");
    expect(table).toContain(".personeller-table");
    expect(table).toContain("overflow-x: auto");
  });

  it("preserves puantaj table→card mobile breakpoint contract", () => {
    const source = read(PUANTAJ_CSS);
    expect(source).toMatch(/@media\s*\(max-width:\s*720px\)/);
    expect(source).toContain(".puantaj-etki-aday-table-wrap");
    expect(source).toContain("display: none");
    expect(source).toContain(".puantaj-etki-aday-card-list");
    expect(source).toContain("display: grid");
  });

  it("keeps modal viewport-safe bottom clearance with safe-area", () => {
    const source = read(MODAL_CSS);
    expect(source).toContain("env(safe-area-inset-bottom");
    expect(source).toContain("--modal-gap-above-footer");
    expect(source).toContain(".modal-body:has(> .personeller-page)");
    expect(source).toContain("overflow: hidden");
  });

  it("keeps blue brand theme literals at zero outside colors.css", () => {
    const hits: string[] = [];
    for (const file of listFiles(STYLES_ROOT, (name) => name.endsWith(".css"))) {
      if (file === COLORS_TOKEN_FILE) continue;
      const source = read(file).toLowerCase();
      for (const hex of BLUE_BRAND_HEX) {
        if (source.includes(hex)) hits.push(`${rel(file)}:${hex}`);
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
      if (styleRe.test(read(file))) hits.push(rel(file));
      styleRe.lastIndex = 0;
    }
    expect(hits).toEqual([]);
  });

  it("allows direct CSS import only from main entry", () => {
    const hits: string[] = [];
    const importRe = /import\s+["'][^"']+\.css["']/g;
    for (const file of listFiles(SRC_ROOT, (name) => /\.(ts|tsx)$/.test(name))) {
      if (file === MAIN_ENTRY) continue;
      const matches = read(file).match(importRe);
      if (matches) hits.push(`${rel(file)}:${matches.join(",")}`);
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
      if (!existsSync(absolute)) missing.push(match[1]);
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
      while ((match = defRe.exec(source)) !== null) defined.add(match[1]);
    }

    const usageRe = /var\(\s*--([a-zA-Z0-9-]+)\s*(?:,|\))/g;
    const undefinedUsages: string[] = [];
    for (const file of cssFiles) {
      const source = read(file);
      let match: RegExpExecArray | null;
      while ((match = usageRe.exec(source)) !== null) {
        if (match[0].includes(",")) continue;
        if (!defined.has(match[1])) undefinedUsages.push(`${rel(file)}:--${match[1]}`);
      }
    }
    expect(undefinedUsages).toEqual([]);
  });

  it("limits safe-area usage to platform/layout/chrome owners", () => {
    const hits: string[] = [];
    for (const file of listFiles(STYLES_ROOT, (name) => name.endsWith(".css"))) {
      const fileRel = rel(file);
      if (!read(file).includes("safe-area-inset")) continue;
      if (!isSafeAreaAllowed(fileRel)) hits.push(fileRel);
    }
    // also allow self-service? no
    expect(hits).toEqual([]);
  });

  it("does not introduce page-level horizontal overflow via 100vw in platform/mobile.css", () => {
    const mobile = read(MOBILE_CSS);
    expect(mobile).not.toMatch(/100vw/);
    expect(mobile).toContain("overflow-x: hidden");
    expect(mobile).toContain("min-width: 0");
  });

  it("preserves AppShell functional wiring (presentation-only pack)", () => {
    const appShell = read(APP_SHELL_TSX);
    expect(appShell).toContain("export type AppShellOutletContext");
    expect(appShell).toContain("useKayitModalController");
    expect(appShell).toContain("<Outlet");
  });

  it("keeps mobile foundation free of !important and raw hex", () => {
    for (const file of [MOBILE_CSS, IOS_PWA_CSS, ANDROID_PWA_CSS]) {
      const source = read(file);
      expect(source).not.toContain("!important");
      expect(source).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
    }
  });
});
