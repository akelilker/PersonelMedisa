export type UserRole =
  | "GENEL_YONETICI"
  | "BOLUM_YONETICISI"
  | "MUHASEBE"
  | "BIRIM_AMIRI";

export type UiProfile = "yonetim" | "birim_amiri";

export type SubeInfo = {
  id: number;
  ad: string;
};

export type AuthUser = {
  id: number;
  ad_soyad: string;
  rol: UserRole;
  /** Bos ise tum subelere erisim (yonetim); dolu ise yalnizca bu id'ler */
  sube_ids: number[];
};

export type AuthSession = {
  token: string;
  user: AuthUser;
  ui_profile: UiProfile;
  /**
   * Yetkili sube listesi (user.sube_ids) icinden secili aktif sube.
   * sube_ids bos ise tum subeler modu; null.
   */
  active_sube_id: number | null;
  /** Opsiyonel etiketler (login yaniti) */
  sube_list?: SubeInfo[];
};

export type LoginCredentials = {
  username: string;
  password: string;
  /** true ise token localStorage'da; aksi halde sessionStorage (varsayilan). */
  rememberMe?: boolean;
};

export const MANAGEMENT_ROLES: UserRole[] = [
  "GENEL_YONETICI",
  "BOLUM_YONETICISI",
  "MUHASEBE"
];

export const ALL_ROLES: UserRole[] = [...MANAGEMENT_ROLES, "BIRIM_AMIRI"];
