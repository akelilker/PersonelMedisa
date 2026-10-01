import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("PERSONEL mobile product phase 1 shell", () => {
  it("binds home identity to GET /me and keeps attendance owners", () => {
    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).toContain("fetchMe(");
    expect(home).toContain("buildPersonelSelfIdentityView");
    expect(home).toContain("PersonelSelfServiceIdentity");
    expect(home).toContain("PersonelSelfServiceMenu");
    expect(home).toContain("OwnQrAttendanceBoxes");
    expect(home).not.toContain("PersonelSelfServiceHomeInfoBlock");
    expect(home).not.toContain("fetchMeYillikIzinBakiye");
    expect(home).toContain("useSelfProfilFoto");
    expect(home).toContain("AttendanceCorrectionRequestModal");
    expect(home).not.toContain("<img");
    expect(home).not.toContain("avatar");

    const identity = read("src/features/self-service/components/PersonelSelfServiceIdentity.tsx");
    expect(identity).not.toContain("<img");
    expect(identity).not.toContain("avatar");
    expect(identity).toContain("Doğum Tarihi");
    expect(identity).toContain("Cinsiyet");
    expect(identity).toContain("Telefon");
    expect(identity).toContain("Sicil No.");
    expect(identity).toContain("TC Kimlik No");
    expect(identity).toContain("Kan Grubu");
    expect(identity).toContain("İşe Giriş");
    expect(identity).toContain("Çalışılan Süre");
    expect(identity).toContain("pm-self-identity__label");
    expect(identity).toContain("pm-self-identity__value");
    expect(identity.indexOf("Doğum Tarihi")).toBeLessThan(identity.indexOf("Cinsiyet"));
    expect(identity.indexOf("Cinsiyet")).toBeLessThan(identity.indexOf("Telefon"));
    expect(identity.indexOf("Telefon")).toBeLessThan(identity.indexOf("Sicil No."));
    expect(identity.indexOf("Sicil No.")).toBeLessThan(identity.indexOf("TC Kimlik No"));
    expect(identity.indexOf("TC Kimlik No")).toBeLessThan(identity.indexOf("Kan Grubu"));
    expect(identity.indexOf("Kan Grubu")).toBeLessThan(identity.indexOf("İşe Giriş"));
    expect(identity.indexOf("İşe Giriş")).toBeLessThan(identity.indexOf("Çalışılan Süre"));

    const menu = read("src/features/self-service/components/PersonelSelfServiceMenu.tsx");
    expect(menu).toContain("PERSONEL_SELF_HOME_DOCK_MENU");
    expect(menu).toContain('className="pm-self-dock"');
    expect(menu).toContain('d="M5 22h14"');
    expect(menu).toContain('d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"');
    expect(menu).toContain('d="m14.5 13.5 5-5 2 2-5 5-3 1 1-3z"');
    expect(menu).toContain('<circle cx="12" cy="12" r="8" />');
    expect(menu).toContain("pm-self-dock__icon-wrap--");
    expect(menu).not.toContain("personel-menu-duyurular");
    expect(menu).not.toContain("personel-menu-profil");
    expect(menu).not.toContain("fetchSelfDuyurular");
    expect(menu).not.toContain("Öz Servis");
    expect(home).not.toContain("Öz Servis");
    expect(home).not.toContain("self-service-fab");
    expect(home).not.toContain("HomeSelfServiceGateway");

    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(".pm-self-dock__icon-wrap--gecmis");
    expect(css).toContain(".pm-self-dock__icon-wrap--izinlerim");
    expect(css).toContain(".pm-self-dock__icon-wrap--talepler");
    expect(css).toContain(".pm-self-dock__icon-wrap--fazla-mesai");
    expect(css).toMatch(/\.pm-self-dock__icon-wrap\s*\{[^}]*background:\s*none/s);
    expect(css).toMatch(/\.pm-self-dock__icon-wrap\s*\{[^}]*border:\s*none/s);
    expect(css).toMatch(/\.pm-self-dock__icon-wrap\s*\{[^}]*border-radius:\s*0/s);
    expect(css).toMatch(/\.pm-self-identity--home\s*\{[^}]*align-items:\s*center/s);
    expect(css).toMatch(/\.pm-self-identity--home \.pm-self-portrait\s*\{[^}]*width:\s*104px/s);
    expect(css).toContain("margin-right: 33px");
    expect(css).toContain("margin-top: 1.3em");
    expect(css).toContain("color: #fff");
    expect(css).toContain("color: var(--text-muted)");
  });

  it("removes the PERSONEL header history icon and keeps the history route", () => {
    const header = read("src/components/shell/ShellHeaderActions.tsx");
    expect(header).not.toContain('data-testid="header-attendance-history"');
    expect(header).not.toContain('navigateTo("/self/qr-hareketleri")');

    const routes = read("src/app/routes.tsx");
    expect(routes).toContain('path="self/qr-hareketleri"');
    expect(routes).toContain("PersonelQrHistoryPage");
    expect(routes).toContain('path="self/izinlerim"');
    expect(routes).toContain('path="self/talepler"');
    expect(routes).toContain('path="self/duyurular"');
    expect(routes).toContain('path="self/fazla-mesai"');
    expect(routes).toContain('path="self/profil"');

    const history = read("src/features/self-service/pages/PersonelQrHistoryPage.tsx");
    expect(history).toContain("fetchMeQrHareketleri");
    expect(history).toContain("fetchMePuantaj");
    expect(history).toContain("fetchMeFazlaCalisma");
    expect(history).toContain("buildHistoryMonthSummary");
    expect(history).toContain("qr-history-event-timeline");

    const shortcuts = read("src/features/self-service/components/SelfServiceQrShortcuts.tsx");
    expect(shortcuts).toContain("self-qr-history-link");
  });

  it("keeps BIRIM_AMIRI home off the personel menu shell", () => {
    const amir = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(amir).toContain("OwnQrAttendanceBoxes");
    expect(amir).toContain("<SelfServiceQrShortcuts");
    expect(amir).not.toContain("PersonelSelfServiceMenu");
    expect(amir).toContain("fetchMe(");
  });

  it("wires real owners and refuses fake announcement or request submits", () => {
    const izin = read("src/features/self-service/pages/PersonelSelfServiceIzinlerimPage.tsx");
    expect(izin).toContain("fetchMeYillikIzinBakiye");
    expect(izin).toContain("buildSelfServiceYillikIzinView");
    expect(izin).not.toContain("fake");

    const talepler = read("src/features/self-service/pages/PersonelSelfServiceTaleplerPage.tsx");
    expect(talepler).toContain("AttendanceCorrectionRequestModal");
    expect(talepler).toContain("fetchAttendanceToday");
    expect(talepler).toContain("createSelfIzinTalebi");
    expect(talepler).toContain("createSelfAvansTalebi");
    expect(talepler).toContain("createSelfGeriBildirim");
    expect(talepler).not.toContain("fetchInboxNotifications");

    const duyurular = read("src/features/self-service/pages/PersonelSelfServiceDuyurularPage.tsx");
    expect(duyurular).toContain("Henüz duyuru bulunmuyor");
    expect(duyurular).toContain("fetchSelfDuyurular");
    expect(duyurular).not.toContain("fetchInboxNotifications");

    const fazla = read("src/features/self-service/pages/PersonelSelfServiceFazlaMesaiPage.tsx");
    expect(fazla).toContain("fetchMeFazlaCalisma");
    expect(fazla).toContain("formatSelfServiceMinutes");
    expect(fazla).not.toContain("puantaj-hesap-motoru");
    expect(fazla).not.toContain("hesaplaFazlaCalisma");

    const profil = read("src/features/self-service/pages/PersonelSelfServiceProfilPage.tsx");
    expect(profil).toContain("fetchMe(");
    expect(profil).toContain("ise_giris_tarihi");
    expect(profil).toContain("useSelfProfilFoto");
    expect(profil).not.toContain("tc_kimlik");
    expect(profil).not.toContain("<img");
  });
});
