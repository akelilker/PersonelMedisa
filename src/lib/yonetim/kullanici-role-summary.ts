import type { UserRole } from "../../types/auth";
import { getRolePermissions, type AppPermission } from "../authorization/role-permissions";

export type KullaniciRoleSummaryGroup = {
  title: string;
  items: string[];
};

const PERMISSION_LABELS: Partial<Record<AppPermission, string>> = {
  "yonetim-paneli.view": "Yönetim paneli görüntüleme",
  "yonetim-paneli.manage": "Kullanıcı / şube yönetimi",
  "personeller.view": "Personel listesi",
  "personeller.view.sube": "Şube kapsamlı personel listesi",
  "personeller.create": "Personel oluşturma",
  "personeller.import.apply": "Personel import uygulama",
  "personeller.detail.view": "Personel kartı detay",
  "puantaj.view": "Puantaj görüntüleme",
  "puantaj.update": "Puantaj güncelleme",
  "puantaj.muhurle": "Aylık puantaj mühürleme",
  "sgk_karar_paketi.prepare": "SGK karar paketi hazırlama",
  "sgk_karar_paketi.approve": "SGK karar paketi onayı",
  "self_service.view": "Self-service ana sayfa",
  "self_service.qr.scan": "QR okutma",
  "self_service.qr.events.view": "QR hareket geçmişi",
  "raporlar.view": "Raporlar",
  "finans.view": "Finans görüntüleme",
  "aylik-ozet.view": "Aylık kapanış özeti",
  "aylik-ozet.executive_ack": "Üst onay / ay kapatma",
  "ops.auth_smoke.read": "Salt okuma smoke erişimi"
};

const GROUPS: Array<{ title: string; permissions: AppPermission[] }> = [
  {
    title: "Yönetim",
    permissions: ["yonetim-paneli.view", "yonetim-paneli.manage"]
  },
  {
    title: "Personel",
    permissions: [
      "personeller.view",
      "personeller.view.sube",
      "personeller.create",
      "personeller.import.apply",
      "personeller.detail.view"
    ]
  },
  {
    title: "Puantaj",
    permissions: ["puantaj.view", "puantaj.update", "puantaj.muhurle"]
  },
  {
    title: "SGK dual-control",
    permissions: ["sgk_karar_paketi.prepare", "sgk_karar_paketi.approve"]
  },
  {
    title: "Self-service / QR",
    permissions: ["self_service.view", "self_service.qr.scan", "self_service.qr.events.view"]
  },
  {
    title: "Rapor / finans",
    permissions: ["raporlar.view", "finans.view", "aylik-ozet.view", "aylik-ozet.executive_ack"]
  }
];

function labelForPermission(permission: AppPermission): string {
  return PERMISSION_LABELS[permission] ?? permission;
}

export function buildKullaniciRoleSummary(role: UserRole): KullaniciRoleSummaryGroup[] {
  const granted = new Set(getRolePermissions(role));
  const groups: KullaniciRoleSummaryGroup[] = [];

  for (const group of GROUPS) {
    const items = group.permissions.filter((permission) => granted.has(permission)).map(labelForPermission);
    if (items.length > 0) {
      groups.push({ title: group.title, items });
    }
  }

  if (granted.has("ops.auth_smoke.read")) {
    groups.push({
      title: "Teknik",
      items: [labelForPermission("ops.auth_smoke.read")]
    });
  }

  return groups;
}
