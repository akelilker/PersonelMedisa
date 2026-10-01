import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("ui correction batch — modal back + missing info owners", () => {
  it("AppModal renders headerStart and target back label together via modal-header-leading", () => {
    const modal = read("src/components/modal/AppModal.tsx");
    expect(modal).toMatch(/modal-header-leading/);
    expect(modal).toMatch(/headerStart/);
    expect(modal).toMatch(/ModalBackButton/);
    expect(modal).not.toMatch(/headerStart \? \([\s\S]*\) : onBack && backLabel/);
  });

  it("canonical modal header inset is 8px and body back-bar aligns to 8px", () => {
    const css = read("src/styles/components/modal.css");
    expect(css).toMatch(/\.modal-header-leading\s*\{[^}]*left:\s*8px/s);
    expect(css).toMatch(/\.modal-body > \.universal-back-bar[\s\S]*margin-inline:\s*calc\(8px - var\(--modal-body-inline-pad\)\)/s);
    expect(css).toMatch(/\.modal-close-btn[\s\S]*right:\s*8px/s);
  });

  it("Bugünkü Personel Durumu uses parent screen labels instead of Geri", () => {
    const bugun = read("src/features/bildirimler/components/BugunPersonelDurumuModal.tsx");
    expect(bugun).toMatch(/resolveBugunBackLabel/);
    expect(bugun).not.toMatch(/backLabel=\{nav\.kind === "branches" \? undefined : "Geri"\}/);
    expect(bugun).not.toMatch(/goHome\(\);\s*onClose\(\)/);
  });

  it("missing-info gateway opens kayit overlay on current kart path and auto-edits", () => {
    const hook = read("src/features/kayit/hooks/useKayitModalController.ts");
    expect(hook).toMatch(/kayitIntent/);

    const gateway = read("src/features/personeller/hooks/usePersonelKartGatewayReturn.ts");
    expect(gateway).toMatch(/overlayPath/);
    expect(gateway).not.toMatch(/navigate\("\/",\s*\{[\s\S]*personel-missing-info-gateway/s);

    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).toMatch(/personel-missing-info-gateway/);
    expect(workspace).toMatch(/openEditOnMount/);

    const panel = read("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx");
    expect(panel).toMatch(/openEditOnMount/);
  });

  it("missing-info gateway copy names the first missing field; salary is not in completeness rules", () => {
    const gatewayUi = read("src/features/personeller/components/personel-dosya/PersonelDosyaMissingInfoGateway.tsx");
    expect(gatewayUi).toMatch(/primaryField\.label/);

    const policy = read("src/features/personeller/personel-missing-info.ts");
    expect(policy).not.toMatch(/maas_tutari/);
    expect(policy).not.toMatch(/"Maaş"/);
  });

  it("adds target-label header back on remaining Level 2+ modal owners", () => {
    const qr = read("src/features/puantaj/components/QrPuantajAdayiSection.tsx");
    expect(qr).toMatch(/backLabel="QR Puantaj Adayı"/);

    const etki = read("src/features/puantaj/components/BildirimPuantajEtkiAdaylariSection.tsx");
    expect(etki).toMatch(/etkiAdayListBackLabel/);
    expect(etki).toMatch(/backLabel=\{etkiAdayListBackLabel\}/);

    const kapanis = read("src/features/raporlar/components/donem-kapanis/KapanisPersonelDetayModal.tsx");
    expect(kapanis).toMatch(/backLabel="Dönem Kapanış Kontrolleri"/);

    const belgeler = read("src/features/personeller/components/personel-dosya/PersonelBelgelerPanel.tsx");
    expect(belgeler).toMatch(/backLabel="Personel Belgeleri"/);

    const ucret = read("src/features/personeller/components/personel-dosya/PersonelUcretCreateModal.tsx");
    expect(ucret).toMatch(/backLabel="Ücret Geçmişi"/);

    const rtt = read("src/features/yonetim/pages/ResmiTatilTakvimiPage.tsx");
    expect(rtt).toMatch(/backLabel="Resmî Tatil Takvimi"/);
  });

  it("tightens eksik bilgi banner vertical rhythm in tab scroll owner", () => {
    const missingCss = read("src/styles/modules/personel-missing-info.css");
    expect(missingCss).toMatch(/\.personel-dosya-missing-gateway-wrap\s*\{[^}]*margin:\s*0/s);

    const personellerCss = read("src/styles/modules/personeller.css");
    expect(personellerCss).toMatch(/\.personel-dosya-tab-scroll\s*\{[^}]*gap:\s*var\(--space-2\)/s);
  });

  it("kayit detour pages use KayitSurecBackBar with parent screen label and return state", () => {
    const backBar = read("src/features/kayit/components/KayitSurecBackBar.tsx");
    expect(backBar).toMatch(/buildKayitSurecReturnState/);
    expect(backBar).toMatch(/label=\{label\}/);

    const belge = read("src/features/personeller/pages/BelgeTakipPage.tsx");
    expect(belge).toMatch(/KayitSurecReturnLink context=\{kayitSurecReturn\} label="Belge Takip"/);
    expect(belge).not.toMatch(/Kayıt ve Süreç'e dön/);
    expect(belge).not.toMatch(/Personellere dön/);

    const gunluk = read("src/features/puantaj/pages/GunlukPuantajPage.tsx");
    expect(gunluk).toMatch(/label="Puantaj"/);
  });

  it("puantaj inline child flows expose canonical back to Puantaj hub", () => {
    const panel = read("src/features/kayit/components/KayitSurecPersonelPuantajPanel.tsx");
    expect(panel).toMatch(/SurecInlineBackButton/);
    expect(panel).toMatch(/label="Puantaj"/);

    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).toMatch(/backToPuantajHub/);
    expect(workspace).toMatch(/onBackToPuantajHub=\{backToPuantajHub\}/);
  });

  it("görev/organizasyon Vazgeç resets extended sube transfer state", () => {
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).toMatch(/setYeniSubeId\(""\)/);
    expect(workspace).toMatch(/setSubeGerekce\(""\)/);
    expect(workspace).toMatch(/setSubeTransferError\(null\)/);
    expect(workspace).toMatch(/onSecondaryClick: resetPozisyonForm/);
  });

  it("/self reverse gateway and title hierarchy owners", () => {
    const gateway = read("src/features/self-service/components/SelfAppReverseGateway.tsx");
    expect(gateway).toMatch(/Personel Yönetim Sistemi/);
    expect(gateway).toMatch(/home-self-service-gateway/);

    const shell = read("src/app/AppShell.tsx");
    expect(shell).toMatch(/SelfAppReverseGateway/);
    expect(shell).toMatch(/isSelfSurfaceRoute/);

    const heroCss = read("src/styles/components/hero.css");
    expect(heroCss).toMatch(
      /body\.app-home-route \.hero\.hero--personel-shell \.hero-title-stack \.hero-panel-subtitle[\s\S]*font-size: min\(17px/s
    );
    expect(heroCss).toMatch(
      /body\.app-home-route \.hero\.hero-with-session\.hero--panel-subtitle \.hero-title-stack \.hero-panel-subtitle/
    );

    const selfCss = read("src/features/self-service/self-service.css");
    expect(selfCss).toMatch(/\.pm-self-identity--home[\s\S]*align-items: flex-start/s);
    expect(selfCss).toMatch(/align-self: flex-start/);
  });

  it("import history detail footer avoids duplicate list back CTA", () => {
    const modal = read("src/features/personeller/components/PersonelImportHistoryModal.tsx");
    expect(modal).toMatch(/backLabel="Personel Import Geçmişi"/);
    expect(modal).not.toMatch(/Listeye dön/);
    expect(modal).toMatch(/Kapat/);
  });
});
