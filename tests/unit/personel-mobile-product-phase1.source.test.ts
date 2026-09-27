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
    expect(home).toContain("PersonelSelfServiceHomeInfoBlock");
    expect(home).toContain("fetchMeYillikIzinBakiye");
    expect(home).toContain("AttendanceCorrectionRequestModal");
    expect(home).not.toContain("<img");
    expect(home).not.toContain("avatar");

    const identity = read("src/features/self-service/components/PersonelSelfServiceIdentity.tsx");
    expect(identity).not.toContain("<img");
    expect(identity).not.toContain("avatar");
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
    expect(profil).toContain("fetchMeYillikIzinBakiye");
    expect(profil).not.toContain("tc_kimlik");
    expect(profil).not.toContain("<img");
  });
});
