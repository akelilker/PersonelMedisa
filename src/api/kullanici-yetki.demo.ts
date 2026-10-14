import type { ApiResponse } from "../types/api";
import { hasRolePermission, getRolePermissions, type AppPermission } from "../lib/authorization/role-permissions";
import {
  GY_NON_DENYABLE_PERMISSIONS,
  NON_GRANTABLE_PERMISSIONS,
  YETKI_SEMA_HAZIR_DEGIL,
  bilinenIzinler,
  type YetkiEtki
} from "../lib/yonetim/kullanici-yetki-ekrani";

type DemoActor = { userId: number; role: string };

type DemoIstisna = {
  id: number;
  user_id: number;
  permission: string;
  etki: YetkiEtki;
  gerekce: string;
  veren_username_snapshot: string;
  hedef_username_snapshot: string;
  hedef_rol_snapshot: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  created_at: string;
  iptal_edildi_at: string | null;
  iptal_nedeni: string | null;
  iptal_eden_username_snapshot: string | null;
  hassas_baslangic: boolean;
};

type DemoAudit = {
  id: number;
  aksiyon: string;
  aktor_user_id: number;
  aktor_username_snapshot: string;
  hedef_user_id: number;
  hedef_username_snapshot: string;
  permission: string | null;
  etki: string | null;
  gerekce: string | null;
  uyari_kodlari: string | null;
  created_at: string;
};

type DemoKullanici = { id: number; rol: string; ad_soyad: string };

/** Demo belleğinde şema hazırdır. false iken ekran migration uyarısını gösterir ve yazmaz. */
const SEMA_HAZIR = true;

const istisnalar: DemoIstisna[] = [
  {
    id: 1,
    user_id: 2,
    permission: "personeller.update",
    etki: "DENY",
    gerekce: "Personel düzenleme bu hesapta kapalı.",
    veren_username_snapshot: "İlker Akel",
    hedef_username_snapshot: "Adnan Bulut",
    hedef_rol_snapshot: "BOLUM_YONETICISI",
    gecerlilik_baslangic: "2026-10-01 09:00:00",
    gecerlilik_bitis: null,
    created_at: "2026-10-01 09:00:00",
    iptal_edildi_at: null,
    iptal_nedeni: null,
    iptal_eden_username_snapshot: null,
    hassas_baslangic: false
  },
  {
    id: 2,
    user_id: 2,
    permission: "sgk_karar_paketi.approve",
    etki: "DENY",
    gerekce: "Hassas başlangıç kısıtı. Bu yetki ekrandan açılmaz.",
    veren_username_snapshot: "İlker Akel",
    hedef_username_snapshot: "Adnan Bulut",
    hedef_rol_snapshot: "BOLUM_YONETICISI",
    gecerlilik_baslangic: "2026-10-01 09:00:00",
    gecerlilik_bitis: null,
    created_at: "2026-10-01 09:00:00",
    iptal_edildi_at: null,
    iptal_nedeni: null,
    iptal_eden_username_snapshot: null,
    hassas_baslangic: true
  },
  {
    id: 4,
    user_id: 2,
    permission: "bordro_kesinlestirme.approve",
    etki: "DENY",
    gerekce: "Geçici hassas kısıt. Başlangıç kaydı değil.",
    veren_username_snapshot: "İlker Akel",
    hedef_username_snapshot: "Adnan Bulut",
    hedef_rol_snapshot: "BOLUM_YONETICISI",
    gecerlilik_baslangic: "2026-10-03 09:00:00",
    gecerlilik_bitis: "2026-12-01 09:00:00",
    created_at: "2026-10-03 09:00:00",
    iptal_edildi_at: null,
    iptal_nedeni: null,
    iptal_eden_username_snapshot: null,
    hassas_baslangic: false
  },
  {
    id: 3,
    user_id: 2,
    permission: "maas_hesaplama.view",
    etki: "ALLOW",
    gerekce: "Maaş hesaplamasını yalnız görsün.",
    veren_username_snapshot: "İlker Akel",
    hedef_username_snapshot: "Adnan Bulut",
    hedef_rol_snapshot: "BOLUM_YONETICISI",
    gecerlilik_baslangic: "2026-10-02 09:00:00",
    gecerlilik_bitis: "2027-01-02 09:00:00",
    created_at: "2026-10-02 09:00:00",
    iptal_edildi_at: null,
    iptal_nedeni: null,
    iptal_eden_username_snapshot: null,
    hassas_baslangic: false
  }
];

