export type UserRole =
  | "PERSONEL"
  | "MUHASEBE"
  | "IK_SORUMLUSU"
  | "BIRIM_AMIRI"
  | "BOLUM_YONETICISI"
  | "GENEL_YONETICI"
  | "SISTEM_YONETICISI"
  | "AUTH_SMOKE_READONLY";

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
  /** Optional self-service binding from login payload (DB-authoritative on /me). */
  personel_id?: number | null;
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

/** Insan kullanici olusturma / rol picker — exact 7 canonical human roles. */
export const ASSIGNABLE_USER_ROLES: UserRole[] = [
  "PERSONEL",
  "MUHASEBE",
  "IK_SORUMLUSU",
  "BIRIM_AMIRI",
  "BOLUM_YONETICISI",
  "GENEL_YONETICI",
  "SISTEM_YONETICISI"
];

/** Technical-only; not in role picker. */
export const TECHNICAL_ROLES: UserRole[] = ["AUTH_SMOKE_READONLY"];

export const ALL_ROLES: UserRole[] = [
  ...ASSIGNABLE_USER_ROLES,
  ...TECHNICAL_ROLES
];
