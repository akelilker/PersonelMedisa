export type UserRole =
  | "GENEL_YONETICI"
  | "BOLUM_YONETICISI"
  | "MUHASEBE"
  | "BIRIM_AMIRI";

export type UiProfile = "yonetim" | "birim_amiri";

export type AuthUser = {
  id: number;
  ad_soyad: string;
  rol: UserRole;
};

export type AuthSession = {
  token: string;
  user: AuthUser;
  ui_profile: UiProfile;
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
