import { describe, expect, it } from "vitest";
import {
  formatGenelYoneticiBildirimOnayiDate,
  formatGenelYoneticiBildirimOnayiState,
  resolveGenelYoneticiBildirimOnayiBlockMessage
} from "../../src/lib/bildirim/genel-yonetici-bildirim-onayi";

describe("genel yonetici bildirim onayi UI helper", () => {
  it.each([
    ["AYLIK_BILDIRIM_ONAYI_GEREKLI", "Önce aylık bildirim onayı tamamlanmalıdır."],
    ["AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI", "Aylık bildirim onayı henüz tamamlanmamış."],
    ["EKSIK_HAFTA_VAR", "Eksik haftalar tamamlanmadan Genel Yönetici onayı verilemez."],
    ["ZATEN_ONAYLANDI", "Bu dönem Genel Yönetici tarafından onaylanmış."]
  ])("%s blok nedenini guvenli mesaja cevirir", (code, message) => {
    expect(resolveGenelYoneticiBildirimOnayiBlockMessage(code)).toBe(message);
  });

  it("bilinmeyen code ve state degerini raw basmaz", () => {
    expect(resolveGenelYoneticiBildirimOnayiBlockMessage("SECRET_CODE")).not.toContain("SECRET_CODE");
    expect(formatGenelYoneticiBildirimOnayiState(null)).toBe("Henüz onaylanmadı");
    expect(formatGenelYoneticiBildirimOnayiState("TAMAMLANDI")).toBe("TAMAMLANDI");
  });

  it("bos ve gecerli tarihi guvenli bicimlendirir", () => {
    expect(formatGenelYoneticiBildirimOnayiDate(null)).toBe("—");
    expect(formatGenelYoneticiBildirimOnayiDate("2026-07-12 10:30:00")).not.toBe("—");
  });
});
