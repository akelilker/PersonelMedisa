import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

function trackNativeDialogs(page: Page) {
  const nativeDialogs: string[] = [];
  page.on("dialog", (dialog) => {
    nativeDialogs.push(`${dialog.type()}:${dialog.message()}`);
    void dialog.dismiss();
  });
  return nativeDialogs;
}

async function setActiveSube(page: Page, subeId: number, panel: "donem-kapanis" | "bordro-hazirlik") {
  await page.evaluate((nextSubeId) => {
    const key = "medisa_auth_session";
    const fromSession = sessionStorage.getItem(key);
    const storage = fromSession ? sessionStorage : localStorage;
    const raw = fromSession ?? localStorage.getItem(key);
    if (!raw) {
      throw new Error("auth session missing");
    }
    const session = JSON.parse(raw) as { active_sube_id?: number | null };
    session.active_sube_id = nextSubeId;
    storage.setItem(key, JSON.stringify(session));
  }, subeId);
  await page.goto(`/raporlar?panel=${panel}`, { waitUntil: "domcontentloaded" });
}

test.describe("S95 authenticated action dialogs", () => {
  test("GENEL_YONETICI: dönem mühür dialogu native confirm oluşturmaz", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await openRaporlarPanel(page, "GENEL_YONETICI", "donem-kapanis");
    await setActiveSube(page, 1, "donem-kapanis");

    await page.getByLabel("Ay", { exact: true }).first().fill("2026-06");
    const subeSelect = page.getByLabel("Şube");
    await expect(subeSelect.locator('option[value="1"]')).toHaveCount(1, { timeout: 15_000 });
    await subeSelect.selectOption("1");

    await page.route("**/api/puantaj/donem-kapanis-preflight**", async (route) => {
      if (route.request().method() !== "GET") {
        await route.fallback();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          ok: true,
          data: {
            sube: { id: 1, kod: "MRK", ad: "Merkez" },
            yil: 2026,
            ay: 6,
            donem: "2026-06",
            donem_state: "ACIK",
            muhur_state: "ACIK",
            muhur_id: null,
            kapanabilir_mi: true,
            blocker_count: 0,
            warning_count: 0,
            info_count: 0,
            kategori_sayaclari: { etki_adayi: 0, finans: 0 },
            blockers: [],
            warnings: [],
            infos: [],
            candidate_state_counts: { HAZIR: 0, INCELEME_GEREKLI: 0, UYGULANDI: 0, YOK_SAYILDI: 0 },
            notification_chain_counts: { toplam: 0 },
            puantaj_counts: { toplam_satir: 1, kontrol_bekleyen: 0 },
            finance_readiness: { eksik_maas_sayisi: 0, finans_kayit_sayisi: 0 },
            preflight_hash: "s95-seal",
            schema_version: "S76_PERIOD_CLOSE_PREFLIGHT_V1",
            generated_at: "2026-07-29T00:00:00+00:00"
          }
        })
      });
    });

    const preflight = page.waitForResponse((response) =>
      response.url().includes("/api/puantaj/donem-kapanis-preflight")
    );
    await page.getByTestId("donem-kapanis-submit").click();
    expect((await preflight).status()).toBe(200);

    await expect(page.getByTestId("donem-kapanis-muhurle")).toBeEnabled();
    await page.getByTestId("donem-kapanis-muhurle").click();
    await expect(page.getByTestId("donem-kapanis-muhur-action-dialog")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-muhur-action-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);

    await page.getByTestId("donem-kapanis-muhur-action-dialog-cancel").click();
    await expect(page.getByTestId("donem-kapanis-muhur-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: bordro kesinleştir dialogu native confirm oluşturmaz", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await openRaporlarPanel(page, "GENEL_YONETICI", "bordro-hazirlik");
    await setActiveSube(page, 1, "bordro-hazirlik");

    await page.getByLabel("Ay", { exact: true }).first().fill("2026-03");
    const subeSelect = page.getByLabel("Şube");
    await expect(subeSelect.locator('option[value="1"]')).toHaveCount(1, { timeout: 15_000 });
    await subeSelect.selectOption("1");
    await page.getByTestId("bordro-hazirlik-submit").click();

    await page.getByTestId("bordro-hazirlik-tab-on-izleme").click();
    await expect(page.getByTestId("bordro-kesinlestir")).toBeVisible();

    const kesinlestir = page.getByTestId("bordro-kesinlestir");
    if (await kesinlestir.isDisabled()) {
      test.skip(true, "Kesinleştir mock blocker nedeniyle kapalı; dialog UX unit/S82 ile kilitli.");
    }

    await kesinlestir.click();
    await expect(page.getByTestId("bordro-kesinlestir-action-dialog")).toBeVisible();
    await expect(page.getByTestId("bordro-kesinlestir-action-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);
    await page.getByTestId("bordro-kesinlestir-action-dialog-cancel").click();
    await expect(page.getByTestId("bordro-kesinlestir-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: SGK katalog + UBGT resmi tatil authenticated kabul zinciri", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "bordro-hazirlik");
    await page.getByTestId("bordro-hazirlik-tab-sgk-katalog").click();
    await expect(page.getByTestId("sgk-katalog-hazirlik-panel")).toBeVisible();
    await expect(page.getByTestId("sgk-katalog-blocker-SGK_KATALOG_TAMLIK_KANITI_EKSIK")).toBeVisible();
    await page.getByTestId("sgk-katalog-subtab-import").click();
    await page.getByTestId("sgk-katalog-import-dry-run").click();
    await expect(page.getByTestId("sgk-katalog-import-result")).toBeVisible();
    await expect(page.getByTestId("sgk-katalog-import-write")).toBeDisabled();

    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/resmi-tatil-takvimi");
    await expect(page.getByTestId("resmi-tatil-takvimi-page")).toBeVisible();
    await expect(page.getByTestId("rtt-readiness-cards")).toBeVisible();
    await expect(page.getByTestId("rtt-create-btn")).toBeVisible();
  });
});
