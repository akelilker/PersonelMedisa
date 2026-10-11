import type { UserRole } from "../../types/auth";
import { getRolePermissions } from "../authorization/role-permissions";

/**
 * PHP EffectivePermissionResolver ile aynı kırmızı liste.
 * Ekran bu izinleri kişiye özel ALLOW olarak sunmaz.
 * Asıl ret sunucudadır (YETKI_ALLOW_ILE_VERILEMEZ).
 */
export const NON_GRANTABLE_PERMISSIONS = [
  "kullanicilar.kalici_sil",
  "yonetim-paneli.manage",
  "kullanici_yetkileri.view",
  "kullanici_yetkileri.manage",
  "kullanici_yetkileri.audit.view",
  "sgk_karar_paketi.approve",
  "bordro_kesinlestirme.approve",
  "genel_yonetici_onayi.approve",
  "genel_yonetici_bildirim_onayi.approve",
  "legal_hold.manage",
  "retention.destruction.request",
  "retention.destruction.approve",
  "retention.destruction.execute",
  "retention.destruction.view"
] as const;

/** GENEL_YONETICI hedefte DENY ile kapatılamayan sistem hakları. */
export const GY_NON_DENYABLE_PERMISSIONS = [
  "kullanicilar.kalici_sil",
  "yonetim-paneli.view",
  "yonetim-paneli.manage",
  "kullanici_yetkileri.view",
  "kullanici_yetkileri.manage",
  "kullanici_yetkileri.audit.view"
] as const;

export const YETKI_SEMA_HAZIR_DEGIL = "YETKI_SEMASI_HAZIR_DEGIL";

export const YETKI_SEMA_UYARI =
  "Yetki kayıtları henüz kurulmadı. Bu ekrandan izin verilemez, kısıt uygulanamaz ve kısıt kaldırılamaz. Gösterilen liste yalnız rolün varsayılan yetkisidir; kişisel istisna olmadığı anlamına gelmez.";

const ROLLER: UserRole[] = [
  "PERSONEL",
  "MUHASEBE",
  "IK_SORUMLUSU",
  "IK_PERSONELI",
  "BIRIM_AMIRI",
  "BOLUM_YONETICISI",
  "SUBE_YONETICISI",
  "GENEL_YONETICI",
  "SISTEM_YONETICISI",
  "AUTH_SMOKE_READONLY"
];

const YETKI_ETIKETLERI: Record<string, string> = {
  "personeller.view": "Personel listesi",
  "personeller.view.sube": "Şube kapsamlı personel listesi",
  "personeller.create": "Personel oluşturma",
  "personeller.update": "Personel düzenleme",
  "personeller.detail.view": "Personel kartı",
  "personeller.ucret.view": "Ücret görme",
  "personeller.ucret.manage": "Ücret düzenleme",
  "puantaj.view": "Puantaj görme",
  "puantaj.update": "Puantaj güncelleme",
  "puantaj.donem_muhurle": "Aylık puantaj mühürleme",
  "finans.view": "Finans görme",
  "finans.create": "Finans kaydı oluşturma",
  "finans.update": "Finans kaydı güncelleme",
  "raporlar.view": "Raporlar",
  "maas_hesaplama.view": "Maaş hesaplama görme",
  "maas_hesaplama.manage": "Maaş hesaplama",
  "yonetim-paneli.view": "Yönetim paneli görme",
  "yonetim-paneli.manage": "Kullanıcı ve şube yönetimi",
  "kullanici_yetkileri.view": "Yetki görüntüleme",
  "kullanici_yetkileri.manage": "Yetki yönetimi",
  "kullanici_yetkileri.audit.view": "Yetki geçmişini görme",
  "kullanicilar.kalici_sil": "Kullanıcıyı kalıcı silme",
  "sgk_karar_paketi.prepare": "SGK karar paketi hazırlama",
  "sgk_karar_paketi.approve": "SGK nihai onayı",
  "bordro_kesinlestirme.approve": "Bordro kesinleştirme",
  "genel_yonetici_onayi.approve": "Genel yönetici onayı",
  "genel_yonetici_bildirim_onayi.approve": "Genel yönetici bildirim onayı",
  "legal_hold.manage": "Hukuki bekletme",
  "retention.destruction.request": "İmha talebi",
  "retention.destruction.approve": "İmha onayı",
  "retention.destruction.execute": "İmha uygulama",
  "retention.destruction.view": "İmha kayıtlarını görme",
  "aylik-ozet.view": "Aylık kapanış özeti",
  "aylik-ozet.executive_ack": "Üst onay ve ay kapatma"
};

