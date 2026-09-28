import type { AppPermission } from "../../lib/authorization/role-permissions";

export type PersonelSelfMenuItem = {
  id: "gecmis" | "izinlerim" | "talepler" | "duyurular" | "fazla-mesai" | "profil";
  label: string;
  to: string;
  permission: AppPermission;
  testId: string;
  modalTitle: string;
};

/**
 * PERSONEL product menu registry. Geçmiş keeps the existing history route/modal.
 * Self-product routes open modals; they do not replace manager shortcuts.
 */
export const PERSONEL_SELF_MENU: readonly PersonelSelfMenuItem[] = [
  {
    id: "gecmis",
    label: "Geçmiş",
    to: "/self/qr-hareketleri",
    permission: "self_service.qr.events.view",
    testId: "personel-menu-gecmis",
    modalTitle: "Giriş / Çıkış Geçmişim"
  },
  {
    id: "izinlerim",
    label: "İzinlerim",
    to: "/self/izinlerim",
    permission: "self_service.yillik_izin.view",
    testId: "personel-menu-izinlerim",
    modalTitle: "İzinlerim"
  },
  {
    id: "talepler",
    label: "Talepler",
    to: "/self/talepler",
    permission: "self_service.view",
    testId: "personel-menu-talepler",
    modalTitle: "Talepler"
  },
  {
    id: "duyurular",
    label: "Duyurular",
    to: "/self/duyurular",
    permission: "self_service.view",
    testId: "personel-menu-duyurular",
    modalTitle: "Duyurular"
  },
  {
    id: "fazla-mesai",
    label: "Fazla Mesaim",
    to: "/self/fazla-mesai",
    permission: "self_service.fazla_calisma.view",
    testId: "personel-menu-fazla-mesai",
    modalTitle: "Fazla Mesaim"
  },
  {
    id: "profil",
    label: "Profilim",
    to: "/self/profil",
    permission: "self_service.view",
    testId: "personel-menu-profil",
    modalTitle: "Profilim"
  }
];

/** PERSONEL ana ekran alt dock — Duyurular üst megafonda, Profilim foto dokunuşunda. */
export const PERSONEL_SELF_HOME_DOCK_IDS: readonly PersonelSelfMenuItem["id"][] = [
  "gecmis",
  "izinlerim",
  "talepler",
  "fazla-mesai"
];

export const PERSONEL_SELF_HOME_DOCK_MENU: readonly PersonelSelfMenuItem[] = PERSONEL_SELF_MENU.filter(
  (item) => PERSONEL_SELF_HOME_DOCK_IDS.includes(item.id)
);

const SELF_PRODUCT_PATHS = new Set(
  PERSONEL_SELF_MENU.filter((item) => item.id !== "gecmis").map((item) => item.to)
);

/** Modal title for the five new self-product routes. History keeps its existing AppShell owner. */
export function personelSelfProductModalTitle(pathname: string): string | null {
  if (!SELF_PRODUCT_PATHS.has(pathname)) {
    return null;
  }
  return PERSONEL_SELF_MENU.find((item) => item.to === pathname)?.modalTitle ?? null;
}
