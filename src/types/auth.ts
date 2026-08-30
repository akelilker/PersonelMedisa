export type UserRole =
  | "PERSONEL"
  | "MUHASEBE"
  | "IK_SORUMLUSU"
  | "BIRIM_AMIRI"
  | "BOLUM_YONETICISI"
  | "SUBE_YONETICISI"
  | "GENEL_YONETICI"
  | "SISTEM_YONETICISI"
  | "AUTH_SMOKE_READONLY";

export type UiProfile = "yonetim" | "birim_amiri";

export type SubeInfo = {
  id: number;
  /**
   * Shared display name derived by the backend read model: company-qualified
   * ("Medisa Ankara") once the branch is mapped, the raw branch name otherwise.
   * Consumers render this as-is and never concatenate a company name themselves.
   */
  ad: string;
  /** Raw short branch name ("Ankara"), for company-scoped screens. */
  kisa_ad?: string;
  tam_ad?: string;
};

export type AuthUser = {
  id: number;
  ad_soyad: string;
  rol: UserRole;
  /**
   * Authoritative branch assignment (user_subeler).
   * Empty is unrestricted ONLY for GENEL_YONETICI / SISTEM_YONETICISI.
   */
  sube_ids: number[];
  /**
   * Explicit user_subeler grants only, without the branches a company scope
   * resolves to. Present so the union in `sube_ids` stays inspectable and a
   * company scope is provably not materialised into branch assignments.
   */
  explicit_sube_ids?: number[];
  /** Company-wide scope (user_sirketler). Resolved to branches per request. */
  sirket_ids?: number[];
  /** Payroll-employer scope (user_sgk_isverenler), an axis of its own. */
  sgk_isveren_ids?: number[];
  /** Authoritative department assignment (user_bolumler). */
  bolum_ids?: number[];
  /** Authoritative unit assignment (user_birimler). */
  birim_ids?: number[];
  /** Optional self-service binding from login payload (DB-authoritative on /me). */
  personel_id?: number | null;
};

export type AuthSession = {
  token: string;
  user: AuthUser;
  ui_profile: UiProfile;
  /**
   * Selector context. For global roles with empty sube_ids: null = all-branches mode.
   * For scoped roles: derived/assigned branch used for UX narrowing only.
   */
  active_sube_id: number | null;
  /** Opsiyonel etiketler (login yaniti) */
  sube_list?: SubeInfo[];
  /** Admin geçici şifre sonrası kullanıcının kendi şifresini belirlemesi gerekir. */
  must_change_password?: boolean;
};

export type LoginCredentials = {
  username: string;
  password: string;
  /** true ise token localStorage'da; aksi halde sessionStorage (varsayilan). */
  rememberMe?: boolean;
};

export const MANAGEMENT_ROLES: UserRole[] = [
  "GENEL_YONETICI",
  "SUBE_YONETICISI",
  "BOLUM_YONETICISI",
  "MUHASEBE"
];

/** Insan kullanici olusturma / rol picker — exact 8 canonical human roles. */
export const ASSIGNABLE_USER_ROLES: UserRole[] = [
  "PERSONEL",
  "MUHASEBE",
  "IK_SORUMLUSU",
  "BIRIM_AMIRI",
  "BOLUM_YONETICISI",
  "SUBE_YONETICISI",
  "GENEL_YONETICI",
  "SISTEM_YONETICISI"
];

/** Technical-only; not in role picker. */
export const TECHNICAL_ROLES: UserRole[] = ["AUTH_SMOKE_READONLY"];

export const ALL_ROLES: UserRole[] = [
  ...ASSIGNABLE_USER_ROLES,
  ...TECHNICAL_ROLES
];

/** Empty org assignment means unrestricted. */
export const GLOBAL_SCOPE_ROLES: UserRole[] = ["GENEL_YONETICI", "SISTEM_YONETICISI"];

export const SUBE_ASSIGNMENT_ROLES: UserRole[] = [
  "SUBE_YONETICISI",
  "IK_SORUMLUSU",
  "MUHASEBE"
];

export const BOLUM_ASSIGNMENT_ROLES: UserRole[] = ["BOLUM_YONETICISI"];
export const BIRIM_ASSIGNMENT_ROLES: UserRole[] = ["BIRIM_AMIRI"];
