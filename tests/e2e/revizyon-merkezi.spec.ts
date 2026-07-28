import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import type { MockUserRole } from "./helpers/mock-api";

const CLOSED_WEEK_START = "2024-01-01";
const CLOSED_WEEK_END = "2024-01-07";

async function openCreateFromKaynakRow(page: Page, personelId: string) {
  await page.goto(
    `/haftalik-kapanis?personel_id=${encodeURIComponent(personelId)}&hafta_baslangic=${CLOSED_WEEK_START}`
  );
  await expect(page.getByTestId("haftalik-kapanis-page")).toBeVisible();
  await page.getByTestId("hk-prefill-personel").selectOption(personelId);
  await page.getByTestId("hk-prefill-hafta").fill(CLOSED_WEEK_START);
  await expect(page.getByTestId("hk-kaynak-tablosu")).toBeVisible({ timeout: 15_000 });
  await page.getByTestId("hk-satir-revizyon-ac-9002").click();
  await expect(page.getByTestId("revizyon-talep-create")).toBeVisible();
  await expect(page.locator("#revizyon-personel")).toHaveValue(personelId);
  await expect(page.locator('input[name="hafta_baslangic"]')).toHaveValue(CLOSED_WEEK_START);
  await expect(page.locator("#revizyon-kaynak")).not.toHaveValue("");
  await expect(page.getByTestId("revizyon-onceki-deger-readonly")).toContainText("Giriş");
  await expect(page.getByTestId("revizyon-onceki-deger-readonly")).not.toContainText("[object Object]");
}

async function fillCreateAndSave(
  page: Page,
  opts: { gerekce: string; yeniDeger: string; submit?: boolean }
) {
  await page.locator("#talep-edilen-deger").fill(opts.yeniDeger);
  await page.locator("#revizyon-gerekce").fill(opts.gerekce);
  if (opts.submit) {
    await page.getByTestId("revizyon-kaydet-gonder").click();
  } else {
    await page.getByTestId("revizyon-taslak-kaydet").click();
  }
  await expect(page.getByTestId("revizyon-talep-detay")).toBeVisible({ timeout: 15_000 });
}

function trackNativeDialogs(page: Page) {
  const nativeDialogs: string[] = [];
  page.on("dialog", (dialog) => {
    nativeDialogs.push(`${dialog.type()}:${dialog.message()}`);
    void dialog.dismiss();
  });
  return nativeDialogs;
}

async function createApprovedCorrection(page: Page, label: string) {
  await loginAsMockRole(page, "GENEL_YONETICI");
  await openCreateFromKaynakRow(page, "1");
  await fillCreateAndSave(page, {
    gerekce: `${label} talep`,
    yeniDeger: "09:00-18:00",
    submit: true
  });
  await page.locator('input[name="karar_notu"]').fill(`${label} karar notu`);
  await page.getByTestId("revizyon-onayla").click();
  await expect(page.getByRole("dialog", { name: "Revizyon Talebini Onayla" })).toBeVisible();
  await page.getByTestId("revizyon-action-dialog-confirm").click();
  await expect(page.getByTestId("revizyon-action-success")).toContainText("onaylandı");
  await page.getByTestId("revizyon-correction-uret").click();
  await expect(page.getByTestId("revizyon-action-success")).toContainText("Düzeltme kaydı");
}

