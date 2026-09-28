import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { buildHistoryDayWorkFacts, buildHistoryMonthSummary } from "../../src/features/self-service/personel-self-history-summary";
import type { MePuantajGun, MePuantajOzet, MeQrHistoryDay } from "../../src/types/self-service";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

const ozet: MePuantajOzet = {
  calisma_gun_adet: 3,
  gec_kalma_adet: 1,
  gec_kalma_dakika_toplam: 40,
  erken_cikis_adet: 0,
  erken_cikis_dakika_toplam: 0,
  fazla_calisma_dakika_toplam: 999,
  net_calisma_dakika_toplam: 480,
  aylik_onayli_mi: false
};

function qrDay(date: string, giris?: string, cikis?: string): MeQrHistoryDay {
  const girisEvent = giris
    ? { id: 1, event_type: "GIRIS", time: giris.slice(11, 16), occurred_at: giris, status: null }
    : null;
  const cikisEvent = cikis
    ? { id: 2, event_type: "CIKIS", time: cikis.slice(11, 16), occurred_at: cikis, status: null }
    : null;
  return {
    date,
    has_events: Boolean(giris || cikis),
    giris: girisEvent,
    cikis: cikisEvent,
    events: [girisEvent, cikisEvent].filter(Boolean),
    status_lines: []
  };
}