const auditler: DemoAudit[] = [
  {
    id: 1,
    aksiyon: "VER",
    aktor_user_id: 1,
    aktor_username_snapshot: "İlker Akel",
    hedef_user_id: 2,
    hedef_username_snapshot: "Adnan Bulut",
    permission: "personeller.update",
    etki: "DENY",
    gerekce: "Personel düzenleme bu hesapta kapalı.",
    uyari_kodlari: null,
    created_at: "2026-10-01 09:00:00"
  },
  {
    id: 2,
    aksiyon: "VER",
    aktor_user_id: 1,
    aktor_username_snapshot: "İlker Akel",
    hedef_user_id: 2,
    hedef_username_snapshot: "Adnan Bulut",
    permission: "sgk_karar_paketi.approve",
    etki: "DENY",
    gerekce: "Hassas başlangıç kısıtı. Bu yetki ekrandan açılmaz.",
    uyari_kodlari: null,
    created_at: "2026-10-01 09:00:01"
  },
  {
    id: 3,
    aksiyon: "VER",
    aktor_user_id: 1,
    aktor_username_snapshot: "İlker Akel",
    hedef_user_id: 2,
    hedef_username_snapshot: "Adnan Bulut",
    permission: "maas_hesaplama.view",
    etki: "ALLOW",
    gerekce: "Maaş hesaplamasını yalnız görsün.",
    uyari_kodlari: null,
    created_at: "2026-10-02 09:00:00"
  }
];

let nextIstisnaId = 5;
let nextAuditId = 4;

function ok<T>(data: T): ApiResponse<T> {
  return { data, meta: {}, errors: [] };
}

function fail(code: string, message: string, field?: string): ApiResponse<unknown> {
  return { data: null, meta: {}, errors: [{ code, message, field }] };
}

function aktifler(userId: number): DemoIstisna[] {
  return istisnalar.filter((row) => row.user_id === userId && row.iptal_edildi_at == null);
}

function etkinIzinler(rol: string, userId: number): { permissions: string[]; kaynaklar: Record<string, string> } {
  const kaynaklar: Record<string, string> = {};
  for (const izin of getRolePermissions(rol)) {
    kaynaklar[izin] = "ROLE_DEFAULT";
  }
  for (const row of aktifler(userId)) {
    if (row.etki === "ALLOW" && !(NON_GRANTABLE_PERMISSIONS as readonly string[]).includes(row.permission)) {
      if (!kaynaklar[row.permission]) kaynaklar[row.permission] = "USER_ALLOW";
    }
  }
  for (const row of aktifler(userId)) {
    if (row.etki === "DENY") delete kaynaklar[row.permission];
  }
  return { permissions: Object.keys(kaynaklar).sort(), kaynaklar };
}

function istisnaSatiri(row: DemoIstisna) {
  return {
    id: row.id,
    user_id: row.user_id,
    permission: row.permission,
    etki: row.etki,
    sube_id: null,
    gecerlilik_baslangic: row.gecerlilik_baslangic,
    gecerlilik_bitis: row.gecerlilik_bitis,
    veren_user_id: 1,
    veren_username_snapshot: row.veren_username_snapshot,
    hedef_username_snapshot: row.hedef_username_snapshot,
    hedef_rol_snapshot: row.hedef_rol_snapshot,
    gerekce: row.gerekce,
    created_at: row.created_at,
    iptal_edildi_at: row.iptal_edildi_at,
    iptal_eden_user_id: row.iptal_edildi_at ? 1 : null,
    iptal_eden_username_snapshot: row.iptal_eden_username_snapshot,
    iptal_nedeni: row.iptal_nedeni,
    baslangic_hassas_kilit: row.hassas_baslangic === true && row.etki === "DENY"
  };
}

