import { describe, expect, it } from "vitest";
import {
  GY_NON_DENYABLE_PERMISSIONS,
  NON_GRANTABLE_PERMISSIONS,
  aktifIstisnalar,
  bitisHatasi,
  hassasYetkiMi,
  istisnaKaldirilabilir,
  istisnaZamanEtiketi,
  istisnaZamani,
  kapanmisIstisnalar,
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
    baslangicHassasKilit: false,
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

  it("rolde olan yetkiye yeniden izin vermez; yalnız başlangıç hassas kilidini kapatır", () => {
    const izin = secilebilirIzinler({
      rol: "BOLUM_YONETICISI",
      rolVarsayilanlari: ["personeller.update"],
      istisnalar: [],
      etki: "ALLOW",
      arama: "personel düzenleme"
    });
    expect(izin).not.toContain("personeller.update");

    const sonradanHassas = istisna({ id: 2, permission: "sgk_karar_paketi.approve", etki: "DENY" });
    const baslangicHassas = istisna({
      id: 3,
      permission: "sgk_karar_paketi.approve",
      etki: "DENY",
      baslangicHassasKilit: true
    });
    const normal = istisna({ id: 1, permission: "personeller.update", etki: "DENY" });
    expect(istisnaKaldirilabilir(sonradanHassas, false, true)).toBe(true);
    expect(istisnaKaldirilabilir(baslangicHassas, false, true)).toBe(false);
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

const SIMDI = "2026-10-11 12:00:00";

function zamanli(partial: Partial<KullaniciYetkiIstisna> & Pick<KullaniciYetkiIstisna, "id">): KullaniciYetkiIstisna {
  return istisna({
    permission: "personeller.update",
    etki: "DENY",
    gecerlilikBaslangic: "2026-01-01 00:00:00",
    gecerlilikBitis: null,
    ...partial
  });
}

describe("yetki istisnası UTC süresi", () => {
  it("kalıcı ve hâlâ geçerli kayıt etkin kalır", () => {
    const kalici = zamanli({ id: 1 });
    const pencere = zamanli({ id: 2, gecerlilikBitis: "2026-10-11 12:00:01" });
    const tamBaslangic = zamanli({ id: 3, gecerlilikBaslangic: SIMDI });

    expect(aktifIstisnalar([kalici, pencere, tamBaslangic], SIMDI).map((item) => item.id)).toEqual([1, 2, 3]);
    expect(istisnaZamani(kalici, SIMDI)).toBe("etkin");
    expect(
      secilebilirIzinler({
        rol: "BOLUM_YONETICISI",
        rolVarsayilanlari: ["personeller.update"],
        istisnalar: [kalici],
        etki: "DENY",
        arama: "",
        simdiUtc: SIMDI
      })
    ).not.toContain("personeller.update");
  });

  it("bitiş anında dolmuş sayılır, geçmişe düşer ve yeni kısıtı engellemez", () => {
    const tamBitis = zamanli({ id: 4, gecerlilikBitis: SIMDI });
    const gecmis = zamanli({ id: 5, gecerlilikBitis: "2026-10-01 00:00:00" });

    expect(aktifIstisnalar([tamBitis, gecmis], SIMDI)).toEqual([]);
    expect(kapanmisIstisnalar([tamBitis, gecmis], SIMDI).map((item) => item.id)).toEqual([4, 5]);
    expect(istisnaZamani(tamBitis, SIMDI)).toBe("dolmus");
    expect(istisnaZamanEtiketi("dolmus")).toBe("Süresi doldu");
    expect(
      secilebilirIzinler({
        rol: "BOLUM_YONETICISI",
        rolVarsayilanlari: ["personeller.update"],
        istisnalar: [tamBitis],
        etki: "DENY",
        arama: "",
        simdiUtc: SIMDI
      })
    ).toContain("personeller.update");
  });

  it("henüz başlamamış kaydı etkin saymaz ve durum etiketi verir", () => {
    const ileri = zamanli({ id: 6, gecerlilikBaslangic: "2026-10-12 00:00:00" });

    expect(aktifIstisnalar([ileri], SIMDI)).toEqual([]);
    expect(kapanmisIstisnalar([ileri], SIMDI)).toEqual([]);
    expect(istisnaZamani(ileri, SIMDI)).toBe("baslamadi");
    expect(istisnaZamanEtiketi("baslamadi")).toBe("Henüz başlamadı");
    expect(
      secilebilirIzinler({
        rol: "BOLUM_YONETICISI",
        rolVarsayilanlari: ["personeller.update"],
        istisnalar: [ileri],
        etki: "DENY",
        arama: "",
        simdiUtc: SIMDI
      })
    ).toContain("personeller.update");
  });

  it("iptal edilmiş geçerli kaydı geçmişte tutar", () => {
    const iptal = zamanli({ id: 7, iptalEdildiAt: "2026-10-02 00:00:00" });
    expect(istisnaZamani(iptal, SIMDI)).toBe("iptal");
    expect(aktifIstisnalar([iptal], SIMDI)).toEqual([]);
    expect(kapanmisIstisnalar([iptal], SIMDI).map((item) => item.id)).toEqual([7]);
  });
});
