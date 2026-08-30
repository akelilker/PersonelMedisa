import { describe, expect, it } from "vitest";
import {
  resolveYonetimModalTitle,
  resolveYonetimPanelTab
} from "../../src/lib/yonetim/yonetim-modal-title";

describe("yonetim modal title mapping", () => {
  it("maps each yönetim tab to its canonical Turkish title", () => {
    expect(resolveYonetimModalTitle("kullanicilar")).toBe("KULLANICI YÖNETİMİ");
    expect(resolveYonetimModalTitle("subeler")).toBe("ŞİRKET VE ŞUBE YÖNETİMİ");
    expect(resolveYonetimModalTitle("mevzuat")).toBe("MEVZUAT PARAMETRELERİ");
    expect(resolveYonetimModalTitle("saklama")).toBe("SAKLAMA VE İMHA YÖNETİMİ");
  });

  it("does not show kullanıcı title for saklama aliases", () => {
    expect(resolveYonetimPanelTab("saklama")).toBe("saklama");
    expect(resolveYonetimPanelTab("legal-hold")).toBe("saklama");
    expect(resolveYonetimPanelTab("retention")).toBe("saklama");
    expect(resolveYonetimModalTitle("saklama")).not.toBe("KULLANICI YÖNETİMİ");
  });
});