describe("PERSONEL self-service product closure", () => {
  it("sums day-by-day with QR fallback and shows the onaylı-değil label", () => {
    const shown = buildHistoryMonthSummary({
      ozet: { ...ozet, net_calisma_dakika_toplam: null, calisma_gun_adet: 0, gec_kalma_dakika_toplam: 0 },
      fazlaDonemDakika: null,
      puantajItems: [],
      qrDays: [qrDay("2026-09-25", "2026-09-25T17:15:00+03:00", "2026-09-25T17:17:00+03:00")]
    });
    expect(shown[0].label).toBe("Toplam saat (Aylık) (Onaylı Değil)");
    expect(shown[0].value).toBe("2 dk");
    expect(shown[0].testId).toBe("qr-history-monthly-total");
  });

  it("prefers puantaj net over QR for the same day (no double count)", () => {
    const day: MePuantajGun = {
      tarih: "2026-09-24",
      gun_tipi: "Normal_Is_Gunu",
      giris_saati: "09:00:00",
      cikis_saati: "17:00:00",
      net_calisma_suresi_dakika: 480,
      gunluk_brut_sure_dakika: 480,
      gec_kalma_dakika: 0,
      erken_cikis_dakika: 0,
      fazla_calisma_dakika: 0
    };
    const shown = buildHistoryMonthSummary({
      ozet: { ...ozet, net_calisma_dakika_toplam: null },
      fazlaDonemDakika: null,
      puantajItems: [day],
      qrDays: [qrDay("2026-09-24", "2026-09-24T09:00:00+03:00", "2026-09-24T17:00:00+03:00")]
    });
    expect(shown[0].value).toBe("8 saat");
  });

  it("uses the official puantaj total and drops the suffix when approved", () => {
    const shown = buildHistoryMonthSummary({
      ozet: { ...ozet, net_calisma_dakika_toplam: 600, aylik_onayli_mi: true },
      fazlaDonemDakika: 90,
      puantajItems: [],
      qrDays: []
    });
    expect(shown[0].label).toBe("Toplam saat (Aylık)");
    expect(shown[0].value).toBe("10 saat");
    expect(shown.find((row) => row.testId === "qr-history-fazla-total")?.value).toBe("1 saat 30 dk");
  });

  it("maps a day detail with a Süre line and does not invent a duration without ÇIKIŞ", () => {
    const day: MePuantajGun = {
      tarih: "2026-09-24",
      gun_tipi: "Normal_Is_Gunu",
      giris_saati: "09:05:00",
      cikis_saati: null,
      net_calisma_suresi_dakika: null,
      gunluk_brut_sure_dakika: null,
      gec_kalma_dakika: 15,
      erken_cikis_dakika: null,
      fazla_calisma_dakika: 0
    };
    const facts = buildHistoryDayWorkFacts(day, null, false);
    expect(facts.map((row) => row.label)).toEqual(["Giriş", "Geç kalma", "Fazla çalışma", "Durum"]);
    expect(facts.find((row) => row.label === "Süre")).toBeUndefined();

    const qrFacts = buildHistoryDayWorkFacts(
      null,
      qrDay("2026-09-25", "2026-09-25T17:15:00+03:00", "2026-09-25T17:17:00+03:00"),
      false
    );
    expect(qrFacts.map((row) => row.label)).toEqual(["Süre"]);
    expect(qrFacts[0].value).toBe("2 dk (Onaylı Değil)");

    const approvedFacts = buildHistoryDayWorkFacts(
      null,
      qrDay("2026-09-25", "2026-09-25T17:15:00+03:00", "2026-09-25T17:17:00+03:00"),
      true
    );
    expect(approvedFacts[0].value).toBe("2 dk");

    expect(buildHistoryDayWorkFacts(null, null, false)).toEqual([]);
  });

  it("keeps self reads on the bound personel and separate domain owners", () => {
    const puantaj = read("api/src/Services/SelfService/SelfPuantajReadService.php");
    expect(puantaj).toContain("'net_calisma_dakika_toplam' => $netCalismaToplam");
    expect(puantaj).toContain("aylik_bildirim_onaylari");
    expect(puantaj).toContain("sube_id = :sube_id AND ay = :ay AND state = 'TAMAMLANDI'");

    const me = read("api/src/Controllers/MeController.php");
    expect(me).toContain("aylik_onayli_mi");

    const leaveRead = read("api/src/Services/SelfService/SelfIzinReadService.php");
    expect(leaveRead).toContain("surec_turu = 'IZIN'");
    expect(leaveRead).toContain("personel_id = :pid");
    expect(leaveRead).toContain("YillikIzinKullanimService::summarizeWindow");
    expect(leaveRead).not.toContain("CREATE TABLE");

    const leaveWrite = read("api/src/Controllers/SureclerController.php");
    expect(leaveWrite).toContain("function createSelfIzin");
    expect(leaveWrite).toContain("CAP_IZIN_WRITE");
    expect(leaveWrite).toContain("$body['personel_id'] = (int) $ctx['personel_id']");
    expect(leaveWrite).toContain("$body['surec_turu'] = 'IZIN'");

    const correction = read("api/src/Services/Qr/QrAttendanceCorrectionService.php");
    expect(correction).toContain("function listForSelf");
    expect(correction).toContain("WHERE personel_id = :pid");
    expect(correction).toContain("function createRequest");
    expect(correction).toContain("function decide");

    const advance = read("api/src/Services/SelfService/PersonelAvansTalepService.php");
    expect(advance).toContain("personel_avans_talepleri");
    expect(advance).not.toContain("EkOdeme");
    expect(advance).not.toContain("ek_odeme");

    const feedback = read("api/src/Services/SelfService/PersonelGeriBildirimService.php");
    expect(feedback).toContain("Anonim gonderim kapali");
    expect(feedback).toContain("personel_id");

    const duyuru = read("api/src/Services/SelfService/DuyuruService.php");
    expect(duyuru).toContain("FROM duyurular d");
    expect(duyuru).not.toContain("personel_inbox_notifications");
    expect(duyuru).toContain("duyuru_okumalari");

    const photo = read("api/src/Services/SelfService/PersonelProfilFotoStorageService.php");
    expect(photo).toContain("personel-profil-fotograflari");
    expect(photo).not.toContain("PersonelBelgeStorageService");
    expect(photo).toContain("PROFIL_FOTO_PATH_GECERSIZ");

    const photoService = read("api/src/Services/SelfService/PersonelProfilFotoService.php");
    expect(photoService).not.toContain("absolute_path");
    expect(photoService).toContain("image_base64");
    expect(photoService).toContain("getimagesizefromstring");

    const router = read("api/src/Router.php");
    expect(router).toContain("'/me/izinler'");
    expect(router).toContain("'/me/izin-talepleri'");
    expect(router).toContain("listCorrections");
    expect(router).toContain("'/me/profil-foto'");
    expect(router).not.toContain("profil-foto' && $method === 'DELETE'");

    const history = read("src/features/self-service/pages/PersonelQrHistoryPage.tsx");
    expect(history).toContain("qr-history-grid");
    expect(history).toContain("qr-history-event-timeline");
    expect(history).toContain("AttendanceCorrectionRequestModal");
    expect(history).not.toContain("items.reduce");

    const amir = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(amir).not.toContain("PersonelSelfServiceMenu");
  });
});