export function resolveDemoKullaniciYetki(input: {
  pathname: string;
  method: string;
  body: Record<string, unknown>;
  searchParams: URLSearchParams;
  actor: DemoActor;
  kullanicilar: readonly DemoKullanici[];
}): ApiResponse<unknown> | null {
  const { pathname, method, body, actor, kullanicilar } = input;
  if (pathname === "/auth/yetkiler" && method === "GET") {
    const etkin = etkinIzinler(actor.role, actor.userId);
    return ok({
      user_id: actor.userId,
      rol: actor.role,
      effective_permissions: etkin.permissions,
      kaynaklar: etkin.kaynaklar,
      istisnalar: istisnalar
        .filter((row) => row.user_id === actor.userId && row.iptal_edildi_at == null)
        .map(istisnaSatiri),
      sube_istisnalari_etkin: false
    });
  }
  const yetkiMatch = pathname.match(/^\/yonetim\/kullanicilar\/(\d+)\/yetkiler$/);
  const iptalMatch = pathname.match(/^\/yonetim\/kullanicilar\/(\d+)\/yetkiler\/(\d+)\/iptal$/);
  const auditPath = pathname === "/yonetim/yetki-auditleri";
  if (!yetkiMatch && !iptalMatch && !auditPath) return null;

  const izinVar = (permission: AppPermission) => hasRolePermission(actor.role, permission);
  if (auditPath && method === "GET") {
    if (!izinVar("kullanici_yetkileri.audit.view")) {
      return fail("FORBIDDEN", "Bu işlem için yetkiniz yok.");
    }
    if (!SEMA_HAZIR) return ok({ schema_ready: false, items: [] });
    const hedef = Number(input.searchParams.get("kullanici_id") ?? "");
    const items = auditler
      .filter((row) => !Number.isFinite(hedef) || hedef <= 0 || row.hedef_user_id === hedef)
      .sort((a, b) => b.id - a.id);
    return ok({ schema_ready: true, items });
  }

  const hedefId = Number((yetkiMatch ?? iptalMatch)?.[1] ?? "");
  const hedef = kullanicilar.find((item) => item.id === hedefId);
  if (!hedef) return fail("NOT_FOUND", "Kullanici bulunamadi.");

  if (yetkiMatch && method === "GET") {
    if (!izinVar("kullanici_yetkileri.view")) return fail("FORBIDDEN", "Bu işlem için yetkiniz yok.");
    const varsayilan = [...getRolePermissions(hedef.rol)].sort();
    if (!SEMA_HAZIR) {
      const bos = etkinIzinler(hedef.rol, -1);
      return ok({
        user_id: hedef.id,
        rol: hedef.rol,
        schema_ready: false,
        rol_varsayilanlari: varsayilan,
        istisnalar: [],
        effective_permissions: bos.permissions,
        kaynaklar: bos.kaynaklar,
        sube_istisnalari_etkin: false
      });
    }
    const etkin = etkinIzinler(hedef.rol, hedef.id);
    return ok({
      user_id: hedef.id,
      rol: hedef.rol,
      schema_ready: true,
      rol_varsayilanlari: varsayilan,
      istisnalar: istisnalar.filter((row) => row.user_id === hedef.id).sort((a, b) => b.id - a.id).map(istisnaSatiri),
      effective_permissions: etkin.permissions,
      kaynaklar: etkin.kaynaklar,
      sube_istisnalari_etkin: false
    });
  }

  if (!SEMA_HAZIR && (method === "POST")) {
    return fail(YETKI_SEMA_HAZIR_DEGIL, "Yetki istisnası şeması henüz kurulmadı (migration 100).");
  }

  if (!izinVar("kullanici_yetkileri.manage")) return fail("FORBIDDEN", "Bu işlem için yetkiniz yok.");
  if (actor.userId === hedef.id) {
    return fail("KENDI_YETKISI_DEGISTIRILEMEZ", "Kendi yetkinizi veya kendi üzerinizdeki kısıtı değiştiremezsiniz.", "id");
  }

  if (iptalMatch && method === "POST") {
    const istisnaId = Number(iptalMatch[2]);
    const gerekce = typeof body.gerekce === "string" ? body.gerekce.trim() : "";
    if (!gerekce) return fail("VALIDATION_ERROR", "Gerekçe zorunludur.", "gerekce");
    const row = istisnalar.find((item) => item.id === istisnaId && item.user_id === hedef.id);
    if (!row) return fail("NOT_FOUND", "Yetki istisnası bulunamadı.", "istisna_id");
    if (row.iptal_edildi_at) return fail("YETKI_ISTISNASI_ZATEN_IPTAL", "Bu yetki istisnası zaten iptal edilmiş.", "istisna_id");
    if (row.hassas_baslangic && row.etki === "DENY") {
      return fail("YETKI_HASSAS_BASLANGIC_KALDIRILAMAZ", "Bu hassas yetkinin başlangıç kısıtı kaldırılamaz.", "istisna_id");
    }
    const simdi = new Date().toISOString().slice(0, 19).replace("T", " ");
    row.iptal_edildi_at = simdi;
    row.iptal_nedeni = "MANUEL";
    row.iptal_eden_username_snapshot = `kullanici-${actor.userId}`;
    auditler.push({
      id: nextAuditId,
      aksiyon: "KALDIR",
      aktor_user_id: actor.userId,
      aktor_username_snapshot: `kullanici-${actor.userId}`,
      hedef_user_id: hedef.id,
      hedef_username_snapshot: hedef.ad_soyad,
      permission: row.permission,
      etki: row.etki,
      gerekce,
      uyari_kodlari: null,
      created_at: simdi
    });
    const auditId = nextAuditId;
    nextAuditId += 1;
    return ok({ istisna_id: row.id, audit_id: auditId, uyarilar: [], bildirim: 0 });
  }

  if (yetkiMatch && method === "POST") {
    const permission = typeof body.permission === "string" ? body.permission.trim() : "";
    const etki = typeof body.etki === "string" ? body.etki.trim().toUpperCase() : "";
    const gerekce = typeof body.gerekce === "string" ? body.gerekce.trim() : "";
    if (body.sube_id != null && body.sube_id !== "") {
      return fail("SUBE_ISTISNASI_KAPALI", "Şube kapsamlı yetki istisnası henüz kapalıdır; yalnız tüm şubeleri kapsayan istisna verilebilir.", "sube_id");
    }
    if (!bilinenIzinler().includes(permission)) return fail("VALIDATION_ERROR", "Bilinmeyen yetki.", "permission");
    if (etki !== "ALLOW" && etki !== "DENY") return fail("VALIDATION_ERROR", "Etki ALLOW veya DENY olmalıdır.", "etki");
    if (!gerekce) return fail("VALIDATION_ERROR", "Gerekçe zorunludur.", "gerekce");
    if (etki === "ALLOW" && (NON_GRANTABLE_PERMISSIONS as readonly string[]).includes(permission)) {
      return fail("YETKI_ALLOW_ILE_VERILEMEZ", "Bu yetki kişiye özel olarak verilemez (yalnız rol ile).", "permission");
    }
    if (
      etki === "DENY" &&
      hedef.rol === "GENEL_YONETICI" &&
      (GY_NON_DENYABLE_PERMISSIONS as readonly string[]).includes(permission)
    ) {
      return fail("YETKI_GY_ICIN_KISITLANAMAZ", "Genel Yönetici için bu sistem yetkisi kısıtlanamaz.", "permission");
    }
    const rolde = getRolePermissions(hedef.rol).includes(permission as AppPermission);
    if (etki === "ALLOW" && rolde) {
      return fail("YETKI_ZATEN_ROLDE_VAR", "Bu yetki kullanıcının rolünde zaten var.", "permission");
    }
    if (aktifler(hedef.id).some((row) => row.permission === permission && row.etki === etki)) {
      return fail("YETKI_ISTISNASI_ZATEN_VAR", "Bu kullanıcıda aynı yetki istisnası zaten etkin.", "permission");
    }
    const bitis = typeof body.gecerlilik_bitis === "string" && body.gecerlilik_bitis.trim() ? body.gecerlilik_bitis.trim() : null;
    const simdi = new Date().toISOString().slice(0, 19).replace("T", " ");
    const id = nextIstisnaId;
    nextIstisnaId += 1;
    istisnalar.push({
      id,
      user_id: hedef.id,
      permission,
      etki,
      gerekce,
      veren_username_snapshot: `kullanici-${actor.userId}`,
      hedef_username_snapshot: hedef.ad_soyad,
      hedef_rol_snapshot: hedef.rol,
      gecerlilik_baslangic: simdi,
      gecerlilik_bitis: bitis,
      created_at: simdi,
      iptal_edildi_at: null,
      iptal_nedeni: null,
      iptal_eden_username_snapshot: null,
      hassas_baslangic: false
    });
    auditler.push({
      id: nextAuditId,
      aksiyon: "VER",
      aktor_user_id: actor.userId,
      aktor_username_snapshot: `kullanici-${actor.userId}`,
      hedef_user_id: hedef.id,
      hedef_username_snapshot: hedef.ad_soyad,
      permission,
      etki,
      gerekce,
      uyari_kodlari: null,
      created_at: simdi
    });
    const auditId = nextAuditId;
    nextAuditId += 1;
    return ok({ istisna_id: id, audit_id: auditId, uyarilar: [], bildirim: 0 });
  }

  return null;
}
