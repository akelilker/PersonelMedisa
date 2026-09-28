import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { buildHistoryDayWorkFacts, buildHistoryMonthSummary } from "../../src/features/self-service/personel-self-history-summary";
import type { MePuantajGun, MePuantajOzet } from "../../src/types/self-service";

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
  net_calisma_dakika_toplam: 480
};

describe("PERSONEL self-service product closure", () => {
  it("shows monthly totals from owners and hides a missing net figure", () => {
    const shown = buildHistoryMonthSummary({ ozet, fazlaDonemDakika: 90 });
    expect(shown.map((row) => row.label)).toEqual([
      "Toplam çalışılan süre",
      "Toplam fazla çalışma",
      "Çalışılan gün",
      "Toplam geç kalma"
    ]);
    expect(shown.find((row) => row.testId === "qr-history-fazla-total")?.value).toBe("1 saat 30 dk");
    expect(shown.find((row) => row.testId === "qr-history-net-total")?.value).toBe("8 saat");
    expect(shown.some((row) => row.label === "Toplam erken çıkma")).toBe(false);

    const hiddenNet = buildHistoryMonthSummary({
      ozet: { ...ozet, net_calisma_dakika_toplam: null },
      fazlaDonemDakika: null
    });
    expect(hiddenNet.some((row) => row.label === "Toplam çalışılan süre")).toBe(false);
    expect(hiddenNet.some((row) => row.label === "Toplam fazla çalışma")).toBe(false);
  });

  it("maps one authoritative day and does not invent missing minutes", () => {
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
    const facts = buildHistoryDayWorkFacts(day);
    expect(facts.map((row) => row.label)).toEqual(["Giriş", "Geç kalma", "Fazla çalışma", "Durum"]);
    expect(facts.find((row) => row.label === "Net çalışma")).toBeUndefined();
    expect(buildHistoryDayWorkFacts(null)).toEqual([]);
  });

  it("keeps self reads on the bound personel and separate domain owners", () => {
    const puantaj = read("api/src/Services/SelfService/SelfPuantajReadService.php");
    expect(puantaj).toContain("'net_calisma_dakika_toplam' => $netCalismaToplam");

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