export type YetkiEtki = "ALLOW" | "DENY";

export type KullaniciYetkiIstisna = {
  id: number;
  permission: string;
  etki: YetkiEtki;
  subeId: number | null;
  gecerlilikBaslangic: string;
  gecerlilikBitis: string | null;
  gerekce: string;
  verenUsername: string;
  createdAt: string;
  iptalEdildiAt: string | null;
  iptalNedeni: string | null;
  iptalEdenUsername: string | null;
};

export type KullaniciYetkiAudit = {
  id: number;
  aksiyon: string;
  aktorUsername: string;
  permission: string | null;
  etki: string | null;
  gerekce: string | null;
  uyariKodlari: string | null;
  createdAt: string;
};

export type KullaniciYetkiOkuma = {
  userId: number;
  rol: string;
  schemaReady: boolean;
  rolVarsayilanlari: string[];
  istisnalar: KullaniciYetkiIstisna[];
  effectivePermissions: string[];
  kaynaklar: Record<string, string>;
};

export type KullaniciYetkiYazmaSonucu = {
  istisnaId: number;
  auditId: number;
  uyarilar: string[];
};

export function hassasYetkiMi(permission: string): boolean {
  return (NON_GRANTABLE_PERMISSIONS as readonly string[]).includes(permission);
}

export function kendiHesabiMi(actorUserId: number | null, hedefUserId: number): boolean {
  return actorUserId != null && actorUserId === hedefUserId;
}

export function yetkiEtiketi(permission: string): string {
  return YETKI_ETIKETLERI[permission] ?? permission.replace(/_/g, " ").replace(/\./g, " · ");
}

export function etkiEtiketi(etki: string): string {
  if (etki === "ALLOW") return "Kişisel izin";
  if (etki === "DENY") return "Kısıt";
  return etki;
}

export function aksiyonEtiketi(aksiyon: string): string {
  switch (aksiyon) {
    case "VER":
      return "Yetki kaydı";
    case "KALDIR":
      return "Kaldırma";
    case "ROL_DEGISTI_IPTAL":
      return "Rol değişince kapanış";
    case "PROFIL_DEGISTI":
      return "Profil değişikliği";
    default:
      return aksiyon;
  }
}

export function kaynakEtiketi(kaynak: string): string {
  switch (kaynak) {
    case "ROLE_DEFAULT":
      return "Rol varsayılanı";
    case "USER_ALLOW":
      return "Kişisel izin";
    case "QR_SELF_SERVICE":
      return "QR self-servis";
    case "SELF_SERVICE_BASELINE":
      return "Personel hesabı";
    default:
      return kaynak;
  }
}

export function uyariMetni(kod: string): string {
  switch (kod.trim()) {
    case "K3_ISTISNA_TEK_YONETICI":
      return "Başka uygun Genel Yönetici olmadığı için işlemi atayan yönetici yaptı.";
    case "YETKI_VEREN_KIMLIK_DOGRULANMAMIS":
      return "İşlemi yapan hesabın doğrulanmış gerçek kişi kimliği yok. Kayıt uyarıyla alındı.";
    default:
      return kod.trim();
  }
}

export function uyariMetinleri(kodlar: string | null | undefined): string[] {
  if (!kodlar) return [];
  return kodlar
    .split(",")
    .map((kod) => uyariMetni(kod))
    .filter((metin) => metin.length > 0);
}

export function bilinenIzinler(): string[] {
  const set = new Set<string>();
  for (const rol of ROLLER) {
    for (const izin of getRolePermissions(rol)) {
      set.add(izin);
    }
  }
  return [...set].sort((a, b) => a.localeCompare(b, "tr"));
}

