import { describe, expect, it } from "vitest";
import type { MeIdentity } from "../../src/types/self-service";
import { formatSelfServiceMinutes } from "../../src/features/self-service/format-self-service-minutes";
import { buildPersonelSelfIdentityView } from "../../src/features/self-service/personel-self-identity-view";
import {
  PERSONEL_SELF_HOME_DOCK_MENU,
  PERSONEL_SELF_MENU
} from "../../src/features/self-service/personel-self-service-menu";

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
    expect(view?.iseGiris).toBe("15/01/2020");
    expect(view?.calismaSuresi).toMatch(/^\d+ yıl \d+ ay$/);
    expect(view?.subeGorev).toBe("Merkez - Teknisyen");
    expect(view?.dogumTarihi).toBe("-");
    expect(view?.cinsiyet).toBe("-");
    expect(view?.telefon).toBe("-");
    expect(view?.tcKimlikNo).toBe("-");
    expect(view?.kanGrubu).toBe("-");
  });

  it("formats supplied identity facts and does not invent missing gender", () => {
    const view = buildPersonelSelfIdentityView(
      identity({
        dogum_tarihi: "1992-03-14",
        telefon: "0532 111 22 33",
        tc_kimlik_no: "10000000146",
        kan_grubu: "A Rh+",
        cinsiyet: null
      })
    );
    expect(view?.dogumTarihi).toBe("14/03/1992");
    expect(view?.telefon).toBe("0532 111 22 33");
    expect(view?.tcKimlikNo).toBe("10000000146");
    expect(view?.kanGrubu).toBe("A Rh+");
    expect(view?.cinsiyet).toBe("-");
    expect(view?.sicil).toBe("P-007");
  });

  it("shows API-supplied gender without inventing missing values", () => {
    const view = buildPersonelSelfIdentityView(
      identity({
        cinsiyet: "Erkek"
      })
    );
    expect(view?.cinsiyet).toBe("Erkek");
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

  it("keeps the full self menu registry for routes and modals", () => {
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

  it("limits the home dock to four icon entries without duyurular or profil", () => {
    expect(PERSONEL_SELF_HOME_DOCK_MENU.map((item) => item.id)).toEqual([
      "gecmis",
      "izinlerim",
      "talepler",
      "fazla-mesai"
    ]);
    expect(PERSONEL_SELF_HOME_DOCK_MENU.map((item) => item.testId)).toEqual([
      "personel-menu-gecmis",
      "personel-menu-izinlerim",
      "personel-menu-talepler",
      "personel-menu-fazla-mesai"
    ]);
  });
});
