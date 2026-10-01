import type { Page, Route } from "@playwright/test";

async function fulfillOk(route: Route, data: unknown, status = 200) {
  await route.fulfill({
    status,
    contentType: "application/json",
    body: JSON.stringify({ data, meta: {}, errors: [] })
  });
}

const LINKED_ATTENDANCE_TODAY = {
  business_date: "2026-10-01",
  capabilities: {
    calisan_kapsami: "IC_PERSONEL",
    shell: true,
    qr_scan: false,
    attendance_correct: true,
    puantaj_write: false,
    izin_write: false,
    coming_soon_message: null
  },
  personel: {
    id: 173,
    ad_soyad: "Deniz Kaya",
    sube_ad: "Merkez",
    bolum_ad: "Operasyon",
    birim_ad: "Saha",
    gorev_ad: "Uzman"
  },
  giris: null,
  cikis: null,
  can_scan_giris: false,
  can_scan_cikis: false,
  next_action: "GIRIS",
  pending_giris_correction: null,
  pending_cikis_correction: null
};

export async function installLinkedManagerSelfMeMocks(
  page: Page,
  options: { personelAdSoyad: string; personelId: number }
) {
  const today = {
    ...LINKED_ATTENDANCE_TODAY,
    personel: { ...LINKED_ATTENDANCE_TODAY.personel, id: options.personelId, ad_soyad: options.personelAdSoyad }
  };

  await page.route(
    (url) => /\/api\/me\/?$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== "GET") {
        await route.fallback();
        return;
      }
      await fulfillOk(route, {
        user_id: 4,
        username: "bolum",
        ad_soyad: "Yönetici Mock",
        rol: "BOLUM_YONETICISI",
        personel_id: options.personelId,
        personel: {
          id: options.personelId,
          ad: options.personelAdSoyad.split(" ")[0] ?? "Deniz",
          soyad: options.personelAdSoyad.split(" ").slice(1).join(" ") || "Kaya",
          ad_soyad: options.personelAdSoyad,
          sube_id: 1,
          sube_ad: "Merkez",
          departman_id: null,
          departman_ad: null,
          bolum_id: 2,
          bolum_ad: "Operasyon",
          birim_id: 3,
          birim_ad: "Saha",
          gorev_id: 4,
          gorev_ad: "Uzman",
          aktif_durum: "AKTIF"
        },
        completeness: null,
        last_qr_event: null
      });
    }
  );

  await page.route("**/api/me/attendance/today**", async (route) => {
    await fulfillOk(route, today);
  });

  await page.route("**/api/me/inbox-notifications**", async (route) => {
    await fulfillOk(route, { items: [], pending_popups: [] });
  });

  await page.route("**/api/me/yillik-izin-bakiye**", async (route) => {
    await fulfillOk(route, {
      personel_id: options.personelId,
      contract_version: "s2c-v1",
      ise_giris_tarihi: "2020-01-01",
      referans_tarih: "2026-10-01",
      kidem_yil: 6,
      efektif_hak_gun: 94,
      kullanilan_gun: 10,
      kalan_gun: 84,
      mevcut_yillik_hak_gun: 20,
      birikmis_yasal_hak_gun: 94,
      yasal_hak_gun: 94
    });
  });
}
