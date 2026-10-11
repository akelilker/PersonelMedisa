import { describe, expect, it } from "vitest";
import {
  GY_NON_DENYABLE_PERMISSIONS,
  NON_GRANTABLE_PERMISSIONS,
  bitisHatasi,
  hassasYetkiMi,
  istisnaKaldirilabilir,
  secilebilirIzinler,
  type KullaniciYetkiIstisna
} from "../../src/lib/yonetim/kullanici-yetki-ekrani";

function istisna(partial: Partial<KullaniciYetkiIstisna> & Pick<KullaniciYetkiIstisna, "id" | "permission" | "etki">): KullaniciYetkiIstisna {
  return {
    subeId: null,
    gecerlilikBaslangic: "2026-10-01 09:00:00",
    gecerlilikBitis: null,
    gerekce: "gerekce",
    verenUsername: "yonetici",
    createdAt: "2026-10-01 09:00:00",
    iptalEdildiAt: null,
    iptalNedeni: null,
    iptalEdenUsername: null,
    ...partial
  };
}

describe("kullanıcı yetki ekranı kuralları", () => {
  it("hassas yetkiyi izin listesine koymaz", () => {
    const secenekler = secilebilirIzinler({
      rol: "BOLUM_YONETICISI",
      rolVarsayilanlari: ["personeller.update"],
      istisnalar: [],
      etki: "ALLOW",
      arama: "sgk"
    });
    expect(secenekler).not.toContain("sgk_karar_paketi.approve");
    expect(hassasYetkiMi("sgk_karar_paketi.approve")).toBe(true);
    expect(NON_GRANTABLE_PERMISSIONS).toContain("bordro_kesinlestirme.approve");
  });

  it("Genel Yöneticinin sistem hakkını kısıt listesine koymaz", () => {
    const secenekler = secilebilirIzinler({
      rol: "GENEL_YONETICI",
      rolVarsayilanlari: [...GY_NON_DENYABLE_PERMISSIONS, "personeller.update"],
      istisnalar: [],
      etki: "DENY",
      arama: ""
    });
    expect(secenekler).toContain("personeller.update");
    expect(secenekler).not.toContain("kullanici_yetkileri.manage");
    expect(secenekler).not.toContain("yonetim-paneli.manage");
  });

  it("rolde olan yetkiye yeniden izin vermez; kısıtı kaldırmayı hassas kayıtta kapalı tutar", () => {
    const izin = secilebilirIzinler({
      rol: "BOLUM_YONETICISI",
      rolVarsayilanlari: ["personeller.update"],
      istisnalar: [],
      etki: "ALLOW",
      arama: "personel düzenleme"
    });
    expect(izin).not.toContain("personeller.update");

    const hassas = istisna({ id: 2, permission: "sgk_karar_paketi.approve", etki: "DENY" });
    const normal = istisna({ id: 1, permission: "personeller.update", etki: "DENY" });
    expect(istisnaKaldirilabilir(hassas, false, true)).toBe(false);
    expect(istisnaKaldirilabilir(normal, false, true)).toBe(true);
    expect(istisnaKaldirilabilir(normal, true, true)).toBe(false);
  });

  it("süreli izinde 365 günü aşan bitişi reddeder", () => {
    const simdi = new Date("2026-10-11T12:00:00Z");
    expect(bitisHatasi("2027-04-01", "ALLOW", simdi)).toBeNull();
    expect(bitisHatasi("2027-10-12", "ALLOW", simdi)).toMatch(/365/);
    expect(bitisHatasi("", "ALLOW", simdi)).toBeNull();
  });
});
