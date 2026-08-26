import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  formatSgkImportUygunlukLabel,
  formatSgkSurumDurumLabel
} from "../../src/lib/display/sgk-display";
import { formatSurecStateLabel } from "../../src/lib/display/enum-display";

const root = process.cwd();

function read(rel: string) {
  return readFileSync(resolve(root, rel), "utf8");
}

function userVisibleSnippets(src: string): string[] {
  const out: string[] = [];
  const strRe = /(["'`])((?:\\.|(?!\1).)*)\1/g;
  let m: RegExpExecArray | null;
  while ((m = strRe.exec(src))) {
    const lit = m[2];
    if (lit.length < 2) continue;
    if (/^[a-zA-Z0-9_./:-]+$/.test(lit)) continue;
    if (!/[\sA-Za-zÇĞİÖŞÜçğıöşü]/.test(lit)) continue;
    out.push(lit);
  }
  const jsxRe = />(\s*[^<>{}\n][^<>{}]*?)\s*</g;
  while ((m = jsxRe.exec(src))) {
    const t = m[1].trim();
    if (t) out.push(t);
  }
  return out;
}

describe("sgk display formatters", () => {
  it("maps ONAY_BEKLIYOR to Turkish label", () => {
    expect(formatSgkSurumDurumLabel("ONAY_BEKLIYOR")).toBe("Onay Bekliyor");
    expect(formatSurecStateLabel("ONAY_BEKLIYOR")).toBe("Onay Bekliyor");
  });

  it("maps import eligibility booleans to Turkish", () => {
    expect(formatSgkImportUygunlukLabel(true)).toBe("Uygun");
    expect(formatSgkImportUygunlukLabel(false)).toBe("Uygun değil");
  });
});

describe("sgk katalog raw technical UI guards", () => {
  it("does not expose payload_hash or import_yapilabilir_mi as user-visible labels", () => {
    const panel = read("src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx");
    const visible = userVisibleSnippets(panel).join("\n");
    expect(visible).not.toContain("payload_hash:");
    expect(visible).not.toContain("import_yapilabilir_mi:");
    expect(visible).not.toContain("esleme_payload_hash:");
    expect(visible).not.toContain("apply_yapilabilir_mi:");
    expect(visible).not.toContain("politika_hash:");
    expect(visible).toContain("Doğrulama Kodu");
    expect(visible).toContain("İçe Aktarmaya Uygun");
    expect(visible).toContain("İşlem Özeti");
    // API payload fields remain internal
    expect(panel).toContain("payload_hash:");
    expect(panel).toContain("import_yapilabilir_mi");
  });

  it("does not show raw ONAY_BEKLIYOR in user-visible dialog text", () => {
    const panel = read("src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx");
    const visible = userVisibleSnippets(panel).join("\n");
    expect(visible).not.toMatch(/ONAY_BEKLIYOR durum/);
    expect(visible).not.toMatch(/sürümü ONAY_BEKLIYOR/);
    expect(panel).toContain("SGK_ONAY_BEKLIYOR_LABEL");
  });
});

describe("saklama UI plain Turkish redesign", () => {
  it("keeps backend target_domain personel while hiding technical fields", () => {
    const panel = read("src/features/yonetim/components/SaklamaLegalHoldPanel.tsx");
    expect(panel).toContain('const TARGET_DOMAIN_PERSONEL = "personel"');
    expect(panel).toContain("target_domain: TARGET_DOMAIN_PERSONEL");
    expect(panel).not.toContain("Hedef domain");
    expect(panel).not.toMatch(/Legal hold oluştur/i);
    expect(panel).toContain("Korumaya Al");
  });
});
