import { describe, expect, it } from "vitest";
import type { MeIdentity } from "../../src/types/self-service";
import { formatSelfServiceMinutes } from "../../src/features/self-service/format-self-service-minutes";
import { buildPersonelSelfIdentityView } from "../../src/features/self-service/personel-self-identity-view";
import { PERSONEL_SELF_MENU } from "../../src/features/self-service/personel-self-service-menu";

function identity(overrides: Partial<MeIdentity["personel"]> = {}): MeIdentity {
  return {
    user_id: 1,
    username: "personel",
    ad_soyad: "Ayşe Yılmaz",
    rol: "PERSONEL",
    personel_id: 7,
    personel: {
      id: 7,
      ad: "Ayşe",
      soyad: "Yılmaz",
      ad_soyad: "Ayşe Yılmaz",
      sube_id: 1,
      sube_ad: "Merkez",
      departman_id: null,
      departman_ad: null,
      bolum_id: 2,
      bolum_ad: "Operasyon",
      birim_id: 3,
      birim_ad: "Saha",
      gorev_id: 4,
      gorev_ad: "Teknisyen",
      sicil_no: "P-007",
      ise_giris_tarihi: "2020-01-15",
      aktif_durum: "AKTIF",
      ...overrides
    }
  };
}

describe("PERSONEL mobile product phase 1 identity", () => {
  it("splits identity facts and keeps only şube - görev on the organization line", () => {
    const view = buildPersonelSelfIdentityView(identity());
    expect(view?.adSoyad).toBe("Ayşe Yılmaz");
    expect(view?.sicil).toBe("P-007");
    expect(view?.iseGiris).toBe("15.01.2020");
    expect(view?.calismaSuresi).toMatch(/^\d+ yıl \d+ ay$/);
    expect(view?.subeGorev).toBe("Merkez - Teknisyen");
  });

  it("omits empty görev/bölüm/birim parts instead of placeholders", () => {
    const view = buildPersonelSelfIdentityView(
      identity({ birim_ad: "  ", gorev_ad: null, bolum_ad: "" })
    );
    expect(view?.subeGorev).toBe("Merkez");
  });

  it("formats overtime minutes without recomputing a balance", () => {
    expect(formatSelfServiceMinutes(0)).toBe("0 dk");
    expect(formatSelfServiceMinutes(90)).toBe("1 saat 30 dk");
    expect(formatSelfServiceMinutes(120)).toBe("2 saat");
    expect(formatSelfServiceMinutes(16200)).toBe("270 saat");
  });

  it("locks the six menu routes", () => {
    expect(PERSONEL_SELF_MENU.map((item) => item.label)).toEqual([
      "Geçmiş",
      "İzinlerim",
      "Talepler",
      "Duyurular",
      "Fazla Mesaim",
      "Profilim"
    ]);
    expect(PERSONEL_SELF_MENU.map((item) => item.to)).toEqual([
      "/self/qr-hareketleri",
      "/self/izinlerim",
      "/self/talepler",
      "/self/duyurular",
      "/self/fazla-mesai",
      "/self/profil"
    ]);
  });
});