export function aktifIstisnalar(istisnalar: readonly KullaniciYetkiIstisna[]): KullaniciYetkiIstisna[] {
  return istisnalar.filter((istisna) => istisna.iptalEdildiAt == null);
}

export function kapanmisIstisnalar(istisnalar: readonly KullaniciYetkiIstisna[]): KullaniciYetkiIstisna[] {
  return istisnalar.filter((istisna) => istisna.iptalEdildiAt != null);
}

export function istisnaKaldirilabilir(
  istisna: KullaniciYetkiIstisna,
  kendi: boolean,
  canManage: boolean
): boolean {
  if (!canManage || kendi || istisna.iptalEdildiAt != null) {
    return false;
  }
  if (istisna.etki === "DENY" && hassasYetkiMi(istisna.permission)) {
    return false;
  }
  return true;
}

export type YetkiOzet = {
  etkinSayisi: number;
  rolSayisi: number;
  kisiselIzinler: string[];
  kisitlilar: string[];
};

export function yetkiOzeti(okuma: KullaniciYetkiOkuma): YetkiOzet {
  const etkin = new Set(okuma.effectivePermissions);
  return {
    etkinSayisi: okuma.effectivePermissions.length,
    rolSayisi: okuma.rolVarsayilanlari.length,
    kisiselIzinler: okuma.effectivePermissions.filter((izin) => okuma.kaynaklar[izin] === "USER_ALLOW"),
    kisitlilar: okuma.rolVarsayilanlari.filter((izin) => !etkin.has(izin))
  };
}

export function secilebilirIzinler(input: {
  rol: string;
  rolVarsayilanlari: readonly string[];
  istisnalar: readonly KullaniciYetkiIstisna[];
  etki: YetkiEtki;
  arama: string;
}): string[] {
  const rolde = new Set(input.rolVarsayilanlari);
  const ayniEtki = new Set(
    aktifIstisnalar(input.istisnalar)
      .filter((istisna) => istisna.etki === input.etki)
      .map((istisna) => istisna.permission)
  );
  const gy = input.rol === "GENEL_YONETICI";
  const arama = input.arama.trim().toLocaleLowerCase("tr");

  return bilinenIzinler().filter((permission) => {
    if (ayniEtki.has(permission)) return false;
    if (input.etki === "ALLOW") {
      if (hassasYetkiMi(permission) || rolde.has(permission)) return false;
    } else {
      if (!rolde.has(permission)) return false;
      if (gy && (GY_NON_DENYABLE_PERMISSIONS as readonly string[]).includes(permission)) return false;
    }
    if (!arama) return true;
    const etiket = yetkiEtiketi(permission).toLocaleLowerCase("tr");
    return permission.toLocaleLowerCase("tr").includes(arama) || etiket.includes(arama);
  });
}

const GUN_MS = 24 * 60 * 60 * 1000;

export function bitisHatasi(bitisTarih: string, etki: YetkiEtki, simdi = new Date()): string | null {
  const value = bitisTarih.trim();
  if (!value) return null;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return "Geçersiz tarih.";
  const bitis = Date.parse(`${value}T23:59:59Z`);
  if (Number.isNaN(bitis)) return "Geçersiz tarih.";
  if (bitis <= simdi.getTime()) return "Bitiş tarihi başlangıçtan sonra olmalıdır.";
  if (etki === "ALLOW" && bitis > simdi.getTime() + 365 * GUN_MS) {
    return "Süreli yetki en fazla 365 gün olabilir; süresiz için bitiş boş bırakılır.";
  }
  return null;
}

export function bitisApiDegeri(bitisTarih: string): string | null {
  const value = bitisTarih.trim();
  return value ? `${value} 23:59:59` : null;
}

export function izinAra(izinler: readonly string[], arama: string): string[] {
  const needle = arama.trim().toLocaleLowerCase("tr");
  if (!needle) return [...izinler];
  return izinler.filter((izin) => {
    const etiket = yetkiEtiketi(izin).toLocaleLowerCase("tr");
    return izin.toLocaleLowerCase("tr").includes(needle) || etiket.includes(needle);
  });
}
