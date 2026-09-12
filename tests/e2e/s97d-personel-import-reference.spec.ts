import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

/** Kanonik giris: Kayit ve Surec > Kayit footer linki. */
async function openBulkImport(page: Page): Promise<void> {
  await page.goto("/");
  await page.getByTestId("menu-kayit-surec").click();
  await page.getByTestId("kayit-bulk-import-link").click();
  await expect(page.getByTestId("personel-import-dry-run-title")).toContainText("Toplu Kayıt Aktarma");
}

test.describe("S97-D personel import reference pack UI", () => {
  test("authorized user downloads reference CSV with BOM and required types", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openBulkImport(page);

    await expect(page.getByTestId("personel-import-references-download")).toContainText(
      "Yükleme Kılavuzunu İndir"
    );

    const downloadPromise = page.waitForEvent("download");
    await page.getByTestId("personel-import-references-download").click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe("personel-import-referanslari.csv");

    const stream = await download.createReadStream();
    const chunks: Buffer[] = [];
    if (!stream) {
      throw new Error("download stream missing");
    }
    for await (const chunk of stream) {
      chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
    }
    const buf = Buffer.concat(chunks);
    expect(buf.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf]))).toBeTruthy();
    const text = buf.toString("utf8");
    expect(text).toContain(
      "referans_turu;deger;bagli_sube;kullanilabilir;eslesme_sayisi;uyari_kodu;aciklama"
    );
    expect(text).toContain("SUBE;");
    expect(text).toContain("DEPARTMAN;");
    expect(text).toContain("GOREV;");
    expect(text).toContain("PERSONEL_TIPI;");
    expect(text).not.toMatch(/\btc_kimlik_no\b/i);
    expect(text).not.toMatch(/\d{11}/);
  });

  test("scoped role CSV does not include other branch name", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI");
    await openBulkImport(page);

    const downloadPromise = page.waitForEvent("download");
    await page.getByTestId("personel-import-references-download").click();
    const download = await downloadPromise;
    const stream = await download.createReadStream();
    const chunks: Buffer[] = [];
    if (!stream) {
      throw new Error("download stream missing");
    }
    for await (const chunk of stream) {
      chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
    }
    const text = Buffer.concat(chunks).toString("utf8");
    expect(text).toContain("Demo Merkez");
    expect(text).not.toContain("Demo Diger Sube");
  });

  test("ambiguous reference is not usable and formula injection is guarded", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openBulkImport(page);

    const downloadPromise = page.waitForEvent("download");
    await page.getByTestId("personel-import-references-download").click();
    const download = await downloadPromise;
    const stream = await download.createReadStream();
    const chunks: Buffer[] = [];
    if (!stream) {
      throw new Error("download stream missing");
    }
    for await (const chunk of stream) {
      chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
    }
    const text = Buffer.concat(chunks).toString("utf8");
    expect(text).toContain("PERSONEL_IMPORT_REFERANS_BELIRSIZ");
    expect(text).toContain("HAYIR");
    expect(text).toContain("'=BelirsizDept");
    expect(text).not.toMatch(/\bid\b\s*;/i);
  });
});
