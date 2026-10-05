import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("personel detay gorsel duzen paketi owners", () => {
  it("exposes sticky hero identity chips from existing personel fields", () => {
    const hero = read("src/features/personeller/components/personel-dosya/PersonelDosyaHero.tsx");
    expect(hero).toMatch(/data-testid="personel-dosya-hero"/);
    expect(hero).toMatch(/personel-dosya-hero-name/);
    expect(hero).toMatch(/data-testid="personel-dosya-hero-status"/);
    expect(hero).toMatch(/personel-dosya-status-label/);
    expect(hero).toMatch(/data-testid="personel-dosya-hero-sicil"/);
    expect(hero).toMatch(/data-testid="personel-dosya-hero-kapsam"/);
    expect(hero).toMatch(/formatCalisanKapsamiLabel\(personel\.calisan_kapsami/);
    expect(hero).toMatch(/data-testid="personel-dosya-hero-org"/);
    expect(hero).not.toMatch(/>\s*IC_PERSONEL\s*</);
    expect(hero).not.toMatch(/>\s*DIS_KAYNAK\s*</);
    expect(hero).not.toMatch(/fetch[A-Z]|useQuery|useEffect/);
  });

  it("places Islemler above ozluk mirror inside Genel panel", () => {
    const panels = read(
      "src/features/personeller/components/personel-dosya/PersonelDosyaTabPanels.tsx"
    );
    const genelPanel = panels.match(
      /id="personel-kart-panel-genel-bilgiler"[\s\S]*?<\/div>\s*\) : null\}/
    )?.[0];
    expect(genelPanel).toBeTruthy();
    expect(genelPanel).toMatch(
      /PersonelDosyaActionRow[\s\S]*PersonelDosyaGenelUst[\s\S]*PersonelKartPanelGenelBilgiler/s
    );
    expect(genelPanel).not.toMatch(/PersonelDosyaGenelUst[\s\S]*PersonelDosyaActionRow/s);

    const actionRow = read(
      "src/features/personeller/components/personel-dosya/PersonelDosyaActionRow.tsx"
    );
    expect(actionRow).toMatch(/data-testid="personel-dosya-actions-row"/);
  });

  it("splits Genel into ozluk and operasyon zones without new tabs", () => {
    const genelUst = read(
      "src/features/personeller/components/personel-dosya/PersonelDosyaGenelUst.tsx"
    );
    expect(genelUst).toMatch(/personel-dosya-zone--ozluk/);
    expect(genelUst).toMatch(/Özlük \/ kayıt özeti/);

    const genelPanel = read(
      "src/features/personeller/components/personel-dosya/PersonelKartPanelGenelBilgiler.tsx"
    );
    expect(genelPanel).toMatch(/personel-dosya-zone--operasyon/);
    expect(genelPanel).toMatch(/data-testid="personel-dosya-zone-operasyon"/);
    expect(genelPanel).toMatch(/Operasyonel özetler/);

    const tabs = read("src/features/personeller/components/personel-dosya/PersonelDosyaTabs.tsx");
    expect(tabs).toMatch(/id: "genel-bilgiler"/);
    expect(tabs.match(/id: "/g)?.length).toBe(5);
  });

  it("aligns QR history with dossier section classes and keeps table scroll wrap", () => {
    const qr = read(
      "src/features/personeller/components/personel-dosya/PersonelQrHistorySection.tsx"
    );
    expect(qr).toMatch(/className="personel-dosya-section personel-qr-history"/);
    expect(qr).toMatch(/personel-dosya-section-head--with-action/);
    expect(qr).toMatch(/personel-qr-history-table-wrap/);
    expect(qr).toMatch(/raporlar-table-wrap/);
    expect(qr).toMatch(/personel-qr-history-card-list/);
    expect(qr).toMatch(/pm-self-request-list/);
    expect(qr).toMatch(/data-testid="personel-qr-history-cards"/);
    expect(qr).not.toMatch(/personel-dossier-section/);

    const css = read("src/styles/modules/personeller.css");
    expect(css).toMatch(/\.personel-qr-history-table-wrap[\s\S]*overflow-x:\s*auto/s);
    expect(css).toMatch(/\.personel-qr-history[\s\S]*min-width:\s*0/s);
    expect(css).toMatch(/@media \(max-width: 720px\)[\s\S]*\.personel-qr-history-table-wrap[\s\S]*display:\s*none/s);
    expect(css).toMatch(/\.personel-qr-history-card-list[\s\S]*display:\s*grid/s);
  });

  it("scopes mobile mirror stack and notice styles to personel-detail-card", () => {
    const css = read("src/styles/modules/personeller.css");
    expect(css).toMatch(
      /@media \(max-width: 640px\)[\s\S]*\.personel-detail-card \.personel-dosya-kayit-mirror \.personel-form-columns[\s\S]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s
    );
    expect(css).toMatch(/\.personel-archive-banner/);
    expect(css).toMatch(/\.personel-write-scope-notice/);
    expect(css).toMatch(/\.personel-dosya-info-notice/);

    const kayitCss = read("src/styles/modules/kayit-surec.css");
    const kayitMobileBlock =
      kayitCss.match(/@media \(max-width: 640px\)[\s\S]*?\.personel-form-columns \{[^}]+\}/)?.[0] ??
      "";
    // Shared owner mobilde tek kolona iner (mirror aynı davranışı devralır).
    expect(kayitMobileBlock).toMatch(/grid-template-columns:\s*minmax\(0,\s*1fr\)\s*;/);

    const missingCss = read("src/styles/modules/personel-missing-info.css");
    expect(missingCss).toMatch(
      /\.personel-detail-card \.personel-dosya-field\.is-missing[\s\S]*border-color:\s*rgba\(var\(--theme-color-rgb\),\s*0\.28\)/s
    );
    expect(missingCss).toMatch(/\.personeller-missing-badge/);
  });
});