test.describe("S80 Revizyon Merkezi final UI kabul", () => {
  test("Kayıt ve Süreç gateway → Revizyon Merkezi (GY)", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/");
    await page.getByTestId("menu-kayit-surec").click();
    await expect(page.getByTestId("kayit-surec-ops-links")).toBeVisible();
    await expect(page.getByTestId("kayit-surec-revizyon-merkezi-link")).toBeVisible();
    await page.getByTestId("kayit-surec-revizyon-merkezi-link").click();
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
  });

  test("BIRIM_AMIRI: create/submit + finans/onay yok + prefill", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await openCreateFromKaynakRow(page, "1");
    await expect(page.getByTestId("revizyon-bordro-etki-alani")).toHaveCount(0);
    await fillCreateAndSave(page, {
      gerekce: "S80 BA taslak",
      yeniDeger: "09:00-18:00",
      submit: false
    });
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-reddet")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-detail-bordro-alani")).toHaveCount(0);
    await page.getByTestId("revizyon-onaya-gonder").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("gönderildi");
  });

  test("BIRIM_AMIRI: iptal akışı", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await openCreateFromKaynakRow(page, "1");
    await fillCreateAndSave(page, {
      gerekce: "S80 BA iptal",
      yeniDeger: "10:00-19:00",
      submit: true
    });
    const cancelRequest = page.waitForRequest(
      (request) =>
        request.method() === "POST" &&
        /\/api\/haftalik-kapanis\/revizyon-talepleri\/\d+\/iptal$/.test(
          new URL(request.url()).pathname
        )
    );
    await page.getByTestId("revizyon-talep-iptal").click();
    await expect(page.getByRole("dialog", { name: "Revizyon Talebini İptal Et" })).toBeVisible();
    await expect(page.getByTestId("revizyon-action-dialog-cancel")).toBeFocused();
    await page.getByTestId("revizyon-action-dialog-confirm").click();
    expect((await cancelRequest).postDataJSON()).toEqual({ karar_notu: null });
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
    await expect(page.getByTestId("revizyon-onaya-gonder")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("BOLUM_YONETICISI: kendi scope create/submit/iptal, onay yok", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "BOLUM_YONETICISI");
    await page.goto("/haftalik-kapanis/revizyonlar");
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-tab-onay")).toHaveCount(0);
    await openCreateFromKaynakRow(page, "2");
    await fillCreateAndSave(page, {
      gerekce: "S80 Bölüm talep",
      yeniDeger: "08:30-17:30",
      submit: true
    });
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    await page.getByTestId("revizyon-talep-iptal").click();
    await page.getByTestId("revizyon-action-dialog-confirm").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
    expect(nativeDialogs).toEqual([]);
  });

  test("MUHASEBE: finans görünür, onay/correction yok", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "MUHASEBE");
    await openCreateFromKaynakRow(page, "1");
    await expect(page.getByTestId("revizyon-bordro-etki-alani")).toBeVisible();
    await fillCreateAndSave(page, {
      gerekce: "S80 Muhasebe talep",
      yeniDeger: "08:00-16:00",
      submit: false
    });
    await expect(page.getByTestId("revizyon-detail-bordro-alani")).toBeVisible();
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
    await page.getByTestId("revizyon-talep-iptal").click();
    await page.getByTestId("revizyon-action-dialog-confirm").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: onay → correction → değer ayrımı → iptal + duplicate 409", async ({
    page
  }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/");
    await expect(page.getByTestId("kayit-surec-revizyon-merkezi-link")).toHaveCount(0);
    await page.getByTestId("menu-kayit-surec").click();
    await page.getByTestId("kayit-surec-revizyon-merkezi-link").click();
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-tab-onay")).toBeVisible();

    await openCreateFromKaynakRow(page, "1");
    await fillCreateAndSave(page, {
      gerekce: "S80 GY onay akışı",
      yeniDeger: '{"giris_saati":"09:00","cikis_saati":"18:00"}',
      submit: true
    });
    await page.locator('input[name="karar_notu"]').fill("S80 onay notu");
    const approvalRequests: Array<Record<string, unknown>> = [];
    page.on("request", (request) => {
      if (
        request.method() === "POST" &&
        /\/api\/haftalik-kapanis\/revizyon-talepleri\/\d+\/onay$/.test(
          new URL(request.url()).pathname
        )
      ) {
        approvalRequests.push((request.postDataJSON() ?? {}) as Record<string, unknown>);
      }
    });
    await page.getByTestId("revizyon-onayla").click();
    const approveDialog = page.getByRole("dialog", { name: "Revizyon Talebini Onayla" });
    await expect(approveDialog).toBeVisible();
    await expect(page.getByTestId("revizyon-action-dialog-title")).toHaveText(
      "Revizyon Talebini Onayla"
    );
    await expect(page.getByTestId("revizyon-action-dialog-description")).toContainText(
      "Bu revizyon talebi onaylanacaktır."
    );
    await expect(page.getByTestId("revizyon-action-dialog-cancel")).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(page.getByTestId("revizyon-action-dialog-confirm")).toBeFocused();
    await page.keyboard.press("Shift+Tab");
    await expect(page.getByTestId("revizyon-action-dialog-cancel")).toBeFocused();
    await page.getByTestId("revizyon-action-dialog-cancel").click();
    await expect(approveDialog).toHaveCount(0);
    await expect(page.getByTestId("revizyon-onayla")).toBeFocused();
    expect(approvalRequests).toHaveLength(0);
    await page.getByTestId("revizyon-onayla").click();
    await page.getByTestId("revizyon-action-dialog-confirm").dblclick();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("onay");
    expect(approvalRequests).toEqual([{ karar_notu: "S80 onay notu" }]);

    await expect(page.getByTestId("revizyon-deger-ayrimi")).toBeVisible();
    await expect(page.getByTestId("revizyon-ham-deger")).toBeVisible();
    await expect(page.getByTestId("revizyon-talep-deger")).toBeVisible();
    await expect(page.getByTestId("revizyon-corrected-deger")).toContainText("Aktif düzeltme kaydı yok");
    await expect(page.getByTestId("revizyon-overlay-uyari")).toContainText("rapor/bordro");
    await expect(page.getByTestId("revizyon-audit-gecmisi")).toBeVisible();

    await page.getByTestId("revizyon-correction-uret").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("Düzeltme kaydı");
    await expect(page.getByTestId("revizyon-corrected-deger")).not.toContainText("Aktif düzeltme kaydı yok");
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-correction-iptal")).toHaveText("Düzeltme Kaydını İptal Et");
    await page.getByTestId("revizyon-correction-detay-git").click();
    await expect(page.getByTestId("revizyon-correction-detay")).toBeVisible();
    await expect(page.getByTestId("revizyon-correction-detay")).toContainText("Fark: dakika / gün");
    await expect(page.getByRole("button", { name: "Düzeltme Kaydını İptal Et" })).toBeVisible();
    await page.goBack();
    await expect(page.getByTestId("revizyon-talep-detay")).toBeVisible();

    const talepId = page.url().match(/revizyonlar\/(\d+)/)?.[1];
    expect(talepId).toBeTruthy();
    await page.goto("/haftalik-kapanis/revizyonlar?gorunum=corrections");
    await expect(page.getByTestId("revizyon-correction-tablosu")).toBeVisible();
    await expect(page.getByRole("columnheader", { name: "Fark (dk)" })).toBeVisible();
    await expect(page.getByRole("columnheader", { name: "Fark (gün)" })).toBeVisible();
    await page.goto(`/haftalik-kapanis/revizyonlar/${talepId}`);
    await expect(page.getByTestId("revizyon-talep-detay")).toBeVisible();
    await expect(page.getByTestId("revizyon-correction-iptal")).toHaveText("Düzeltme Kaydını İptal Et");

    const dup = await page.evaluate(async (id) => {
      const keys = ["medisa.auth.session.v1", "medisa_auth_session"];
      let raw: string | null = null;
      for (const key of keys) {
        raw = window.sessionStorage.getItem(key) ?? window.localStorage.getItem(key);
        if (raw) break;
      }
      if (!raw) return { status: 0, code: "NO_SESSION" };
      const session = JSON.parse(raw) as { token?: string };
      const res = await fetch(`/api/haftalik-kapanis/revizyon-talepleri/${id}/correction-uret`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${session.token ?? ""}`
        },
        body: "{}"
      });
      const body = (await res.json()) as { errors?: Array<{ code?: string }> };
      return { status: res.status, code: body.errors?.[0]?.code ?? null };
    }, talepId);
    expect(dup.status).toBe(409);
    expect(dup.code).toBe("CORRECTION_ALREADY_EXISTS");

    const correctionCancelRequests: Array<Record<string, unknown>> = [];
    page.on("request", (request) => {
      if (
        request.method() === "POST" &&
        /\/api\/haftalik-kapanis\/revizyon-corrections\/\d+\/iptal$/.test(
          new URL(request.url()).pathname
        )
      ) {
        correctionCancelRequests.push((request.postDataJSON() ?? {}) as Record<string, unknown>);
      }
    });
    await page.getByTestId("revizyon-correction-iptal").click();
    const cancelReason = page.getByRole("textbox", { name: "İptal açıklaması" });
    await cancelReason.fill("  Hatalı vardiya kaydı  ");
    await cancelReason.press("Enter");
    await expect(page.getByTestId("revizyon-action-dialog")).toBeVisible();
    expect(correctionCancelRequests).toHaveLength(0);
    await page.getByTestId("revizyon-action-dialog-confirm").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
    expect(correctionCancelRequests).toEqual([{ aciklama: "Hatalı vardiya kaydı" }]);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: red + terminal state", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openCreateFromKaynakRow(page, "1");
    await fillCreateAndSave(page, {
      gerekce: "S80 GY red akışı",
      yeniDeger: "07:00-16:00",
      submit: true
    });
    const kararNotu = page.locator('input[name="karar_notu"]');
    await page.getByTestId("revizyon-reddet").click();
    await expect(page.getByTestId("revizyon-action-dialog")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-action-error")).toContainText(
      "Red için karar notu zorunludur."
    );
    await expect(kararNotu).toBeFocused();
    await kararNotu.fill("S80 red gerekçesi");
    await page.getByTestId("revizyon-reddet").click();
    await expect(page.getByRole("dialog", { name: "Revizyon Talebini Reddet" })).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByTestId("revizyon-reddet")).toBeFocused();
    await page.getByTestId("revizyon-reddet").click();
    const rejectRequest = page.waitForRequest(
      (request) =>
        request.method() === "POST" &&
        /\/api\/haftalik-kapanis\/revizyon-talepleri\/\d+\/red$/.test(
          new URL(request.url()).pathname
        )
    );
    await page.getByTestId("revizyon-action-dialog-confirm").click();
    expect((await rejectRequest).postDataJSON()).toEqual({ karar_notu: "S80 red gerekçesi" });
    await expect(page.getByTestId("revizyon-action-success")).toContainText("reddedildi");
    await expect(page.getByText("S80 red gerekçesi", { exact: true })).toBeVisible();
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-reddet")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: correction detail boş açıklama null payload ve başarı", async ({
    page
  }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await createApprovedCorrection(page, "S93-E3A boş açıklama");
    await page.getByTestId("revizyon-correction-detay-git").click();
    await expect(page.getByTestId("revizyon-correction-detay")).toBeVisible();

    const cancelRequest = page.waitForRequest(
      (request) =>
        request.method() === "POST" &&
        /\/api\/haftalik-kapanis\/revizyon-corrections\/\d+\/iptal$/.test(
          new URL(request.url()).pathname
        )
    );
    await page.getByTestId("revizyon-correction-iptal").click();
    await expect(page.getByTestId("revizyon-action-dialog-cancel")).toBeFocused();
    await page.getByTestId("revizyon-action-dialog-confirm").click();

    expect((await cancelRequest).postDataJSON()).toEqual({ aciklama: null });
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal edildi");
    await expect(page.getByText("İptal", { exact: true })).toBeVisible();
    await expect(page.getByTestId("revizyon-correction-iptal")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: correction detail failure girdiyi korur ve retry tekilleşir", async ({
    page
  }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await createApprovedCorrection(page, "S93-E3A retry");
    await page.getByTestId("revizyon-correction-detay-git").click();
    await expect(page.getByTestId("revizyon-correction-detay")).toBeVisible();

    let cancelAttempts = 0;
    await page.route(
      "**/api/haftalik-kapanis/revizyon-corrections/*/iptal",
      async (route) => {
        cancelAttempts += 1;
        if (cancelAttempts === 1) {
          await route.fulfill({
            status: 409,
            contentType: "application/json",
            body: JSON.stringify({
              data: null,
              meta: {},
              errors: [
                {
                  code: "CORRECTION_RECOMPUTE_REQUIRED",
                  message: "Kontrollü S93-E3A hata."
                }
              ]
            })
          });
          return;
        }
        await route.fallback();
      }
    );

    await page.getByTestId("revizyon-correction-iptal").click();
    const reason = page.getByRole("textbox", { name: "İptal açıklaması" });
    await reason.fill("  Retry açıklaması  ");
    await page.getByTestId("revizyon-action-dialog-confirm").click();
    await expect(page.getByTestId("revizyon-action-error")).toBeVisible();
    await expect(page.getByTestId("revizyon-action-success")).toHaveCount(0);
    await expect(reason).toHaveValue("  Retry açıklaması  ");
    await expect(page.getByText("Aktif", { exact: true })).toBeVisible();

    await page.getByTestId("revizyon-action-dialog-confirm").dblclick();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal edildi");
    expect(cancelAttempts).toBe(2);
    expect(nativeDialogs).toEqual([]);
  });

  test("AppActionDialog viewport matrisi taşma ve footer/body çakışması üretmez", async ({
    page
  }) => {
    await createApprovedCorrection(page, "S93-E3A responsive");
    await page.getByTestId("revizyon-correction-iptal").click();
    await expect(page.getByRole("textbox", { name: "İptal açıklaması" })).toBeVisible();
    const cancelButton = page.getByTestId("revizyon-action-dialog-cancel");
    await expect(cancelButton).toBeFocused();

    for (const viewport of [
      { width: 1536, height: 864 },
      { width: 1366, height: 768 },
      { width: 768, height: 1024 },
      { width: 430, height: 932 },
      { width: 390, height: 844 },
      { width: 360, height: 800 },
      { width: 320, height: 568 }
    ]) {
      await page.setViewportSize(viewport);
      await expect
        .poll(() => cancelButton.evaluate((element) => getComputedStyle(element).boxShadow))
        .not.toBe("none");
      const metrics = await page.evaluate(() => {
        const overlays = document.querySelectorAll(".modal-overlay.open");
        const topmostOverlay = overlays.item(overlays.length - 1);
        if (!(topmostOverlay instanceof HTMLElement)) {
          throw new Error("Topmost dialog overlay eksik.");
        }

        function rect(selector: string) {
          const element = topmostOverlay.querySelector(selector);
          if (!(element instanceof HTMLElement)) {
            throw new Error(`Eksik element: ${selector}`);
          }
          const bounds = element.getBoundingClientRect();
          return {
            top: bounds.top,
            right: bounds.right,
            bottom: bounds.bottom,
            left: bounds.left,
            width: bounds.width,
            height: bounds.height
          };
        }

        const title = topmostOverlay.querySelector(".modal-header h2");
        const body = topmostOverlay.querySelector(".modal-body");
        const cancel = topmostOverlay.querySelector(
          '[data-testid="revizyon-action-dialog-cancel"]'
        );
        const confirm = topmostOverlay.querySelector(
          '[data-testid="revizyon-action-dialog-confirm"]'
        );
        if (
          !(title instanceof HTMLElement) ||
          !(body instanceof HTMLElement) ||
          !(cancel instanceof HTMLElement) ||
          !(confirm instanceof HTMLElement)
        ) {
          throw new Error("Dialog kontrat elementi eksik.");
        }

        return {
          viewportWidth: window.innerWidth,
          documentScrollWidth: document.documentElement.scrollWidth,
          dialog: rect(".modal-container"),
          body: rect(".modal-body"),
          footer: rect(".modal-footer"),
          textarea: rect("#revizyon-action-dialog-input"),
          cancel: rect('[data-testid="revizyon-action-dialog-cancel"]'),
          confirm: rect('[data-testid="revizyon-action-dialog-confirm"]'),
          titleFits: title.scrollWidth <= title.clientWidth + 1,
          bodyOverflowY: getComputedStyle(body).overflowY,
          cancelFocusRing: getComputedStyle(cancel).boxShadow,
          cancelColor: getComputedStyle(cancel).color,
          confirmColor: getComputedStyle(confirm).color
        };
      });

      expect(metrics.documentScrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
      expect(metrics.dialog.left).toBeGreaterThanOrEqual(-1);
      expect(metrics.dialog.right).toBeLessThanOrEqual(metrics.viewportWidth + 1);
      expect(metrics.textarea.width).toBeGreaterThan(0);
      expect(metrics.textarea.height).toBeGreaterThan(0);
      expect(metrics.cancel.left).toBeGreaterThanOrEqual(metrics.dialog.left);
      expect(metrics.confirm.right).toBeLessThanOrEqual(metrics.dialog.right);
      expect(metrics.body.bottom).toBeLessThanOrEqual(metrics.footer.top + 1);
      expect(metrics.titleFits).toBe(true);
      expect(metrics.bodyOverflowY).toBe("auto");
      expect(metrics.cancelFocusRing).not.toBe("none");
      expect(metrics.confirmColor).not.toBe(metrics.cancelColor);
    }
  });

  test("PATRON: gateway yok + doğrudan route yetkisiz", async ({ page }) => {
    await loginAsMockRole(page, "PATRON");
    await page.goto("/");
    await expect(page.getByTestId("menu-kayit-surec")).toBeDisabled();
    await expect(page.getByTestId("menu-personel-karti")).toBeDisabled();
    await page.goto("/haftalik-kapanis/revizyonlar");
    await expect(page.getByTestId("yetkisiz-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-merkezi-page")).toHaveCount(0);
    await expect(page.getByTestId("kayit-surec-revizyon-merkezi-link")).toHaveCount(0);
  });

  test("server-owned onceki_deger: UI canonical + sahte payload reddedilir", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await openCreateFromKaynakRow(page, "1");
    await expect(page.getByTestId("revizyon-onceki-deger-readonly")).toContainText("server_owned");

    const forged = await page.evaluate(async () => {
      const keys = ["medisa.auth.session.v1", "medisa_auth_session"];
      let raw: string | null = null;
      for (const key of keys) {
        raw = window.sessionStorage.getItem(key) ?? window.localStorage.getItem(key);
        if (raw) break;
      }
      if (!raw) return { status: 0, code: "NO_SESSION" };
      const session = JSON.parse(raw) as { token?: string };
      const res = await fetch("/api/haftalik-kapanis/revizyon-talepleri", {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${session.token ?? ""}`
        },
        body: JSON.stringify({
          personel_id: 1,
          hafta_baslangic: "2024-01-01",
          hafta_bitis: "2024-01-07",
          etkilenen_tarih: "2024-01-01",
          kaynak_tipi: "PUANTAJ",
          kaynak_id: 9002,
          revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
          talep_edilen_deger: "fake",
          gerekce: "S80 forged onceki",
          onceki_deger: { forged: true }
        })
      });
      const body = (await res.json()) as { errors?: Array<{ code?: string; message?: string }> };
      return { status: res.status, code: body.errors?.[0]?.code ?? null, message: body.errors?.[0]?.message ?? null };
    });
    expect(forged.status).toBe(422);
    expect(forged.code).toBe("VALIDATION_ERROR");
    expect(String(forged.message)).toContain("onceki_deger");
  });

  for (const role of [
    "BIRIM_AMIRI",
    "BOLUM_YONETICISI",
    "MUHASEBE",
    "GENEL_YONETICI"
  ] as MockUserRole[]) {
    test(`${role} Revizyon Merkezi erişir`, async ({ page }) => {
      await loginAsMockRole(page, role);
      await page.goto("/haftalik-kapanis/revizyonlar");
      await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
      if (role === "GENEL_YONETICI") {
        await expect(page.getByTestId("revizyon-tab-onay")).toBeVisible();
      } else {
        await expect(page.getByTestId("revizyon-tab-onay")).toHaveCount(0);
      }
    });
  }
});
